<?php
/**
 * Site navigation options (Site Control Panel > Site Settings > Menu).
 *
 * Right now there's one: whether "Written Shiurim" is listed under the
 * Shiurim item of the site header menu. The menu itself is built in
 * Appearance > Menus; the theme adds the entry at render time
 * (ner-michoel-child/inc/navigation.php), so this option only decides
 * whether that happens. Nothing here writes to the menu.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * On by default. The option is only written once an admin saves the Menu
 * tab, so a fresh install shows the entry without any setup step.
 */
function ner_michoel_written_in_shiurim_menu_enabled() {
	return '1' === (string) get_option( 'nm_menu_written_in_shiurim', '1' );
}

function ner_michoel_register_navigation_settings_route() {
	register_rest_route(
		'ner-michoel/v1',
		'/navigation-settings',
		array(
			'methods'             => 'POST',
			'callback'            => 'ner_michoel_handle_navigation_settings_rest',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		)
	);
}
add_action( 'rest_api_init', 'ner_michoel_register_navigation_settings_route' );

function ner_michoel_handle_navigation_settings_rest( WP_REST_Request $request ) {
	update_option(
		'nm_menu_written_in_shiurim',
		rest_sanitize_boolean( $request->get_param( 'written_in_shiurim' ) ) ? '1' : '0'
	);

	return new WP_REST_Response(
		array(
			'written_in_shiurim' => ner_michoel_written_in_shiurim_menu_enabled(),
		),
		200
	);
}
