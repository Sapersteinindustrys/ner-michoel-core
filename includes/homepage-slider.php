<?php
/**
 * Homepage hero slider — an ordered, editable list of (image,
 * heading, subtext, optional button) slides, managed from the Site
 * Control Panel instead of a developer editing template code.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wide, hard-cropped banner shape — a hero strip should fill its
 * frame edge-to-edge and look consistent slide to slide, unlike the
 * gallery slider where preserving each photo's natural shape matters
 * more. Generation is deferred to the background job in
 * image-processing.php, same as the other custom sizes.
 */
function ner_michoel_register_hero_slide_size() {
	add_image_size( 'nm_hero_slide', 1920, 800, true );
}
add_action( 'after_setup_theme', 'ner_michoel_register_hero_slide_size' );

/**
 * "Home Page" settings screen — a tabbed container so homepage-related
 * settings live in one place instead of each being its own flat
 * top-level Site Control Panel entry. Hero Slider is the first tab;
 * new homepage settings should add a tab here rather than a new
 * top-level menu item (see ner_michoel_home_page_tabs() below).
 */
function ner_michoel_home_page_tabs() {
	return array(
		'hero-slider' => array(
			'label'    => __( 'Hero Slider', 'ner-michoel-core' ),
			'callback' => 'ner_michoel_render_hero_slider_tab',
		),
	);
}

function ner_michoel_render_homepage_settings_page() {
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'ner-michoel-core' ) );
	}

	$tabs        = ner_michoel_home_page_tabs();
	$current_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! isset( $tabs[ $current_tab ] ) ) {
		$current_tab = array_key_first( $tabs );
	}
	?>
	<div class="wrap nm-dashboard">
		<h1><?php esc_html_e( 'Home Page', 'ner-michoel-core' ); ?></h1>

		<h2 class="nav-tab-wrapper">
			<?php foreach ( $tabs as $tab_slug => $tab ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=nm-homepage-settings&tab=' . $tab_slug ) ); ?>" class="nav-tab <?php echo $tab_slug === $current_tab ? 'nav-tab-active' : ''; ?>">
					<?php echo esc_html( $tab['label'] ); ?>
				</a>
			<?php endforeach; ?>
		</h2>

		<div class="nm-tab-content" style="margin-top:20px;">
			<?php call_user_func( $tabs[ $current_tab ]['callback'] ); ?>
		</div>
	</div>
	<?php
}

function ner_michoel_render_hero_slider_tab() {
	$saved = false;
	if ( isset( $_POST['nm_homepage_slider_nonce'] ) && wp_verify_nonce( $_POST['nm_homepage_slider_nonce'], 'nm_save_homepage_slider' ) ) {
		ner_michoel_save_homepage_slider();
		$saved = true;
	}

	$slides      = ner_michoel_get_homepage_slider();
	$interval    = ner_michoel_get_homepage_slider_interval_seconds();
	$button_style = ner_michoel_get_homepage_slider_button_style();
	?>
	<p><?php esc_html_e( 'These images rotate at the top of the homepage. Add, remove, or drag to reorder slides below.', 'ner-michoel-core' ); ?></p>

	<?php if ( $saved ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Homepage slider saved.', 'ner-michoel-core' ); ?></p></div>
	<?php endif; ?>

	<form method="post" id="nm-slider-form">
		<?php wp_nonce_field( 'nm_save_homepage_slider', 'nm_homepage_slider_nonce' ); ?>

		<p>
			<label for="nm-slider-interval"><strong><?php esc_html_e( 'Seconds per slide', 'ner-michoel-core' ); ?></strong></label><br />
			<input type="number" id="nm-slider-interval" name="nm_homepage_slider_interval" value="<?php echo esc_attr( $interval ); ?>" min="2" max="60" step="1" style="width:80px;" />
			<span class="description"><?php esc_html_e( 'How long each slide stays up before auto-advancing to the next one.', 'ner-michoel-core' ); ?></span>
		</p>

		<div class="nm-slider-bulk-button">
			<strong><?php esc_html_e( 'Slide button design', 'ner-michoel-core' ); ?></strong>
			<p class="description"><?php esc_html_e( 'Applies to every slide\'s button link (e.g. "Learn More").', 'ner-michoel-core' ); ?></p>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="nm_slider_button_color"><?php esc_html_e( 'Color', 'ner-michoel-core' ); ?></label></th>
					<td><input type="color" id="nm_slider_button_color" name="nm_slider_button_color" value="<?php echo esc_attr( $button_style['color'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="nm_slider_button_position"><?php esc_html_e( 'Placement', 'ner-michoel-core' ); ?></label></th>
					<td>
						<select id="nm_slider_button_position" name="nm_slider_button_position">
							<?php foreach ( ner_michoel_homepage_slider_button_positions() as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $button_style['position'], $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Where the button sits over the photo, independent of the heading/subtext above it.', 'ner-michoel-core' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="nm_slider_button_shape"><?php esc_html_e( 'Shape', 'ner-michoel-core' ); ?></label></th>
					<td>
						<select id="nm_slider_button_shape" name="nm_slider_button_shape">
							<option value="pill" <?php selected( $button_style['shape'], 'pill' ); ?>><?php esc_html_e( 'Pill (fully rounded)', 'ner-michoel-core' ); ?></option>
							<option value="rounded" <?php selected( $button_style['shape'], 'rounded' ); ?>><?php esc_html_e( 'Rounded corners', 'ner-michoel-core' ); ?></option>
							<option value="square" <?php selected( $button_style['shape'], 'square' ); ?>><?php esc_html_e( 'Square corners', 'ner-michoel-core' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="nm_slider_button_opacity"><?php esc_html_e( 'Opacity', 'ner-michoel-core' ); ?></label></th>
					<td>
						<input type="number" id="nm_slider_button_opacity" name="nm_slider_button_opacity" value="<?php echo esc_attr( $button_style['opacity'] ); ?>" min="10" max="100" step="5" style="width:80px;" />%
					</td>
				</tr>
			</table>
		</div>

		<div id="nm-slider-list">
			<?php foreach ( $slides as $i => $slide ) : ?>
				<?php ner_michoel_render_slide_row( $i, $slide ); ?>
			<?php endforeach; ?>
		</div>

		<p>
			<button type="button" class="button" id="nm-slider-add"><?php esc_html_e( '+ Add Slide', 'ner-michoel-core' ); ?></button>
			<button type="button" class="button" id="nm-slider-add-multiple"><?php esc_html_e( '+ Add Slides from Photos…', 'ner-michoel-core' ); ?></button>
			<br />
			<span class="description"><?php esc_html_e( 'In the photo picker, hold Ctrl (⌘ Cmd on Mac) while clicking to select more than one photo at a time — WordPress\'s picker treats a plain click as "just this one" instead.', 'ner-michoel-core' ); ?></span>
		</p>

		<div class="nm-slider-bulk-button">
			<strong><?php esc_html_e( 'Set one button for every slide', 'ner-michoel-core' ); ?></strong>
			<p class="description"><?php esc_html_e( 'Fills in the same button link + text on every slide below, including new ones you add afterward. Each slide can still be edited individually.', 'ner-michoel-core' ); ?></p>
			<div class="nm-slider-bulk-button__row">
				<input type="url" id="nm-bulk-link-url" placeholder="https://" />
				<input type="text" id="nm-bulk-link-text" placeholder="<?php esc_attr_e( 'Learn More', 'ner-michoel-core' ); ?>" />
				<button type="button" class="button" id="nm-bulk-link-apply"><?php esc_html_e( 'Apply to All Slides', 'ner-michoel-core' ); ?></button>
			</div>
		</div>

		<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save Slider', 'ner-michoel-core' ); ?></button></p>
	</form>

	<script type="text/html" id="nm-slide-row-template">
		<?php ner_michoel_render_slide_row( '__INDEX__', array() ); ?>
	</script>
	<?php
}

/**
 * One slide's row of fields. $index may be an integer (a saved slide)
 * or the literal string '__INDEX__' when used as the JS template for
 * a freshly-added slide — assets/admin.js replaces that placeholder
 * before appending the row, so every field name ends up correctly
 * indexed without any custom JS serialization.
 */
function ner_michoel_render_slide_row( $index, $slide ) {
	$slide = wp_parse_args(
		$slide,
		array(
			'image_id'  => 0,
			'heading'   => '',
			'subtext'   => '',
			'link_url'  => '',
			'link_text' => '',
		)
	);
	$thumb = $slide['image_id'] ? wp_get_attachment_image_url( $slide['image_id'], 'medium' ) : '';
	$name  = 'nm_homepage_slider[' . esc_attr( $index ) . ']';
	?>
	<div class="nm-slide-row">
		<div class="nm-slide-row__drag" title="<?php esc_attr_e( 'Drag to reorder', 'ner-michoel-core' ); ?>">&#9776;</div>
		<div class="nm-slide-row__image">
			<div class="nm-slide-row__preview"><?php if ( $thumb ) : ?><img src="<?php echo esc_url( $thumb ); ?>" alt="" /><?php endif; ?></div>
			<input type="hidden" class="nm-slide-image-id" name="<?php echo $name; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[image_id]" value="<?php echo esc_attr( $slide['image_id'] ); ?>" />
			<button type="button" class="button nm-slide-choose-image" title="<?php esc_attr_e( 'Hold Ctrl (⌘ Cmd on Mac) to pick more than one — extras become new slides.', 'ner-michoel-core' ); ?>"><?php esc_html_e( 'Choose Image', 'ner-michoel-core' ); ?></button>
		</div>
		<div class="nm-slide-row__fields">
			<label>
				<?php esc_html_e( 'Heading', 'ner-michoel-core' ); ?>
				<input type="text" name="<?php echo $name; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[heading]" value="<?php echo esc_attr( $slide['heading'] ); ?>" />
			</label>
			<label>
				<?php esc_html_e( 'Subtext', 'ner-michoel-core' ); ?>
				<textarea name="<?php echo $name; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[subtext]" rows="2"><?php echo esc_textarea( $slide['subtext'] ); ?></textarea>
			</label>
			<label>
				<?php esc_html_e( 'Button Link (optional)', 'ner-michoel-core' ); ?>
				<input type="url" class="nm-slide-link-url" name="<?php echo $name; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[link_url]" value="<?php echo esc_attr( $slide['link_url'] ); ?>" placeholder="https://" />
			</label>
			<div class="nm-slide-link-picker">
				<input type="text" class="nm-slide-link-search" placeholder="<?php esc_attr_e( 'Search pages…', 'ner-michoel-core' ); ?>" autocomplete="off" />
				<div class="nm-slide-link-results" hidden></div>
			</div>
			<label>
				<?php esc_html_e( 'Button Text (optional)', 'ner-michoel-core' ); ?>
				<input type="text" class="nm-slide-link-text" name="<?php echo $name; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[link_text]" value="<?php echo esc_attr( $slide['link_text'] ); ?>" placeholder="<?php esc_attr_e( 'Learn More', 'ner-michoel-core' ); ?>" />
			</label>
		</div>
		<button type="button" class="button-link-delete nm-slide-remove"><?php esc_html_e( 'Remove Slide', 'ner-michoel-core' ); ?></button>
	</div>
	<?php
}

/**
 * Search-as-you-type page results for the slide link picker — a
 * dedicated action rather than reusing WordPress's own internal
 * "wp-link-ajax" (which powers the post editor's Insert Link dialog),
 * since scoping that one to pages only would also restrict the
 * editor's link search everywhere else on the site.
 */
function ner_michoel_ajax_search_pages() {
	check_ajax_referer( 'nm_search_pages', 'nonce' );

	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error();
	}

	$search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
	if ( '' === $search ) {
		wp_send_json_success( array() );
	}

	$query = new WP_Query(
		array(
			'post_type'              => 'page',
			'post_status'            => 'publish',
			's'                      => $search,
			'posts_per_page'         => 15,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		)
	);

	$results = array();
	foreach ( $query->posts as $post ) {
		$results[] = array(
			'title' => get_the_title( $post ),
			'url'   => get_permalink( $post ),
		);
	}

	wp_send_json_success( $results );
}
add_action( 'wp_ajax_nm_search_pages', 'ner_michoel_ajax_search_pages' );

function ner_michoel_save_homepage_slider() {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	$raw = ( isset( $_POST['nm_homepage_slider'] ) && is_array( $_POST['nm_homepage_slider'] ) )
		? wp_unslash( $_POST['nm_homepage_slider'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		: array();

	$slides = array();
	foreach ( $raw as $row ) {
		$image_id = isset( $row['image_id'] ) ? absint( $row['image_id'] ) : 0;
		if ( ! $image_id ) {
			continue; // Skip rows added then left empty.
		}
		$slides[] = array(
			'image_id'  => $image_id,
			'heading'   => isset( $row['heading'] ) ? sanitize_text_field( $row['heading'] ) : '',
			'subtext'   => isset( $row['subtext'] ) ? sanitize_textarea_field( $row['subtext'] ) : '',
			'link_url'  => isset( $row['link_url'] ) ? esc_url_raw( $row['link_url'] ) : '',
			'link_text' => isset( $row['link_text'] ) ? sanitize_text_field( $row['link_text'] ) : '',
		);
	}

	update_option( 'nm_homepage_slider', $slides );

	$interval = isset( $_POST['nm_homepage_slider_interval'] ) ? absint( $_POST['nm_homepage_slider_interval'] ) : 0;
	update_option( 'nm_homepage_slider_interval', $interval ? max( 2, min( 60, $interval ) ) : 6 );

	$valid_shapes    = array( 'pill', 'rounded', 'square' );
	$shape           = isset( $_POST['nm_slider_button_shape'] ) ? sanitize_key( wp_unslash( $_POST['nm_slider_button_shape'] ) ) : 'pill';
	$opacity         = isset( $_POST['nm_slider_button_opacity'] ) ? absint( $_POST['nm_slider_button_opacity'] ) : 100;
	$position        = isset( $_POST['nm_slider_button_position'] ) ? sanitize_key( wp_unslash( $_POST['nm_slider_button_position'] ) ) : 'bottom-center';
	$valid_positions = ner_michoel_homepage_slider_button_positions();
	update_option(
		'nm_homepage_slider_button_style',
		array(
			'color'    => isset( $_POST['nm_slider_button_color'] ) ? sanitize_hex_color( wp_unslash( $_POST['nm_slider_button_color'] ) ) : '#2f8f5b',
			'shape'    => in_array( $shape, $valid_shapes, true ) ? $shape : 'pill',
			'opacity'  => max( 10, min( 100, $opacity ) ),
			'position' => isset( $valid_positions[ $position ] ) ? $position : 'bottom-center',
		)
	);
}

/**
 * REST route for setting these options programmatically, for the
 * custom /admin Settings UI (custom-admin-settings-api.php) — same
 * validation as ner_michoel_save_homepage_slider() above, reading a
 * JSON body ({interval, button_color, button_position, button_shape,
 * button_opacity, slides: [{image_id, heading, subtext, link_url,
 * link_text}, ...]}) instead of a $_POST array.
 */
function ner_michoel_register_hero_slider_rest_route() {
	register_rest_route(
		'ner-michoel/v1',
		'/hero-slider-settings',
		array(
			'methods'             => 'POST',
			'callback'            => 'ner_michoel_handle_hero_slider_settings_rest',
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
		)
	);
}
add_action( 'rest_api_init', 'ner_michoel_register_hero_slider_rest_route' );

function ner_michoel_handle_hero_slider_settings_rest( WP_REST_Request $request ) {
	$body = $request->get_json_params();
	if ( ! is_array( $body ) ) {
		$body = array();
	}

	$slides_in = isset( $body['slides'] ) && is_array( $body['slides'] ) ? $body['slides'] : array();
	$slides    = array();
	foreach ( $slides_in as $row ) {
		$image_id = isset( $row['image_id'] ) ? absint( $row['image_id'] ) : 0;
		if ( ! $image_id ) {
			continue;
		}
		$slides[] = array(
			'image_id'  => $image_id,
			'heading'   => isset( $row['heading'] ) ? sanitize_text_field( $row['heading'] ) : '',
			'subtext'   => isset( $row['subtext'] ) ? sanitize_textarea_field( $row['subtext'] ) : '',
			'link_url'  => isset( $row['link_url'] ) ? esc_url_raw( $row['link_url'] ) : '',
			'link_text' => isset( $row['link_text'] ) ? sanitize_text_field( $row['link_text'] ) : '',
		);
	}
	update_option( 'nm_homepage_slider', $slides );

	$interval = isset( $body['interval'] ) ? absint( $body['interval'] ) : 0;
	update_option( 'nm_homepage_slider_interval', $interval ? max( 2, min( 60, $interval ) ) : 6 );

	$valid_shapes    = array( 'pill', 'rounded', 'square' );
	$shape           = isset( $body['button_shape'] ) ? sanitize_key( $body['button_shape'] ) : 'pill';
	$opacity         = isset( $body['button_opacity'] ) ? absint( $body['button_opacity'] ) : 100;
	$position        = isset( $body['button_position'] ) ? sanitize_key( $body['button_position'] ) : 'bottom-center';
	$valid_positions = ner_michoel_homepage_slider_button_positions();

	update_option(
		'nm_homepage_slider_button_style',
		array(
			'color'    => isset( $body['button_color'] ) ? sanitize_hex_color( $body['button_color'] ) : '#2f8f5b',
			'shape'    => in_array( $shape, $valid_shapes, true ) ? $shape : 'pill',
			'opacity'  => max( 10, min( 100, $opacity ) ),
			'position' => isset( $valid_positions[ $position ] ) ? $position : 'bottom-center',
		)
	);

	return new WP_REST_Response( array( 'saved' => true ), 200 );
}

/**
 * Same 9-slot grid as the Layout Toggle's placement setting
 * (Site Control Panel > Layout Toggle) — kept as its own copy rather
 * than a shared cross-file dependency, since the two features are
 * otherwise unrelated and each is meant to stand alone.
 */
function ner_michoel_homepage_slider_button_positions() {
	return array(
		'top-left'      => __( 'Top left', 'ner-michoel-core' ),
		'top-center'    => __( 'Top center', 'ner-michoel-core' ),
		'top-right'     => __( 'Top right', 'ner-michoel-core' ),
		'center-left'   => __( 'Center left', 'ner-michoel-core' ),
		'center-center' => __( 'Center center', 'ner-michoel-core' ),
		'center-right'  => __( 'Center right', 'ner-michoel-core' ),
		'bottom-left'   => __( 'Bottom left', 'ner-michoel-core' ),
		'bottom-center' => __( 'Bottom center (default)', 'ner-michoel-core' ),
		'bottom-right'  => __( 'Bottom right', 'ner-michoel-core' ),
	);
}

/**
 * Seconds each slide stays up before auto-advancing, as set on the
 * Home Page settings screen. Defaults to 6 (this feature's original
 * hardcoded value) if never saved.
 */
function ner_michoel_get_homepage_slider_interval_seconds() {
	return (int) get_option( 'nm_homepage_slider_interval', 6 );
}

/**
 * Raw color/shape/opacity for every slide's button link, as set on
 * the Home Page settings screen.
 */
function ner_michoel_get_homepage_slider_button_style() {
	return wp_parse_args(
		get_option( 'nm_homepage_slider_button_style', array() ),
		array(
			'color'    => '#2f8f5b',
			'shape'    => 'pill',
			'opacity'  => 100,
			'position' => 'bottom-center',
		)
	);
}

/**
 * Validated against the real option list, same reasoning as
 * ner_michoel_get_layout_toggle_position() — a renamed/removed
 * position from a future version shouldn't be able to render a
 * button with no matching CSS class.
 */
function ner_michoel_get_homepage_slider_button_position() {
	$style = ner_michoel_get_homepage_slider_button_style();
	$valid = ner_michoel_homepage_slider_button_positions();
	return isset( $valid[ $style['position'] ] ) ? $style['position'] : 'bottom-center';
}

/**
 * The button's background as a ready-to-use rgba() string — opacity
 * is applied to the color itself (not a plain CSS `opacity`, which
 * would fade the button's text/label too), computed here so the
 * theme just outputs the result rather than re-doing hex/alpha math
 * itself.
 */
function ner_michoel_get_homepage_slider_button_background() {
	$style = ner_michoel_get_homepage_slider_button_style();
	$hex   = ltrim( $style['color'], '#' );
	if ( 6 !== strlen( $hex ) ) {
		$hex = '2f8f5b';
	}
	$r = hexdec( substr( $hex, 0, 2 ) );
	$g = hexdec( substr( $hex, 2, 2 ) );
	$b = hexdec( substr( $hex, 4, 2 ) );
	return sprintf( 'rgba(%d, %d, %d, %s)', $r, $g, $b, $style['opacity'] / 100 );
}

/**
 * The button's border-radius for its chosen shape.
 */
function ner_michoel_get_homepage_slider_button_radius() {
	$style = ner_michoel_get_homepage_slider_button_style();
	$map   = array(
		'pill'    => '999px',
		'rounded' => '8px',
		'square'  => '3px',
	);
	return isset( $map[ $style['shape'] ] ) ? $map[ $style['shape'] ] : '999px';
}

/**
 * Front-end accessors.
 */

/**
 * Raw saved slides, in display order. Each has image_id, heading,
 * subtext, link_url, link_text (all but image_id may be empty).
 */
function ner_michoel_get_homepage_slider() {
	$slides = get_option( 'nm_homepage_slider', array() );
	return is_array( $slides ) ? $slides : array();
}

/**
 * Same slides with the image already resolved to a URL/width/height/
 * srcset at the given size, so the theme doesn't need to call
 * wp_get_attachment_image_src() itself for every slide. Defaults to
 * the hero crop (nm_hero_slide); pass another registered size if a
 * template needs the slider content elsewhere at a different shape.
 */
function ner_michoel_get_homepage_slider_images( $size = 'nm_hero_slide' ) {
	$slides = ner_michoel_get_homepage_slider();
	$out    = array();
	foreach ( $slides as $slide ) {
		$src = wp_get_attachment_image_src( $slide['image_id'], $size );
		if ( ! $src ) {
			// The custom crop hasn't been generated yet — its
			// background job (image-processing.php) runs on WP-Cron,
			// not synchronously at upload time, so a slide added
			// moments ago can hit this before that job has run.
			// WordPress does NOT automatically fall back to the full
			// image for a registered size with no generated file
			// yet (image_downsize() just returns false for it), so
			// without this the slide would silently disappear from
			// the slider until the background job eventually catches
			// up. The hero slider crops via CSS background-size:cover
			// anyway, so the uncropped full image displays correctly
			// here regardless of its own aspect ratio.
			$src = wp_get_attachment_image_src( $slide['image_id'], 'full' );
		}
		if ( ! $src ) {
			continue; // Genuinely missing/deleted attachment.
		}
		$out[] = array_merge(
			$slide,
			array(
				'url'    => $src[0],
				'width'  => $src[1],
				'height' => $src[2],
				'srcset' => (string) wp_get_attachment_image_srcset( $slide['image_id'], $size ),
			)
		);
	}
	return $out;
}
