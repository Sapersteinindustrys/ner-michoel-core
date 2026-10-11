<?php
/**
 * Speed & capacity: what the site measures about itself, so the Control Panel
 * (Reports > Speed & Capacity, site-speed-screen.php) can say how fast it is, how
 * many people it can take at once, and what slows it down.
 *
 * Measured all the time:
 * - Every request that starts WordPress is timed at the end (wall time, CPU time,
 *   database queries) and added to its minute and kind in nm_perf_minutes: pages
 *   built for visitors, the site's data requests (queue, next up, search index,
 *   play counts), the visitor counter, search engines and other bots, signed-in
 *   staff, and background jobs. One small write per request. Pages the host's
 *   cache serves never reach PHP, so they cost nothing and aren't counted here.
 * - Visitors' browsers say who is on the site and who is listening
 *   (visitor-common.php, nm_presence and nm_live_minutes).
 *
 * In the background, every 5 minutes (WP-Cron, ner_michoel_speed_monitor_run()):
 * - the site asks itself for the homepage (is the host's page cache answering?),
 *   a random shiur page with a throw-away query (how long does an uncached page
 *   take to build?), the next-up list and the light counter, and keeps the times;
 * - it skips those test requests while the site is busy, so watching never adds load;
 * - every 30 minutes it re-checks the list of known causes of slowness
 *   (ner_michoel_speed_checks()).
 *
 * Capacity (ner_michoel_speed_capacity()) comes from what was measured: the PHP
 * time and CPU time each person on the site costs per minute, against the host's
 * limits (25 PHP processes at once on Bluehost shared hosting, and the CPU share,
 * set on the screen).
 *
 * Also here, because they are about load: the library cache (counts and lists that
 * only change when the library does), and the "busy" check the importer and the
 * search-index rebuild ask before a heavy run.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NER_MICHOEL_SPEED_DB_VERSION', '1.0' );

// When this request started being ours: the plugin is loaded early, so the time and
// CPU before this point are WordPress starting up.
$GLOBALS['nm_speed_t0']   = microtime( true );
$GLOBALS['nm_speed_cpu0'] = ner_michoel_cpu_ms();

/* ------------------------------------------------------------------ */
/* Tables                                                              */
/* ------------------------------------------------------------------ */

function ner_michoel_speed_create_tables() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$t       = ner_michoel_visitor_tables();
	$charset = $wpdb->get_charset_collate();

	dbDelta(
		"CREATE TABLE {$t['presence']} (
			sid CHAR(32) NOT NULL,
			visitor_hash CHAR(32) NOT NULL,
			first_seen INT UNSIGNED NOT NULL,
			last_seen INT UNSIGNED NOT NULL,
			playing TINYINT UNSIGNED NOT NULL DEFAULT 0,
			signed_in TINYINT UNSIGNED NOT NULL DEFAULT 0,
			path VARCHAR(191) NOT NULL DEFAULT '',
			PRIMARY KEY  (sid),
			KEY last_seen (last_seen)
		) {$charset};"
	);
	dbDelta(
		"CREATE TABLE {$t['live']} (
			bucket INT UNSIGNED NOT NULL,
			online INT UNSIGNED NOT NULL DEFAULT 0,
			listening INT UNSIGNED NOT NULL DEFAULT 0,
			signed_in INT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (bucket)
		) {$charset};"
	);
	dbDelta(
		"CREATE TABLE {$t['perf']} (
			bucket INT UNSIGNED NOT NULL,
			kind VARCHAR(24) NOT NULL,
			hits INT UNSIGNED NOT NULL DEFAULT 0,
			signed_in INT UNSIGNED NOT NULL DEFAULT 0,
			slow INT UNSIGNED NOT NULL DEFAULT 0,
			wall_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
			wall_max INT UNSIGNED NOT NULL DEFAULT 0,
			cpu_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
			queries INT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (bucket,kind)
		) {$charset};"
	);
	dbDelta(
		'CREATE TABLE ' . ner_michoel_play_seen_table() . " (
			k CHAR(32) NOT NULL,
			seen INT UNSIGNED NOT NULL,
			PRIMARY KEY  (k),
			KEY seen (seen)
		) {$charset};"
	);

	update_option( 'nm_speed_db_version', NER_MICHOEL_SPEED_DB_VERSION );
}

function ner_michoel_play_seen_table() {
	global $wpdb;
	return $wpdb->prefix . 'nm_play_seen';
}

/**
 * Creates the tables the first time this version runs. On `init`, not admin_init:
 * the plugin is updated over REST, and visitors' pings need the tables before an
 * administrator next opens wp-admin. The check is one autoloaded option.
 */
function ner_michoel_speed_maybe_create_tables() {
	static $checked = false;
	if ( $checked ) {
		return;
	}
	$checked = true;
	if ( get_option( 'nm_speed_db_version' ) !== NER_MICHOEL_SPEED_DB_VERSION ) {
		ner_michoel_speed_create_tables();
		if ( function_exists( 'ner_michoel_maybe_create_pageviews_table' ) ) {
			ner_michoel_maybe_create_pageviews_table();
		}
	}
}
add_action( 'init', 'ner_michoel_speed_maybe_create_tables', 1 );

function ner_michoel_speed_tables_ready() {
	return get_option( 'nm_speed_db_version' ) === NER_MICHOEL_SPEED_DB_VERSION;
}

/* ------------------------------------------------------------------ */
/* Settings: the host's limits                                         */
/* ------------------------------------------------------------------ */

/**
 * The host's limits the capacity numbers are worked out against. Bluehost allows
 * 25 processes at once on shared hosting (its "Processes Limit" help article); it
 * publishes no CPU figure per plan, and its own guide gives 100% to 200% of one
 * core as usual for shared hosting, so the default is the cautious 1. Both can be
 * changed on the screen once cPanel's Resource Usage page shows the real ones.
 */
function ner_michoel_speed_settings() {
	$saved = get_option( 'nm_speed_settings', array() );
	$s     = wp_parse_args(
		is_array( $saved ) ? $saved : array(),
		array(
			'processes' => 25,
			'cores'     => 1,
		)
	);
	$s['processes'] = max( 1, min( 500, (int) $s['processes'] ) );
	$s['cores']     = max( 0.25, min( 64, (float) $s['cores'] ) );
	return $s;
}

/* ------------------------------------------------------------------ */
/* Timing every request                                                */
/* ------------------------------------------------------------------ */

/**
 * The secret the monitor's own test requests carry (X-NM-Probe), so they're filed
 * as tests and not as visitors.
 */
function ner_michoel_speed_probe_key() {
	$key = get_option( 'nm_speed_probe_key' );
	if ( ! is_string( $key ) || strlen( $key ) < 20 ) {
		$key = wp_generate_password( 32, false, false );
		update_option( 'nm_speed_probe_key', $key, true );
	}
	return $key;
}

function ner_michoel_speed_is_probe() {
	if ( empty( $_SERVER['HTTP_X_NM_PROBE'] ) ) {
		return false;
	}
	$key = get_option( 'nm_speed_probe_key' );
	return is_string( $key ) && '' !== $key && hash_equals( $key, (string) wp_unslash( $_SERVER['HTTP_X_NM_PROBE'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared, never stored or shown.
}

/**
 * The site's own data requests, by route, so the screen can show them apart.
 */
function ner_michoel_speed_rest_kind() {
	$route = '';
	if ( isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) && ! empty( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
		$route = (string) $GLOBALS['wp']->query_vars['rest_route'];
	}
	$map = array(
		'/ner-michoel/v1/pageview'    => 'ping-rest',
		'/ner-michoel/v1/next-up'     => 'next-up',
		'/ner-michoel/v1/queue'       => 'queue',
		'/ner-michoel/v1/shiur-index' => 'search-index',
		'/ner-michoel/v1/shiur-event' => 'play',
	);
	$route = untrailingslashit( $route );
	if ( isset( $map[ $route ] ) ) {
		return $map[ $route ];
	}
	return 0 === strpos( $route, '/ner-michoel/' ) ? 'rest-site' : 'rest';
}

/**
 * What kind of request this was, or '' for one not to count (WP-CLI and other
 * command-line scripts, which aren't web requests; a server cron job running
 * wp-cron.php from the command line still counts as background work).
 */
function ner_michoel_speed_request_kind() {
	if ( ( defined( 'WP_CLI' ) && WP_CLI ) || ( 'cli' === PHP_SAPI && ! wp_doing_cron() ) ) {
		return '';
	}
	if ( ner_michoel_speed_is_probe() ) {
		return 'probe';
	}
	if ( wp_doing_cron() ) {
		return 'cron';
	}
	if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
		return 'staff';
	}
	$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? ner_michoel_clean_user_agent( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned, only matched.
	if ( ner_michoel_is_bot_user_agent( $ua ) ) {
		return 'bot';
	}
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return ner_michoel_speed_rest_kind();
	}
	if ( wp_doing_ajax() ) {
		return 'ajax';
	}
	if ( is_admin() ) {
		return 'admin';
	}
	return 'page';
}

/**
 * At the very end of every request: adds it to this minute's line. Never lets a
 * problem here reach the visitor.
 */
function ner_michoel_speed_record_request() {
	try {
		if ( ! ner_michoel_speed_tables_ready() ) {
			return;
		}
		$kind = ner_michoel_speed_request_kind();
		if ( '' === $kind ) {
			return;
		}

		$start = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : $GLOBALS['nm_speed_t0'];
		$now   = microtime( true );
		$cpu   = null;
		$cpu1  = ner_michoel_cpu_ms();
		if ( null !== $cpu1 && null !== $GLOBALS['nm_speed_cpu0'] ) {
			// Starting WordPress before this plugin loaded is counted as CPU time.
			$cpu = max( 0, $GLOBALS['nm_speed_t0'] - $start ) * 1000 + ( $cpu1 - $GLOBALS['nm_speed_cpu0'] );
		}

		global $wpdb;
		ner_michoel_perf_add( $kind, ( $now - $start ) * 1000, $cpu, isset( $wpdb->num_queries ) ? $wpdb->num_queries : 0, is_user_logged_in() );

		$cache = ner_michoel_library_cache_stats();
		if ( $cache['lookups'] ) {
			ner_michoel_perf_add_cache( $cache['lookups'], $cache['misses'], $cache['build_ms'] );
		}
	} catch ( Throwable $e ) {
		return;
	}
}
add_action( 'shutdown', 'ner_michoel_speed_record_request', 9999 );

/**
 * The library cache's lookups this request, on a line of their own (kind 'cache'):
 * hits = lookups, slow = how many had to be worked out, wall_ms = the time that took.
 */
function ner_michoel_perf_add_cache( $lookups, $misses, $build_ms ) {
	global $wpdb;
	$t        = ner_michoel_visitor_tables();
	$suppress = $wpdb->suppress_errors( true );
	$wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$t['perf']} (bucket, kind, hits, slow, wall_ms, wall_max) VALUES (%d, 'cache', %d, %d, %d, %d)
			ON DUPLICATE KEY UPDATE hits = hits + VALUES(hits), slow = slow + VALUES(slow), wall_ms = wall_ms + VALUES(wall_ms), wall_max = GREATEST(wall_max, VALUES(wall_max))", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			(int) floor( time() / 60 ),
			(int) $lookups,
			(int) $misses,
			(int) round( $build_ms ),
			(int) round( $build_ms )
		)
	);
	$wpdb->suppress_errors( $suppress );
}

/* ------------------------------------------------------------------ */
/* The library cache                                                   */
/* ------------------------------------------------------------------ */

/**
 * The library's revision: changes whenever a shiur or written shiur is published,
 * edited or removed, its file or length changes, or a speaker, series or topic
 * changes. Everything in the library cache is keyed to it.
 */
function ner_michoel_library_rev() {
	return (string) get_option( 'nm_library_rev', '1' );
}

/**
 * Starts the library cache afresh. A request that changes many shiurim (an import
 * batch, a bulk edit) moves the revision once, unless something read the cache in
 * between: a value worked out then may already be out of date.
 */
function ner_michoel_library_changed() {
	$state = ner_michoel_library_cache_state();
	if ( $state['bumped'] && ! $state['read'] ) {
		return;
	}
	update_option( 'nm_library_rev', (string) microtime( true ), true );
	ner_michoel_library_cache_state( array( 'bumped' => true, 'read' => false ) );
}

/**
 * This request's library-cache state: did it move the revision, has the cache
 * been read since.
 */
function ner_michoel_library_cache_state( $set = null ) {
	static $state = array(
		'bumped' => false,
		'read'   => false,
	);
	if ( is_array( $set ) ) {
		$state = array_merge( $state, $set );
	}
	return $state;
}

function ner_michoel_library_changed_on_status( $new_status, $old_status, $post ) {
	if ( in_array( $post->post_type, array( 'shiur', 'written_shiur' ), true ) && ( 'publish' === $new_status || 'publish' === $old_status ) ) {
		ner_michoel_library_changed();
	}
}
add_action( 'transition_post_status', 'ner_michoel_library_changed_on_status', 10, 3 );

function ner_michoel_library_changed_on_delete( $post_id ) {
	if ( in_array( get_post_type( $post_id ), array( 'shiur', 'written_shiur' ), true ) ) {
		ner_michoel_library_changed();
	}
}
add_action( 'delete_post', 'ner_michoel_library_changed_on_delete' );

function ner_michoel_library_changed_on_meta( $meta_id, $post_id, $meta_key ) {
	if ( in_array( $meta_key, array( '_shiur_audio_id', '_shiur_vimeo_id', '_written_pdf_id', '_shiur_duration', '_thumbnail_id', '_nm_bunny_offloaded' ), true ) ) {
		ner_michoel_library_changed();
	}
}
add_action( 'added_post_meta', 'ner_michoel_library_changed_on_meta', 10, 3 );
add_action( 'updated_post_meta', 'ner_michoel_library_changed_on_meta', 10, 3 );
add_action( 'deleted_post_meta', 'ner_michoel_library_changed_on_meta', 10, 3 );

function ner_michoel_library_changed_on_terms( $object_id, $terms, $tt_ids, $taxonomy ) {
	if ( in_array( $taxonomy, array( 'speaker', 'series', 'topic' ), true ) ) {
		ner_michoel_library_changed();
	}
}
add_action( 'set_object_terms', 'ner_michoel_library_changed_on_terms', 10, 4 );

function ner_michoel_library_changed_on_term( $term_id, $tt_id = 0, $taxonomy = '' ) {
	if ( in_array( $taxonomy, array( 'speaker', 'series', 'topic' ), true ) ) {
		ner_michoel_library_changed();
	}
}
add_action( 'created_term', 'ner_michoel_library_changed_on_term', 10, 3 );
add_action( 'edited_term', 'ner_michoel_library_changed_on_term', 10, 3 );
add_action( 'delete_term', 'ner_michoel_library_changed_on_term', 10, 3 );

// Audio and image addresses depend on the Bunny settings.
add_action( 'update_option_nm_bunny_storage', 'ner_michoel_library_changed' );

/**
 * This request's library-cache lookups (for the request's timing line).
 */
function ner_michoel_library_cache_stats( $add = null ) {
	static $stats = array(
		'lookups'  => 0,
		'misses'   => 0,
		'build_ms' => 0,
	);
	if ( is_array( $add ) ) {
		foreach ( $add as $k => $v ) {
			$stats[ $k ] += $v;
		}
	}
	return $stats;
}

/**
 * A value worked out from the library, kept until the library changes (or 12 hours).
 * $name says what it is (and its arguments); $callback works it out. One stored copy
 * per name, replaced in place, so the options table doesn't fill with old copies.
 *
 * Used for the archive's per-series and per-speaker counts and first tracks, the
 * speaker page's lists, and the next-up ranking: queries over the whole library
 * that every visit used to run again.
 */
function ner_michoel_library_cache( $name, $callback, $ttl = 0 ) {
	ner_michoel_library_cache_state( array( 'read' => true ) );
	$key    = 'nm_lib_' . md5( $name );
	$rev    = ner_michoel_library_rev();
	$stored = get_transient( $key );

	if ( is_array( $stored ) && isset( $stored['rev'] ) && array_key_exists( 'v', $stored ) && $stored['rev'] === $rev ) {
		ner_michoel_library_cache_stats( array( 'lookups' => 1 ) );
		return $stored['v'];
	}

	$started = microtime( true );
	$value   = call_user_func( $callback );
	ner_michoel_library_cache_stats(
		array(
			'lookups'  => 1,
			'misses'   => 1,
			'build_ms' => ( microtime( true ) - $started ) * 1000,
		)
	);

	set_transient( $key, array( 'rev' => $rev, 'v' => $value ), $ttl ? (int) $ttl : 12 * HOUR_IN_SECONDS );
	return $value;
}

/* ------------------------------------------------------------------ */
/* Busy or not                                                         */
/* ------------------------------------------------------------------ */

/**
 * How much of the host's capacity requests used over the last few minutes: the
 * larger of PHP processes in use (wall time over the process limit) and CPU (CPU
 * time over the CPU share). 0.5 means half. Background jobs and the monitor's own
 * tests aren't counted, so a long import doesn't call itself busy.
 */
function ner_michoel_speed_utilization( $minutes = 3 ) {
	if ( ! ner_michoel_speed_tables_ready() ) {
		return 0.0;
	}
	global $wpdb;
	$t      = ner_michoel_visitor_tables();
	$now    = time();
	$first  = (int) floor( $now / 60 ) - ( $minutes - 1 );
	$window = max( 60, $now - $first * 60 ) * 1000;

	$row = $wpdb->get_row( $wpdb->prepare( "SELECT COALESCE(SUM(wall_ms), 0) AS wall, COALESCE(SUM(cpu_ms), 0) AS cpu FROM {$t['perf']} WHERE bucket >= %d AND kind NOT IN ('cron', 'probe', 'cache') AND kind NOT LIKE %s", $first, $wpdb->esc_like( 'probe' ) . '%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( ! $row ) {
		return 0.0;
	}

	$s = ner_michoel_speed_settings();
	return max( (float) $row->wall / ( $window * $s['processes'] ), (float) $row->cpu / ( $window * $s['cores'] ) );
}

/**
 * True while visitors keep the server well occupied (40% of what it can do), or
 * while more than half as many people as it can comfortably take are on the site.
 * The importer, the written-summary batch and the search-index rebuild wait then.
 */
function ner_michoel_site_busy() {
	static $busy = null;
	if ( null !== $busy ) {
		return $busy;
	}
	$busy = ner_michoel_speed_utilization( 3 ) >= 0.4;
	if ( ! $busy ) {
		$now      = ner_michoel_speed_people_now();
		$capacity = ner_michoel_speed_capacity();
		$busy     = $capacity['visit_smooth'] > 0 && $now['online'] > 0.5 * $capacity['visit_smooth'];
	}
	return $busy;
}

/**
 * For a heavy background job: when the site is busy, puts the job off by 5 minutes
 * and returns true (the caller then stops). After an hour of waiting it lets the
 * job run anyway, so it can't be held up for ever. Keeps a count per job for the
 * screen (option nm_speed_deferrals).
 */
function ner_michoel_speed_defer_if_busy( $hook, $label ) {
	$all   = get_option( 'nm_speed_deferrals', array() );
	$all   = is_array( $all ) ? $all : array();
	$entry = isset( $all[ $hook ] ) && is_array( $all[ $hook ] ) ? $all[ $hook ] : array( 'streak' => 0, 'total' => 0, 'last' => 0 );

	$entry['label'] = $label;

	if ( ! ner_michoel_site_busy() || $entry['streak'] >= 12 ) {
		if ( $entry['streak'] ) {
			$entry['streak'] = 0;
			$all[ $hook ]    = $entry;
			update_option( 'nm_speed_deferrals', $all, false );
		}
		return false;
	}

	$entry['streak']++;
	$entry['total']++;
	$entry['last'] = time();
	$all[ $hook ]  = $entry;
	update_option( 'nm_speed_deferrals', $all, false );

	if ( ! wp_next_scheduled( $hook ) ) {
		wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, $hook );
	}
	return true;
}

/* ------------------------------------------------------------------ */
/* People on the site                                                  */
/* ------------------------------------------------------------------ */

/**
 * Who is on the site now: tabs heard from in the last two and a half minutes.
 * 'online', 'listening', 'signed_in', and 'pages': the most-viewed addresses now.
 */
function ner_michoel_speed_people_now( $with_pages = false ) {
	$out = array(
		'online'    => 0,
		'listening' => 0,
		'signed_in' => 0,
		'pages'     => array(),
	);
	if ( ! ner_michoel_speed_tables_ready() ) {
		return $out;
	}
	global $wpdb;
	$t     = ner_michoel_visitor_tables();
	$since = time() - NER_MICHOEL_PRESENCE_WINDOW;

	$row = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS online, COALESCE(SUM(playing), 0) AS listening, COALESCE(SUM(signed_in), 0) AS signed_in FROM {$t['presence']} WHERE last_seen >= %d", $since ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( $row ) {
		$out['online']    = (int) $row->online;
		$out['listening'] = (int) $row->listening;
		$out['signed_in'] = (int) $row->signed_in;
	}
	if ( $with_pages ) {
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT path, COUNT(*) AS people, COALESCE(SUM(playing), 0) AS listening FROM {$t['presence']} WHERE last_seen >= %d GROUP BY path ORDER BY people DESC, path ASC LIMIT 6", $since ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $rows as $r ) {
			$out['pages'][] = array(
				'path'      => (string) $r->path,
				'people'    => (int) $r->people,
				'listening' => (int) $r->listening,
			);
		}
	}
	return $out;
}

/* ------------------------------------------------------------------ */
/* Capacity                                                            */
/* ------------------------------------------------------------------ */

/**
 * Request kinds a visitor's own browsing causes (not bots, staff, background jobs or tests).
 */
function ner_michoel_speed_visitor_kinds() {
	return array( 'page', 'ajax', 'admin', 'rest', 'rest-site', 'queue', 'next-up', 'play', 'search-index', 'ping-light', 'ping-rest' );
}

/**
 * Sums per kind over the last $minutes: hits, wall, cpu, signed_in, slow, max.
 */
function ner_michoel_speed_kind_totals( $minutes ) {
	global $wpdb;
	$t    = ner_michoel_visitor_tables();
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT kind, SUM(hits) AS hits, SUM(wall_ms) AS wall, SUM(cpu_ms) AS cpu, SUM(signed_in) AS signed_in, SUM(slow) AS slow, MAX(wall_max) AS wmax, SUM(queries) AS queries FROM {$t['perf']} WHERE bucket > %d GROUP BY kind", (int) floor( time() / 60 ) - $minutes ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$out  = array();
	foreach ( (array) $rows as $r ) {
		$out[ $r->kind ] = array(
			'hits'      => (int) $r->hits,
			'wall'      => (float) $r->wall,
			'cpu'       => (float) $r->cpu,
			'signed_in' => (int) $r->signed_in,
			'slow'      => (int) $r->slow,
			'max'       => (int) $r->wmax,
			'queries'   => (int) $r->queries,
		);
	}
	return $out;
}

/**
 * The middle value of the monitor's test times for one probe over the last $minutes.
 */
function ner_michoel_speed_probe_median( $probe, $minutes = 1440 ) {
	global $wpdb;
	$t    = ner_michoel_visitor_tables();
	$vals = $wpdb->get_col( $wpdb->prepare( "SELECT wall_ms FROM {$t['perf']} WHERE kind = %s AND bucket > %d ORDER BY wall_ms ASC LIMIT 500", 'probe:' . $probe, (int) floor( time() / 60 ) - $minutes ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$n    = count( $vals );
	if ( ! $n ) {
		return null;
	}
	return (float) $vals[ (int) floor( ( $n - 1 ) / 2 ) ];
}

/**
 * How many people the site can take at once, worked out from the last 7 days.
 *
 * For each kind of person (browsing, or only listening) it finds what one person
 * costs per minute in PHP time and in CPU time, then divides the host's limits by
 * it: processes x 60 s, and CPU cores x 60 s, whichever runs out first. "Smooth" is
 * 70% of that (queues stay short); at 100% requests wait in line and pages slow down.
 *
 * With at least two hours of real visitor-minutes, the cost per person is measured
 * (all their requests' time, divided by the minutes they were on the site). Before
 * that it's estimated from the monitor's test requests and typical use: a page every
 * minute, half of them from the host's cache. 'basis' says which.
 */
function ner_michoel_speed_capacity( $fresh = false ) {
	$cached = get_transient( 'nm_speed_capacity' );
	if ( ! $fresh && is_array( $cached ) ) {
		return $cached;
	}

	$empty = array(
		'visit_smooth'    => 0,
		'visit_max'       => 0,
		'listen_smooth'   => 0,
		'listen_max'      => 0,
		'limit'           => 'processes',
		'basis'           => 'none',
		'visitor_minutes' => 0,
		'listen_minutes'  => 0,
		'peak_online'     => 0,
		'peak_listening'  => 0,
		'peak_at'         => 0,
		'cost_visit_ms'   => 0,
		'cost_visit_cpu'  => 0,
		'cost_listen_ms'  => 0,
		'cost_listen_cpu' => 0,
		'ping_ms'         => 0,
		'page_ms'         => 0,
		'cache_share'     => null,
		'settings'        => ner_michoel_speed_settings(),
		'at'              => time(),
	);
	if ( ! ner_michoel_speed_tables_ready() ) {
		return $empty;
	}

	global $wpdb;
	$t       = ner_michoel_visitor_tables();
	$minutes = 7 * 1440;
	$since   = (int) floor( time() / 60 ) - $minutes;
	$s       = ner_michoel_speed_settings();
	$out     = $empty;

	$live = $wpdb->get_row( $wpdb->prepare( "SELECT COALESCE(SUM(online), 0) AS vm, COALESCE(SUM(listening), 0) AS lm, COALESCE(MAX(online), 0) AS peak, COALESCE(MAX(listening), 0) AS peak_l FROM {$t['live']} WHERE bucket > %d", $since ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$out['visitor_minutes'] = $live ? (int) $live->vm : 0;
	$out['listen_minutes']  = $live ? (int) $live->lm : 0;
	$out['peak_online']     = $live ? (int) $live->peak : 0;
	$out['peak_listening']  = $live ? (int) $live->peak_l : 0;
	if ( $out['peak_online'] ) {
		$out['peak_at'] = 60 * (int) $wpdb->get_var( $wpdb->prepare( "SELECT bucket FROM {$t['live']} WHERE bucket > %d ORDER BY online DESC, bucket DESC LIMIT 1", $since ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	$k   = ner_michoel_speed_kind_totals( $minutes );
	$sum = function ( $kinds, $field ) use ( $k ) {
		$total = 0;
		foreach ( $kinds as $kind ) {
			$total += isset( $k[ $kind ][ $field ] ) ? $k[ $kind ][ $field ] : 0;
		}
		return $total;
	};
	$avg = function ( $kinds, $field ) use ( $sum ) {
		$hits = $sum( $kinds, 'hits' );
		return $hits ? $sum( $kinds, $field ) / $hits : null;
	};

	// The visitor counter: what one "still here" costs, about 1.2 of them a minute per person.
	$ping_ms  = $avg( array( 'ping-light', 'ping-rest' ), 'wall' );
	$ping_cpu = $avg( array( 'ping-light', 'ping-rest' ), 'cpu' );
	if ( null === $ping_ms ) {
		$ping_ms  = ner_michoel_speed_probe_median( 'beacon', $minutes );
		$ping_ms  = null === $ping_ms ? 30.0 : $ping_ms;
		$ping_cpu = $ping_ms;
	}
	$pings_per_min = 60 / NER_MICHOEL_PING_SECONDS;
	$out['ping_ms'] = round( $ping_ms, 1 );

	$browse_kinds = array( 'page', 'ajax', 'admin', 'rest', 'rest-site', 'queue', 'next-up', 'play', 'search-index' );
	$listen_kinds = array( 'play', 'next-up' );

	// How often a visitor's page came from the host's cache: page views counted by
	// browsers, against the pages PHP had to build for visitors.
	$views = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t['pageviews']} WHERE created_at >= %s", gmdate( 'Y-m-d H:i:s', $since * 60 ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$built = $sum( array( 'page' ), 'hits' );
	if ( $views >= 50 ) {
		$out['cache_share'] = max( 0, min( 1, 1 - $built / $views ) );
	}

	$page_ms = $avg( array( 'page' ), 'wall' );
	if ( null === $page_ms || $sum( array( 'page' ), 'hits' ) < 20 ) {
		$probe   = ner_michoel_speed_probe_median( 'page', $minutes );
		$page_ms = null !== $probe ? $probe : $page_ms;
	}
	$out['page_ms'] = null === $page_ms ? 0 : round( $page_ms );

	if ( $out['visitor_minutes'] >= 120 ) {
		$out['basis']          = 'measured';
		$cost_visit_ms         = $sum( $browse_kinds, 'wall' ) / $out['visitor_minutes'] + $pings_per_min * $ping_ms;
		$cost_visit_cpu        = $sum( $browse_kinds, 'cpu' ) / $out['visitor_minutes'] + $pings_per_min * $ping_cpu;
	} elseif ( null !== $page_ms ) {
		// Not enough visitors yet: a page a minute, as often from the cache as measured (or half).
		$out['basis']          = 'estimate';
		$miss                  = null === $out['cache_share'] ? 0.5 : max( 0.05, 1 - $out['cache_share'] );
		$cpu_share             = $sum( array( 'page' ), 'wall' ) > 0 ? min( 1, $sum( array( 'page' ), 'cpu' ) / $sum( array( 'page' ), 'wall' ) ) : 0.8;
		$cost_visit_ms         = $miss * $page_ms + $pings_per_min * $ping_ms;
		$cost_visit_cpu        = $miss * $page_ms * $cpu_share + $pings_per_min * $ping_cpu;
	} else {
		set_transient( 'nm_speed_capacity', $out, 5 * MINUTE_IN_SECONDS );
		return $out;
	}

	// Listening only: the pings, plus what starting and following shiurim costs.
	if ( $out['listen_minutes'] >= 60 ) {
		$cost_listen_ms  = $sum( $listen_kinds, 'wall' ) / $out['listen_minutes'] + $pings_per_min * $ping_ms;
		$cost_listen_cpu = $sum( $listen_kinds, 'cpu' ) / $out['listen_minutes'] + $pings_per_min * $ping_cpu;
	} else {
		// A play every 20 minutes and a next-up list every 30, at the times measured (or a page's).
		$play_ms         = $avg( array( 'play' ), 'wall' );
		$next_ms         = $avg( array( 'next-up' ), 'wall' );
		$probe_next      = ner_michoel_speed_probe_median( 'nextup', $minutes );
		$play_ms         = null !== $play_ms ? $play_ms : ( null !== $page_ms ? 0.6 * $page_ms : 200 );
		$next_ms         = null !== $next_ms ? $next_ms : ( null !== $probe_next ? $probe_next : $play_ms );
		$cost_listen_ms  = $play_ms / 20 + $next_ms / 30 + $pings_per_min * $ping_ms;
		$cost_listen_cpu = 0.8 * ( $play_ms / 20 + $next_ms / 30 ) + $pings_per_min * $ping_cpu;
	}

	$by_processes = function ( $ms ) use ( $s ) {
		return $ms > 0 ? $s['processes'] * 60000 / $ms : 0;
	};
	$by_cpu = function ( $ms ) use ( $s ) {
		return $ms > 0 ? $s['cores'] * 60000 / $ms : 0;
	};

	$visit_p  = $by_processes( $cost_visit_ms );
	$visit_c  = $by_cpu( $cost_visit_cpu );
	$listen_p = $by_processes( $cost_listen_ms );
	$listen_c = $by_cpu( $cost_listen_cpu );

	$out['limit']           = $visit_c < $visit_p ? 'cpu' : 'processes';
	$out['visit_max']       = (int) floor( min( $visit_p, $visit_c ) );
	$out['visit_smooth']    = (int) floor( 0.7 * min( $visit_p, $visit_c ) );
	$out['listen_max']      = (int) floor( min( $listen_p, $listen_c ) );
	$out['listen_smooth']   = (int) floor( 0.7 * min( $listen_p, $listen_c ) );
	$out['cost_visit_ms']   = round( $cost_visit_ms, 1 );
	$out['cost_visit_cpu']  = round( $cost_visit_cpu, 1 );
	$out['cost_listen_ms']  = round( $cost_listen_ms, 1 );
	$out['cost_listen_cpu'] = round( $cost_listen_cpu, 1 );

	set_transient( 'nm_speed_capacity', $out, 10 * MINUTE_IN_SECONDS );
	return $out;
}

/* ------------------------------------------------------------------ */
/* The background monitor                                              */
/* ------------------------------------------------------------------ */

function ner_michoel_speed_cron_schedules( $schedules ) {
	$schedules['nm_every_5_minutes'] = array(
		'interval' => 5 * MINUTE_IN_SECONDS,
		'display'  => __( 'Every 5 minutes', 'ner-michoel-core' ),
	);
	return $schedules;
}
add_filter( 'cron_schedules', 'ner_michoel_speed_cron_schedules' );

function ner_michoel_speed_ensure_cron() {
	if ( ! wp_next_scheduled( 'nm_speed_monitor' ) ) {
		wp_schedule_event( time() + MINUTE_IN_SECONDS, 'nm_every_5_minutes', 'nm_speed_monitor' );
	}
}
add_action( 'init', 'ner_michoel_speed_ensure_cron' );

/**
 * Headers for the monitor's own requests: like a browser's (the host's firewall
 * turns away bare requests), with no cookies, and the probe key so the request is
 * filed as a test.
 */
function ner_michoel_speed_probe_args( $extra = array() ) {
	return array_merge(
		array(
			'timeout'     => 20,
			'redirection' => 2,
			'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
			'headers'     => array(
				'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36 NerMichoelMonitor/1.0',
				'Accept'          => 'text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8',
				'Accept-Language' => 'en-US,en;q=0.9',
				'Referer'         => home_url( '/' ),
				'X-NM-Probe'      => ner_michoel_speed_probe_key(),
			),
		),
		$extra
	);
}

/**
 * One timed request. Returns array( ok, ms, code, cache (the host's X-Proxy-Cache
 * header, if any), error ).
 */
function ner_michoel_speed_timed_request( $method, $url, $args = array() ) {
	$started  = microtime( true );
	$response = 'POST' === $method ? wp_remote_post( $url, ner_michoel_speed_probe_args( $args ) ) : wp_remote_get( $url, ner_michoel_speed_probe_args( $args ) );
	$ms       = ( microtime( true ) - $started ) * 1000;

	if ( is_wp_error( $response ) ) {
		return array( 'ok' => false, 'ms' => round( $ms ), 'code' => 0, 'cache' => '', 'error' => $response->get_error_message() );
	}
	$code = (int) wp_remote_retrieve_response_code( $response );
	return array(
		'ok'    => $code >= 200 && $code < 300,
		'ms'    => round( $ms ),
		'code'  => $code,
		'cache' => (string) wp_remote_retrieve_header( $response, 'x-proxy-cache' ),
		'age'   => (string) wp_remote_retrieve_header( $response, 'cache-control' ),
		'error' => '',
	);
}

/**
 * A random published shiur, for the uncached-page test.
 */
function ner_michoel_speed_random_shiur() {
	global $wpdb;
	$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'shiur' AND post_status = 'publish'" );
	if ( ! $count ) {
		return 0;
	}
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'shiur' AND post_status = 'publish' ORDER BY ID LIMIT 1 OFFSET %d", wp_rand( 0, $count - 1 ) ) );
}

/**
 * The monitor's test requests. Each time is kept in nm_perf_minutes as kind
 * 'probe:<name>' (and 'probe-fail:<name>' when it failed). All of them together
 * get 25 seconds at most, so a host that blocks the server's requests to itself
 * can't hold a "Check now" (or a cron run) past the host's time limit.
 */
function ner_michoel_speed_run_probes() {
	$results  = array();
	$shiur    = ner_michoel_speed_random_shiur();
	$token    = wp_rand( 100000, 999999999 );
	$deadline = microtime( true ) + 25;

	$probes = array(
		'home' => array( 'GET', home_url( '/' ), array() ),
	);
	if ( $shiur ) {
		$probes['page']   = array( 'GET', add_query_arg( 'nm_probe', $token, get_permalink( $shiur ) ), array() );
		$probes['nextup'] = array(
			'GET',
			add_query_arg(
				array(
					'rest_route' => '/ner-michoel/v1/next-up',
					'current'    => $shiur,
					'nm_probe'   => $token,
				),
				home_url( '/' )
			),
			array(),
		);
	}
	$probes['beacon'] = array(
		'POST',
		NER_MICHOEL_CORE_URL . 'beacon.php',
		array(
			'body'    => '{"t":"test"}',
			'headers' => array_merge( ner_michoel_speed_probe_args()['headers'], array( 'Content-Type' => 'application/json' ) ),
		),
	);

	foreach ( $probes as $name => $probe ) {
		$left = $deadline - microtime( true );
		if ( $left < 3 ) {
			$results[ $name ] = array( 'ok' => false, 'ms' => 0, 'code' => 0, 'cache' => '', 'error' => __( 'Not run: the tests before it used up the time allowed.', 'ner-michoel-core' ) );
			continue;
		}
		$results[ $name ] = ner_michoel_speed_timed_request( $probe[0], $probe[1], array_merge( $probe[2], array( 'timeout' => min( 15, (int) floor( $left ) ) ) ) );
		ner_michoel_perf_add( ( $results[ $name ]['ok'] ? 'probe:' : 'probe-fail:' ) . $name, $results[ $name ]['ms'], null, 0, false );
	}
	return $results;
}

/**
 * Every 5 minutes: the test requests (unless the site is busy), the list of known
 * causes every 30 minutes, and clearing out old numbers once an hour. $force runs
 * everything now (the screen's "Check now").
 */
function ner_michoel_speed_monitor_run( $force = false ) {
	ner_michoel_speed_maybe_create_tables();
	if ( ! ner_michoel_speed_tables_ready() ) {
		return;
	}

	$state = get_option( 'nm_speed_monitor', array() );
	$state = is_array( $state ) ? $state : array();
	$now   = time();
	$busy  = ner_michoel_site_busy();

	$state['last_run'] = $now;
	$state['busy']     = $busy;
	if ( ! $busy || $force ) {
		$state['probes']    = ner_michoel_speed_run_probes();
		$state['probed_at'] = $now;
		$state['skipped']   = false;
	} else {
		$state['skipped'] = true;
	}
	update_option( 'nm_speed_monitor', $state, false );

	ner_michoel_speed_capacity( true );

	$checks = get_option( 'nm_speed_checks', array() );
	if ( $force || ! is_array( $checks ) || empty( $checks['at'] ) || $checks['at'] < $now - 30 * MINUTE_IN_SECONDS ) {
		update_option(
			'nm_speed_checks',
			array(
				'at'    => $now,
				'items' => ner_michoel_speed_checks(),
			),
			false
		);
	}

	if ( empty( $state['pruned'] ) || $state['pruned'] < $now - HOUR_IN_SECONDS ) {
		ner_michoel_speed_prune();
		$state['pruned'] = $now;
		update_option( 'nm_speed_monitor', $state, false );
	}
}
add_action( 'nm_speed_monitor', 'ner_michoel_speed_monitor_run' );

/**
 * Keeps 15 days of minutes, and the play-count guard's last few minutes.
 */
function ner_michoel_speed_prune() {
	global $wpdb;
	$t      = ner_michoel_visitor_tables();
	$oldest = (int) floor( time() / 60 ) - 15 * 1440;
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$t['perf']} WHERE bucket < %d", $oldest ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$t['live']} WHERE bucket < %d", $oldest ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$t['presence']} WHERE last_seen < %d", time() - 15 * MINUTE_IN_SECONDS ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . ner_michoel_play_seen_table() . ' WHERE seen < %d', time() - 10 * MINUTE_IN_SECONDS ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}

/* ------------------------------------------------------------------ */
/* What slows the site down, and whether it's handled                  */
/* ------------------------------------------------------------------ */

/**
 * The known causes of slowness (from the audits of October 2026, plus a few the
 * monitor can see for itself), each checked against the live site. Each item:
 *   id, title, status ('handled', 'partly', 'watch', 'host' or 'todo'),
 *   impact ('high', 'medium' or 'low'), what (the cause in plain words),
 *   now (what was found just now), done (what this site does about it).
 */
function ner_michoel_speed_checks() {
	global $wpdb;
	$t     = ner_michoel_visitor_tables();
	$day   = ner_michoel_speed_kind_totals( 1440 );
	$get   = function ( $kind, $field ) use ( $day ) {
		return isset( $day[ $kind ][ $field ] ) ? $day[ $kind ][ $field ] : 0;
	};
	$avg   = function ( $kind ) use ( $get ) {
		return $get( $kind, 'hits' ) ? round( $get( $kind, 'wall' ) / $get( $kind, 'hits' ) ) : null;
	};
	$state = get_option( 'nm_speed_monitor', array() );
	$probe = isset( $state['probes'] ) && is_array( $state['probes'] ) ? $state['probes'] : array();
	$items = array();

	// 1. The visitor counter.
	$light = $get( 'ping-light', 'hits' );
	$rest  = $get( 'ping-rest', 'hits' );
	$beacon_ok = isset( $probe['beacon'] ) ? $probe['beacon']['ok'] : null;
	if ( $light + $rest > 0 ) {
		$share  = $light / ( $light + $rest );
		$status = $share >= 0.8 ? 'handled' : ( $share > 0 ? 'partly' : 'todo' );
		/* translators: 1: percent, 2: ms, 3: ms */
		$now = sprintf( __( '%1$s%% of visitor pings in the last 24 hours used the light counter (%2$s ms each on average; the REST route took %3$s ms).', 'ner-michoel-core' ), number_format_i18n( 100 * $share ), null === $avg( 'ping-light' ) ? '—' : number_format_i18n( $avg( 'ping-light' ) ), null === $avg( 'ping-rest' ) ? '—' : number_format_i18n( $avg( 'ping-rest' ) ) );
	} elseif ( null !== $beacon_ok ) {
		$status = $beacon_ok ? 'handled' : 'todo';
		$now    = $beacon_ok
			/* translators: %s: ms */
			? sprintf( __( 'No visitor pings in the last 24 hours yet. The monitor’s test reached the light counter in %s ms.', 'ner-michoel-core' ), number_format_i18n( $probe['beacon']['ms'] ) )
			: __( 'The monitor’s test could not reach the light counter, so visitors will use the slower REST route. Ask Bluehost whether direct requests to plugin files are blocked.', 'ner-michoel-core' );
	} else {
		$status = 'handled';
		$now    = __( 'Waiting for the first visitors and the first background check.', 'ner-michoel-core' );
	}
	$items[] = array(
		'id'     => 'counter',
		'title'  => __( 'Counting a page view started all of WordPress', 'ner-michoel-core' ),
		'status' => $status,
		'impact' => 'high',
		'what'   => __( 'Every page view sent a ping that loaded every plugin and the theme just to save one row (about 0.19 s of a server process each). For visitors who get pages from the host’s cache, it was the main cost.', 'ner-michoel-core' ),
		'now'    => $now,
		'done'   => __( 'Pings now go to a light counter that starts only the database layer, and the REST route is kept as a fallback. The same counter now also says who is on the site and who is listening.', 'ner-michoel-core' ),
	);

	// 2. The host's page cache.
	$home = isset( $probe['home'] ) ? $probe['home'] : null;
	if ( $home && $home['ok'] && '' !== $home['cache'] ) {
		$status = 'host';
		/* translators: 1: cache state, 2: ms */
		$now = sprintf( __( 'The homepage came back from the host’s cache (%1$s) in %2$s ms at the last check.', 'ner-michoel-core' ), $home['cache'], number_format_i18n( $home['ms'] ) );
	} elseif ( $home && $home['ok'] ) {
		$status = 'todo';
		/* translators: %s: ms */
		$now = sprintf( __( 'The homepage answered in %s ms but without the host’s cache header. Check that caching is on in the Bluehost plugin (Performance).', 'ner-michoel-core' ), number_format_i18n( $home['ms'] ) );
	} else {
		$status = 'watch';
		$now    = __( 'Not checked yet, or the server could not reach its own homepage.', 'ner-michoel-core' );
	}
	$items[] = array(
		'id'     => 'page-cache',
		'title'  => __( 'Pages built from scratch for every visitor', 'ner-michoel-core' ),
		'status' => $status,
		'impact' => 'high',
		'what'   => __( 'A page the host’s cache serves costs the server nothing. Bluehost keeps pages for visitors who aren’t signed in for 2 hours.', 'ner-michoel-core' ),
		'now'    => $now,
		'done'   => __( 'The host’s cache does this; the monitor checks that it is answering every 5 minutes.', 'ner-michoel-core' ),
	);

	// 3. Signed-in visitors.
	$built    = $get( 'page', 'hits' );
	$built_in = $get( 'page', 'signed_in' );
	$items[]  = array(
		'id'     => 'signed-in',
		'title'  => __( 'Signed-in visitors skip the page cache', 'ner-michoel-core' ),
		'status' => 'partly',
		'impact' => 'medium',
		'what'   => __( 'Anyone signed in (for History, Saved or their account) gets every page built fresh. As accounts grow, so does this load.', 'ner-michoel-core' ),
		'now'    => $built
			/* translators: 1: count, 2: count, 3: ms */
			? sprintf( __( 'In the last 24 hours the server built %1$s pages for visitors, %2$s of them for signed-in people, %3$s ms each on average.', 'ner-michoel-core' ), number_format_i18n( $built ), number_format_i18n( $built_in ), number_format_i18n( (int) $avg( 'page' ) ) )
			: __( 'No pages built for visitors in the last 24 hours.', 'ner-michoel-core' ),
		'done'   => __( 'The heavy parts of those pages (series and speaker counts, first tracks, a speaker’s lists, next up) now come from a cache that is kept until the library changes. The page itself is still built each time.', 'ner-michoel-core' ),
	);

	// 4. Uncached pages.
	$page_ms = ner_michoel_speed_probe_median( 'page', 1440 );
	$items[] = array(
		'id'     => 'cold-pages',
		'title'  => __( 'Pages not in the cache are slow the first time', 'ner-michoel-core' ),
		'status' => null === $page_ms ? 'watch' : ( $page_ms > 1500 ? 'todo' : 'watch' ),
		'impact' => 'medium',
		'what'   => __( 'The cache keeps a page for 2 hours, but most of the thousands of shiur pages aren’t visited that often, so many visits build the page from scratch.', 'ner-michoel-core' ),
		'now'    => null === $page_ms
			? __( 'Not measured yet.', 'ner-michoel-core' )
			/* translators: %s: ms */
			: sprintf( __( 'A random shiur page, built from scratch, took %s ms (middle value of the last 24 hours of tests).', 'ner-michoel-core' ), number_format_i18n( $page_ms ) ),
		'done'   => __( 'Measured every 5 minutes on a random shiur. The cached lists above make each build cheaper.', 'ner-michoel-core' ),
	);

	// 5. Next up.
	$next_ms = $avg( 'next-up' );
	$items[] = array(
		'id'     => 'next-up',
		'title'  => __( '“Next up” searched every title in the library', 'ner-michoel-core' ),
		'status' => 'handled',
		'impact' => 'high',
		'what'   => __( 'When a queue runs out, the player asks for related shiurim. That compared title words against every shiur and loaded up to 400 of them, and grows with the library.', 'ner-michoel-core' ),
		'now'    => null === $next_ms
			? __( 'No next-up requests in the last 24 hours.', 'ner-michoel-core' )
			/* translators: 1: count, 2: ms */
			: sprintf( __( '%1$s next-up requests in the last 24 hours, %2$s ms on average.', 'ner-michoel-core' ), number_format_i18n( $get( 'next-up', 'hits' ) ), number_format_i18n( $next_ms ) ),
		'done'   => __( 'Each shiur’s ranked list is worked out once and kept until the library changes, so listeners of the same shiur share it. Signed-in listeners with a History still get their own order.', 'ner-michoel-core' ),
	);

	// 6. Library lists and counts.
	$lookups = $get( 'cache', 'hits' );
	$misses  = $get( 'cache', 'slow' );
	$items[] = array(
		'id'     => 'lists-cache',
		'title'  => __( 'Counts and lists worked out again on every visit', 'ner-michoel-core' ),
		'status' => 'handled',
		'impact' => 'medium',
		'what'   => __( 'The Shiurim pages count every series’ and speaker’s shiurim and find each one’s first track: queries over the whole library, on every visit, though they only change when a shiur is added.', 'ner-michoel-core' ),
		'now'    => $lookups
			/* translators: 1: percent, 2: count */
			? sprintf( __( '%1$s%% of %2$s lookups in the last 24 hours came from the cache.', 'ner-michoel-core' ), number_format_i18n( 100 * ( $lookups - $misses ) / $lookups ), number_format_i18n( $lookups ) )
			: __( 'No lookups in the last 24 hours yet.', 'ner-michoel-core' ),
		'done'   => __( 'Kept until the library changes (any shiur, speaker, series or topic edit starts fresh), at most 12 hours.', 'ner-michoel-core' ),
	);

	// 7. Speaker pages.
	$items[] = array(
		'id'     => 'speaker-page',
		'title'  => __( 'Speaker pages loaded every shiur by that speaker', 'ner-michoel-core' ),
		'status' => 'handled',
		'impact' => 'medium',
		'what'   => __( 'To find the few shiurim that aren’t in a series (usually none), each speaker page loaded all of the speaker’s shiurim: over 1,000 for some.', 'ner-michoel-core' ),
		'now'    => __( 'One small query now asks only for the speaker’s shiurim without a series.', 'ner-michoel-core' ),
		'done'   => __( 'Fixed in the plugin.', 'ner-michoel-core' ),
	);

	// 8. Play counts.
	$items[] = array(
		'id'     => 'play-count',
		'title'  => __( 'Play counts lost when many people pressed Play at once', 'ner-michoel-core' ),
		'status' => 'handled',
		'impact' => 'low',
		'what'   => __( 'The count was read, raised by one and written back, so two plays at the same moment counted once. It also kept a note per play in the options table.', 'ner-michoel-core' ),
		/* translators: %s: count */
		'now'    => sprintf( __( '%s plays recorded in the last 24 hours.', 'ner-michoel-core' ), number_format_i18n( $get( 'play', 'hits' ) ) ),
		'done'   => __( 'The database now adds one in a single step, and the double-press guard has a small table of its own.', 'ner-michoel-core' ),
	);

	// 9. Background jobs.
	$def      = get_option( 'nm_speed_deferrals', array() );
	$deferred = 0;
	$last_def = 0;
	foreach ( is_array( $def ) ? $def : array() as $entry ) {
		$deferred += isset( $entry['total'] ) ? (int) $entry['total'] : 0;
		$last_def  = max( $last_def, isset( $entry['last'] ) ? (int) $entry['last'] : 0 );
	}
	$items[] = array(
		'id'     => 'background-jobs',
		'title'  => __( 'The library import and the search index ran at busy times', 'ner-michoel-core' ),
		'status' => 'handled',
		'impact' => 'medium',
		'what'   => __( 'Importing shiurim and rebuilding the search index are heavy, and they use the same server processes as visitors.', 'ner-michoel-core' ),
		'now'    => $deferred
			/* translators: 1: count, 2: time ago */
			? sprintf( __( 'Put off %1$s times so far because the site was busy, most recently %2$s ago.', 'ner-michoel-core' ), number_format_i18n( $deferred ), human_time_diff( $last_def ) )
			: __( 'Not put off yet: the site hasn’t been busy while a job was due.', 'ner-michoel-core' ),
		'done'   => __( 'Before each run they check whether the site is busy and, if so, wait 5 minutes (at most an hour, so they always finish).', 'ner-michoel-core' ),
	);

	// 10. Audio on Bunny.
	$audio = $wpdb->get_row(
		"SELECT COUNT(DISTINCT pm.meta_value) AS total, COUNT(DISTINCT CASE WHEN off.meta_value = '1' THEN pm.meta_value END) AS cdn
		FROM {$wpdb->postmeta} pm
		INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'shiur' AND p.post_status = 'publish'
		LEFT JOIN {$wpdb->postmeta} off ON off.post_id = pm.meta_value AND off.meta_key = '_nm_bunny_offloaded'
		WHERE pm.meta_key = '_shiur_audio_id' AND pm.meta_value NOT IN ('', '0')"
	);
	$total   = $audio ? (int) $audio->total : 0;
	$cdn     = $audio ? (int) $audio->cdn : 0;
	$bunny   = function_exists( 'ner_michoel_bunny_storage_is_configured' ) && ner_michoel_bunny_storage_is_configured();
	$share   = $total ? $cdn / $total : 1;
	$items[] = array(
		'id'     => 'audio-cdn',
		'title'  => __( 'Audio and video played from this server', 'ner-michoel-core' ),
		'status' => ! $total ? 'watch' : ( $share >= 0.98 ? 'handled' : ( $bunny ? 'partly' : 'todo' ) ),
		'impact' => 'high',
		'what'   => __( 'Every play, skip and seek of a file on this server uses the same shared server as the pages.', 'ner-michoel-core' ),
		'now'    => $total
			/* translators: 1: count, 2: count, 3: percent */
			? sprintf( __( '%1$s of %2$s shiur audio files (%3$s%%) are on Bunny’s network, so listening costs this server almost nothing.', 'ner-michoel-core' ), number_format_i18n( $cdn ), number_format_i18n( $total ), number_format_i18n( 100 * $share, 1 ) )
			: __( 'No shiurim with audio files yet.', 'ner-michoel-core' ),
		'done'   => $bunny
			? __( 'New uploads and imports go to Bunny Storage automatically (Advanced > Storage). Files uploaded before it was switched on stay here until moved.', 'ner-michoel-core' )
			: __( 'Bunny Storage is not switched on (Advanced > Storage).', 'ner-michoel-core' ),
	);

	// 11. Image sizes.
	$crops   = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->postmeta} off INNER JOIN {$wpdb->posts} a ON a.ID = off.post_id AND a.post_mime_type LIKE 'image/%'
		WHERE off.meta_key = '_nm_bunny_offloaded' AND off.meta_value = '1'"
	);
	$items[] = array(
		'id'     => 'image-sizes',
		'title'  => __( 'Smaller image sizes stay on this server', 'ner-michoel-core' ),
		'status' => 'watch',
		'impact' => 'low',
		'what'   => __( 'For a picture moved to Bunny, only the full-size file moves; the smaller sizes cards use stay here. The server sends them as plain files (no PHP), so they hardly count against its limits.', 'ner-michoel-core' ),
		/* translators: %s: count */
		'now'    => sprintf( __( '%s pictures are on Bunny with their smaller sizes still here.', 'ner-michoel-core' ), number_format_i18n( $crops ) ),
		'done'   => __( 'Not changed. Worth doing if phone data use or this server’s traffic becomes a problem.', 'ner-michoel-core' ),
	);

	// 12. Fonts.
	$theme_features = (array) apply_filters( 'ner_michoel_theme_speed_features', array() );
	$items[] = array(
		'id'     => 'fonts',
		'title'  => __( 'Google Fonts connected late', 'ner-michoel-core' ),
		'status' => in_array( 'font-preconnect', $theme_features, true ) ? 'handled' : 'todo',
		'impact' => 'low',
		'what'   => __( 'The serif font comes from Google in two steps (fonts.googleapis.com, then fonts.gstatic.com), each needing its own connection before the text can show in it.', 'ner-michoel-core' ),
		'now'    => in_array( 'font-preconnect', $theme_features, true )
			? __( 'The theme tells browsers to open both connections early.', 'ner-michoel-core' )
			: __( 'The theme doesn’t open the connections early yet (needs theme 0.2.66 or later).', 'ner-michoel-core' ),
		'done'   => __( 'This only affects how fast text shows for the visitor, not the server.', 'ner-michoel-core' ),
	);

	// 13. The written library.
	$written = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'written_shiur' AND post_status = 'publish'" );
	$items[] = array(
		'id'     => 'written-archive',
		'title'  => __( 'The written library loads everything at once', 'ner-michoel-core' ),
		'status' => $written > 600 ? 'todo' : 'watch',
		'impact' => $written > 600 ? 'medium' : 'low',
		'what'   => __( 'The Written Shiurim page lists every written shiur in one go, so its search can work in the browser. Fine at today’s size; slow if thousands are added.', 'ner-michoel-core' ),
		/* translators: %s: count */
		'now'    => sprintf( __( '%s written shiurim. Worth changing past about 600.', 'ner-michoel-core' ), number_format_i18n( $written ) ),
		'done'   => __( 'Watched here.', 'ner-michoel-core' ),
	);

	// 14. Two Bunny plugins.
	$others = array();
	foreach ( (array) get_option( 'active_plugins', array() ) as $plugin ) {
		if ( false !== stripos( $plugin, 'bunny' ) && 0 !== strpos( $plugin, 'ner-michoel-core/' ) ) {
			$others[] = dirname( $plugin );
		}
	}
	$items[] = array(
		'id'     => 'bunny-twice',
		'title'  => __( 'Two Bunny add-ons rewriting the same addresses', 'ner-michoel-core' ),
		'status' => $others ? 'watch' : 'handled',
		'impact' => 'low',
		'what'   => __( 'The separate bunny.net plugin rewrites image addresses to its CDN, and this site’s own Bunny Storage does too. Two rewrites of the same file can point a picture at the wrong place.', 'ner-michoel-core' ),
		'now'    => $others
			/* translators: %s: plugin folder names */
			? sprintf( __( 'Also active: %s. If pictures look right, leave it; if one is broken, try switching that plugin off.', 'ner-michoel-core' ), implode( ', ', $others ) )
			: __( 'Only this site’s own Bunny Storage is active.', 'ner-michoel-core' ),
		'done'   => __( 'Watched here. Nothing was switched off.', 'ner-michoel-core' ),
	);

	// 15. Bots.
	$all_wall = 0;
	foreach ( array_merge( ner_michoel_speed_visitor_kinds(), array( 'bot' ) ) as $kind ) {
		$all_wall += $get( $kind, 'wall' );
	}
	$bot_share = $all_wall ? $get( 'bot', 'wall' ) / $all_wall : 0;
	$items[]   = array(
		'id'     => 'bots',
		'title'  => __( 'Search engines and bots using the server', 'ner-michoel-core' ),
		'status' => $bot_share > 0.4 ? 'todo' : 'watch',
		'impact' => $bot_share > 0.4 ? 'high' : 'low',
		'what'   => __( 'Crawlers read thousands of shiur pages the cache doesn’t have, each one built from scratch.', 'ner-michoel-core' ),
		/* translators: 1: percent, 2: count */
		'now'    => sprintf( __( '%1$s%% of the server’s page work in the last 24 hours was for bots (%2$s requests).', 'ner-michoel-core' ), number_format_i18n( 100 * $bot_share ), number_format_i18n( $get( 'bot', 'hits' ) ) ),
		'done'   => __( 'Watched here. If it grows past about 40%, slow down the crawlers named in the host’s logs (robots.txt, or Bluehost’s bot protection).', 'ner-michoel-core' ),
	);

	// 16. Object cache.
	$items[] = array(
		'id'     => 'object-cache',
		'title'  => __( 'No memory cache between requests', 'ner-michoel-core' ),
		'status' => wp_using_ext_object_cache() ? 'handled' : 'host',
		'impact' => 'low',
		'what'   => __( 'Without one (Redis or Memcached), every request reads settings and lists from the database again.', 'ner-michoel-core' ),
		'now'    => wp_using_ext_object_cache() ? __( 'A memory cache is in use.', 'ner-michoel-core' ) : __( 'None on this hosting plan. The library cache above keeps the heavy lists in the database instead.', 'ner-michoel-core' ),
		'done'   => __( 'Up to the host.', 'ner-michoel-core' ),
	);

	// 17. Autoloaded settings.
	$autoload = (int) $wpdb->get_var( "SELECT COALESCE(SUM(LENGTH(option_value)), 0) FROM {$wpdb->options} WHERE autoload IN ('yes', 'on', 'auto-on', 'auto')" );
	$items[]  = array(
		'id'     => 'autoload',
		'title'  => __( 'Settings loaded on every request', 'ner-michoel-core' ),
		'status' => $autoload > 1024 * 1024 ? 'todo' : 'handled',
		'impact' => $autoload > 1024 * 1024 ? 'medium' : 'low',
		'what'   => __( 'WordPress reads every “autoloaded” setting on every request. Plugins that store big things there slow down every page.', 'ner-michoel-core' ),
		/* translators: %s: size */
		'now'    => sprintf( __( '%s of settings are read on every request (under 1 MB is fine).', 'ner-michoel-core' ), size_format( $autoload, 0 ) ),
		'done'   => __( 'Watched here. This site’s big items (the search index, these numbers) are kept out of it.', 'ner-michoel-core' ),
	);

	// 18. The hosting plan itself.
	$cap     = ner_michoel_speed_capacity();
	$s       = ner_michoel_speed_settings();
	$items[] = array(
		'id'     => 'ceiling',
		'title'  => __( 'The shared hosting plan’s limits', 'ner-michoel-core' ),
		'status' => 'host',
		'impact' => 'high',
		'what'   => __( 'Shared hosting allows a fixed number of server processes and a share of a CPU. A spike on one popular shiur takes processes from everyone else.', 'ner-michoel-core' ),
		'now'    => $cap['visit_smooth']
			/* translators: 1: processes, 2: cores, 3: people, 4: people */
			? sprintf( __( 'Worked out against %1$s processes and %2$s CPU core(s): about %3$s people browsing, or %4$s listening, at once without slowing down.', 'ner-michoel-core' ), number_format_i18n( $s['processes'] ), number_format_i18n( $s['cores'], floor( $s['cores'] ) == $s['cores'] ? 0 : 2 ), number_format_i18n( $cap['visit_smooth'] ), number_format_i18n( $cap['listen_smooth'] ) ) // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- float whole-number test.
			: __( 'Not enough measurements yet.', 'ner-michoel-core' ),
		'done'   => __( 'Everything above stretches what the plan can take. For far more people at once, a bigger plan (VPS) is the next step.', 'ner-michoel-core' ),
	);

	return $items;
}
