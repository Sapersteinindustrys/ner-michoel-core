<?php
/**
 * Live Shiur / Zoom link manager — a small options screen (Zoom link,
 * meeting ID, schedule blurb) so the admin can update the live-shiur
 * details from the Site Control Panel instead of a developer editing
 * them into post content. The original site hardcoded this directly
 * into News posts.
 *
 * Backend-only for now: stores the option and exposes the accessors
 * below, but nothing in ner-michoel-child renders them yet — that's
 * theme-side work (homepage / News & Events page), tracked separately
 * so it doesn't collide with whoever's actively in that repo.
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
		array(
			'zoom_link'  => isset( $_POST['nm_live_shiur_zoom_link'] ) ? esc_url_raw( wp_unslash( $_POST['nm_live_shiur_zoom_link'] ) ) : '',
			'meeting_id' => isset( $_POST['nm_live_shiur_meeting_id'] ) ? sanitize_text_field( wp_unslash( $_POST['nm_live_shiur_meeting_id'] ) ) : '',
			'schedule'   => isset( $_POST['nm_live_shiur_schedule'] ) ? sanitize_textarea_field( wp_unslash( $_POST['nm_live_shiur_schedule'] ) ) : '',
		)
	);
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
