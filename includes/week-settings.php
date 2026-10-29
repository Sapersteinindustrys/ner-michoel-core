<?php
/**
 * What the homepage's "This week" section is told to show, set under Homepage & Look >
 * This Week in the Control Panel.
 *
 * The section has two boxes (the theme's inc/week.php): this week's parsha (parsha.php) and a
 * second one, the Yom Tov in season (topics.php). By themselves they follow the Hebrew
 * calendar. Here an editor can:
 *
 *   - choose by hand what a box shows: a parsha for the first box; any topic (Succos, Chanukah,
 *     Halacha ...) or series for the second. Handy before a Yom Tov, to put its shiurim up
 *     early, and on a Shabbos that is Yom Tov or Chol Hamoed, which has no parsha.
 *   - hide a box;
 *   - say until which day a hand-made choice stays, after which the box goes back to the
 *     calendar by itself (a choice with no day stays until it is changed);
 *   - say which reading the homepage shows first, Chutz La'aretz's or Eretz Yisroel's, and
 *     whether visitors get buttons to switch in the weeks the two read different parshiyos.
 *
 * Stored in one option, nm_week_settings:
 *   parsha / season: 'auto', 'hide', or what was chosen: 'p:22' or 'p:22,23' (parsha numbers,
 *                    parsha.php), 't:<term id>' (a topic), 's:<term id>' (a series)
 *   parsha_until / season_until: '' or YYYY-MM-DD (the last day the choice holds)
 *   region: 'diaspora' or 'israel'
 *   toggle: whether visitors get the switch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ner_michoel_week_settings_defaults() {
	return array(
		'parsha'       => 'auto',
		'parsha_until' => '',
		'season'       => 'auto',
		'season_until' => '',
		'region'       => 'diaspora',
		'toggle'       => true,
	);
}

/**
 * The settings as they hold today: a choice whose last day has passed is 'auto' again, and a day
 * means nothing without a choice. Today is the site's date (an editor's ?nm_season_date= preview
 * moves it, topics.php).
 *
 * @return array
 */
function ner_michoel_week_settings() {
	$defaults = ner_michoel_week_settings_defaults();
	$saved    = get_option( 'nm_week_settings', array() );
	$out      = array_merge( $defaults, is_array( $saved ) ? array_intersect_key( $saved, $defaults ) : array() );
	$today    = ner_michoel_season_today_ymd();

	foreach ( array( 'parsha', 'season' ) as $slot ) {
		$out[ $slot ] = is_string( $out[ $slot ] ) && '' !== $out[ $slot ] ? $out[ $slot ] : 'auto';
		$until        = ( is_string( $out[ $slot . '_until' ] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $out[ $slot . '_until' ] ) ) ? $out[ $slot . '_until' ] : '';
		if ( '' !== $until && $until < $today ) {
			$out[ $slot ] = 'auto';
		}
		$out[ $slot . '_until' ] = ( 'auto' === $out[ $slot ] ) ? '' : $until;
	}
	$out['region'] = ( 'israel' === $out['region'] ) ? 'israel' : 'diaspora';
	$out['toggle'] = (bool) $out['toggle'];
	return $out;
}

/**
 * Whether the homepage shows Eretz Yisroel's reading first. The screen's choice; the filter
 * 'ner_michoel_parsha_israel' (parsha.php) can still decide in code.
 */
function ner_michoel_week_default_israel() {
	$settings = ner_michoel_week_settings();
	return (bool) apply_filters( 'ner_michoel_parsha_israel', 'israel' === $settings['region'] );
}

/**
 * Whether visitors get the buttons between the two readings when they differ.
 */
function ner_michoel_week_toggle_on() {
	$settings = ner_michoel_week_settings();
	return $settings['toggle'];
}

/**
 * The first box, as an editor set it: null for the calendar's parsha, 'hide' for no box, or the
 * parsha numbers chosen.
 *
 * @return string|int[]|null
 */
function ner_michoel_week_parsha_pin() {
	$settings = ner_michoel_week_settings();
	$choice   = $settings['parsha'];
	if ( 'hide' === $choice ) {
		return 'hide';
	}
	if ( preg_match( '/^p:(\d+)(?:,(\d+))?$/', $choice, $m ) ) {
		$names   = ner_michoel_parsha_names();
		$numbers = array( (int) $m[1] );
		if ( isset( $m[2] ) ) {
			$numbers[] = (int) $m[2];
		}
		foreach ( $numbers as $number ) {
			if ( ! isset( $names[ $number ] ) ) {
				return null;
			}
		}
		return $numbers;
	}
	return null;
}

/* ------------------------------------------------------------------
 * The second box.
 * ------------------------------------------------------------------ */

/**
 * A topic or series an editor chose, as the second box shows it (the shape of
 * ner_michoel_get_active_seasons()'s entries, plus kind and pinned), or null when it has no
 * published shiurim. A Yom Tov topic takes in its other spellings, as it does by the calendar.
 *
 * @param string $taxonomy 'topic' or 'series'.
 * @param int    $term_id  The term.
 * @param int    $per_page How many shiurim to list.
 * @return array|null
 */
function ner_michoel_week_pinned_term( $taxonomy, $term_id, $per_page = 4 ) {
	if ( ! taxonomy_exists( $taxonomy ) ) {
		return null;
	}
	$term = get_term( (int) $term_id, $taxonomy );
	if ( ! $term || is_wp_error( $term ) ) {
		return null;
	}

	$ids  = array( (int) $term->term_id );
	$kind = 'featured';
	if ( 'topic' === $taxonomy ) {
		$occasion = ner_michoel_topic_occasion( $term->name );
		if ( '' !== $occasion ) {
			$kind = 'season';
			$all  = get_terms(
				array(
					'taxonomy'   => 'topic',
					'hide_empty' => true,
				)
			);
			foreach ( is_wp_error( $all ) ? array() : $all as $other ) {
				if ( $occasion === ner_michoel_topic_occasion( $other->name ) ) {
					$ids[] = (int) $other->term_id;
				}
			}
			$ids = array_values( array_unique( $ids ) );
		}
	}

	$posts = get_posts(
		array(
			'post_type'           => array( 'shiur', 'written_shiur' ),
			'post_status'         => 'publish',
			'posts_per_page'      => (int) $per_page,
			'orderby'             => 'date',
			'order'               => 'DESC',
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
			'tax_query'           => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => $taxonomy,
					'field'    => 'term_id',
					'terms'    => $ids,
				),
			),
		)
	);
	if ( ! $posts ) {
		return null;
	}

	$count = 0;
	foreach ( $ids as $id ) {
		$one = get_term( $id, $taxonomy );
		if ( $one && ! is_wp_error( $one ) ) {
			$count += (int) $one->count;
		}
	}

	$link = get_term_link( $term );
	return array(
		'name'    => ner_michoel_term_label( $term ),
		'slug'    => $term->slug,
		'link'    => is_wp_error( $link ) ? '' : $link,
		'posts'   => $posts,
		'term_id' => (int) $term->term_id,
		'count'   => $count,
		'label'   => '',
		'mode'    => 'pinned',
		'kind'    => $kind,
		'pinned'  => true,
	);
}

/**
 * The second box for the homepage: the topic or series an editor chose; or, by the calendar,
 * the Yom Tov (or other topic) in season; or null for no box. Same shape as an entry of
 * ner_michoel_get_active_seasons(), plus kind ('season' or 'featured') and pinned.
 *
 * @param int $per_page How many shiurim to list.
 * @return array|null
 */
function ner_michoel_get_week_season( $per_page = 4 ) {
	$settings = ner_michoel_week_settings();
	if ( 'hide' === $settings['season'] ) {
		return null;
	}
	if ( preg_match( '/^([ts]):(\d+)$/', $settings['season'], $m ) ) {
		$chosen = ner_michoel_week_pinned_term( 't' === $m[1] ? 'topic' : 'series', (int) $m[2], $per_page );
		if ( $chosen ) {
			return $chosen;
		}
		// Gone, or no shiurim in it any more: the calendar's box instead of an empty one.
	}

	$seasons = ner_michoel_get_active_seasons( $per_page );
	if ( ! $seasons ) {
		return null;
	}
	return array_merge(
		$seasons[0],
		array(
			'kind'   => 'season',
			'pinned' => false,
		)
	);
}

/* ------------------------------------------------------------------
 * The Control Panel screen.
 * ------------------------------------------------------------------ */

/**
 * The parshiyos an editor can choose, as 'p:22' => 'Vayakhel'; the pairs read together
 * ('p:22,23' => 'Vayakhel-Pekudei') come after the second of each.
 *
 * @return array<string,string>
 */
function ner_michoel_week_parsha_options() {
	$names = ner_michoel_parsha_names();
	$pairs = array( 22, 27, 29, 32, 39, 42, 51 );
	$out   = array();
	foreach ( $names as $number => $name ) {
		$out[ 'p:' . $number ] = $name;
		if ( in_array( $number - 1, $pairs, true ) ) {
			$out[ 'p:' . ( $number - 1 ) . ',' . $number ] = $names[ $number - 1 ] . '-' . $name;
		}
	}
	return $out;
}

/**
 * The terms of a taxonomy that have shiurim, as 't:12' => 'Succos (62)', by name.
 *
 * @param string $taxonomy 'topic' or 'series'.
 * @param string $prefix   't' or 's'.
 * @return array<string,string>
 */
function ner_michoel_week_term_options( $taxonomy, $prefix ) {
	$out = array();
	if ( ! taxonomy_exists( $taxonomy ) ) {
		return $out;
	}
	$terms = get_terms(
		array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => true,
			'orderby'    => 'name',
			'order'      => 'ASC',
		)
	);
	if ( is_wp_error( $terms ) ) {
		return $out;
	}
	// A series is listed by its whole name, in the order of those names (the order the flat names had).
	if ( 'series' === $taxonomy ) {
		usort(
			$terms,
			function ( $a, $b ) {
				return ner_michoel_series_compare_labels( ner_michoel_term_label( $a ), ner_michoel_term_label( $b ) );
			}
		);
	}
	foreach ( $terms as $term ) {
		$out[ $prefix . ':' . $term->term_id ] = sprintf( '%s (%d)', html_entity_decode( ner_michoel_term_label( $term ), ENT_QUOTES, 'UTF-8' ), (int) $term->count );
	}
	return $out;
}

/**
 * Whether a choice sent from the screen is one the screen could have offered.
 */
function ner_michoel_week_choice_valid( $slot, $choice ) {
	if ( 'auto' === $choice || 'hide' === $choice ) {
		return true;
	}
	if ( 'parsha' === $slot ) {
		$options = ner_michoel_week_parsha_options();
		return isset( $options[ $choice ] );
	}
	if ( preg_match( '/^([ts]):(\d+)$/', $choice, $m ) ) {
		$taxonomy = ( 't' === $m[1] ) ? 'topic' : 'series';
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return false;
		}
		$term = get_term( (int) $m[2], $taxonomy );
		return $term && ! is_wp_error( $term );
	}
	return false;
}

/**
 * A YYYY-MM-DD day as "Oct 31", in the site's language.
 */
function ner_michoel_week_day_label( $ymd ) {
	$time = strtotime( $ymd . ' 12:00:00 UTC' );
	return function_exists( 'wp_date' ) ? wp_date( 'M j', $time, new DateTimeZone( 'UTC' ) ) : gmdate( 'M j', $time );
}

/**
 * What the Control Panel screen shows when it opens.
 */
function ner_michoel_week_settings_values() {
	return ner_michoel_week_settings();
}

/**
 * Plain-words lines at the top of the screen: what each box shows right now.
 *
 * @return string[]
 */
function ner_michoel_week_status_lines() {
	$settings = ner_michoel_week_settings();
	$lines    = array();

	// The first box.
	$regions = ner_michoel_get_week_regions( 1 );
	$name    = function ( $week ) {
		return $week ? $week['title'] : '';
	};
	if ( 'hide' === $settings['parsha'] ) {
		$lines[] = __( 'Parsha box: hidden, by your choice.', 'ner-michoel-core' );
	} elseif ( 'auto' !== $settings['parsha'] ) {
		$week = $regions['diaspora'];
		$line = $week
			/* translators: %s: a parsha such as Parshas Noach */
			? sprintf( __( 'Parsha box: showing %s, which you chose', 'ner-michoel-core' ), $name( $week ) )
			: __( 'Parsha box: showing your choice', 'ner-michoel-core' );
		if ( '' !== $settings['parsha_until'] ) {
			/* translators: %s: a date such as Oct 31 */
			$line .= sprintf( __( ', through %s, then back to the calendar.', 'ner-michoel-core' ), ner_michoel_week_day_label( $settings['parsha_until'] ) );
		} else {
			$line .= __( ', until you change it back.', 'ner-michoel-core' );
		}
		$lines[] = $line;
	} elseif ( $regions['toggle'] ) {
		$di = $name( $regions['diaspora'] );
		$il = $name( $regions['israel'] );
		$lines[] = sprintf(
			/* translators: 1: what Chutz La'aretz reads, 2: what Eretz Yisroel reads */
			__( 'Parsha box: by the calendar. This week Chutz La’aretz has %1$s and Eretz Yisroel has %2$s, so visitors get buttons to switch.', 'ner-michoel-core' ),
			'' !== $di ? $di : __( 'no parsha (Yom Tov)', 'ner-michoel-core' ),
			'' !== $il ? $il : __( 'no parsha (Yom Tov)', 'ner-michoel-core' )
		);
	} else {
		$week = $regions[ $regions['default'] ];
		$lines[] = $week
			/* translators: %s: a parsha such as Parshas Noach */
			? sprintf( __( 'Parsha box: by the calendar, %s this week.', 'ner-michoel-core' ), $name( $week ) )
			: __( 'Parsha box: by the calendar. This Shabbos is Yom Tov or Chol Hamoed, so there is no parsha this week.', 'ner-michoel-core' );
	}

	// The second box.
	$season = ner_michoel_get_week_season( 1 );
	if ( 'hide' === $settings['season'] ) {
		$lines[] = __( 'Second box: hidden, by your choice.', 'ner-michoel-core' );
	} elseif ( $season && $season['pinned'] ) {
		/* translators: %s: the name of a topic or series */
		$line = sprintf( __( 'Second box: showing %s, which you chose', 'ner-michoel-core' ), html_entity_decode( $season['name'], ENT_QUOTES, 'UTF-8' ) );
		if ( '' !== $settings['season_until'] ) {
			/* translators: %s: a date such as Oct 31 */
			$line .= sprintf( __( ', through %s, then back to the calendar.', 'ner-michoel-core' ), ner_michoel_week_day_label( $settings['season_until'] ) );
		} else {
			$line .= __( ', until you change it back.', 'ner-michoel-core' );
		}
		$lines[] = $line;
	} elseif ( $season ) {
		/* translators: %s: the name of a topic such as Succos */
		$lines[] = sprintf( __( 'Second box: by the calendar, %s is in season.', 'ner-michoel-core' ), html_entity_decode( $season['name'], ENT_QUOTES, 'UTF-8' ) );
	} elseif ( 'auto' === $settings['season'] ) {
		$lines[] = __( 'Second box: by the calendar. No Yom Tov with shiurim is in season now, so there is no second box.', 'ner-michoel-core' );
	} else {
		$lines[] = __( 'Second box: your choice has no shiurim, so there is no second box.', 'ner-michoel-core' );
	}

	return $lines;
}

/**
 * Extras for the screen: the lines above, and the long lists the two choice boxes offer (kept
 * out of the screen's field list so the other screens don't pay for reading every topic and series).
 */
function ner_michoel_week_settings_extra() {
	$groups = array(
		'parsha' => array(
			array(
				'label'   => __( 'Choose a parsha', 'ner-michoel-core' ),
				'options' => ner_michoel_week_parsha_options(),
			),
		),
		'season' => array(),
	);
	$topics = ner_michoel_week_term_options( 'topic', 't' );
	if ( $topics ) {
		$groups['season'][] = array(
			'label'   => __( 'Choose a topic (Yom Tov, a subject)', 'ner-michoel-core' ),
			'options' => $topics,
		);
	}
	$series = ner_michoel_week_term_options( 'series', 's' );
	if ( $series ) {
		$groups['season'][] = array(
			'label'   => __( 'Choose a series', 'ner-michoel-core' ),
			'options' => $series,
		);
	}
	return array(
		'status_lines' => ner_michoel_week_status_lines(),
		'groups'       => $groups,
	);
}

function ner_michoel_register_week_settings_rest_route() {
	register_rest_route(
		'ner-michoel/v1',
		'/week-settings',
		array(
			'methods'             => 'POST',
			'callback'            => 'ner_michoel_handle_week_settings_rest',
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
		)
	);
}
add_action( 'rest_api_init', 'ner_michoel_register_week_settings_rest_route' );

/**
 * Saves {parsha, parsha_until, season, season_until, region, toggle}. A key that is not sent is
 * left as it was; anything wrong saves nothing.
 */
function ner_michoel_handle_week_settings_rest( WP_REST_Request $request ) {
	$body = $request->get_json_params();
	if ( ! is_array( $body ) ) {
		$body = array();
	}

	$new   = array_merge( ner_michoel_week_settings_defaults(), (array) get_option( 'nm_week_settings', array() ) );
	$today = current_time( 'Y-m-d' );

	foreach ( array( 'parsha', 'season' ) as $slot ) {
		if ( isset( $body[ $slot ] ) ) {
			$choice = is_string( $body[ $slot ] ) ? trim( $body[ $slot ] ) : '';
			if ( ! ner_michoel_week_choice_valid( $slot, $choice ) ) {
				return new WP_Error( 'nm_week_choice', __( 'That choice is not on the list. Reload the screen and pick again.', 'ner-michoel-core' ), array( 'status' => 400 ) );
			}
			$new[ $slot ] = $choice;
		}
		if ( isset( $body[ $slot . '_until' ] ) ) {
			$until = is_string( $body[ $slot . '_until' ] ) ? trim( $body[ $slot . '_until' ] ) : '';
			if ( '' !== $until ) {
				if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $until, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
					return new WP_Error( 'nm_week_until', __( 'That is not a date. Pick the last day from the calendar.', 'ner-michoel-core' ), array( 'status' => 400 ) );
				}
				if ( $until < $today ) {
					return new WP_Error( 'nm_week_until_past', __( 'That day has already passed. Pick today or a later day.', 'ner-michoel-core' ), array( 'status' => 400 ) );
				}
			}
			$new[ $slot . '_until' ] = $until;
		}
		// A last day means nothing when the box follows the calendar.
		if ( 'auto' === $new[ $slot ] ) {
			$new[ $slot . '_until' ] = '';
		}
	}

	if ( isset( $body['region'] ) ) {
		if ( ! in_array( $body['region'], array( 'diaspora', 'israel' ), true ) ) {
			return new WP_Error( 'nm_week_region', __( 'Choose Chutz La’aretz or Eretz Yisroel.', 'ner-michoel-core' ), array( 'status' => 400 ) );
		}
		$new['region'] = $body['region'];
	}
	if ( isset( $body['toggle'] ) ) {
		$new['toggle'] = (bool) $body['toggle'];
	}

	update_option( 'nm_week_settings', array_intersect_key( $new, ner_michoel_week_settings_defaults() ) );

	if ( function_exists( 'ner_michoel_purge_page_caches' ) ) {
		ner_michoel_purge_page_caches();
	}

	return new WP_REST_Response( array( 'saved' => true ), 200 );
}
