<?php
/**
 * Autoplay queue for a single shiur.
 *
 * A single shiur page used to queue only itself, so when it ended there was
 * nothing to autoplay into. This gives the page a queue that runs on from the
 * shiur: the rest of its series, in series order, or the rest of its speaker's
 * shiurim if it isn't in a series. The player starts on the shiur and carries
 * on through the list.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The shiurim to play from this one onward, starting with this one. Falls
 * back to just this shiur when it has no series or speaker, or isn't in the
 * list it would belong to.
 */
function ner_michoel_autoplay_list_for_shiur( $post ) {
	$post = get_post( $post );
	if ( ! $post ) {
		return array();
	}

	$list = array();

	$series = get_the_terms( $post->ID, 'series' );
	if ( $series && ! is_wp_error( $series ) ) {
		$list = ner_michoel_get_series_shiurim( $series[0]->term_id );
	} else {
		$speaker = get_the_terms( $post->ID, 'speaker' );
		if ( $speaker && ! is_wp_error( $speaker ) ) {
			$list = ner_michoel_get_speaker_shiurim( $speaker[0]->term_id );
		}
	}

	$position = array_search( $post->ID, wp_list_pluck( $list, 'ID' ), true );
	if ( false === $position ) {
		return array( $post );
	}

	return array_slice( $list, $position );
}

/**
 * The rest of a shiur's series, from this shiur on, in series order. A shiur
 * that isn't in a series comes back alone. This is the queue for a card, so
 * picking one shiur from a series plays the rest of it in order before
 * autoplay moves on to related shiurim (next-up.php).
 */
function ner_michoel_series_rest_for_shiur( $post ) {
	$post = get_post( $post );
	if ( ! $post ) {
		return array();
	}

	$series = get_the_terms( $post->ID, 'series' );
	if ( ! $series || is_wp_error( $series ) ) {
		return array( $post );
	}

	$list     = ner_michoel_get_series_shiurim( $series[0]->term_id );
	$position = array_search( $post->ID, wp_list_pluck( $list, 'ID' ), true );
	if ( false === $position ) {
		return array( $post );
	}

	return array_slice( $list, $position );
}

/**
 * Series a listener has been playing, each with the shiur they last played in
 * it, most recent first. Built from the play history (user-library.php), so
 * "continue" means where they left off. One entry per series.
 */
function ner_michoel_continue_series_for_user( $user_id, $limit = 4 ) {
	$out  = array();
	$seen = array();

	foreach ( ner_michoel_get_user_history( $user_id, 200 ) as $row ) {
		if ( 'shiur' !== $row->post_type ) {
			continue;
		}

		$shiur = get_post( (int) $row->post_id );
		if ( ! $shiur || 'publish' !== $shiur->post_status ) {
			continue;
		}

		$series = get_the_terms( $shiur->ID, 'series' );
		if ( ! $series || is_wp_error( $series ) ) {
			continue;
		}

		$term_id = (int) $series[0]->term_id;
		if ( isset( $seen[ $term_id ] ) ) {
			continue;
		}
		$seen[ $term_id ] = true;

		$out[] = array(
			'term'  => $series[0],
			'shiur' => $shiur,
		);

		if ( count( $out ) >= $limit ) {
			break;
		}
	}

	return $out;
}
