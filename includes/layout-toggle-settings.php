<?php
/**
 * Settings for the Modern/Classic/24Six layout toggle's on-screen
 * placement and background darkness — rendered by the theme
 * (ner_michoel_render_layout_toggle() in inc/template-tags.php) but
 * configured here, same split as every other admin-managed piece of
 * the site (Homepage Slider images, Appearance colors, etc.).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every valid position value, shared between the settings form and
 * the getter's own validation so they can't drift out of sync.
 */
function ner_michoel_layout_toggle_positions() {
	return array(
		'top-left'      => __( 'Top left', 'ner-michoel-core' ),
		'top-center'    => __( 'Top center', 'ner-michoel-core' ),
		'top-right'     => __( 'Top right (default)', 'ner-michoel-core' ),
		'center-left'   => __( 'Center left', 'ner-michoel-core' ),
		'center-center' => __( 'Center center', 'ner-michoel-core' ),
		'center-right'  => __( 'Center right', 'ner-michoel-core' ),
		'bottom-left'   => __( 'Bottom left', 'ner-michoel-core' ),
		'bottom-center' => __( 'Bottom center', 'ner-michoel-core' ),
		'bottom-right'  => __( 'Bottom right', 'ner-michoel-core' ),
	);
}

function ner_michoel_layout_toggle_settings() {
	return wp_parse_args(
		get_option( 'nm_layout_toggle_settings', array() ),
		array(
			'position' => 'top-right',
			'opacity'  => 100,
		)
	);
}

/**
 * Validated against the real option list rather than trusting
 * whatever's stored — a renamed/removed position from a future
 * version shouldn't be able to render a toggle with no valid CSS
 * class matching it.
 */
function ner_michoel_get_layout_toggle_position() {
	$settings = ner_michoel_layout_toggle_settings();
	$valid    = ner_michoel_layout_toggle_positions();
	return isset( $valid[ $settings['position'] ] ) ? $settings['position'] : 'top-right';
}

/**
 * 0 (fully see-through) to 100 (fully solid) — how dark/opaque the
 * toggle's pill background is, independent of its position.
 */
function ner_michoel_get_layout_toggle_opacity() {
	$settings = ner_michoel_layout_toggle_settings();
	return max( 0, min( 100, (int) $settings['opacity'] ) );
}

function ner_michoel_render_layout_toggle_settings_page() {
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'ner-michoel-core' ) );
	}

	$saved = false;
	if ( isset( $_POST['nm_layout_toggle_nonce'] ) && wp_verify_nonce( $_POST['nm_layout_toggle_nonce'], 'nm_save_layout_toggle' ) ) {
		ner_michoel_save_layout_toggle_settings();
		$saved = true;
	}

	$settings = ner_michoel_layout_toggle_settings();
	?>
	<div class="wrap nm-dashboard">
		<h1><?php esc_html_e( 'Layout Toggle', 'ner-michoel-core' ); ?></h1>
		<p><?php esc_html_e( 'Controls the Modern / Classic / 24Six switcher shown on Shiurim, Galleries, and News pages.', 'ner-michoel-core' ); ?></p>

		<?php if ( $saved ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Layout toggle settings saved.', 'ner-michoel-core' ); ?></p></div>
		<?php endif; ?>

		<form method="post" style="max-width:500px;">
			<?php wp_nonce_field( 'nm_save_layout_toggle', 'nm_layout_toggle_nonce' ); ?>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="nm_layout_toggle_position"><?php esc_html_e( 'Placement', 'ner-michoel-core' ); ?></label></th>
					<td>
						<select id="nm_layout_toggle_position" name="nm_layout_toggle_position">
							<?php foreach ( ner_michoel_layout_toggle_positions() as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['position'], $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="nm_layout_toggle_opacity"><?php esc_html_e( 'Background darkness', 'ner-michoel-core' ); ?></label></th>
					<td>
						<input type="number" id="nm_layout_toggle_opacity" name="nm_layout_toggle_opacity" value="<?php echo esc_attr( $settings['opacity'] ); ?>" min="0" max="100" step="5" style="width:80px;" />%
						<p class="description"><?php esc_html_e( '0 = fully see-through, 100 = fully solid/dark.', 'ner-michoel-core' ); ?></p>
					</td>
				</tr>
			</table>
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'ner-michoel-core' ); ?></button></p>
		</form>
	</div>
	<?php
}

function ner_michoel_save_layout_toggle_settings() {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	$position = isset( $_POST['nm_layout_toggle_position'] ) ? sanitize_key( wp_unslash( $_POST['nm_layout_toggle_position'] ) ) : 'top-right';
	$opacity  = isset( $_POST['nm_layout_toggle_opacity'] ) ? absint( $_POST['nm_layout_toggle_opacity'] ) : 100;

	if ( ! isset( ner_michoel_layout_toggle_positions()[ $position ] ) ) {
		$position = 'top-right';
	}

	update_option(
		'nm_layout_toggle_settings',
		array(
			'position' => $position,
			'opacity'  => max( 0, min( 100, $opacity ) ),
		)
	);
}
