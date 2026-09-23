<?php
/**
 * Missing-audio report — not a separate screen, just a filtered view
 * added to the existing Shiurim list table (the same "All | Published |
 * Drafts" row WordPress already shows), for shiur posts with no
 * audio/video file attached, so gaps are visible without scrolling the
 * whole library.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Matches a shiur with no `_shiur_audio_id` meta at all, or with it
 * present but empty (e.g. a meta box save that got cleared).
 */
function ner_michoel_missing_audio_meta_query() {
	return array(
		'relation' => 'OR',
		array(
			'key'     => '_shiur_audio_id',
			'compare' => 'NOT EXISTS',
		),
		array(
			'key'     => '_shiur_audio_id',
			'value'   => '',
			'compare' => '=',
		),
	);
}

function ner_michoel_missing_audio_count() {
	$query = new WP_Query(
		array(
			'post_type'      => 'shiur',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => ner_michoel_missing_audio_meta_query(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		)
	);
	return (int) $query->found_posts;
}

function ner_michoel_add_missing_audio_view( $views ) {
	$count = ner_michoel_missing_audio_count();
	$url   = add_query_arg(
		array(
			'post_type'        => 'shiur',
			'nm_missing_audio' => '1',
		),
		admin_url( 'edit.php' )
	);
	$is_current = isset( $_GET['nm_missing_audio'] ) && '1' === $_GET['nm_missing_audio']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	$views['nm_missing_audio'] = sprintf(
		'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
		esc_url( $url ),
		$is_current ? ' class="current"' : '',
		esc_html__( 'Missing Audio', 'ner-michoel-core' ),
		$count
	);

	return $views;
}
add_filter( 'views_edit-shiur', 'ner_michoel_add_missing_audio_view' );

function ner_michoel_filter_missing_audio( $query ) {
	if ( ! is_admin() || ! $query->is_main_query() ) {
		return;
	}
	if ( 'shiur' !== $query->get( 'post_type' ) ) {
		return;
	}
	if ( ! isset( $_GET['nm_missing_audio'] ) || '1' !== $_GET['nm_missing_audio'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}
	$query->set( 'meta_query', ner_michoel_missing_audio_meta_query() ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
}
add_action( 'pre_get_posts', 'ner_michoel_filter_missing_audio' );
