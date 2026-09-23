<?php
/**
 * "Appearance" settings — colors and font for the Modern/Classic app
 * system (Shiurim, Galleries, News & Events, the player bar), so the
 * admin can theme those screens without a developer touching CSS.
 *
 * Writes plain options; ner-michoel-child reads them (see
 * inc/appearance.php there) and outputs a small CSS override block
 * setting the matching --sh-* custom properties in custom.css. Nothing
 * here renders on the front end itself — this is purely the settings
 * UI and the values it stores. Option names are a fixed contract with
 * the theme, not just a naming convention:
 * nm_color_bg / nm_color_surface / nm_color_text / nm_color_accent /
 * nm_font_family.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One place listing every field, so the render/save functions and the
 * "reset" handling below can't drift out of sync with each other.
 */
function ner_michoel_appearance_fields() {
	return array(
		'nm_color_bg'      => array(
			'type'    => 'color',
			'label'   => __( 'Background', 'ner-michoel-core' ),
			'desc'    => __( 'The app\'s overall background color.', 'ner-michoel-core' ),
			'default' => '#121212',
		),
		'nm_color_surface' => array(
			'type'    => 'color',
			'label'   => __( 'Surface', 'ner-michoel-core' ),
			'desc'    => __( 'Cards and the player bar\'s background.', 'ner-michoel-core' ),
			'default' => '#181818',
		),
		'nm_color_text'    => array(
			'type'    => 'color',
			'label'   => __( 'Text', 'ner-michoel-core' ),
			'desc'    => __( 'Primary text color.', 'ner-michoel-core' ),
			'default' => '#ffffff',
		),
		'nm_color_accent'  => array(
			'type'    => 'color',
			'label'   => __( 'Accent', 'ner-michoel-core' ),
			'desc'    => __( 'Buttons, active states, and the player\'s progress fill — the color that most visibly "recolors" the player.', 'ner-michoel-core' ),
			'default' => '#2f8f5b',
		),
		'nm_font_family'   => array(
			'type'    => 'text',
			'label'   => __( 'Font', 'ner-michoel-core' ),
			'desc'    => __( 'A CSS font-family value, e.g. Georgia, \'Times New Roman\', serif. Leave blank for the default system font.', 'ner-michoel-core' ),
			'default' => '',
		),
	);
}

function ner_michoel_render_appearance_settings_page() {
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'ner-michoel-core' ) );
	}

	$saved = false;
	if ( isset( $_POST['nm_appearance_nonce'] ) && wp_verify_nonce( $_POST['nm_appearance_nonce'], 'nm_save_appearance' ) ) {
		ner_michoel_save_appearance_settings();
		$saved = true;
	}

	$fields = ner_michoel_appearance_fields();
	?>
	<div class="wrap nm-dashboard">
		<h1><?php esc_html_e( 'Appearance', 'ner-michoel-core' ); ?></h1>
		<p><?php esc_html_e( 'Colors and font for the Shiurim / Galleries / News & Events app, including the player bar. Leave a field blank to fall back to the site\'s default look.', 'ner-michoel-core' ); ?></p>

		<?php if ( $saved ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Appearance settings saved.', 'ner-michoel-core' ); ?></p></div>
		<?php endif; ?>

		<form method="post">
			<?php wp_nonce_field( 'nm_save_appearance', 'nm_appearance_nonce' ); ?>
			<table class="form-table">
				<?php foreach ( $fields as $key => $field ) : ?>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $field['label'] ); ?></label></th>
						<td>
							<?php if ( 'color' === $field['type'] ) : ?>
								<input
									type="text"
									id="<?php echo esc_attr( $key ); ?>"
									name="<?php echo esc_attr( $key ); ?>"
									class="nm-color-field"
									data-default-color="<?php echo esc_attr( $field['default'] ); ?>"
									value="<?php echo esc_attr( get_option( $key, '' ) ); ?>"
								/>
							<?php else : ?>
								<input
									type="text"
									id="<?php echo esc_attr( $key ); ?>"
									name="<?php echo esc_attr( $key ); ?>"
									class="regular-text"
									value="<?php echo esc_attr( get_option( $key, '' ) ); ?>"
								/>
							<?php endif; ?>
							<p class="description"><?php echo esc_html( $field['desc'] ); ?></p>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>
			<p>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'ner-michoel-core' ); ?></button>
			</p>
		</form>
	</div>
	<script>
	( function( $ ) {
		$( '.nm-color-field' ).wpColorPicker();
	} )( jQuery );
	</script>
	<?php
}

function ner_michoel_save_appearance_settings() {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	foreach ( ner_michoel_appearance_fields() as $key => $field ) {
		if ( ! isset( $_POST[ $key ] ) ) {
			continue;
		}
		$raw = wp_unslash( $_POST[ $key ] );

		if ( 'color' === $field['type'] ) {
			$value = sanitize_hex_color( $raw );
		} else {
			$value = sanitize_text_field( $raw );
		}

		if ( '' === $value || null === $value ) {
			delete_option( $key ); // Blank = fall back to the theme's own default.
		} else {
			update_option( $key, $value );
		}
	}
}

function ner_michoel_appearance_admin_assets( $hook ) {
	$screen = get_current_screen();
	if ( ! $screen || false === strpos( $screen->id, 'nm-appearance' ) ) {
		return;
	}
	wp_enqueue_style( 'wp-color-picker' );
	wp_enqueue_script( 'wp-color-picker' );
}
add_action( 'admin_enqueue_scripts', 'ner_michoel_appearance_admin_assets' );
