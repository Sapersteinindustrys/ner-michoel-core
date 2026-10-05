<?php
/**
 * Shiur duration comes from the audio or video file, never typed in.
 *
 * The file's own length is in its attachment metadata (WordPress reads it when
 * the file is uploaded or sideloaded). Whenever a shiur's media is attached or
 * replaced, its `_shiur_duration` is set from that. That covers every route: the
 * shiur form, the wp-admin editor, Bulk Upload, and the importers. Removing the
 * media clears the duration.
 *
 * Existing shiurim are brought up to date in batches on admin visits, so a large
 * archive doesn't stall one request. Typed durations are replaced by the file's
 * length as part of that.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * h:mm:ss for an hour or more, otherwise m:ss. The same format the shiur list
 * and the player already show.
 */
function ner_michoel_format_duration( $seconds ) {
	$seconds = (int) round( (float) $seconds );
	if ( $seconds <= 0 ) {
		return '';
	}

	$hours   = intdiv( $seconds, 3600 );
	$minutes = intdiv( $seconds % 3600, 60 );
	$secs    = $seconds % 60;

	return $hours ? sprintf( '%d:%02d:%02d', $hours, $minutes, $secs ) : sprintf( '%d:%02d', $minutes, $secs );
}

/**
 * Sets a shiur's duration from its media file. Leaves the existing value alone
 * if the file has no length in its metadata (which is rare for real audio).
 */
function ner_michoel_sync_shiur_duration( $post_id, $attachment_id ) {
	$meta = wp_get_attachment_metadata( (int) $attachment_id );
	if ( ! is_array( $meta ) ) {
		return;
	}

	$formatted = '';
	if ( ! empty( $meta['length'] ) ) {
		$formatted = ner_michoel_format_duration( $meta['length'] );
	} elseif ( ! empty( $meta['length_formatted'] ) ) {
		$formatted = (string) $meta['length_formatted'];
	}

	if ( '' !== $formatted ) {
		update_post_meta( (int) $post_id, '_shiur_duration', $formatted );
	}
}

function ner_michoel_on_shiur_audio_meta( $meta_id, $post_id, $meta_key, $meta_value ) {
	if ( '_shiur_audio_id' !== $meta_key ) {
		return;
	}
	ner_michoel_sync_shiur_duration( $post_id, $meta_value );
}
add_action( 'added_post_meta', 'ner_michoel_on_shiur_audio_meta', 10, 4 );
add_action( 'updated_post_meta', 'ner_michoel_on_shiur_audio_meta', 10, 4 );

/**
 * No media means no duration. Stale values would mislead.
 */
function ner_michoel_on_shiur_audio_removed( $meta_ids, $post_id, $meta_key ) {
	if ( '_shiur_audio_id' === $meta_key ) {
		delete_post_meta( (int) $post_id, '_shiur_duration' );
	}
}
add_action( 'deleted_post_meta', 'ner_michoel_on_shiur_audio_removed', 10, 3 );

/**
 * Brings existing shiurim up to date, 300 per admin visit, from the last ID
 * done. Stops once every shiur with media has been handled.
 */
function ner_michoel_maybe_backfill_durations() {
	if ( get_option( 'nm_duration_backfill_done' ) || ! is_admin() || wp_doing_ajax() ) {
		return;
	}

	global $wpdb;
	$after = (int) get_option( 'nm_duration_backfill_after', 0 );
	$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->prepare(
			"SELECT p.ID, pm.meta_value FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_shiur_audio_id'
			WHERE p.post_type = 'shiur' AND p.ID > %d
			ORDER BY p.ID ASC LIMIT 300",
			$after
		)
	);

	foreach ( (array) $rows as $row ) {
		ner_michoel_sync_shiur_duration( $row->ID, $row->meta_value );
		$after = (int) $row->ID;
	}

	if ( count( (array) $rows ) < 300 ) {
		update_option( 'nm_duration_backfill_done', 1 );
	} else {
		update_option( 'nm_duration_backfill_after', $after );
	}
}
add_action( 'admin_init', 'ner_michoel_maybe_backfill_durations' );
