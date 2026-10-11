<?php
/**
 * The light visitor counter: page views and "still here" pings from visitors'
 * browsers (assets/site-stats.js).
 *
 * Every page view used to go to the REST route ner-michoel/v1/pageview, which
 * starts all of WordPress (every plugin, the theme, the REST server) to write one
 * row: about 0.19 s of a PHP process per page view, on a host that allows 25 at
 * once. This file starts only WordPress's database layer (SHORTINIT), so a ping
 * costs a small fraction of that. The REST route stays as the fallback: if this
 * file can't be reached (a host rule, a moved WordPress), the script switches to
 * the route for the rest of the visit.
 *
 * Accepts POST only, a small JSON body (see ner_michoel_handle_visitor_ping() in
 * includes/visitor-common.php), and answers {"ok":true,"next":50}: the seconds
 * until the next ping.
 */

$nm_started   = microtime( true );
$nm_usage     = function_exists( 'getrusage' ) ? getrusage() : null;
$nm_cpu_start = is_array( $nm_usage ) && isset( $nm_usage['ru_utime.tv_sec'] )
	? ( $nm_usage['ru_utime.tv_sec'] + $nm_usage['ru_stime.tv_sec'] ) * 1000 + ( $nm_usage['ru_utime.tv_usec'] + $nm_usage['ru_stime.tv_usec'] ) / 1000
	: null;

$nm_method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : '';
if ( 'POST' !== $nm_method ) {
	header( 'Allow: POST' );
	http_response_code( 405 );
	exit;
}

// WordPress's own folder: the one above wp-content that holds wp-load.php.
$nm_wp_load = '';
$nm_dir     = __DIR__;
for ( $nm_i = 0; $nm_i < 6; $nm_i++ ) {
	$nm_dir = dirname( $nm_dir );
	if ( is_file( $nm_dir . '/wp-load.php' ) ) {
		$nm_wp_load = $nm_dir . '/wp-load.php';
		break;
	}
}
if ( '' === $nm_wp_load ) {
	http_response_code( 404 ); // The script falls back to the REST route.
	exit;
}

define( 'SHORTINIT', true );
require $nm_wp_load;
require __DIR__ . '/includes/visitor-common.php';

header( 'Content-Type: application/json; charset=utf-8' );
header( 'Cache-Control: no-store, max-age=0' );
header( 'X-Robots-Tag: noindex' );

$nm_body = (string) file_get_contents( 'php://input', false, null, 0, 4096 );
$nm_data = json_decode( $nm_body, true );
if ( ! is_array( $nm_data ) ) {
	http_response_code( 400 );
	echo '{"ok":false,"next":0}';
	exit;
}

$nm_ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
$nm_ua   = ner_michoel_clean_user_agent( isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : '' );
$nm_host = isset( $_SERVER['HTTP_HOST'] ) ? preg_replace( '/:\d+$/', '', (string) $_SERVER['HTTP_HOST'] ) : '';

$nm_result = ner_michoel_handle_visitor_ping( $nm_data, $nm_ip, $nm_ua, $nm_host );

if ( ! $nm_result['ok'] ) {
	http_response_code( 503 ); // Couldn't store it: the script uses the REST route from now on.
}
echo '{"ok":' . ( $nm_result['ok'] ? 'true' : 'false' ) . ',"next":' . (int) $nm_result['next'] . '}';

// What this ping cost, for the Speed & Capacity screen. Measured from the start of the request.
$nm_request_start = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : $nm_started;
$nm_cpu_end       = ner_michoel_cpu_ms();
ner_michoel_perf_add(
	'ping-light',
	( microtime( true ) - $nm_request_start ) * 1000,
	null === $nm_cpu_end || null === $nm_cpu_start ? null : ( $nm_started - $nm_request_start ) * 1000 + ( $nm_cpu_end - $nm_cpu_start ),
	isset( $GLOBALS['wpdb']->num_queries ) ? $GLOBALS['wpdb']->num_queries : 0,
	! empty( $nm_data['in'] )
);

