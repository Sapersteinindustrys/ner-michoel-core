<?php
/**
 * Lets a visitor download a shiur's media file directly (audio or
 * video), with a friendly "{Speaker} - {Title}.ext" filename, instead
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
	if ( ! get_query_var( 'nm_download' ) || ! is_singular( 'shiur' ) ) {
		return;
	}
	ner_michoel_stream_shiur_download( get_queried_object_id() );
}
add_action( 'template_redirect', 'ner_michoel_maybe_serve_shiur_download' );

/**
 * Streams the shiur's audio file as a download and ends the request.
 * Falls through silently (normal page render) if there's no audio
 * attached — the query var alone can't force a download that isn't
 * there.
 */
function ner_michoel_stream_shiur_download( $post_id ) {
	$attachment_id = get_post_meta( $post_id, '_shiur_audio_id', true );
	if ( ! $attachment_id ) {
		return;
	}

	$file = get_attached_file( $attachment_id );
	if ( ! $file || ! file_exists( $file ) ) {
		return;
	}

	$mime     = get_post_mime_type( $attachment_id );
	$ext      = pathinfo( $file, PATHINFO_EXTENSION );
	$filename = ner_michoel_build_shiur_download_filename( $post_id, $ext );

	nocache_headers();
	header( 'Content-Type: ' . ( $mime ? $mime : 'application/octet-stream' ) );
	header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
	header( 'Content-Length: ' . filesize( $file ) );
	header( 'X-Robots-Tag: noindex' );

	readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile
	exit;
}

/**
 * "{Speaker} - {Title}.ext", falling back to just the title if no
 * speaker is set. sanitize_file_name() strips filesystem-unsafe
 * characters while preserving non-Latin (e.g. Hebrew/Yiddish) names.
 */
function ner_michoel_build_shiur_download_filename( $post_id, $ext ) {
	$title         = get_the_title( $post_id );
	$speaker_terms = get_the_terms( $post_id, 'speaker' );
	$speaker       = ( $speaker_terms && ! is_wp_error( $speaker_terms ) ) ? $speaker_terms[0]->name : '';

	$name = $speaker ? $speaker . ' - ' . $title : $title;
	$name = sanitize_file_name( $name );

	return $name . '.' . $ext;
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
