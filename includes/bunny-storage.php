<?php
/**
 * Bunny Storage adapter — the cloud-storage piece sketched in
 * dev-notes.md #6, now built against a real, chosen provider (Bunny)
 * after explicit user sign-off. Distinct from the bunnycdn/bunnycdn
 * plugin already installed, which only does CDN Pull Zone (speeds up
 * delivery of files that still live on the server) — this is real
 * offload (Storage Zone: files physically move off local disk), needed
 * because the full nermichoel.org library import (~16,756 items) would
 * otherwise need several hundred GB of local hosting storage.
 *
 * Credentials (zone name, region, API key, and the Pull Zone hostname
 * that serves this Storage Zone publicly) are never hardcoded — this
 * repo is public, same reasoning as the /admin password design. They
 * live only as WP options, set through the "Storage" settings screen
 * below (see ner_michoel_render_storage_settings_page()).
 *
 * Integration point matches the original plan exactly: hook into
 * WordPress's own attachment pipeline (wp_generate_attachment_metadata,
 * filtering wp_get_attachment_url()) so every existing accessor
 * (get_shiur_audio_url, get_shiur_video_url, get_gallery_images, the
 * homepage slider, etc.) keeps working completely unchanged — they all
 * already just consume whatever wp_get_attachment_url()-style calls
 * return. Zero theme-side changes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One place for every setting this adapter needs — keeps the settings
 * page, the "is it configured" check, and the API calls from drifting
 * out of sync with each other.
 */
function ner_michoel_bunny_storage_settings() {
	return wp_parse_args(
		get_option( 'nm_bunny_storage', array() ),
		array(
			'enabled'            => false,
			'zone_name'          => '',
			'region'             => 'ny',
			'api_key'            => '',
			'pull_zone_hostname' => '',
		)
	);
}

function ner_michoel_bunny_storage_is_configured() {
	$s = ner_michoel_bunny_storage_settings();
	return $s['enabled'] && $s['zone_name'] && $s['api_key'] && $s['pull_zone_hostname'];
}

function ner_michoel_bunny_storage_endpoint( $remote_path ) {
	$s = ner_michoel_bunny_storage_settings();
	return sprintf(
		'https://%s.storage.bunnycdn.com/%s/%s',
		rawurlencode( $s['region'] ),
		rawurlencode( $s['zone_name'] ),
		ltrim( $remote_path, '/' )
	);
}

function ner_michoel_bunny_public_url( $remote_path ) {
	$s = ner_michoel_bunny_storage_settings();
	return 'https://' . trim( $s['pull_zone_hostname'], '/' ) . '/' . ltrim( $remote_path, '/' );
}

/**
 * Uploads one local file to Bunny Storage. Returns true/false rather
 * than throwing — callers (the attachment-pipeline hook below) decide
 * what "failed" means for their situation (keep the local copy, retry
 * later, etc.), same spirit as ner_michoel_sample_content_sideload_audio()
 * failing soft elsewhere in this plugin.
 */
function ner_michoel_bunny_upload_file( $local_path, $remote_path ) {
	if ( ! ner_michoel_bunny_storage_is_configured() || ! file_exists( $local_path ) ) {
		return false;
	}

	$s        = ner_michoel_bunny_storage_settings();
	$contents = file_get_contents( $local_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	if ( false === $contents ) {
		return false;
	}

	$response = wp_remote_request(
		ner_michoel_bunny_storage_endpoint( $remote_path ),
		array(
			'method'  => 'PUT',
			'timeout' => 60,
			'headers' => array(
				'AccessKey'    => $s['api_key'],
				'Content-Type' => 'application/octet-stream',
			),
			'body'    => $contents,
		)
	);

	if ( is_wp_error( $response ) ) {
		return false;
	}

	$code = wp_remote_retrieve_response_code( $response );
	return $code >= 200 && $code < 300;
}

function ner_michoel_bunny_delete_file( $remote_path ) {
	if ( ! ner_michoel_bunny_storage_is_configured() ) {
		return false;
	}
	$s        = ner_michoel_bunny_storage_settings();
	$response = wp_remote_request(
		ner_michoel_bunny_storage_endpoint( $remote_path ),
		array(
			'method'  => 'DELETE',
			'timeout' => 30,
			'headers' => array( 'AccessKey' => $s['api_key'] ),
		)
	);
	if ( is_wp_error( $response ) ) {
		return false;
	}
	$code = wp_remote_retrieve_response_code( $response );
	return $code >= 200 && $code < 300;
}

/**
 * The path Bunny stores this attachment's file under — mirrors
 * WordPress's own year/month/filename structure (relative to
 * wp-content/uploads), so it's recognizable and collision-safe the
 * same way local uploads already are.
 */
function ner_michoel_bunny_remote_path_for_attachment( $attachment_id ) {
	$file = get_post_meta( $attachment_id, '_wp_attached_file', true );
	return $file ? $file : '';
}

/**
 * Pushes a newly-processed attachment's main file to Bunny, then
 * deletes the local copy — that deletion is the entire point (the
 * disk-space problem this exists to solve), but only happens after a
 * confirmed-successful upload, never speculatively.
 *
 * Deliberately only the main file, not every generated thumbnail size —
 * for audio/video (this import's actual use case) there are no
 * "sizes" to begin with, and offloading every image size too is a
 * larger job left for later if it turns out to matter (see dev-notes.md).
 */
function ner_michoel_bunny_offload_attachment( $metadata, $attachment_id ) {
	if ( ! ner_michoel_bunny_storage_is_configured() ) {
		return $metadata;
	}

	$remote_path = ner_michoel_bunny_remote_path_for_attachment( $attachment_id );
	$local_path  = get_attached_file( $attachment_id );

	if ( ! $remote_path || ! $local_path || ! file_exists( $local_path ) ) {
		return $metadata;
	}

	if ( ner_michoel_bunny_upload_file( $local_path, $remote_path ) ) {
		update_post_meta( $attachment_id, '_nm_bunny_offloaded', 1 );
		wp_delete_file( $local_path );
	} else {
		update_post_meta( $attachment_id, '_nm_bunny_offload_failed', current_time( 'mysql' ) );
	}

	return $metadata;
}
add_filter( 'wp_generate_attachment_metadata', 'ner_michoel_bunny_offload_attachment', 20, 2 );

/**
 * Swaps in the Bunny public URL for any attachment that's been
 * offloaded — every existing accessor in this plugin already just
 * calls wp_get_attachment_url() (or something built on it), so this
 * one filter is what makes the whole rest of the codebase "just work"
 * unchanged.
 */
function ner_michoel_bunny_filter_attachment_url( $url, $attachment_id ) {
	if ( ! get_post_meta( $attachment_id, '_nm_bunny_offloaded', true ) ) {
		return $url;
	}
	$remote_path = ner_michoel_bunny_remote_path_for_attachment( $attachment_id );
	return $remote_path ? ner_michoel_bunny_public_url( $remote_path ) : $url;
}
add_filter( 'wp_get_attachment_url', 'ner_michoel_bunny_filter_attachment_url', 10, 2 );

/**
 * The base URL a Bunny-offloaded attachment's file would have had if
 * it were still local — needed below because wp_get_attachment_url()
 * (and therefore image_downsize(), which builds every sized URL off
 * of it) has already been filtered to return the Bunny URL instead,
 * and that's exactly the URL a sized/thumbnail file was never
 * actually uploaded to.
 */
function ner_michoel_bunny_local_base_url( $attachment_id ) {
	$file = get_post_meta( $attachment_id, '_wp_attached_file', true );
	if ( ! $file ) {
		return false;
	}
	$upload_dir = wp_get_upload_dir();
	return trailingslashit( $upload_dir['baseurl'] ) . $file;
}

/**
 * Only the main file gets offloaded to Bunny (see
 * ner_michoel_bunny_offload_attachment() above) — thumbnails and any
 * other registered size stay on local disk. But image_downsize()
 * builds a sized file's URL by string-replacing the base filename
 * *within the attachment's own URL* — which, for an offloaded
 * attachment, is now the Bunny URL, since wp_get_attachment_url() is
 * filtered above. That produces a URL for a crop that was never
 * uploaded there, so every non-"full" size of any offloaded image
 * 404s: the WP Media Library grid, our own custom crops, all of it.
 * Reconstructs the real (still-local) URL directly instead, using the
 * same _wp_attached_file source of truth the offload path itself
 * uses, rather than trusting the swapped base URL.
 */
function ner_michoel_bunny_fix_sized_image_url( $downsize, $id, $size ) {
	if ( 'full' === $size || ! get_post_meta( $id, '_nm_bunny_offloaded', true ) ) {
		return $downsize;
	}

	$meta = wp_get_attachment_metadata( $id );
	if ( empty( $meta['sizes'][ $size ]['file'] ) ) {
		return $downsize; // No such size — let core's own handling apply.
	}

	$local_base_url = ner_michoel_bunny_local_base_url( $id );
	if ( ! $local_base_url ) {
		return $downsize;
	}

	$sized_url = str_replace( wp_basename( $local_base_url ), $meta['sizes'][ $size ]['file'], $local_base_url );

	return array( $sized_url, $meta['sizes'][ $size ]['width'], $meta['sizes'][ $size ]['height'], true );
}
add_filter( 'image_downsize', 'ner_michoel_bunny_fix_sized_image_url', 10, 3 );

/**
 * Deleting the attachment should delete it from Bunny too, not just
 * locally — otherwise storage quietly fills up with orphaned files no
 * post ever references again.
 */
function ner_michoel_bunny_delete_on_attachment_delete( $attachment_id ) {
	if ( ! get_post_meta( $attachment_id, '_nm_bunny_offloaded', true ) ) {
		return;
	}
	$remote_path = ner_michoel_bunny_remote_path_for_attachment( $attachment_id );
	if ( $remote_path ) {
		ner_michoel_bunny_delete_file( $remote_path );
	}
}
add_action( 'delete_attachment', 'ner_michoel_bunny_delete_on_attachment_delete' );

/**
 * Admin: "Storage" settings screen — manage_options-gated, same
 * reasoning as the other sensitive/migration-adjacent screens (Import
 * Sample Content, Full Library Import). A live API key is meaningfully
 * different from a display password, so it's masked like one.
 */
function ner_michoel_render_storage_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'ner-michoel-core' ) );
	}

	$saved = false;
	if ( isset( $_POST['nm_storage_nonce'] ) && wp_verify_nonce( $_POST['nm_storage_nonce'], 'nm_save_storage' ) ) {
		ner_michoel_save_storage_settings();
		$saved = true;
	}

	$s = ner_michoel_bunny_storage_settings();
	?>
	<div class="wrap nm-dashboard">
		<h1><?php esc_html_e( 'Storage (Bunny)', 'ner-michoel-core' ); ?></h1>
		<p><?php esc_html_e( 'Offloads new media uploads (including the Full Library Import) to Bunny Storage instead of this server\'s local disk. Distinct from the Bunny CDN plugin already installed, which only speeds up delivery of files that stay local.', 'ner-michoel-core' ); ?></p>

		<?php if ( $saved ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Storage settings saved.', 'ner-michoel-core' ); ?></p></div>
		<?php endif; ?>

		<?php if ( $s['api_key'] && ! $s['pull_zone_hostname'] ) : ?>
			<div class="notice notice-warning"><p><?php esc_html_e( 'API key is set but no Pull Zone hostname yet — uploads will work, but files won\'t have a working public URL until that\'s filled in.', 'ner-michoel-core' ); ?></p></div>
		<?php endif; ?>

		<form method="post" style="max-width:600px;">
			<?php wp_nonce_field( 'nm_save_storage', 'nm_storage_nonce' ); ?>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="nm_bunny_enabled"><?php esc_html_e( 'Enabled', 'ner-michoel-core' ); ?></label></th>
					<td><label><input type="checkbox" id="nm_bunny_enabled" name="nm_bunny_enabled" value="1" <?php checked( $s['enabled'] ); ?> /> <?php esc_html_e( 'Offload new uploads to Bunny Storage', 'ner-michoel-core' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><label for="nm_bunny_zone_name"><?php esc_html_e( 'Storage Zone name', 'ner-michoel-core' ); ?></label></th>
					<td><input type="text" id="nm_bunny_zone_name" name="nm_bunny_zone_name" class="regular-text" value="<?php echo esc_attr( $s['zone_name'] ); ?>" placeholder="e.g. tomo-shuirim" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="nm_bunny_region"><?php esc_html_e( 'Region', 'ner-michoel-core' ); ?></label></th>
					<td><input type="text" id="nm_bunny_region" name="nm_bunny_region" class="regular-text" value="<?php echo esc_attr( $s['region'] ); ?>" placeholder="ny" />
					<p class="description"><?php esc_html_e( 'The subdomain prefix in your storage endpoint, e.g. "ny" from ny.storage.bunnycdn.com.', 'ner-michoel-core' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="nm_bunny_api_key"><?php esc_html_e( 'API Key (write access)', 'ner-michoel-core' ); ?></label></th>
					<td><input type="password" id="nm_bunny_api_key" name="nm_bunny_api_key" class="regular-text" value="<?php echo esc_attr( $s['api_key'] ); ?>" autocomplete="off" />
					<p class="description"><?php esc_html_e( 'The Storage Zone\'s password/API key from bunny.net — needs write access (uploads and deletes), not the read-only key.', 'ner-michoel-core' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="nm_bunny_pull_zone"><?php esc_html_e( 'Pull Zone hostname', 'ner-michoel-core' ); ?></label></th>
					<td><input type="text" id="nm_bunny_pull_zone" name="nm_bunny_pull_zone" class="regular-text" value="<?php echo esc_attr( $s['pull_zone_hostname'] ); ?>" placeholder="e.g. tomo-shiurim.b-cdn.net" />
					<p class="description"><?php esc_html_e( 'The public CDN hostname connected to this Storage Zone (not the CDN plugin\'s Pull Zone, which points at this server instead). This is what makes offloaded files actually reachable by visitors.', 'ner-michoel-core' ); ?></p></td>
				</tr>
			</table>
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'ner-michoel-core' ); ?></button></p>
		</form>
	</div>
	<?php
}

function ner_michoel_save_storage_settings() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	update_option(
		'nm_bunny_storage',
		array(
			'enabled'            => ! empty( $_POST['nm_bunny_enabled'] ),
			'zone_name'          => isset( $_POST['nm_bunny_zone_name'] ) ? sanitize_text_field( wp_unslash( $_POST['nm_bunny_zone_name'] ) ) : '',
			'region'             => isset( $_POST['nm_bunny_region'] ) ? sanitize_key( wp_unslash( $_POST['nm_bunny_region'] ) ) : 'ny',
			'api_key'            => isset( $_POST['nm_bunny_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['nm_bunny_api_key'] ) ) : '',
			'pull_zone_hostname' => isset( $_POST['nm_bunny_pull_zone'] ) ? preg_replace( '#^https?://#', '', sanitize_text_field( wp_unslash( $_POST['nm_bunny_pull_zone'] ) ) ) : '',
		)
	);
}

/**
 * REST route for setting these options programmatically —
 * manage_options-gated (a valid application password for an admin
 * account satisfies this the same as a logged-in session), so whoever
 * has that access can configure this without hand-typing a live API
 * key into a form field. Same fields/sanitization as the settings
 * form above, just JSON in instead of $_POST.
 */
function ner_michoel_register_storage_settings_route() {
	register_rest_route(
		'ner-michoel/v1',
		'/storage-settings',
		array(
			'methods'             => 'POST',
			'callback'            => 'ner_michoel_handle_storage_settings_rest',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		)
	);
}
add_action( 'rest_api_init', 'ner_michoel_register_storage_settings_route' );

function ner_michoel_handle_storage_settings_rest( WP_REST_Request $request ) {
	$current = ner_michoel_bunny_storage_settings();

	$enabled   = $request->get_param( 'enabled' );
	$zone_name = $request->get_param( 'zone_name' );
	$region    = $request->get_param( 'region' );
	$api_key   = $request->get_param( 'api_key' );
	$pull_zone = $request->get_param( 'pull_zone_hostname' );

	update_option(
		'nm_bunny_storage',
		array(
			'enabled'            => null !== $enabled ? (bool) $enabled : $current['enabled'],
			'zone_name'          => null !== $zone_name ? sanitize_text_field( $zone_name ) : $current['zone_name'],
			'region'             => null !== $region ? sanitize_key( $region ) : $current['region'],
			'api_key'            => null !== $api_key ? sanitize_text_field( $api_key ) : $current['api_key'],
			'pull_zone_hostname' => null !== $pull_zone ? preg_replace( '#^https?://#', '', sanitize_text_field( $pull_zone ) ) : $current['pull_zone_hostname'],
		)
	);

	return new WP_REST_Response(
		array(
			'saved'       => true,
			'configured'  => ner_michoel_bunny_storage_is_configured(),
		),
		200
	);
}
