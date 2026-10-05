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
