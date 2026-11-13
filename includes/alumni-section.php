<?php
/**
 * The Alumni section (Yoni, 2026-11-01): a separate part of the site, only for
 * alumni, with its own page (/alumni/) and its own search. For now it holds
 * Rabbi Shurkin's shiurim.
 *
 * How it stays separate: the shiurim of the speakers chosen on Control Panel >
 * Announcements > Alumni are moved to their own post type, alumni_shiur. Every
 * public list, the search index, feeds, the sitemap and the REST API only ever
 * ask for "shiur" and "written_shiur", so they never see them. Moving back (untick
 * the speaker and Save) puts each one back as it was (same id, address, files).
 *
 * Since 2026-11-11 a chosen speaker can be limited to some of his folders (series, with
 * everything inside them) and / or his videos; his other shiurim stay public. With nothing
 * ticked under him, all his shiurim are in the section, as before. An audio shiur that has
 * its video on the same post stays public when only "videos" is ticked; its video then shows
 * only to alumni (ner_michoel_alumni_video_hidden()).
 *
 * Who can see it: staff, accounts the alumni sign-up matched or the office
 * approved (alumni-accounts.php), accounts the office gave access by hand, and
 * older accounts whose email or whole name is in the Years List.
 *
 * Not covered: the audio files themselves stay at their Bunny addresses, so a
 * person who has a file's address can play it (as on every site that serves files
 * from a CDN). The pages, the list and the search are what is kept private.
 *
 * The theme draws the pages: alumni.php (the section) and single-alumni_shiur.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NER_MICHOEL_ALUMNI_SPEAKERS_OPTION', 'nm_alumni_speakers' );
// Per chosen speaker, which of his shiurim are for alumni (R' Ariel Katz, 2026-11-03: Rabbi
// Shurkin's parsha shiurim are for everyone; his Gemara shiurim and Zoom videos are for alumni).
// array( speaker id => array( 'folders' => series ids, 'videos' => 0|1 ) ). Nothing chosen = all.
define( 'NER_MICHOEL_ALUMNI_RULES_OPTION', 'nm_alumni_speaker_rules' );

/* ------------------------------------------------------------------ */
/* The post type and the /alumni/ address                              */
/* ------------------------------------------------------------------ */

function ner_michoel_register_alumni_section() {
	register_post_type(
		'alumni_shiur',
		array(
			'labels'              => array(
				'name'          => __( 'Alumni Shiurim', 'ner-michoel-core' ),
				'singular_name' => __( 'Alumni Shiur', 'ner-michoel-core' ),
				'edit_item'     => __( 'Edit Alumni Shiur', 'ner-michoel-core' ),
				'all_items'     => __( 'Alumni Shiurim', 'ner-michoel-core' ),
				'menu_name'     => __( 'Alumni Shiurim', 'ner-michoel-core' ),
			),
			'public'              => false,
			'publicly_queryable'  => true,  // its own pages, behind the alumni check below
			'exclude_from_search' => true,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'show_in_nav_menus'   => false,
			'show_in_rest'        => false, // nothing about it in /wp-json
			'has_archive'         => false,
			'rewrite'             => array( 'slug' => 'alumni/shiur', 'with_front' => false, 'feeds' => false ),
			'query_var'           => 'alumni_shiur',
			'menu_icon'           => 'dashicons-lock',
			'supports'            => array( 'title', 'editor' ),
			'capabilities'        => array( 'create_posts' => 'do_not_allow' ), // they arrive by moving a speaker in
			'map_meta_cap'        => true,
		)
	);
	add_rewrite_rule( '^alumni/?$', 'index.php?nm_alumni=1', 'top' );

	// The new addresses, once.
	if ( 'v1' !== get_option( 'nm_alumni_rewrite' ) ) {
		update_option( 'nm_alumni_rewrite', 'v1' );
		flush_rewrite_rules( false );
	}
}
add_action( 'init', 'ner_michoel_register_alumni_section', 20 );

function ner_michoel_alumni_query_vars( $vars ) {
	$vars[] = 'nm_alumni';
	return $vars;
}
add_filter( 'query_vars', 'ner_michoel_alumni_query_vars' );

/** The section's address. */
function ner_michoel_alumni_url() {
	return home_url( '/alumni/' );
}

/** Is this request the section page? */
function ner_michoel_is_alumni_page() {
	return (bool) get_query_var( 'nm_alumni' );
}

/* ------------------------------------------------------------------ */
/* Who can see it                                                      */
/* ------------------------------------------------------------------ */

/**
 * Quick check, no Years List lookup (used for showing the menu link on every page).
 */
function ner_michoel_alumni_can_view_quick( $user_id = null ) {
	$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
	if ( ! $user_id ) {
		return false;
	}
	if ( user_can( $user_id, 'edit_posts' ) || get_user_meta( $user_id, 'nm_alumni_access', true ) ) {
		return true;
	}
	$status = function_exists( 'ner_michoel_alumni_status' ) ? ner_michoel_alumni_status( $user_id ) : '';
	return in_array( $status, array( 'matched', 'approved' ), true );
}

/**
 * The full check, for the section's own pages: an older account (made before
 * alumni sign-up) is looked up in the Years List once (again after the list
 * changes), and kept as matched when it is there.
 */
function ner_michoel_alumni_can_view( $user_id = null ) {
	$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
	if ( ner_michoel_alumni_can_view_quick( $user_id ) ) {
		return true;
	}
	if ( ! $user_id || ! function_exists( 'ner_michoel_alumni_status' ) || '' !== ner_michoel_alumni_status( $user_id ) || ! function_exists( 'ner_michoel_years_get_list' ) ) {
		return false; // waiting (or turned down) accounts never get in
	}
	$list = ner_michoel_years_get_list();
	if ( ! $list['count'] ) {
		return false;
	}
	if ( (int) get_user_meta( $user_id, 'nm_alumni_checked', true ) === (int) $list['at'] ) {
		return false;
	}
	$user  = get_userdata( $user_id );
	$match = $user ? ner_michoel_alumni_match( $user->display_name, $user->user_email ) : null;
	if ( $match ) {
		update_user_meta( $user_id, 'nm_alumni_status', 'matched' );
		update_user_meta( $user_id, 'nm_alumni_match', $match );
		return true;
	}
	update_user_meta( $user_id, 'nm_alumni_checked', (int) $list['at'] );
	return false;
}

/* ------------------------------------------------------------------ */
/* The pages                                                           */
/* ------------------------------------------------------------------ */

/**
 * The section page and its shiurim: never cached for everyone by the host,
 * never indexed. A shiur page for someone without access goes to the section
 * page (which says how to get in). An old public address of a shiur that moved
 * here goes to its new address.
 */
function ner_michoel_alumni_template_redirect() {
	if ( ner_michoel_is_alumni_page() || is_singular( 'alumni_shiur' ) ) {
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		if ( is_singular( 'alumni_shiur' ) && ! ner_michoel_alumni_can_view() ) {
			wp_safe_redirect( ner_michoel_alumni_url() );
			exit;
		}
		return;
	}
	if ( is_404() ) {
		$slug = '';
		foreach ( array( 'shiur', 'written_shiur', 'name' ) as $var ) {
			$value = get_query_var( $var );
			if ( is_string( $value ) && '' !== $value ) {
				$slug = sanitize_title( $value );
				break;
			}
		}
		if ( '' !== $slug ) {
			$moved = get_posts(
				array(
					'post_type'      => 'alumni_shiur',
					'name'           => $slug,
					'posts_per_page' => 1,
					'fields'         => 'ids',
				)
			);
			if ( $moved ) {
				wp_safe_redirect( get_permalink( $moved[0] ), 302 );
				exit;
			}
		}
	}
}
add_action( 'template_redirect', 'ner_michoel_alumni_template_redirect', 5 );

/** /alumni/ is drawn by the theme's alumni.php. */
function ner_michoel_alumni_template_include( $template ) {
	if ( ner_michoel_is_alumni_page() ) {
		$theme = locate_template( 'alumni.php' );
		if ( $theme ) {
			return $theme;
		}
	}
	return $template;
}
add_filter( 'template_include', 'ner_michoel_alumni_template_include', 20 );

/** /alumni/ is not "nothing found". */
function ner_michoel_alumni_not_404( $preempt, $wp_query ) {
	if ( ! empty( $wp_query->query_vars['nm_alumni'] ) ) {
		$wp_query->is_404  = false;
		$wp_query->is_home = false;
		status_header( 200 );
		return true;
	}
	return $preempt;
}
add_filter( 'pre_handle_404', 'ner_michoel_alumni_not_404', 10, 2 );

function ner_michoel_alumni_document_title( $title ) {
	if ( ner_michoel_is_alumni_page() ) {
		$title['title'] = __( 'Alumni', 'ner-michoel-core' );
	}
	return $title;
}
add_filter( 'document_title_parts', 'ner_michoel_alumni_document_title' );

/** The main query on /alumni/ asks for nothing (the page draws its own list). */
function ner_michoel_alumni_main_query( $query ) {
	if ( $query->is_main_query() && ! is_admin() && $query->get( 'nm_alumni' ) ) {
		$query->set( 'post_type', 'alumni_shiur' );
		$query->set( 'posts_per_page', 1 );
		$query->set( 'post__in', array( 0 ) );
	}
}
add_action( 'pre_get_posts', 'ner_michoel_alumni_main_query' );

/**
 * Every shiur in the section, newest first, for the page's list and search:
 * id, title, url, time, kind (a / v / w), series and duration.
 */
function ner_michoel_alumni_items() {
	$posts = get_posts(
		array(
			'post_type'              => 'alumni_shiur',
			'post_status'            => 'publish',
			'numberposts'            => -1,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'update_post_term_cache' => true,
			'update_post_meta_cache' => true,
		)
	);
	$items = array();
	foreach ( $posts as $post ) {
		$series = get_the_terms( $post->ID, 'series' );
		$name   = '';
		if ( $series && ! is_wp_error( $series ) ) {
			$name = function_exists( 'ner_michoel_series_label' ) ? ner_michoel_series_label( $series[0] ) : $series[0]->name;
		}
		$items[] = array(
			'id'       => $post->ID,
			'title'    => get_the_title( $post ),
			'url'      => get_permalink( $post ),
			'time'     => get_post_time( 'U', true, $post ),
			'kind'     => ner_michoel_alumni_kind( $post->ID ),
			'series'   => $name,
			'duration' => (string) get_post_meta( $post->ID, '_shiur_duration', true ),
		);
	}
	return $items;
}

/** a (audio), v (video) or w (written). */
function ner_michoel_alumni_kind( $post_id ) {
	if ( 'written_shiur' === get_post_meta( $post_id, '_nm_alumni_from', true ) || get_post_meta( $post_id, '_written_pdf_id', true ) ) {
		return 'w';
	}
	return ( 'audio' === ner_michoel_get_shiur_media_type( $post_id ) ) ? 'a' : 'v';
}

/* ------------------------------------------------------------------ */
/* Moving a speaker's shiurim in and out                              */
/* ------------------------------------------------------------------ */

/** The speakers whose shiurim are in the section (term ids). */
function ner_michoel_alumni_speakers() {
	return array_values( array_filter( array_map( 'absint', (array) get_option( NER_MICHOEL_ALUMNI_SPEAKERS_OPTION, array() ) ) ) );
}

/** Puts posts in the section (from shiur / written_shiur) or back. Returns how many moved. */
function ner_michoel_alumni_move( array $ids, $into ) {
	$moved   = 0;
	$touched = array();
	foreach ( array_unique( array_map( 'absint', $ids ) ) as $id ) {
		$type = get_post_type( $id );
		if ( $into && in_array( $type, array( 'shiur', 'written_shiur' ), true ) ) {
			update_post_meta( $id, '_nm_alumni_from', $type );
			set_post_type( $id, 'alumni_shiur' );
		} elseif ( ! $into && 'alumni_shiur' === $type ) {
			$from = get_post_meta( $id, '_nm_alumni_from', true );
			set_post_type( $id, 'written_shiur' === $from ? 'written_shiur' : 'shiur' );
			delete_post_meta( $id, '_nm_alumni_from' );
		} else {
			continue;
		}
		$moved++;
		foreach ( array( 'speaker', 'series', 'topic' ) as $taxonomy ) {
			$terms = get_the_terms( $id, $taxonomy );
			if ( $terms && ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					$touched[ $taxonomy ][ $term->term_taxonomy_id ] = true;
				}
			}
		}
	}
	// Speaker / series counts on public pages count only what is still public.
	foreach ( $touched as $taxonomy => $tt_ids ) {
		wp_update_term_count_now( array_keys( $tt_ids ), $taxonomy );
	}
	return $moved;
}

/** Ids of the shiurim (or alumni shiurim) of these speakers. */
function ner_michoel_alumni_posts_of( array $speaker_ids, $types ) {
	if ( ! $speaker_ids ) {
		return array();
	}
	return get_posts(
		array(
			'post_type'        => $types,
			'post_status'      => 'any',
			'numberposts'      => -1,
			'fields'           => 'ids',
			'suppress_filters' => true,
			'tax_query'        => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => 'speaker',
					'field'    => 'term_id',
					'terms'    => $speaker_ids,
				),
			),
		)
	);
}

/** The rule of each chosen speaker: array( id => array( 'folders' => ids, 'videos' => bool ) ). */
function ner_michoel_alumni_rules() {
	$stored = get_option( NER_MICHOEL_ALUMNI_RULES_OPTION, array() );
	$stored = is_array( $stored ) ? $stored : array();
	$rules  = array();
	foreach ( ner_michoel_alumni_speakers() as $sid ) {
		$rule          = isset( $stored[ $sid ] ) && is_array( $stored[ $sid ] ) ? $stored[ $sid ] : array();
		$rules[ $sid ] = array(
			'folders' => isset( $rule['folders'] ) ? array_values( array_filter( array_map( 'absint', (array) $rule['folders'] ) ) ) : array(),
			'videos'  => ! empty( $rule['videos'] ),
		);
	}
	return $rules;
}

/** Is this post a video (a Vimeo / Zoom link or a video file, with no audio file)? */
function ner_michoel_alumni_is_video( $post_id ) {
	if ( 'written_shiur' === get_post_type( $post_id ) || 'written_shiur' === get_post_meta( $post_id, '_nm_alumni_from', true ) || get_post_meta( $post_id, '_written_pdf_id', true ) ) {
		return false;
	}
	return function_exists( 'ner_michoel_get_shiur_media_type' ) && 'audio' !== ner_michoel_get_shiur_media_type( $post_id );
}

/** The post's series and every folder above them (term ids). */
function ner_michoel_alumni_post_folders( $post_id ) {
	$terms = wp_get_object_terms( $post_id, 'series', array( 'fields' => 'ids' ) );
	if ( is_wp_error( $terms ) ) {
		return array();
	}
	$all = array();
	foreach ( $terms as $term_id ) {
		$all[ (int) $term_id ] = true;
		foreach ( get_ancestors( (int) $term_id, 'series', 'taxonomy' ) as $up ) {
			$all[ (int) $up ] = true;
		}
	}
	return array_keys( $all );
}

/** Does this shiur belong in the section under the current rules? */
function ner_michoel_alumni_post_matches( $post_id, $rules = null ) {
	$rules = null === $rules ? ner_michoel_alumni_rules() : $rules;
	if ( ! $rules ) {
		return false;
	}
	$speakers = wp_get_object_terms( $post_id, 'speaker', array( 'fields' => 'ids' ) );
	if ( is_wp_error( $speakers ) ) {
		return false;
	}
	foreach ( $speakers as $sid ) {
		$sid = (int) $sid;
		if ( ! isset( $rules[ $sid ] ) ) {
			continue;
		}
		$rule = $rules[ $sid ];
		if ( ! $rule['folders'] && ! $rule['videos'] ) {
			return true; // all his shiurim
		}
		if ( $rule['videos'] && ner_michoel_alumni_is_video( $post_id ) ) {
			return true;
		}
		if ( $rule['folders'] && array_intersect( $rule['folders'], ner_michoel_alumni_post_folders( $post_id ) ) ) {
			return true;
		}
	}
	return false;
}

/**
 * A public audio shiur whose video is on the same post, by a speaker whose videos are for
 * alumni: true when the person looking can't see the alumni section (so the page leaves
 * the video out and says it is for alumni).
 */
function ner_michoel_alumni_video_hidden( $post_id ) {
	$rules = ner_michoel_alumni_rules();
	if ( ! $rules ) {
		return false;
	}
	$speakers = wp_get_object_terms( $post_id, 'speaker', array( 'fields' => 'ids' ) );
	if ( is_wp_error( $speakers ) ) {
		return false;
	}
	foreach ( $speakers as $sid ) {
		if ( isset( $rules[ (int) $sid ] ) && $rules[ (int) $sid ]['videos'] ) {
			return ! ner_michoel_alumni_can_view_quick();
		}
	}
	return false;
}

/**
 * Moves every shiur of these speakers to where the rules say: into the section or
 * back to the public site. Returns array( in, out ).
 */
function ner_michoel_alumni_apply( array $speaker_ids ) {
	$rules = ner_michoel_alumni_rules();
	$in    = array();
	$out   = array();
	foreach ( ner_michoel_alumni_posts_of( $speaker_ids, array( 'shiur', 'written_shiur', 'alumni_shiur' ) ) as $id ) {
		$is_in = 'alumni_shiur' === get_post_type( $id );
		$match = ner_michoel_alumni_post_matches( $id, $rules );
		if ( $match && ! $is_in ) {
			$in[] = $id;
		} elseif ( ! $match && $is_in ) {
			$out[] = $id;
		}
	}
	return array( ner_michoel_alumni_move( $in, true ), ner_michoel_alumni_move( $out, false ) );
}

/**
 * The folders a speaker's shiurim are in, for ticking: the top folders and the folders one
 * level inside them, each with how many of his shiurim it holds (public and alumni).
 * array( term id => array( 'label' => "Gemara / Gittin", 'count' => n, 'depth' => 0|1 ) ),
 * in name order.
 */
function ner_michoel_alumni_speaker_folders( $speaker_id ) {
	$ids    = ner_michoel_alumni_posts_of( array( (int) $speaker_id ), array( 'shiur', 'written_shiur', 'alumni_shiur' ) );
	$counts = array();
	foreach ( array_chunk( $ids, 500 ) as $chunk ) {
		$terms = wp_get_object_terms( $chunk, 'series', array( 'fields' => 'all_with_object_id' ) );
		if ( is_wp_error( $terms ) ) {
			continue;
		}
		$per_post = array();
		foreach ( $terms as $term ) {
			$chain = array_reverse( get_ancestors( (int) $term->term_id, 'series', 'taxonomy' ) );
			$chain[] = (int) $term->term_id;
			foreach ( array_slice( $chain, 0, 2 ) as $depth => $folder ) {
				$per_post[ (int) $term->object_id ][ $folder ] = $depth;
			}
		}
		foreach ( $per_post as $folders ) {
			foreach ( $folders as $folder => $depth ) {
				if ( ! isset( $counts[ $folder ] ) ) {
					$counts[ $folder ] = array( 'count' => 0, 'depth' => $depth );
				}
				++$counts[ $folder ]['count'];
			}
		}
	}
	$out = array();
	foreach ( $counts as $folder => $row ) {
		$label = function_exists( 'ner_michoel_series_label' ) ? ner_michoel_series_label( $folder ) : '';
		if ( '' === $label ) {
			$term  = get_term( $folder, 'series' );
			$label = ( $term && ! is_wp_error( $term ) ) ? $term->name : '#' . $folder;
		}
		$out[ $folder ] = array(
			'label' => html_entity_decode( $label, ENT_QUOTES, 'UTF-8' ),
			'count' => $row['count'],
			'depth' => $row['depth'],
		);
	}
	uasort(
		$out,
		function ( $a, $b ) {
			return strnatcasecmp( $a['label'], $b['label'] );
		}
	);
	return $out;
}

/**
 * Sets the section's speakers: moves in the shiurim of newly chosen speakers,
 * moves back those of speakers no longer chosen, and rebuilds the search index
 * now (so the public search drops them at once). Returns array( in, out ).
 */
function ner_michoel_alumni_set_speakers( array $speaker_ids, $rules = null ) {
	$speaker_ids = array_values( array_unique( array_filter( array_map( 'absint', $speaker_ids ) ) ) );
	$before      = ner_michoel_alumni_speakers();
	update_option( NER_MICHOEL_ALUMNI_SPEAKERS_OPTION, $speaker_ids, false );
	if ( is_array( $rules ) ) {
		$clean = array();
		foreach ( $speaker_ids as $sid ) {
			$rule          = isset( $rules[ $sid ] ) && is_array( $rules[ $sid ] ) ? $rules[ $sid ] : array();
			$clean[ $sid ] = array(
				'folders' => isset( $rule['folders'] ) ? array_values( array_unique( array_filter( array_map( 'absint', (array) $rule['folders'] ) ) ) ) : array(),
				'videos'  => empty( $rule['videos'] ) ? 0 : 1,
			);
		}
		update_option( NER_MICHOEL_ALUMNI_RULES_OPTION, $clean, false );
	}

	// Every shiur of a speaker chosen now or before goes where the rules say.
	list( $in, $out ) = ner_michoel_alumni_apply( array_values( array_unique( array_merge( $speaker_ids, $before ) ) ) );
	if ( ( $in || $out ) && function_exists( 'ner_michoel_shiur_index_rebuild' ) ) {
		ner_michoel_shiur_index_rebuild();
	}
	return array( $in, $out );
}

/**
 * A new shiur by a chosen speaker (Add New Shiur, the importer, an edit that
 * changes the speaker) joins the section at the end of the request that gave it
 * the speaker, before the search index rebuilds.
 */
function ner_michoel_alumni_watch_terms( $object_id, $terms, $tt_ids, $taxonomy ) {
	if ( ! in_array( $taxonomy, array( 'speaker', 'series' ), true ) || ! in_array( get_post_type( $object_id ), array( 'shiur', 'written_shiur', 'alumni_shiur' ), true ) ) {
		return;
	}
	if ( ! ner_michoel_alumni_speakers() ) {
		return;
	}
	// Checked at the end of the request, when its speaker, series and files are all saved.
	$GLOBALS['nm_alumni_pending_moves'][ (int) $object_id ] = true;
	if ( ! has_action( 'shutdown', 'ner_michoel_alumni_finish_moves' ) ) {
		add_action( 'shutdown', 'ner_michoel_alumni_finish_moves', 0 );
	}
}
add_action( 'set_object_terms', 'ner_michoel_alumni_watch_terms', 20, 4 );

function ner_michoel_alumni_finish_moves() {
	$ids = empty( $GLOBALS['nm_alumni_pending_moves'] ) ? array() : array_keys( $GLOBALS['nm_alumni_pending_moves'] );
	$GLOBALS['nm_alumni_pending_moves'] = array();
	$rules = ner_michoel_alumni_rules();
	$in    = array();
	$out   = array();
	foreach ( $ids as $id ) {
		$is_in = 'alumni_shiur' === get_post_type( $id );
		$match = ner_michoel_alumni_post_matches( $id, $rules );
		if ( $match && ! $is_in ) {
			$in[] = $id;
		} elseif ( ! $match && $is_in ) {
			$out[] = $id;
		}
	}
	$moved = ner_michoel_alumni_move( $in, true ) + ner_michoel_alumni_move( $out, false );
	if ( $moved && function_exists( 'ner_michoel_shiur_index_schedule' ) ) {
		ner_michoel_shiur_index_schedule();
	}
}

/* ------------------------------------------------------------------ */
/* Control Panel: the section's part of the Alumni screen              */
/* ------------------------------------------------------------------ */

function ner_michoel_handle_alumni_section() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'ner-michoel-core' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'nm_alumni_section', 'nm_alumni_nonce' );
	$chosen = isset( $_POST['speakers'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['speakers'] ) ) : array();
	$rules  = array();
	$posted = isset( $_POST['folders'] ) && is_array( $_POST['folders'] ) ? wp_unslash( $_POST['folders'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- absint below.
	$videos = isset( $_POST['videos'] ) && is_array( $_POST['videos'] ) ? wp_unslash( $_POST['videos'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read as flags.
	$known  = get_option( NER_MICHOEL_ALUMNI_RULES_OPTION, array() );
	foreach ( $chosen as $sid ) {
		if ( isset( $_POST['rule_shown'][ $sid ] ) ) { // the speaker's choices were on the form
			$rules[ $sid ] = array(
				'folders' => isset( $posted[ $sid ] ) ? array_map( 'absint', (array) $posted[ $sid ] ) : array(),
				'videos'  => ! empty( $videos[ $sid ] ),
			);
		} elseif ( is_array( $known ) && isset( $known[ $sid ] ) ) {
			$rules[ $sid ] = $known[ $sid ];
		}
	}
	list( $in, $out ) = ner_michoel_alumni_set_speakers( $chosen, $rules );
	ner_michoel_alumni_flash(
		sprintf(
			/* translators: 1: moved in, 2: moved back */
			__( 'Saved. %1$s shiurim moved into the Alumni section, %2$s moved back to the public site.', 'ner-michoel-core' ),
			number_format_i18n( $in ),
			number_format_i18n( $out )
		)
	);
	ner_michoel_alumni_back();
}
add_action( 'admin_post_nm_alumni_section', 'ner_michoel_handle_alumni_section' );

/** Give or take away access for an existing account, by email. */
function ner_michoel_handle_alumni_access() {
	if ( ! current_user_can( 'edit_users' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'ner-michoel-core' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'nm_alumni_access', 'nm_alumni_nonce' );
	$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : 'give';
	if ( 'remove' === $do ) {
		$user = get_userdata( isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0 );
	} else {
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$user  = is_email( $email ) ? get_user_by( 'email', $email ) : false;
	}
	if ( ! $user ) {
		ner_michoel_alumni_flash( __( 'No account has that email. The person needs to sign up on the Account page first.', 'ner-michoel-core' ), true );
		ner_michoel_alumni_back();
	}
	if ( 'remove' === $do ) {
		delete_user_meta( $user->ID, 'nm_alumni_access' );
		ner_michoel_alumni_flash( sprintf( /* translators: %s: name */ __( '%s no longer has access given by hand.', 'ner-michoel-core' ), $user->display_name ) );
	} else {
		update_user_meta( $user->ID, 'nm_alumni_access', 1 );
		if ( 'pending' === ner_michoel_alumni_status( $user->ID ) ) {
			update_user_meta( $user->ID, 'nm_alumni_status', 'approved' );
		}
		ner_michoel_alumni_flash( sprintf( /* translators: %s: name */ __( '%s can open the Alumni section now.', 'ner-michoel-core' ), $user->display_name ) );
	}
	ner_michoel_alumni_back();
}
add_action( 'admin_post_nm_alumni_access', 'ner_michoel_handle_alumni_access' );

/** Control Panel > Alumni > Alumni Shiurim. */
function ner_michoel_render_alumni_section_page() {
	$key   = 'nm_alumni_flash_' . get_current_user_id();
	$flash = get_transient( $key );
	if ( $flash ) {
		delete_transient( $key );
	}
	echo '<div class="wrap nm-dashboard nm-alumni">';
	if ( is_array( $flash ) ) {
		printf( '<div class="notice notice-%s"><p>%s</p></div>', $flash['error'] ? 'error' : 'success', esc_html( $flash['message'] ) );
	}
	ner_michoel_render_alumni_section_settings( 'speakers' );
	echo '</div>';
}

/**
 * $part 'speakers' (the Alumni Shiurim screen), 'access' (“Give an account
 * access”, on the Sign-ups & Access screen) or 'all'.
 */
function ner_michoel_render_alumni_section_settings( $part = 'all' ) {
	$post_to = admin_url( 'admin-post.php' );
	$chosen  = ner_michoel_alumni_speakers();
	$in      = wp_count_posts( 'alumni_shiur' );
	$given   = get_users(
		array(
			'meta_key' => 'nm_alumni_access', // phpcs:ignore WordPress.DB.SlowDBQuery
			'number'   => 200,
		)
	);
	?>
	<?php if ( 'access' !== $part ) : ?>
	<h2><?php esc_html_e( 'Alumni section', 'ner-michoel-core' ); ?></h2>
	<p>
		<?php
		echo esc_html( sprintf( /* translators: %s: number */ __( 'The section has %s shiurim. Its page:', 'ner-michoel-core' ), number_format_i18n( (int) $in->publish ) ) );
		?>
		<a href="<?php echo esc_url( ner_michoel_alumni_url() ); ?>" target="_blank" rel="noopener"><?php echo esc_html( ner_michoel_alumni_url() ); ?></a>
	</p>

	<?php if ( current_user_can( 'manage_options' ) ) : ?>
	<form method="post" action="<?php echo esc_url( $post_to ); ?>" class="nm-alumni-section">
		<?php wp_nonce_field( 'nm_alumni_section', 'nm_alumni_nonce' ); ?>
		<input type="hidden" name="action" value="nm_alumni_section" />
		<input type="hidden" name="nm_back" value="alumnisection" />
		<?php ner_michoel_panel_form_field(); ?>
		<p><strong><?php esc_html_e( 'Speakers whose shiurim are only in the Alumni section', 'ner-michoel-core' ); ?></strong><br />
		<span class="description"><?php esc_html_e( 'Their shiurim leave the public site (lists, search, speaker and series pages) and appear on the Alumni page with its own search. New shiurim by them go there by themselves. Untick a speaker and Save to put their shiurim back on the public site. After Save, you can choose below which of a speaker\'s shiurim are for alumni.', 'ner-michoel-core' ); ?></span></p>
		<div style="max-height:260px;overflow:auto;border:1px solid #dcdcde;padding:8px 12px;max-width:520px">
			<?php
			$speakers = get_terms( array( 'taxonomy' => 'speaker', 'hide_empty' => false, 'orderby' => 'name' ) );
			foreach ( is_wp_error( $speakers ) ? array() : $speakers as $term ) :
				$is_on = in_array( (int) $term->term_id, $chosen, true );
				?>
				<label style="display:block;margin:2px 0"><input type="checkbox" name="speakers[]" value="<?php echo (int) $term->term_id; ?>" <?php checked( $is_on ); ?> /> <span dir="auto"><?php echo esc_html( $term->name ); ?></span> <span class="description">(<?php echo esc_html( $is_on ? __( 'in the Alumni section', 'ner-michoel-core' ) : sprintf( /* translators: %s: number */ __( '%s public', 'ner-michoel-core' ), number_format_i18n( (int) $term->count ) ) ); ?>)</span></label>
			<?php endforeach; ?>
		</div>
		<?php
		$rules = ner_michoel_alumni_rules();
		foreach ( $chosen as $sid ) :
			$term = get_term( $sid, 'speaker' );
			if ( ! $term || is_wp_error( $term ) ) {
				continue;
			}
			$rule    = isset( $rules[ $sid ] ) ? $rules[ $sid ] : array( 'folders' => array(), 'videos' => false );
			$folders = ner_michoel_alumni_speaker_folders( $sid );
			?>
			<fieldset style="margin-top:14px;max-width:620px;border:1px solid #dcdcde;padding:8px 12px">
				<legend><strong><?php echo esc_html( sprintf( /* translators: %s: speaker */ __( 'Which of %s\'s shiurim are for alumni?', 'ner-michoel-core' ), $term->name ) ); ?></strong></legend>
				<input type="hidden" name="rule_shown[<?php echo (int) $sid; ?>]" value="1" />
				<p class="description" style="margin-top:0"><?php esc_html_e( 'Tick the folders (everything inside a folder goes with it) and / or his videos. The rest of his shiurim stay on the public site. Nothing ticked: all his shiurim are for alumni.', 'ner-michoel-core' ); ?></p>
				<label style="display:block;margin:2px 0 8px"><input type="checkbox" name="videos[<?php echo (int) $sid; ?>]" value="1" <?php checked( $rule['videos'] ); ?> /> <?php esc_html_e( 'His videos (Vimeo or Zoom recordings, video files). An audio shiur with its video stays public; only its video is for alumni.', 'ner-michoel-core' ); ?></label>
				<div style="max-height:260px;overflow:auto">
					<?php foreach ( $folders as $fid => $info ) : ?>
						<label style="display:block;margin:2px 0<?php echo $info['depth'] ? ';padding-left:22px' : ''; ?>"><input type="checkbox" name="folders[<?php echo (int) $sid; ?>][]" value="<?php echo (int) $fid; ?>" <?php checked( in_array( (int) $fid, $rule['folders'], true ) ); ?> /> <span dir="auto"><?php echo esc_html( $info['label'] ); ?></span> <span class="description">(<?php echo esc_html( number_format_i18n( $info['count'] ) ); ?>)</span></label>
					<?php endforeach; ?>
					<?php if ( ! $folders ) : ?>
						<p class="description"><?php esc_html_e( 'His shiurim are in no folder.', 'ner-michoel-core' ); ?></p>
					<?php endif; ?>
				</div>
			</fieldset>
		<?php endforeach; ?>
		<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'ner-michoel-core' ); ?></button></p>
	</form>
	<?php endif; ?>
	<?php endif; ?>
	<?php
	if ( 'speakers' === $part ) {
		return;
	}
	?>

	<h3><?php esc_html_e( 'Give an account access', 'ner-michoel-core' ); ?></h3>
	<p class="description"><?php esc_html_e( 'Alumni found in the alumni sheet or the Years List get in by themselves. Use this for anyone else, such as an alumnus who isn’t in either.', 'ner-michoel-core' ); ?></p>
	<form method="post" action="<?php echo esc_url( $post_to ); ?>">
		<?php wp_nonce_field( 'nm_alumni_access', 'nm_alumni_nonce' ); ?>
		<input type="hidden" name="action" value="nm_alumni_access" />
		<input type="hidden" name="do" value="give" />
		<?php ner_michoel_panel_form_field(); ?>
		<input type="email" name="email" class="regular-text" placeholder="<?php esc_attr_e( 'Their account email', 'ner-michoel-core' ); ?>" required />
		<button type="submit" class="button"><?php esc_html_e( 'Give access', 'ner-michoel-core' ); ?></button>
	</form>
	<?php if ( $given ) : ?>
		<table class="widefat striped" style="max-width:640px;margin-top:10px">
			<tbody>
			<?php foreach ( $given as $u ) : ?>
				<tr>
					<td><span dir="auto"><?php echo esc_html( $u->display_name ); ?></span></td>
					<td><?php echo esc_html( $u->user_email ); ?></td>
					<td>
						<form method="post" action="<?php echo esc_url( $post_to ); ?>">
							<?php wp_nonce_field( 'nm_alumni_access', 'nm_alumni_nonce' ); ?>
							<input type="hidden" name="action" value="nm_alumni_access" />
							<input type="hidden" name="do" value="remove" />
							<input type="hidden" name="user_id" value="<?php echo (int) $u->ID; ?>" />
							<?php ner_michoel_panel_form_field(); ?>
							<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Remove', 'ner-michoel-core' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
	<?php
}
