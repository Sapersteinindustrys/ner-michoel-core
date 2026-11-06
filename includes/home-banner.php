<?php
/**
 * The homepage banner: the space under the welcome that used to be the Zoom box.
 *
 * Set in the Control Panel under Announcements > Homepage Banner: words, a button, a logo
 * or small picture, a wide background picture, and a design (plain, colored, gold, or the
 * background picture). It can be switched off. The theme prints it
 * (template-parts/home-banner.php).
 *
 * The same screen has the alumni invitation: a box for visitors who are not signed in,
 * asking alumni to log in (or make an account) and telling them what they get.
 *
 * Each of the two can be placed: under the welcome on the homepage, above the footer, inside
 * the footer, or as a popup that shows once on each computer (and again when its words change).
 *
 * The same screen also edits the small Zoom line on the homepage. Its link, meeting ID and
 * schedule stay where they always were (option nm_live_shiur, live-shiur.php), so the
 * Live Shiur / Zoom screen and this one show the same details.
 *
 * Stored in one option, nm_home_banner. Until it is first saved the banner is off.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The banner's designs, as the screen offers them. */
function ner_michoel_home_banner_styles() {
	return array(
		'color' => __( 'Colored (the site’s blue)', 'ner-michoel-core' ),
		'gold'  => __( 'Gold', 'ner-michoel-core' ),
		'light' => __( 'Light (white card)', 'ner-michoel-core' ),
		'image' => __( 'The background picture below', 'ner-michoel-core' ),
		'plain' => __( 'No design (just the words)', 'ner-michoel-core' ),
	);
}

function ner_michoel_home_banner_defaults() {
	return array(
		'on'             => false,
		'style'          => 'color',
		'kicker'         => '',
		'title'          => '',
		'text'           => '',
		'logo_id'        => 0,
		'background_id'  => 0,
		'button_label'   => '',
		'button_url'     => '',
		'alumni_on'      => true,
		'alumni_title'   => '',
		'alumni_text'    => '',
		'alumni_button'  => '',
		'place'          => 'home',
		'alumni_place'   => 'home',
		'zoom_on'        => true,
	);
}

/** Where the banner or the alumni invitation can show, as the screen offers them. */
function ner_michoel_home_banner_places() {
	return array(
		'home'         => __( 'On the homepage, under the welcome', 'ner-michoel-core' ),
		'above_footer' => __( 'On every page, just above the footer', 'ner-michoel-core' ),
		'footer'       => __( 'Inside the footer', 'ner-michoel-core' ),
		'popup'        => __( 'A popup, once on each computer', 'ner-michoel-core' ),
	);
}

/** The Zoom fields this screen edits, kept in nm_live_shiur. */
function ner_michoel_home_banner_zoom_keys() {
	return array( 'zoom_link', 'meeting_id', 'schedule' );
}

/** The alumni invitation's own words, used when its boxes are left empty. */
function ner_michoel_home_banner_alumni_words() {
	return array(
		'alumni_title'  => __( 'Alumni, log in to stay connected', 'ner-michoel-core' ),
		'alumni_text'   => __( 'Log in (or make your account in a minute) to see the alumni shiurim, save shiurim and pick up where you left off, and keep your details up to date with the Yeshiva.', 'ner-michoel-core' ),
		'alumni_button' => __( 'Log in', 'ner-michoel-core' ),
	);
}

/**
 * The banner as saved (off until the first save).
 *
 * @return array
 */
function ner_michoel_home_banner() {
	$defaults = ner_michoel_home_banner_defaults();
	$saved    = get_option( 'nm_home_banner', null );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}

	$out = array_merge( $defaults, array_intersect_key( $saved, $defaults ) );

	$out['on']            = (bool) $out['on'];
	$out['alumni_on']     = (bool) $out['alumni_on'];
	$out['zoom_on']       = (bool) $out['zoom_on'];
	$places               = ner_michoel_home_banner_places();
	$out['place']         = isset( $places[ $out['place'] ] ) ? $out['place'] : 'home';
	$out['alumni_place']  = isset( $places[ $out['alumni_place'] ] ) ? $out['alumni_place'] : 'home';
	$out['style']         = isset( ner_michoel_home_banner_styles()[ $out['style'] ] ) ? $out['style'] : 'color';
	$out['logo_id']       = (int) $out['logo_id'];
	$out['background_id'] = (int) $out['background_id'];
	foreach ( array( 'kicker', 'title', 'text', 'button_label', 'button_url', 'alumni_title', 'alumni_text', 'alumni_button' ) as $key ) {
		$out[ $key ] = (string) $out[ $key ];
	}
	return $out;
}

/** A picture's address, '' when there is none. */
function ner_michoel_home_banner_image_url( $id, $size = 'large' ) {
	if ( ! $id ) {
		return '';
	}
	$url = wp_get_attachment_image_url( (int) $id, $size );
	return $url ? $url : '';
}

/**
 * What the theme prints: the banner (null when it is off or has nothing to say), the
 * alumni invitation (null when it is off) and the Zoom line (null when it is off or there is
 * no link or schedule). Pictures as addresses, the alumni words with the site's own words in
 * empty boxes. The banner and the invitation each carry 'place' and 'key': a short hash of
 * the words, which the popup uses to show again when they change.
 *
 * @return array{banner: array|null, alumni: array|null, zoom: array|null}
 */
function ner_michoel_home_banner_public() {
	$b      = ner_michoel_home_banner();
	$banner = null;
	if ( $b['on'] && ( '' !== trim( $b['title'] ) || '' !== trim( $b['text'] ) || $b['logo_id'] ) ) {
		$banner = array(
			'style'        => $b['style'],
			'kicker'       => $b['kicker'],
			'title'        => $b['title'],
			'text'         => $b['text'],
			'logo'         => ner_michoel_home_banner_image_url( $b['logo_id'], 'medium' ),
			'background'   => 'image' === $b['style'] ? ner_michoel_home_banner_image_url( $b['background_id'], 'full' ) : '',
			'button_label' => $b['button_label'],
			'button_url'   => $b['button_url'],
		);
		if ( 'image' === $banner['style'] && '' === $banner['background'] ) {
			$banner['style'] = 'color';
		}
		$banner['place'] = $b['place'];
		$banner['key']   = substr( md5( wp_json_encode( array( $b['kicker'], $b['title'], $b['text'], $b['logo_id'], $b['button_label'], $b['button_url'] ) ) ), 0, 10 );
	}

	$alumni = null;
	if ( $b['alumni_on'] ) {
		$words  = ner_michoel_home_banner_alumni_words();
		$alumni = array();
		foreach ( $words as $key => $default ) {
			$alumni[ substr( $key, 7 ) ] = '' !== trim( $b[ $key ] ) ? $b[ $key ] : $default;
		}
		$alumni['url']   = home_url( '/account/' );
		$alumni['place'] = $b['alumni_place'];
		$alumni['key']   = substr( md5( wp_json_encode( array( $alumni['title'], $alumni['text'], $alumni['button'] ) ) ), 0, 10 );
	}

	$zoom = null;
	if ( $b['zoom_on'] && function_exists( 'ner_michoel_live_shiur_is_set' ) && ner_michoel_live_shiur_is_set() ) {
		$zoom = ner_michoel_get_live_shiur();
	}

	return array(
		'banner' => $banner,
		'alumni' => $alumni,
		'zoom'   => $zoom,
	);
}

/** What the Control Panel screen shows when it opens: the values, and each picture's address. */
function ner_michoel_home_banner_settings_values() {
	$values                       = ner_michoel_home_banner();
	if ( function_exists( 'ner_michoel_get_live_shiur' ) ) {
		$values = array_merge( $values, array_intersect_key( ner_michoel_get_live_shiur(), array_flip( ner_michoel_home_banner_zoom_keys() ) ) );
	}
	$values['logo_id_url']        = ner_michoel_home_banner_image_url( $values['logo_id'], 'medium' );
	$values['background_id_url']  = ner_michoel_home_banner_image_url( $values['background_id'], 'medium' );
	return $values;
}

function ner_michoel_register_home_banner_route() {
	register_rest_route(
		'ner-michoel/v1',
		'/home-banner-settings',
		array(
			'methods'             => 'POST',
			'callback'            => 'ner_michoel_handle_home_banner_rest',
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
		)
	);
}
add_action( 'rest_api_init', 'ner_michoel_register_home_banner_route' );

/**
 * Saves the screen. A key that is not sent keeps what it had.
 */
function ner_michoel_handle_home_banner_rest( WP_REST_Request $request ) {
	$body = $request->get_json_params();
	if ( ! is_array( $body ) ) {
		$body = $request->get_params();
	}

	$new = ner_michoel_home_banner();

	foreach ( array( 'on', 'alumni_on', 'zoom_on' ) as $key ) {
		if ( array_key_exists( $key, $body ) ) {
			$new[ $key ] = (bool) $body[ $key ];
		}
	}
	if ( isset( $body['style'] ) ) {
		$style = (string) $body['style'];
		if ( ! isset( ner_michoel_home_banner_styles()[ $style ] ) ) {
			return new WP_Error( 'nm_banner_style', __( 'Choose one of the designs on the list.', 'ner-michoel-core' ), array( 'status' => 400 ) );
		}
		$new['style'] = $style;
	}
	foreach ( array( 'place', 'alumni_place' ) as $key ) {
		if ( isset( $body[ $key ] ) ) {
			$place = (string) $body[ $key ];
			if ( ! isset( ner_michoel_home_banner_places()[ $place ] ) ) {
				return new WP_Error( 'nm_banner_place', __( 'Choose one of the places on the list.', 'ner-michoel-core' ), array( 'status' => 400 ) );
			}
			$new[ $key ] = $place;
		}
	}
	foreach ( array( 'kicker', 'title', 'button_label', 'alumni_title', 'alumni_button' ) as $key ) {
		if ( isset( $body[ $key ] ) ) {
			$new[ $key ] = sanitize_text_field( (string) $body[ $key ] );
		}
	}
	foreach ( array( 'text', 'alumni_text' ) as $key ) {
		if ( isset( $body[ $key ] ) ) {
			$new[ $key ] = sanitize_textarea_field( (string) $body[ $key ] );
		}
	}
	if ( isset( $body['button_url'] ) ) {
		$url = trim( (string) $body['button_url'] );
		if ( '' !== $url && 0 === strpos( $url, '/' ) ) {
			$url = home_url( $url );
		}
		$new['button_url'] = '' === $url ? '' : esc_url_raw( $url );
		if ( '' !== $url && '' === $new['button_url'] ) {
			return new WP_Error( 'nm_banner_url', __( 'The button’s address is not a web address. Paste the whole address, starting with https://', 'ner-michoel-core' ), array( 'status' => 400 ) );
		}
	}
	foreach ( array( 'logo_id', 'background_id' ) as $key ) {
		if ( array_key_exists( $key, $body ) ) {
			$id = (int) $body[ $key ];
			if ( $id && ! wp_attachment_is_image( $id ) ) {
				return new WP_Error( 'nm_banner_image', __( 'That file is not a picture. Choose a picture.', 'ner-michoel-core' ), array( 'status' => 400 ) );
			}
			$new[ $key ] = $id;
		}
	}
	if ( 'image' === $new['style'] && ! $new['background_id'] ) {
		return new WP_Error( 'nm_banner_background', __( 'The design “The background picture” needs a background picture. Choose one, or pick another design.', 'ner-michoel-core' ), array( 'status' => 400 ) );
	}

	// The Zoom line's details live with the Live Shiur / Zoom screen's.
	$zoom_sent = array_intersect_key( $body, array_flip( ner_michoel_home_banner_zoom_keys() ) );
	if ( $zoom_sent && function_exists( 'ner_michoel_sanitize_live_shiur' ) ) {
		$live = ner_michoel_sanitize_live_shiur( array_merge( ner_michoel_get_live_shiur(), $zoom_sent ) );
		if ( isset( $zoom_sent['zoom_link'] ) && '' !== trim( (string) $zoom_sent['zoom_link'] ) && '' === $live['zoom_link'] ) {
			return new WP_Error( 'nm_banner_zoom', __( 'The Zoom link is not a web address. Paste the whole link, starting with https://', 'ner-michoel-core' ), array( 'status' => 400 ) );
		}
		update_option( 'nm_live_shiur', $live );
	}

	update_option( 'nm_home_banner', array_intersect_key( $new, ner_michoel_home_banner_defaults() ) );

	if ( function_exists( 'ner_michoel_purge_page_caches' ) ) {
		ner_michoel_purge_page_caches();
	}

	return new WP_REST_Response( ner_michoel_home_banner_settings_values(), 200 );
}
