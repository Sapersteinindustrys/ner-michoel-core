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

/**
 * Curated presets so the admin can pick a cohesive look in one click
 * instead of choosing 4 individual colors that have to work together.
 * All built on the same dark-app formula as the default (dark
 * bg/surface, white text) so every preset stays readable — only the
 * accent actually varies much, since that's "the one that visibly
 * recolors the player" per the app's own design. Picking a palette
 * just fills in the color fields below; nothing saves until the admin
 * clicks Save, so it's easy to preview a few before committing.
 */
function ner_michoel_appearance_palettes() {
	return array(
		array(
			'label'   => __( 'Forest (default)', 'ner-michoel-core' ),
			'bg'      => '#121212',
			'surface' => '#181818',
			'text'    => '#ffffff',
			'accent'  => '#2f8f5b',
		),
		array(
			'label'   => __( 'Royal Blue', 'ner-michoel-core' ),
			'bg'      => '#10151f',
			'surface' => '#171e2b',
			'text'    => '#ffffff',
			'accent'  => '#3b6fd6',
		),
		array(
			'label'   => __( 'Burgundy', 'ner-michoel-core' ),
			'bg'      => '#1a1214',
			'surface' => '#241a1d',
			'text'    => '#ffffff',
			'accent'  => '#b23a50',
		),
		array(
			'label'   => __( 'Gold', 'ner-michoel-core' ),
			'bg'      => '#16130d',
			'surface' => '#211c13',
			'text'    => '#ffffff',
			'accent'  => '#d6a13b',
		),
		array(
			'label'   => __( 'Slate', 'ner-michoel-core' ),
			'bg'      => '#16181a',
			'surface' => '#202325',
			'text'    => '#ffffff',
			'accent'  => '#7a8a99',
		),
		array(
			'label'   => __( 'Deep Purple', 'ner-michoel-core' ),
			'bg'      => '#14101c',
			'surface' => '#1d1728',
			'text'    => '#ffffff',
			'accent'  => '#8266dd',
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

		<h2><?php esc_html_e( 'Color Palettes', 'ner-michoel-core' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Click one to fill in the fields below — nothing saves until you click Save, so feel free to try a few first.', 'ner-michoel-core' ); ?></p>
		<div class="nm-palette-grid" style="display:flex;flex-wrap:wrap;gap:14px;margin:16px 0 28px;">
			<?php foreach ( ner_michoel_appearance_palettes() as $palette ) : ?>
				<button
					type="button"
					class="nm-palette-swatch"
					data-bg="<?php echo esc_attr( $palette['bg'] ); ?>"
					data-surface="<?php echo esc_attr( $palette['surface'] ); ?>"
					data-text="<?php echo esc_attr( $palette['text'] ); ?>"
					data-accent="<?php echo esc_attr( $palette['accent'] ); ?>"
					style="border:1px solid #ccd0d4;border-radius:6px;padding:8px;background:#fff;cursor:pointer;width:120px;text-align:left;"
				>
					<span style="display:block;height:36px;border-radius:4px;overflow:hidden;background:<?php echo esc_attr( $palette['bg'] ); ?>;position:relative;">
						<span style="position:absolute;inset:0;left:60%;background:<?php echo esc_attr( $palette['surface'] ); ?>;"></span>
						<span style="position:absolute;bottom:4px;right:4px;width:14px;height:14px;border-radius:50%;background:<?php echo esc_attr( $palette['accent'] ); ?>;"></span>
					</span>
					<span style="display:block;margin-top:6px;font-size:12px;"><?php echo esc_html( $palette['label'] ); ?></span>
				</button>
			<?php endforeach; ?>
		</div>

		<h2><?php esc_html_e( 'Preview', 'ner-michoel-core' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Updates live as you pick a palette or adjust a color below — before you save anything.', 'ner-michoel-core' ); ?></p>
		<div id="nm-appearance-preview" style="max-width:360px;border-radius:8px;padding:20px;margin:16px 0 28px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
			<div id="nm-appearance-preview-card" style="border-radius:8px;padding:16px;">
				<div id="nm-appearance-preview-title" style="font-weight:600;font-size:1.1rem;margin-bottom:6px;"><?php esc_html_e( 'Sample Shiur Title', 'ner-michoel-core' ); ?></div>
				<div id="nm-appearance-preview-text" style="font-size:0.9rem;margin-bottom:14px;"><?php esc_html_e( 'Rabbi Example — Series Name', 'ner-michoel-core' ); ?></div>
				<button type="button" id="nm-appearance-preview-button" style="border:none;border-radius:999px;padding:8px 18px;font-weight:700;cursor:default;">▶ <?php esc_html_e( 'Play', 'ner-michoel-core' ); ?></button>
			</div>
		</div>

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
	jQuery( function ( $ ) {
		function updatePreview() {
			var bg      = $( '#nm_color_bg' ).val() || '#121212';
			var surface = $( '#nm_color_surface' ).val() || '#181818';
			var text    = $( '#nm_color_text' ).val() || '#ffffff';
			var accent  = $( '#nm_color_accent' ).val() || '#2f8f5b';

			$( '#nm-appearance-preview' ).css( 'background', bg );
			$( '#nm-appearance-preview-card' ).css( 'background', surface );
			$( '#nm-appearance-preview-title' ).css( 'color', text );
			$( '#nm-appearance-preview-text' ).css( 'color', text ).css( 'opacity', 0.65 );
			$( '#nm-appearance-preview-button' ).css( { background: accent, color: '#fff' } );
		}

		// The `change` callback fires both on a manual pick AND on the
		// programmatic .wpColorPicker('color', ...) call the palette
		// swatches use below — one code path keeps the preview correct
		// either way.
		$( '.nm-color-field' ).wpColorPicker( { change: updatePreview } );

		$( '.nm-palette-swatch' ).on( 'click', function () {
			var $swatch = $( this );
			var map = {
				nm_color_bg: $swatch.data( 'bg' ),
				nm_color_surface: $swatch.data( 'surface' ),
				nm_color_text: $swatch.data( 'text' ),
				nm_color_accent: $swatch.data( 'accent' )
			};
			$.each( map, function ( id, color ) {
				$( '#' + id ).wpColorPicker( 'color', color );
			} );
		} );

		updatePreview();
	} );
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

/**
 * REST route for setting these options programmatically — same
 * reasoning as ner_michoel_handle_storage_settings_rest() in
 * bunny-storage.php: manage_options-gated, so a valid Application
 * Password can configure this without a live wp-admin session (no
 * REST route exists for that; a plain color picker form needs one).
 */
function ner_michoel_register_appearance_settings_route() {
	register_rest_route(
		'ner-michoel/v1',
		'/appearance-settings',
		array(
			'methods'             => 'POST',
			'callback'            => 'ner_michoel_handle_appearance_settings_rest',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		)
	);
}
add_action( 'rest_api_init', 'ner_michoel_register_appearance_settings_route' );

function ner_michoel_handle_appearance_settings_rest( WP_REST_Request $request ) {
	$saved = array();
	foreach ( ner_michoel_appearance_fields() as $key => $field ) {
		$raw = $request->get_param( $key );
		if ( null === $raw ) {
			continue;
		}

		$value = ( 'color' === $field['type'] ) ? sanitize_hex_color( $raw ) : sanitize_text_field( $raw );

		if ( '' === $value || null === $value ) {
			delete_option( $key );
			$saved[ $key ] = null;
		} else {
			update_option( $key, $value );
			$saved[ $key ] = $value;
		}
	}

	return new WP_REST_Response( array( 'saved' => $saved ), 200 );
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
