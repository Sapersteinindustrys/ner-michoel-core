<?php
/**
 * Series as a tree: "Gemara" > "Bava Kamma" > "Perek 3".
 *
 * The library came over from the old site with the whole path in each series' NAME, written with
 * " / " between the parts ("Gemara / Bava Kamma / Perek 3"). The `series` taxonomy was already
 * hierarchical, so here those names are split into real series and sub-series: the term keeps its ID
 * (every shiur stays in it), its slug (every address and bookmark keeps working), and gets the last
 * part as its name and the rest as its parents.
 *
 * Everything on the site that shows a series name, sorts by it, searches it or reads a sefer or a
 * parsha out of it still wants the old text, so ner_michoel_series_label() builds it again from the
 * tree: "Gemara / Bava Kamma / Perek 3". A series that has not been split yet (no parent) is its own
 * label, so a half-finished split looks exactly like a finished one, and so does a site that never
 * had one.
 *
 * What is in this file:
 * - the helpers: the parts of a name, the label, the tree in order, find-or-create by path;
 * - the split of the old names (a background job in short steps, with an Undo that puts every name
 *   and parent back) and the routes the Series screen uses to show it;
 * - the route the series picker uses to add a series or a sub-series.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NER_MICHOEL_SERIES_SPLIT_VERSION', '1' );

/* ------------------------------------------------------------------
 * Names and labels.
 * ------------------------------------------------------------------ */

/**
 * A part of a series name as a comparison key: no entities, one space between words, lower case.
 */
function ner_michoel_series_key( $text ) {
	$text = html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' );
	$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
}

/**
 * The parts of a name written as a path: "Gemara / Bava Kamma" => array( 'Gemara', 'Bava Kamma' ).
 * Only a slash with a space on each side splits ("Yoma 2a/b" is one part).
 *
 * @return string[]
 */
function ner_michoel_series_split_name( $name ) {
	$parts = array();
	foreach ( preg_split( '#\s+/\s+#u', (string) $name ) as $part ) {
		$part = trim( (string) preg_replace( '/\s+/u', ' ', $part ) );
		if ( '' !== $part ) {
			$parts[] = $part;
		}
	}
	return $parts;
}

/**
 * A path's parts as one key.
 *
 * @param string[] $parts Parts as split by ner_michoel_series_split_name().
 */
function ner_michoel_series_path_key( array $parts ) {
	return implode( "\x1f", array_map( 'ner_michoel_series_key', $parts ) );
}

/**
 * Every series term.
 *
 * @return WP_Term[]
 */
function ner_michoel_series_all_terms() {
	$terms = get_terms(
		array(
			'taxonomy'               => 'series',
			'hide_empty'             => false,
			'orderby'                => 'term_id',
			'update_term_meta_cache' => false,
		)
	);
	return is_wp_error( $terms ) ? array() : $terms;
}

/**
 * Term ID => its label ("Gemara / Bava Kamma / Perek 3"), for every series. Built once per request
 * (one query); pass true to forget it after a term changed.
 *
 * @param bool $reset Forget the map.
 * @return array<int,string>
 */
function ner_michoel_series_label_map( $reset = false ) {
	static $map = null;
	if ( $reset ) {
		$map = null;
		return array();
	}
	if ( null !== $map ) {
		return $map;
	}

	$by = array();
	foreach ( ner_michoel_series_all_terms() as $term ) {
		$by[ (int) $term->term_id ] = array( (string) $term->name, (int) $term->parent );
	}

	$map = array();
	foreach ( $by as $id => $row ) {
		$parts = array( $row[0] );
		$up    = $row[1];
		$guard = 0;
		while ( $up && isset( $by[ $up ] ) && $guard++ < 20 ) {
			array_unshift( $parts, $by[ $up ][0] );
			$up = $by[ $up ][1];
		}
		$map[ $id ] = implode( ' / ', $parts );
	}
	return $map;
}

/**
 * Path key => term ID for every series, by its whole name (split or not yet split), so a name written as a path
 * finds the series it means. The first series wins if two have the same name.
 *
 * @param bool $reset Forget the index.
 * @return array<string,int>
 */
function ner_michoel_series_label_index( $reset = false ) {
	static $index = null;
	if ( $reset ) {
		$index = null;
		return array();
	}
	if ( null !== $index ) {
		return $index;
	}
	$index = array();
	foreach ( ner_michoel_series_label_map() as $id => $label ) {
		$key = ner_michoel_series_path_key( ner_michoel_series_split_name( $label ) );
		if ( '' !== $key && ! isset( $index[ $key ] ) ) {
			$index[ $key ] = (int) $id;
		}
	}
	return $index;
}

function ner_michoel_series_forget_labels() {
	ner_michoel_series_label_map( true );
	ner_michoel_series_label_index( true );
}
add_action( 'clean_term_cache', 'ner_michoel_series_forget_labels' );
add_action( 'created_term', 'ner_michoel_series_forget_labels' );
add_action( 'edited_term', 'ner_michoel_series_forget_labels' );
add_action( 'delete_term', 'ner_michoel_series_forget_labels' );

/**
 * A series' whole name: its parents' names and its own, with " / " between. This is the text the site
 * has always shown for a series; a series with no parent is just its name.
 *
 * @param WP_Term|object|int $term The term or its ID.
 * @return string Escaped like the term's name is (entities as stored).
 */
function ner_michoel_series_label( $term ) {
	if ( is_numeric( $term ) ) {
		$term = get_term( (int) $term, 'series' );
	}
	if ( ! $term || is_wp_error( $term ) || ! isset( $term->name ) ) {
		return '';
	}
	if ( empty( $term->parent ) || ( isset( $term->taxonomy ) && 'series' !== $term->taxonomy ) ) {
		return (string) $term->name;
	}
	$map = ner_michoel_series_label_map();
	return isset( $map[ (int) $term->term_id ] ) ? $map[ (int) $term->term_id ] : (string) $term->name;
}

/**
 * The text to show for a term of any taxonomy: a series' whole name, any other term's name.
 *
 * @param WP_Term|object $term The term.
 */
function ner_michoel_term_label( $term ) {
	if ( $term && ! is_wp_error( $term ) && isset( $term->taxonomy ) && 'series' === $term->taxonomy ) {
		return ner_michoel_series_label( $term );
	}
	return ( $term && ! is_wp_error( $term ) && isset( $term->name ) ) ? (string) $term->name : '';
}

/**
 * The tree, parents first and each parent's series in natural order (Perek 2 before Perek 10).
 * 'path' is the label, 'depth' is 0 for a top-level series, 'children' how many sit directly inside,
 * 'last' when a published post of $post_type last used it (for "Recently used").
 *
 * @param string $post_type Post type for 'last'.
 * @return array<int,array>
 */
function ner_michoel_series_rows( $post_type = 'shiur' ) {
	$terms = ner_michoel_series_all_terms();
	$by    = array();
	foreach ( $terms as $term ) {
		$by[ (int) $term->term_id ] = $term;
	}

	$kids = array();
	foreach ( $by as $id => $term ) {
		$parent = (int) $term->parent;
		if ( ! $parent || ! isset( $by[ $parent ] ) ) {
			$parent = 0;
		}
		$kids[ $parent ][] = $id;
	}
	$decoded = function ( $id ) use ( $by ) {
		return html_entity_decode( (string) $by[ $id ]->name, ENT_QUOTES, 'UTF-8' );
	};
	foreach ( $kids as $parent => $list ) {
		usort(
			$list,
			function ( $a, $b ) use ( $decoded ) {
				$cmp = strnatcasecmp( $decoded( $a ), $decoded( $b ) );
				return 0 !== $cmp ? $cmp : $a - $b;
			}
		);
		$kids[ $parent ] = $list;
	}

	$labels = ner_michoel_series_label_map();
	$latest = function_exists( 'ner_michoel_term_latest_dates' ) ? ner_michoel_term_latest_dates( 'series', $post_type ) : array();

	$out  = array();
	$seen = array();
	$walk = function ( $parent, $depth ) use ( &$walk, &$out, &$seen, $kids, $by, $labels, $latest ) {
		if ( empty( $kids[ $parent ] ) || $depth > 20 ) {
			return;
		}
		foreach ( $kids[ $parent ] as $id ) {
			if ( isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$term        = $by[ $id ];
			$out[]       = array(
				'id'       => $id,
				'name'     => (string) $term->name,
				'parent'   => (int) $term->parent,
				'path'     => isset( $labels[ $id ] ) ? $labels[ $id ] : (string) $term->name,
				'depth'    => $depth,
				'count'    => (int) $term->count,
				'children' => isset( $kids[ $id ] ) ? count( $kids[ $id ] ) : 0,
				'last'     => isset( $latest[ $id ] ) ? $latest[ $id ] : '',
			);
			$walk( $id, $depth + 1 );
		}
	};
	$walk( 0, 0 );

	// A loop (a is inside b, b is inside a) would leave both out of the walk: list them at the top.
	foreach ( $by as $id => $term ) {
		if ( ! isset( $seen[ $id ] ) ) {
			$out[] = array(
				'id'       => $id,
				'name'     => (string) $term->name,
				'parent'   => (int) $term->parent,
				'path'     => isset( $labels[ $id ] ) ? $labels[ $id ] : (string) $term->name,
				'depth'    => 0,
				'count'    => (int) $term->count,
				'children' => 0,
				'last'     => isset( $latest[ $id ] ) ? $latest[ $id ] : '',
			);
		}
	}
	return $out;
}

/**
 * The series with this name directly inside $parent_id (0 for the top level), compared the way people
 * read names: no case, one space.
 *
 * @return int Term ID, or 0.
 */
function ner_michoel_series_find_child( $name, $parent_id = 0 ) {
	$key      = ner_michoel_series_key( $name );
	$siblings = get_terms(
		array(
			'taxonomy'               => 'series',
			'hide_empty'             => false,
			'parent'                 => (int) $parent_id,
			'fields'                 => 'id=>name',
			'update_term_meta_cache' => false,
		)
	);
	if ( is_wp_error( $siblings ) ) {
		return 0;
	}
	foreach ( $siblings as $id => $sibling_name ) {
		if ( ner_michoel_series_key( $sibling_name ) === $key ) {
			return (int) $id;
		}
	}
	return 0;
}

/**
 * The series at the end of a path under $parent_id, made (with the series on the way) when they are not
 * there. "Bava Kamma / Perek 3" under Gemara finds or makes Bava Kamma in Gemara, then Perek 3 in it.
 * A series that is made gets the address of its whole name ("gemara-bava-kamma-perek-3"), the way the
 * old names did, so two Perek 3 never fight over one address.
 *
 * @param string $path      One name or a path with " / ".
 * @param int    $parent_id Where it starts; 0 for the top level.
 * @param bool   $create    False only looks.
 * @return array|int|WP_Error array( 'term_id' => int, 'added' => bool ); 0 when only looking and not found.
 */
function ner_michoel_series_find_or_create_path( $path, $parent_id = 0, $create = true ) {
	$parts = ner_michoel_series_split_name( $path );
	if ( ! $parts ) {
		return new WP_Error( 'nm_series_no_name', __( 'A series needs a name.', 'ner-michoel-core' ), array( 'status' => 400 ) );
	}

	// The series may be there already under its whole name, split or not ("Gemara / Bava Kamma" as it came over).
	$whole = $parent_id ? array_merge( ner_michoel_series_split_name( ner_michoel_series_label( (int) $parent_id ) ), $parts ) : $parts;
	$index = ner_michoel_series_label_index();
	$want  = ner_michoel_series_path_key( $whole );
	if ( isset( $index[ $want ] ) ) {
		return array(
			'term_id' => $index[ $want ],
			'added'   => false,
		);
	}

	$current = (int) $parent_id;
	$added   = false;
	foreach ( $parts as $part ) {
		$found = ner_michoel_series_find_child( $part, $current );
		if ( $found ) {
			$current = $found;
			continue;
		}
		if ( ! $create ) {
			return 0;
		}
		$label = ( $current ? html_entity_decode( ner_michoel_series_label( $current ), ENT_QUOTES, 'UTF-8' ) . ' / ' : '' ) . html_entity_decode( $part, ENT_QUOTES, 'UTF-8' );
		$made  = wp_insert_term(
			$part,
			'series',
			array(
				'parent' => $current,
				'slug'   => sanitize_title( $label ),
			)
		);
		if ( is_wp_error( $made ) ) {
			$existing = $made->get_error_data( 'term_exists' );
			if ( $existing ) {
				$current = (int) $existing;
				continue;
			}
			return new WP_Error( 'nm_series_add_failed', $made->get_error_message(), array( 'status' => 400 ) );
		}
		$current = (int) $made['term_id'];
		$added   = true;
	}
	return array(
		'term_id' => $current,
		'added'   => $added,
	);
}

/**
 * Series for the term loops that list "series that have shiurim": with 'hide_empty', WordPress also
 * lists a parent whose sub-series are not empty. The lists on the site (the Shiurim page, the sidebar,
 * search, the This Week picker, the parsha map) mean the series that hold shiurim themselves, as they
 * always did, so for those the parents with nothing of their own stay out. The admin lists ask for
 * every series (hide_empty false) and are not touched, and a query that does want the parents (a series
 * page listing the series inside it) says so with 'nm_with_parents' => true.
 *
 * @param array    $args       get_terms() arguments.
 * @param string[] $taxonomies The taxonomies asked for.
 */
function ner_michoel_series_terms_args( $args, $taxonomies ) {
	if ( ! empty( $args['hide_empty'] ) && empty( $args['nm_with_parents'] ) && in_array( 'series', (array) $taxonomies, true ) ) {
		$args['hierarchical'] = false;
	}
	return $args;
}
add_filter( 'get_terms_args', 'ner_michoel_series_terms_args', 10, 2 );

/**
 * Two series labels in the order the database sorted the old whole names: no regard for case, character by character.
 * (Checked against the live library: the 462 real names come out in exactly the order WordPress listed them in.)
 *
 * @param string $a A label.
 * @param string $b Another.
 * @return int
 */
function ner_michoel_series_compare_labels( $a, $b ) {
	$a = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $a, 'UTF-8' ) : strtolower( (string) $a );
	$b = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $b, 'UTF-8' ) : strtolower( (string) $b );
	return strcmp( $a, $b );
}

/**
 * A list of series "by name" is by WHOLE name, as it always was. WordPress sorts by the term's own name, which
 * after the split would put every "Perek 1" together and "Gemara" among the Bs. This puts a plain A-to-Z list
 * of series (get_terms() with the default order, no paging) back in the order of the whole names.
 *
 * @param WP_Term[] $terms      The terms found.
 * @param string[]  $taxonomies The taxonomies asked for.
 * @param array     $args       The query arguments.
 * @return WP_Term[]
 */
function ner_michoel_series_order_terms( $terms, $taxonomies, $args ) {
	if ( ! is_array( $terms ) || count( $terms ) < 2 || array( 'series' ) !== array_values( (array) $taxonomies ) ) {
		return $terms;
	}
	if ( ( isset( $args['orderby'] ) ? $args['orderby'] : 'name' ) !== 'name' || 'ASC' !== strtoupper( isset( $args['order'] ) ? (string) $args['order'] : 'ASC' )
		|| ( isset( $args['fields'] ) ? $args['fields'] : 'all' ) !== 'all' || ! empty( $args['number'] ) || ! empty( $args['offset'] ) ) {
		return $terms;
	}
	if ( ! ( reset( $terms ) instanceof WP_Term ) ) {
		return $terms;
	}
	usort(
		$terms,
		function ( $a, $b ) {
			$cmp = ner_michoel_series_compare_labels( ner_michoel_series_label( $a ), ner_michoel_series_label( $b ) );
			return 0 !== $cmp ? $cmp : (int) $a->term_id - (int) $b->term_id;
		}
	);
	return $terms;
}
add_filter( 'get_terms', 'ner_michoel_series_order_terms', 10, 3 );

/**
 * A series' own page is titled with its whole name, as before the split ("Gemara / Bava Kamma / Perek 3",
 * not "Perek 3"). Only the copy of the term this request is showing is changed, never what is stored.
 */
function ner_michoel_series_queried_label() {
	if ( is_admin() || ! is_tax( 'series' ) ) {
		return;
	}
	$term = get_queried_object();
	if ( $term instanceof WP_Term && $term->parent ) {
		$term->name = ner_michoel_series_label( $term );
	}
}
add_action( 'wp', 'ner_michoel_series_queried_label' );

/* ------------------------------------------------------------------
 * Splitting the old names into series and sub-series.
 * ------------------------------------------------------------------ */

/**
 * Whether the split may start by itself. Define NER_MICHOEL_SERIES_SPLIT_OFF as true to stop it (the
 * Series screen can still split by hand).
 */
function ner_michoel_series_split_auto() {
	return ! ( defined( 'NER_MICHOEL_SERIES_SPLIT_OFF' ) && NER_MICHOEL_SERIES_SPLIT_OFF );
}

/**
 * The record of what a split changed, so Undo can put it back.
 *
 * @return array{changed:array,created:int[],skipped:array}
 */
function ner_michoel_series_split_log() {
	$log = get_option( 'nm_series_split_log', array() );
	$log = is_array( $log ) ? $log : array();
	return array(
		'changed' => isset( $log['changed'] ) && is_array( $log['changed'] ) ? $log['changed'] : array(),
		'created' => isset( $log['created'] ) && is_array( $log['created'] ) ? $log['created'] : array(),
		'skipped' => isset( $log['skipped'] ) && is_array( $log['skipped'] ) ? $log['skipped'] : array(),
	);
}

function ner_michoel_series_split_save_log( array $log ) {
	if ( ! $log['changed'] && ! $log['created'] && ! $log['skipped'] ) {
		delete_option( 'nm_series_split_log' );
		return;
	}
	update_option( 'nm_series_split_log', $log, false );
}

/**
 * How many series still carry a path in their name and are waiting for the split. The ones it left as they are
 * on purpose (listed as skipped) are not counted: pressing the button again would not change them.
 */
function ner_michoel_series_split_pending() {
	$log     = ner_michoel_series_split_log();
	$skipped = array();
	foreach ( $log['skipped'] as $row ) {
		$skipped[ (int) $row['id'] ] = true;
	}
	$n = 0;
	foreach ( ner_michoel_series_all_terms() as $term ) {
		if ( ! (int) $term->parent && ! isset( $skipped[ (int) $term->term_id ] ) && count( ner_michoel_series_split_name( $term->name ) ) > 1 ) {
			++$n;
		}
	}
	return $n;
}

/**
 * One go at the split: works until the time is up, and says whether there is more. Safe to repeat and
 * to stop at any point: each series is changed in one step, and the next go starts from what is stored.
 *
 * A series named "Gemara / Bava Kamma / Perek 3" becomes "Perek 3" inside "Bava Kamma" inside "Gemara".
 * Parents that are not there yet are made; one that is there already (a series called "Gemara / Eiruvin"
 * with shiurim of its own) is used as the parent and renamed "Eiruvin" itself. A name that matches another
 * series' whole name is left as it is and listed as skipped.
 *
 * @param int  $seconds Time to work.
 * @param bool $retry   Try the series an earlier go skipped again (a series that was in the way may be gone).
 * @return array{more:bool,changed:int,created:int,skipped:int}
 */
function ner_michoel_series_split_run( $seconds = 20, $retry = false ) {
	$deadline = time() + max( 1, (int) $seconds );
	$terms    = ner_michoel_series_all_terms();
	$labels   = ner_michoel_series_label_map( true );
	$labels   = ner_michoel_series_label_map();

	// Every series by the key of its whole name: where a path already has a series.
	$nodes = array();
	foreach ( $terms as $term ) {
		$id    = (int) $term->term_id;
		$label = isset( $labels[ $id ] ) ? $labels[ $id ] : (string) $term->name;
		$key   = ner_michoel_series_path_key( ner_michoel_series_split_name( $label ) );
		if ( '' !== $key && ! isset( $nodes[ $key ] ) ) {
			$nodes[ $key ] = $id;
		}
	}

	$todo = array();
	foreach ( $terms as $term ) {
		if ( (int) $term->parent ) {
			continue;
		}
		$parts = ner_michoel_series_split_name( $term->name );
		if ( count( $parts ) > 1 ) {
			$todo[] = array( $term, $parts );
		}
	}
	// Shorter paths first, so a series that is also a parent is renamed before its children look for it.
	usort(
		$todo,
		function ( $a, $b ) {
			$cmp = count( $a[1] ) - count( $b[1] );
			return 0 !== $cmp ? $cmp : (int) $a[0]->term_id - (int) $b[0]->term_id;
		}
	);

	$log      = ner_michoel_series_split_log();
	$skipped  = array();
	foreach ( $retry ? array() : $log['skipped'] as $row ) {
		$skipped[ (int) $row['id'] ] = $row;
	}
	$changed  = 0;
	$created  = 0;
	$more     = false;
	$since    = 0;

	foreach ( $todo as $item ) {
		list( $term, $parts ) = $item;
		$id = (int) $term->term_id;

		if ( isset( $skipped[ $id ] ) ) {
			continue;
		}
		if ( time() >= $deadline ) {
			$more = true;
			break;
		}

		$own_key = ner_michoel_series_path_key( $parts );
		if ( isset( $nodes[ $own_key ] ) && $nodes[ $own_key ] !== $id ) {
			$skipped[ $id ] = array(
				'id'     => $id,
				'name'   => (string) $term->name,
				'reason' => sprintf( 'the same name as series %d', $nodes[ $own_key ] ),
			);
			continue;
		}

		$parent_id = 0;
		$prefix    = array();
		$failed    = '';
		for ( $i = 0, $last = count( $parts ) - 1; $i < $last; $i++ ) {
			$prefix[] = $parts[ $i ];
			$key      = ner_michoel_series_path_key( $prefix );
			if ( isset( $nodes[ $key ] ) ) {
				$parent_id = $nodes[ $key ];
				continue;
			}
			$made = wp_insert_term(
				$parts[ $i ],
				'series',
				array(
					'parent' => $parent_id,
					'slug'   => sanitize_title( html_entity_decode( implode( ' / ', $prefix ), ENT_QUOTES, 'UTF-8' ) ),
				)
			);
			if ( is_wp_error( $made ) ) {
				$failed = $made->get_error_message();
				break;
			}
			$parent_id         = (int) $made['term_id'];
			$nodes[ $key ]     = $parent_id;
			$log['created'][]  = $parent_id;
			++$created;
		}
		if ( '' !== $failed ) {
			$skipped[ $id ] = array(
				'id'     => $id,
				'name'   => (string) $term->name,
				'reason' => $failed,
			);
			continue;
		}

		$result = wp_update_term(
			$id,
			'series',
			array(
				'name'   => $parts[ count( $parts ) - 1 ],
				'parent' => $parent_id,
				'slug'   => $term->slug,
			)
		);
		if ( is_wp_error( $result ) ) {
			$skipped[ $id ] = array(
				'id'     => $id,
				'name'   => (string) $term->name,
				'reason' => $result->get_error_message(),
			);
			continue;
		}

		$now = get_term( $id, 'series' );
		$log['changed'][] = array(
			'id'         => $id,
			'name'       => (string) $term->name,
			'parent'     => 0,
			'new_name'   => ( $now && ! is_wp_error( $now ) ) ? (string) $now->name : $parts[ count( $parts ) - 1 ],
			'new_parent' => $parent_id,
		);
		++$changed;

		if ( ++$since >= 25 ) {
			$log['skipped'] = array_values( $skipped );
			ner_michoel_series_split_save_log( $log );
			$since = 0;
		}
	}

	$log['skipped'] = array_values( $skipped );
	ner_michoel_series_split_save_log( $log );

	if ( ! $more ) {
		update_option( 'nm_series_split_version', NER_MICHOEL_SERIES_SPLIT_VERSION );
		update_option( 'nm_series_split_done', time(), false );
		// Done: a follow-up that was already queued would only find nothing to do.
		wp_clear_scheduled_hook( 'nm_series_split' );
	}

	return array(
		'more'    => $more,
		'changed' => $changed,
		'created' => $created,
		'skipped' => count( $skipped ),
	);
}

/**
 * One go at taking the split back: every series the split renamed gets its old name and no parent, the
 * parents it made are deleted when nothing is in them, and series someone has renamed or moved since
 * are left as they are.
 *
 * @param int $seconds Time to work.
 * @return array{more:bool,restored:int,kept:int,removed:int}
 */
function ner_michoel_series_split_undo( $seconds = 20 ) {
	$deadline = time() + max( 1, (int) $seconds );
	$log      = ner_michoel_series_split_log();
	$restored = 0;
	$kept     = 0;
	$removed  = 0;
	$more     = false;

	while ( $log['changed'] ) {
		if ( time() >= $deadline ) {
			$more = true;
			break;
		}
		$row  = array_pop( $log['changed'] );
		$term = get_term( (int) $row['id'], 'series' );
		if ( ! $term || is_wp_error( $term ) ) {
			continue;
		}
		if ( (string) $term->name !== (string) $row['new_name'] || (int) $term->parent !== (int) $row['new_parent'] ) {
			++$kept;
			continue;
		}
		$result = wp_update_term(
			(int) $row['id'],
			'series',
			array(
				'name'   => (string) $row['name'],
				'parent' => (int) $row['parent'],
				'slug'   => $term->slug,
			)
		);
		if ( is_wp_error( $result ) ) {
			++$kept;
		} else {
			++$restored;
		}
	}

	if ( ! $more ) {
		// The parents the split made, deepest first. One with a shiur, or with a series someone put in it since, stays.
		foreach ( array_reverse( $log['created'] ) as $id ) {
			$term = get_term( (int) $id, 'series' );
			if ( ! $term || is_wp_error( $term ) ) {
				continue;
			}
			$inside = get_term_children( (int) $id, 'series' );
			$posts  = get_objects_in_term( (int) $id, 'series' );
			if ( ( ! is_wp_error( $inside ) && $inside ) || ( ! is_wp_error( $posts ) && $posts ) ) {
				++$kept;
				continue;
			}
			wp_delete_term( (int) $id, 'series' );
			++$removed;
		}
		$log['created'] = array();
		$log['skipped'] = array();
		// Undone on purpose: it must not start again by itself.
		update_option( 'nm_series_split_version', NER_MICHOEL_SERIES_SPLIT_VERSION );
		delete_option( 'nm_series_split_done' );
	}

	ner_michoel_series_split_save_log( $log );

	return array(
		'more'     => $more,
		'restored' => $restored,
		'kept'     => $kept,
		'removed'  => $removed,
	);
}

/**
 * What the Series screen says about the split.
 *
 * @return array<string,mixed>
 */
function ner_michoel_series_split_status() {
	$log = ner_michoel_series_split_log();
	return array(
		'pending'  => ner_michoel_series_split_pending(),
		'changed'  => count( $log['changed'] ),
		'created'  => count( $log['created'] ),
		'skipped'  => $log['skipped'],
		'can_undo' => (bool) ( $log['changed'] || $log['created'] ),
		'running'  => (bool) wp_next_scheduled( 'nm_series_split' ) || (bool) get_transient( 'nm_series_split_lock' ),
		'done_at'  => (int) get_option( 'nm_series_split_done', 0 ),
	);
}

/**
 * The background run: one go, then another a few seconds later until nothing is left. One at a time.
 */
function ner_michoel_series_split_tick() {
	if ( ! ner_michoel_series_split_auto() ) {
		return;
	}
	if ( get_transient( 'nm_series_split_lock' ) ) {
		return;
	}
	set_transient( 'nm_series_split_lock', 1, 2 * MINUTE_IN_SECONDS );
	$result = ner_michoel_series_split_run( function_exists( 'ner_michoel_import_tick_seconds' ) ? ner_michoel_import_tick_seconds() : 20 );
	delete_transient( 'nm_series_split_lock' );
	if ( $result['more'] ) {
		wp_schedule_single_event( time() + 5, 'nm_series_split' );
	}
}
add_action( 'nm_series_split', 'ner_michoel_series_split_tick' );

/**
 * Starts the split in the background once this version is installed. Cheap on every load: one option and
 * the event check.
 */
function ner_michoel_series_split_ensure() {
	if ( ! ner_michoel_series_split_auto() ) {
		return;
	}
	$done = get_option( 'nm_series_split_version' );
	if ( false === $done ) {
		// Stored (and loaded with the other options) so this check costs no query on the pages that follow.
		add_option( 'nm_series_split_version', '0' );
		$done = '0';
	}
	if ( NER_MICHOEL_SERIES_SPLIT_VERSION !== $done && ! wp_next_scheduled( 'nm_series_split' ) ) {
		wp_schedule_single_event( time() + 30, 'nm_series_split' );
	}
}
add_action( 'init', 'ner_michoel_series_split_ensure', 20 );

/* ------------------------------------------------------------------
 * Routes: the split's status / run / undo for the Series screen, and add-a-series for the picker.
 * ------------------------------------------------------------------ */

function ner_michoel_series_can_manage() {
	return current_user_can( 'manage_categories' );
}

function ner_michoel_series_register_routes() {
	register_rest_route(
		'ner-michoel/v1',
		'/series/add',
		array(
			'methods'             => 'POST',
			'callback'            => 'ner_michoel_series_route_add',
			'permission_callback' => 'ner_michoel_series_can_manage',
		)
	);
	register_rest_route(
		'ner-michoel/v1',
		'/series/split-status',
		array(
			'methods'             => 'GET',
			'callback'            => 'ner_michoel_series_route_split_status',
			'permission_callback' => 'ner_michoel_series_can_manage',
		)
	);
	register_rest_route(
		'ner-michoel/v1',
		'/series/split',
		array(
			'methods'             => 'POST',
			'callback'            => 'ner_michoel_series_route_split',
			'permission_callback' => 'ner_michoel_series_can_manage',
		)
	);
	register_rest_route(
		'ner-michoel/v1',
		'/series/split-undo',
		array(
			'methods'             => 'POST',
			'callback'            => 'ner_michoel_series_route_split_undo',
			'permission_callback' => 'ner_michoel_series_can_manage',
		)
	);
}
add_action( 'rest_api_init', 'ner_michoel_series_register_routes' );

/**
 * Adds a series, or a sub-series inside the one in 'parent'. A name that is already there comes back as
 * it is. A name written as a path ("Bava Kamma / Perek 3") makes the series on the way too.
 */
function ner_michoel_series_route_add( WP_REST_Request $request ) {
	$name   = trim( wp_strip_all_tags( (string) $request->get_param( 'name' ) ) );
	$parent = absint( $request->get_param( 'parent' ) );

	if ( $parent ) {
		$parent_term = get_term( $parent, 'series' );
		if ( ! $parent_term || is_wp_error( $parent_term ) ) {
			return new WP_Error( 'nm_series_bad_parent', __( 'The series to add it to was not found.', 'ner-michoel-core' ), array( 'status' => 400 ) );
		}
	}

	$result = ner_michoel_series_find_or_create_path( $name, $parent, true );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$term = get_term( (int) $result['term_id'], 'series' );
	if ( ! $term || is_wp_error( $term ) ) {
		return new WP_Error( 'nm_series_add_failed', __( 'The series could not be added.', 'ner-michoel-core' ), array( 'status' => 500 ) );
	}

	return new WP_REST_Response(
		array(
			'id'     => (int) $term->term_id,
			'name'   => html_entity_decode( (string) $term->name, ENT_QUOTES, 'UTF-8' ),
			'parent' => (int) $term->parent,
			'path'   => html_entity_decode( ner_michoel_series_label( $term ), ENT_QUOTES, 'UTF-8' ),
			'added'  => (bool) $result['added'],
		),
		200
	);
}

function ner_michoel_series_route_split_status() {
	return new WP_REST_Response( ner_michoel_series_split_status(), 200 );
}

function ner_michoel_series_route_split() {
	if ( get_transient( 'nm_series_split_lock' ) ) {
		return new WP_REST_Response( array_merge( ner_michoel_series_split_status(), array( 'busy' => true ) ), 200 );
	}
	set_transient( 'nm_series_split_lock', 1, 2 * MINUTE_IN_SECONDS );
	$result = ner_michoel_series_split_run( 20, true );
	delete_transient( 'nm_series_split_lock' );
	return new WP_REST_Response( array_merge( ner_michoel_series_split_status(), array( 'run' => $result ) ), 200 );
}

function ner_michoel_series_route_split_undo() {
	if ( get_transient( 'nm_series_split_lock' ) ) {
		return new WP_REST_Response( array_merge( ner_michoel_series_split_status(), array( 'busy' => true ) ), 200 );
	}
	set_transient( 'nm_series_split_lock', 1, 2 * MINUTE_IN_SECONDS );
	$result = ner_michoel_series_split_undo( 20 );
	delete_transient( 'nm_series_split_lock' );
	return new WP_REST_Response( array_merge( ner_michoel_series_split_status(), array( 'run' => $result ) ), 200 );
}
