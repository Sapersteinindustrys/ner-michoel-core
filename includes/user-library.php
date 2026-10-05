<?php
/**
 * Per-user history ("what have I listened to / read") and saved items
 * ("my list"), plus a simple "more like what you've heard" suggestion
 * query — the data behind ner-michoel-child's Account page History /
 * Saved / Suggested tabs.
 *
 * Two small tables (not user meta: history grows per item per user,
 * and "most recently viewed, newest first" needs an indexed sort that
 * usermeta can't give without loading and sorting every row in PHP).
 * Same pattern as site-stats.php's pageviews table: dbDelta() on
 * activation, versioned via an option, with an admin_init backstop so
 * an already-active install picks up the table without a reactivate.
 *
 * History is one row per (user, post) — a visit updates viewed_at
 * rather than adding a new row, so "history" means "the shiurim
 * you've engaged with, most recent first", not a full play log.
 * Saved is the same shape, but the visitor controls it directly
 * (Save/Unsave) instead of it being recorded automatically.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NER_MICHOEL_USER_LIBRARY_DB_VERSION', '1.1' );

function ner_michoel_history_table_name() {
	global $wpdb;
	return $wpdb->prefix . 'nm_history';
}

function ner_michoel_saved_table_name() {
	global $wpdb;
	return $wpdb->prefix . 'nm_saved';
}

function ner_michoel_create_user_library_tables() {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$charset_collate = $wpdb->get_charset_collate();
	$history_table    = ner_michoel_history_table_name();
	$saved_table      = ner_michoel_saved_table_name();

	// Self-healing for exactly the bug this version fixes: if either
	// table already exists (the first, buggy version of this ran
	// before), it may already hold duplicate (user_id, post_id) rows —
	// saved from toggling with no UNIQUE KEY to stop it, history from
	// the INSERT ... ON DUPLICATE KEY UPDATE upsert silently behaving
	// as a plain INSERT for the same reason — which MySQL will then
	// refuse to add that key over. Keeps the newest row per pair,
	// removes the rest. A no-op on a fresh install (neither table
	// exists yet) or once this has already run once (no duplicates
	// left to find).
	foreach ( array( $history_table, $saved_table ) as $table ) {
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"DELETE older FROM {$table} older
				INNER JOIN {$table} newer
					ON older.user_id = newer.user_id
					AND older.post_id = newer.post_id
					AND older.id < newer.id"
			);
		}
	}

	// user_post UNIQUE on both tables: history upserts (INSERT ...
	// ON DUPLICATE KEY UPDATE) instead of growing one row per visit,
	// and saved can only be toggled, never duplicated.
	//
	// Two separate dbDelta() calls, not one call with both CREATE TABLE
	// statements concatenated: dbDelta() parses each statement in the
	// string it's given to find the table name and diff it against
	// what exists, and that parsing is documented as needing a blank
	// line between statements when more than one is passed together.
	// Without it, the first version of this shipped the two statements
	// back-to-back with a single newline — dbDelta still created both
	// tables (no visible error; it fails quiet, not loud), but silently
	// dropped the second table's UNIQUE KEY, so Saved could never
	// actually detect an existing row and toggling the same item kept
	// inserting a new one instead of removing it. Bumped
	// NER_MICHOEL_USER_LIBRARY_DB_VERSION so this corrected version
	// re-runs dbDelta on an already-active install and adds the
	// missing key to the existing table — dbDelta does that safely,
	// without dropping the (sparse, this early) data already in it.
	dbDelta(
		"CREATE TABLE {$history_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			post_id BIGINT UNSIGNED NOT NULL,
			post_type VARCHAR(20) NOT NULL,
			viewed_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_post (user_id, post_id),
			KEY user_viewed (user_id, viewed_at)
		) {$charset_collate};"
	);

	dbDelta(
		"CREATE TABLE {$saved_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			post_id BIGINT UNSIGNED NOT NULL,
			post_type VARCHAR(20) NOT NULL,
			saved_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_post (user_id, post_id),
			KEY user_saved (user_id, saved_at)
		) {$charset_collate};"
	);

	update_option( 'nm_user_library_db_version', NER_MICHOEL_USER_LIBRARY_DB_VERSION );
}

function ner_michoel_maybe_create_user_library_tables() {
	if ( get_option( 'nm_user_library_db_version' ) !== NER_MICHOEL_USER_LIBRARY_DB_VERSION ) {
		ner_michoel_create_user_library_tables();
	}
}
// init, not admin_init (unlike the otherwise-identical pageviews-table
// backstop this was modeled on): a visitor could hit the Save button
// on the front end before any admin happens to next load a wp-admin
// screen, and a REST deploy (ner-michoel/v1/update-now) never fires
// admin_init at all — same reasoning already documented at
// ner_michoel_maybe_flush_written_rewrite() in written-shiurim.php,
// which chose init for the same reason.
add_action( 'init', 'ner_michoel_maybe_create_user_library_tables', 20 );

/**
 * Records (or re-dates) one history row. Silently does nothing for a
 * logged-out visitor (user_id 0) or an unpublished/invalid post — the
 * two REST routes below are the only callers and already check both,
 * but this stays safe to call directly too.
 */
function ner_michoel_record_history( $user_id, $post_id ) {
	$user_id = (int) $user_id;
	$post_id = (int) $post_id;
	if ( ! $user_id || ! $post_id ) {
		return;
	}

	$post_type = get_post_type( $post_id );
	if ( ! in_array( $post_type, array( 'shiur', 'written_shiur' ), true ) || 'publish' !== get_post_status( $post_id ) ) {
		return;
	}

	global $wpdb;
	$table = ner_michoel_history_table_name();
	$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->prepare(
			"INSERT INTO {$table} (user_id, post_id, post_type, viewed_at) VALUES (%d, %d, %s, %s)
			ON DUPLICATE KEY UPDATE viewed_at = VALUES(viewed_at)",
			$user_id,
			$post_id,
			$post_type,
			current_time( 'mysql' )
		)
	);
}

/**
 * A user's history, most recently viewed first. Each row has post_id,
 * post_type, viewed_at.
 */
function ner_michoel_get_user_history( $user_id, $limit = 20 ) {
	global $wpdb;
	$table = ner_michoel_history_table_name();
	$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->prepare(
			"SELECT post_id, post_type, viewed_at FROM {$table} WHERE user_id = %d ORDER BY viewed_at DESC LIMIT %d",
			(int) $user_id,
			(int) $limit
		)
	);
	return $rows ? $rows : array();
}

function ner_michoel_is_post_saved_by_user( $post_id, $user_id = 0 ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();
	if ( ! $user_id ) {
		return false;
	}
	global $wpdb;
	$table = ner_michoel_saved_table_name();
	return (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->prepare(
			"SELECT 1 FROM {$table} WHERE user_id = %d AND post_id = %d",
			$user_id,
			(int) $post_id
		)
	);
}

/**
 * Adds or removes a saved row depending on its current state. Returns
 * the new state (true = now saved), or null if the post isn't a
 * shiur/written_shiur — the REST route turns that into an error.
 */
function ner_michoel_toggle_saved( $user_id, $post_id ) {
	$user_id = (int) $user_id;
	$post_id = (int) $post_id;

	$post_type = get_post_type( $post_id );
	if ( ! in_array( $post_type, array( 'shiur', 'written_shiur' ), true ) ) {
		return null;
	}

	global $wpdb;
	$table = ner_michoel_saved_table_name();

	if ( ner_michoel_is_post_saved_by_user( $post_id, $user_id ) ) {
		$wpdb->delete( $table, array( 'user_id' => $user_id, 'post_id' => $post_id ), array( '%d', '%d' ) );
		return false;
	}

	$wpdb->insert(
		$table,
		array(
			'user_id'   => $user_id,
			'post_id'   => $post_id,
			'post_type' => $post_type,
			'saved_at'  => current_time( 'mysql' ),
		),
		array( '%d', '%d', '%s', '%s' )
	);
	return true;
}

/**
 * A user's saved items, most recently saved first. $post_type narrows
 * to 'shiur' or 'written_shiur'; '' (default) returns both.
 */
function ner_michoel_get_user_saved( $user_id, $post_type = '', $limit = 0 ) {
	global $wpdb;
	$table = ner_michoel_saved_table_name();

	if ( $post_type ) {
		$sql  = "SELECT post_id, post_type, saved_at FROM {$table} WHERE user_id = %d AND post_type = %s ORDER BY saved_at DESC";
		$args = array( (int) $user_id, $post_type );
	} else {
		$sql  = "SELECT post_id, post_type, saved_at FROM {$table} WHERE user_id = %d ORDER BY saved_at DESC";
		$args = array( (int) $user_id );
	}

	if ( $limit > 0 ) {
		$sql   .= ' LIMIT %d';
		$args[] = (int) $limit;
	}

	$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	return $rows ? $rows : array();
}

/**
 * How strongly a signed-in visitor is tied to each speaker/series,
 * from two signals: shiurim in their History (something they actually
 * listened to) and shiurim in their Saved list (something they picked
 * out deliberately, even if not heard yet) — both count, on the
 * theory that either one says something real about what this visitor
 * is interested in. Shared by the Suggested tab
 * (ner_michoel_get_suggested_for_user()) and the signed-in Next Up
 * boost (ner-michoel-child's next-up.php), so the two don't drift
 * into computing "what this visitor likes" two different ways.
 *
 * Returns speaker/series weights (term_id => count, unsorted) and
 * heard_ids — shiur IDs from History specifically, for a caller that
 * wants to exclude what's already been listened to. Written shiurim
 * never contribute a weight (series/speaker weighting is enough of a
 * signal on its own, and this stays fast without needing to look past
 * taxonomy terms at any library size).
 */
function ner_michoel_user_affinity_weights( $user_id, $history_limit = 50 ) {
	$speaker_weight = array();
	$series_weight  = array();
	$heard_ids      = array();

	if ( ! $user_id ) {
		return array( 'speaker' => $speaker_weight, 'series' => $series_weight, 'heard_ids' => $heard_ids );
	}

	foreach ( ner_michoel_get_user_history( $user_id, $history_limit ) as $row ) {
		if ( 'shiur' !== $row->post_type ) {
			continue;
		}
		$heard_ids[] = (int) $row->post_id;

		$speaker_terms = get_the_terms( $row->post_id, 'speaker' );
		if ( $speaker_terms && ! is_wp_error( $speaker_terms ) ) {
			foreach ( $speaker_terms as $term ) {
				$speaker_weight[ $term->term_id ] = ( isset( $speaker_weight[ $term->term_id ] ) ? $speaker_weight[ $term->term_id ] : 0 ) + 1;
			}
		}

		$series_terms = get_the_terms( $row->post_id, 'series' );
		if ( $series_terms && ! is_wp_error( $series_terms ) ) {
			foreach ( $series_terms as $term ) {
				$series_weight[ $term->term_id ] = ( isset( $series_weight[ $term->term_id ] ) ? $series_weight[ $term->term_id ] : 0 ) + 1;
			}
		}
	}

	foreach ( ner_michoel_get_user_saved( $user_id, 'shiur' ) as $row ) {
		$speaker_terms = get_the_terms( $row->post_id, 'speaker' );
		if ( $speaker_terms && ! is_wp_error( $speaker_terms ) ) {
			foreach ( $speaker_terms as $term ) {
				$speaker_weight[ $term->term_id ] = ( isset( $speaker_weight[ $term->term_id ] ) ? $speaker_weight[ $term->term_id ] : 0 ) + 1;
			}
		}

		$series_terms = get_the_terms( $row->post_id, 'series' );
		if ( $series_terms && ! is_wp_error( $series_terms ) ) {
			foreach ( $series_terms as $term ) {
				$series_weight[ $term->term_id ] = ( isset( $series_weight[ $term->term_id ] ) ? $series_weight[ $term->term_id ] : 0 ) + 1;
			}
		}
	}

	return array(
		'speaker'   => $speaker_weight,
		'series'    => $series_weight,
		'heard_ids' => array_values( array_unique( $heard_ids ) ),
	);
}

/**
 * "More like what you've heard (and saved)": the speakers and series
 * this visitor is most tied to (ner_michoel_user_affinity_weights()),
 * then the newest shiurim from those they haven't already heard —
 * topped up with the newest shiurim overall if that leaves fewer than
 * $limit (a new speaker/series with little else published, or a
 * visitor with thin history/saves). Nothing in either signal yet: the
 * site's newest shiurim, same as "Recent" elsewhere.
 *
 * Written shiurim aren't suggested (this is title/name-based taxonomy
 * weighting, the same signal the Shiurim sidebar/search already use —
 * nothing here needs the content itself, so it stays fast at any
 * library size without a separate recommendation index to maintain).
 */
function ner_michoel_get_suggested_for_user( $user_id, $limit = 12 ) {
	$affinity       = ner_michoel_user_affinity_weights( $user_id );
	$speaker_weight = $affinity['speaker'];
	$series_weight  = $affinity['series'];
	$exclude        = $affinity['heard_ids'];
	$suggested      = array();

	if ( $speaker_weight || $series_weight ) {
		arsort( $speaker_weight );
		arsort( $series_weight );

		$tax_query = array( 'relation' => 'OR' );
		if ( $speaker_weight ) {
			$tax_query[] = array(
				'taxonomy' => 'speaker',
				'field'    => 'term_id',
				'terms'    => array_slice( array_keys( $speaker_weight ), 0, 3 ),
			);
		}
		if ( $series_weight ) {
			$tax_query[] = array(
				'taxonomy' => 'series',
				'field'    => 'term_id',
				'terms'    => array_slice( array_keys( $series_weight ), 0, 3 ),
			);
		}

		$suggested = get_posts(
			array(
				'post_type'      => 'shiur',
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'post__not_in'   => $exclude,
				'tax_query'      => $tax_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);
	}

	if ( count( $suggested ) < $limit ) {
		$already = array_merge( $exclude, wp_list_pluck( $suggested, 'ID' ) );
		$topped_up = get_posts(
			array(
				'post_type'      => 'shiur',
				'post_status'    => 'publish',
				'posts_per_page' => $limit - count( $suggested ),
				'post__not_in'   => $already,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);
		$suggested = array_merge( $suggested, $topped_up );
	}

	return $suggested;
}

/**
 * REST routes.
 */
function ner_michoel_register_user_library_routes() {
	register_rest_route(
		'ner-michoel/v1',
		'/saved-toggle',
		array(
			'methods'             => 'POST',
			'callback'            => 'ner_michoel_handle_saved_toggle',
			'permission_callback' => function () {
				return is_user_logged_in();
			},
		)
	);

	register_rest_route(
		'ner-michoel/v1',
		'/history-record',
		array(
			'methods'             => 'POST',
			'callback'            => 'ner_michoel_handle_history_record',
			'permission_callback' => function () {
				return is_user_logged_in();
			},
		)
	);
}
add_action( 'rest_api_init', 'ner_michoel_register_user_library_routes' );

function ner_michoel_handle_saved_toggle( WP_REST_Request $request ) {
	$post_id = absint( $request->get_param( 'post_id' ) );
	if ( ! $post_id ) {
		return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Missing post.', 'ner-michoel-core' ) ), 400 );
	}

	$saved = ner_michoel_toggle_saved( get_current_user_id(), $post_id );
	if ( null === $saved ) {
		return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Not something that can be saved.', 'ner-michoel-core' ) ), 400 );
	}

	return new WP_REST_Response( array( 'success' => true, 'saved' => $saved ), 200 );
}

/**
 * Written shiurim only — a shiur (audio/video) records history through
 * ner_michoel_handle_shiur_event()'s 'play' event instead (shiur-
 * stats.php), which the player already pings, so listening records
 * history for free with no extra request. A written shiur has no
 * "play"; this is called when the visitor opens the PDF viewer
 * ("Read here" — custom.js), which is a deliberate reading action the
 * way a page visit alone isn't.
 */
function ner_michoel_handle_history_record( WP_REST_Request $request ) {
	$post_id = absint( $request->get_param( 'post_id' ) );
	if ( ! $post_id || 'written_shiur' !== get_post_type( $post_id ) ) {
		return new WP_REST_Response( array( 'success' => false ), 400 );
	}

	ner_michoel_record_history( get_current_user_id(), $post_id );
	return new WP_REST_Response( array( 'success' => true ), 200 );
}
