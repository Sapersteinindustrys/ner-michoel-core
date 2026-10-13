<?php
/**
 * Backups to Bunny. Every day the whole site, the database and wp-content (themes, plugins, uploads), is
 * packed into a zip and sent to Bunny Storage, where three copies are kept, each replacing the one before:
 *
 *   daily/      replaced by every backup (each night at 3 a.m. site time unless changed on the screen);
 *   monthly/    replaced by the first backup of each month, normally the one on the 1st;
 *   bimonthly/  replaced every two months: by the first backup of a month two or more months after its own.
 *
 * An empty slot is filled by the next backup, so the first one fills all three. A new copy replaces an old
 * one only once it is on Bunny in full (Bunny checks the SHA-256 we send, and its size is checked after):
 * the old copy is deleted after that, never before. The audio, video and PDF files already kept on Bunny
 * (bunny-storage.php) aren't copied again: the database holds every file's address.
 *
 * Where: the media Storage Zone, in a folder with a long random name (files in that zone can be fetched
 * through its Pull Zone by anyone who knows the exact address), or a Storage Zone of the backups' own when
 * one is entered on the screen (with no Pull Zone, nobody can read it without the key).
 *
 * How: a site takes longer to pack than a host lets one PHP request run, so a backup is a job done in steps
 * of about 20 seconds, each saving where it got to: database → list of files → zip, a file at a time
 * (backups-zip.php) → send each piece to each slot due, checked → delete the old copies → tidy up. Each step
 * starts the next with a request to the site itself (backups/step, with the job's own token), WP-Cron a minute
 * later is the fallback, and a 5-minute check carries on a job that stopped. A job that fails three times in
 * a row at the same point stops, leaves the old copies as they were, and emails the site's admin address.
 *
 * Screen: Control Panel > Advanced > Backups (backups-screen.php).
 * REST (manage_options): GET backups/status, POST backups/run, POST backups/cancel, POST backups/settings,
 * GET backups/remote. Public but token-checked: POST backups/step. Download (manage_options):
 * admin-post.php?action=nm_backup_download&slot=…&file=….
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/backups-zip.php';

/**
 * A problem in a phase that also changed the job (a half-made piece put back to its start, say): the step
 * keeps that job, counts the try, and tries again later.
 */
class NM_Backup_Retry extends Exception {
	public $job;

	public function __construct( $message, $job ) {
		parent::__construct( $message );
		$this->job = $job;
	}
}

/* ------------------------------------------------------------------ */
/* Settings and where the copies go                                    */
/* ------------------------------------------------------------------ */

function ner_michoel_backup_slot_keys() {
	return array( 'daily', 'monthly', 'bimonthly' );
}

/**
 * enabled, hour (0-23, site time), email (on failure), folder (made once: 'site-backups-' and 20 random
 * letters and digits), and an optional Storage Zone of the backups' own: zone_name, region, api_key.
 */
function ner_michoel_backup_settings() {
	$saved = get_option( 'nm_backup_settings', array() );
	$s     = wp_parse_args(
		is_array( $saved ) ? $saved : array(),
		array(
			'enabled'   => true,
			'hour'      => 3,
			'email'     => true,
			'folder'    => '',
			'zone_name' => '',
			'region'    => '',
			'api_key'   => '',
		)
	);
	if ( ! preg_match( '/^site-backups-[a-z0-9]{20}$/', (string) $s['folder'] ) ) {
		$s['folder'] = 'site-backups-' . strtolower( wp_generate_password( 20, false, false ) );
		update_option( 'nm_backup_settings', $s, true ); // Small, and read on every load (the schedule check).
	}
	$s['hour'] = min( 23, max( 0, (int) $s['hour'] ) );
	return $s;
}

/**
 * The Storage Zone the copies go to: array( zone, region, key, own ), or null when there is none yet. $s: the
 * backups' settings to work it out from (the saved ones when not given).
 */
function ner_michoel_backup_target( $s = null ) {
	$s = $s ? $s : ner_michoel_backup_settings();
	if ( '' !== $s['zone_name'] && '' !== $s['api_key'] ) {
		return array(
			'zone'   => $s['zone_name'],
			'region' => $s['region'],
			'key'    => $s['api_key'],
			'own'    => true,
		);
	}
	$b = ner_michoel_bunny_storage_settings();
	if ( '' !== (string) $b['zone_name'] && '' !== (string) $b['api_key'] ) {
		return array(
			'zone'   => $b['zone_name'],
			'region' => $b['region'],
			'key'    => $b['api_key'],
			'own'    => false,
		);
	}
	return null;
}

/**
 * Which place a target is (zone, own or media, region), to tell when it changes. '' for none.
 */
function ner_michoel_backup_target_id( $target ) {
	return $target ? $target['zone'] . '|' . ( $target['own'] ? 'own' : 'media' ) . '|' . strtolower( trim( (string) $target['region'] ) ) : '';
}

/**
 * The storage address of $path in $target's zone (each part encoded; a trailing slash is kept, which is how
 * Bunny is asked for a folder's list). The media zone gets the same host as uploads do (bunny-storage.php);
 * a zone of the backups' own with no region, or "de", is Falkenstein's plain storage.bunnycdn.com.
 */
function ner_michoel_backup_endpoint( $path, $target ) {
	$region = strtolower( trim( (string) $target['region'] ) );
	if ( '' === $region || ( $target['own'] && in_array( $region, array( 'de', 'falkenstein' ), true ) ) ) {
		$host = 'storage.bunnycdn.com';
	} else {
		$host = rawurlencode( $region ) . '.storage.bunnycdn.com';
	}
	$parts = array_map( 'rawurlencode', explode( '/', ltrim( $path, '/' ) ) );
	$url   = 'https://' . $host . '/' . rawurlencode( $target['zone'] ) . '/' . implode( '/', $parts );
	return apply_filters( 'ner_michoel_backup_storage_endpoint', $url, $path, $target );
}

/** The folder of a slot, with its slash: 'site-backups-…/daily/'. */
function ner_michoel_backup_slot_folder( $slot ) {
	$s = ner_michoel_backup_settings();
	return $s['folder'] . '/' . $slot . '/';
}

/* ------------------------------------------------------------------ */
/* Talking to Bunny Storage                                            */
/* ------------------------------------------------------------------ */

/**
 * One request to Bunny Storage with cURL (it can send a file from disk and pass one on without holding it in
 * memory, which wp_remote_request() can't). $opts: timeout, headers, upload (array( handle, size )), write
 * and header (callbacks for a download), progress (callback). Returns array( code, body, error ).
 */
function ner_michoel_backup_curl( $method, $path, $opts = array(), $target = null ) {
	$target = $target ? $target : ner_michoel_backup_target();
	if ( ! $target ) {
		return array( 'code' => 0, 'body' => '', 'error' => __( 'No Bunny storage is set up.', 'ner-michoel-core' ) );
	}
	if ( ! function_exists( 'curl_init' ) ) {
		return array( 'code' => 0, 'body' => '', 'error' => __( 'This server’s PHP has no cURL, which the backups need.', 'ner-michoel-core' ) );
	}

	$ch      = curl_init();
	$headers = array( 'AccessKey: ' . $target['key'], 'Expect:' );
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_URL             => ner_michoel_backup_endpoint( $path, $target ),
			CURLOPT_CUSTOMREQUEST   => $method,
			CURLOPT_CONNECTTIMEOUT  => 20,
			CURLOPT_LOW_SPEED_LIMIT => 1024,
			CURLOPT_LOW_SPEED_TIME  => 90,
			CURLOPT_FOLLOWLOCATION  => false,
			CURLOPT_USERAGENT       => 'NerMichoelBackups/' . NER_MICHOEL_CORE_VERSION,
		)
	);
	$ca = ABSPATH . WPINC . '/certificates/ca-bundle.crt';
	if ( file_exists( $ca ) ) {
		curl_setopt( $ch, CURLOPT_CAINFO, $ca );
	}
	if ( isset( $opts['timeout'] ) ) {
		curl_setopt( $ch, CURLOPT_TIMEOUT, (int) $opts['timeout'] );
	}
	if ( isset( $opts['upload'] ) ) {
		curl_setopt( $ch, CURLOPT_UPLOAD, true );
		curl_setopt( $ch, CURLOPT_INFILE, $opts['upload'][0] );
		curl_setopt( $ch, CURLOPT_INFILESIZE, (int) $opts['upload'][1] );
		$headers[] = 'Content-Type: application/octet-stream';
	}
	if ( ! empty( $opts['headers'] ) ) {
		$headers = array_merge( $headers, $opts['headers'] );
	}
	curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
	if ( isset( $opts['write'] ) ) {
		curl_setopt( $ch, CURLOPT_WRITEFUNCTION, $opts['write'] );
		if ( isset( $opts['header'] ) ) {
			curl_setopt( $ch, CURLOPT_HEADERFUNCTION, $opts['header'] );
		}
	} else {
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
	}
	if ( isset( $opts['progress'] ) ) {
		curl_setopt( $ch, CURLOPT_NOPROGRESS, false );
		curl_setopt( $ch, CURLOPT_PROGRESSFUNCTION, $opts['progress'] );
	}

	$body  = curl_exec( $ch );
	$code  = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
	$errno = curl_errno( $ch );
	$error = $errno ? curl_error( $ch ) : '';
	unset( $ch ); // Closes the handle (curl_close() does nothing since PHP 8 and is deprecated in 8.5).

	return array(
		'code'  => $code,
		'body'  => is_string( $body ) ? $body : '',
		'error' => $error,
	);
}

/** "Bunny answered 401: …" / "Couldn't reach Bunny: …" for a failed request. */
function ner_michoel_backup_http_error( $r ) {
	if ( ! $r['code'] ) {
		/* translators: %s: the error */
		return sprintf( __( 'Couldn’t reach Bunny: %s', 'ner-michoel-core' ), $r['error'] ? $r['error'] : __( 'no answer', 'ner-michoel-core' ) );
	}
	$said = trim( wp_strip_all_tags( (string) $r['body'] ) );
	$json = json_decode( (string) $r['body'], true );
	if ( is_array( $json ) && isset( $json['Message'] ) ) {
		$said = (string) $json['Message'];
	}
	if ( 401 === $r['code'] || 403 === $r['code'] ) {
		/* translators: %d: HTTP status */
		return sprintf( __( 'Bunny refused the Storage Zone’s name or password (%d). Check them on the Storage screen.', 'ner-michoel-core' ), $r['code'] );
	}
	/* translators: 1: HTTP status, 2: what Bunny said */
	return trim( sprintf( __( 'Bunny answered %1$d %2$s', 'ner-michoel-core' ), $r['code'], substr( $said, 0, 160 ) ) );
}

/**
 * The files in a folder: array of array( name, size, changed, checksum ), or a WP_Error. A folder that isn't
 * there yet is empty.
 */
function ner_michoel_backup_list_folder( $folder, $target = null ) {
	$r = ner_michoel_backup_curl( 'GET', trailingslashit( $folder ), array( 'timeout' => 30 ), $target );
	if ( 404 === $r['code'] ) {
		return array();
	}
	if ( 200 !== $r['code'] ) {
		return new WP_Error( 'nm_backup_list', ner_michoel_backup_http_error( $r ) );
	}
	$data = json_decode( $r['body'], true );
	if ( ! is_array( $data ) ) {
		return new WP_Error( 'nm_backup_list', __( 'Bunny’s list of files couldn’t be read.', 'ner-michoel-core' ) );
	}
	$out = array();
	foreach ( $data as $object ) {
		if ( ! is_array( $object ) || ! empty( $object['IsDirectory'] ) || ! isset( $object['ObjectName'] ) ) {
			continue;
		}
		$out[] = array(
			'name'     => (string) $object['ObjectName'],
			'size'     => isset( $object['Length'] ) ? (int) $object['Length'] : 0,
			'changed'  => isset( $object['LastChanged'] ) ? (string) $object['LastChanged'] : '',
			'checksum' => isset( $object['Checksum'] ) ? (string) $object['Checksum'] : '',
		);
	}
	usort(
		$out,
		function ( $a, $b ) {
			return strcmp( $b['name'], $a['name'] );
		}
	);
	return $out;
}

function ner_michoel_backup_delete_remote( $path ) {
	$r = ner_michoel_backup_curl( 'DELETE', $path, array( 'timeout' => 30 ) );
	return ( $r['code'] >= 200 && $r['code'] < 300 ) || 404 === $r['code'];
}

/**
 * Sends a local file to $remote, then checks it is there with the right size. Bunny is given the file's
 * SHA-256 and refuses the upload if what arrived differs; if it refuses the header itself (400), the file is
 * sent again without it and the checksum in Bunny's list is compared instead. True, or a WP_Error.
 */
function ner_michoel_backup_send( $file, $remote, $size, $sha256, $progress = null ) {
	$with_sum = true;
	for ( $round = 0; $round < 2; $round++ ) {
		$fh = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			return new WP_Error( 'nm_backup_send', __( 'The backup file on this server couldn’t be opened to send.', 'ner-michoel-core' ) );
		}
		$opts = array( 'upload' => array( $fh, $size ) );
		if ( $with_sum ) {
			$opts['headers'] = array( 'Checksum: ' . strtoupper( $sha256 ) );
		}
		if ( $progress ) {
			$opts['progress'] = $progress;
		}
		$r = ner_michoel_backup_curl( 'PUT', $remote, $opts );
		fclose( $fh );

		if ( 400 === $r['code'] && $with_sum ) {
			$with_sum = false;
			continue;
		}
		break;
	}
	if ( $r['code'] < 200 || $r['code'] >= 300 ) {
		return new WP_Error( 'nm_backup_send', ner_michoel_backup_http_error( $r ) );
	}

	$list = ner_michoel_backup_list_folder( dirname( $remote ) . '/' );
	if ( is_wp_error( $list ) ) {
		return $list;
	}
	$name = basename( $remote );
	foreach ( $list as $item ) {
		if ( $item['name'] !== $name ) {
			continue;
		}
		if ( (int) $item['size'] !== (int) $size ) {
			/* translators: 1, 2: numbers of bytes */
			return new WP_Error( 'nm_backup_send', sprintf( __( 'Bunny has %1$s bytes of the backup, not %2$s.', 'ner-michoel-core' ), number_format_i18n( $item['size'] ), number_format_i18n( $size ) ) );
		}
		if ( ! $with_sum && preg_match( '/^[0-9a-f]{64}$/i', $item['checksum'] ) && 0 !== strcasecmp( $item['checksum'], $sha256 ) ) {
			return new WP_Error( 'nm_backup_send', __( 'The copy on Bunny differs from the one sent (checksum).', 'ner-michoel-core' ) );
		}
		return true;
	}
	return new WP_Error( 'nm_backup_send', __( 'Bunny took the backup but it isn’t in its list of files.', 'ner-michoel-core' ) );
}

/* ------------------------------------------------------------------ */
/* Which copies a backup replaces                                      */
/* ------------------------------------------------------------------ */

/**
 * "Now" for the slots and the file names. A filter so the tests can try the 1st of a month.
 */
function ner_michoel_backup_now() {
	return (int) apply_filters( 'ner_michoel_backup_now', time() );
}

/**
 * The copies on Bunny as this site last left them: slot => array( id, made, month ('Y-m', site time),
 * files (array of array( name, size, sha256 )), size ), or no entry for an empty slot.
 */
function ner_michoel_backup_slots() {
	$slots = get_option( 'nm_backup_slots', array() );
	return is_array( $slots ) ? $slots : array();
}

function ner_michoel_backup_month_number( $ym ) {
	$p = explode( '-', (string) $ym );
	return count( $p ) < 2 ? 0 : (int) $p[0] * 12 + (int) $p[1] - 1;
}

/**
 * The slots a backup made at $now replaces: always the daily one; the monthly one if it is empty or from an
 * earlier month; the two-monthly one if it is empty or from two or more months before.
 */
function ner_michoel_backup_due_slots( $now ) {
	$slots = ner_michoel_backup_slots();
	$month = ner_michoel_backup_month_number( wp_date( 'Y-m', $now ) );
	$due   = array( 'daily' );
	if ( empty( $slots['monthly']['month'] ) || ner_michoel_backup_month_number( $slots['monthly']['month'] ) < $month ) {
		$due[] = 'monthly';
	}
	if ( empty( $slots['bimonthly']['month'] ) || $month - ner_michoel_backup_month_number( $slots['bimonthly']['month'] ) >= 2 ) {
		$due[] = 'bimonthly';
	}
	return $due;
}

/** "yeshivas-toras-moshe-backup-2026-10-12-030000" (site time). */
function ner_michoel_backup_base_name( $now ) {
	$slug = sanitize_title( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
	$slug = trim( substr( preg_replace( '/[^a-z0-9-]+/', '', $slug ), 0, 40 ), '-' );
	return ( '' === $slug ? 'site' : $slug ) . '-backup-' . wp_date( 'Y-m-d-His', $now );
}

/** Piece 1 is "<base>.zip", the next ones "<base>-part2.zip" and so on. */
function ner_michoel_backup_part_name( $base, $n ) {
	return $base . ( $n > 1 ? '-part' . $n : '' ) . '.zip';
}

/* ------------------------------------------------------------------ */
/* The job                                                             */
/* ------------------------------------------------------------------ */

function ner_michoel_backup_work_root() {
	return WP_CONTENT_DIR . '/nm-backup-work';
}

/** Seconds of work in one step (a host stops a request after 30 to 60 seconds). */
function ner_michoel_backup_step_seconds() {
	return max( 3, (int) apply_filters( 'ner_michoel_backup_step_seconds', 20 ) );
}

/** A backup piece is closed once it would pass this many bytes (1 GB). */
function ner_michoel_backup_part_limit() {
	return max( 1048576, min( 3221225472, (int) apply_filters( 'ner_michoel_backup_part_limit', 1073741824 ) ) );
}

/** A file this big or bigger is left out (and listed in the README). */
function ner_michoel_backup_max_file() {
	return min( NM_Backup_Zip::MAX_FILE, (int) apply_filters( 'ner_michoel_backup_max_file', NM_Backup_Zip::MAX_FILE ) );
}

/**
 * The running job, or null. $fresh skips what this request already read (another request may have changed it).
 */
function ner_michoel_backup_job( $fresh = false ) {
	if ( $fresh ) {
		wp_cache_delete( 'nm_backup_job', 'options' );
	}
	$job = get_option( 'nm_backup_job' );
	return is_array( $job ) && ! empty( $job['id'] ) ? $job : null;
}

/** Notes a file left out: the first 200 by name, all of them in the count. */
function ner_michoel_backup_skip( $job, $entry ) {
	if ( count( $job['skipped'] ) < 200 ) {
		$job['skipped'][] = $entry;
	}
	$job['skipped_n'] = ( isset( $job['skipped_n'] ) ? (int) $job['skipped_n'] : 0 ) + 1;
	return $job;
}

function ner_michoel_backup_save_job( $job ) {
	$job['touched'] = time();
	update_option( 'nm_backup_job', $job, false );
	return $job;
}

/**
 * The lock: only one step works on the job at a time. An option row whose value is the time it was taken or
 * last refreshed; one older than 5 minutes is from a request the host stopped, and is taken over. Straight
 * SQL, because add_option() goes through caches and a race needs the database's own answer.
 */
function ner_michoel_backup_lock() {
	global $wpdb;
	$now = time();
	$got = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES ('nm_backup_lock', %s, 'no')", (string) $now ) );
	if ( ! $got ) {
		$at = $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'nm_backup_lock'" );
		if ( null !== $at && (int) $at > $now - 5 * MINUTE_IN_SECONDS ) {
			return false;
		}
		$got = null === $at
			? $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES ('nm_backup_lock', %s, 'no')", (string) $now ) )
			: $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = 'nm_backup_lock' AND option_value = %s", (string) $now, (string) $at ) );
		if ( ! $got ) {
			return false;
		}
	}
	wp_cache_delete( 'nm_backup_lock', 'options' );
	return true;
}

function ner_michoel_backup_lock_refresh() {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = 'nm_backup_lock'", (string) time() ) );
}

function ner_michoel_backup_unlock() {
	global $wpdb;
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name = 'nm_backup_lock'" );
	wp_cache_delete( 'nm_backup_lock', 'options' );
}

function ner_michoel_backup_is_locked() {
	global $wpdb;
	$at = $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'nm_backup_lock'" );
	return null !== $at && (int) $at > time() - 5 * MINUTE_IN_SECONDS;
}

/** Whether Stop was pressed for this job (read from the database, not a cache another request filled). */
function ner_michoel_backup_cancel_requested( $id ) {
	global $wpdb;
	return (string) $id === (string) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'nm_backup_cancel'" );
}

/**
 * Starts a backup ('schedule' or 'manual'). Returns the job, or a WP_Error when one is already running or
 * there is nowhere to send it.
 */
function ner_michoel_backup_start( $trigger ) {
	if ( ! ner_michoel_backup_target() ) {
		return new WP_Error( 'nm_backup_no_target', __( 'Bunny storage isn’t set up yet, so there is nowhere to keep the backups. Fill in Advanced > Storage (Bunny) first.', 'ner-michoel-core' ), array( 'status' => 400 ) );
	}
	if ( ! ner_michoel_backup_lock() ) {
		return new WP_Error( 'nm_backup_busy', __( 'A backup is already running.', 'ner-michoel-core' ), array( 'status' => 409 ) );
	}
	if ( ner_michoel_backup_job() ) {
		ner_michoel_backup_unlock();
		return new WP_Error( 'nm_backup_busy', __( 'A backup is already running.', 'ner-michoel-core' ), array( 'status' => 409 ) );
	}

	$now = ner_michoel_backup_now();
	$job = ner_michoel_backup_save_job(
		array(
			'id'      => wp_date( 'Ymd-His', $now ) . '-' . strtolower( wp_generate_password( 4, false, false ) ),
			'token'   => wp_generate_password( 32, false, false ),
			'trigger' => 'manual' === $trigger ? 'manual' : 'schedule',
			'started' => time(),
			'now'     => $now,
			'due'     => ner_michoel_backup_due_slots( $now ),
			'base'    => ner_michoel_backup_base_name( $now ),
			'target'  => ner_michoel_backup_target_id( ner_michoel_backup_target() ),
			'phase'   => 'start',
			'tries'   => 0,
			'error'   => '',
			'running' => 0,
			'db'      => array(),
			'scan'    => array(),
			'pack'    => array(),
			'parts'     => array(),
			'skipped'   => array(),
			'skipped_n' => 0,
			'notes'     => array(),
		)
	);
	delete_option( 'nm_backup_cancel' );
	ner_michoel_backup_unlock();

	ner_michoel_backup_continue( $job );
	return $job;
}

/**
 * Runs one step of the job: about 20 seconds of work, saved as it goes. $source is only for the log.
 * Returns 'idle', 'locked', 'deferred', or the job's phase after the step.
 */
function ner_michoel_backup_step( $source = '' ) {
	$job = ner_michoel_backup_job();
	if ( ! $job ) {
		return 'idle';
	}
	if ( 'manual' !== $job['trigger'] && 'start' === $job['phase'] && function_exists( 'ner_michoel_speed_defer_if_busy' ) && ner_michoel_speed_defer_if_busy( 'nm_backup_step', __( 'Nightly backup', 'ner-michoel-core' ) ) ) {
		return 'deferred'; // The site is busy: back in 5 minutes (site-speed.php), for an hour at most.
	}
	if ( ! ner_michoel_backup_lock() ) {
		return 'locked';
	}
	$job = ner_michoel_backup_job( true ); // Again, now that it's ours: another step may have just finished it.
	if ( ! $job ) {
		ner_michoel_backup_unlock();
		return 'idle';
	}

	// Where the copies go was changed while this backup ran (the Storage screen, say): its pieces would be split
	// between two places, so it stops. The next backup goes to the new place.
	if ( isset( $job['target'] ) && ner_michoel_backup_target_id( ner_michoel_backup_target() ) !== $job['target'] ) {
		ner_michoel_backup_finish( $job, 'cancelled', __( 'Stopped: where the backups are kept was changed while it ran. The next backup goes to the new place.', 'ner-michoel-core' ) );
		ner_michoel_backup_unlock();
		return 'cancelled';
	}

	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- some hosts don't allow it; the steps are short anyway.
	}
	ignore_user_abort( true );

	// A step that started and never finished was stopped by the host (time or memory): that counts as a try.
	if ( ! empty( $job['running'] ) ) {
		$job['tries']++;
		/* translators: %s: what the backup was doing */
		$job['error'] = sprintf( __( 'The server stopped the backup while %s (it ran out of time or memory).', 'ner-michoel-core' ), ner_michoel_backup_phase_text( $job ) );
		if ( $job['tries'] >= 3 ) {
			ner_michoel_backup_fail( $job, $job['error'] );
			ner_michoel_backup_unlock();
			return 'failed';
		}
	}
	$job['running'] = time();
	$job            = ner_michoel_backup_save_job( $job );

	$deadline = time() + ner_michoel_backup_step_seconds();
	$retry    = false;
	try {
		while ( time() < $deadline ) {
			if ( ner_michoel_backup_cancel_requested( $job['id'] ) ) {
				ner_michoel_backup_finish( $job, 'cancelled', __( 'Stopped by hand.', 'ner-michoel-core' ) );
				ner_michoel_backup_unlock();
				return 'cancelled';
			}
			$job          = ner_michoel_backup_slice( $job, min( $deadline, time() + 3 ) );
			$job['tries'] = 0;
			$job['error'] = '';
			if ( 'done' === $job['phase'] ) {
				ner_michoel_backup_finish( $job, 'ok' );
				ner_michoel_backup_unlock();
				return 'done';
			}
			$job = ner_michoel_backup_save_job( $job );
			ner_michoel_backup_lock_refresh();
			if ( 'upload' === $job['phase'] && time() > $deadline - 10 ) {
				break; // An upload can't be split: it starts a fresh step instead.
			}
		}
	} catch ( Exception $e ) {
		if ( $e instanceof NM_Backup_Retry ) {
			$job = $e->job;
		}
		if ( 'nm_backup_cancelled' === $e->getMessage() ) {
			ner_michoel_backup_finish( $job, 'cancelled', __( 'Stopped by hand.', 'ner-michoel-core' ) );
			ner_michoel_backup_unlock();
			return 'cancelled';
		}
		$job['tries']++;
		$job['error'] = $e->getMessage();
		if ( $job['tries'] >= 3 ) {
			ner_michoel_backup_fail( $job, $job['error'] );
			ner_michoel_backup_unlock();
			return 'failed';
		}
		$retry = true;
	}

	$job['running'] = 0;
	$job            = ner_michoel_backup_save_job( $job );
	ner_michoel_backup_unlock();
	ner_michoel_backup_continue( $job, $retry );
	return $job['phase'];
}

/**
 * Arranges the next step: straight away by a request to the site itself, with WP-Cron as the fallback (a
 * minute later, or longer after a failed try, which isn't hurried).
 */
function ner_michoel_backup_continue( $job, $retry = false ) {
	$when = time() + ( $retry ? 60 * max( 1, (int) $job['tries'] ) : 60 );
	$next = wp_next_scheduled( 'nm_backup_step' );
	if ( $next && ( $next > $when + 5 || $retry ) ) {
		wp_unschedule_event( $next, 'nm_backup_step' );
		$next = false;
	}
	if ( ! $next ) {
		wp_schedule_single_event( $when, 'nm_backup_step' );
	}
	if ( ! $retry ) {
		ner_michoel_backup_kick( $job );
	}
}

/**
 * Asks the site to run the next step, without waiting for it. Browser-like headers: the host's firewall
 * turns away requests that don't look like one.
 */
function ner_michoel_backup_kick( $job ) {
	if ( ! apply_filters( 'ner_michoel_backup_loopback', true ) ) {
		return;
	}
	wp_remote_post(
		rest_url( 'ner-michoel/v1/backups/step' ),
		array(
			'timeout'     => 1,
			'blocking'    => false,
			'redirection' => 0,
			'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
			'user-agent'  => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36 NerMichoelBackups/' . NER_MICHOEL_CORE_VERSION,
			'headers'     => array(
				'Accept'          => 'application/json, text/plain, */*',
				'Accept-Language' => 'en-US,en;q=0.9',
				'Referer'         => admin_url(),
				'Origin'          => home_url(),
			),
			'body'        => array( 'token' => $job['token'] ),
		)
	);
}

/**
 * Up to $until (a few seconds) of the current phase. Throws on a problem; the step counts the tries.
 */
function ner_michoel_backup_slice( $job, $until ) {
	switch ( $job['phase'] ) {
		case 'start':
			return ner_michoel_backup_phase_start( $job );
		case 'db':
			return ner_michoel_backup_phase_db( $job, $until );
		case 'scan':
			return ner_michoel_backup_phase_scan( $job );
		case 'pack':
			return ner_michoel_backup_phase_pack( $job, $until );
		case 'upload':
			return ner_michoel_backup_phase_upload( $job );
		case 'prune':
			return ner_michoel_backup_phase_prune( $job );
	}
	$job['phase'] = 'done';
	return $job;
}

/* ------------------------------------------------------------------ */
/* Phases                                                              */
/* ------------------------------------------------------------------ */

/**
 * A folder for this job in wp-content/nm-backup-work (closed to the web), the list of tables, and the top of
 * database.sql. Leftovers of an earlier job that was cut off are removed.
 */
function ner_michoel_backup_phase_start( $job ) {
	global $wpdb;

	$root = ner_michoel_backup_work_root();
	if ( ! wp_mkdir_p( $root ) ) {
		throw new Exception( __( 'Couldn’t make the backup’s work folder in wp-content (is it writable?).', 'ner-michoel-core' ) );
	}
	if ( ! file_exists( $root . '/.htaccess' ) ) {
		file_put_contents( $root . '/.htaccess', "# Backups being made: never served.\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}
	if ( ! file_exists( $root . '/index.php' ) ) {
		file_put_contents( $root . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}
	$old = glob( $root . '/*', GLOB_ONLYDIR );
	foreach ( $old ? $old : array() as $dir ) {
		ner_michoel_backup_rmdir( $dir );
	}

	$dir = $root . '/' . $job['id'] . '-' . strtolower( wp_generate_password( 12, false, false ) );
	if ( ! wp_mkdir_p( $dir ) ) {
		throw new Exception( __( 'Couldn’t make the backup’s work folder in wp-content (is it writable?).', 'ner-michoel-core' ) );
	}
	$job['dir'] = $dir;

	// Tables of this site (its prefix), not views, and not other sites' tables in the same database.
	$like   = $wpdb->esc_like( $wpdb->base_prefix ) . '%';
	$tables = array();
	$rows   = $wpdb->get_results( $wpdb->prepare( 'SHOW FULL TABLES LIKE %s', $like ), ARRAY_N );
	if ( $wpdb->last_error || ! is_array( $rows ) ) {
		$rows = array_map(
			function ( $t ) {
				return array( $t, 'BASE TABLE' );
			},
			(array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) )
		);
	}
	foreach ( $rows as $row ) {
		if ( isset( $row[0] ) && ( ! isset( $row[1] ) || 'VIEW' !== strtoupper( (string) $row[1] ) ) ) {
			$tables[] = (string) $row[0];
		}
	}
	if ( ! $tables ) {
		throw new Exception( __( 'The database’s list of tables came back empty.', 'ner-michoel-core' ) );
	}
	sort( $tables );

	$head  = "-- " . sprintf( 'Database of %s (%s)', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), home_url() ) . "\n";
	$head .= '-- Made ' . wp_date( 'Y-m-d H:i:s T', $job['now'] ) . ' by Ner Michoel Core ' . NER_MICHOEL_CORE_VERSION . ', WordPress ' . get_bloginfo( 'version' ) . "\n";
	$head .= '-- ' . count( $tables ) . ' tables, prefix ' . $wpdb->base_prefix . "\n\n";
	$head .= '/*!40101 SET NAMES ' . ( $wpdb->charset ? $wpdb->charset : 'utf8mb4' ) . " */;\n";
	$head .= "SET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\nSET AUTOCOMMIT = 0;\nSTART TRANSACTION;\n";
	if ( false === file_put_contents( $dir . '/database.sql', $head ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		throw new Exception( __( 'Couldn’t write the database copy (is the disk full?).', 'ner-michoel-core' ) );
	}

	$job['db']    = array(
		'tables'   => $tables,
		'ti'       => 0,
		'begun'    => false,
		'pk'       => array(),
		'bin'      => array(),
		'bits'     => array(),
		'select'   => '*',
		'last'     => null,
		'offset'   => 0,
		'n'        => 500,
		'rows'     => 0,
		'sql_size' => strlen( $head ),
	);
	$job['phase'] = 'db';
	return $job;
}

function ner_michoel_backup_ident( $name ) {
	return '`' . str_replace( '`', '``', $name ) . '`';
}

/**
 * A table's columns: array( primary key columns in key order (empty when it has none), binary columns,
 * BIT columns, the SELECT list ('*', or the columns with each BIT one read as a number) ).
 */
function ner_michoel_backup_table_columns( $table ) {
	global $wpdb;
	$t    = ner_michoel_backup_ident( $table );
	$cols = $wpdb->get_results( "SHOW COLUMNS FROM {$t}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( $wpdb->last_error ) {
		throw new Exception( sprintf( 'SHOW COLUMNS %s: %s', $table, $wpdb->last_error ) );
	}
	$bin    = array();
	$bits   = array();
	$pri    = array();
	$select = array();
	foreach ( (array) $cols as $c ) {
		$col = ner_michoel_backup_ident( $c['Field'] );
		if ( preg_match( '/^bit/i', (string) $c['Type'] ) ) {
			// PHP's MySQL driver gives a BIT value back as decimal text, so it's read as a number and written as one.
			$bits[]   = $c['Field'];
			$select[] = "{$col} + 0 AS {$col}";
		} else {
			$select[] = $col;
			if ( preg_match( '/^(tiny|medium|long)?blob|^(var)?binary/i', (string) $c['Type'] ) ) {
				$bin[] = $c['Field'];
			}
		}
		if ( 'PRI' === $c['Key'] ) {
			$pri[] = $c['Field'];
		}
	}
	$select = $bits ? implode( ', ', $select ) : '*';
	$keys = $wpdb->get_results( "SHOW KEYS FROM {$t} WHERE Key_name = 'PRIMARY'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( ! $wpdb->last_error && $keys && count( $keys ) === count( $pri ) ) {
		usort(
			$keys,
			function ( $a, $b ) {
				return (int) $a['Seq_in_index'] - (int) $b['Seq_in_index'];
			}
		);
		$pri = wp_list_pluck( $keys, 'Column_name' );
	}
	return array( $pri, $bin, $bits, $select );
}

/** A value as SQL: NULL, a quoted string, for a binary column 0x…, for a BIT column its number. */
function ner_michoel_backup_sql_value( $v, $binary, $bit = false ) {
	global $wpdb;
	if ( null === $v ) {
		return 'NULL';
	}
	$v = (string) $v;
	if ( $bit && preg_match( '/^[0-9]+$/', $v ) ) {
		return $v;
	}
	if ( $binary ) {
		return '' === $v ? "''" : '0x' . bin2hex( $v );
	}
	return "'" . $wpdb->remove_placeholder_escape( $wpdb->_real_escape( $v ) ) . "'";
}

/**
 * database.sql, a few hundred rows at a time: each table's CREATE TABLE, then its rows in primary key order,
 * read on from the last key written (so a row added while the backup runs can't be written twice). The file
 * is first cut back to its size at the last save, so rows a stopped step wrote after it aren't written twice.
 */
function ner_michoel_backup_phase_db( $job, $until ) {
	global $wpdb;
	$db   = $job['db'];
	$path = $job['dir'] . '/database.sql';
	clearstatcache( true, $path );
	$fh = file_exists( $path ) && filesize( $path ) >= $db['sql_size'] ? fopen( $path, 'r+b' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
	if ( ! $fh ) {
		throw new Exception( __( 'The database copy on this server is missing or shorter than expected (is the disk full?).', 'ner-michoel-core' ) );
	}
	ftruncate( $fh, $db['sql_size'] );
	fseek( $fh, $db['sql_size'] );

	while ( $db['ti'] < count( $db['tables'] ) && time() < $until ) {
		$table = $db['tables'][ $db['ti'] ];
		$t     = ner_michoel_backup_ident( $table );

		if ( ! $db['begun'] ) {
			$create = $wpdb->get_row( "SHOW CREATE TABLE {$t}", ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $wpdb->last_error || empty( $create[1] ) ) {
				fclose( $fh );
				throw new Exception( sprintf( 'SHOW CREATE TABLE %s: %s', $table, $wpdb->last_error ) );
			}
			list( $db['pk'], $db['bin'], $db['bits'], $db['select'] ) = ner_michoel_backup_table_columns( $table );
			fwrite( $fh, "\n--\n-- Table {$t}\n--\n\nDROP TABLE IF EXISTS {$t};\n" . $create[1] . ";\n\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			$db['begun']  = true;
			$db['last']   = null;
			$db['offset'] = 0;
		}

		$n    = max( 10, (int) $db['n'] );
		$cols = isset( $db['select'] ) ? $db['select'] : '*';
		if ( $db['pk'] ) {
			$order = implode( ', ', array_map( 'ner_michoel_backup_ident', $db['pk'] ) );
			if ( null === $db['last'] ) {
				$sql = "SELECT {$cols} FROM {$t} ORDER BY {$order} LIMIT {$n}";
			} elseif ( 1 === count( $db['pk'] ) ) {
				$sql = $wpdb->prepare( "SELECT {$cols} FROM {$t} WHERE {$order} > %s ORDER BY {$order} LIMIT {$n}", $db['last'][0] ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			} else {
				$sql = $wpdb->prepare( "SELECT {$cols} FROM {$t} WHERE ({$order}) > (" . implode( ', ', array_fill( 0, count( $db['pk'] ), '%s' ) ) . ") ORDER BY {$order} LIMIT {$n}", $db['last'] ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders
			}
		} else {
			$sql = "SELECT {$cols} FROM {$t} LIMIT " . (int) $db['offset'] . ", {$n}";
		}
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $wpdb->last_error ) {
			fclose( $fh );
			throw new Exception( sprintf( 'SELECT %s: %s', $table, $wpdb->last_error ) );
		}

		if ( ! $rows ) {
			$db['ti']++;
			$db['begun'] = false;
			continue;
		}

		$bin    = array_flip( $db['bin'] );
		$bits   = array_flip( isset( $db['bits'] ) ? $db['bits'] : array() );
		$buffer = array();
		$length = 0;
		$bytes  = 0;
		foreach ( $rows as $row ) {
			$values = array();
			foreach ( $row as $col => $v ) {
				$values[] = ner_michoel_backup_sql_value( $v, isset( $bin[ $col ] ), isset( $bits[ $col ] ) );
			}
			$tuple = '(' . implode( ',', $values ) . ')';
			if ( $buffer && $length + strlen( $tuple ) > 1048576 ) {
				$bytes += ner_michoel_backup_write_insert( $fh, $t, $buffer );
				$buffer = array();
				$length = 0;
			}
			$buffer[] = $tuple;
			$length  += strlen( $tuple ) + 2;
		}
		$bytes += ner_michoel_backup_write_insert( $fh, $t, $buffer );

		$last = end( $rows );
		if ( $db['pk'] ) {
			$db['last'] = array();
			foreach ( $db['pk'] as $col ) {
				$db['last'][] = $last[ $col ];
			}
		}
		$db['offset'] += count( $rows );
		$db['rows']   += count( $rows );

		// Fewer rows at a time for big rows, more for small ones.
		if ( $bytes > 4194304 ) {
			$db['n'] = max( 10, (int) floor( $n / 2 ) );
		} elseif ( $bytes < 524288 && count( $rows ) === $n ) {
			$db['n'] = min( 5000, $n * 2 );
		}
		$wpdb->flush();
	}

	if ( $db['ti'] >= count( $db['tables'] ) ) {
		fwrite( $fh, "\nCOMMIT;\nSET FOREIGN_KEY_CHECKS = 1;\n-- End: " . count( $db['tables'] ) . ' tables, ' . $db['rows'] . " rows.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		$job['phase'] = 'scan';
	}
	fflush( $fh );
	$db['sql_size'] = ftell( $fh );
	fclose( $fh );
	$job['db'] = $db;
	return $job;
}

/**
 * Writes one INSERT of $tuples. Returns the bytes written; throws when the disk takes fewer (full). Whatever
 * was written is cut off again before the next try (the saved 'sql_size').
 */
function ner_michoel_backup_write_insert( $fh, $t, $tuples ) {
	if ( ! $tuples ) {
		return 0;
	}
	$sql = "INSERT INTO {$t} VALUES\n" . implode( ",\n", $tuples ) . ";\n";
	if ( fwrite( $fh, $sql ) !== strlen( $sql ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fclose( $fh );
		throw new Exception( __( 'Couldn’t write the database copy (is the disk full?).', 'ner-michoel-core' ) );
	}
	return strlen( $sql );
}

/**
 * Folders in wp-content that are never backed up (caches, the backups' own work folder, other backup tools'
 * archives), as paths below wp-content in lower case. And any '.git' or 'node_modules' folder.
 */
function ner_michoel_backup_excluded() {
	return (array) apply_filters(
		'ner_michoel_backup_excluded',
		array(
			'nm-backup-work',
			'cache',
			'endurance-page-cache',
			'litespeed',
			'et-cache',
			'upgrade',
			'upgrade-temp-backup',
			'updraft',
			'ai1wm-backups',
			'backups-dup-lite',
			'backups-dup-pro',
			'wpvividbackups',
			'debug.log',
		)
	);
}

/**
 * The list of files to back up, written to files.tsv (name, size): everything in wp-content but the folders
 * above. Files too big for a zip are listed as left out.
 */
function ner_michoel_backup_phase_scan( $job ) {
	$root = wp_normalize_path( untrailingslashit( WP_CONTENT_DIR ) );
	$skip = array_flip( array_map( 'strtolower', ner_michoel_backup_excluded() ) );
	$max  = ner_michoel_backup_max_file();
	$out  = fopen( $job['dir'] . '/files.tsv', 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
	if ( ! $out ) {
		throw new Exception( __( 'Couldn’t write the list of files (is the disk full?).', 'ner-michoel-core' ) );
	}

	$filter = new RecursiveCallbackFilterIterator(
		new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO ),
		function ( $file ) use ( $root, $skip ) {
			$rel  = strtolower( ltrim( substr( wp_normalize_path( $file->getPathname() ), strlen( $root ) ), '/' ) );
			$base = strtolower( $file->getFilename() );
			if ( isset( $skip[ $rel ] ) ) {
				return false;
			}
			return ! ( $file->isDir() && ( '.git' === $base || 'node_modules' === $base ) );
		}
	);
	$files = new RecursiveIteratorIterator( $filter, RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD );

	$count = 0;
	$bytes = 0;
	foreach ( $files as $file ) {
		if ( ! $file->isFile() ) {
			continue;
		}
		$rel  = 'wp-content/' . ltrim( substr( wp_normalize_path( $file->getPathname() ), strlen( $root ) ), '/' );
		$size = (int) $file->getSize();
		if ( false !== strpbrk( $rel, "\t\r\n" ) ) {
			$job = ner_michoel_backup_skip( $job, array( $rel, 'name' ) );
			continue;
		}
		if ( $size >= $max ) {
			$job = ner_michoel_backup_skip( $job, array( $rel, 'too big for a zip', $size ) );
			continue;
		}
		fwrite( $out, $rel . "\t" . $size . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		$count++;
		$bytes += $size;
	}
	fclose( $out );

	$db_bytes = (int) @filesize( $job['dir'] . '/database.sql' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	$need     = min( $bytes + $db_bytes, ner_michoel_backup_part_limit() ) + 50 * MB_IN_BYTES;
	$free     = function_exists( 'disk_free_space' ) ? @disk_free_space( $job['dir'] ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	if ( false !== $free && null !== $free && $free < $need ) {
		/* translators: 1, 2: sizes */
		throw new Exception( sprintf( __( 'Not enough free space on this server to make the backup: it needs about %1$s and %2$s is free.', 'ner-michoel-core' ), size_format( $need ), size_format( $free ) ) );
	}

	$job['scan']  = array(
		'count'    => $count,
		'bytes'    => $bytes,
		'db_bytes' => $db_bytes,
	);
	$job['pack']  = array(
		'part'        => 1,
		'listpos'     => 0,
		'part_start'  => 0,
		'zip'         => null,
		'extras'      => false,
		'eof'         => false,
		'files_done'  => 0,
		'bytes_done'  => 0,
		'part_files'  => 0,
		'part_bytes'  => 0,
	);
	$job['phase'] = 'pack';
	return $job;
}

/**
 * Packs files into the current piece until $until, or until the piece is full or the list is done; then the
 * piece is closed, checked, and handed to the upload phase.
 */
function ner_michoel_backup_phase_pack( $job, $until ) {
	$p    = $job['pack'];
	$path = $job['dir'] . '/part' . $p['part'] . '.zip';
	$zip  = NM_Backup_Zip::open( $path, $p['zip'] );

	if ( is_wp_error( $zip ) ) {
		// The part-made piece is gone (the server was tidied?): start this piece again.
		$job['pack'] = ner_michoel_backup_restart_part( $p );
		throw new NM_Backup_Retry( $zip->get_error_message(), $job );
	}

	if ( 1 === (int) $p['part'] && ! $p['extras'] ) {
		$r = $zip->add_file( $job['dir'] . '/database.sql', 'database.sql' );
		if ( ! is_wp_error( $r ) ) {
			$r = $zip->add_string( 'README.txt', ner_michoel_backup_readme( $job ), $job['now'] );
		}
		if ( ! is_wp_error( $r ) ) {
			$r = $zip->add_string( 'backup.json', wp_json_encode( ner_michoel_backup_manifest( $job ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), $job['now'] );
		}
		if ( is_wp_error( $r ) ) {
			$zip->release();
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
			$job['pack'] = ner_michoel_backup_restart_part( $p );
			throw new NM_Backup_Retry( __( 'Couldn’t put the database copy into the backup: ', 'ner-michoel-core' ) . $r->get_error_message(), $job );
		}
		$p['extras'] = true;
	}

	$list = fopen( $job['dir'] . '/files.tsv', 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
	if ( ! $list ) {
		$zip->release();
		throw new Exception( __( 'The list of files to back up is missing.', 'ner-michoel-core' ) );
	}
	fseek( $list, (int) $p['listpos'] );

	$limit   = ner_michoel_backup_part_limit();
	$content = wp_normalize_path( untrailingslashit( WP_CONTENT_DIR ) );
	$close   = false;
	while ( time() < $until ) {
		$line = fgets( $list );
		if ( false === $line || '' === trim( $line ) ) {
			$p['eof'] = true;
			$close    = true;
			break;
		}
		$fields = explode( "\t", rtrim( $line, "\n" ) );
		$rel    = $fields[0];
		$size   = isset( $fields[1] ) ? (int) $fields[1] : 0;

		if ( $zip->entries() > 0 && ( $zip->size() + $size > $limit || $zip->entries() >= 65000 ) ) {
			$close = true; // This file starts the next piece.
			break;
		}

		$abs = $content . substr( $rel, strlen( 'wp-content' ) );
		$r   = $zip->add_file( $abs, $rel );
		if ( is_wp_error( $r ) ) {
			$job = ner_michoel_backup_skip( $job, array( $rel, file_exists( $abs ) ? $r->get_error_message() : 'deleted while the backup ran' ) );
		} else {
			$p['files_done']++;
			$p['part_files']++;
		}
		$p['bytes_done'] += $size;
		$p['part_bytes'] += $size;
		$p['listpos']     = ftell( $list );
	}
	if ( ! $close && false === fgets( $list ) ) {
		$p['eof'] = true; // The last file just went in.
		$close    = true;
	}
	fclose( $list );

	if ( ! $close ) {
		$p['zip'] = $zip->state();
		$zip->release();
		$job['pack'] = $p;
		return $job;
	}

	$closed = $zip->close_archive();
	if ( is_wp_error( $closed ) ) {
		$zip->release();
		@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
		$job['pack'] = ner_michoel_backup_restart_part( $p );
		throw new NM_Backup_Retry( $closed->get_error_message(), $job );
	}
	clearstatcache( true, $path );
	$check = ner_michoel_backup_check_zip( $path );
	if ( true !== $check ) {
		@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
		$job['pack'] = ner_michoel_backup_restart_part( $p );
		throw new NM_Backup_Retry( $check, $job );
	}

	$job['parts'][] = array(
		'n'       => (int) $p['part'],
		'name'    => ner_michoel_backup_part_name( $job['base'], (int) $p['part'] ),
		'file'    => $path,
		'size'    => (int) filesize( $path ),
		'sha256'  => hash_file( 'sha256', $path ),
		'entries' => $p['part_files'],
		'sent'    => array(),
	);

	$p['part']++;
	$p['zip']        = null;
	$p['part_start'] = $p['listpos'];
	$p['part_files'] = 0;
	$p['part_bytes'] = 0;
	$job['pack']     = $p;
	$job['phase']    = 'upload';
	return $job;
}

/** Pack state put back to the start of the current piece (its files are packed again). */
function ner_michoel_backup_restart_part( $p ) {
	$p['files_done'] -= $p['part_files'];
	$p['bytes_done'] -= $p['part_bytes'];
	$p['listpos']     = $p['part_start'];
	$p['zip']         = null;
	$p['eof']         = false;
	$p['part_files']  = 0;
	$p['part_bytes']  = 0;
	if ( 1 === (int) $p['part'] ) {
		$p['extras'] = false; // database.sql is kept until the first piece is closed, checked and saved.
	}
	return $p;
}

/**
 * A finished piece read back: PHP's zip reader opens it with its consistency checks (every entry's header
 * against the central directory). True, or what is wrong.
 */
function ner_michoel_backup_check_zip( $path ) {
	if ( ! class_exists( 'ZipArchive' ) ) {
		return true;
	}
	$zip = new ZipArchive();
	$r   = $zip->open( $path, ZipArchive::CHECKCONS );
	if ( true !== $r ) {
		/* translators: %d: the zip library's error number */
		return sprintf( __( 'The backup file didn’t read back as a sound zip (error %d).', 'ner-michoel-core' ), (int) $r );
	}
	$zip->close();
	return true;
}

/**
 * Sends the newest piece to the next slot that still needs it (one per call, so each upload is saved as
 * done straight away). When every slot has it, the local copy is deleted and packing carries on, or the
 * old copies are pruned if this was the last piece.
 */
function ner_michoel_backup_phase_upload( $job ) {
	$i    = count( $job['parts'] ) - 1;
	$part = $job['parts'][ $i ];

	// The first piece is closed, checked and saved as such by now, so database.sql inside it is safe: its own
	// copy goes, for the space. (Not earlier: a step stopped before that save packs the piece again.)
	if ( 1 === (int) $part['n'] ) {
		@unlink( $job['dir'] . '/database.sql' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
	}

	foreach ( $job['due'] as $slot ) {
		if ( in_array( $slot, $part['sent'], true ) ) {
			continue;
		}
		$job_id   = $job['id'];
		$last     = 0;
		$progress = function ( $ch, $dl_total, $dl_now, $ul_total, $ul_now ) use ( $job_id, $slot, $part, &$last ) {
			if ( time() - $last < 5 ) {
				return 0;
			}
			$last = time();
			ner_michoel_backup_lock_refresh();
			update_option(
				'nm_backup_upload',
				array(
					'job'  => $job_id,
					'slot' => $slot,
					'part' => $part['n'],
					'sent' => (int) $ul_now,
					'size' => (int) $part['size'],
					'at'   => time(),
				),
				false
			);
			return ner_michoel_backup_cancel_requested( $job_id ) ? 1 : 0; // Non-zero stops the transfer.
		};

		$sent = ner_michoel_backup_send( $part['file'], ner_michoel_backup_slot_folder( $slot ) . $part['name'], $part['size'], $part['sha256'], $progress );
		delete_option( 'nm_backup_upload' );
		if ( ner_michoel_backup_cancel_requested( $job['id'] ) ) {
			throw new Exception( 'nm_backup_cancelled' );
		}
		if ( is_wp_error( $sent ) ) {
			/* translators: 1: copy name (daily …), 2: the error */
			throw new Exception( sprintf( __( 'Sending the %1$s copy to Bunny failed: %2$s', 'ner-michoel-core' ), ner_michoel_backup_slot_label( $slot ), $sent->get_error_message() ) );
		}
		$job['parts'][ $i ]['sent'][] = $slot;
		return $job;
	}

	@unlink( $part['file'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
	$job['parts'][ $i ]['file'] = '';
	$job['phase']               = $job['pack']['eof'] ? 'prune' : 'pack';
	return $job;
}

/**
 * The new copy is in place in every slot due: delete what was there before (any other .zip in those folders),
 * and remember the new copies. A delete that fails is only noted: the next backup tries again.
 */
function ner_michoel_backup_phase_prune( $job ) {
	$names = wp_list_pluck( $job['parts'], 'name' );
	$files = array();
	$total = 0;
	foreach ( $job['parts'] as $part ) {
		$files[] = array(
			'name'   => $part['name'],
			'size'   => $part['size'],
			'sha256' => $part['sha256'],
		);
		$total  += $part['size'];
	}

	$slots = ner_michoel_backup_slots();
	foreach ( $job['due'] as $slot ) {
		$folder = ner_michoel_backup_slot_folder( $slot );
		$list   = ner_michoel_backup_list_folder( $folder );
		if ( is_wp_error( $list ) ) {
			$job['notes'][] = $list->get_error_message();
		} else {
			foreach ( $list as $item ) {
				if ( preg_match( '/\.zip$/i', $item['name'] ) && ! in_array( $item['name'], $names, true ) && ! ner_michoel_backup_delete_remote( $folder . $item['name'] ) ) {
					/* translators: 1: file name, 2: copy name */
					$job['notes'][] = sprintf( __( 'Couldn’t delete the old %2$s copy %1$s; the next backup tries again.', 'ner-michoel-core' ), $item['name'], ner_michoel_backup_slot_label( $slot ) );
				}
			}
		}
		$slots[ $slot ] = array(
			'id'    => $job['id'],
			'made'  => $job['now'],
			'month' => wp_date( 'Y-m', $job['now'] ),
			'files' => $files,
			'size'  => $total,
		);
	}
	update_option( 'nm_backup_slots', $slots, false );
	$job['phase'] = 'done';
	return $job;
}

/* ------------------------------------------------------------------ */
/* The end of a job                                                    */
/* ------------------------------------------------------------------ */

/**
 * Records how the job ended ('ok', 'failed' or 'cancelled') and tidies up: the work folder goes, and a job
 * that didn't finish takes its new files off Bunny again (the old copies were never touched).
 */
function ner_michoel_backup_finish( $job, $status, $error = '' ) {
	if ( 'ok' !== $status ) {
		$keep = array();
		foreach ( ner_michoel_backup_slots() as $slot => $info ) {
			foreach ( (array) ( isset( $info['files'] ) ? $info['files'] : array() ) as $f ) {
				$keep[ $slot . '/' . $f['name'] ] = true;
			}
		}
		foreach ( $job['parts'] as $part ) {
			foreach ( $part['sent'] as $slot ) {
				if ( empty( $keep[ $slot . '/' . $part['name'] ] ) ) {
					ner_michoel_backup_delete_remote( ner_michoel_backup_slot_folder( $slot ) . $part['name'] );
				}
			}
		}
	}
	if ( ! empty( $job['dir'] ) ) {
		ner_michoel_backup_rmdir( $job['dir'] );
	}

	$size = 0;
	foreach ( $job['parts'] as $part ) {
		$size += $part['size'];
	}
	$entry = array(
		'id'       => $job['id'],
		'trigger'  => $job['trigger'],
		'started'  => $job['started'],
		'finished' => time(),
		'status'   => $status,
		'slots'    => 'ok' === $status ? $job['due'] : array(),
		'size'     => $size,
		'pieces'   => count( $job['parts'] ),
		'files'    => isset( $job['pack']['files_done'] ) ? (int) $job['pack']['files_done'] : 0,
		'tables'   => isset( $job['db']['tables'] ) ? count( $job['db']['tables'] ) : 0,
		'skipped'  => isset( $job['skipped_n'] ) ? (int) $job['skipped_n'] : 0,
		'error'    => $error,
		'notes'    => isset( $job['notes'] ) ? array_slice( (array) $job['notes'], 0, 5 ) : array(),
	);
	$log = get_option( 'nm_backup_log', array() );
	$log = is_array( $log ) ? $log : array();
	array_unshift( $log, $entry );
	update_option( 'nm_backup_log', array_slice( $log, 0, 30 ), false );

	delete_option( 'nm_backup_job' );
	delete_option( 'nm_backup_cancel' );
	delete_option( 'nm_backup_upload' );
	wp_clear_scheduled_hook( 'nm_backup_step' );

	if ( 'failed' === $status ) {
		ner_michoel_backup_email_failure( $entry );
	}
	return $entry;
}

function ner_michoel_backup_fail( $job, $error ) {
	return ner_michoel_backup_finish( $job, 'failed', $error );
}

function ner_michoel_backup_email_failure( $entry ) {
	$s = ner_michoel_backup_settings();
	if ( ! $s['email'] ) {
		return;
	}
	$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	/* translators: %s: site name */
	$subject = sprintf( __( '[%s] The backup didn’t finish', 'ner-michoel-core' ), $site );
	$lines   = array(
		/* translators: %s: date and time */
		sprintf( __( 'The backup that started %s didn’t finish:', 'ner-michoel-core' ), wp_date( 'l, F j, Y, g:i a', $entry['started'] ) ),
		'',
		'    ' . $entry['error'],
		'',
		__( 'The copies already on Bunny weren’t touched, so the last good backup is still there.', 'ner-michoel-core' ),
		__( 'The next backup runs at its usual time. To try now, or to see the details:', 'ner-michoel-core' ),
		ner_michoel_custom_admin_url( 'backups' ),
	);
	wp_mail( get_option( 'admin_email' ), $subject, implode( "\n", $lines ) );
}

/** Deletes a folder and everything in it, only ever inside wp-content/nm-backup-work. */
function ner_michoel_backup_rmdir( $dir ) {
	$root = realpath( ner_michoel_backup_work_root() );
	$real = realpath( $dir );
	if ( ! $root || ! $real || 0 !== strpos( $real, $root . DIRECTORY_SEPARATOR ) || ! is_dir( $real ) ) {
		return;
	}
	$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $real, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $items as $item ) {
		if ( $item->isDir() && ! $item->isLink() ) {
			@rmdir( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		} else {
			@unlink( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}
	@rmdir( $real ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}

/* ------------------------------------------------------------------ */
/* What goes in the zip besides the files                              */
/* ------------------------------------------------------------------ */

function ner_michoel_backup_manifest( $job ) {
	global $wpdb;
	return array(
		'site'      => home_url(),
		'name'      => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
		'made'      => wp_date( 'c', $job['now'] ),
		'id'        => $job['id'],
		'wordpress' => get_bloginfo( 'version' ),
		'plugin'    => NER_MICHOEL_CORE_VERSION,
		'php'       => PHP_VERSION,
		'prefix'    => $wpdb->base_prefix,
		'tables'    => $job['db']['tables'],
		'rows'      => $job['db']['rows'],
		'files'     => $job['scan']['count'],
		'bytes'     => $job['scan']['bytes'],
		'left_out'  => array_slice( $job['skipped'], 0, 200 ),
		'excluded'  => ner_michoel_backup_excluded(),
	);
}

function ner_michoel_backup_readme( $job ) {
	global $wpdb;
	$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	$lines = array(
		sprintf( 'Backup of %s (%s)', $site, home_url() ),
		sprintf( 'Made %s by the Ner Michoel Core plugin %s, WordPress %s.', wp_date( 'l, F j, Y, g:i a T', $job['now'] ), NER_MICHOEL_CORE_VERSION, get_bloginfo( 'version' ) ),
		'',
		'WHAT IS IN IT',
		sprintf( '- database.sql: the whole database, %d tables (their names start with "%s"): shiurim, pages, settings, accounts.', count( $job['db']['tables'] ), $wpdb->base_prefix ),
		sprintf( '- wp-content/: themes, plugins and uploaded files (%s files, %s).', number_format_i18n( $job['scan']['count'] ), size_format( $job['scan']['bytes'] ) ),
		'- backup.json: the same details, for a program.',
		'',
		'WHAT IS NOT IN IT',
		'- The audio, video and PDF files kept on Bunny. They stay on Bunny, and database.sql still has their addresses.',
		'- WordPress itself (get the same version from wordpress.org) and wp-config.php (it holds the database password; the host keeps it).',
		'- Caches, and other backup tools\' archives: ' . implode( ', ', ner_michoel_backup_excluded() ) . '.',
	);
	if ( ! empty( $job['skipped_n'] ) ) {
		$lines[] = sprintf( '- %d file(s) left out (too big for a zip, or unreadable); see "left_out" in backup.json.', $job['skipped_n'] );
	}
	$lines = array_merge(
		$lines,
		array(
			'',
			'IF THE BACKUP IS IN MORE THAN ONE PIECE',
			'A big site is split into pieces of about 1 GB: this file, then ...-part2.zip, ...-part3.zip. Unzip them all into the same folder; database.sql is in the first.',
			'',
			'TO PUT THE SITE BACK',
			'1. Have WordPress installed (the same version is best), with its own wp-config.php. Its table prefix must be "' . $wpdb->base_prefix . '".',
			'2. In phpMyAdmin (the host\'s control panel, Databases), choose the site\'s database, then Import, and pick database.sql. It replaces every table it contains.',
			'3. Upload the wp-content folder from this zip over the site\'s wp-content (the host\'s File Manager, or FTP).',
			'4. If the site\'s address has changed since this backup, change the old address to the new one with a search-and-replace tool.',
			'5. Sign in and check the site.',
		)
	);
	return implode( "\r\n", $lines ) . "\r\n";
}

/* ------------------------------------------------------------------ */
/* Schedule                                                            */
/* ------------------------------------------------------------------ */

function ner_michoel_backup_cron_schedules( $schedules ) {
	$schedules['nm_backup_5_minutes'] = array(
		'interval' => 5 * MINUTE_IN_SECONDS,
		'display'  => __( 'Every 5 minutes (backups)', 'ner-michoel-core' ),
	);
	return $schedules;
}
add_filter( 'cron_schedules', 'ner_michoel_backup_cron_schedules' );

/** The next time it is $hour o'clock on the site's clock. */
function ner_michoel_backup_next_time( $hour ) {
	$now  = new DateTimeImmutable( 'now', wp_timezone() );
	$then = $now->setTime( (int) $hour, 0, 0 );
	if ( $then <= $now ) {
		$then = $then->modify( '+1 day' );
	}
	return $then->getTimestamp();
}

/**
 * Keeps the nightly backup at the chosen hour (a daily event drifts by an hour when the clocks change) and
 * the 5-minute check scheduled. Runs on every load, which is cheap, so an update needs no reactivation.
 */
function ner_michoel_backup_ensure_schedule() {
	$s    = ner_michoel_backup_settings();
	$next = wp_next_scheduled( 'nm_backup_daily' );
	if ( ! $s['enabled'] ) {
		if ( $next ) {
			wp_clear_scheduled_hook( 'nm_backup_daily' );
		}
	} elseif ( ! $next || ( $next > time() && (int) wp_date( 'G', $next ) !== $s['hour'] ) ) {
		if ( $next ) {
			wp_clear_scheduled_hook( 'nm_backup_daily' );
		}
		wp_schedule_event( ner_michoel_backup_next_time( $s['hour'] ), 'daily', 'nm_backup_daily' );
	}
	if ( ! wp_next_scheduled( 'nm_backup_watchdog' ) ) {
		wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'nm_backup_5_minutes', 'nm_backup_watchdog' );
	}
}
add_action( 'init', 'ner_michoel_backup_ensure_schedule' );

function ner_michoel_backup_daily_tick() {
	$s = ner_michoel_backup_settings();
	if ( $s['enabled'] && ner_michoel_backup_target() && ! ner_michoel_backup_job() ) {
		ner_michoel_backup_start( 'schedule' );
	}
}
add_action( 'nm_backup_daily', 'ner_michoel_backup_daily_tick' );

function ner_michoel_backup_cron_step() {
	ner_michoel_backup_step( 'cron' );
}
add_action( 'nm_backup_step', 'ner_michoel_backup_cron_step' );

/**
 * Every 5 minutes: carries on a job whose chain of steps broke (nothing for 2 minutes), and stops one that
 * has gone on for 12 hours.
 */
function ner_michoel_backup_watchdog() {
	$job = ner_michoel_backup_job();
	if ( ! $job ) {
		return;
	}
	if ( time() - (int) $job['started'] > 12 * HOUR_IN_SECONDS && ! ner_michoel_backup_is_locked() && ner_michoel_backup_lock() ) {
		ner_michoel_backup_fail( $job, __( 'The backup was still going after 12 hours, so it was stopped.', 'ner-michoel-core' ) );
		ner_michoel_backup_unlock();
		return;
	}
	if ( time() - (int) $job['touched'] > 2 * MINUTE_IN_SECONDS ) {
		ner_michoel_backup_step( 'watchdog' );
	}
}
add_action( 'nm_backup_watchdog', 'ner_michoel_backup_watchdog' );

/* ------------------------------------------------------------------ */
/* For the screen                                                      */
/* ------------------------------------------------------------------ */

function ner_michoel_backup_slot_label( $slot ) {
	$labels = array(
		'daily'     => __( 'daily', 'ner-michoel-core' ),
		'monthly'   => __( 'monthly', 'ner-michoel-core' ),
		'bimonthly' => __( 'every-two-months', 'ner-michoel-core' ),
	);
	return isset( $labels[ $slot ] ) ? $labels[ $slot ] : $slot;
}

/** What the job is doing, in words ("packing files"). */
function ner_michoel_backup_phase_text( $job ) {
	switch ( $job['phase'] ) {
		case 'start':
			return __( 'getting ready', 'ner-michoel-core' );
		case 'db':
			return __( 'copying the database', 'ner-michoel-core' );
		case 'scan':
			return __( 'listing the files', 'ner-michoel-core' );
		case 'pack':
			return __( 'packing the files', 'ner-michoel-core' );
		case 'upload':
			return __( 'sending the backup to Bunny', 'ner-michoel-core' );
		case 'prune':
			return __( 'replacing the old copies', 'ner-michoel-core' );
	}
	return __( 'finishing', 'ner-michoel-core' );
}

/** The running job for the screen: phase, a percentage and a line of detail. */
function ner_michoel_backup_job_summary( $job ) {
	$percent = 0;
	$detail  = '';
	switch ( $job['phase'] ) {
		case 'start':
			$percent = 1;
			break;
		case 'db':
			$n       = max( 1, count( $job['db']['tables'] ) );
			$percent = 2 + 13 * min( 1, $job['db']['ti'] / $n );
			/* translators: 1, 2: numbers, 3: number of rows */
			$detail = sprintf( __( 'Table %1$s of %2$s, %3$s rows so far', 'ner-michoel-core' ), number_format_i18n( min( $n, $job['db']['ti'] + 1 ) ), number_format_i18n( $n ), number_format_i18n( $job['db']['rows'] ) );
			break;
		case 'scan':
			$percent = 15;
			break;
		case 'pack':
		case 'upload':
		case 'prune':
			$bytes   = max( 1, (int) $job['scan']['bytes'] );
			$packed  = min( 1, $job['pack']['bytes_done'] / $bytes );
			$pieces  = max( count( $job['parts'] ), (int) ceil( $bytes / ner_michoel_backup_part_limit() ) );
			$units   = max( 1, $pieces * count( $job['due'] ) );
			$sent    = 0;
			foreach ( $job['parts'] as $part ) {
				$sent += count( $part['sent'] );
			}
			$upload = get_option( 'nm_backup_upload' );
			if ( is_array( $upload ) && $upload['job'] === $job['id'] && $upload['size'] > 0 ) {
				$sent += min( 1, $upload['sent'] / $upload['size'] );
			}
			$percent = 'prune' === $job['phase'] ? 98 : 16 + 52 * $packed + 30 * min( 1, $sent / $units );
			if ( 'pack' === $job['phase'] ) {
				/* translators: 1, 2: numbers of files */
				$detail = sprintf( __( 'File %1$s of %2$s', 'ner-michoel-core' ), number_format_i18n( $job['pack']['files_done'] ), number_format_i18n( $job['scan']['count'] ) );
			} elseif ( 'upload' === $job['phase'] ) {
				$part = end( $job['parts'] );
				$slot = '';
				foreach ( $job['due'] as $s ) {
					if ( ! in_array( $s, $part['sent'], true ) ) {
						$slot = $s;
						break;
					}
				}
				$done = is_array( $upload ) && $upload['job'] === $job['id'] && $upload['slot'] === $slot ? (int) $upload['sent'] : 0;
				/* translators: 1: copy name, 2, 3: sizes */
				$detail = $slot ? sprintf( __( 'The %1$s copy: %2$s of %3$s', 'ner-michoel-core' ), ner_michoel_backup_slot_label( $slot ), size_format( $done, 1 ), size_format( $part['size'], 1 ) ) : '';
			}
			break;
	}
	return array(
		'id'      => $job['id'],
		'trigger' => $job['trigger'],
		'started' => (int) $job['started'],
		'phase'   => $job['phase'],
		'doing'   => ner_michoel_backup_phase_text( $job ),
		'percent' => (int) floor( $percent ),
		'detail'  => $detail,
		'due'     => $job['due'],
		'retry'   => $job['tries'] ? $job['error'] : '',
	);
}

/** Everything the screen shows. */
function ner_michoel_backup_status_data() {
	$s      = ner_michoel_backup_settings();
	$target = ner_michoel_backup_target();
	$job    = ner_michoel_backup_job();
	$log    = get_option( 'nm_backup_log', array() );
	$log    = is_array( $log ) ? $log : array();

	$last_ok = 0;
	foreach ( $log as $entry ) {
		if ( 'ok' === $entry['status'] ) {
			$last_ok = (int) $entry['finished'];
			break;
		}
	}

	// Times in the site's own words and clock, so the screen needn't know its time zone.
	$slots = array();
	foreach ( ner_michoel_backup_slots() as $slot => $info ) {
		$files = array();
		foreach ( (array) $info['files'] as $f ) {
			$files[] = array(
				'name' => $f['name'],
				'size' => (int) $f['size'],
				'url'  => ner_michoel_backup_download_url( $slot, $f['name'] ),
			);
		}
		$slots[ $slot ] = array(
			'made'     => (int) $info['made'],
			'madeText' => ner_michoel_backup_when( $info['made'] ),
			'size'     => (int) $info['size'],
			'files'    => $files,
		);
	}
	$log = array_slice( $log, 0, 10 );
	foreach ( $log as $i => $entry ) {
		$log[ $i ]['startedText'] = ner_michoel_backup_when( $entry['started'] );
		$log[ $i ]['took']        = max( 0, (int) $entry['finished'] - (int) $entry['started'] );
	}
	$next = $s['enabled'] ? (int) wp_next_scheduled( 'nm_backup_daily' ) : 0;
	$summ = $job ? ner_michoel_backup_job_summary( $job ) : null;
	if ( $summ ) {
		$summ['startedText'] = ner_michoel_backup_when( $summ['started'] );
	}

	return array(
		'configured' => (bool) $target,
		'where'      => array(
			'zone'   => $target ? $target['zone'] : '',
			'own'    => $target ? $target['own'] : false,
			'folder' => $s['folder'],
		),
		'settings'   => array(
			'enabled'    => (bool) $s['enabled'],
			'hour'       => (int) $s['hour'],
			'email'      => (bool) $s['email'],
			'zone_name'  => $s['zone_name'],
			'region'     => $s['region'],
			'has_key'    => '' !== $s['api_key'],
			'adminEmail' => get_option( 'admin_email' ),
		),
		'job'        => $summ,
		'slots'      => (object) $slots,
		'next'       => $next,
		'nextText'   => $next ? ner_michoel_backup_when( $next ) : '',
		'lastOk'     => $last_ok,
		'lastOkText' => $last_ok ? ner_michoel_backup_when( $last_ok ) : '',
		'overdue'    => $s['enabled'] && $target && ! $job && $log && ( ! $last_ok || $last_ok < time() - 30 * HOUR_IN_SECONDS ),
		'log'        => $log,
		'now'        => time(),
	);
}

/** "Today at 3:00 AM", "Yesterday at 3:00 AM", "Mon, Oct 5 at 3:00 AM", "Mon, Oct 5, 2025 at 3:00 AM" (site time). */
function ner_michoel_backup_when( $ts ) {
	$ts   = (int) $ts;
	$day  = wp_date( 'Y-m-d', $ts );
	$time = wp_date( get_option( 'time_format' ) ? get_option( 'time_format' ) : 'g:i a', $ts );
	if ( wp_date( 'Y-m-d' ) === $day ) {
		/* translators: %s: time */
		return sprintf( __( 'Today at %s', 'ner-michoel-core' ), $time );
	}
	if ( wp_date( 'Y-m-d', time() - DAY_IN_SECONDS ) === $day ) {
		/* translators: %s: time */
		return sprintf( __( 'Yesterday at %s', 'ner-michoel-core' ), $time );
	}
	if ( wp_date( 'Y-m-d', time() + DAY_IN_SECONDS ) === $day ) {
		/* translators: %s: time */
		return sprintf( __( 'Tomorrow at %s', 'ner-michoel-core' ), $time );
	}
	/* translators: 1: date, 2: time */
	return sprintf( __( '%1$s at %2$s', 'ner-michoel-core' ), wp_date( wp_date( 'Y' ) === wp_date( 'Y', $ts ) ? 'D, M j' : 'D, M j, Y', $ts ), $time );
}

/* ------------------------------------------------------------------ */
/* REST                                                                */
/* ------------------------------------------------------------------ */

function ner_michoel_register_backup_routes() {
	$admin = function () {
		return current_user_can( 'manage_options' );
	};

	register_rest_route(
		'ner-michoel/v1',
		'/backups/status',
		array(
			'methods'             => 'GET',
			'callback'            => function () {
				// A job nobody has moved on for a minute (its chain broke, or WP-Cron is quiet): nudge it.
				$job = ner_michoel_backup_job();
				if ( $job && time() - (int) $job['touched'] > MINUTE_IN_SECONDS && ! ner_michoel_backup_is_locked() ) {
					ner_michoel_backup_kick( $job );
				}
				$response = new WP_REST_Response( ner_michoel_backup_status_data(), 200 );
				$response->header( 'Cache-Control', 'no-store' );
				return $response;
			},
			'permission_callback' => $admin,
		)
	);

	register_rest_route(
		'ner-michoel/v1',
		'/backups/run',
		array(
			'methods'             => 'POST',
			'callback'            => function () {
				$job = ner_michoel_backup_start( 'manual' );
				if ( is_wp_error( $job ) ) {
					return $job;
				}
				return new WP_REST_Response( ner_michoel_backup_status_data(), 200 );
			},
			'permission_callback' => $admin,
		)
	);

	register_rest_route(
		'ner-michoel/v1',
		'/backups/cancel',
		array(
			'methods'             => 'POST',
			'callback'            => function () {
				$job = ner_michoel_backup_job();
				if ( $job ) {
					update_option( 'nm_backup_cancel', $job['id'], false );
					// No step is working on it right now: stop it here. Otherwise the step sees the flag.
					if ( ner_michoel_backup_lock() ) {
						$job = ner_michoel_backup_job( true );
						if ( $job ) {
							ner_michoel_backup_finish( $job, 'cancelled', __( 'Stopped by hand.', 'ner-michoel-core' ) );
						}
						ner_michoel_backup_unlock();
					}
				}
				return new WP_REST_Response( ner_michoel_backup_status_data(), 200 );
			},
			'permission_callback' => $admin,
		)
	);

	register_rest_route(
		'ner-michoel/v1',
		'/backups/settings',
		array(
			'methods'             => 'POST',
			'callback'            => 'ner_michoel_backup_save_settings_rest',
			'permission_callback' => $admin,
		)
	);

	register_rest_route(
		'ner-michoel/v1',
		'/backups/remote',
		array(
			'methods'             => 'GET',
			'callback'            => function () {
				$out   = array();
				$slots = ner_michoel_backup_slots();
				$gone  = false;
				if ( ner_michoel_backup_target() ) {
					foreach ( ner_michoel_backup_slot_keys() as $slot ) {
						$list = ner_michoel_backup_list_folder( ner_michoel_backup_slot_folder( $slot ) );
						if ( is_wp_error( $list ) ) {
							$out[ $slot ] = array( 'error' => $list->get_error_message() );
							continue;
						}
						$out[ $slot ] = array( 'files' => $list );
						// A copy this site remembers that isn't on Bunny any more (deleted there by hand):
						// forget it, so the next backup fills the slot again.
						if ( ! empty( $slots[ $slot ]['files'] ) && ! ner_michoel_backup_job() ) {
							$there = wp_list_pluck( $list, 'size', 'name' );
							foreach ( $slots[ $slot ]['files'] as $f ) {
								if ( ! isset( $there[ $f['name'] ] ) || (int) $there[ $f['name'] ] !== (int) $f['size'] ) {
									unset( $slots[ $slot ] );
									$out[ $slot ]['forgotten'] = true;
									$gone = true;
									break;
								}
							}
						}
					}
				}
				if ( $gone ) {
					update_option( 'nm_backup_slots', $slots, false );
				}
				$response = new WP_REST_Response( (object) $out, 200 );
				$response->header( 'Cache-Control', 'no-store' );
				return $response;
			},
			'permission_callback' => $admin,
		)
	);

	register_rest_route(
		'ner-michoel/v1',
		'/backups/step',
		array(
			'methods'             => 'POST',
			'callback'            => function ( WP_REST_Request $request ) {
				$job = ner_michoel_backup_job();
				if ( ! $job || ! hash_equals( (string) $job['token'], (string) $request->get_param( 'token' ) ) ) {
					return new WP_Error( 'nm_backup_token', 'No backup step to run.', array( 'status' => 403 ) );
				}
				// Background work: the Speed screen counts it with the cron runs, not as visitors (site-speed.php).
				add_filter( 'wp_doing_cron', '__return_true' );
				return new WP_REST_Response( array( 'phase' => ner_michoel_backup_step( 'loopback' ) ), 200 );
			},
			'permission_callback' => '__return_true',
		)
	);
}
add_action( 'rest_api_init', 'ner_michoel_register_backup_routes' );

/**
 * Saves the screen's settings. A Storage Zone of the backups' own is tried first (Bunny is asked for the
 * backups folder's list) and only saved if Bunny accepts it. Moving the backups somewhere else empties the
 * three slots here (the next backup fills them in the new place); the copies in the old place are left.
 */
function ner_michoel_backup_save_settings_rest( WP_REST_Request $request ) {
	$s    = ner_michoel_backup_settings();
	$was  = ner_michoel_backup_target();
	$hour = $request->get_param( 'hour' );

	if ( null !== $request->get_param( 'enabled' ) ) {
		$s['enabled'] = rest_sanitize_boolean( $request->get_param( 'enabled' ) );
	}
	if ( null !== $hour ) {
		if ( ! is_numeric( $hour ) || (int) $hour < 0 || (int) $hour > 23 ) {
			return new WP_Error( 'nm_backup_hour', __( 'Choose an hour of the day.', 'ner-michoel-core' ), array( 'status' => 400 ) );
		}
		$s['hour'] = (int) $hour;
	}
	if ( null !== $request->get_param( 'email' ) ) {
		$s['email'] = rest_sanitize_boolean( $request->get_param( 'email' ) );
	}

	if ( rest_sanitize_boolean( $request->get_param( 'remove_zone' ) ) ) {
		$s['zone_name'] = '';
		$s['region']    = '';
		$s['api_key']   = '';
	} elseif ( null !== $request->get_param( 'zone_name' ) ) {
		$zone   = sanitize_text_field( (string) $request->get_param( 'zone_name' ) );
		$region = sanitize_key( (string) $request->get_param( 'region' ) );
		$key    = trim( sanitize_text_field( (string) $request->get_param( 'api_key' ) ) );
		if ( '' === $key && $zone === $s['zone_name'] ) {
			$key = $s['api_key']; // Left empty: keep the saved password.
		}
		if ( '' !== $zone || '' !== $key ) {
			if ( '' === $zone || '' === $key ) {
				return new WP_Error( 'nm_backup_zone', __( 'Fill in both the Storage Zone’s name and its password, or neither.', 'ner-michoel-core' ), array( 'status' => 400 ) );
			}
			$try  = array(
				'zone'   => $zone,
				'region' => $region,
				'key'    => $key,
				'own'    => true,
			);
			$list = ner_michoel_backup_list_folder( $s['folder'] . '/', $try );
			if ( is_wp_error( $list ) ) {
				return new WP_Error( 'nm_backup_zone', $list->get_error_message(), array( 'status' => 400 ) );
			}
		}
		$s['zone_name'] = $zone;
		$s['region']    = $region;
		$s['api_key']   = $key;
	}

	// Not while a backup runs: its pieces would land in two places.
	if ( ner_michoel_backup_job( true ) && ner_michoel_backup_target_id( ner_michoel_backup_target( $s ) ) !== ner_michoel_backup_target_id( $was ) ) {
		return new WP_Error( 'nm_backup_running', __( 'A backup is running. Wait for it to finish (or stop it) before changing where the backups are kept.', 'ner-michoel-core' ), array( 'status' => 409 ) );
	}

	update_option( 'nm_backup_settings', $s, true );

	$now = ner_michoel_backup_target();
	if ( ! $was || ! $now || $was['zone'] !== $now['zone'] || $was['own'] !== $now['own'] ) {
		delete_option( 'nm_backup_slots' );
	}

	wp_clear_scheduled_hook( 'nm_backup_daily' );
	ner_michoel_backup_ensure_schedule();
	return new WP_REST_Response( ner_michoel_backup_status_data(), 200 );
}

/* ------------------------------------------------------------------ */
/* Download                                                            */
/* ------------------------------------------------------------------ */

/**
 * A plain address (not wp_nonce_url(), which HTML-escapes it: the screen escapes it once itself when it
 * draws the link, and escaped twice the nonce would be lost).
 */
function ner_michoel_backup_download_url( $slot, $name ) {
	return add_query_arg(
		array(
			'action'   => 'nm_backup_download',
			'slot'     => $slot,
			'file'     => rawurlencode( $name ),
			'_wpnonce' => wp_create_nonce( 'nm_backup_download' ),
		),
		admin_url( 'admin-post.php' )
	);
}

/**
 * Passes a copy from Bunny on to the administrator's browser as it arrives (never held whole in memory or on
 * this server's disk). Only a .zip that Bunny lists in one of the three backup folders.
 */
function ner_michoel_backup_download() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to download backups.', 'ner-michoel-core' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'nm_backup_download' );

	$slot = isset( $_GET['slot'] ) ? sanitize_key( wp_unslash( $_GET['slot'] ) ) : '';
	$name = isset( $_GET['file'] ) ? (string) wp_unslash( $_GET['file'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked against the pattern and Bunny's list below.
	if ( ! in_array( $slot, ner_michoel_backup_slot_keys(), true ) || ! preg_match( '/^[A-Za-z0-9._-]+\.zip$/', $name ) ) {
		wp_die( esc_html__( 'No such backup.', 'ner-michoel-core' ), '', array( 'response' => 404 ) );
	}

	$folder = ner_michoel_backup_slot_folder( $slot );
	$list   = ner_michoel_backup_list_folder( $folder );
	$size   = null;
	if ( ! is_wp_error( $list ) ) {
		foreach ( $list as $item ) {
			if ( $item['name'] === $name ) {
				$size = $item['size'];
			}
		}
	}
	if ( null === $size ) {
		wp_die( esc_html( is_wp_error( $list ) ? $list->get_error_message() : __( 'That backup isn’t on Bunny any more (a newer one replaced it). Reload the Backups screen.', 'ner-michoel-core' ) ), '', array( 'response' => 404 ) );
	}

	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
	if ( function_exists( 'ini_set' ) ) {
		@ini_set( 'zlib.output_compression', 'Off' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.PHP.IniSet.Risky -- the zip goes out as it is.
	}
	while ( ob_get_level() ) {
		ob_end_clean();
	}

	$status  = 0;
	$started = false;
	$error   = '';
	ner_michoel_backup_curl(
		'GET',
		$folder . $name,
		array(
			'header' => function ( $ch, $line ) use ( &$status ) {
				if ( preg_match( '#^HTTP/\S+\s+(\d{3})#', $line, $m ) ) {
					$status = (int) $m[1];
				}
				return strlen( $line );
			},
			'write'  => function ( $ch, $chunk ) use ( &$status, &$started, &$error, $name, $size ) {
				if ( 200 !== $status ) {
					$error .= $chunk;
					return strlen( $chunk );
				}
				if ( ! $started ) {
					$started = true;
					nocache_headers();
					header( 'Content-Type: application/zip' );
					header( 'Content-Disposition: attachment; filename="' . $name . '"' );
					header( 'Content-Length: ' . $size );
					header( 'X-Content-Type-Options: nosniff' );
					header( 'X-Accel-Buffering: no' );
				}
				echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the zip's bytes.
				flush();
				return strlen( $chunk );
			},
		)
	);
	if ( ! $started ) {
		wp_die( esc_html( ner_michoel_backup_http_error( array( 'code' => $status, 'body' => $error, 'error' => '' ) ) ), '', array( 'response' => 502 ) );
	}
	exit;
}
add_action( 'admin_post_nm_backup_download', 'ner_michoel_backup_download' );
