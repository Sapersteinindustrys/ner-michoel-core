<?php
/**
 * The backup route for media files: GET media.php?p=<path of the file in the
 * Bunny zone>. Used by the page script (assets/media-fallback.js) for a visitor
 * whose browser can't reach Bunny's address: this server fetches the file from
 * Bunny and passes it on. See includes/media-relay.php for what it does and the
 * limits it keeps.
 *
 * Like beacon.php it starts only WordPress's database layer (SHORTINIT): a
 * request costs a few milliseconds of PHP besides the transfer itself.
 */

$nm_started = microtime( true );

$nm_method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : '';
if ( 'GET' !== $nm_method && 'HEAD' !== $nm_method ) {
	header( 'Allow: GET, HEAD' );
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
	http_response_code( 404 );
	exit;
}

define( 'SHORTINIT', true );
require $nm_wp_load;
require __DIR__ . '/includes/media-relay.php';

$nm_path   = isset( $_GET['p'] ) && is_string( $_GET['p'] ) ? $_GET['p'] : '';
$nm_result = ner_michoel_relay_serve( $nm_path );

// What this cost, for the Speed & Capacity screen: the time a PHP process was held.
// After the answer is out, so the visitor doesn't wait for it.
if ( function_exists( 'fastcgi_finish_request' ) ) {
	fastcgi_finish_request();
}
if ( 'HEAD' !== $nm_method && is_file( __DIR__ . '/includes/visitor-common.php' ) ) {
	require __DIR__ . '/includes/visitor-common.php';
	if ( function_exists( 'ner_michoel_perf_add' ) && function_exists( 'ner_michoel_visitor_tables' ) ) {
		$nm_request_start = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : $nm_started;
		ner_michoel_perf_add(
			$nm_result['busy'] ? 'relay-busy' : 'relay',
			( microtime( true ) - $nm_request_start ) * 1000,
			null,
			isset( $GLOBALS['wpdb']->num_queries ) ? $GLOBALS['wpdb']->num_queries : 0,
			false
		);
	}
}
