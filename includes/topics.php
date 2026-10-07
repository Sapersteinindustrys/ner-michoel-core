<?php
/**
 * Topics: tags for shiurim and written shiurim. A shiur can have several, so it
 * sits in every category it belongs to. The old site joined a shiur's categories
 * into one name, which came in as its series ("Gemara / Shabbos / Perek 2 /
 * Moadim / Chanukah"); as topics that shiur is under Gemara, Shabbos, Moadim and
 * Chanukah at once.
 *
 * A topic can also show on the homepage: always, or every year between two
 * Hebrew dates (the seasonal boxes, hebrew-calendar.php). The theme gets the
 * ones showing today from ner_michoel_get_active_seasons(). Set them in the Site
 * Control Panel, Content → Topics.
 *
 * Existing shiurim get their topics from their series names once, in the
 * background after this update (ner_michoel_topics_seed_tick()), and the main
 * holidays get default dates. New imports get topics as they come in
 * (library-import.php). Shiurim added by hand get theirs in the shiur form.
 *
 * Term meta: nm_home ('' = not on the homepage, 'always', 'season'), and for a
 * season nm_season_from_month / nm_season_from_day / nm_season_to_month /
 * nm_season_to_day (a month slug from ner_michoel_hebrew_months(), a day 'd01'-'d30':
 * not a plain number, so the Site Control Panel keeps its day list in order).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NER_MICHOEL_TOPICS_SEED_VERSION', '1' );
define( 'NER_MICHOEL_TOPICS_REWRITE_VERSION', '1' );

function ner_michoel_register_topic_taxonomy() {
	register_taxonomy(
		'topic',
		array( 'shiur', 'written_shiur' ),
		array(
			'labels'            => array(
				'name'                       => __( 'Topics', 'ner-michoel-core' ),
				'singular_name'              => __( 'Topic', 'ner-michoel-core' ),
				'add_new_item'               => __( 'Add New Topic', 'ner-michoel-core' ),
				'edit_item'                  => __( 'Edit Topic', 'ner-michoel-core' ),
				'search_items'               => __( 'Search Topics', 'ner-michoel-core' ),
				'all_items'                  => __( 'All Topics', 'ner-michoel-core' ),
				'popular_items'              => __( 'Popular Topics', 'ner-michoel-core' ),
				'separate_items_with_commas' => __( 'Separate topics with commas', 'ner-michoel-core' ),
				'choose_from_most_used'      => __( 'Choose from the most used topics', 'ner-michoel-core' ),
				'not_found'                  => __( 'No topics found.', 'ner-michoel-core' ),
			),
			'hierarchical'      => false,
			'public'            => true,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'rewrite'           => array( 'slug' => 'topic', 'with_front' => false ),
		)
	);
}
add_action( 'init', 'ner_michoel_register_topic_taxonomy' );

/**
 * /topic/<slug>/ needs the rewrite rules flushed once. An update doesn't run the
 * activation hook, so do it here the first time this version loads.
 */
function ner_michoel_maybe_flush_topic_rewrite() {
	if ( get_option( 'nm_topic_rewrite_version' ) !== NER_MICHOEL_TOPICS_REWRITE_VERSION ) {
		flush_rewrite_rules( false );
		update_option( 'nm_topic_rewrite_version', NER_MICHOEL_TOPICS_REWRITE_VERSION );
	}
}
add_action( 'init', 'ner_michoel_maybe_flush_topic_rewrite', 99 );

/* ------------------------------------------------------------------
 * Homepage seasons.
 * ------------------------------------------------------------------ */

function ner_michoel_topic_home_settings( $term_id ) {
	return array(
		'mode'       => (string) get_term_meta( $term_id, 'nm_home', true ),
		'from_month' => (string) get_term_meta( $term_id, 'nm_season_from_month', true ),
		'from_day'   => ner_michoel_topic_day( get_term_meta( $term_id, 'nm_season_from_day', true ) ),
		'to_month'   => (string) get_term_meta( $term_id, 'nm_season_to_month', true ),
		'to_day'     => ner_michoel_topic_day( get_term_meta( $term_id, 'nm_season_to_day', true ) ),
	);
}

/**
 * A stored day ('d05', the Site Control Panel's option key) as a number, 0 when unset.
 */
function ner_michoel_topic_day( $raw ) {
	return (int) preg_replace( '/\D+/', '', (string) $raw );
}

/**
 * A day number in the stored form, the same as the panel's option keys.
 */
function ner_michoel_topic_day_key( $day ) {
	return sprintf( 'd%02d', (int) $day );
}

/**
 * A topic's dates as text, such as "11–23 Tishrei" or "1 Elul – 10 Tishrei", or
 * '' when it isn't set to a season or the dates are incomplete.
 */
function ner_michoel_topic_season_label( $term_id ) {
	$s      = ner_michoel_topic_home_settings( $term_id );
	$months = ner_michoel_hebrew_months();
	if ( 'season' !== $s['mode'] || ! isset( $months[ $s['from_month'] ], $months[ $s['to_month'] ] ) || ! $s['from_day'] || ! $s['to_day'] ) {
		return '';
	}

	if ( $s['from_month'] === $s['to_month'] && $s['from_day'] <= $s['to_day'] ) {
		/* translators: 1: first day, 2: last day, 3: Hebrew month */
		return sprintf( __( '%1$d–%2$d %3$s', 'ner-michoel-core' ), $s['from_day'], $s['to_day'], $months[ $s['from_month'] ] );
	}
	/* translators: 1: first day, 2: its Hebrew month, 3: last day, 4: its Hebrew month */
	return sprintf( __( '%1$d %2$s – %3$d %4$s', 'ner-michoel-core' ), $s['from_day'], $months[ $s['from_month'] ], $s['to_day'], $months[ $s['to_month'] ] );
}

/**
 * What a topic does on the homepage, in a few words: "Always", its dates, or ''.
 * For the Topics list in the Site Control Panel and in wp-admin.
 */
function ner_michoel_topic_home_summary( $term ) {
	$term_id = is_object( $term ) ? $term->term_id : (int) $term;
	$mode    = (string) get_term_meta( $term_id, 'nm_home', true );
	if ( 'always' === $mode ) {
		return __( 'Always', 'ner-michoel-core' );
	}
	if ( 'season' === $mode ) {
		$label = ner_michoel_topic_season_label( $term_id );
		return $label ? $label : __( 'Dates missing', 'ner-michoel-core' );
	}
	return '';
}

/**
 * Today's fixed day number (hebrew-calendar.php), in the site's timezone.
 * Someone who can edit can preview another day with ?nm_season_date=YYYY-MM-DD.
 * Logged-in pages aren't cached, so no visitor ever sees a preview.
 */
function ner_michoel_season_today() {
	$date = current_time( 'Y-m-d' );

	if ( isset( $_GET['nm_season_date'] ) && current_user_can( 'edit_posts' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only preview.
		$preview = sanitize_text_field( wp_unslash( $_GET['nm_season_date'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $preview, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			$date = $preview;
		}
	}

	list( $year, $month, $day ) = array_map( 'intval', explode( '-', $date ) );
	return ner_michoel_fixed_from_gregorian( $year, $month, $day );
}

/**
 * The topics showing on the homepage today, for the theme's seasonal boxes.
 * Seasons whose dates include today come first, the one that started most
 * recently first; then the topics set to "always", by name. A topic with no
 * published shiurim is left out.
 *
 * Each entry: name, slug, link (the topic page), posts (WP_Post[], shiurim and
 * written shiurim, newest first, up to $per_topic), and also term_id, count (all
 * its published shiurim), label (its dates, or '') and mode ('season' or
 * 'always'). An empty array when nothing is showing.
 */
function ner_michoel_get_active_seasons( $per_topic = 6 ) {
	$terms = get_terms(
		array(
			'taxonomy'   => 'topic',
			'hide_empty' => true,
			'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- a handful of topics.
				array(
					'key'     => 'nm_home',
					'value'   => array( 'always', 'season' ),
					'compare' => 'IN',
				),
			),
		)
	);
	if ( is_wp_error( $terms ) || ! $terms ) {
		return array();
	}

	$today  = ner_michoel_season_today();
	$chosen = array();
	foreach ( $terms as $term ) {
		$s = ner_michoel_topic_home_settings( $term->term_id );
		if ( 'season' === $s['mode'] ) {
			$started = ner_michoel_hebrew_window_start( $s['from_month'], $s['from_day'], $s['to_month'], $s['to_day'], $today );
			if ( null === $started ) {
				continue;
			}
			$chosen[] = array( $term, 'season', 0, -$started );
		} elseif ( 'always' === $s['mode'] ) {
			$chosen[] = array( $term, 'always', 1, 0 );
		}
	}

	usort(
		$chosen,
		function ( $a, $b ) {
			return array( $a[2], $a[3], $a[0]->name ) <=> array( $b[2], $b[3], $b[0]->name );
		}
	);

	$out = array();
	foreach ( $chosen as $entry ) {
		$term  = $entry[0];
		$posts = get_posts(
			array(
				'post_type'           => array( 'shiur', 'written_shiur' ),
				'post_status'         => 'publish',
				'posts_per_page'      => (int) $per_topic,
				'orderby'             => 'date',
				'order'               => 'DESC',
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
				'tax_query'           => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array(
						'taxonomy' => 'topic',
						'field'    => 'term_id',
						'terms'    => $term->term_id,
					),
				),
			)
		);
		if ( ! $posts ) {
			continue;
		}

		$link  = get_term_link( $term );
		$out[] = array(
			'name'    => $term->name,
			'slug'    => $term->slug,
			'link'    => is_wp_error( $link ) ? '' : $link,
			'posts'   => $posts,
			'term_id' => (int) $term->term_id,
			'count'   => (int) $term->count,
			'label'   => ner_michoel_topic_season_label( $term->term_id ),
			'mode'    => $entry[1],
		);
	}
	return $out;
}

/* ------------------------------------------------------------------
 * The topic page's list.
 * ------------------------------------------------------------------ */

/**
 * A topic page lists its shiurim (audio and video) newest first, 40 a page:
 * some topics have over a thousand. The theme lists the topic's written
 * shiurim in a section of their own (taxonomy-topic.php).
 */
function ner_michoel_topic_archive_query( $query ) {
	if ( is_admin() || ! $query->is_main_query() || ! $query->is_tax( 'topic' ) ) {
		return;
	}
	$query->set( 'post_type', 'shiur' );
	$query->set( 'posts_per_page', 40 );
	$query->set( 'orderby', 'date' );
	$query->set( 'order', 'DESC' );
}
add_action( 'pre_get_posts', 'ner_michoel_topic_archive_query' );

/* ------------------------------------------------------------------
 * Topics from the old categories.
 * ------------------------------------------------------------------ */

/**
 * The topic names in a series name: each part between " / ", without "Perek …"
 * parts (a perek means nothing without its masechta, which is a topic of its
 * own) and without bare numbers. Case doesn't make two topics.
 */
function ner_michoel_topic_names_from_series( $series_name ) {
	$name  = html_entity_decode( (string) $series_name, ENT_QUOTES, 'UTF-8' );
	$names = array();
	foreach ( preg_split( '#\s+/\s+#u', $name ) as $part ) {
		$part = trim( (string) preg_replace( '/\s+/u', ' ', $part ) );
		if ( '' === $part || preg_match( '/^perek\b/i', $part ) || is_numeric( $part ) ) {
			continue;
		}
		$key = function_exists( 'mb_strtolower' ) ? mb_strtolower( $part, 'UTF-8' ) : strtolower( $part );
		if ( ! isset( $names[ $key ] ) ) {
			$names[ $key ] = $part;
		}
	}
	return array_values( $names );
}

/**
 * Topic term IDs for names, making the topics that don't exist yet. Names are
 * looked up rather than passed to wp_set_object_terms(), which would read a
 * name like "2" as a term ID.
 */
function ner_michoel_topic_ids_for_names( $names ) {
	$ids = array();
	foreach ( (array) $names as $name ) {
		$found = term_exists( $name, 'topic' );
		if ( ! $found ) {
			$found = wp_insert_term( $name, 'topic' );
			if ( is_wp_error( $found ) ) {
				$existing = $found->get_error_data( 'term_exists' );
				if ( $existing ) {
					$ids[] = (int) $existing;
				}
				continue;
			}
		}
		$ids[] = (int) ( is_array( $found ) ? $found['term_id'] : $found );
	}
	return array_values( array_unique( array_filter( $ids ) ) );
}

/**
 * Adds the topics in a series name to a post, keeping any topics it already has.
 */
function ner_michoel_add_topics_from_series( $post_id, $series_name ) {
	$ids = ner_michoel_topic_ids_for_names( ner_michoel_topic_names_from_series( $series_name ) );
	if ( $ids ) {
		wp_set_object_terms( (int) $post_id, $ids, 'topic', true );
	}
}

/**
 * Default dates for the holiday topics. Only applied to a topic that hasn't been
 * given a homepage setting yet, so a choice made in the Site Control Panel stays.
 */
function ner_michoel_topics_default_seasons() {
	return array(
		'Rosh Hashanah'       => array( 'elul', 1, 'tishrei', 2 ),
		'Yom Kippur'          => array( 'tishrei', 3, 'tishrei', 10 ),
		'Sukkos'              => array( 'tishrei', 11, 'tishrei', 23 ),
		'Chanukah'            => array( 'kislev', 1, 'teves', 3 ),
		"Asara B'Teves"       => array( 'teves', 4, 'teves', 10 ),
		'Purim'               => array( 'adar', 1, 'adar', 15 ),
		'Pesach'              => array( 'adar', 16, 'nisan', 22 ),
		'Sefiras Haomer'      => array( 'nisan', 23, 'iyar', 29 ),
		'Shavuos'             => array( 'sivan', 1, 'sivan', 7 ),
		'Tisha Bav & 3 Weeks' => array( 'tammuz', 17, 'av', 10 ),
	);
}

function ner_michoel_topics_apply_default_seasons() {
	foreach ( ner_michoel_topics_default_seasons() as $name => $window ) {
		$found = term_exists( $name, 'topic' );
		if ( ! $found ) {
			continue;
		}
		$term_id = (int) ( is_array( $found ) ? $found['term_id'] : $found );
		if ( '' !== (string) get_term_meta( $term_id, 'nm_home', true ) ) {
			continue;
		}
		update_term_meta( $term_id, 'nm_home', 'season' );
		update_term_meta( $term_id, 'nm_season_from_month', $window[0] );
		update_term_meta( $term_id, 'nm_season_from_day', ner_michoel_topic_day_key( $window[1] ) );
		update_term_meta( $term_id, 'nm_season_to_month', $window[2] );
		update_term_meta( $term_id, 'nm_season_to_day', ner_michoel_topic_day_key( $window[3] ) );
	}
}

/**
 * One run of the seeding: goes through the series terms in term ID order and
 * gives each of their shiurim the series name's topics, until the time budget is
 * used, then schedules the next run. Resumes where it stopped (the series term
 * and the position in its shiurim). When done, applies the holiday dates.
 */
function ner_michoel_topics_seed_tick() {
	if ( NER_MICHOEL_TOPICS_SEED_VERSION === get_option( 'nm_topics_seed_version' ) ) {
		return;
	}
	// One run at a time: a second request could schedule another run while this one works.
	if ( get_transient( 'nm_topics_seed_lock' ) ) {
		return;
	}
	set_transient( 'nm_topics_seed_lock', 1, 2 * MINUTE_IN_SECONDS );

	global $wpdb;
	$deadline = time() + ( function_exists( 'ner_michoel_import_tick_seconds' ) ? ner_michoel_import_tick_seconds() : 20 );
	$after    = (int) get_option( 'nm_topics_seed_cursor', 0 );
	$offset   = (int) get_option( 'nm_topics_seed_offset', 0 );

	while ( time() < $deadline ) {
		$term = $wpdb->get_row( $wpdb->prepare( "SELECT t.term_id, t.name FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy = 'series' AND t.term_id > %d ORDER BY t.term_id ASC LIMIT 1", $after ) );

		if ( ! $term ) {
			ner_michoel_topics_apply_default_seasons();
			update_option( 'nm_topics_seed_version', NER_MICHOEL_TOPICS_SEED_VERSION );
			delete_option( 'nm_topics_seed_cursor' );
			delete_option( 'nm_topics_seed_offset' );
			delete_transient( 'nm_topics_seed_lock' );
			return;
		}

		$ids = ner_michoel_topic_ids_for_names( ner_michoel_topic_names_from_series( $term->name ) );
		if ( $ids ) {
			$objects = array_map( 'intval', (array) get_objects_in_term( (int) $term->term_id, 'series' ) );
			sort( $objects );
			$total = count( $objects );
			for ( $i = $offset; $i < $total; $i++ ) {
				if ( time() >= $deadline ) {
					update_option( 'nm_topics_seed_cursor', $after, false );
					update_option( 'nm_topics_seed_offset', $i, false );
					delete_transient( 'nm_topics_seed_lock' );
					wp_schedule_single_event( time() + 5, 'nm_topics_seed' );
					return;
				}
				wp_set_object_terms( $objects[ $i ], $ids, 'topic', true );
			}
		}

		$after  = (int) $term->term_id;
		$offset = 0;
		update_option( 'nm_topics_seed_cursor', $after, false );
		update_option( 'nm_topics_seed_offset', 0, false );
	}

	delete_transient( 'nm_topics_seed_lock' );
	wp_schedule_single_event( time() + 5, 'nm_topics_seed' );
}
add_action( 'nm_topics_seed', 'ner_michoel_topics_seed_tick' );

/**
 * Starts the seeding in the background after this version is installed. Cheap on
 * every load: one autoloaded option, and the event check.
 */
function ner_michoel_topics_ensure_seed() {
	if ( NER_MICHOEL_TOPICS_SEED_VERSION !== get_option( 'nm_topics_seed_version' ) && ! wp_next_scheduled( 'nm_topics_seed' ) ) {
		wp_schedule_single_event( time() + 30, 'nm_topics_seed' );
	}
}
add_action( 'init', 'ner_michoel_topics_ensure_seed', 20 );

/* ------------------------------------------------------------------
 * wp-admin: the Topics list shows what each one does on the homepage.
 * ------------------------------------------------------------------ */

function ner_michoel_topic_admin_columns( $columns ) {
	$columns['nm_home'] = __( 'On the homepage', 'ner-michoel-core' );
	return $columns;
}
add_filter( 'manage_edit-topic_columns', 'ner_michoel_topic_admin_columns' );

function ner_michoel_topic_admin_column( $content, $column, $term_id ) {
	if ( 'nm_home' === $column ) {
		$summary = ner_michoel_topic_home_summary( $term_id );
		$content = $summary ? esc_html( $summary ) : '—';
	}
	return $content;
}
add_filter( 'manage_topic_custom_column', 'ner_michoel_topic_admin_column', 10, 3 );

function ner_michoel_topic_admin_note() {
	echo '<p class="description">' . esc_html__( 'To show a topic on the homepage, always or between Hebrew dates each year, edit it in the Site Control Panel: Content → Topics.', 'ner-michoel-core' ) . '</p>';
}
add_action( 'topic_add_form', 'ner_michoel_topic_admin_note' );
