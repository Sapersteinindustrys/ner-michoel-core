<?php
/**
 * Generic read/save engine behind the custom /admin UI's Settings
 * screens (Appearance, Layout Toggle, Hero Slider, Admin Login
 * Shortcut, Storage) — same idea as custom-admin-api.php's content
 * registry, but for singleton option groups instead of repeatable
 * records: one schema per screen, one GET route to read current
 * values, and each screen's own existing (or newly added) POST REST
 * route to save — reusing music-4d's appearance-settings/storage-
 * settings routes as-is rather than replacing them.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ner_michoel_settings_registry() {
	return array(
		'appearance'    => array(
			'capability' => 'manage_options',
			'label'      => __( 'Appearance', 'ner-michoel-core' ),
			'rest_path'  => 'appearance-settings',
			'fields'     => array(
				'nm_color_bg'      => array( 'type' => 'color', 'label' => __( 'Background', 'ner-michoel-core' ), 'desc' => __( 'The app\'s overall background color.', 'ner-michoel-core' ) ),
				'nm_color_surface' => array( 'type' => 'color', 'label' => __( 'Surface', 'ner-michoel-core' ), 'desc' => __( 'Cards and the player bar\'s background.', 'ner-michoel-core' ) ),
				'nm_color_text'    => array( 'type' => 'color', 'label' => __( 'Text', 'ner-michoel-core' ), 'desc' => __( 'Primary text color.', 'ner-michoel-core' ) ),
				'nm_color_accent'  => array( 'type' => 'color', 'label' => __( 'Accent', 'ner-michoel-core' ), 'desc' => __( 'Buttons, active states, and the player\'s progress fill.', 'ner-michoel-core' ) ),
				'nm_font_family'   => array( 'type' => 'text', 'label' => __( 'Font', 'ner-michoel-core' ), 'desc' => __( 'A CSS font-family value, e.g. Georgia, serif. Leave blank for the default.', 'ner-michoel-core' ) ),
			),
			'get_values' => function () {
				$out = array();
				foreach ( ner_michoel_appearance_fields() as $key => $field ) {
					$out[ $key ] = get_option( $key, $field['default'] );
				}
				return $out;
			},
			'get_extra'  => function () {
				return array( 'palettes' => ner_michoel_appearance_palettes() );
			},
		),
		'storage'       => array(
			'capability' => 'manage_options',
			'label'      => __( 'Storage (Bunny)', 'ner-michoel-core' ),
			'rest_path'  => 'storage-settings',
			'fields'     => array(
				'enabled'            => array( 'type' => 'checkbox', 'label' => __( 'Offload new uploads to Bunny Storage', 'ner-michoel-core' ) ),
				'zone_name'          => array( 'type' => 'text', 'label' => __( 'Storage Zone name', 'ner-michoel-core' ), 'placeholder' => 'e.g. tomo-shiurim' ),
				'region'             => array( 'type' => 'text', 'label' => __( 'Region', 'ner-michoel-core' ), 'placeholder' => 'ny', 'desc' => __( 'The subdomain prefix in your storage endpoint, e.g. "ny" from ny.storage.bunnycdn.com.', 'ner-michoel-core' ) ),
				'api_key'            => array( 'type' => 'password', 'label' => __( 'API Key (write access)', 'ner-michoel-core' ), 'desc' => __( 'The Storage Zone\'s password/API key from bunny.net — needs write access, not the read-only key.', 'ner-michoel-core' ) ),
				'pull_zone_hostname' => array( 'type' => 'text', 'label' => __( 'Pull Zone hostname', 'ner-michoel-core' ), 'placeholder' => 'e.g. tomo-shiurim.b-cdn.net', 'desc' => __( 'The public CDN hostname connected to this Storage Zone.', 'ner-michoel-core' ) ),
			),
			'get_values' => function () {
				return ner_michoel_bunny_storage_settings();
			},
		),
		'layout_toggle' => array(
			'capability' => 'edit_posts',
			'label'      => __( 'Layout Toggle', 'ner-michoel-core' ),
			'rest_path'  => 'layout-toggle-settings',
			'fields'     => array(
				'position' => array( 'type' => 'select', 'label' => __( 'Placement', 'ner-michoel-core' ), 'options' => ner_michoel_layout_toggle_positions() ),
				'opacity'  => array( 'type' => 'number', 'label' => __( 'Background darkness (%)', 'ner-michoel-core' ), 'min' => 0, 'max' => 100 ),
			),
			'get_values' => function () {
				return ner_michoel_layout_toggle_settings();
			},
		),
		'admin_login'   => array(
			'capability' => 'manage_options',
			'label'      => __( 'Admin Login Shortcut', 'ner-michoel-core' ),
			'rest_path'  => 'admin-login-settings',
			'fields'     => array(
				'new_password'     => array( 'type' => 'password', 'label' => __( 'New Password', 'ner-michoel-core' ) ),
				'confirm_password' => array( 'type' => 'password', 'label' => __( 'Confirm Password', 'ner-michoel-core' ) ),
				'login_as'         => array( 'type' => 'select', 'label' => __( 'Logs In As', 'ner-michoel-core' ) ),
			),
			'get_values' => function () {
				return array(
					'new_password'     => '',
					'confirm_password' => '',
					'login_as'         => (int) get_option( 'nm_admin_panel_user_id' ),
				);
			},
			'get_extra'  => function () {
				$admins = get_users( array( 'capability' => 'manage_options' ) );
				return array(
					'has_password' => (bool) get_option( 'nm_admin_panel_password' ),
					'admin_url'    => trailingslashit( home_url() ) . 'admin',
					'admins'       => array_map(
						function ( $user ) {
							return array( 'id' => $user->ID, 'name' => $user->display_name );
						},
						$admins
					),
				);
			},
		),
		'hero_slider'   => array(
			'capability' => 'edit_posts',
			'label'      => __( 'Hero Slider', 'ner-michoel-core' ),
			'rest_path'  => 'hero-slider-settings',
			'fields'     => array(
				'interval'        => array( 'type' => 'number', 'label' => __( 'Seconds per slide', 'ner-michoel-core' ), 'min' => 2, 'max' => 60 ),
				'button_color'    => array( 'type' => 'color', 'label' => __( 'Slide button color', 'ner-michoel-core' ) ),
				'button_position' => array( 'type' => 'select', 'label' => __( 'Slide button placement', 'ner-michoel-core' ), 'options' => ner_michoel_homepage_slider_button_positions() ),
				'button_shape'    => array(
					'type'    => 'select',
					'label'   => __( 'Slide button shape', 'ner-michoel-core' ),
					'options' => array(
						'pill'    => __( 'Pill (fully rounded)', 'ner-michoel-core' ),
						'rounded' => __( 'Rounded corners', 'ner-michoel-core' ),
						'square'  => __( 'Square corners', 'ner-michoel-core' ),
					),
				),
				'button_opacity'  => array( 'type' => 'number', 'label' => __( 'Slide button opacity (%)', 'ner-michoel-core' ), 'min' => 10, 'max' => 100 ),
				'slides'          => array(
					'type'        => 'repeater',
					'label'       => __( 'Slides', 'ner-michoel-core' ),
					'item_label'  => __( 'Slide', 'ner-michoel-core' ),
					'item_fields' => array(
						'image_id'  => array( 'type' => 'image', 'label' => __( 'Image', 'ner-michoel-core' ) ),
						'heading'   => array( 'type' => 'text', 'label' => __( 'Heading', 'ner-michoel-core' ) ),
						'subtext'   => array( 'type' => 'textarea', 'label' => __( 'Subtext', 'ner-michoel-core' ), 'rows' => 2 ),
						'link_url'  => array( 'type' => 'text', 'label' => __( 'Button Link (optional)', 'ner-michoel-core' ) ),
						'link_text' => array( 'type' => 'text', 'label' => __( 'Button Text (optional)', 'ner-michoel-core' ) ),
					),
				),
			),
			'get_values' => function () {
				$style  = ner_michoel_get_homepage_slider_button_style();
				$slides = ner_michoel_get_homepage_slider();
				foreach ( $slides as &$slide ) {
					$slide['image_url'] = $slide['image_id'] ? wp_get_attachment_image_url( $slide['image_id'], 'thumbnail' ) : '';
				}
				unset( $slide );
				return array(
					'interval'        => ner_michoel_get_homepage_slider_interval_seconds(),
					'button_color'    => $style['color'],
					'button_position' => $style['position'],
					'button_shape'    => $style['shape'],
					'button_opacity'  => $style['opacity'],
					'slides'          => $slides,
				);
			},
		),
	);
}

function ner_michoel_settings_get_config( $key ) {
	$registry = ner_michoel_settings_registry();
	return isset( $registry[ $key ] ) ? $registry[ $key ] : null;
}

function ner_michoel_settings_permission_check( WP_REST_Request $request ) {
	$config = ner_michoel_settings_get_config( $request->get_param( 'key' ) );
	if ( ! $config ) {
		return new WP_Error( 'nm_settings_unknown', __( 'Unknown settings screen.', 'ner-michoel-core' ), array( 'status' => 404 ) );
	}
	if ( ! current_user_can( $config['capability'] ) ) {
		return new WP_Error( 'nm_settings_forbidden', __( "You don't have permission to view this.", 'ner-michoel-core' ), array( 'status' => 403 ) );
	}
	return true;
}

function ner_michoel_register_settings_routes() {
	register_rest_route(
		'ner-michoel/v1',
		'/settings/(?P<key>[a-z_]+)',
		array(
			'methods'             => 'GET',
			'callback'            => 'ner_michoel_settings_route_get',
			'permission_callback' => 'ner_michoel_settings_permission_check',
		)
	);
}
add_action( 'rest_api_init', 'ner_michoel_register_settings_routes' );

function ner_michoel_settings_route_get( WP_REST_Request $request ) {
	$config = ner_michoel_settings_get_config( $request->get_param( 'key' ) );
	$out    = array(
		'label'     => $config['label'],
		'rest_path' => $config['rest_path'],
		'fields'    => $config['fields'],
		'values'    => call_user_func( $config['get_values'] ),
		'extra'     => isset( $config['get_extra'] ) ? call_user_func( $config['get_extra'] ) : new stdClass(),
	);
	return new WP_REST_Response( $out, 200 );
}
