<?php
/**
 * Play queues without the page doing the work. A card or Play All button used to
 * carry its whole queue in the HTML, and building that queue cost several database
 * queries per shiur. The archive built about 17,000 of them on one visit, and a
 * shiur page built a speaker's whole list (1,212 shiurim for one speaker) to show six
 * related shiurim.
 *
 * Now a button carries only its first track (data-play-queue), so playback starts
 * inside the tap, and the rest of the queue comes from ner-michoel/v1/queue when it
 * is clicked (data-queue-url). The player swaps the full queue in behind the track
 * that is already playing (assets/js/custom.js).
 *
 * The queue endpoint takes one of:
 *   shiur=ID & mode=autoplay  the shiur's autoplay list (the Play All on a shiur page)
 *   shiur=ID & mode=series    the rest of its series from this shiur (a card)
 *   series=ID                 the whole series, in series order
 *   speaker=ID                the speaker's shiurim, newest first
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads, in a few queries, what building the tracks for these shiurim needs: the
 * posts, their terms and meta, and the attachments for their audio and thumbnail.
 * After this, ner_michoel_build_track_queue() mostly reads from the object cache.
 */
function ner_michoel_prime_shiur_caches( $ids ) {
	$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );
	if ( ! $ids ) {
		return;
	}

	_prime_post_caches( $ids, true, true );

	$attachments = array();
	foreach ( $ids as $id ) {
		foreach ( array( '_shiur_audio_id', '_thumbnail_id' ) as $key ) {
			$value = get_post_meta( $id, $key, true );
			if ( $value ) {
				$attachments[] = (int) $value;
			}
		}
	}
	if ( $attachments ) {
		_prime_post_caches( array_values( array_unique( $attachments ) ), false, true );
	}
}

/**
 * The first published shiur of each term, in one query: term ID => post ID. "First"
 * means the same as the queues use: series order (menu order, then date) for a
 * series, and newest first for a speaker.
 */
function ner_michoel_first_shiur_ids_by_term( $taxonomy, $term_ids, $newest_first ) {
	global $wpdb;

	$term_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $term_ids ) ) ) );
	if ( ! $term_ids ) {
		return array();
	}

	$order = $newest_first ? 'p.post_date DESC, p.ID DESC' : 'p.menu_order ASC, p.post_date ASC, p.ID ASC';
	$in    = implode( ',', $term_ids );

	// GROUP_CONCAT keeps its order and only drops the end when it's too long, so the
	// first ID is always right, even for a term with hundreds of shiurim.
	$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the ORDER BY and the IN list are built from fixed strings and absint() values.
		"SELECT tt.term_id, SUBSTRING_INDEX( GROUP_CONCAT( p.ID ORDER BY {$order} SEPARATOR ',' ), ',', 1 ) AS first_id
		FROM {$wpdb->term_relationships} tr
		INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
		INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
		WHERE tt.taxonomy = %s AND tt.term_id IN ({$in}) AND p.post_type = 'shiur' AND p.post_status = 'publish'
		GROUP BY tt.term_id",
		$taxonomy
	) );

	$first = array();
	foreach ( (array) $rows as $row ) {
		$first[ (int) $row->term_id ] = (int) $row->first_id;
	}
	return $first;
}

/**
 * The first track of each term's queue, for a card's play button: term ID =>
 * array with one track (or an empty array when that shiur has no audio, in which
 * case the button falls back to fetching the full queue).
 */
function ner_michoel_first_track_by_term( $taxonomy, $term_ids, $newest_first ) {
	$first = ner_michoel_first_shiur_ids_by_term( $taxonomy, $term_ids, $newest_first );
	if ( ! $first ) {
		return array();
	}

	ner_michoel_prime_shiur_caches( array_values( $first ) );

	$posts = get_posts(
		array(
			'post_type'      => 'shiur',
			'post__in'       => array_values( $first ),
			'posts_per_page' => count( $first ),
			'orderby'        => 'post__in',
			'no_found_rows'  => true,
		)
	);
	$by_id = array();
	foreach ( $posts as $post ) {
		$by_id[ $post->ID ] = $post;
	}

	$out = array();
	foreach ( $first as $term_id => $post_id ) {
		if ( isset( $by_id[ $post_id ] ) ) {
			$out[ $term_id ] = ner_michoel_build_track_queue( array( $by_id[ $post_id ] ) );
		}
	}
	return $out;
}

/**
 * Published shiurim per term: term ID => count. One query for every term of the
 * taxonomy, so the archive's "N shiurim" lines don't load the shiurim themselves.
 */
function ner_michoel_term_shiur_counts( $taxonomy ) {
	global $wpdb;

	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT tt.term_id, COUNT(*) AS n
		FROM {$wpdb->term_relationships} tr
		INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
		INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
		WHERE tt.taxonomy = %s AND p.post_type = 'shiur' AND p.post_status = 'publish'
		GROUP BY tt.term_id",
		$taxonomy
	) );

	$counts = array();
	foreach ( (array) $rows as $row ) {
		$counts[ (int) $row->term_id ] = (int) $row->n;
	}
	return $counts;
}

/**
 * The newest shiurim of a speaker, leaving out one (the shiur being viewed).
 * Only as many as are shown, not the whole list.
 */
function ner_michoel_related_speaker_shiurim( $post_id, $speaker_term_id, $count = 6 ) {
	return get_posts(
		array(
			'post_type'           => 'shiur',
			'post_status'         => 'publish',
			'posts_per_page'      => (int) $count,
			'post__not_in'        => array( (int) $post_id ),
			'orderby'             => 'date',
			'order'               => 'DESC',
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
			'tax_query'           => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => 'speaker',
					'field'    => 'term_id',
					'terms'    => (int) $speaker_term_id,
				),
			),
		)
	);
}

/**
 * URL of the queue endpoint for these arguments. ?rest_route= form, as for the
 * search index: the host sends no-store on every /wp-json/ URL.
 */
function ner_michoel_queue_url( $args ) {
	return add_query_arg(
		array_merge( array( 'rest_route' => '/ner-michoel/v1/queue' ), $args ),
		home_url( '/' )
	);
}

function ner_michoel_register_queue_route() {
	register_rest_route(
		'ner-michoel/v1',
		'/queue',
		array(
			'methods'             => 'GET',
			'callback'            => 'ner_michoel_queue_rest',
			// Public on purpose: the tracks carry the audio URLs the public site already plays.
			'permission_callback' => '__return_true',
			'args'                => array(
				'shiur'   => array( 'sanitize_callback' => 'absint' ),
				'series'  => array( 'sanitize_callback' => 'absint' ),
				'speaker' => array( 'sanitize_callback' => 'absint' ),
				'mode'    => array( 'sanitize_callback' => 'sanitize_key' ),
			),
		)
	);
}
add_action( 'rest_api_init', 'ner_michoel_register_queue_route' );

function ner_michoel_queue_rest( WP_REST_Request $request ) {
	$shiur   = (int) $request->get_param( 'shiur' );
	$series  = (int) $request->get_param( 'series' );
	$speaker = (int) $request->get_param( 'speaker' );
	$mode    = 'series' === $request->get_param( 'mode' ) ? 'series' : 'autoplay';

	if ( $shiur ) {
		$post = get_post( $shiur );
		if ( ! $post || 'shiur' !== $post->post_type || 'publish' !== $post->post_status ) {
			return new WP_REST_Response( array(), 200 );
		}
		$posts = 'series' === $mode ? ner_michoel_series_rest_for_shiur( $post ) : ner_michoel_autoplay_list_for_shiur( $post );
	} elseif ( $series ) {
		$posts = ner_michoel_get_series_shiurim( $series );
	} elseif ( $speaker ) {
		$posts = ner_michoel_get_speaker_shiurim( $speaker );
	} else {
		return new WP_Error( 'nm_queue_missing', __( 'Give a shiur, series or speaker.', 'ner-michoel-core' ), array( 'status' => 400 ) );
	}

	ner_michoel_prime_shiur_caches( wp_list_pluck( $posts, 'ID' ) );

	$response = new WP_REST_Response( ner_michoel_build_track_queue( $posts ), 200 );
	$response->header( 'Cache-Control', 'public, max-age=300' );
	return $response;
}
