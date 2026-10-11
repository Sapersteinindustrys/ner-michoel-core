<?php
/**
 * Settings behind the Control Panel's Homepage screen: what the new homepage
 * (the theme's template-parts/home-new.php) actually shows.
 *
 *   - The words: the small line above the welcome, the welcome title, the
 *     paragraph under it, and the title and text of the bottom "Stay Connected"
 *     banner. Blank means the theme's own words.
 *   - The photos beside the welcome, and the seconds each one stays up.
 *
 * The photos and timing are the same options the homepage slider always used
 * (nm_homepage_slider, nm_homepage_slider_interval, homepage-slider.php). The
 * new homepage shows only each slide's picture, so the screen offers only the
 * picture; the heading, text and button an older slide may still carry are kept
 * in the option, not shown and not deleted.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The editable words: key => max length, and whether the text is a paragraph.
 */
function ner_michoel_homepage_text_fields() {
	return array(
		'eyebrow'   => array( 'max' => 80, 'multiline' => false ),
		'title'     => array( 'max' => 80, 'multiline' => false ),
		'lede'      => array( 'max' => 300, 'multiline' => true ),
		'cta_title' => array( 'max' => 60, 'multiline' => false ),
		'cta_text'  => array( 'max' => 300, 'multiline' => true ),
	);
}

/**
 * What the theme says when a field is blank. Shown as the box's placeholder so
 * an admin sees what a blank box means; the theme keeps its own copy of these
 * words (inc/home.php), used when this plugin is behind.
 */
function ner_michoel_homepage_text_defaults() {
	return array(
		'eyebrow'   => __( 'Yeshivas Toras Moshe Alumni Association', 'ner-michoel-core' ),
		'title'     => __( 'Welcome to Ner Michoel', 'ner-michoel-core' ),
		'lede'      => __( 'Thousands of shiurim, community updates, and a way to stay close to the Yeshiva and each other — wherever you are.', 'ner-michoel-core' ),
		'cta_title' => __( 'Stay Connected', 'ner-michoel-core' ),
		'cta_text'  => __( 'Update your contact info, join the alumni WhatsApp group, or reach out any time — we\'d love to hear how you\'re doing.', 'ner-michoel-core' ),
	);
}

/**
 * One saved word, or '' when it was left blank (the theme then uses its own).
 */
function ner_michoel_get_homepage_text( $key ) {
	$saved = get_option( 'nm_homepage_text', array() );
	if ( ! is_array( $saved ) || ! isset( $saved[ $key ] ) || ! is_string( $saved[ $key ] ) ) {
		return '';
	}
	return $saved[ $key ];
}

/**
 * What the Homepage screen shows when it opens.
 */
function ner_michoel_homepage_settings_values() {
	$values = array(
		'interval' => ner_michoel_get_homepage_slider_interval_seconds(),
		'slides'   => array(),
	);
	foreach ( array_keys( ner_michoel_homepage_text_fields() ) as $key ) {
		$values[ $key ] = ner_michoel_get_homepage_text( $key );
	}
	foreach ( ner_michoel_get_homepage_slider() as $slide ) {
		$image_id          = isset( $slide['image_id'] ) ? (int) $slide['image_id'] : 0;
		$values['slides'][] = array(
			'image_id'  => $image_id,
			'image_url' => $image_id ? (string) wp_get_attachment_image_url( $image_id, 'thumbnail' ) : '',
		);
	}
	return $values;
}

function ner_michoel_register_homepage_settings_rest_route() {
	register_rest_route(
		'ner-michoel/v1',
		'/homepage-settings',
		array(
			'methods'             => 'POST',
			'callback'            => 'ner_michoel_handle_homepage_settings_rest',
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
		)
	);
}
add_action( 'rest_api_init', 'ner_michoel_register_homepage_settings_rest_route' );

/**
 * Saves {eyebrow, title, lede, cta_title, cta_text, interval, slides: [{image_id}]}.
 * A key that is not sent is left as it was.
 */
function ner_michoel_handle_homepage_settings_rest( WP_REST_Request $request ) {
	$body = $request->get_json_params();
	if ( ! is_array( $body ) ) {
		$body = array();
	}

	$text = get_option( 'nm_homepage_text', array() );
	$text = is_array( $text ) ? $text : array();
	foreach ( ner_michoel_homepage_text_fields() as $key => $field ) {
		if ( ! isset( $body[ $key ] ) ) {
			continue;
		}
		$value = is_string( $body[ $key ] ) ? $body[ $key ] : '';
		$value = $field['multiline'] ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
		$value = trim( function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $field['max'] ) : substr( $value, 0, $field['max'] ) );
		if ( '' === $value ) {
			unset( $text[ $key ] );
		} else {
			$text[ $key ] = $value;
		}
	}
	update_option( 'nm_homepage_text', $text );

	if ( isset( $body['slides'] ) && is_array( $body['slides'] ) ) {
		// What an older slide carried besides its picture is kept, matched by picture.
		$previous = array();
		foreach ( ner_michoel_get_homepage_slider() as $slide ) {
			$old_id = isset( $slide['image_id'] ) ? (int) $slide['image_id'] : 0;
			if ( $old_id && ! isset( $previous[ $old_id ] ) ) {
				$previous[ $old_id ] = $slide;
			}
		}
		$slides = array();
		foreach ( $body['slides'] as $row ) {
			$image_id = ( is_array( $row ) && isset( $row['image_id'] ) ) ? absint( $row['image_id'] ) : 0;
			if ( ! $image_id ) {
				continue;
			}
			$kept     = isset( $previous[ $image_id ] ) ? $previous[ $image_id ] : array();
			$slides[] = array_merge(
				array(
					'heading'   => '',
					'subtext'   => '',
					'link_url'  => '',
					'link_text' => '',
				),
				$kept,
				array( 'image_id' => $image_id )
			);
		}
		update_option( 'nm_homepage_slider', $slides );
	}

	if ( isset( $body['interval'] ) ) {
		$interval = absint( $body['interval'] );
		update_option( 'nm_homepage_slider_interval', $interval ? max( 2, min( 60, $interval ) ) : 6 );
	}

	if ( function_exists( 'ner_michoel_purge_page_caches' ) ) {
		ner_michoel_purge_page_caches();
	}

	return new WP_REST_Response( array( 'saved' => true ), 200 );
}
