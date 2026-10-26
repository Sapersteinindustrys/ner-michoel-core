<?php
/**
 * Mazal Tov presets: ready-made wordings with blanks that the admin fills in
 * when posting, for example "Mazel Tov to the ___ family on the birth of a
 * baby ___", saved under the name "Baby".
 *
 * A preset is a small, non-public post (a name, the wording, and an optional
 * Type). That keeps it in the site's backups and exports, and lets the panel's
 * generic list and form (includes/custom-admin-api.php) edit it with no screen
 * of its own. Visitors never see one.
 *
 * An announcement keeps only the finished sentence, as its headline (the post
 * title). The public pages already print the title, so they need no change, and
 * editing a preset later never rewrites an announcement that was already posted.
 *
 * Blanks are a run of two or more underscores, or a label in curly brackets
 * ({Family name}). assets/mazal-tov-presets.js turns them into boxes inside the
 * sentence and writes the headline; this file stores and lists presets, and
 * refuses a headline that still has a blank in it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ner_michoel_register_mazal_tov_preset_post_type() {
	register_post_type(
		'mazal_tov_preset',
		array(
			'labels'              => array(
				'name'          => __( 'Mazal Tov Presets', 'ner-michoel-core' ),
				'singular_name' => __( 'Mazal Tov Preset', 'ner-michoel-core' ),
			),
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => false,
			'show_in_menu'        => false,
			'show_in_nav_menus'   => false,
			'show_in_rest'        => false,
			'query_var'           => false,
			'rewrite'             => false,
			'has_archive'         => false,
			'supports'            => array( 'title' ),
		)
	);
}
add_action( 'init', 'ner_michoel_register_mazal_tov_preset_post_type' );

/**
 * True when a headline still has a blank in it (two or more underscores in a
 * row, or a label in curly brackets such as {Family name}). Used as a last check so
 * "Mazel Tov to the ___ family" can't be posted. {name}, the place where the person's
 * name goes (includes/mazal-tov.php), is not a blank.
 */
function ner_michoel_mazal_tov_has_blank( $text ) {
	return (bool) preg_match( '/_{2,}|\{(?!\s*name\s*\})[^{}]{0,60}\}/i', (string) $text );
}

/**
 * The choices for the visitor form's "What kind of simcha?" menu: every preset with a
 * wording, as id => name, in the order the presets are listed. Kept for a while so that
 * a page view costs one lookup; saving or deleting a preset or a Type forgets it.
 */
function ner_michoel_mazal_tov_share_choices() {
	$cached = get_transient( 'nm_mt_share_choices' );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	$choices = array();
	foreach ( ner_michoel_get_mazal_tov_presets() as $preset ) {
		$choices[ (int) $preset['id'] ] = $preset['name'];
	}
	set_transient( 'nm_mt_share_choices', $choices, 12 * HOUR_IN_SECONDS );
	return $choices;
}

function ner_michoel_mazal_tov_forget_share_choices() {
	delete_transient( 'nm_mt_share_choices' );
}
/** The panel saves a preset's wording and Type after the post itself, so those are watched too. */
function ner_michoel_mazal_tov_forget_share_choices_meta( $meta_id, $post_id, $meta_key ) {
	if ( 0 === strpos( (string) $meta_key, '_nm_preset_' ) ) {
		ner_michoel_mazal_tov_forget_share_choices();
	}
}
add_action( 'added_post_meta', 'ner_michoel_mazal_tov_forget_share_choices_meta', 10, 3 );
add_action( 'updated_post_meta', 'ner_michoel_mazal_tov_forget_share_choices_meta', 10, 3 );
add_action( 'deleted_post_meta', 'ner_michoel_mazal_tov_forget_share_choices_meta', 10, 3 );
add_action( 'save_post_mazal_tov_preset', 'ner_michoel_mazal_tov_forget_share_choices' );
add_action( 'deleted_post', 'ner_michoel_mazal_tov_forget_share_choices' );
add_action( 'trashed_post', 'ner_michoel_mazal_tov_forget_share_choices' );
add_action( 'edited_mazal_tov_type', 'ner_michoel_mazal_tov_forget_share_choices' );
add_action( 'delete_mazal_tov_type', 'ner_michoel_mazal_tov_forget_share_choices' );

/**
 * The Types a preset can pick (slug => name), with a "none" choice first. The
 * panel's form builds its menu from a JS object, which would put number-like
 * keys first, so slugs are the keys and the term IDs are looked up on read.
 */
function ner_michoel_mazal_tov_type_options() {
	$options = array( '' => __( '— None —', 'ner-michoel-core' ) );
	if ( ! taxonomy_exists( 'mazal_tov_type' ) ) {
		return $options;
	}
	$terms = get_terms( array( 'taxonomy' => 'mazal_tov_type', 'hide_empty' => false ) );
	if ( is_wp_error( $terms ) ) {
		return $options;
	}
	foreach ( $terms as $term ) {
		$options[ $term->slug ] = wp_specialchars_decode( $term->name, ENT_QUOTES );
	}
	return $options;
}

/**
 * The presets the pickers offer, by name: id, name, wording, and the preset's
 * Type (its term id and name, 0 and '' when there isn't one any more). A
 * preset with no wording is left out.
 */
function ner_michoel_get_mazal_tov_presets() {
	$posts = get_posts(
		array(
			'post_type'      => 'mazal_tov_preset',
			'post_status'    => 'publish',
			'posts_per_page' => 200,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		)
	);

	// The Types, by slug: a handful, looked up once rather than once per preset.
	$types = array();
	if ( $posts && taxonomy_exists( 'mazal_tov_type' ) ) {
		$terms = get_terms( array( 'taxonomy' => 'mazal_tov_type', 'hide_empty' => false ) );
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$types[ $term->slug ] = $term;
			}
		}
	}

	$presets = array();
	foreach ( $posts as $post ) {
		$wording = trim( (string) get_post_meta( $post->ID, '_nm_preset_wording', true ) );
		if ( '' === $wording ) {
			continue;
		}
		$slug = (string) get_post_meta( $post->ID, '_nm_preset_type', true );
		$type = ( '' !== $slug && isset( $types[ $slug ] ) ) ? $types[ $slug ] : null;
		$presets[] = array(
			'id'        => (int) $post->ID,
			'name'      => wp_specialchars_decode( $post->post_title, ENT_QUOTES ),
			'wording'   => $wording,
			'type_id'   => $type ? (int) $type->term_id : 0,
			'type_name' => $type ? wp_specialchars_decode( $type->name, ENT_QUOTES ) : '',
		);
	}
	return $presets;
}

/**
 * Same list for the panel's Add New form, which loads it each time it opens, so
 * a preset made a minute ago is there.
 */
function ner_michoel_register_mazal_tov_presets_route() {
	register_rest_route(
		'ner-michoel/v1',
		'/mazal-tov-presets',
		array(
			'methods'             => 'GET',
			'callback'            => 'ner_michoel_mazal_tov_presets_route',
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
		)
	);
}
add_action( 'rest_api_init', 'ner_michoel_register_mazal_tov_presets_route' );

function ner_michoel_mazal_tov_presets_route() {
	return new WP_REST_Response( ner_michoel_get_mazal_tov_presets(), 200 );
}

/**
 * The script and styles of the preset picker and the preset form. The panel
 * loads them on every page; the wp-admin Quick Mazal Tov screen asks for them
 * itself (includes/admin-dashboard.php).
 */
function ner_michoel_enqueue_mazal_tov_presets_assets() {
	wp_enqueue_style( 'ner-michoel-mazal-tov-presets', NER_MICHOEL_CORE_URL . 'assets/mazal-tov-presets.css', array(), NER_MICHOEL_CORE_VERSION );
	wp_enqueue_script( 'ner-michoel-mazal-tov-presets', NER_MICHOEL_CORE_URL . 'assets/mazal-tov-presets.js', array(), NER_MICHOEL_CORE_VERSION, true );
}

/**
 * Where presets are made: the panel's page. Also linked from wp-admin's Quick
 * Mazal Tov screen, which has no preset form of its own.
 */
function ner_michoel_mazal_tov_presets_url() {
	return function_exists( 'ner_michoel_custom_admin_url' ) ? ner_michoel_custom_admin_url( 'mazaltovpresets' ) : '';
}
