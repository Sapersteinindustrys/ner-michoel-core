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

/**
 * Sets the section's speakers: moves in the shiurim of newly chosen speakers,
 * moves back those of speakers no longer chosen, and rebuilds the search index
 * now (so the public search drops them at once). Returns array( in, out ).
 */
function ner_michoel_alumni_set_speakers( array $speaker_ids ) {
	$speaker_ids = array_values( array_unique( array_filter( array_map( 'absint', $speaker_ids ) ) ) );
	$before      = ner_michoel_alumni_speakers();
	update_option( NER_MICHOEL_ALUMNI_SPEAKERS_OPTION, $speaker_ids, false );

	$in  = ner_michoel_alumni_move( ner_michoel_alumni_posts_of( $speaker_ids, array( 'shiur', 'written_shiur' ) ), true );
	$out = 0;
	$gone = array_diff( $before, $speaker_ids );
	if ( $gone ) {
		// Back out only what no chosen speaker still holds.
		$keep = array_flip( ner_michoel_alumni_posts_of( $speaker_ids, 'alumni_shiur' ) );
		$back = array();
		foreach ( ner_michoel_alumni_posts_of( $gone, 'alumni_shiur' ) as $id ) {
			if ( ! isset( $keep[ $id ] ) ) {
				$back[] = $id;
			}
		}
		$out = ner_michoel_alumni_move( $back, false );
	}
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
	if ( 'speaker' !== $taxonomy || ! in_array( get_post_type( $object_id ), array( 'shiur', 'written_shiur' ), true ) ) {
		return;
	}
	$chosen = ner_michoel_alumni_speakers();
	if ( ! $chosen ) {
		return;
	}
	$now = wp_get_object_terms( $object_id, 'speaker', array( 'fields' => 'ids' ) );
	if ( ! is_wp_error( $now ) && array_intersect( array_map( 'intval', $now ), $chosen ) ) {
		$GLOBALS['nm_alumni_pending_moves'][ (int) $object_id ] = true;
		if ( ! has_action( 'shutdown', 'ner_michoel_alumni_finish_moves' ) ) {
			add_action( 'shutdown', 'ner_michoel_alumni_finish_moves', 0 );
		}
	}
}
add_action( 'set_object_terms', 'ner_michoel_alumni_watch_terms', 20, 4 );

function ner_michoel_alumni_finish_moves() {
	$ids = empty( $GLOBALS['nm_alumni_pending_moves'] ) ? array() : array_keys( $GLOBALS['nm_alumni_pending_moves'] );
	$GLOBALS['nm_alumni_pending_moves'] = array();
	if ( $ids && ner_michoel_alumni_move( $ids, true ) && function_exists( 'ner_michoel_shiur_index_schedule' ) ) {
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
	list( $in, $out ) = ner_michoel_alumni_set_speakers( $chosen );
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

/** Drawn inside the Alumni screen (ner_michoel_render_alumni_page()). */
function ner_michoel_render_alumni_section_settings() {
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
		<?php ner_michoel_panel_form_field(); ?>
		<p><strong><?php esc_html_e( 'Speakers whose shiurim are only in the Alumni section', 'ner-michoel-core' ); ?></strong><br />
		<span class="description"><?php esc_html_e( 'Their shiurim leave the public site (lists, search, speaker and series pages) and appear on the Alumni page with its own search. New shiurim by them go there by themselves. Untick a speaker and Save to put their shiurim back on the public site.', 'ner-michoel-core' ); ?></span></p>
		<div style="max-height:260px;overflow:auto;border:1px solid #dcdcde;padding:8px 12px;max-width:520px">
			<?php
			$speakers = get_terms( array( 'taxonomy' => 'speaker', 'hide_empty' => false, 'orderby' => 'name' ) );
			foreach ( is_wp_error( $speakers ) ? array() : $speakers as $term ) :
				$is_on = in_array( (int) $term->term_id, $chosen, true );
				?>
				<label style="display:block;margin:2px 0"><input type="checkbox" name="speakers[]" value="<?php echo (int) $term->term_id; ?>" <?php checked( $is_on ); ?> /> <span dir="auto"><?php echo esc_html( $term->name ); ?></span> <span class="description">(<?php echo esc_html( $is_on ? __( 'in the Alumni section', 'ner-michoel-core' ) : sprintf( /* translators: %s: number */ __( '%s public', 'ner-michoel-core' ), number_format_i18n( (int) $term->count ) ) ); ?>)</span></label>
			<?php endforeach; ?>
		</div>
		<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'ner-michoel-core' ); ?></button></p>
	</form>
	<?php endif; ?>

	<h3><?php esc_html_e( 'Give an account access', 'ner-michoel-core' ); ?></h3>
	<p class="description"><?php esc_html_e( 'Alumni whose email or name is in the Years List get in by themselves. Use this for anyone else, such as an alumnus whose email isn’t in the list.', 'ner-michoel-core' ); ?></p>
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
