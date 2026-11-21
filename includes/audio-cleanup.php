<?php
/**
 * Shiur audio cleanup (an Experiment; Yoni 2026-11-20).
 *
 * Removes background noise and room echo from shiur recordings, evens out the
 * volume and compresses the file. A Sony recorder gives two tracks (left/right
 * or two files); the better one is picked by itself, not mixed.
 *
 * Where the work happens: the web host can't run a noise-removal model, so the
 * site keeps a list of jobs and a "cleanup helper" does them
 * (tools/audio-cleanup-helper.py). The helper can run on Kaggle (the site starts
 * it there by itself when a job is waiting) or on any computer. It asks
 * /ner-michoel/v1/audio-cleanup/worker/next for work, downloads the audio,
 * cleans it and posts the cleaned MP3 back; the site stores it as a new media
 * file (on Bunny like every upload). Nothing else can be done with the helper key.
 *
 * Two ways jobs are made:
 * - "try": an admin on Experiments > Shiur audio cleanup picks a shiur or
 *   uploads a recording (one or two files). Never touches what listeners hear.
 * - "auto": every new shiur with an audio file, when the admin turns automatic
 *   cleanup on. OFF by default. "test" mode cleans and keeps the result to
 *   compare (listeners still hear the original); "on" mode also switches the
 *   shiur to the cleaned file, keeping the original (_shiur_audio_original_id)
 *   so "Use the original again" puts it back.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NM_AC_DB_VERSION', '1' );
define( 'NM_AC_SETTINGS', 'nm_audio_cleanup_settings' );
define( 'NM_AC_KAGGLE', 'nm_audio_cleanup_kaggle' ); // credentials + last run, not autoloaded
define( 'NM_AC_HELPER_SEEN', 'nm_audio_cleanup_helper_seen' );
define( 'NM_AC_STALE_MINUTES', 120 ); // a job "working" this long without an answer goes back in the queue

function nm_ac_table() {
	global $wpdb;
	return $wpdb->prefix . 'nm_audio_cleanup';
}

function nm_ac_install() {
	if ( get_option( 'nm_audio_cleanup_db' ) === NM_AC_DB_VERSION ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$charset = $wpdb->get_charset_collate();
	$table   = nm_ac_table();
	dbDelta(
		"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			kind varchar(10) NOT NULL DEFAULT 'try',
			shiur_id bigint(20) unsigned NOT NULL DEFAULT 0,
			sources text NOT NULL,
			options text NOT NULL,
			status varchar(12) NOT NULL DEFAULT 'queued',
			result_id bigint(20) unsigned NOT NULL DEFAULT 0,
			report longtext NULL,
			message text NULL,
			applied tinyint(1) NOT NULL DEFAULT 0,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			claimed_at datetime NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY shiur_id (shiur_id)
		) {$charset};"
	);
	update_option( 'nm_audio_cleanup_db', NM_AC_DB_VERSION );
}
add_action( 'admin_init', 'nm_ac_install' );
add_action( 'rest_api_init', 'nm_ac_install', 1 );

/* ------------------------------------------------------------------ settings */

function nm_ac_settings() {
	$s = wp_parse_args(
		(array) get_option( NM_AC_SETTINGS, array() ),
		array(
			'auto'      => 'off',    // off | test | on
			'strength'  => 'normal', // gentle | normal | strong
			'normalize' => 1,
			'bitrate'   => 48,       // kbps, mono MP3
			'auto_days' => 14,       // only shiurim dated within this many days are "new"
			'key_hash'  => '',       // the helper key (own computer), hashed
		)
	);
	if ( ! in_array( $s['auto'], array( 'off', 'test', 'on' ), true ) ) {
		$s['auto'] = 'off';
	}
	if ( ! isset( nm_ac_strengths()[ $s['strength'] ] ) ) {
		$s['strength'] = 'normal';
	}
	$s['bitrate'] = in_array( (int) $s['bitrate'], array( 32, 48, 64, 96 ), true ) ? (int) $s['bitrate'] : 48;
	return $s;
}

function nm_ac_save_settings( $new ) {
	$s = array_merge( nm_ac_settings(), $new );
	update_option( NM_AC_SETTINGS, $s, false );
	return nm_ac_settings();
}

function nm_ac_strengths() {
	return array(
		'gentle' => __( 'Gentle (takes off some hiss, most natural)', 'ner-michoel-core' ),
		'normal' => __( 'Normal (recommended)', 'ner-michoel-core' ),
		'strong' => __( 'Strong (takes off as much as it can)', 'ner-michoel-core' ),
	);
}

/* ------------------------------------------------------------------ jobs */

function nm_ac_now() {
	return current_time( 'mysql', true );
}

/**
 * Add a job. $sources = attachment ids (1 or 2). Returns the job id or WP_Error.
 */
function nm_ac_add_job( $kind, $shiur_id, $sources, $options = array() ) {
	global $wpdb;
	$sources = array_values( array_filter( array_map( 'absint', (array) $sources ) ) );
	if ( ! $sources || count( $sources ) > 2 ) {
		return new WP_Error( 'nm_ac_sources', __( 'Pick one recording, or the two tracks of one recording.', 'ner-michoel-core' ) );
	}
	foreach ( $sources as $a ) {
		if ( 'attachment' !== get_post_type( $a ) || ! ( wp_attachment_is( 'audio', $a ) || wp_attachment_is( 'video', $a ) ) ) {
			return new WP_Error( 'nm_ac_not_audio', __( 'That file is not an audio file.', 'ner-michoel-core' ) );
		}
	}
	$s    = nm_ac_settings();
	$opts = array(
		'strength'        => isset( $options['strength'] ) && isset( nm_ac_strengths()[ $options['strength'] ] ) ? $options['strength'] : $s['strength'],
		'limit_seconds'   => isset( $options['limit_seconds'] ) ? max( 0, min( 6 * 3600, (int) $options['limit_seconds'] ) ) : 0,
		'normalize'       => isset( $options['normalize'] ) ? (int) (bool) $options['normalize'] : (int) $s['normalize'],
		'shorten_silence' => ! empty( $options['shorten_silence'] ) ? 1 : 0,
		'bitrate_kbps'    => $s['bitrate'],
	);
	$now = nm_ac_now();
	$wpdb->insert(
		nm_ac_table(),
		array(
			'kind'       => 'auto' === $kind ? 'auto' : 'try',
			'shiur_id'   => absint( $shiur_id ),
			'sources'    => wp_json_encode( $sources ),
			'options'    => wp_json_encode( $opts ),
			'status'     => 'queued',
			'created_by' => get_current_user_id(),
			'created_at' => $now,
			'updated_at' => $now,
		)
	);
	$id = (int) $wpdb->insert_id;
	if ( ! $id ) {
		return new WP_Error( 'nm_ac_db', __( 'Could not save the job.', 'ner-michoel-core' ) );
	}
	do_action( 'nm_ac_job_added', $id );
	if ( 'auto' !== $kind ) {
		nm_ac_wake_kaggle(); // an admin is waiting: start Kaggle now (automatic jobs wait for the 5-minute check)
	}
	return $id;
}

function nm_ac_get_job( $id ) {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . nm_ac_table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	return $row ? $row : null;
}

function nm_ac_update_job( $id, $data ) {
	global $wpdb;
	$data['updated_at'] = nm_ac_now();
	return $wpdb->update( nm_ac_table(), $data, array( 'id' => (int) $id ) );
}

/** Jobs stuck in "working" (the helper stopped half way) go back in the queue. */
function nm_ac_requeue_stale() {
	global $wpdb;
	$cut = gmdate( 'Y-m-d H:i:s', time() - NM_AC_STALE_MINUTES * MINUTE_IN_SECONDS );
	$wpdb->query( $wpdb->prepare( 'UPDATE ' . nm_ac_table() . " SET status = 'queued', claimed_at = NULL WHERE status = 'working' AND claimed_at < %s", $cut ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}

function nm_ac_count_waiting() {
	global $wpdb;
	return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . nm_ac_table() . " WHERE status IN ('queued','working')" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}

/** A job for the admin screen (no secrets in it). */
function nm_ac_job_for_screen( $row ) {
	$sources = json_decode( $row['sources'], true );
	$before  = array();
	foreach ( (array) $sources as $a ) {
		$before[] = array(
			'id'    => (int) $a,
			'url'   => (string) wp_get_attachment_url( $a ),
			'title' => get_the_title( $a ),
		);
	}
	$shiur = (int) $row['shiur_id'];
	$title = $shiur ? get_the_title( $shiur ) : ( $before ? $before[0]['title'] : '' );
	return array(
		'id'         => (int) $row['id'],
		'kind'       => $row['kind'],
		'status'     => $row['status'],
		'shiur_id'   => $shiur,
		'shiur_url'  => $shiur ? get_permalink( $shiur ) : '',
		'edit_url'   => $shiur ? get_edit_post_link( $shiur, 'raw' ) : '',
		'title'      => html_entity_decode( (string) $title, ENT_QUOTES ),
		'before'     => $before,
		'after'      => $row['result_id'] ? (string) wp_get_attachment_url( $row['result_id'] ) : '',
		'options'    => json_decode( $row['options'], true ),
		'report'     => $row['report'] ? json_decode( $row['report'], true ) : null,
		'message'    => (string) $row['message'],
		'applied'    => (int) $row['applied'],
		'created_at' => get_date_from_gmt( $row['created_at'], 'j M Y, H:i' ),
		'updated_at' => get_date_from_gmt( $row['updated_at'], 'j M Y, H:i' ),
	);
}

/** Delete a job and the files it made (and recordings uploaded only for it). */
function nm_ac_delete_job( $id ) {
	global $wpdb;
	$row = nm_ac_get_job( $id );
	if ( ! $row ) {
		return false;
	}
	if ( $row['applied'] ) {
		nm_ac_use_original( $id );
	}
	if ( $row['result_id'] ) {
		wp_delete_attachment( (int) $row['result_id'], true );
	}
	foreach ( (array) json_decode( $row['sources'], true ) as $a ) {
		if ( get_post_meta( $a, '_nm_ac_upload', true ) && ! nm_ac_attachment_in_other_job( $a, $id ) ) {
			wp_delete_attachment( (int) $a, true );
		}
	}
	return (bool) $wpdb->delete( nm_ac_table(), array( 'id' => (int) $id ) );
}

function nm_ac_attachment_in_other_job( $attachment_id, $job_id ) {
	global $wpdb;
	$like = '%' . $wpdb->esc_like( (string) (int) $attachment_id ) . '%';
	$rows = $wpdb->get_col( $wpdb->prepare( 'SELECT sources FROM ' . nm_ac_table() . ' WHERE id <> %d AND sources LIKE %s', $job_id, $like ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	foreach ( $rows as $s ) {
		if ( in_array( (int) $attachment_id, array_map( 'intval', (array) json_decode( $s, true ) ), true ) ) {
			return true;
		}
	}
	return false;
}

/* ------------------------------------------------------------------ listeners hear the cleaned file */

$GLOBALS['nm_ac_switching'] = false;

/** Switch the shiur to the cleaned file, keeping the original to go back to. */
function nm_ac_use_cleaned( $job_id ) {
	$row = nm_ac_get_job( $job_id );
	if ( ! $row || 'done' !== $row['status'] || ! $row['result_id'] || ! $row['shiur_id'] ) {
		return false;
	}
	$shiur   = (int) $row['shiur_id'];
	$current = (int) get_post_meta( $shiur, '_shiur_audio_id', true );
	if ( $current === (int) $row['result_id'] ) {
		return true;
	}
	$GLOBALS['nm_ac_switching'] = true;
	if ( ! get_post_meta( $shiur, '_shiur_audio_original_id', true ) ) {
		update_post_meta( $shiur, '_shiur_audio_original_id', $current );
	}
	update_post_meta( $shiur, '_shiur_audio_id', (int) $row['result_id'] );
	$GLOBALS['nm_ac_switching'] = false;
	nm_ac_update_job( $job_id, array( 'applied' => 1 ) );
	return true;
}

function nm_ac_use_original( $job_id ) {
	$row = nm_ac_get_job( $job_id );
	if ( ! $row || ! $row['shiur_id'] ) {
		return false;
	}
	$shiur    = (int) $row['shiur_id'];
	$original = (int) get_post_meta( $shiur, '_shiur_audio_original_id', true );
	if ( $original && (int) get_post_meta( $shiur, '_shiur_audio_id', true ) === (int) $row['result_id'] ) {
		$GLOBALS['nm_ac_switching'] = true;
		update_post_meta( $shiur, '_shiur_audio_id', $original );
		delete_post_meta( $shiur, '_shiur_audio_original_id' );
		$GLOBALS['nm_ac_switching'] = false;
	}
	nm_ac_update_job( $job_id, array( 'applied' => 0 ) );
	return true;
}

/* ------------------------------------------------------------------ automatic cleanup of new shiurim (OFF by default) */

/**
 * A shiur got (or changed) its audio file: when automatic cleanup is on, queue
 * it. Only shiurim dated in the last few days count (the library import also
 * sets audio on old shiurim, which are not "new uploads").
 */
function nm_ac_on_audio_meta( $meta_id, $post_id, $meta_key, $value ) {
	if ( '_shiur_audio_id' !== $meta_key || ! empty( $GLOBALS['nm_ac_switching'] ) ) {
		return;
	}
	$s = nm_ac_settings();
	if ( 'off' === $s['auto'] || 'shiur' !== get_post_type( $post_id ) ) {
		return;
	}
	$attachment = absint( $value );
	if ( ! $attachment || ! wp_attachment_is( 'audio', $attachment ) || get_post_meta( $attachment, '_nm_ac_cleaned_from', true ) ) {
		return;
	}
	$date = get_post_time( 'U', true, $post_id );
	if ( $date && $date < time() - (int) $s['auto_days'] * DAY_IN_SECONDS ) {
		return;
	}
	global $wpdb;
	$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . nm_ac_table() . " WHERE kind = 'auto' AND shiur_id = %d AND sources = %s", $post_id, wp_json_encode( array( $attachment ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	if ( $exists ) {
		return;
	}
	nm_ac_add_job( 'auto', $post_id, array( $attachment ), array( 'shorten_silence' => 0, 'limit_seconds' => 0 ) );
}
add_action( 'added_post_meta', 'nm_ac_on_audio_meta', 10, 4 );
add_action( 'updated_post_meta', 'nm_ac_on_audio_meta', 10, 4 );

/* ------------------------------------------------------------------ helper key */

/** A new helper key (for running the helper on your own computer). Shown once. */
function nm_ac_new_helper_key() {
	$key = 'nmac_' . wp_generate_password( 40, false, false );
	nm_ac_save_settings( array( 'key_hash' => wp_hash_password( $key ) ) );
	return $key;
}

/** The key the helper sent: the saved helper key, or a Kaggle run's own key (6 hours). */
function nm_ac_check_helper_key( WP_REST_Request $request ) {
	$key = (string) $request->get_header( 'x_nm_worker_key' );
	if ( strlen( $key ) < 20 ) {
		return false;
	}
	$s = nm_ac_settings();
	if ( $s['key_hash'] && wp_check_password( $key, $s['key_hash'] ) ) {
		return true;
	}
	$k = nm_ac_kaggle();
	if ( ! empty( $k['run_key_hash'] ) && ! empty( $k['run_key_until'] ) && time() < (int) $k['run_key_until'] ) {
		return hash_equals( $k['run_key_hash'], hash( 'sha256', $key ) );
	}
	return false;
}

/* ------------------------------------------------------------------ Kaggle (the site starts the helper there by itself) */

function nm_ac_kaggle() {
	return wp_parse_args(
		(array) get_option( NM_AC_KAGGLE, array() ),
		array(
			'enabled'       => 0,
			'username'      => '',
			'token'         => '',
			'slug'          => 'nm-audio-cleanup',
			'last_push'     => 0,
			'last_status'   => '',
			'last_message'  => '',
			'last_url'      => '',
			'run_key_hash'  => '',
			'run_key_until' => 0,
		)
	);
}

function nm_ac_kaggle_save( $data ) {
	update_option( NM_AC_KAGGLE, array_merge( nm_ac_kaggle(), $data ), false );
}

function nm_ac_kaggle_ready() {
	$k = nm_ac_kaggle();
	return $k['enabled'] && $k['username'] && $k['token'];
}

/** Kaggle's API (the one its own command-line tool uses). */
function nm_ac_kaggle_call( $method, $body ) {
	$k        = nm_ac_kaggle();
	$response = wp_remote_post(
		'https://api.kaggle.com/v1/kernels.KernelsApiService/' . $method,
		array(
			'timeout' => 45,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
				// New Kaggle API tokens (KGAT_...) are Bearer tokens; an old kaggle.json key is username + key.
				'Authorization' => 0 === strpos( $k['token'], 'KGAT_' )
					? 'Bearer ' . $k['token']
					: 'Basic ' . base64_encode( $k['username'] . ':' . $k['token'] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			),
			'body'    => wp_json_encode( $body ),
		)
	);
	if ( is_wp_error( $response ) ) {
		return $response;
	}
	$code = wp_remote_retrieve_response_code( $response );
	$json = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( $code < 200 || $code >= 300 ) {
		$msg = is_array( $json ) && ! empty( $json['message'] ) ? $json['message'] : wp_remote_retrieve_body( $response );
		return new WP_Error( 'nm_ac_kaggle', sprintf( 'Kaggle %d: %s', $code, wp_strip_all_tags( substr( (string) $msg, 0, 300 ) ) ) );
	}
	return is_array( $json ) ? $json : array();
}

/** RUNNING / QUEUED / COMPLETE / ERROR / CANCEL..., or WP_Error. */
function nm_ac_kaggle_status() {
	$k = nm_ac_kaggle();
	$r = nm_ac_kaggle_call(
		'GetKernelSessionStatus',
		array(
			'userName'   => $k['username'],
			'kernelSlug' => $k['slug'],
		)
	);
	if ( is_wp_error( $r ) ) {
		return $r;
	}
	$status = strtoupper( (string) ( isset( $r['status'] ) ? $r['status'] : '' ) );
	nm_ac_kaggle_save(
		array(
			'last_status'  => $status,
			'last_message' => isset( $r['failureMessage'] ) ? (string) $r['failureMessage'] : '',
		)
	);
	return $status;
}

/**
 * The script the Kaggle run executes: install DeepFilterNet, then the helper
 * with this site's address and a key that works for 6 hours only.
 */
function nm_ac_kaggle_script( $run_key ) {
	$helper = file_get_contents( NER_MICHOEL_CORE_PATH . 'tools/audio-cleanup-helper.py' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$args   = array( '--site', home_url( '/' ), '--key', $run_key, '--once' );
	// DeepFilterNet only has builds for Python 3.8-3.11 and Kaggle runs a newer
	// Python, so the run makes a small Python 3.11 setup with uv (about 20 seconds).
	return "# Started by the Ner Michoel site (Experiments > Shiur audio cleanup). Safe to delete.\n"
		. 'HELPER = "' . base64_encode( $helper ) . "\"\n" // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		. 'ARGS = ' . wp_json_encode( $args, JSON_UNESCAPED_SLASHES ) . "\n"
		. <<<'PY'
import base64, os, subprocess, sys
W = "/kaggle/tmp/nmac"
os.makedirs(W, exist_ok=True)
open(W + "/helper.py", "wb").write(base64.b64decode(HELPER))
def sh(*c):
    print("+", " ".join(c[:3]), flush=True)  # never the key
    return subprocess.run(list(c)).returncode
py = W + "/venv/bin/python"
if not os.path.exists(py):
    sh(sys.executable, "-m", "pip", "install", "-q", "uv")
    sh("uv", "venv", "-q", "-p", "3.11", W + "/venv")
    sh("uv", "pip", "install", "-q", "--python", py, "torch==2.3.1", "torchaudio==2.3.1", "--index-url", "https://download.pytorch.org/whl/cpu")
    sh("uv", "pip", "install", "-q", "--python", py, "deepfilternet==0.5.6", "numpy<2")
sys.exit(sh(py, W + "/helper.py", *ARGS))

PY;
}

/**
 * Start the helper on Kaggle when work is waiting and no run is going.
 * Called when a job is added and every few minutes by WP-Cron.
 * $force: start even if one was started a few minutes ago (the "Start now" button).
 */
function nm_ac_wake_kaggle( $force = false ) {
	if ( ! nm_ac_kaggle_ready() || ! nm_ac_count_waiting() ) {
		return false;
	}
	$k = nm_ac_kaggle();
	// The helper on your own computer is up (asked in the last 3 minutes): it will do it.
	if ( ! $force && (int) get_option( NM_AC_HELPER_SEEN . '_own', 0 ) > time() - 3 * MINUTE_IN_SECONDS ) {
		return false;
	}
	if ( ! $force && $k['last_push'] > time() - 10 * MINUTE_IN_SECONDS ) {
		return false; // just started one; give it time to boot (installing takes a minute or two)
	}
	if ( $k['last_push'] ) {
		$status = nm_ac_kaggle_status();
		if ( ! is_wp_error( $status ) && in_array( $status, array( 'RUNNING', 'QUEUED', 'NEW', 'KERNEL_WORKER_STATUS_RUNNING', 'KERNEL_WORKER_STATUS_QUEUED' ), true ) ) {
			return false; // it is running; it keeps going while jobs are waiting
		}
	}
	$run_key = 'nmrun_' . wp_generate_password( 40, false, false );
	nm_ac_kaggle_save(
		array(
			'run_key_hash'  => hash( 'sha256', $run_key ),
			'run_key_until' => time() + 6 * HOUR_IN_SECONDS,
			'last_push'     => time(),
		)
	);
	$r = nm_ac_kaggle_call(
		'SaveKernel',
		array(
			'slug'                 => $k['username'] . '/' . $k['slug'],
			'newTitle'             => $k['slug'],
			'text'                 => nm_ac_kaggle_script( $run_key ),
			'language'             => 'python',
			'kernelType'           => 'script',
			'isPrivate'            => true,
			'enableGpu'            => false, // a CPU is enough (about 3-5 minutes per hour of audio) and never waits in a queue
			'enableTpu'            => false,
			'enableInternet'       => true,
			'datasetDataSources'   => array(),
			'competitionDataSources' => array(),
			'kernelDataSources'    => array(),
			'modelDataSources'     => array(),
			'categoryIds'          => array(),
		)
	);
	if ( is_wp_error( $r ) || ! empty( $r['error'] ) ) {
		$msg = is_wp_error( $r ) ? $r->get_error_message() : (string) $r['error'];
		nm_ac_kaggle_save(
			array(
				'last_status'  => 'COULD NOT START',
				'last_message' => $msg,
			)
		);
		return new WP_Error( 'nm_ac_kaggle', $msg );
	}
	nm_ac_kaggle_save(
		array(
			'last_status'  => 'STARTED',
			'last_message' => '',
			'last_url'     => isset( $r['url'] ) ? esc_url_raw( $r['url'] ) : '',
		)
	);
	return true;
}

function nm_ac_cron_schedules( $schedules ) {
	if ( ! isset( $schedules['nm_five_minutes'] ) ) {
		$schedules['nm_five_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => 'Every five minutes',
		);
	}
	return $schedules;
}
add_filter( 'cron_schedules', 'nm_ac_cron_schedules' );

/** Every 5 minutes, only while something is waiting: stale jobs back in the queue, Kaggle woken. */
function nm_ac_cron() {
	nm_ac_requeue_stale();
	if ( nm_ac_count_waiting() ) {
		nm_ac_wake_kaggle();
	} else {
		wp_clear_scheduled_hook( 'nm_audio_cleanup_tick' );
	}
}
add_action( 'nm_audio_cleanup_tick', 'nm_ac_cron' );

function nm_ac_schedule_tick() {
	if ( ! wp_next_scheduled( 'nm_audio_cleanup_tick' ) ) {
		wp_schedule_event( time() + 60, 'nm_five_minutes', 'nm_audio_cleanup_tick' );
	}
}
add_action( 'nm_ac_job_added', 'nm_ac_schedule_tick' );

/* ------------------------------------------------------------------ REST: the helper */

function nm_ac_register_routes() {
	$ns     = 'ner-michoel/v1';
	$helper = 'nm_ac_check_helper_key';
	$admin  = function () {
		return current_user_can( 'manage_options' );
	};

	register_rest_route( $ns, '/audio-cleanup/worker/next', array( 'methods' => 'POST', 'callback' => 'nm_ac_rest_next', 'permission_callback' => $helper ) );
	register_rest_route( $ns, '/audio-cleanup/worker/(?P<id>\d+)/done', array( 'methods' => 'POST', 'callback' => 'nm_ac_rest_done', 'permission_callback' => $helper ) );
	register_rest_route( $ns, '/audio-cleanup/worker/(?P<id>\d+)/fail', array( 'methods' => 'POST', 'callback' => 'nm_ac_rest_fail', 'permission_callback' => $helper ) );

	register_rest_route(
		$ns,
		'/audio-cleanup/jobs',
		array(
			array( 'methods' => 'GET', 'callback' => 'nm_ac_rest_jobs', 'permission_callback' => $admin ),
			array( 'methods' => 'POST', 'callback' => 'nm_ac_rest_create', 'permission_callback' => $admin ),
		)
	);
	register_rest_route( $ns, '/audio-cleanup/jobs/(?P<id>\d+)', array( 'methods' => 'DELETE', 'callback' => 'nm_ac_rest_delete', 'permission_callback' => $admin ) );
	register_rest_route( $ns, '/audio-cleanup/jobs/(?P<id>\d+)/(?P<what>use-cleaned|use-original|again)', array( 'methods' => 'POST', 'callback' => 'nm_ac_rest_job_action', 'permission_callback' => $admin ) );
	register_rest_route( $ns, '/audio-cleanup/shiurim', array( 'methods' => 'GET', 'callback' => 'nm_ac_rest_shiurim', 'permission_callback' => $admin ) );
	register_rest_route( $ns, '/audio-cleanup/settings', array( 'methods' => 'POST', 'callback' => 'nm_ac_rest_settings', 'permission_callback' => $admin ) );
	register_rest_route( $ns, '/audio-cleanup/helper-key', array( 'methods' => 'POST', 'callback' => 'nm_ac_rest_helper_key', 'permission_callback' => $admin ) );
	register_rest_route( $ns, '/audio-cleanup/kaggle', array( 'methods' => 'POST', 'callback' => 'nm_ac_rest_kaggle', 'permission_callback' => $admin ) );
}
add_action( 'rest_api_init', 'nm_ac_register_routes' );

/** The helper asks for work: the oldest waiting job, marked "working". */
function nm_ac_rest_next( WP_REST_Request $request ) {
	global $wpdb;
	$own = 0 === strpos( (string) $request->get_header( 'x_nm_worker_key' ), 'nmac_' );
	update_option( NM_AC_HELPER_SEEN, time(), false );
	if ( $own ) {
		update_option( NM_AC_HELPER_SEEN . '_own', time(), false );
	}
	nm_ac_requeue_stale();
	for ( $try = 0; $try < 3; $try++ ) {
		$row = $wpdb->get_row( 'SELECT * FROM ' . nm_ac_table() . " WHERE status = 'queued' ORDER BY id ASC LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $row ) {
			return array( 'job' => null );
		}
		// Claim it only if nobody else did in the meantime.
		$claimed = $wpdb->query( $wpdb->prepare( 'UPDATE ' . nm_ac_table() . " SET status = 'working', claimed_at = %s, updated_at = %s, message = '' WHERE id = %d AND status = 'queued'", nm_ac_now(), nm_ac_now(), $row['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $claimed ) {
			continue;
		}
		$urls = array();
		foreach ( (array) json_decode( $row['sources'], true ) as $a ) {
			$url = wp_get_attachment_url( $a );
			if ( $url ) {
				$urls[] = $url;
			}
		}
		if ( ! $urls ) {
			nm_ac_update_job( $row['id'], array( 'status' => 'failed', 'message' => __( 'The recording is no longer on the site.', 'ner-michoel-core' ) ) );
			continue;
		}
		return array(
			'job' => array(
				'id'      => (int) $row['id'],
				'sources' => $urls,
				'options' => json_decode( $row['options'], true ),
			),
		);
	}
	return array( 'job' => null );
}

/** The helper sends the cleaned MP3 (multipart: file + report). */
function nm_ac_rest_done( WP_REST_Request $request ) {
	$id  = (int) $request['id'];
	$row = nm_ac_get_job( $id );
	if ( ! $row || 'working' !== $row['status'] ) {
		return new WP_Error( 'nm_ac_job', 'No such job in progress.', array( 'status' => 404 ) );
	}
	$files = $request->get_file_params();
	if ( empty( $files['file']['tmp_name'] ) || ! empty( $files['file']['error'] ) ) {
		return new WP_Error( 'nm_ac_file', 'No file received.', array( 'status' => 400 ) );
	}
	// Only an MP3 is taken.
	$check = wp_check_filetype_and_ext( $files['file']['tmp_name'], 'cleaned.mp3', array( 'mp3' => 'audio/mpeg' ) );
	if ( 'audio/mpeg' !== $check['type'] ) {
		return new WP_Error( 'nm_ac_file', 'The file is not an MP3.', array( 'status' => 400 ) );
	}
	$report = json_decode( (string) $request->get_param( 'report' ), true );
	$report = is_array( $report ) ? $report : array();

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$base = $row['shiur_id'] ? get_post_field( 'post_name', $row['shiur_id'] ) : 'recording-' . $id;
	$file = array(
		'name'     => sanitize_file_name( $base . '-cleaned-' . $id . '.mp3' ),
		'tmp_name' => $files['file']['tmp_name'],
	);
	$title  = ( $row['shiur_id'] ? get_the_title( $row['shiur_id'] ) : __( 'Recording', 'ner-michoel-core' ) ) . ' ' . __( '(cleaned)', 'ner-michoel-core' );
	$att_id = media_handle_sideload( $file, 0, $title );
	if ( is_wp_error( $att_id ) ) {
		nm_ac_update_job( $id, array( 'status' => 'failed', 'message' => $att_id->get_error_message() ) );
		return new WP_Error( 'nm_ac_store', $att_id->get_error_message(), array( 'status' => 500 ) );
	}
	$sources = (array) json_decode( $row['sources'], true );
	update_post_meta( $att_id, '_nm_ac_cleaned_from', (int) reset( $sources ) );
	update_post_meta( $att_id, '_nm_ac_job', $id );
	nm_ac_update_job(
		$id,
		array(
			'status'    => 'done',
			'result_id' => $att_id,
			'report'    => wp_json_encode( $report ),
			'message'   => '',
		)
	);
	if ( 'auto' === $row['kind'] && 'on' === nm_ac_settings()['auto'] ) {
		nm_ac_use_cleaned( $id );
	}
	return array( 'ok' => true, 'attachment' => $att_id );
}

function nm_ac_rest_fail( WP_REST_Request $request ) {
	$id  = (int) $request['id'];
	$row = nm_ac_get_job( $id );
	if ( ! $row || 'working' !== $row['status'] ) {
		return new WP_Error( 'nm_ac_job', 'No such job in progress.', array( 'status' => 404 ) );
	}
	nm_ac_update_job(
		$id,
		array(
			'status'  => 'failed',
			'message' => sanitize_textarea_field( substr( (string) $request->get_param( 'message' ), 0, 900 ) ),
		)
	);
	return array( 'ok' => true );
}

/* ------------------------------------------------------------------ REST: the admin screen */

function nm_ac_status_for_screen() {
	$k       = nm_ac_kaggle();
	$seen    = (int) get_option( NM_AC_HELPER_SEEN, 0 );
	$ownseen = (int) get_option( NM_AC_HELPER_SEEN . '_own', 0 );
	$s       = nm_ac_settings();
	return array(
		'settings' => array(
			'auto'      => $s['auto'],
			'strength'  => $s['strength'],
			'normalize' => (int) $s['normalize'],
			'bitrate'   => (int) $s['bitrate'],
			'has_key'   => (bool) $s['key_hash'],
		),
		'helper'   => array(
			'seen'     => $seen ? human_time_diff( $seen ) : '',
			'seen_own' => $ownseen ? human_time_diff( $ownseen ) : '',
			'waiting'  => nm_ac_count_waiting(),
		),
		'kaggle'   => array(
			'enabled'     => (int) $k['enabled'],
			'username'    => $k['username'],
			'has_token'   => (bool) $k['token'],
			'last_push'   => $k['last_push'] ? human_time_diff( (int) $k['last_push'] ) : '',
			'last_status' => $k['last_status'],
			'message'     => $k['last_message'],
			'url'         => $k['last_url'] ? $k['last_url'] : ( $k['username'] ? 'https://www.kaggle.com/code/' . rawurlencode( $k['username'] ) . '/' . $k['slug'] : '' ),
		),
	);
}

function nm_ac_rest_jobs() {
	global $wpdb;
	nm_ac_requeue_stale();
	$rows = $wpdb->get_results( 'SELECT * FROM ' . nm_ac_table() . ' ORDER BY id DESC LIMIT 60', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	return array(
		'jobs'   => array_map( 'nm_ac_job_for_screen', (array) $rows ),
		'status' => nm_ac_status_for_screen(),
	);
}

function nm_ac_rest_create( WP_REST_Request $request ) {
	$shiur   = absint( $request->get_param( 'shiur_id' ) );
	$sources = array_map( 'absint', (array) $request->get_param( 'attachment_ids' ) );
	if ( $shiur ) {
		if ( 'shiur' !== get_post_type( $shiur ) ) {
			return new WP_Error( 'nm_ac_shiur', __( 'That is not a shiur.', 'ner-michoel-core' ), array( 'status' => 400 ) );
		}
		$a       = (int) get_post_meta( $shiur, '_shiur_audio_original_id', true );
		$a       = $a ? $a : (int) get_post_meta( $shiur, '_shiur_audio_id', true );
		$sources = array( $a );
	} else {
		foreach ( $sources as $a ) {
			update_post_meta( $a, '_nm_ac_upload', 1 ); // uploaded on the Experiments page: deleted with its job
		}
	}
	$id = nm_ac_add_job(
		'try',
		$shiur,
		$sources,
		array(
			'strength'        => sanitize_key( (string) $request->get_param( 'strength' ) ),
			'limit_seconds'   => (int) $request->get_param( 'limit_seconds' ),
			'normalize'       => $request->has_param( 'normalize' ) ? (bool) $request->get_param( 'normalize' ) : true,
			'shorten_silence' => (bool) $request->get_param( 'shorten_silence' ),
		)
	);
	if ( is_wp_error( $id ) ) {
		$id->add_data( array( 'status' => 400 ) );
		return $id;
	}
	return array( 'job' => nm_ac_job_for_screen( nm_ac_get_job( $id ) ) );
}

function nm_ac_rest_delete( WP_REST_Request $request ) {
	return array( 'ok' => nm_ac_delete_job( (int) $request['id'] ) );
}

function nm_ac_rest_job_action( WP_REST_Request $request ) {
	$id  = (int) $request['id'];
	$row = nm_ac_get_job( $id );
	if ( ! $row ) {
		return new WP_Error( 'nm_ac_job', 'No such job.', array( 'status' => 404 ) );
	}
	switch ( $request['what'] ) {
		case 'use-cleaned':
			$ok = nm_ac_use_cleaned( $id );
			break;
		case 'use-original':
			$ok = nm_ac_use_original( $id );
			break;
		default: // again: run it once more (e.g. after a failure or with the helper fixed)
			if ( $row['result_id'] && ! $row['applied'] ) {
				wp_delete_attachment( (int) $row['result_id'], true );
			}
			$ok = $row['applied'] ? false : (bool) nm_ac_update_job( $id, array( 'status' => 'queued', 'result_id' => 0, 'report' => null, 'message' => '', 'claimed_at' => null ) );
			if ( $ok ) {
				do_action( 'nm_ac_job_added', $id );
				nm_ac_wake_kaggle();
			}
	}
	return array( 'ok' => (bool) $ok, 'job' => nm_ac_job_for_screen( nm_ac_get_job( $id ) ) );
}

/** Shiurim with an audio file, for the picker. */
function nm_ac_rest_shiurim( WP_REST_Request $request ) {
	$args = array(
		'post_type'      => 'shiur',
		'post_status'    => array( 'publish', 'draft', 'private', 'future' ),
		'posts_per_page' => 20,
		'orderby'        => 'date',
		'order'          => 'DESC',
		'meta_query'     => array( array( 'key' => '_shiur_audio_id', 'compare' => 'EXISTS' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		'no_found_rows'  => true,
	);
	$q = trim( (string) $request->get_param( 'search' ) );
	if ( '' !== $q ) {
		$args['s'] = $q;
	}
	$out = array();
	foreach ( get_posts( $args ) as $p ) {
		$a = (int) get_post_meta( $p->ID, '_shiur_audio_id', true );
		if ( ! $a || ! wp_attachment_is( 'audio', $a ) ) {
			continue;
		}
		$speakers = wp_get_post_terms( $p->ID, 'speaker', array( 'fields' => 'names' ) );
		$out[]    = array(
			'id'       => $p->ID,
			'title'    => html_entity_decode( get_the_title( $p ), ENT_QUOTES ),
			'date'     => get_the_date( 'j M Y', $p ),
			'speaker'  => is_wp_error( $speakers ) ? '' : implode( ', ', $speakers ),
			'duration' => (string) get_post_meta( $p->ID, '_shiur_duration', true ),
			'audio'    => (string) wp_get_attachment_url( $a ),
		);
	}
	return array( 'shiurim' => $out );
}

function nm_ac_rest_settings( WP_REST_Request $request ) {
	$new = array();
	if ( $request->has_param( 'auto' ) ) {
		$new['auto'] = sanitize_key( (string) $request->get_param( 'auto' ) );
	}
	if ( $request->has_param( 'strength' ) ) {
		$new['strength'] = sanitize_key( (string) $request->get_param( 'strength' ) );
	}
	if ( $request->has_param( 'normalize' ) ) {
		$new['normalize'] = (int) (bool) $request->get_param( 'normalize' );
	}
	if ( $request->has_param( 'bitrate' ) ) {
		$new['bitrate'] = (int) $request->get_param( 'bitrate' );
	}
	nm_ac_save_settings( $new );
	return nm_ac_status_for_screen();
}

function nm_ac_rest_helper_key() {
	return array( 'key' => nm_ac_new_helper_key() );
}

/** Kaggle settings (the token is never sent back), or "start now". */
function nm_ac_rest_kaggle( WP_REST_Request $request ) {
	if ( $request->get_param( 'start' ) ) {
		$r = nm_ac_wake_kaggle( true );
		if ( is_wp_error( $r ) ) {
			return new WP_Error( 'nm_ac_kaggle', $r->get_error_message(), array( 'status' => 400 ) );
		}
		return array( 'started' => (bool) $r, 'status' => nm_ac_status_for_screen() );
	}
	if ( $request->get_param( 'check' ) ) {
		$r = nm_ac_kaggle_ready() && nm_ac_kaggle()['last_push'] ? nm_ac_kaggle_status() : '';
		if ( is_wp_error( $r ) ) {
			nm_ac_kaggle_save( array( 'last_message' => $r->get_error_message() ) );
		}
		return array( 'status' => nm_ac_status_for_screen() );
	}
	$new = array( 'enabled' => (int) (bool) $request->get_param( 'enabled' ) );
	if ( $request->has_param( 'username' ) ) {
		$new['username'] = sanitize_user( (string) $request->get_param( 'username' ), true );
	}
	$token = trim( (string) $request->get_param( 'token' ) );
	if ( '' !== $token ) {
		$new['token'] = preg_replace( '/[^A-Za-z0-9_\-]/', '', $token );
	}
	nm_ac_kaggle_save( $new );
	if ( $new['enabled'] ) {
		nm_ac_wake_kaggle();
	}
	return array( 'status' => nm_ac_status_for_screen() );
}
