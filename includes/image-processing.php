<?php
/**
 * Defers generation of this plugin's custom ("nm_*") image sizes to a
 * background WP-Cron job, instead of the synchronous resize
 * WordPress normally does inside the upload/save request — so
 * attaching a large photo to a speaker, series, or gallery doesn't
 * make the admin wait on a crop it doesn't need yet.
 *
 * Core sizes (thumbnail/medium/large/etc.) are untouched and still
 * generate immediately, as WordPress normally does. Only the sizes
 * this plugin registers are deferred.
 *
 * Note on "background": WordPress has no built-in job queue, so this
 * uses WP-Cron — the resize is scheduled for "now" but actually runs
 * on the next pageview that triggers WP-Cron (or the next real system
 * cron hit on wp-cron.php, if one's configured). That's normally
 * seconds on an active site. Until it runs, requesting one of these
 * sizes falls back to the full original — WordPress' standard
 * behavior for a size that doesn't exist yet — so nothing is broken
 * in the meantime, it just briefly isn't the cropped/optimized size.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every custom size this plugin registers, kept in one place so the
 * deferral logic below always matches whatever term-meta.php /
 * gallery.php add via add_image_size().
 */
function ner_michoel_get_custom_image_sizes() {
	return array( 'nm_speaker_photo', 'nm_series_cover', 'nm_gallery_thumb', 'nm_gallery_slide', 'nm_hero_slide' );
}

/**
 * Strip our custom sizes out of the set WordPress generates during
 * the upload/save request itself.
 */
function ner_michoel_defer_custom_image_sizes( $sizes ) {
	foreach ( ner_michoel_get_custom_image_sizes() as $size ) {
		unset( $sizes[ $size ] );
	}
	return $sizes;
}
add_filter( 'intermediate_image_sizes_advanced', 'ner_michoel_defer_custom_image_sizes' );

/**
 * After WordPress finishes generating the (now-reduced) metadata,
 * queue a one-off background job to generate just our sizes.
 */
function ner_michoel_queue_custom_image_sizes( $metadata, $attachment_id ) {
	if ( wp_attachment_is_image( $attachment_id )
		&& ! wp_next_scheduled( 'ner_michoel_generate_custom_sizes', array( $attachment_id ) ) ) {
		wp_schedule_single_event( time(), 'ner_michoel_generate_custom_sizes', array( $attachment_id ) );
	}
	return $metadata;
}
add_filter( 'wp_generate_attachment_metadata', 'ner_michoel_queue_custom_image_sizes', 10, 2 );

/**
 * The background job itself: generate this plugin's custom sizes for
 * one attachment and merge them into its stored metadata, the same
 * way WordPress' own thumbnail generation does.
 */
function ner_michoel_generate_custom_sizes( $attachment_id ) {
	$file = get_attached_file( $attachment_id );
	if ( ! $file || ! file_exists( $file ) ) {
		return;
	}

	$metadata = wp_get_attachment_metadata( $attachment_id );
	if ( ! is_array( $metadata ) ) {
		return;
	}
	$metadata['sizes'] = isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? $metadata['sizes'] : array();

	require_once ABSPATH . 'wp-admin/includes/image.php';

	global $_wp_additional_image_sizes;

	foreach ( ner_michoel_get_custom_image_sizes() as $size ) {
		if ( isset( $metadata['sizes'][ $size ] ) || empty( $_wp_additional_image_sizes[ $size ] ) ) {
			continue; // Already generated, or the size isn't registered (yet).
		}
		$spec    = $_wp_additional_image_sizes[ $size ];
		$resized = image_make_intermediate_size( $file, $spec['width'], $spec['height'], $spec['crop'] );
		if ( $resized ) {
			$metadata['sizes'][ $size ] = $resized;
		}
	}

	wp_update_attachment_metadata( $attachment_id, $metadata );
}
add_action( 'ner_michoel_generate_custom_sizes', 'ner_michoel_generate_custom_sizes' );
