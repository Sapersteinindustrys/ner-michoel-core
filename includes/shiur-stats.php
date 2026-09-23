<?php
/**
 * Per-shiur play / completion tracking. The front-end player pings a
 * REST endpoint on playback start, and again once a listener has heard
 * enough of it to count as "finished" — the threshold (e.g. 90%) is a
 * theme-side call, this only records whichever event it's told about.
 *
 * Nothing in ner-michoel-child calls this yet — that's theme-side
 * player JS, tracked separately (see dev-notes.md) so it doesn't
 * collide with whoever's actively in that repo, same split already
 * used for Live Shiur / Zoom.
 *
 * Counts are plain post meta integers. A short per-IP/post/event
 * transient guards against a single client's double-fire (a JS bug
 * re-triggering 'ended', a flaky retry) inflating the count — not
 * abuse-proof, but this is a shul-sized site's stats page, not
 * ad-fraud detection.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ner_michoel_register_shiur_stats_route() {
	register_rest_route(
		'ner-michoel/v1',
		'/shiur-event',
		array(
			'methods'             => 'POST',
			'callback'            => 'ner_michoel_handle_shiur_event',
			'permission_callback' => '__return_true',
			'args'                => array(
				'post_id' => array(
					'required'          => true,
					'validate_callback' => function ( $value ) {
						return is_numeric( $value );
					},
				),
				'event'   => array(
					'required' => true,
					'enum'     => array( 'play', 'complete' ),
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'ner_michoel_register_shiur_stats_route' );

function ner_michoel_handle_shiur_event( WP_REST_Request $request ) {
	$post_id = absint( $request->get_param( 'post_id' ) );
	$event   = $request->get_param( 'event' );

	if ( ! $post_id || 'shiur' !== get_post_type( $post_id ) || 'publish' !== get_post_status( $post_id ) ) {
		return new WP_REST_Response( array( 'recorded' => false ), 200 );
	}

	$meta_key = 'play' === $event ? '_shiur_play_count' : '_shiur_complete_count';

	$ip       = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$rate_key = 'nm_evt_' . md5( $ip . '|' . $post_id . '|' . $event );

	if ( false === get_transient( $rate_key ) ) {
		set_transient( $rate_key, 1, 20 );
		$count = (int) get_post_meta( $post_id, $meta_key, true );
		update_post_meta( $post_id, $meta_key, $count + 1 );
	}

	return new WP_REST_Response( array( 'recorded' => true ), 200 );
}

/**
 * Front-end accessors.
 */
function ner_michoel_get_shiur_play_count( $post_id ) {
	return (int) get_post_meta( $post_id, '_shiur_play_count', true );
}

function ner_michoel_get_shiur_complete_count( $post_id ) {
	return (int) get_post_meta( $post_id, '_shiur_complete_count', true );
}

/**
 * Admin: "Plays" / "Completed" columns on the existing Shiurim list
 * table, Plays sortable so "Most Listened" is just that column sorted
 * descending — no separate report screen needed.
 */
function ner_michoel_shiur_stats_columns( $columns ) {
	$columns['nm_plays']    = __( 'Plays', 'ner-michoel-core' );
	$columns['nm_complete'] = __( 'Completed', 'ner-michoel-core' );
	return $columns;
}
add_filter( 'manage_shiur_posts_columns', 'ner_michoel_shiur_stats_columns' );

function ner_michoel_shiur_stats_column_content( $column, $post_id ) {
	if ( 'nm_plays' === $column ) {
		echo esc_html( number_format_i18n( ner_michoel_get_shiur_play_count( $post_id ) ) );
	} elseif ( 'nm_complete' === $column ) {
		echo esc_html( number_format_i18n( ner_michoel_get_shiur_complete_count( $post_id ) ) );
	}
}
add_action( 'manage_shiur_posts_custom_column', 'ner_michoel_shiur_stats_column_content', 10, 2 );

function ner_michoel_shiur_stats_sortable_columns( $columns ) {
	$columns['nm_plays'] = 'nm_plays';
	return $columns;
}
add_filter( 'manage_edit-shiur_sortable_columns', 'ner_michoel_shiur_stats_sortable_columns' );

function ner_michoel_shiur_stats_orderby( $query ) {
	if ( ! is_admin() || ! $query->is_main_query() ) {
		return;
	}
	if ( 'nm_plays' !== $query->get( 'orderby' ) ) {
		return;
	}
	$query->set( 'meta_key', '_shiur_play_count' ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
	$query->set( 'orderby', 'meta_value_num' );
}
add_action( 'pre_get_posts', 'ner_michoel_shiur_stats_orderby' );
