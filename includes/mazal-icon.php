<?php
/**
 * The icon in the round gold badge at the top of the homepage's Mazal Tov box, which an administrator
 * can change on the Control Panel's Homepage screen (Homepage & Look > Homepage, "Mazal Tov icon").
 *
 * The choice is one of the ready-made icons below, or the administrator's own picture. It is kept in
 * one option, nm_mazal_icon: {choice: an icon's key, or "image", image_id: the picture's attachment id}.
 * Until something is saved the box has the default icon (a kiddush cup); the sparkle it had before is
 * still on the list, as "Sparkle (the original)".
 *
 * The theme (ner-michoel-child, template-parts/home-new.php) asks ner_michoel_get_mazal_icon() for what
 * to draw and does the drawing and styling, so a redesign of the theme keeps the choice. It keeps its
 * own copy of the default icon for a plugin that is behind.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The ready-made icons: key => label, style and the inside of a 24 x 24 picture. "line" icons are
 * outlines drawn in the badge's own color; "fill" ones are solid shapes. All of them take their color
 * from the text color around them (currentColor).
 */
function ner_michoel_mazal_icons() {
	return array(
		'cup'     => array(
			'label' => __( 'Kiddush cup', 'ner-michoel-core' ),
			'style' => 'line',
			'inner' => '<path d="M7 3h10l-.8 6.6a4.2 4.2 0 0 1-8.4 0z"/><path d="M7.6 7h8.8"/><path d="M12 13.8v5.7"/><path d="M8 21h8"/>',
		),
		'popper'  => array(
			'label' => __( 'Party popper', 'ner-michoel-core' ),
			'style' => 'line',
			'inner' => '<path d="M3.5 20.5 7.7 9.2l7.1 7.1z"/><path d="M6.2 14.7l3.2 3.2"/><path d="M11 6.6c0-1.5 1-2.3 1.7-3.1"/><path d="M15.6 10.6c1.4-.4 2.5.1 3.3-.9"/><path d="M17.4 15.2c1.2.3 2.2 1 2.9 2.1"/><path d="M15 3.4h.01"/><path d="M20.6 5.6h.01"/><path d="M20.2 12.6h.01"/>',
		),
		'star'    => array(
			'label' => __( 'Star of David', 'ner-michoel-core' ),
			'style' => 'line',
			'inner' => '<path d="M12 3.2 19.6 16.4H4.4z"/><path d="M12 20.8 4.4 7.6h15.2z"/>',
		),
		'heart'   => array(
			'label' => __( 'Heart', 'ner-michoel-core' ),
			'style' => 'line',
			'inner' => '<path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/>',
		),
		'gift'    => array(
			'label' => __( 'Gift', 'ner-michoel-core' ),
			'style' => 'line',
			'inner' => '<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M5 12v8a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-8"/><path d="M12 8v13"/><path d="M12 8H8.2a2.4 2.4 0 1 1 0-4.8C10.8 3.2 12 8 12 8z"/><path d="M12 8h3.8a2.4 2.4 0 1 0 0-4.8C13.2 3.2 12 8 12 8z"/>',
		),
		'rings'   => array(
			'label' => __( 'Wedding rings', 'ner-michoel-core' ),
			'style' => 'line',
			'inner' => '<circle cx="8.6" cy="12" r="5.6"/><circle cx="15.4" cy="12" r="5.6"/>',
		),
		'balloon' => array(
			'label' => __( 'Balloon', 'ner-michoel-core' ),
			'style' => 'line',
			'inner' => '<path d="M12 2.6c3.5 0 5.9 2.6 5.9 6 0 3.6-2.6 6.4-5.9 6.9-3.3-.5-5.9-3.3-5.9-6.9 0-3.4 2.4-6 5.9-6z"/><path d="M11 15.7l-.7 1.9h3.4L13 15.7"/><path d="M12 17.6c-1.2 1.5 1.2 2.7 0 4.4"/>',
		),
		'sparkle' => array(
			'label' => __( 'Sparkle (the original)', 'ner-michoel-core' ),
			'style' => 'fill',
			'inner' => '<path d="M12 2.5l1.9 5.6 5.6 1.9-5.6 1.9L12 17.5l-1.9-5.6L4.5 10l5.6-1.9L12 2.5z"/><path d="M19 15.5l.8 2.2 2.2.8-2.2.8L19 21.5l-.8-2.2-2.2-.8 2.2-.8.8-2.2z" opacity=".7"/>',
		),
	);
}

/**
 * What the badge shows until an icon is saved.
 */
function ner_michoel_mazal_icon_default() {
	return 'cup';
}

/**
 * One ready-made icon as a picture ("" for a key that isn't on the list).
 */
function ner_michoel_mazal_icon_svg( $key, $size = 22 ) {
	$icons = ner_michoel_mazal_icons();
	if ( ! isset( $icons[ $key ] ) ) {
		return '';
	}
	$icon  = $icons[ $key ];
	$attrs = 'fill' === $icon['style']
		? 'fill="currentColor"'
		: 'fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"';

	// The paths are fixed strings written above, not user input.
	return sprintf( '<svg class="nm-mazal-icon" viewBox="0 0 24 24" width="%1$d" height="%1$d" %2$s aria-hidden="true" focusable="false">%3$s</svg>', (int) $size, $attrs, $icon['inner'] );
}

/**
 * The saved choice as it is kept, with anything of the wrong kind left out.
 */
function ner_michoel_mazal_icon_saved() {
	$raw = get_option( 'nm_mazal_icon', array() );
	if ( ! is_array( $raw ) ) {
		$raw = array();
	}
	return array(
		'choice'   => ( isset( $raw['choice'] ) && is_string( $raw['choice'] ) ) ? $raw['choice'] : '',
		'image_id' => isset( $raw['image_id'] ) ? absint( $raw['image_id'] ) : 0,
	);
}

/**
 * The address of a picture used as the icon: its small size, or the original when no small one exists
 * ('' when the picture is gone or isn't an image).
 */
function ner_michoel_mazal_icon_image_url( $image_id ) {
	$image_id = absint( $image_id );
	if ( ! $image_id || ! wp_attachment_is_image( $image_id ) ) {
		return '';
	}
	$url = (string) wp_get_attachment_image_url( $image_id, 'thumbnail' );
	return '' !== $url ? $url : (string) wp_get_attachment_url( $image_id );
}

/**
 * What the theme draws in the badge:
 *
 *   choice    the icon's key, or "image"
 *   kind      "svg" (a ready-made icon) or "image" (the administrator's own picture)
 *   svg       the icon as a picture, for kind "svg"
 *   url       the picture's address, for kind "image"
 *   image_id  the picture's attachment id, for kind "image"
 *
 * A picture that has been deleted since it was chosen gives the default icon, never an empty badge.
 */
function ner_michoel_get_mazal_icon() {
	$saved = ner_michoel_mazal_icon_saved();

	if ( 'image' === $saved['choice'] ) {
		$url = ner_michoel_mazal_icon_image_url( $saved['image_id'] );
		if ( '' !== $url ) {
			return array(
				'choice'   => 'image',
				'kind'     => 'image',
				'svg'      => '',
				'url'      => $url,
				'image_id' => $saved['image_id'],
			);
		}
	}

	$icons = ner_michoel_mazal_icons();
	$key   = isset( $icons[ $saved['choice'] ] ) ? $saved['choice'] : ner_michoel_mazal_icon_default();
	return array(
		'choice'   => $key,
		'kind'     => 'svg',
		'svg'      => ner_michoel_mazal_icon_svg( $key ),
		'url'      => '',
		'image_id' => 0,
	);
}

/**
 * The Homepage screen's value for the icon: what is chosen, and the last picture, kept even while a
 * ready-made icon is chosen so that choosing "Your own picture" again brings it back.
 */
function ner_michoel_mazal_icon_panel_value() {
	$icon  = ner_michoel_get_mazal_icon();
	$saved = ner_michoel_mazal_icon_saved();
	$url   = ner_michoel_mazal_icon_image_url( $saved['image_id'] );
	return array(
		'choice'    => $icon['choice'],
		'image_id'  => '' !== $url ? $saved['image_id'] : '',
		'image_url' => $url,
	);
}

/**
 * The ready-made icons for the Homepage screen's picker: key => label and picture.
 */
function ner_michoel_mazal_icon_panel_options() {
	$out = array();
	foreach ( ner_michoel_mazal_icons() as $key => $icon ) {
		$out[ $key ] = array(
			'label' => $icon['label'],
			'svg'   => ner_michoel_mazal_icon_svg( $key, 24 ),
		);
	}
	return $out;
}

/**
 * What the Homepage screen sent, as it will be kept, or a WP_Error saying what to fix. Checked before
 * the screen's other settings are saved, so a mistake here saves nothing.
 *
 * @param mixed $input {choice, image_id}.
 * @return array|WP_Error
 */
function ner_michoel_mazal_icon_from_input( $input ) {
	if ( ! is_array( $input ) ) {
		return new WP_Error( 'nm_mazal_icon', __( 'The icon was not sent properly. Choose one from the list.', 'ner-michoel-core' ), array( 'status' => 400 ) );
	}

	$choice   = ( isset( $input['choice'] ) && is_string( $input['choice'] ) ) ? sanitize_key( $input['choice'] ) : '';
	$image_id = isset( $input['image_id'] ) ? absint( $input['image_id'] ) : 0;
	$icons    = ner_michoel_mazal_icons();

	if ( 'image' === $choice ) {
		if ( ! $image_id ) {
			return new WP_Error( 'nm_mazal_icon_image', __( 'Choose a picture for the icon, or pick one of the ready-made icons.', 'ner-michoel-core' ), array( 'status' => 400 ) );
		}
		if ( ! wp_attachment_is_image( $image_id ) ) {
			return new WP_Error( 'nm_mazal_icon_image', __( 'That file is not a picture, so it can’t be the icon. Choose an image.', 'ner-michoel-core' ), array( 'status' => 400 ) );
		}
		return array(
			'choice'   => 'image',
			'image_id' => $image_id,
		);
	}

	if ( ! isset( $icons[ $choice ] ) ) {
		return new WP_Error( 'nm_mazal_icon_choice', __( 'Choose an icon from the list.', 'ner-michoel-core' ), array( 'status' => 400 ) );
	}
	return array(
		'choice'   => $choice,
		'image_id' => ( $image_id && wp_attachment_is_image( $image_id ) ) ? $image_id : 0,
	);
}
