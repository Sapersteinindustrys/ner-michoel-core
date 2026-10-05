<?php
/**
 * "Next up": which shiur should play after the queue runs out.
 *
 * When the queue ends and autoplay is on, the player asks for the shiurim most
 * related to the one that just finished. The ranking is deterministic, so the
 * same shiur always gets the same next list. Nothing is picked at random, and
 * an unrelated shiur never fills the slot.
 *
 * A shiur is related to the current one by, in order of weight:
 *   1. Series. Being in the same series is the strongest tie. Within it, the
 *      shiur just after the current one ranks highest, then the ones after it
 *      in series order, then the ones before it.
 *   2. Speaker. The same speaker, if not already covered by a series.
 *   3. Topic. Each significant word the two titles share adds weight. Common
 *      filler words (the, shiur, part, and so on) are ignored.
 *
 * Signed in (ner-michoel-core's user-library.php has the visitor's History
 * and Saved list), a fourth factor nudges the order: a bonus for a
 * candidate's speaker or series being one this visitor already engages
 * with, capped low enough to reorder among related shiurim without ever
 * promoting one with none of the three ties above — personal preference
 * narrows down "what's related", it doesn't replace it. History also
 * excludes anything the visitor has already listened to, so a related
 * shiur they've already heard doesn't take a slot from one they haven't.
 * Logged out, or signed in with no History/Saved yet, ranks exactly as
 * documented above — nothing here changes for that visitor.
 *
 * Ties go to the newer shiur, then the higher ID. Shiurim with no link to the
 * current one aren't returned at all. Video shiurim are left out too, since
 * the player only plays audio. A shiur that's already been played, or is
 * already queued, is excluded.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Words too common in shiur titles to show a real connection on their own.
 * Kept short on purpose: anything not listed here counts as a topic word.
 */
function ner_michoel_next_up_stopwords() {
	return array(
		'the', 'and', 'for', 'with', 'from', 'that', 'this', 'what', 'when', 'who',
		'shiur', 'shiurim', 'part', 'lesson', 'talk', 'session', 'series', 'episode',
		'intro', 'introduction', 'about', 'into', 'over', 'under', 'chapter',
		'של', 'את', 'על', 'עם', 'או', 'כי', 'זה', 'זו', 'הוא', 'היא', 'אל', 'מן',
	);
}

/**
 * The topic words in a title: folded, at least three characters, and not in
 * the stopword list. Returned unique, in the order they appear.
 */
function ner_michoel_next_up_topic_words( $title ) {
	$stop  = ner_michoel_next_up_stopwords();
	$words = array();

	foreach ( explode( ' ', ner_michoel_search_fold( $title ) ) as $word ) {
		if ( '' === $word || ner_michoel_search_strlen( $word ) < 3 || in_array( $word, $stop, true ) ) {
			continue;
		}
		$words[] = $word;
	}

	return array_values( array_unique( $words ) );
}

/**
 * Whether the player can play this shiur: audio, and not a video.
 */
function ner_michoel_next_up_is_playable( $shiur_id ) {
	return ! ner_michoel_shiur_is_video( $shiur_id ) && '' !== ner_michoel_get_shiur_audio_url( $shiur_id );
}

/**
 * The next shiurim after $current_id, most related first. Returns IDs, so the
 * caller decides how many to use. $exclude holds IDs to leave out (anything
 * already played or queued). $limit is capped at 10.
 */
function ner_michoel_next_up_ids( $current_id, $exclude = array(), $limit = 5 ) {
	$current = get_post( (int) $current_id );
	if ( ! $current || 'shiur' !== $current->post_type ) {
		return array();
	}

	$limit = max( 1, min( 10, (int) $limit ) );

	// function_exists(): both files are required unconditionally in
	// ner-michoel-core.php, so by the time anything actually calls this
	// function user-library.php has already loaded regardless of
	// which require_once came first — this guard isn't about load
	// order, it's cheap insurance against that changing later (e.g.
	// user-library.php's require being wrapped in a feature flag) and
	// turning into a fatal instead of just skipping the personalization.
	$affinity = function_exists( 'ner_michoel_user_affinity_weights' )
		? ner_michoel_user_affinity_weights( get_current_user_id() )
		: array( 'speaker' => array(), 'series' => array(), 'heard_ids' => array() );

	$skip = array_flip( array_map( 'intval', array_merge( array( $current->ID ), (array) $exclude, $affinity['heard_ids'] ) ) );

	$series_terms  = get_the_terms( $current->ID, 'series' );
	$speaker_terms = get_the_terms( $current->ID, 'speaker' );
	$series_id     = ( $series_terms && ! is_wp_error( $series_terms ) ) ? (int) $series_terms[0]->term_id : 0;
	$speaker_id    = ( $speaker_terms && ! is_wp_error( $speaker_terms ) ) ? (int) $speaker_terms[0]->term_id : 0;
	$topic_words   = ner_michoel_next_up_topic_words( $current->post_title );

	// Candidates come from three places. Series and speaker lists are capped
	// (a speaker can have thousands of shiurim), and the topic lookup is capped too.
	$candidates      = array();
	$series_position = array(); // shiur ID => position in the current series.

	if ( $series_id ) {
		$series_list = ner_michoel_get_series_shiurim( $series_id );
		foreach ( $series_list as $position => $series_shiur ) {
			$series_position[ $series_shiur->ID ] = $position;
			$candidates[ $series_shiur->ID ]      = true;
		}
	}

	if ( $speaker_id ) {
		$speaker_ids = get_posts(
			array(
				'post_type'      => 'shiur',
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array(
						'taxonomy' => 'speaker',
						'field'    => 'term_id',
						'terms'    => $speaker_id,
					),
				),
			)
		);
		foreach ( $speaker_ids as $speaker_shiur_id ) {
			$candidates[ $speaker_shiur_id ] = true;
		}
	}

	if ( $topic_words ) {
		global $wpdb;
		$clauses = array();
		foreach ( $topic_words as $word ) {
			$clauses[] = $wpdb->prepare( 'post_title LIKE %s', '%' . $wpdb->esc_like( $word ) . '%' );
		}
		$topic_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'shiur' AND post_status = 'publish' AND (" . implode( ' OR ', $clauses ) . ') ORDER BY post_date DESC LIMIT 200'
		);
		foreach ( $topic_ids as $topic_shiur_id ) {
			$candidates[ (int) $topic_shiur_id ] = true;
		}
	}

	$candidates = array_diff_key( $candidates, $skip );
	if ( ! $candidates ) {
		return array();
	}

	$posts = get_posts(
		array(
			'post_type'      => 'shiur',
			'post_status'    => 'publish',
			'post__in'       => array_keys( $candidates ),
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		)
	);

	$current_position = isset( $series_position[ $current->ID ] ) ? $series_position[ $current->ID ] : null;
	$scored           = array();

	foreach ( $posts as $post ) {
		if ( ! ner_michoel_next_up_is_playable( $post->ID ) ) {
			continue;
		}

		$score = 0;

		// 1. Series: strongest, and closer in the series order is better.
		if ( isset( $series_position[ $post->ID ] ) ) {
			$score += 100;
			if ( null !== $current_position ) {
				$diff = $series_position[ $post->ID ] - $current_position;
				if ( $diff > 0 ) {
					$score += max( 0, 40 - $diff ) + ( 1 === $diff ? 20 : 0 );
				} else {
					$score += max( 0, 10 - abs( $diff ) );
				}
			}
		}

		// 2. Speaker.
		$post_speakers = get_the_terms( $post->ID, 'speaker' );
		if ( $speaker_id && $post_speakers && ! is_wp_error( $post_speakers ) && in_array( $speaker_id, wp_list_pluck( $post_speakers, 'term_id' ), true ) ) {
			$score += 50;
		}

		// 3. Topic: shared title words.
		$shared = array_intersect( $topic_words, ner_michoel_next_up_topic_words( $post->post_title ) );
		$score += 15 * count( $shared );

		if ( $score <= 0 ) {
			continue; // Not related at all, so it's never offered.
		}

		// 4. Signed-in preference, added only once a candidate already
		// qualifies above — this reorders among related shiurim, it
		// never pulls in one with no tie to the current shiur. Capped
		// per tag so one heavily-weighted speaker/series can't swamp
		// the relatedness signals above it (series 100, speaker 50,
		// each shared topic word 15).
		if ( $affinity['speaker'] && $post_speakers && ! is_wp_error( $post_speakers ) ) {
			foreach ( $post_speakers as $post_speaker_term ) {
				if ( isset( $affinity['speaker'][ $post_speaker_term->term_id ] ) ) {
					$score += min( 20, 4 * $affinity['speaker'][ $post_speaker_term->term_id ] );
				}
			}
		}
		if ( $affinity['series'] ) {
			$post_series = get_the_terms( $post->ID, 'series' );
			if ( $post_series && ! is_wp_error( $post_series ) ) {
				foreach ( $post_series as $post_series_term ) {
					if ( isset( $affinity['series'][ $post_series_term->term_id ] ) ) {
						$score += min( 20, 4 * $affinity['series'][ $post_series_term->term_id ] );
					}
				}
			}
		}

		$scored[] = array(
			'id'    => (int) $post->ID,
			'score' => $score,
			'date'  => $post->post_date,
		);
	}

	usort(
		$scored,
		function ( $a, $b ) {
			if ( $a['score'] !== $b['score'] ) {
				return $b['score'] - $a['score'];
			}
			$by_date = strcmp( $b['date'], $a['date'] );
			if ( 0 !== $by_date ) {
				return $by_date;
			}
			return $b['id'] - $a['id'];
		}
	);

	return array_slice( array_column( $scored, 'id' ), 0, $limit );
}

/**
 * REST: GET /ner-michoel/v1/next-up?current=ID&exclude=1,2,3&limit=5
 * Public and read-only. It returns the same track objects as the queue, so the
 * player can append them directly. Only published shiurim are read.
 */
function ner_michoel_register_next_up_route() {
	register_rest_route(
		'ner-michoel/v1',
		'/next-up',
		array(
			'methods'             => 'GET',
			'callback'            => 'ner_michoel_handle_next_up_rest',
			'permission_callback' => '__return_true',
			'args'                => array(
				'current' => array(
					'required'          => true,
					'sanitize_callback' => 'absint',
				),
				'exclude' => array(
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'limit'   => array(
					'default'           => 5,
					'sanitize_callback' => 'absint',
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'ner_michoel_register_next_up_route' );

function ner_michoel_handle_next_up_rest( WP_REST_Request $request ) {
	$exclude = array_filter( array_map( 'absint', explode( ',', (string) $request->get_param( 'exclude' ) ) ) );
	$exclude = array_slice( $exclude, 0, 200 );

	$ids = ner_michoel_next_up_ids( $request->get_param( 'current' ), $exclude, $request->get_param( 'limit' ) ?: 5 );

	$posts = array();
	foreach ( $ids as $id ) {
		$post = get_post( $id );
		if ( $post ) {
			$posts[] = $post;
		}
	}

	return new WP_REST_Response( ner_michoel_build_track_queue( $posts ), 200 );
}
