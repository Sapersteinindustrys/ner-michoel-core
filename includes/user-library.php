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

define( 'NER_MICHOEL_USER_LIBRARY_DB_VERSION', '1.0' );

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

	// user_post UNIQUE on both tables: history upserts (INSERT ...
	// ON DUPLICATE KEY UPDATE) instead of growing one row per visit,
	// and saved can only be toggled, never duplicated.
	$sql = "CREATE TABLE {$history_table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		user_id BIGINT UNSIGNED NOT NULL,
		post_id BIGINT UNSIGNED NOT NULL,
		post_type VARCHAR(20) NOT NULL,
		viewed_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY user_post (user_id, post_id),
		KEY user_viewed (user_id, viewed_at)
	) {$charset_collate};
CREATE TABLE {$saved_table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		user_id BIGINT UNSIGNED NOT NULL,
		post_id BIGINT UNSIGNED NOT NULL,
		post_type VARCHAR(20) NOT NULL,
		saved_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY user_post (user_id, post_id),
		KEY user_saved (user_id, saved_at)
	) {$charset_collate};";

	dbDelta( $sql );

	update_option( 'nm_user_library_db_version', NER_MICHOEL_USER_LIBRARY_DB_VERSION );
}

function ner_michoel_maybe_create_user_library_tables() {
	if ( get_option( 'nm_user_library_db_version' ) !== NER_MICHOEL_USER_LIBRARY_DB_VERSION ) {
		ner_michoel_create_user_library_tables();
	}
}
add_action( 'admin_init', 'ner_michoel_maybe_create_user_library_tables' );

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
 * "More like what you've heard": the speakers and series appearing
 * most often in the user's shiur history, then the newest shiurim
 * from those the user hasn't already seen — topped up with the
 * newest shiurim overall if that leaves fewer than $limit (a new
 * speaker/series with little else published, or a user with thin
 * history). A user with no shiur history yet gets the site's newest
 * shiurim, same as "Recent" elsewhere.
 *
 * Written shiurim aren't suggested (this is title/name-based taxonomy
 * weighting, the same signal the Shiurim sidebar/search already use —
 * nothing here needs the content itself, so it stays fast at any
 * library size without a separate recommendation index to maintain).
 */
function ner_michoel_get_suggested_for_user( $user_id, $limit = 12 ) {
	$seen_ids       = array();
	$speaker_weight = array();
	$series_weight  = array();

	foreach ( ner_michoel_get_user_history( $user_id, 50 ) as $row ) {
		$seen_ids[ (int) $row->post_id ] = true;
		if ( 'shiur' !== $row->post_type ) {
			continue;
		}

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

	$exclude   = array_keys( $seen_ids );
	$suggested = array();

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
