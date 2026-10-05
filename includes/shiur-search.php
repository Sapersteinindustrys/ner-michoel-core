<?php
/**
 * Shiurim search: titles first, then speakers and series.
 *
 * WordPress's own search matches a phrase anywhere in the title or the
 * content, so a shiur with two of the words in its title can drop out
 * entirely. This replaces that for the Shiurim search only. Results come
 * back in this order, each group under its own heading:
 *
 *   1. Titles that contain the whole search, as one phrase.
 *   2. Speakers whose name contains the whole search (or every word of it).
 *   3. Series whose name contains the whole search (or every word of it).
 *   4. Titles that contain some of the search's words, most first.
 *      With "a b c", a title with a and b ranks above one with only a.
 *
 * Speakers and series match strictly, so one shared word can't bring in a
 * whole speaker. Within a group, newer shiurim come first. A shiur appears
 * once, under the first group that matches it. Matching never looks at the
 * content, so the scan stays fast even at 16,000+ shiurim.
 *
 * The results are handed to WordPress as an ordered post__in, so the
 * main query, its template and the card markup all work as they do for
 * any other query.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lowercases, turns punctuation into spaces, and squeezes whitespace, so
 * "Pirkei-Avot," and "pirkei avot" compare equal. Works on Hebrew and other
 * non-Latin text.
 */
function ner_michoel_search_fold( $text ) {
	$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $text, 'UTF-8' ) : strtolower( (string) $text );
	$text = preg_replace( '/\p{P}+/u', ' ', $text );
	$text = preg_replace( '/\s+/u', ' ', $text );
	return trim( $text );
}

function ner_michoel_search_strlen( $text ) {
	return function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
}

/**
 * Ranks shiurim against a search term. Returns:
 *   'ids'    => shiurim in display order (strongest match first)
 *   'labels' => shiur ID => heading for its tier, e.g. "Title contains 2 of 3 words"
 *
 * Results are cached per request, so the query hook and the template can
 * both call this without scanning twice.
 */
function ner_michoel_shiur_search_ranking( $raw_term ) {
	static $cache = array();

	$raw_term = (string) $raw_term;
	if ( isset( $cache[ $raw_term ] ) ) {
		return $cache[ $raw_term ];
	}

	$empty = array(
		'ids'    => array(),
		'labels' => array(),
	);

	$phrase = ner_michoel_search_fold( $raw_term );
	if ( '' === $phrase ) {
		return $cache[ $raw_term ] = $empty;
	}

	// Words of one character ("a", "I") match almost every title, so they're
	// dropped, unless that would leave nothing to search for.
	$all_words = array_values( array_filter( explode( ' ', $phrase ), 'strlen' ) );
	$long      = array_values(
		array_filter(
			$all_words,
			function ( $word ) {
				return ner_michoel_search_strlen( $word ) >= 2;
			}
		)
	);
	$words = array_values( array_unique( $long ? $long : $all_words ) );
	$total = count( $words );

	// Candidates: any title with at least one of the words. Only the
	// candidates get scored in PHP.
	global $wpdb;
	$clauses = array();
	foreach ( $words as $word ) {
		$clauses[] = $wpdb->prepare( 'post_title LIKE %s', '%' . $wpdb->esc_like( $word ) . '%' );
	}
	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		"SELECT ID, post_title, post_date FROM {$wpdb->posts} WHERE post_type = 'shiur' AND post_status = 'publish' AND (" . implode( ' OR ', $clauses ) . ')'
	);

	// Whole phrase outranks any count of single words, so it gets the top
	// rank value. Word counts run 1..$total.
	$phrase_rank = $total + 1;
	$scored      = array();

	foreach ( (array) $rows as $row ) {
		$title = ner_michoel_search_fold( $row->post_title );

		if ( false !== strpos( $title, $phrase ) ) {
			$scored[] = array(
				'id'   => (int) $row->ID,
				'rank' => $phrase_rank,
				'date' => $row->post_date,
			);
			continue;
		}

		$hits = 0;
		foreach ( $words as $word ) {
			if ( false !== strpos( $title, $word ) ) {
				++$hits;
			}
		}

		if ( $hits > 0 ) {
			$scored[] = array(
				'id'   => (int) $row->ID,
				'rank' => $hits,
				'date' => $row->post_date,
			);
		}
	}

	usort(
		$scored,
		function ( $a, $b ) {
			if ( $a['rank'] !== $b['rank'] ) {
				return $b['rank'] - $a['rank'];
			}
			return strcmp( $b['date'], $a['date'] );
		}
	);

	// Labels use the search as typed (not folded), trimmed of extra spaces.
	$display = trim( preg_replace( '/\s+/', ' ', $raw_term ) );
	$labels  = array();

	foreach ( $scored as $item ) {
		if ( $phrase_rank === $item['rank'] ) {
			$label = sprintf(
				/* translators: %s: the search as typed */
				__( 'Title contains “%s”', 'ner-michoel-core' ),
				$display
			);
		} elseif ( 1 === $total ) {
			// One real word left (the search may have had a one-letter word dropped).
			$label = sprintf(
				/* translators: %s: the word searched for */
				__( 'Title contains “%s”', 'ner-michoel-core' ),
				$words[0]
			);
		} elseif ( $item['rank'] === $total ) {
			$label = sprintf(
				/* translators: %d: number of words in the search */
				__( 'Title contains all %d words', 'ner-michoel-core' ),
				$total
			);
		} else {
			$label = sprintf(
				/* translators: 1: words found in the title, 2: words in the search */
				__( 'Title contains %1$d of %2$d words', 'ner-michoel-core' ),
				$item['rank'],
				$total
			);
		}
		$labels[ $item['id'] ] = $label;
	}

	// Order: whole-phrase title matches, then speakers, then series, then titles
	// that match only some of the words. A shiur shows once, under the first
	// group that matches it.
	$phrase_ids = array();
	$partial    = array();
	foreach ( $scored as $item ) {
		if ( $phrase_rank === $item['rank'] ) {
			$phrase_ids[] = $item['id'];
		} else {
			$partial[] = $item;
		}
	}
	$ids  = $phrase_ids;
	$seen = array_flip( $ids );

	// Speakers, then series ("topic"). The heading names the speaker or
	// series, so the reason it's in the results is visible.
	foreach ( array( 'speaker', 'series' ) as $taxonomy ) {
		foreach ( ner_michoel_search_matching_terms( $taxonomy, $phrase, $words, $total ) as $term ) {
			$label = 'speaker' === $taxonomy
				? sprintf( /* translators: %s: speaker name */ __( 'Speaker: %s', 'ner-michoel-core' ), $term->name )
				: sprintf( /* translators: %s: series name */ __( 'Series: %s', 'ner-michoel-core' ), $term->name );

			foreach ( ner_michoel_search_term_shiur_ids( $term->term_id, $taxonomy ) as $shiur_id ) {
				if ( isset( $seen[ $shiur_id ] ) ) {
					continue;
				}
				$seen[ $shiur_id ]     = true;
				$ids[]                 = $shiur_id;
				$labels[ $shiur_id ]   = $label;
			}
		}
	}

	// Titles with only some of the words, most words first (already sorted).
	foreach ( $partial as $item ) {
		if ( isset( $seen[ $item['id'] ] ) ) {
			continue;
		}
		$seen[ $item['id'] ] = true;
		$ids[]               = $item['id'];
	}

	$result = array(
		'ids'    => $ids,
		'labels' => $labels,
	);

	return $cache[ $raw_term ] = $result;
}

/**
 * Speakers or series whose name matches the search. Strict: the whole
 * search has to appear in the name, or every word of it. Best matches
 * first, then by name.
 */
function ner_michoel_search_matching_terms( $taxonomy, $phrase, $words, $total ) {
	$terms = get_terms(
		array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => true,
		)
	);
	if ( is_wp_error( $terms ) || ! $terms ) {
		return array();
	}

	$phrase_rank = $total + 1;
	$matched     = array();

	foreach ( $terms as $term ) {
		$name = ner_michoel_search_fold( $term->name );

		if ( false !== strpos( $name, $phrase ) ) {
			$matched[] = array(
				'term' => $term,
				'rank' => $phrase_rank,
			);
			continue;
		}

		$hits = 0;
		foreach ( $words as $word ) {
			if ( false !== strpos( $name, $word ) ) {
				++$hits;
			}
		}

		if ( $total > 0 && $hits === $total ) {
			$matched[] = array(
				'term' => $term,
				'rank' => $total,
			);
		}
	}

	usort(
		$matched,
		function ( $a, $b ) {
			if ( $a['rank'] !== $b['rank'] ) {
				return $b['rank'] - $a['rank'];
			}
			return strcmp( $a['term']->name, $b['term']->name );
		}
	);

	return array_column( $matched, 'term' );
}

/**
 * IDs of the published shiurim in a speaker or series, newest first.
 */
function ner_michoel_search_term_shiur_ids( $term_id, $taxonomy ) {
	return get_posts(
		array(
			'post_type'      => 'shiur',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
			'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => $taxonomy,
					'field'    => 'term_id',
					'terms'    => (int) $term_id,
				),
			),
		)
	);
}

/**
 * Whether the current request is a Shiurim search, i.e. the main query
 * with a search term and post_type=shiur (the sidebar's search form sends
 * exactly that).
 */
function ner_michoel_is_shiur_search_query( $query ) {
	return ! is_admin() && $query->is_main_query() && $query->is_search() && 'shiur' === $query->get( 'post_type' );
}

/**
 * Feeds the ranked IDs into the main query. Every result is one shiur,
 * and all of them fit on one page, so there's no pagination to manage.
 */
function ner_michoel_shiur_search_pre_get_posts( $query ) {
	if ( ! ner_michoel_is_shiur_search_query( $query ) ) {
		return;
	}

	$ranking = ner_michoel_shiur_search_ranking( $query->get( 's' ) );
	$ids     = $ranking['ids'] ? $ranking['ids'] : array( 0 ); // array(0): no shiur matches an ID 0.

	$query->set( 'post__in', $ids );
	$query->set( 'orderby', 'post__in' );
	$query->set( 'posts_per_page', 100 );
	$query->set( 'ignore_sticky_posts', true );
}
add_action( 'pre_get_posts', 'ner_michoel_shiur_search_pre_get_posts' );

/**
 * Drops WordPress's own content-matching SQL for this search. Without this,
 * a title match that isn't also in the content would be filtered out again.
 * The query keeps its search term, so is_search() still holds for the
 * template.
 */
function ner_michoel_shiur_search_no_sql( $search, $query ) {
	return ner_michoel_is_shiur_search_query( $query ) ? '' : $search;
}
add_filter( 'posts_search', 'ner_michoel_shiur_search_no_sql', 10, 2 );

/**
 * Heading for each shiur in the results, keyed by shiur ID. The template
 * uses this to group results under their tier.
 */
function ner_michoel_shiur_search_labels( $raw_term ) {
	$ranking = ner_michoel_shiur_search_ranking( $raw_term );
	return $ranking['labels'];
}
