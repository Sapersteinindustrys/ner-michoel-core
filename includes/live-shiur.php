<?php
/**
 * Live Shiur / Zoom link manager — a small options screen (Zoom link,
 * meeting ID, schedule blurb) so the admin can update the live-shiur
 * details from the Site Control Panel instead of a developer editing
 * them into post content. The original site hardcoded this directly
 * into News posts.
 *
 * Rendered by ner-michoel-child (template-parts/live-shiur.php) on the
 * homepage and News & Events page. Saved from two places: the wp-admin
 * screen below (fallback), and the Site Control Panel's Live Shiur tab
 * via the live-shiur-settings REST route.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ner_michoel_render_live_shiur_page() {
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'ner-michoel-core' ) );
	}

	$saved = false;
	if ( isset( $_POST['nm_live_shiur_nonce'] ) && wp_verify_nonce( $_POST['nm_live_shiur_nonce'], 'nm_save_live_shiur' ) ) {
		ner_michoel_save_live_shiur();
		$saved = true;
	}

	$live_shiur = ner_michoel_get_live_shiur();
	?>
	<div class="wrap nm-dashboard">
		<h1><?php esc_html_e( 'Live Shiur / Zoom', 'ner-michoel-core' ); ?></h1>
		<p><?php esc_html_e( 'Update the Zoom link and schedule shown on the site without editing any post content.', 'ner-michoel-core' ); ?></p>

		<?php if ( $saved ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Live Shiur details saved.', 'ner-michoel-core' ); ?></p></div>
		<?php endif; ?>

		<form method="post" style="max-width:600px;">
			<?php wp_nonce_field( 'nm_save_live_shiur', 'nm_live_shiur_nonce' ); ?>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="nm_live_shiur_zoom_link"><?php esc_html_e( 'Zoom Link', 'ner-michoel-core' ); ?></label></th>
					<td><input type="url" id="nm_live_shiur_zoom_link" name="nm_live_shiur_zoom_link" class="regular-text" placeholder="https://zoom.us/j/..." value="<?php echo esc_attr( $live_shiur['zoom_link'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="nm_live_shiur_meeting_id"><?php esc_html_e( 'Meeting ID', 'ner-michoel-core' ); ?></label></th>
					<td><input type="text" id="nm_live_shiur_meeting_id" name="nm_live_shiur_meeting_id" class="regular-text" value="<?php echo esc_attr( $live_shiur['meeting_id'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="nm_live_shiur_schedule"><?php esc_html_e( 'Schedule', 'ner-michoel-core' ); ?></label></th>
					<td>
						<textarea id="nm_live_shiur_schedule" name="nm_live_shiur_schedule" rows="3" class="large-text" placeholder="<?php esc_attr_e( 'e.g. Sundays 8:00 PM ET', 'ner-michoel-core' ); ?>"><?php echo esc_textarea( $live_shiur['schedule'] ); ?></textarea>
					</td>
				</tr>
			</table>
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'ner-michoel-core' ); ?></button></p>
		</form>
	</div>
	<?php
}

function ner_michoel_save_live_shiur() {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	update_option(
		'nm_live_shiur',
		ner_michoel_sanitize_live_shiur(
			array(
				'zoom_link'  => isset( $_POST['nm_live_shiur_zoom_link'] ) ? wp_unslash( $_POST['nm_live_shiur_zoom_link'] ) : '',
				'meeting_id' => isset( $_POST['nm_live_shiur_meeting_id'] ) ? wp_unslash( $_POST['nm_live_shiur_meeting_id'] ) : '',
				'schedule'   => isset( $_POST['nm_live_shiur_schedule'] ) ? wp_unslash( $_POST['nm_live_shiur_schedule'] ) : '',
			)
		)
	);
}

/**
 * The one place the live-shiur rules live, shared by the wp-admin form
 * and the REST route. An invalid Zoom URL is stored as blank rather
 * than kept as-is, so the homepage never links somewhere broken.
 */
function ner_michoel_sanitize_live_shiur( array $raw ) {
	return array(
		'zoom_link'  => isset( $raw['zoom_link'] ) ? esc_url_raw( trim( (string) $raw['zoom_link'] ) ) : '',
		'meeting_id' => isset( $raw['meeting_id'] ) ? sanitize_text_field( (string) $raw['meeting_id'] ) : '',
		'schedule'   => isset( $raw['schedule'] ) ? sanitize_textarea_field( (string) $raw['schedule'] ) : '',
	);
}

/**
 * Site Control Panel > Live Shiur / Zoom saves here (settings engine,
 * custom-admin-settings-api.php). edit_posts, matching the tab's
 * capability and the wp-admin screen above.
 */
function ner_michoel_register_live_shiur_settings_route() {
	register_rest_route(
		'ner-michoel/v1',
		'/live-shiur-settings',
		array(
			'methods'             => 'POST',
			'callback'            => 'ner_michoel_handle_live_shiur_settings_rest',
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
		)
	);
}
add_action( 'rest_api_init', 'ner_michoel_register_live_shiur_settings_route' );

function ner_michoel_handle_live_shiur_settings_rest( WP_REST_Request $request ) {
	update_option(
		'nm_live_shiur',
		ner_michoel_sanitize_live_shiur(
			array(
				'zoom_link'  => $request->get_param( 'zoom_link' ),
				'meeting_id' => $request->get_param( 'meeting_id' ),
				'schedule'   => $request->get_param( 'schedule' ),
			)
		)
	);

	return new WP_REST_Response( ner_michoel_get_live_shiur(), 200 );
}

/**
 * Front-end accessors.
 */

function ner_michoel_get_live_shiur() {
	return wp_parse_args(
		get_option( 'nm_live_shiur', array() ),
		array(
			'zoom_link'  => '',
			'meeting_id' => '',
			'schedule'   => '',
		)
	);
}

function ner_michoel_get_live_shiur_zoom_link() {
	return ner_michoel_get_live_shiur()['zoom_link'];
}

function ner_michoel_get_live_shiur_meeting_id() {
	return ner_michoel_get_live_shiur()['meeting_id'];
}

function ner_michoel_get_live_shiur_schedule() {
	return ner_michoel_get_live_shiur()['schedule'];
}

/**
 * True when there's something worth showing visitors — a Zoom link or a
 * schedule. A meeting ID on its own isn't enough to be useful.
 */
function ner_michoel_live_shiur_is_set() {
	$live = ner_michoel_get_live_shiur();
	return '' !== $live['zoom_link'] || '' !== $live['schedule'];
}
