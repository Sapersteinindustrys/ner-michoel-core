<?php
/**
 * Reports > Speed & Capacity in the Control Panel: who is on the site right now
 * (refreshed every 10 seconds while the page is open), how many people the site
 * can take at once, how fast it has been, and the list of known causes of
 * slowness with what is done about each. The numbers come from site-speed.php;
 * the charts are drawn by assets/site-speed.js.
 *
 * REST (manage_options): GET speed/live, GET speed/history?range=day|week,
 * POST speed/check (runs the background monitor now), POST speed/settings.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ------------------------------------------------------------------ */
/* Data for the screen                                                 */
/* ------------------------------------------------------------------ */

/**
 * The last 60 minutes, minute by minute: people on the site and listening, and how
 * much of the server's capacity was in use.
 */
function ner_michoel_speed_live_data() {
	global $wpdb;
	$t      = ner_michoel_visitor_tables();
	$now    = time();
	$last   = (int) floor( $now / 60 );
	$first  = $last - 59;
	$s      = ner_michoel_speed_settings();
	$people = array();
	$work   = array();

	if ( ner_michoel_speed_tables_ready() ) {
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT bucket, online, listening FROM {$t['live']} WHERE bucket >= %d", $first ) ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$people[ (int) $r->bucket ] = $r;
		}
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT bucket, SUM(wall_ms) AS wall, SUM(cpu_ms) AS cpu FROM {$t['perf']} WHERE bucket >= %d AND kind NOT IN ('cron', 'probe', 'cache') AND kind NOT LIKE %s GROUP BY bucket", $first, $wpdb->esc_like( 'probe' ) . '%' ) ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$work[ (int) $r->bucket ] = $r;
		}
	}

	$minutes = array();
	for ( $b = $first; $b <= $last; $b++ ) {
		$use = 0;
		if ( isset( $work[ $b ] ) ) {
			$span = $b === $last ? max( 1, $now - $b * 60 ) : 60;
			$use  = max( $work[ $b ]->wall / ( $span * 1000 * $s['processes'] ), $work[ $b ]->cpu / ( $span * 1000 * $s['cores'] ) );
		}
		$minutes[] = array(
			't'         => $b * 60,
			'online'    => isset( $people[ $b ] ) ? (int) $people[ $b ]->online : 0,
			'listening' => isset( $people[ $b ] ) ? (int) $people[ $b ]->listening : 0,
			'use'       => round( min( 2, $use ) * 100, 1 ),
		);
	}

	// A minute is counted at its first ping; for the minute still running, the count
	// right now is the better number (it's what the tiles above the chart show).
	$people_now = ner_michoel_speed_people_now( true );
	$current    = count( $minutes ) - 1;
	$minutes[ $current ]['online']    = max( $minutes[ $current ]['online'], $people_now['online'] );
	$minutes[ $current ]['listening'] = max( $minutes[ $current ]['listening'], $people_now['listening'] );

	$monitor = get_option( 'nm_speed_monitor', array() );
	$monitor = is_array( $monitor ) ? $monitor : array();

	return array(
		'now'      => $people_now,
		'use_now'  => round( min( 2, ner_michoel_speed_utilization( 3 ) ) * 100, 1 ),
		'minutes'  => $minutes,
		'capacity' => ner_michoel_speed_capacity(),
		'monitor'  => array(
			'last_run'  => isset( $monitor['last_run'] ) ? (int) $monitor['last_run'] : 0,
			'probed_at' => isset( $monitor['probed_at'] ) ? (int) $monitor['probed_at'] : 0,
			'skipped'   => ! empty( $monitor['skipped'] ),
			'next_run'  => (int) wp_next_scheduled( 'nm_speed_monitor' ),
		),
		'time'     => $now,
	);
}

/**
 * The last day (5-minute steps) or week (hourly): people, how long pages and data
 * requests took, the monitor's test page, and how much of the server was in use,
 * by who used it. Also the five busiest steps.
 */
function ner_michoel_speed_history_data( $range ) {
	global $wpdb;
	$t     = ner_michoel_visitor_tables();
	$week  = 'week' === $range;
	$step  = $week ? 60 : 5;   // minutes per step
	$count = $week ? 168 : 288;
	$last  = (int) floor( time() / 60 / $step );
	$first = $last - $count + 1;
	$s     = ner_michoel_speed_settings();

	$out = array(
		'range'   => $week ? 'week' : 'day',
		'step'    => $step * 60,
		'buckets' => array(),
		'busiest' => array(),
	);
	if ( ! ner_michoel_speed_tables_ready() ) {
		return $out;
	}

	$people = array();
	foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT FLOOR(bucket / %d) AS b, SUM(online) AS s_on, MAX(online) AS m_on, SUM(listening) AS s_li, MAX(listening) AS m_li FROM {$t['live']} WHERE bucket >= %d GROUP BY FLOOR(bucket / %d)", $step, $first * $step, $step ) ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$people[ (int) $r->b ] = $r;
	}

	$work = array();
	foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT FLOOR(bucket / %d) AS b, kind, SUM(hits) AS hits, SUM(wall_ms) AS wall, SUM(cpu_ms) AS cpu FROM {$t['perf']} WHERE bucket >= %d GROUP BY FLOOR(bucket / %d), kind", $step, $first * $step, $step ) ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$work[ (int) $r->b ][ $r->kind ] = $r;
	}

	$visitor_kinds = ner_michoel_speed_visitor_kinds();
	$data_kinds    = array( 'rest', 'rest-site', 'queue', 'next-up', 'play', 'search-index', 'ajax' );
	$now           = time();

	for ( $b = $first; $b <= $last; $b++ ) {
		$span  = $b === $last ? max( 60, $now - $b * $step * 60 ) : $step * 60;
		$rows  = isset( $work[ $b ] ) ? $work[ $b ] : array();
		$group = array(
			'visitors' => array( 0, 0 ),
			'bots'     => array( 0, 0 ),
			'other'    => array( 0, 0 ),
		);
		$avg   = function ( $kinds ) use ( $rows ) {
			$hits = 0;
			$wall = 0;
			foreach ( $kinds as $kind ) {
				if ( isset( $rows[ $kind ] ) ) {
					$hits += $rows[ $kind ]->hits;
					$wall += $rows[ $kind ]->wall;
				}
			}
			return $hits ? round( $wall / $hits ) : null;
		};
		foreach ( $rows as $kind => $r ) {
			if ( 'cache' === $kind || 0 === strpos( $kind, 'probe:' ) || 0 === strpos( $kind, 'probe-fail:' ) ) {
				continue;
			}
			$g = in_array( $kind, $visitor_kinds, true ) ? 'visitors' : ( 'bot' === $kind ? 'bots' : 'other' );
			$group[ $g ][0] += $r->wall;
			$group[ $g ][1] += $r->cpu;
		}
		// Whichever limit is closer, processes or CPU, is the one shown.
		$by_wall = ( $group['visitors'][0] + $group['bots'][0] + $group['other'][0] ) / ( $span * 1000 * $s['processes'] );
		$by_cpu  = ( $group['visitors'][1] + $group['bots'][1] + $group['other'][1] ) / ( $span * 1000 * $s['cores'] );
		$i       = $by_cpu > $by_wall ? 1 : 0;
		$div     = $span * 1000 * ( 1 === $i ? $s['cores'] : $s['processes'] );

		$p_minutes = $step;
		$bucket    = array(
			't'          => $b * $step * 60,
			'online'     => isset( $people[ $b ] ) ? round( $people[ $b ]->s_on / $p_minutes, 1 ) : 0,
			'peak'       => isset( $people[ $b ] ) ? (int) $people[ $b ]->m_on : 0,
			'listening'  => isset( $people[ $b ] ) ? round( $people[ $b ]->s_li / $p_minutes, 1 ) : 0,
			'page_ms'    => $avg( array( 'page' ) ),
			'data_ms'    => $avg( $data_kinds ),
			'probe_ms'   => $avg( array( 'probe:page' ) ),
			'pages'      => isset( $rows['page'] ) ? (int) $rows['page']->hits : 0,
			'use_visits' => round( 100 * $group['visitors'][ $i ] / $div, 2 ),
			'use_bots'   => round( 100 * $group['bots'][ $i ] / $div, 2 ),
			'use_other'  => round( 100 * $group['other'][ $i ] / $div, 2 ),
		);
		$out['buckets'][] = $bucket;
	}

	$ranked = $out['buckets'];
	usort(
		$ranked,
		function ( $a, $b ) {
			$ua = $a['use_visits'] + $a['use_bots'] + $a['use_other'];
			$ub = $b['use_visits'] + $b['use_bots'] + $b['use_other'];
			return $ua === $ub ? $b['peak'] - $a['peak'] : ( $ub > $ua ? 1 : -1 );
		}
	);
	foreach ( array_slice( $ranked, 0, 5 ) as $bucket ) {
		if ( $bucket['use_visits'] + $bucket['use_bots'] + $bucket['use_other'] > 0 || $bucket['peak'] > 0 ) {
			$out['busiest'][] = $bucket;
		}
	}

	return $out;
}

/* ------------------------------------------------------------------ */
/* REST                                                                */
/* ------------------------------------------------------------------ */

function ner_michoel_register_speed_routes() {
	$admin = function () {
		return current_user_can( 'manage_options' );
	};

	register_rest_route(
		'ner-michoel/v1',
		'/speed/live',
		array(
			'methods'             => 'GET',
			'callback'            => function () {
				$response = new WP_REST_Response( ner_michoel_speed_live_data(), 200 );
				$response->header( 'Cache-Control', 'no-store' );
				return $response;
			},
			'permission_callback' => $admin,
		)
	);
	register_rest_route(
		'ner-michoel/v1',
		'/speed/history',
		array(
			'methods'             => 'GET',
			'callback'            => function ( WP_REST_Request $request ) {
				$response = new WP_REST_Response( ner_michoel_speed_history_data( (string) $request->get_param( 'range' ) ), 200 );
				$response->header( 'Cache-Control', 'no-store' );
				return $response;
			},
			'permission_callback' => $admin,
			'args'                => array(
				'range' => array(
					'type'    => 'string',
					'default' => 'day',
					'enum'    => array( 'day', 'week' ),
				),
			),
		)
	);
	register_rest_route(
		'ner-michoel/v1',
		'/speed/check',
		array(
			'methods'             => 'POST',
			'callback'            => function () {
				ner_michoel_speed_monitor_run( true );
				$checks = get_option( 'nm_speed_checks', array() );
				return new WP_REST_Response(
					array(
						'ok'      => true,
						'monitor' => get_option( 'nm_speed_monitor', array() ),
						'checks'  => isset( $checks['items'] ) ? $checks['items'] : array(),
					),
					200
				);
			},
			'permission_callback' => $admin,
		)
	);
	register_rest_route(
		'ner-michoel/v1',
		'/speed/settings',
		array(
			'methods'             => 'POST',
			'callback'            => function ( WP_REST_Request $request ) {
				$processes = (int) $request->get_param( 'processes' );
				$cores     = (float) $request->get_param( 'cores' );
				if ( $processes < 1 || $processes > 500 || $cores < 0.25 || $cores > 64 ) {
					return new WP_Error( 'nm_speed_bad_settings', __( 'Processes must be 1 to 500, and CPU cores 0.25 to 64.', 'ner-michoel-core' ), array( 'status' => 400 ) );
				}
				update_option( 'nm_speed_settings', array( 'processes' => $processes, 'cores' => $cores ), false );
				return new WP_REST_Response(
					array(
						'settings' => ner_michoel_speed_settings(),
						'capacity' => ner_michoel_speed_capacity( true ),
					),
					200
				);
			},
			'permission_callback' => $admin,
			'args'                => array(
				'processes' => array( 'required' => true ),
				'cores'     => array( 'required' => true ),
			),
		)
	);
}
add_action( 'rest_api_init', 'ner_michoel_register_speed_routes' );

/* ------------------------------------------------------------------ */
/* The screen                                                          */
/* ------------------------------------------------------------------ */

/**
 * What assets/site-speed.js needs: where to ask, the site's time zone for the
 * charts' times, and its words.
 */
function ner_michoel_speed_script_config() {
	return array(
		'restUrl' => esc_url_raw( rest_url( 'ner-michoel/v1/speed' ) ),
		'nonce'   => wp_create_nonce( 'wp_rest' ),
		'tz'      => wp_timezone_string(),
		'labels'  => array(
			'online'     => __( 'On the site', 'ner-michoel-core' ),
			'listening'  => __( 'Listening', 'ner-michoel-core' ),
			'page_ms'    => __( 'Pages built for visitors', 'ner-michoel-core' ),
			'data_ms'    => __( 'Site data (queue, next up, plays)', 'ner-michoel-core' ),
			'probe_ms'   => __( 'Test page, every 5 minutes', 'ner-michoel-core' ),
			'use_visits' => __( 'Visitors', 'ner-michoel-core' ),
			'use_bots'   => __( 'Search engines and bots', 'ner-michoel-core' ),
			'use_other'  => __( 'Staff and background jobs', 'ner-michoel-core' ),
		),
		'text'    => array(
			'overloaded'    => __( 'Overloaded', 'ner-michoel-core' ),
			'veryBusy'      => __( 'Very busy: people may wait', 'ner-michoel-core' ),
			'busy'          => __( 'Busy', 'ner-michoel-core' ),
			'calm'          => __( 'Calm', 'ner-michoel-core' ),
			'showTable'     => __( 'Show as a table', 'ner-michoel-core' ),
			'time'          => __( 'Time', 'ner-michoel-core' ),
			'total'         => __( 'in all', 'ner-michoel-core' ),
			'smoothLine'    => __( 'Smooth up to 70%', 'ner-michoel-core' ),
			'signedIn'      => __( 'signed in', 'ner-michoel-core' ),
			'last3'         => __( 'last 3 minutes', 'ner-michoel-core' ),
			'notYet'        => __( 'not yet', 'ner-michoel-core' ),
			'cpu'           => __( 'CPU', 'ner-michoel-core' ),
			'processes'     => __( 'Server processes', 'ner-michoel-core' ),
			/* translators: %1, %2: numbers of people, %3: percent */
			'capNow'        => __( 'Now: %1 of %2 (%3%)', 'ner-michoel-core' ),
			'noPages'       => __( 'Nobody on the site right now (editors and administrators aren’t counted).', 'ner-michoel-core' ),
			'person'        => __( 'person', 'ner-michoel-core' ),
			'people'        => __( 'people', 'ner-michoel-core' ),
			'listening'     => __( 'listening', 'ner-michoel-core' ),
			/* translators: %1: time ago */
			'lastCheck'     => __( 'Last background check %1 ago.', 'ner-michoel-core' ),
			'noCheck'       => __( 'The first background check hasn’t run yet.', 'ner-michoel-core' ),
			'skipped'       => __( 'Its test requests were skipped because the site was busy.', 'ner-michoel-core' ),
			/* translators: %1: time */
			'nextCheck'     => __( 'Next in about %1.', 'ner-michoel-core' ),
			'aMoment'       => __( 'a moment', 'ner-michoel-core' ),
			'liveEmpty'     => __( 'Nobody has been on the site in the last hour. The line fills in as visitors come.', 'ner-michoel-core' ),
			'basisNone'     => __( 'Not enough measurements yet. The first background check runs within 5 minutes of the update; the numbers fill in after it.', 'ner-michoel-core' ),
			/* translators: %1: visitor-minutes, %2, %3, %4: milliseconds */
			'basisMeasured' => __( 'Measured from %1 visitor-minutes in the last 7 days: one person browsing costs the server about %2 ms of work and %3 ms of CPU a minute; one listening, about %4 ms.', 'ner-michoel-core' ),
			/* translators: %1, %2: milliseconds */
			'basisEstimate' => __( 'An estimate for now: fewer than 2 hours of visitor time measured so far, so it uses the time a page takes to build from scratch (%1 ms), the visitor counter (%2 ms) and typical use (a page a minute, as often from the host’s cache as measured). It becomes a measurement as people visit.', 'ner-michoel-core' ),
			/* translators: %1: time */
			'updated'       => __( 'Updated %1. Updates every 10 seconds while this page is open.', 'ner-michoel-core' ),
			'failed'        => __( 'Couldn’t update just now; trying again.', 'ner-michoel-core' ),
			'peopleEmpty'   => __( 'No visitors counted in this time yet.', 'ner-michoel-core' ),
			'speedEmpty'    => __( 'No requests timed in this time yet.', 'ner-michoel-core' ),
			'useEmpty'      => __( 'The server hasn’t been measured doing any work in this time yet.', 'ner-michoel-core' ),
			'when'          => __( 'When', 'ner-michoel-core' ),
			'peopleCol'     => __( 'People (most)', 'ner-michoel-core' ),
			'listeningCol'  => __( 'Listening', 'ner-michoel-core' ),
			'useCol'        => __( 'Server in use', 'ner-michoel-core' ),
			'pageCol'       => __( 'Page build', 'ner-michoel-core' ),
			'busyEmpty'     => __( 'Nothing measured in this time yet.', 'ner-michoel-core' ),
			'checking'      => __( 'Checking… (about 10 seconds)', 'ner-michoel-core' ),
			'checkFailed'   => __( 'The check didn’t finish: ', 'ner-michoel-core' ),
			'saving'        => __( 'Saving…', 'ner-michoel-core' ),
			'saved'         => __( 'Saved. The numbers above now use these limits.', 'ner-michoel-core' ),
		),
	);
}

/**
 * Label, icon and order for each status.
 */
function ner_michoel_speed_statuses() {
	return array(
		'todo'    => array( 'label' => __( 'Needs attention', 'ner-michoel-core' ), 'icon' => 'alert' ),
		'partly'  => array( 'label' => __( 'Partly fixed', 'ner-michoel-core' ), 'icon' => 'wrench' ),
		'handled' => array( 'label' => __( 'Fixed', 'ner-michoel-core' ), 'icon' => 'check-circle' ),
		'watch'   => array( 'label' => __( 'Watched', 'ner-michoel-core' ), 'icon' => 'eye' ),
		'host'    => array( 'label' => __( 'Up to the host', 'ner-michoel-core' ), 'icon' => 'cloud' ),
	);
}

function ner_michoel_render_speed_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	ner_michoel_speed_maybe_create_tables();

	$checks = get_option( 'nm_speed_checks', array() );
	if ( ! is_array( $checks ) || empty( $checks['items'] ) || empty( $checks['at'] ) || $checks['at'] < time() - 10 * MINUTE_IN_SECONDS ) {
		$checks = array(
			'at'    => time(),
			'items' => ner_michoel_speed_checks(),
		);
		update_option( 'nm_speed_checks', $checks, false );
	}

	$statuses = ner_michoel_speed_statuses();
	$items    = $checks['items'];
	$order    = array_flip( array_keys( $statuses ) );
	$impacts  = array( 'high' => 0, 'medium' => 1, 'low' => 2 );
	usort(
		$items,
		function ( $a, $b ) use ( $order, $impacts ) {
			$sa = isset( $order[ $a['status'] ] ) ? $order[ $a['status'] ] : 9;
			$sb = isset( $order[ $b['status'] ] ) ? $order[ $b['status'] ] : 9;
			if ( $sa !== $sb ) {
				return $sa - $sb;
			}
			return ( isset( $impacts[ $a['impact'] ] ) ? $impacts[ $a['impact'] ] : 3 ) - ( isset( $impacts[ $b['impact'] ] ) ? $impacts[ $b['impact'] ] : 3 );
		}
	);
	$tally = array();
	foreach ( $items as $item ) {
		$tally[ $item['status'] ] = isset( $tally[ $item['status'] ] ) ? $tally[ $item['status'] ] + 1 : 1;
	}

	$live      = ner_michoel_speed_live_data();
	$cap       = $live['capacity'];
	$settings  = ner_michoel_speed_settings();
	$monitor   = get_option( 'nm_speed_monitor', array() );
	$probes    = is_array( $monitor ) && isset( $monitor['probes'] ) && is_array( $monitor['probes'] ) ? $monitor['probes'] : array();
	$deferrals = get_option( 'nm_speed_deferrals', array() );
	$impact_labels = array(
		'high'   => __( 'Big effect', 'ner-michoel-core' ),
		'medium' => __( 'Some effect', 'ner-michoel-core' ),
		'low'    => __( 'Small effect', 'ner-michoel-core' ),
	);
	$probe_labels  = array(
		'home'   => __( 'Homepage (from the host’s cache)', 'ner-michoel-core' ),
		'page'   => __( 'A random shiur page, built from scratch', 'ner-michoel-core' ),
		'nextup' => __( 'Next up list for that shiur', 'ner-michoel-core' ),
		'beacon' => __( 'Light visitor counter', 'ner-michoel-core' ),
	);
	?>
	<div class="nms" data-nms-initial="<?php echo esc_attr( wp_json_encode( $live ) ); ?>">

		<section class="nm-card nms-now" aria-labelledby="nms-now-title">
			<div class="nms-now__head">
				<h2 class="nm-card__title" id="nms-now-title"><span class="nms-pulse" aria-hidden="true"></span><?php esc_html_e( 'Right now', 'ner-michoel-core' ); ?></h2>
				<p class="nms-muted" data-nms="updated" aria-live="polite"><?php esc_html_e( 'Updates every 10 seconds while this page is open.', 'ner-michoel-core' ); ?></p>
			</div>
			<div class="nms-tiles">
				<div class="nms-tile">
					<span class="nms-tile__label"><?php esc_html_e( 'On the site', 'ner-michoel-core' ); ?></span>
					<span class="nms-tile__value" data-nms="online"><?php echo esc_html( number_format_i18n( $live['now']['online'] ) ); ?></span>
					<span class="nms-tile__sub" data-nms="signed"><?php echo esc_html( sprintf( /* translators: %s: count */ __( '%s signed in', 'ner-michoel-core' ), number_format_i18n( $live['now']['signed_in'] ) ) ); ?></span>
				</div>
				<div class="nms-tile">
					<span class="nms-tile__label"><?php esc_html_e( 'Listening', 'ner-michoel-core' ); ?></span>
					<span class="nms-tile__value" data-nms="listening"><?php echo esc_html( number_format_i18n( $live['now']['listening'] ) ); ?></span>
					<span class="nms-tile__sub"><?php esc_html_e( 'audio or video playing', 'ner-michoel-core' ); ?></span>
				</div>
				<div class="nms-tile">
					<span class="nms-tile__label"><?php esc_html_e( 'Most at once, last 7 days', 'ner-michoel-core' ); ?></span>
					<span class="nms-tile__value" data-nms="peak"><?php echo esc_html( number_format_i18n( $cap['peak_online'] ) ); ?></span>
					<span class="nms-tile__sub" data-nms="peak-at"><?php echo $cap['peak_at'] ? esc_html( wp_date( 'D j M, g:i a', $cap['peak_at'] ) ) : esc_html__( 'not yet', 'ner-michoel-core' ); ?></span>
				</div>
				<div class="nms-tile nms-tile--meter">
					<span class="nms-tile__label"><?php esc_html_e( 'Server in use', 'ner-michoel-core' ); ?></span>
					<span class="nms-tile__value" data-nms="use"><?php echo esc_html( number_format_i18n( $live['use_now'], 0 ) ); ?>%</span>
					<span class="nms-meter" role="img" data-nms="use-meter" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: percent */ __( 'Server in use: %s%%', 'ner-michoel-core' ), number_format_i18n( $live['use_now'], 0 ) ) ); ?>"><span class="nms-meter__fill" style="width:<?php echo esc_attr( min( 100, $live['use_now'] ) ); ?>%"></span></span>
					<span class="nms-tile__sub" data-nms="use-label"><?php esc_html_e( 'last 3 minutes', 'ner-michoel-core' ); ?></span>
				</div>
			</div>

			<div class="nms-chart" data-nms-chart="live" aria-label="<?php esc_attr_e( 'People on the site and listening, minute by minute, for the last hour', 'ner-michoel-core' ); ?>"></div>

			<div class="nms-now__pages">
				<h3 class="nms-h3"><?php esc_html_e( 'Pages open now', 'ner-michoel-core' ); ?></h3>
				<ol class="nms-pages" data-nms="pages"></ol>
			</div>
		</section>

		<section class="nm-card nms-capacity" aria-labelledby="nms-cap-title">
			<h2 class="nm-card__title" id="nms-cap-title"><?php echo ner_michoel_admin_icon( 'zap' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'How many people at once', 'ner-michoel-core' ); ?></h2>
			<div class="nms-cap">
				<div class="nms-cap__hero">
					<span class="nms-cap__value" data-nms="visit-smooth"><?php echo $cap['visit_smooth'] ? esc_html( number_format_i18n( $cap['visit_smooth'] ) ) : '—'; ?></span>
					<span class="nms-cap__label"><?php esc_html_e( 'people browsing at once, without slowing down', 'ner-michoel-core' ); ?></span>
					<span class="nms-meter nms-meter--wide" role="img" data-nms="cap-meter" aria-label=""><span class="nms-meter__fill"></span></span>
					<span class="nms-muted" data-nms="cap-now"></span>
				</div>
				<dl class="nms-cap__facts">
					<div><dt><?php esc_html_e( 'Only listening', 'ner-michoel-core' ); ?></dt><dd data-nms="listen-smooth"><?php echo $cap['listen_smooth'] ? esc_html( number_format_i18n( $cap['listen_smooth'] ) ) : '—'; ?></dd></div>
					<div><dt><?php esc_html_e( 'Browsing, before pages wait in line', 'ner-michoel-core' ); ?></dt><dd data-nms="visit-max"><?php echo $cap['visit_max'] ? esc_html( number_format_i18n( $cap['visit_max'] ) ) : '—'; ?></dd></div>
					<div><dt><?php esc_html_e( 'Runs out first', 'ner-michoel-core' ); ?></dt><dd data-nms="limit"><?php echo 'cpu' === $cap['limit'] ? esc_html__( 'CPU', 'ner-michoel-core' ) : esc_html__( 'Server processes', 'ner-michoel-core' ); ?></dd></div>
				</dl>
			</div>
			<p class="nms-basis" data-nms="basis"></p>
			<details class="nms-details">
				<summary><?php esc_html_e( 'How this is worked out, and the host’s limits', 'ner-michoel-core' ); ?></summary>
				<p><?php esc_html_e( 'Every request the server handles is timed. Over the last 7 days, the time and CPU the server spent on visitors is divided by the minutes people were on the site: that is what one person costs per minute. The host’s limits divided by that cost give how many people fit at once. “Without slowing down” keeps the server 70% busy at most, so requests don’t wait in line. Pages the host’s cache serves cost nothing and aren’t counted. Audio streams from Bunny, so a listener costs only the small “still here” pings and the occasional next-up list.', 'ner-michoel-core' ); ?></p>
				<p><?php esc_html_e( 'Bluehost allows 25 processes at once on shared hosting. It doesn’t publish a CPU figure per plan, so 1 core is the careful starting point. Check cPanel → Resource Usage (or ask Bluehost) and put in your plan’s real numbers.', 'ner-michoel-core' ); ?></p>
				<form class="nms-settings" data-nms-settings>
					<label><span><?php esc_html_e( 'Processes at once', 'ner-michoel-core' ); ?></span><input type="number" name="processes" min="1" max="500" step="1" value="<?php echo esc_attr( $settings['processes'] ); ?>" /></label>
					<label><span><?php esc_html_e( 'CPU cores', 'ner-michoel-core' ); ?></span><input type="number" name="cores" min="0.25" max="64" step="0.25" value="<?php echo esc_attr( $settings['cores'] ); ?>" /></label>
					<button type="submit" class="nm-btn nm-btn--soft"><?php esc_html_e( 'Save limits', 'ner-michoel-core' ); ?></button>
					<span class="nms-muted" data-nms="settings-msg" role="status"></span>
				</form>
			</details>
		</section>

		<section class="nm-card nms-causes" aria-labelledby="nms-causes-title">
			<div class="nm-card__head">
				<div>
					<h2 class="nm-card__title" id="nms-causes-title"><?php echo ner_michoel_admin_icon( 'alert' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'What slows the site down', 'ner-michoel-core' ); ?></h2>
					<p class="nm-card__sub">
						<?php
						/* translators: %s: time ago */
						echo esc_html( sprintf( __( 'Checked %s ago. The background monitor checks again every 30 minutes.', 'ner-michoel-core' ), human_time_diff( (int) $checks['at'] ) ) );
						?>
					</p>
				</div>
				<button type="button" class="nm-btn" data-nms-check><?php echo ner_michoel_admin_icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Check now', 'ner-michoel-core' ); ?></button>
			</div>
			<ul class="nms-tally">
				<?php foreach ( $statuses as $key => $status ) : ?>
					<?php if ( ! empty( $tally[ $key ] ) ) : ?>
						<li class="nms-status nms-status--<?php echo esc_attr( $key ); ?>"><?php echo ner_michoel_admin_icon( $status['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo esc_html( $status['label'] ); ?> <strong><?php echo esc_html( number_format_i18n( $tally[ $key ] ) ); ?></strong></li>
					<?php endif; ?>
				<?php endforeach; ?>
			</ul>
			<ul class="nms-causes__list">
				<?php foreach ( $items as $item ) : ?>
					<?php $status = isset( $statuses[ $item['status'] ] ) ? $statuses[ $item['status'] ] : $statuses['watch']; ?>
					<li class="nms-cause" data-cause="<?php echo esc_attr( $item['id'] ); ?>">
						<details>
							<summary>
								<span class="nms-status nms-status--<?php echo esc_attr( $item['status'] ); ?>"><?php echo ner_michoel_admin_icon( $status['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo esc_html( $status['label'] ); ?></span>
								<span class="nms-cause__title"><?php echo esc_html( $item['title'] ); ?></span>
								<span class="nms-impact nms-impact--<?php echo esc_attr( $item['impact'] ); ?>"><?php echo esc_html( isset( $impact_labels[ $item['impact'] ] ) ? $impact_labels[ $item['impact'] ] : '' ); ?></span>
								<span class="nms-cause__now"><?php echo esc_html( $item['now'] ); ?></span>
							</summary>
							<dl class="nms-cause__more">
								<dt><?php esc_html_e( 'What it is', 'ner-michoel-core' ); ?></dt>
								<dd><?php echo esc_html( $item['what'] ); ?></dd>
								<dt><?php esc_html_e( 'What is done about it', 'ner-michoel-core' ); ?></dt>
								<dd><?php echo esc_html( $item['done'] ); ?></dd>
							</dl>
						</details>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>

		<div class="nms-range" role="group" aria-label="<?php esc_attr_e( 'Time range for the charts below', 'ner-michoel-core' ); ?>">
			<span class="nms-range__label"><?php esc_html_e( 'Show', 'ner-michoel-core' ); ?></span>
			<button type="button" class="nms-range__btn is-active" data-nms-range="day" aria-pressed="true"><?php esc_html_e( 'Last 24 hours', 'ner-michoel-core' ); ?></button>
			<button type="button" class="nms-range__btn" data-nms-range="week" aria-pressed="false"><?php esc_html_e( 'Last 7 days', 'ner-michoel-core' ); ?></button>
		</div>

		<div class="nms-grid" data-nms-history>
			<section class="nm-card nms-panel">
				<h2 class="nm-card__title"><?php esc_html_e( 'People on the site', 'ner-michoel-core' ); ?></h2>
				<p class="nm-card__sub"><?php esc_html_e( 'Average at each time; the busiest minute is in the table.', 'ner-michoel-core' ); ?></p>
				<div class="nms-chart" data-nms-chart="people"></div>
			</section>
			<section class="nm-card nms-panel">
				<h2 class="nm-card__title"><?php esc_html_e( 'How fast the server answered', 'ner-michoel-core' ); ?></h2>
				<p class="nm-card__sub"><?php esc_html_e( 'Average time, in milliseconds. Pages from the host’s cache are much faster and aren’t included.', 'ner-michoel-core' ); ?></p>
				<div class="nms-chart" data-nms-chart="speed"></div>
			</section>
			<section class="nm-card nms-panel nms-panel--wide">
				<h2 class="nm-card__title"><?php esc_html_e( 'How busy the server was', 'ner-michoel-core' ); ?></h2>
				<p class="nm-card__sub"><?php esc_html_e( 'Share of what the server can do, by who used it. Above the 70% line, people start to wait.', 'ner-michoel-core' ); ?></p>
				<div class="nms-chart" data-nms-chart="use"></div>
			</section>
			<section class="nm-card nms-panel nms-panel--wide">
				<h2 class="nm-card__title"><?php esc_html_e( 'Busiest times', 'ner-michoel-core' ); ?></h2>
				<div class="nms-table-wrap"><table class="nms-table" data-nms="busiest"></table></div>
			</section>
		</div>

		<section class="nm-card nms-monitor" aria-labelledby="nms-monitor-title">
			<h2 class="nm-card__title" id="nms-monitor-title"><?php echo ner_michoel_admin_icon( 'clock' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Background checks', 'ner-michoel-core' ); ?></h2>
			<p class="nm-card__sub" data-nms="monitor-line"></p>
			<div class="nms-table-wrap">
				<table class="nms-table">
					<thead><tr><th scope="col"><?php esc_html_e( 'Test request', 'ner-michoel-core' ); ?></th><th scope="col"><?php esc_html_e( 'Result', 'ner-michoel-core' ); ?></th><th scope="col" class="nms-num"><?php esc_html_e( 'Time', 'ner-michoel-core' ); ?></th></tr></thead>
					<tbody>
						<?php if ( ! $probes ) : ?>
							<tr><td colspan="3"><?php esc_html_e( 'The first background check hasn’t run yet. Press “Check now” above, or wait up to 5 minutes.', 'ner-michoel-core' ); ?></td></tr>
						<?php endif; ?>
						<?php foreach ( $probes as $name => $probe ) : ?>
							<tr>
								<th scope="row"><?php echo esc_html( isset( $probe_labels[ $name ] ) ? $probe_labels[ $name ] : $name ); ?></th>
								<td>
									<?php if ( $probe['ok'] ) : ?>
										<span class="nms-status nms-status--handled"><?php echo ner_michoel_admin_icon( 'check-circle' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'OK', 'ner-michoel-core' ); ?></span>
										<?php if ( '' !== $probe['cache'] ) : ?>
											<span class="nms-muted"><?php echo esc_html( sprintf( /* translators: %s: HIT, MISS or EXPIRED */ __( 'cache: %s', 'ner-michoel-core' ), $probe['cache'] ) ); ?></span>
										<?php endif; ?>
									<?php else : ?>
										<span class="nms-status nms-status--todo"><?php echo ner_michoel_admin_icon( 'alert' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo esc_html( $probe['code'] ? sprintf( /* translators: %d: HTTP status */ __( 'Failed (%d)', 'ner-michoel-core' ), $probe['code'] ) : __( 'Failed', 'ner-michoel-core' ) ); ?></span>
										<?php if ( '' !== $probe['error'] ) : ?>
											<span class="nms-muted"><?php echo esc_html( $probe['error'] ); ?></span>
										<?php endif; ?>
									<?php endif; ?>
								</td>
								<td class="nms-num"><?php echo esc_html( number_format_i18n( $probe['ms'] ) ); ?> ms</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php if ( is_array( $deferrals ) && $deferrals ) : ?>
				<h3 class="nms-h3"><?php esc_html_e( 'Jobs that waited for a quieter moment', 'ner-michoel-core' ); ?></h3>
				<ul class="nms-plain">
					<?php foreach ( $deferrals as $entry ) : ?>
						<li>
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: job name, 2: count, 3: time ago */
									__( '%1$s: put off %2$s times, last %3$s ago', 'ner-michoel-core' ),
									isset( $entry['label'] ) ? $entry['label'] : '',
									number_format_i18n( isset( $entry['total'] ) ? (int) $entry['total'] : 0 ),
									human_time_diff( isset( $entry['last'] ) ? (int) $entry['last'] : time() )
								)
							);
							?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>
	</div>
	<?php
}
