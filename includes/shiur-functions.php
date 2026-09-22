<?php
/**
 * Query helpers for pulling shiurim/speakers/series in the shapes the
 * streaming-style templates need. Kept here (not in the theme) since
 * they're really just reads of the plugin's own data model.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * All shiurim in a series, in Order (page-attributes) then date.
 *
 * @return WP_Post[]
 */
function ner_michoel_get_series_shiurim( $term_id ) {
	return get_posts(
		array(
			'post_type'      => 'shiur',
			'posts_per_page' => -1,
			'orderby'        => array( 'menu_order' => 'ASC', 'date' => 'ASC' ),
			'tax_query'      => array(
				array(
					'taxonomy' => 'series',
					'field'    => 'term_id',
					'terms'    => $term_id,
				),
			),
		)
	);
}

/**
 * All shiurim by a speaker, newest first.
 *
 * @return WP_Post[]
 */
function ner_michoel_get_speaker_shiurim( $term_id ) {
	return get_posts(
		array(
			'post_type'      => 'shiur',
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'tax_query'      => array(
				array(
					'taxonomy' => 'speaker',
					'field'    => 'term_id',
					'terms'    => $term_id,
				),
			),
		)
	);
}

/**
 * Series a speaker has shiurim in.
 *
 * @return WP_Term[]
 */
function ner_michoel_get_speaker_series( $speaker_term_id ) {
	$shiur_ids = get_posts(
		array(
			'post_type'      => 'shiur',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'tax_query'      => array(
				array(
					'taxonomy' => 'speaker',
					'field'    => 'term_id',
					'terms'    => $speaker_term_id,
				),
			),
		)
	);

	if ( empty( $shiur_ids ) ) {
		return array();
	}

	return wp_get_object_terms(
		$shiur_ids,
		'series',
		array( 'orderby' => 'name', 'order' => 'ASC' )
	);
}

/**
 * Shiurim by a speaker that aren't in any series — standalone tracks.
 *
 * @return WP_Post[]
 */
function ner_michoel_get_speaker_standalone_shiurim( $speaker_term_id ) {
	$shiurim = ner_michoel_get_speaker_shiurim( $speaker_term_id );
	return array_values(
		array_filter(
			$shiurim,
			function( $shiur ) {
				return ! has_term( '', 'series', $shiur );
			}
		)
	);
}

/**
 * Build the JS-consumable queue (array of track data) for a list of
 * shiur posts, used to seed the player with Next/Prev context.
 */
function ner_michoel_build_track_queue( $shiurim ) {
	$queue = array();
	foreach ( $shiurim as $shiur ) {
		$audio_url = ner_michoel_get_shiur_audio_url( $shiur->ID );
		if ( ! $audio_url ) {
			continue;
		}
		$speaker_terms = get_the_terms( $shiur->ID, 'speaker' );
		$speaker_name  = ( $speaker_terms && ! is_wp_error( $speaker_terms ) ) ? $speaker_terms[0]->name : '';
		$queue[]       = array(
			'id'       => $shiur->ID,
			'title'    => get_the_title( $shiur ),
			'speaker'  => $speaker_name,
			'src'      => $audio_url,
			'duration' => ner_michoel_get_shiur_duration( $shiur->ID ),
			'cover'    => get_the_post_thumbnail_url( $shiur, 'thumbnail' ),
		);
	}
	return $queue;
}
