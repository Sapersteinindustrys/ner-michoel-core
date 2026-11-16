<?php
/**
 * Mazal Tovs from the old site (Yoni, 2026-11-15): brings the announcements listed at
 * nermichoel.org/index/news-events-mazal-tov into `mazal_tov` posts, once, from the Control
 * Panel (Announcements > Import Old Mazal Tovs) or POST ner-michoel/v1/mazal-tov-import.
 *
 * The old page lists 20 sentences, newest first, and its "Load More" asks the same address
 * with ?type=mazel&count=N for the next ones until it answers "No more results". Each one is
 * a plain sentence with no date, like
 *
 *     Mazel tov to Rabbi & Mrs. Yehoshua Siegel ('19-'24) on the birth of a baby boy!
 *
 * which is kept the way the Quick Mazal Tov form keeps one: relationship "Rabbi & Mrs.",
 * name, years ("2019-2024", like the Years List writes them) and the what with {name} in
 * it ("Mazel tov to {name} on the birth of a baby boy!"). A sentence that has no name in
 * that place ("Mazel tov on the marriage of ...") is kept whole as the title.
 *
 * Duplicates: an announcement is the same one when its name and what match, whatever the
 * years say (the Years List often gives other years than the old site). The old list has
 * real repeats (a second baby years later reads the same), so it counts: a sentence that is
 * on the old site three times and here once is added twice. Running it again adds nothing.
 *
 * Order: the old site has no dates, only its order. Each new one is dated between the ones
 * on both sides of it that are here already, so they show in the old site's order among
 * the ones posted here; the older ones go below everything posted here. Works in steps of
 * a few hundred (step 'fetch' reads the old site, step 'run' adds the next batch).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NER_MICHOEL_MAZAL_IMPORT_URL', 'https://nermichoel.org/index/news-events-mazal-tov' );

/** Where the sentences read from the old site are kept between steps. */
function ner_michoel_mazal_import_option() {
	return 'nm_mazal_tov_import_list';
}

/** One page of the old list: the sentences in it (plain text, newest first) and whether it was the last. */
function ner_michoel_mazal_import_parse( $html ) {
	$items = array();
	$last  = false !== strpos( (string) $html, 'class="error"' );
	if ( preg_match_all( '#<li[^>]*>\s*<i class="fa-li[^"]*"[^>]*>\s*</i>(.*?)</li>#si', (string) $html, $found ) ) {
		foreach ( $found[1] as $raw ) {
			$text = html_entity_decode( wp_strip_all_tags( $raw ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$text = trim( preg_replace( '/\s+/u', ' ', $text ) );
			if ( '' !== $text ) {
				$items[] = $text;
			}
		}
	}
	return array( $items, $last );
}

/** Reads the whole old list. An array of sentences, or a WP_Error. */
function ner_michoel_mazal_import_fetch() {
	$args  = array(
		'timeout'    => 30,
		'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
		'headers'    => array( 'Accept' => 'text/html,*/*' ),
	);
	$items = array();
	for ( $round = 0; $round < 300; $round++ ) {
		$url = $round ? add_query_arg( array( 'type' => 'mazel', 'count' => count( $items ) ), NER_MICHOEL_MAZAL_IMPORT_URL ) : NER_MICHOEL_MAZAL_IMPORT_URL;
		$res = wp_remote_get( $url, $args );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return new WP_Error( 'nm_mazal_import_http', sprintf( __( 'The old site answered %d.', 'ner-michoel-core' ), (int) wp_remote_retrieve_response_code( $res ) ) );
		}
		list( $found, $last ) = ner_michoel_mazal_import_parse( wp_remote_retrieve_body( $res ) );
		$items = array_merge( $items, $found );
		if ( $last || ! $found ) {
			break;
		}
	}
	if ( ! $items ) {
		return new WP_Error( 'nm_mazal_import_empty', __( 'No Mazal Tovs were found on the old site.', 'ner-michoel-core' ) );
	}
	return $items;
}

/** "'19-'24" -> "2019-2024", "'95" -> "1995"; anything else is kept with straight quotes. */
function ner_michoel_mazal_import_years( $years ) {
	$years = trim( str_replace( array( '’', '‘', '`', '′' ), "'", (string) $years ) );
	if ( ! preg_match( "/^'?(\d{2}|\d{4})(?:\s*[-–]\s*'?(\d{2}|\d{4}))?$/u", $years, $m ) ) {
		return $years;
	}
	$full = function ( $y ) {
		if ( 4 === strlen( $y ) ) {
			return $y;
		}
		return ( (int) $y > (int) gmdate( 'y' ) + 1 ? '19' : '20' ) . $y;
	};
	return $full( $m[1] ) . ( isset( $m[2] ) && '' !== $m[2] ? '-' . $full( $m[2] ) : '' );
}

/**
 * A sentence as the parts an announcement keeps: name, years, what (with {name}),
 * relationship. 'name' is '' when the sentence has no name in the usual place.
 */
function ner_michoel_mazal_import_split( $sentence ) {
	$text = str_replace( array( '’', '‘', '`' ), "'", (string) $sentence );
	$re   = "/^(Mazel|Mazal)\s+tov(?:\s+to)?\s+(?:((?:Rabbi|Dr\.?|Mr\.?)\s*(?:&|and)\s*Mrs\.?)\s+)?(.+?)(?:\s*\(([^)]*\d[^)]*)\))?\s+((?:on|upon)\b.*)$/iu";
	if ( ! preg_match( $re, $text, $m ) || preg_match( '/^(on|upon)\b/i', $m[3] ) ) {
		return array( 'name' => '', 'years' => '', 'what' => $text, 'relationship' => '' );
	}
	return array(
		'name'         => trim( $m[3] ),
		'years'        => isset( $m[4] ) ? ner_michoel_mazal_import_years( $m[4] ) : '',
		'what'         => ucfirst( strtolower( $m[1] ) ) . ' tov to {name} ' . trim( $m[5] ),
		'relationship' => trim( preg_replace( '/\s+/', ' ', $m[2] ) ),
	);
}

/** What makes two announcements the same one: the words without "Mazel tov to", the title, the years and the punctuation. */
function ner_michoel_mazal_import_key( $text ) {
	$text = strtolower( html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	$text = preg_replace( '/\([^)]*\)/', ' ', $text );
	$text = preg_replace( '/\b(maz[ae]l\s+tov(\s+to)?|rabbi|dr|mr|mrs|and)\b/', ' ', $text );
	$text = preg_replace( '/\bthe birth a\b/', 'the birth of a', $text );
	return preg_replace( '/[^a-z0-9]+/', '', $text );
}

/** The type that fits the words, if there is a term for it. */
function ner_michoel_mazal_import_type( $sentence ) {
	$s    = strtolower( $sentence );
	$name = '';
	if ( preg_match( '/bar\s*mitzvah/', $s ) ) {
		$name = 'Bar Mitzvah';
	} elseif ( preg_match( '/\b(birth|born|twins?)\b/', $s ) ) {
		$name = 'Birth';
	} elseif ( preg_match( '/engage/', $s ) ) {
		$name = 'Engagement';
	} elseif ( preg_match( '/marri|wedding/', $s ) ) {
		$name = 'Marriage';
	}
	$term = '' !== $name ? term_exists( $name, 'mazal_tov_type' ) : null;
	return $term ? (int) ( is_array( $term ) ? $term['term_id'] : $term ) : 0;
}

/**
 * The plan for the stored old list: which sentences are here already and which to add,
 * each with the date it gets. array( 'add' => list of array( sentence, timestamp ), 'here' => n ).
 */
function ner_michoel_mazal_import_plan( array $items ) {
	$posts = get_posts(
		array(
			'post_type'      => 'mazal_tov',
			'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		)
	);
	$have   = array(); // key => list of post timestamps, newest first
	$oldest = time();
	foreach ( $posts as $post ) {
		$time                                  = (int) get_post_time( 'U', true, $post );
		$have[ ner_michoel_mazal_import_key( $post->post_title ) ][] = $time;
		$oldest                                = min( $oldest, $time );
	}

	// The newest ones on the old site that are here already are the ones that match.
	$anchor = array(); // index in the old list => timestamp of the one here
	$seen   = array();
	foreach ( $items as $i => $sentence ) {
		$key          = ner_michoel_mazal_import_key( $sentence );
		$n            = isset( $seen[ $key ] ) ? $seen[ $key ] : 0;
		$seen[ $key ] = $n + 1;
		if ( isset( $have[ $key ][ $n ] ) ) {
			$anchor[ $i ] = $have[ $key ][ $n ];
		}
	}

	$add     = array();
	$indexes = array_keys( $anchor );
	$first   = $indexes ? $indexes[0] : null;
	$last    = $indexes ? end( $indexes ) : null;
	$below   = $indexes ? min( $anchor[ $last ], $oldest ) : time();
	foreach ( $items as $i => $sentence ) {
		if ( isset( $anchor[ $i ] ) ) {
			continue;
		}
		if ( null === $first ) {
			$time = time() - 60 * ( $i + 1 );
		} elseif ( $i < $first ) {
			$time = min( time() - 60, $anchor[ $first ] + 60 * ( $first - $i ) );
		} elseif ( $i > $last ) {
			$time = $below - 60 * ( $i - $last );
		} else {
			// Between the two that are here on either side of it, spread evenly.
			$newer = $first;
			$older = $last;
			foreach ( $indexes as $at ) {
				if ( $at < $i ) {
					$newer = $at;
				} elseif ( $at > $i ) {
					$older = $at;
					break;
				}
			}
			$span = $anchor[ $newer ] - $anchor[ $older ];
			$time = $anchor[ $newer ] - (int) floor( $span * ( $i - $newer ) / ( $older - $newer ) );
			if ( $time >= $anchor[ $newer ] ) {
				$time = $anchor[ $newer ] - 1;
			}
		}
		$add[] = array( $sentence, $time );
	}
	return array(
		'add'  => $add,
		'here' => count( $anchor ),
	);
}

/** Adds one announcement. The new post's ID or a WP_Error. */
function ner_michoel_mazal_import_add( $sentence, $time, $menu_order ) {
	$parts = ner_michoel_mazal_import_split( $sentence );
	$title = '' !== $parts['name'] ? ner_michoel_mazal_tov_compose_title( $parts['name'], $parts['years'], $parts['what'], $parts['relationship'] ) : $parts['what'];
	$id    = wp_insert_post(
		array(
			'post_type'     => 'mazal_tov',
			'post_status'   => 'publish',
			'post_title'    => $title,
			'post_date'     => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $time ) ),
			'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $time ),
			'menu_order'    => $menu_order,
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	if ( '' !== $parts['name'] ) {
		ner_michoel_mazal_tov_save_parts( $id, $parts );
	}
	update_post_meta( $id, '_nm_old_site_mazal', $sentence );
	$type = ner_michoel_mazal_import_type( $sentence );
	if ( $type ) {
		wp_set_object_terms( $id, array( $type ), 'mazal_tov_type' );
	}
	return $id;
}

/**
 * One step. 'fetch' reads the old site and keeps the list; 'plan' says what 'run' would do;
 * 'run' adds up to $limit of them. Returns counts, or a WP_Error.
 */
function ner_michoel_mazal_import_step( $step, $limit = 150 ) {
	if ( 'fetch' === $step ) {
		$items = ner_michoel_mazal_import_fetch();
		if ( is_wp_error( $items ) ) {
			return $items;
		}
		update_option( ner_michoel_mazal_import_option(), $items, false );
	}
	$items = get_option( ner_michoel_mazal_import_option() );
	if ( ! is_array( $items ) || ! $items ) {
		return new WP_Error( 'nm_mazal_import_not_read', __( 'Read the old site first.', 'ner-michoel-core' ) );
	}
	$plan  = ner_michoel_mazal_import_plan( $items );
	$added = 0;
	$fails = array();
	if ( 'run' === $step && $plan['add'] ) {
		// When the admin has arranged the order, the old ones go after the arranged ones.
		$menu_order = 0;
		if ( function_exists( 'ner_michoel_mazal_tov_order_is_custom' ) && ner_michoel_mazal_tov_order_is_custom() ) {
			global $wpdb;
			$menu_order = 1 + (int) $wpdb->get_var( "SELECT MAX(menu_order) FROM {$wpdb->posts} WHERE post_type = 'mazal_tov'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		wp_defer_term_counting( true );
		foreach ( array_slice( $plan['add'], 0, max( 1, (int) $limit ) ) as $row ) {
			$id = ner_michoel_mazal_import_add( $row[0], $row[1], $menu_order );
			if ( is_wp_error( $id ) ) {
				$fails[] = $row[0] . ': ' . $id->get_error_message();
			} else {
				$added++;
			}
		}
		wp_defer_term_counting( false );
	}
	return array(
		'on_old_site' => count( $items ),
		'already'     => $plan['here'],
		'added'       => $added,
		'left'        => count( $plan['add'] ) - $added,
		'failed'      => $fails,
	);
}

function ner_michoel_register_mazal_import_route() {
	register_rest_route(
		'ner-michoel/v1',
		'/mazal-tov-import',
		array(
			'methods'             => 'POST',
			'callback'            => function ( WP_REST_Request $request ) {
				$step = (string) $request->get_param( 'step' );
				if ( ! in_array( $step, array( 'fetch', 'plan', 'run' ), true ) ) {
					return new WP_Error( 'nm_mazal_import_step', __( 'Step is fetch, plan or run.', 'ner-michoel-core' ), array( 'status' => 400 ) );
				}
				if ( function_exists( 'set_time_limit' ) ) {
					@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
				$limit  = $request->get_param( 'limit' ) ? absint( $request->get_param( 'limit' ) ) : 150;
				$result = ner_michoel_mazal_import_step( $step, min( 500, max( 1, $limit ) ) );
				if ( is_wp_error( $result ) ) {
					$result->add_data( array( 'status' => 400 ) );
				}
				return $result;
			},
			'permission_callback' => function () {
				return current_user_can( 'edit_others_posts' );
			},
		)
	);
}
add_action( 'rest_api_init', 'ner_michoel_register_mazal_import_route' );

/** Control Panel > Announcements > Import Old Mazal Tovs. */
function ner_michoel_render_mazal_tov_import_page() {
	if ( ! current_user_can( 'edit_others_posts' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'ner-michoel-core' ) );
	}
	?>
	<div class="wrap nm-dashboard">
		<h1><?php esc_html_e( 'Import Old Mazal Tovs', 'ner-michoel-core' ); ?></h1>
		<p><?php esc_html_e( 'Brings the Mazal Tovs from the old site (nermichoel.org) here, in the old site’s order. Ones that are here already are skipped, so it is safe to press again.', 'ner-michoel-core' ); ?></p>
		<p>
			<button type="button" class="button button-secondary" id="nm-mti-plan"><?php esc_html_e( 'Check the old site', 'ner-michoel-core' ); ?></button>
			<button type="button" class="button button-primary" id="nm-mti-run"><?php esc_html_e( 'Import them', 'ner-michoel-core' ); ?></button>
		</p>
		<p id="nm-mti-status" aria-live="polite"></p>
	</div>
	<script>
	( function () {
		var url = <?php echo wp_json_encode( esc_url_raw( rest_url( 'ner-michoel/v1/mazal-tov-import' ) ) ); ?>;
		var nonce = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
		var out = document.getElementById( 'nm-mti-status' );
		var buttons = [ document.getElementById( 'nm-mti-plan' ), document.getElementById( 'nm-mti-run' ) ];
		function call( step ) {
			return fetch( url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce }, body: JSON.stringify( { step: step } ) } )
				.then( function ( r ) { return r.json(); } );
		}
		function say( r, done ) {
			if ( r.code ) { out.textContent = r.message; return; }
			out.textContent = r.on_old_site + ' on the old site, ' + r.already + ' here already' + ( done ? ', ' + done + ' added' : '' ) + ', ' + r.left + ' to add.' + ( r.failed && r.failed.length ? ' Not added: ' + r.failed.join( '; ' ) : '' );
		}
		function busy( on ) { buttons.forEach( function ( b ) { b.disabled = on; } ); }
		buttons[0].addEventListener( 'click', function () {
			busy( true ); out.textContent = 'Reading the old site…';
			call( 'fetch' ).then( function ( r ) { say( r ); } ).catch( function () { out.textContent = 'Could not reach the site.'; } ).then( function () { busy( false ); } );
		} );
		buttons[1].addEventListener( 'click', function () {
			var total = 0;
			busy( true ); out.textContent = 'Reading the old site…';
			function next( r ) {
				if ( r.code || ! r.added || ! r.left ) { say( r, total ); busy( false ); return; }
				say( r, total );
				return call( 'run' ).then( function ( r2 ) { total += r2.added || 0; return next( r2 ); } );
			}
			call( 'fetch' ).then( function ( r ) {
				if ( r.code ) { say( r ); busy( false ); return; }
				return call( 'run' ).then( function ( r2 ) { total += r2.added || 0; return next( r2 ); } );
			} ).catch( function () { out.textContent = 'Could not reach the site.'; busy( false ); } );
		} );
	} )();
	</script>
	<?php
}
