<?php
/**
 * One shiur, given in more than one format.
 *
 * A shiur that exists as audio, as a video and as a written sheet is kept as
 * separate posts (`shiur` holds audio or video, `written_shiur` the PDF), the
 * way the origin site's library was imported. Nothing says they are the same
 * shiur, so the pages could not offer "listen", "watch" or "read" for one
 * another. This file finds them: the same title and the same speaker, read
 * with case and punctuation ignored ("Vaeira #2 5777" and "Vaeira 2 5777" are
 * one title), and each kind of file counted once.
 *
 * Why not the series or the date: the audio and the video of one shiur often
 * sit in different series (a speaker's audio series and a video series), and
 * a written sheet can be posted a few days after the recording. On the live
 * library (11,638 shiurim) title + speaker finds 419 shiurim that exist in
 * more than one format; matching on the series as well would miss 55 of them.
 * Without a speaker a title alone is not enough (the same words can be two
 * different shiurim), so a shiur with no speaker is matched only inside its
 * series, and one with neither is left alone.
 *
 * The matching runs inside the search index rebuild (shiur-index.php), which
 * already reads every shiur's title, speaker and file, and saves the result
 * in a small option that is not loaded on every request. Pages read it with
 * ner_michoel_shiur_formats(); the search index carries it as the `f` key.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NER_MICHOEL_SHIUR_FORMATS_OPTION', 'nm_shiur_formats' );
// Which version of the matching rule saved the option. A change to the rule
// (this number) schedules one rebuild, so a deploy needs no manual step.
define( 'NER_MICHOEL_SHIUR_FORMATS_RULE', '1' );
define( 'NER_MICHOEL_SHIUR_FORMATS_RULE_OPTION', 'nm_shiur_formats_rule' );

/**
 * The key two shiurim share when they are the same shiur: the title as plain
 * lower-case letters and digits, then the speaker (or, with no speaker, the
 * series). '' when there is nothing safe to match on.
 *
 * $title may still hold entities (&#8217;, &amp;), as it does in the database.
 */
function ner_michoel_shiur_format_key( $title, $speaker_id, $series_id = 0 ) {
	$text = html_entity_decode( (string) $title, ENT_QUOTES, 'UTF-8' );
	$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	$text = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text );
	$text = is_string( $text ) ? trim( $text ) : '';
	if ( '' === $text ) {
		return '';
	}

	$speaker_id = (int) $speaker_id;
	$series_id  = (int) $series_id;
	if ( $speaker_id > 0 ) {
		return $text . '|' . $speaker_id;
	}
	return $series_id > 0 ? $text . '|0|' . $series_id : '';
}

/**
 * Groups the shiurim that are the same shiur.
 *
 * $members: array( array( id, kind, key ), ... ), newest first, one row per
 * shiur that has its file. kind is 'a' (audio), 'v' (video) or 'w' (written).
 * A row with an empty key is not grouped.
 *
 * Returns array( id => array( kind => id ) ) for every shiur whose group has
 * at least two kinds. Each kind appears once, in the order a, v, w, and holds
 * the newest shiur of that kind; every member of a group gets the same list
 * (two audio recordings of one title are two members, but not two formats).
 */
function ner_michoel_shiur_formats_group( $members ) {
	$order  = array( 'a' => 0, 'v' => 1, 'w' => 2 );
	$groups = array();
	foreach ( $members as $member ) {
		list( $id, $kind, $key ) = $member;
		if ( '' === $key || ! isset( $order[ $kind ] ) ) {
			continue;
		}
		if ( ! isset( $groups[ $key ] ) ) {
			$groups[ $key ] = array(
				'ids'   => array(),
				'kinds' => array(),
			);
		}
		$groups[ $key ]['ids'][] = (int) $id;
		if ( ! isset( $groups[ $key ]['kinds'][ $kind ] ) ) {
			$groups[ $key ]['kinds'][ $kind ] = (int) $id;
		}
	}

	$out = array();
	foreach ( $groups as $group ) {
		if ( count( $group['kinds'] ) < 2 ) {
			continue;
		}
		$kinds = $group['kinds'];
		uksort(
			$kinds,
			function ( $a, $b ) use ( $order ) {
				return $order[ $a ] - $order[ $b ];
			}
		);
		foreach ( $group['ids'] as $id ) {
			$out[ $id ] = $kinds;
		}
	}
	return $out;
}

/**
 * Saves the groups the index rebuild found. Not autoloaded: only the pages
 * that show formats read it.
 */
function ner_michoel_shiur_formats_save( $groups ) {
	update_option( NER_MICHOEL_SHIUR_FORMATS_OPTION, $groups, false );
	update_option( NER_MICHOEL_SHIUR_FORMATS_RULE_OPTION, NER_MICHOEL_SHIUR_FORMATS_RULE );
}

/**
 * The formats a shiur or written shiur comes in, itself included:
 * array( 'a' => 12, 'w' => 340 ) (audio shiur 12, written shiur 340), in the
 * order audio, video, written. An empty array when it comes in one format
 * only (or the groups have not been built yet).
 *
 * The answer is as fresh as the last index rebuild (a minute after any
 * change). $verify drops a shiur that is no longer published, for the single
 * pages, where one query per format costs nothing; lists leave it off.
 */
function ner_michoel_shiur_formats( $post_id, $verify = false ) {
	static $all = null;
	if ( null === $all ) {
		$stored = get_option( NER_MICHOEL_SHIUR_FORMATS_OPTION, array() );
		$all    = is_array( $stored ) ? $stored : array();
	}

	$post_id = (int) $post_id;
	if ( empty( $all[ $post_id ] ) ) {
		return array();
	}

	$formats = $all[ $post_id ];
	if ( $verify ) {
		foreach ( $formats as $kind => $id ) {
			if ( $id !== $post_id && 'publish' !== get_post_status( $id ) ) {
				unset( $formats[ $kind ] );
			}
		}
	}
	return count( $formats ) > 1 ? $formats : array();
}

/**
 * Builds the first groups after an update (or a change to the matching rule)
 * without anyone pressing a button: the index rebuild computes them.
 */
function ner_michoel_shiur_formats_ensure() {
	if ( get_option( NER_MICHOEL_SHIUR_FORMATS_RULE_OPTION ) !== NER_MICHOEL_SHIUR_FORMATS_RULE && function_exists( 'ner_michoel_shiur_index_schedule' ) ) {
		ner_michoel_shiur_index_schedule();
	}
}
add_action( 'init', 'ner_michoel_shiur_formats_ensure', 20 );
