<?php
/**
 * Lets a visitor download a shiur's media file directly (audio or
 * video), with a friendly "{Title}.ext" filename, instead
 * of relying on the <a download> attribute — which browsers silently
 * ignore for cross-origin file URLs (e.g. if uploads ever move to a CDN) and
 * which can't rename the downloaded file away from whatever it was
 * called in the media library.
 *
 * Implemented as a plain query var (?nm_download=1) on the shiur's
 * own permalink rather than a rewrite endpoint, so it works
 * immediately on every install — no permalink/rewrite-rule flush
 * required.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ner_michoel_register_download_query_var( $vars ) {
	$vars[] = 'nm_download';
	return $vars;
}
add_filter( 'query_vars', 'ner_michoel_register_download_query_var' );

function ner_michoel_maybe_serve_shiur_download() {
	if ( ! get_query_var( 'nm_download' ) || ! is_singular( array( 'shiur', 'written_shiur' ) ) ) {
		return;
	}
	ner_michoel_stream_shiur_download( get_queried_object_id() );
}
add_action( 'template_redirect', 'ner_michoel_maybe_serve_shiur_download' );

/**
 * Streams the shiur's media file (audio, video, or a written shiur's
 * PDF) as a download and ends the request. Falls through silently
 * (normal page render) if there's no file attached — the query var
 * alone can't force a download that isn't there.
 */
function ner_michoel_stream_shiur_download( $post_id ) {
	$is_written    = 'written_shiur' === get_post_type( $post_id );
	$attachment_id = $is_written
		? ner_michoel_get_written_shiur_pdf_id( $post_id )
		: get_post_meta( $post_id, '_shiur_audio_id', true );
	if ( ! $attachment_id ) {
		return;
	}

	$file = get_attached_file( $attachment_id );
	if ( ! $file || ! file_exists( $file ) ) {
		// Offloaded to Bunny (bunny-storage.php removes the local copy after
		// upload), so there's nothing to stream here. Sending the browser to the
		// CDN copy made it play the file instead of saving it (a CDN answer has no
		// "save as" header), so the file is passed on through this site with the
		// friendly name, and the browser saves it. A download leaves two of the
		// backup route's places free for listeners; when the route is busy or off,
		// the browser goes to the CDN copy as before.
		$remote      = wp_get_attachment_url( $attachment_id );
		$remote_path = get_post_meta( $attachment_id, '_nm_bunny_offloaded', true ) && function_exists( 'ner_michoel_bunny_remote_path_for_attachment' )
			? ner_michoel_bunny_remote_path_for_attachment( $attachment_id )
			: '';
		if ( $remote_path && function_exists( 'ner_michoel_relay_serve' ) && function_exists( 'ner_michoel_relay_settings' ) ) {
			$cfg = ner_michoel_relay_settings();
			ner_michoel_relay_serve(
				$remote_path,
				array(
					'filename' => ner_michoel_build_shiur_download_filename( $post_id, pathinfo( $remote_path, PATHINFO_EXTENSION ) ),
					'fallback' => $remote ? $remote : '',
					'slots'    => max( 1, $cfg['max'] - 2 ),
				)
			);
			exit;
		}
		if ( $remote ) {
			wp_redirect( $remote ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
			exit;
		}
		return;
	}

	$mime     = get_post_mime_type( $attachment_id );
	$ext      = pathinfo( $file, PATHINFO_EXTENSION );
	$filename = ner_michoel_build_shiur_download_filename( $post_id, $ext );

	nocache_headers();
	header( 'Content-Type: ' . ( $mime ? $mime : 'application/octet-stream' ) );
	$ascii    = trim( preg_replace( '/[^A-Za-z0-9._ -]+/', '_', $filename ) );
	header( 'Content-Disposition: attachment; filename="' . ( '' === $ascii ? 'download' : $ascii ) . '"; filename*=UTF-8\'\'' . rawurlencode( $filename ) );
	header( 'Content-Length: ' . filesize( $file ) );
	header( 'X-Robots-Tag: noindex' );

	readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile
	exit;
}

/**
 * "{Title}.ext": the shiur's title as it reads on the site (R' Ariel Katz, 2026-11-05:
 * no speaker name in front, and no "8217" where the title has an apostrophe).
 * Entities are turned into characters first; apostrophes and quote marks are left
 * out ("Shimon's" -> "Shimons"); characters Windows won't allow in a file name
 * become spaces. Hebrew and other letters stay. A title that leaves nothing gives
 * "shiur-{id}".
 */
function ner_michoel_build_shiur_download_filename( $post_id, $ext ) {
	$name = html_entity_decode( wp_strip_all_tags( get_the_title( $post_id ) ), ENT_QUOTES, 'UTF-8' );
	$name = str_replace( array( "'", '’', '‘', '`', '´', '"', '“', '”' ), '', $name );
	$name = preg_replace( '~[\\\\/:*?<>|\x00-\x1F\x7F]+~u', ' ', $name );
	$name = trim( preg_replace( '/\s+/u', ' ', (string) $name ), " .\t" );
	if ( function_exists( 'mb_substr' ) ) {
		$name = trim( mb_substr( $name, 0, 150, 'UTF-8' ) );
	}
	if ( '' === $name ) {
		$name = 'shiur-' . (int) $post_id;
	}
	$ext = preg_replace( '/[^A-Za-z0-9]/', '', (string) $ext );
	return '' === $ext ? $name : $name . '.' . strtolower( $ext );
}

/**
 * Front-end accessor: the download URL for a shiur, or '' if it has
 * no media attached. Checked against the raw attachment meta (not
 * ner_michoel_get_shiur_audio_url(), which now only returns a value
 * for the audio case) so this works for both audio and video shiurim.
 */
function ner_michoel_get_shiur_download_url( $post_id ) {
	if ( ! get_post_meta( $post_id, '_shiur_audio_id', true ) ) {
		return '';
	}
	return add_query_arg( 'nm_download', '1', get_permalink( $post_id ) );
}
