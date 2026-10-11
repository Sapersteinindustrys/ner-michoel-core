<?php
/**
 * The site's name, its small second name (the tagline) and its logo, changed from the Control
 * Panel (Homepage & Look > Site Name & Logo) instead of wp-admin's Settings and the Customizer.
 *
 * These are WordPress's own settings: the name is the Site Title (the option blogname), the second
 * name is the Tagline (blogdescription) and the logo is the theme's custom logo (the theme setting
 * custom_logo). Everything that already reads them follows with nothing else to change: the header,
 * the line at the bottom of the page, the title in the browser tab, the "Welcome to ..." on the
 * homepage, the emails the site sends and the Control Panel's own login page.
 *
 * The theme decides how they look (the name large, the second name small beside it, the logo at the
 * left). This file keeps the values and the screen's save route.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The site's name as plain text: "Rabbi & Sons", not "Rabbi &amp; Sons". WordPress keeps the title
 * with its characters escaped for HTML, so anything that prints it through esc_html() would show
 * "&amp;amp;" if it used the stored form.
 */
function ner_michoel_core_site_name() {
	return trim( wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES ) );
}

/**
 * A name or tagline from the screen: plain text, one line, cut to a number of characters (not bytes).
 */
function ner_michoel_identity_clean( $value, $max ) {
	$value = is_string( $value ) ? sanitize_text_field( $value ) : '';
	$value = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max, 'UTF-8' ) : substr( $value, 0, $max );
	return trim( $value );
}

/**
 * What the Site Name & Logo screen shows when it opens. logo_id_url is the picture's address for the
 * preview (the generic settings screen looks for "<field>_url").
 */
function ner_michoel_site_identity_values() {
	$logo_id  = (int) get_theme_mod( 'custom_logo' );
	$logo_url = '';
	if ( $logo_id && wp_attachment_is_image( $logo_id ) ) {
		$logo_url = (string) wp_get_attachment_image_url( $logo_id, 'medium' );
		if ( '' === $logo_url ) {
			$logo_url = (string) wp_get_attachment_url( $logo_id );
		}
	}
	if ( '' === $logo_url ) {
		$logo_id = 0; // A logo whose picture was deleted counts as none.
	}

	return array(
		'name'        => ner_michoel_core_site_name(),
		'tagline'     => trim( wp_specialchars_decode( (string) get_option( 'blogdescription' ), ENT_QUOTES ) ),
		'logo_id'     => $logo_id ? $logo_id : '',
		'logo_id_url' => $logo_url,
	);
}

function ner_michoel_register_site_identity_rest_route() {
	register_rest_route(
		'ner-michoel/v1',
		'/site-identity',
		array(
			'methods'             => 'POST',
			'callback'            => 'ner_michoel_handle_site_identity_rest',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		)
	);
}
add_action( 'rest_api_init', 'ner_michoel_register_site_identity_rest_route' );

/**
 * Saves {name, tagline, logo_id}. A key that is not sent is left as it was. An empty tagline removes
 * it, an empty logo_id (or 0) removes the logo, and an empty name is refused: a site with no name
 * would show nothing at the top of every page and in every browser tab. Everything is checked
 * before anything is written, so a bad logo can't leave the name half saved.
 */
function ner_michoel_handle_site_identity_rest( WP_REST_Request $request ) {
	$body = $request->get_json_params();
	if ( ! is_array( $body ) ) {
		$body = array();
	}

	$name = null;
	if ( array_key_exists( 'name', $body ) ) {
		$name = ner_michoel_identity_clean( $body['name'], 80 );
		if ( '' === $name ) {
			return new WP_Error( 'nm_identity_name', __( 'The site needs a name. Type it in the “Site name” box.', 'ner-michoel-core' ), array( 'status' => 400 ) );
		}
	}

	$tagline = null;
	if ( array_key_exists( 'tagline', $body ) ) {
		$tagline = ner_michoel_identity_clean( $body['tagline'], 100 );
	}

	$logo_id = null;
	if ( array_key_exists( 'logo_id', $body ) ) {
		$logo_id = absint( $body['logo_id'] );
		if ( $logo_id && ! wp_attachment_is_image( $logo_id ) ) {
			return new WP_Error( 'nm_identity_logo', __( 'That file is not a picture, so it can’t be the logo. Choose an image.', 'ner-michoel-core' ), array( 'status' => 400 ) );
		}
	}

	if ( null !== $name ) {
		update_option( 'blogname', $name );
	}
	if ( null !== $tagline ) {
		update_option( 'blogdescription', $tagline );
	}
	if ( null !== $logo_id ) {
		if ( $logo_id ) {
			set_theme_mod( 'custom_logo', $logo_id );
		} else {
			remove_theme_mod( 'custom_logo' );
		}
	}

	if ( function_exists( 'ner_michoel_purge_page_caches' ) ) {
		ner_michoel_purge_page_caches();
	}

	return new WP_REST_Response(
		array(
			'saved'  => true,
			'values' => ner_michoel_site_identity_values(),
		),
		200
	);
}
