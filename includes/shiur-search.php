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

/* ------------------------------------------------------------------
 * Close spellings. These follow assets/js/shiur-search-engine.js in the theme
 * (the live search), rule for rule: same words, same allowance (budget), same
 * distance. A search with a letter or two off, or two letters swapped, finds
 * its shiurim after the ones that match as typed.
 * ------------------------------------------------------------------ */

/**
 * The words of a text, for comparing spellings: lowercase, apostrophes and
 * quotes dropped ("v'eim" is "veim"), and anything that isn't a letter, a mark
 * or a digit between words. A stored title can hold HTML entities (&#8217;), so
 * they are decoded first.
 */
function ner_michoel_search_tokens( $text ) {
	$text = html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' );
	$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	$text = str_replace( array( "'", '"', "\u{2018}", "\u{2019}", "\u{02BC}", '`', "\u{201C}", "\u{201D}" ), '', $text );
	$list = preg_split( '/[^\p{L}\p{M}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
	return $list ? $list : array();
}

/**
 * How many letters of this word may be off: none for a word under three
 * letters or with a digit in it (a year one digit out is another year), one up
 * to five letters, two from six.
 */
function ner_michoel_search_budget( $word ) {
	$length = ner_michoel_search_strlen( $word );
	if ( $length < 3 || preg_match( '/\d/', $word ) ) {
		return 0;
	}
	return $length < 6 ? 1 : 2;
}

/**
 * Whether a close match must start with the same letter: short words turn into
 * other words ("rav", "av"), and a slip on their first letter is rare.
 */
function ner_michoel_search_anchored( $word ) {
	return ner_michoel_search_strlen( $word ) < 5;
}

/**
 * The number of single-letter changes (a letter added, left out or different,
 * or two neighbouring letters swapped) between two words, or $max + 1 when it
 * is more than $max. A swap is one change, so "shabbso" is one from "shabbos".
 */
function ner_michoel_search_distance( $a, $b, $max ) {
	if ( $a === $b ) {
		return 0;
	}
	if ( abs( ner_michoel_search_strlen( $a ) - ner_michoel_search_strlen( $b ) ) > $max ) {
		return $max + 1;
	}

	$ca = preg_split( '//u', $a, -1, PREG_SPLIT_NO_EMPTY );
	$cb = preg_split( '//u', $b, -1, PREG_SPLIT_NO_EMPTY );
	$ca = $ca ? $ca : array();
	$cb = $cb ? $cb : array();
	$m  = count( $ca );
	$n  = count( $cb );

	$older = array();
	$prev  = range( 0, $n );
	for ( $i = 1; $i <= $m; $i++ ) {
		$cur     = array( $i );
		$row_min = $i;
		for ( $j = 1; $j <= $n; $j++ ) {
			$v = $prev[ $j - 1 ] + ( $ca[ $i - 1 ] === $cb[ $j - 1 ] ? 0 : 1 );
			$t = $prev[ $j ] + 1;
			if ( $t < $v ) {
				$v = $t;
			}
			$t = $cur[ $j - 1 ] + 1;
			if ( $t < $v ) {
				$v = $t;
			}
			if ( $i > 1 && $j > 1 && $ca[ $i - 1 ] === $cb[ $j - 2 ] && $ca[ $i - 2 ] === $cb[ $j - 1 ] ) {
				$t = $older[ $j - 2 ] + 1;
				if ( $t < $v ) {
					$v = $t;
				}
			}
			$cur[ $j ] = $v;
			if ( $v < $row_min ) {
				$row_min = $v;
			}
		}
		if ( $row_min > $max ) {
			return $max + 1;
		}
		$older = $prev;
		$prev  = $cur;
	}

	return $prev[ $n ] > $max ? $max + 1 : $prev[ $n ];
}

/**
 * The words of a search that close spellings are looked for with, each as
 * array( 'word' => …, 'max' => letters that may be off ). A list, not a map
 * keyed by word: a word such as "5784" would turn into an integer key. Words
 * of one letter are left out unless nothing else is left, as in the ranking.
 * Empty when no word may be off, so there is nothing to look for.
 */
function ner_michoel_search_close_words( $raw_term ) {
	$all  = array_values( array_unique( ner_michoel_search_tokens( $raw_term ) ) );
	$long = array();
	foreach ( $all as $token ) {
		if ( ner_michoel_search_strlen( $token ) >= 2 ) {
			$long[] = $token;
		}
	}

	$words = array();
	$reach = 0;
	foreach ( $long ? $long : $all as $token ) {
		$max     = ner_michoel_search_budget( $token );
		$reach   = max( $reach, $max );
		$words[] = array(
			'word' => (string) $token,
			'max'  => $max,
		);
	}
	return $reach ? $words : array();
}

/**
 * Ranks shiurim against a search term. Returns:
 *   'ids'    => shiurim in display order (strongest match first)
 *   'labels' => shiur ID => heading for its tier, e.g. "Title contains 2 of 3 words"
 *
 * Results are cached per request, so the query hook and the template can
 * both call this without scanning twice.
 */
function ner_michoel_shiur_search_ranking( $raw_term, $post_type = 'shiur' ) {
	static $cache = array();

	$raw_term = (string) $raw_term;
	if ( isset( $cache[ $post_type . '|' . $raw_term ] ) ) {
		return $cache[ $post_type . '|' . $raw_term ];
	}

	$empty = array(
		'ids'    => array(),
		'labels' => array(),
	);

	$phrase = ner_michoel_search_fold( $raw_term );
	if ( '' === $phrase ) {
		return $cache[ $post_type . '|' . $raw_term ] = $empty;
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

	// Close spellings can't be found with LIKE, so while some word of the search
	// may be a little off, every title is read. Otherwise only the candidates are:
	// any title with at least one of the words. Only they get scored in PHP.
	$close_words = ner_michoel_search_close_words( $raw_term );

	global $wpdb;
	if ( $close_words ) {
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title, post_date FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'", $post_type ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- only the table name is interpolated.
	} else {
		$clauses = array();
		foreach ( $words as $word ) {
			$clauses[] = $wpdb->prepare( 'post_title LIKE %s', '%' . $wpdb->esc_like( $word ) . '%' );
		}
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT ID, post_title, post_date FROM {$wpdb->posts} WHERE post_type = '" . esc_sql( $post_type ) . "' AND post_status = 'publish' AND (" . implode( ' OR ', $clauses ) . ')'
		);
	}

	// Whole phrase outranks any count of single words, so it gets the top
	// rank value. Word counts run 1..$total.
	$phrase_rank = $total + 1;
	$scored      = array();
	$folded      = array(); // each row's folded title, for the close-spelling pass

	foreach ( (array) $rows as $row_index => $row ) {
		$title = ner_michoel_search_fold( $row->post_title );
		$folded[ $row_index ] = $title;

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
	// with all of the words, then close spellings (a letter or two off, or
	// swapped), then titles that match only some of the words. A shiur shows
	// once, under the first group that matches it.
	$phrase_ids = array();
	$all_words  = array();
	$partial    = array();
	foreach ( $scored as $item ) {
		if ( $phrase_rank === $item['rank'] ) {
			$phrase_ids[] = $item['id'];
		} elseif ( $item['rank'] === $total ) {
			$all_words[] = $item;
		} else {
			$partial[] = $item;
		}
	}
	$ids  = $phrase_ids;
	$seen = array_flip( $ids );

	// Speakers, then series ("topic"). The heading names the speaker or
	// series, so the reason it's in the results is visible.
	// A speaker match means that speaker's shiurim only. A matching series is
	// limited to the matched speakers' shiurim in it, so other speakers' shiurim
	// from the same series never show up under a speaker search.
	$matched_speakers    = ner_michoel_search_matching_terms( 'speaker', $phrase, $words, $total );
	$matched_series      = ner_michoel_search_matching_terms( 'series', $phrase, $words, $total );
	$matched_speaker_ids = array_map( 'intval', wp_list_pluck( $matched_speakers, 'term_id' ) );

	foreach ( array( 'speaker', 'series' ) as $taxonomy ) {
		$terms = 'speaker' === $taxonomy ? $matched_speakers : $matched_series;
		foreach ( $terms as $term ) {
			$label = 'speaker' === $taxonomy
				? sprintf( /* translators: %s: speaker name */ __( 'Speaker: %s', 'ner-michoel-core' ), $term->name )
				: sprintf( /* translators: %s: series name */ __( 'Series: %s', 'ner-michoel-core' ), $term->name );

			$only_speakers = 'series' === $taxonomy ? $matched_speaker_ids : array();
			foreach ( ner_michoel_search_term_shiur_ids( $term->term_id, $taxonomy, $post_type, $only_speakers ) as $shiur_id ) {
				if ( isset( $seen[ $shiur_id ] ) ) {
					continue;
				}
				$seen[ $shiur_id ]     = true;
				$ids[]                 = $shiur_id;
				$labels[ $shiur_id ]   = $label;
			}
		}
	}

	// Titles with every word (already sorted, newest first).
	foreach ( $all_words as $item ) {
		if ( isset( $seen[ $item['id'] ] ) ) {
			continue;
		}
		$seen[ $item['id'] ] = true;
		$ids[]               = $item['id'];
	}

	// Close spellings: every word is there, but one or two letters are off, or two
	// are swapped. They come after everything that matches as typed, nearest
	// first: titles, then speakers, then series. They sit ahead of the titles with
	// only some of the words, because a title with all of them, one spelled a little
	// differently, is the better match.
	if ( $close_words ) {
		$close_label = __( 'Close matches (spelled a little differently)', 'ner-michoel-core' );
		foreach ( ner_michoel_search_close_titles( (array) $rows, $folded, $close_words, $seen ) as $item ) {
			$seen[ $item['id'] ]   = true;
			$ids[]                 = $item['id'];
			$labels[ $item['id'] ] = $close_label;
		}

		$close_speakers = ner_michoel_search_close_terms( 'speaker', $close_words, wp_list_pluck( $matched_speakers, 'term_id' ) );
		$close_series   = ner_michoel_search_close_terms( 'series', $close_words, wp_list_pluck( $matched_series, 'term_id' ) );
		// A close series is narrowed to the speakers the search found, close ones too.
		$any_speaker_ids = array_merge( $matched_speaker_ids, array_map( 'intval', wp_list_pluck( $close_speakers, 'term_id' ) ) );

		foreach ( array( 'speaker' => $close_speakers, 'series' => $close_series ) as $taxonomy => $terms ) {
			foreach ( $terms as $term ) {
				$label = 'speaker' === $taxonomy
					? sprintf( /* translators: %s: speaker name */ __( 'Speaker: %s (close match)', 'ner-michoel-core' ), $term->name )
					: sprintf( /* translators: %s: series name */ __( 'Series: %s (close match)', 'ner-michoel-core' ), $term->name );

				$only_speakers = 'series' === $taxonomy ? $any_speaker_ids : array();
				foreach ( ner_michoel_search_term_shiur_ids( $term->term_id, $taxonomy, $post_type, $only_speakers ) as $shiur_id ) {
					if ( isset( $seen[ $shiur_id ] ) ) {
						continue;
					}
					$seen[ $shiur_id ]   = true;
					$ids[]               = $shiur_id;
					$labels[ $shiur_id ] = $label;
				}
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

	return $cache[ $post_type . '|' . $raw_term ] = $result;
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
 * Titles with every word of the search, some of them spelled a little
 * differently (a close match, see ner_michoel_search_close_words()). Each word
 * is there exactly or within its allowance of one of the title's words, and a
 * word that exists in many titles is preferred to a rare one equally near, as in
 * the live search ("shabbos", not "shain", for "shabis"). Nearest first, newest
 * first within that. Titles in $skip (an array keyed by shiur ID) are left out.
 *
 * @param object[] $rows        Rows with ID, post_title and post_date.
 * @param string[] $folded      The folded title of each row, by the row's position.
 * @param array[]  $close_words See ner_michoel_search_close_words().
 * @param array    $skip        Shiur IDs as keys.
 * @return array[] array( 'id' => …, 'rank' => …, 'date' => … ), best first.
 */
function ner_michoel_search_close_titles( $rows, $folded, $close_words, $skip ) {
	// Every word of every title, with the rows it is in.
	$vocab = array();
	$last  = array();
	foreach ( $rows as $position => $row ) {
		foreach ( ner_michoel_search_tokens( $row->post_title ) as $token ) {
			$token = (string) $token;
			if ( ! isset( $vocab[ $token ] ) ) {
				$vocab[ $token ] = array( $position );
			} elseif ( $last[ $token ] !== $position ) {
				$vocab[ $token ][] = $position;
			}
			$last[ $token ] = $position;
		}
	}

	// For each word of the search: the rows with a title word within its allowance,
	// and how near the best one is (a whole number of changes, plus a fraction under
	// one that is smaller the more common the title word is).
	$near = array();
	foreach ( $close_words as $w => $entry ) {
		$word   = $entry['word'];
		$max    = $entry['max'];
		$anchor = ( $max && ner_michoel_search_anchored( $word ) ) ? ner_michoel_search_first_letter( $word ) : '';
		$map    = array();
		foreach ( $vocab as $key => $list ) {
			$other = (string) $key; // numeric words come back from array keys as integers.
			if ( $other === $word ) {
				$d = 0;
			} elseif ( ! $max ) {
				continue;
			} else {
				if ( '' !== $anchor && ner_michoel_search_first_letter( $other ) !== $anchor ) {
					continue;
				}
				$d = ner_michoel_search_distance( $word, $other, $max );
				if ( $d > $max ) {
					continue;
				}
			}
			$score = $d + 1 / ( 1 + count( $list ) );
			foreach ( $list as $position ) {
				if ( ! isset( $map[ $position ] ) || $score < $map[ $position ] ) {
					$map[ $position ] = $score;
				}
			}
		}
		$near[ $w ] = $map;
	}
	unset( $vocab, $last );

	$out   = array();
	$count = count( $close_words );
	foreach ( $rows as $position => $row ) {
		$id = (int) $row->ID;
		if ( isset( $skip[ $id ] ) ) {
			continue;
		}
		$total = 0;
		$extra = 0.0;
		foreach ( $close_words as $w => $entry ) {
			if ( isset( $folded[ $position ] ) && false !== strpos( $folded[ $position ], $entry['word'] ) ) {
				continue;
			}
			if ( ! isset( $near[ $w ][ $position ] ) ) {
				continue 2;
			}
			$score  = $near[ $w ][ $position ];
			$whole  = (int) floor( $score );
			$total += $whole;
			$extra += $score - $whole;
		}
		$out[] = array(
			'id'   => $id,
			'rank' => $total + $extra / $count,
			'date' => $row->post_date,
		);
	}

	usort(
		$out,
		function ( $a, $b ) {
			if ( $a['rank'] !== $b['rank'] ) {
				return $a['rank'] < $b['rank'] ? -1 : 1;
			}
			return strcmp( $b['date'], $a['date'] );
		}
	);

	// A close match is a suggestion, not the answer: a couple of hundred is plenty.
	return array_slice( $out, 0, 200 );
}

/**
 * Speakers or series whose name has every word of the search, some of them
 * spelled a little differently. For the terms the strict matcher above didn't
 * take ($skip_ids). Nearest first, then by name.
 *
 * @param string  $taxonomy    speaker or series.
 * @param array[] $close_words See ner_michoel_search_close_words().
 * @param int[]   $skip_ids    Term IDs already matched exactly.
 * @return WP_Term[]
 */
function ner_michoel_search_close_terms( $taxonomy, $close_words, $skip_ids ) {
	$terms = get_terms(
		array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => true,
		)
	);
	if ( is_wp_error( $terms ) || ! $terms ) {
		return array();
	}
	$skip    = array_flip( array_map( 'intval', (array) $skip_ids ) );
	$matched = array();

	foreach ( $terms as $term ) {
		if ( isset( $skip[ (int) $term->term_id ] ) ) {
			continue;
		}
		$name   = ner_michoel_search_fold( $term->name );
		$tokens = ner_michoel_search_tokens( $term->name );
		$total  = 0;
		foreach ( $close_words as $entry ) {
			$word = $entry['word'];
			if ( false !== strpos( $name, $word ) ) {
				continue;
			}
			$best = null;
			foreach ( $tokens as $token ) {
				$token = (string) $token;
				if ( $token === $word ) {
					$best = 0;
					break;
				}
				if ( ! $entry['max'] || ( ner_michoel_search_anchored( $word ) && ner_michoel_search_first_letter( $token ) !== ner_michoel_search_first_letter( $word ) ) ) {
					continue;
				}
				$d = ner_michoel_search_distance( $word, $token, $entry['max'] );
				if ( $d <= $entry['max'] && ( null === $best || $d < $best ) ) {
					$best = $d;
				}
			}
			if ( null === $best ) {
				continue 2;
			}
			$total += $best;
		}
		$matched[] = array(
			'term' => $term,
			'rank' => $total,
		);
	}

	usort(
		$matched,
		function ( $a, $b ) {
			if ( $a['rank'] !== $b['rank'] ) {
				return $a['rank'] - $b['rank'];
			}
			return strcmp( $a['term']->name, $b['term']->name );
		}
	);

	return array_column( $matched, 'term' );
}

/**
 * The first character of a word, whole (a Hebrew letter is more than one byte).
 */
function ner_michoel_search_first_letter( $word ) {
	return function_exists( 'mb_substr' ) ? mb_substr( $word, 0, 1, 'UTF-8' ) : substr( $word, 0, 1 );
}

/**
 * IDs of the published shiurim in a speaker or series, newest first.
 */
function ner_michoel_search_term_shiur_ids( $term_id, $taxonomy, $post_type = 'shiur', $only_speakers = array() ) {
	$tax_query = array(
		array(
			'taxonomy' => $taxonomy,
			'field'    => 'term_id',
			'terms'    => (int) $term_id,
		),
	);
	if ( $only_speakers ) {
		// Both conditions: in this series, and by one of these speakers.
		$tax_query['relation'] = 'AND';
		$tax_query[]           = array(
			'taxonomy' => 'speaker',
			'field'    => 'term_id',
			'terms'    => array_map( 'intval', $only_speakers ),
		);
	}

	return get_posts(
		array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
			'tax_query'      => $tax_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		)
	);
}

/**
 * Whether the current request is a Shiurim search, i.e. the main query
 * with a search term and post_type=shiur (the sidebar's search form sends
 * exactly that).
 */
function ner_michoel_search_scope( $query ) {
	if ( is_admin() || ! $query->is_main_query() || ! $query->is_search() ) {
		return '';
	}
	$type = $query->get( 'post_type' );
	return in_array( $type, array( 'shiur', 'written_shiur' ), true ) ? $type : '';
}

function ner_michoel_is_shiur_search_query( $query ) {
	return '' !== ner_michoel_search_scope( $query );
}

/**
 * Feeds the ranked IDs into the main query. Every result is one shiur,
 * and all of them fit on one page, so there's no pagination to manage.
 */
function ner_michoel_shiur_search_pre_get_posts( $query ) {
	if ( ! ner_michoel_is_shiur_search_query( $query ) ) {
		return;
	}

	/*
	 * A theme whose search page filters the cached index in the browser (the live
	 * search, shiur-index.php) returns false here, and the main query then finds
	 * nothing, cheaply. Themes that don't know this filter keep the ranking.
	 */
	if ( ! apply_filters( 'ner_michoel_shiur_search_server_ranking', true, $query ) ) {
		$query->set( 'post__in', array( 0 ) );
		$query->set( 'posts_per_page', 1 );
		$query->set( 'no_found_rows', true );
		$query->set( 'ignore_sticky_posts', true );
		return;
	}

	$ranking = ner_michoel_shiur_search_ranking( $query->get( 's' ), ner_michoel_search_scope( $query ) );
	$ids     = $ranking['ids'] ? $ranking['ids'] : array( 0 ); // array(0): no shiur matches an ID 0.

	$query->set( 'post__in', $ids );
	$query->set( 'orderby', 'post__in' );
	$query->set( 'posts_per_page', 100 );
	// Kept apart as well, for ner_michoel_shiur_search_page_size() below.
	$query->set( 'nm_search_per_page', 100 );
	$query->set( 'ignore_sticky_posts', true );
}
add_action( 'pre_get_posts', 'ner_michoel_shiur_search_pre_get_posts' );

/**
 * Puts the page size back. Astra (4.6 and later) sets every archive and search
 * to its own "blog posts per page" on its parse_tax_query hook, which runs after
 * pre_get_posts, so the 100 above came out as ten: the ranked results were cut
 * to the first ten, and the close spellings, which come after the full matches,
 * were out of sight. This runs after Astra's hook.
 */
function ner_michoel_shiur_search_page_size( $query ) {
	$size = (int) $query->get( 'nm_search_per_page' );
	if ( $size > 0 ) {
		$query->set( 'posts_per_page', $size );
	}
}
add_action( 'parse_tax_query', 'ner_michoel_shiur_search_page_size', 99 );

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
function ner_michoel_shiur_search_labels( $raw_term, $post_type = 'shiur' ) {
	$ranking = ner_michoel_shiur_search_ranking( $raw_term, $post_type );
	return $ranking['labels'];
}
