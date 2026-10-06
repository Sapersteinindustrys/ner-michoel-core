<?php
/**
 * Summary batch for written shiurim: fills the Summary (post_excerpt) of each
 * written shiur that has a PDF and no Summary yet, from the PDF's own first
 * line (pdf-first-line.php). The importer's cron runs it, and only once the
 * queue is drained (library-import.php), so new files are imported first and
 * the batch picks them up after.
 *
 * Each post is marked with _nm_summary_checked once it has been tried, so a PDF
 * with no readable text isn't retried on every tick. A PDF that can't be
 * downloaded is retried up to three times (_nm_summary_attempts) before it's
 * marked. _nm_summary_result records the outcome: filled, no-text, too-large,
 * unreadable, or save-failed.
 *
 * A post whose Summary was typed or pasted in the admin already has an
 * excerpt, so it's left alone.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Larger PDFs are skipped: reading them into memory on shared hosting isn't safe.
define( 'NER_MICHOEL_SUMMARY_MAX_BYTES', 40 * 1048576 );

/**
 * Query for written shiurim still waiting for a Summary. Pass $count_only for
 * the number, otherwise the rows (ID and PDF attachment ID). $skip excludes posts
 * already tried in this run.
 */
function ner_michoel_summary_batch_sql( $count_only, $limit = 0, $skip = array() ) {
	global $wpdb;

	$select  = $count_only ? 'COUNT(*)' : 'p.ID, pm.meta_value AS attachment_id';
	$exclude = '';
	if ( $skip ) {
		$exclude = ' AND p.ID NOT IN (' . implode( ',', array_map( 'absint', $skip ) ) . ')';
	}

	$sql = "SELECT {$select} FROM {$wpdb->posts} p
		INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_written_pdf_id'
		LEFT JOIN {$wpdb->postmeta} done ON done.post_id = p.ID AND done.meta_key = '_nm_summary_checked'
		WHERE p.post_type = 'written_shiur' AND p.post_status = 'publish' AND p.post_excerpt = '' AND done.meta_id IS NULL{$exclude}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	if ( ! $count_only ) {
		$sql .= ' ORDER BY p.ID ASC LIMIT ' . absint( $limit );
	}

	return $sql;
}

function ner_michoel_summary_batch_pending_count() {
	global $wpdb;
	return (int) $wpdb->get_var( ner_michoel_summary_batch_sql( true ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}

/**
 * Works through the pending posts until $deadline. Returns how many are still
 * pending, so the caller knows whether to come back.
 */
function ner_michoel_summary_batch_run( $deadline ) {
	global $wpdb;

	$skip = array();
	while ( time() < $deadline ) {
		$rows = $wpdb->get_results( ner_michoel_summary_batch_sql( false, 5, $skip ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $rows ) {
			break;
		}

		foreach ( $rows as $row ) {
			if ( time() >= $deadline ) {
				break 2;
			}

			$post_id = (int) $row->ID;
			$read    = ner_michoel_summary_read_pdf( (int) $row->attachment_id );

			if ( 'ok' !== $read['status'] ) {
				if ( 'unreadable' === $read['status'] ) {
					$attempts = (int) get_post_meta( $post_id, '_nm_summary_attempts', true ) + 1;
					update_post_meta( $post_id, '_nm_summary_attempts', $attempts );
					if ( $attempts < 3 ) {
						$skip[] = $post_id;
						continue;
					}
				}
				ner_michoel_summary_mark( $post_id, $read['status'] );
				continue;
			}

			$line = ner_michoel_pdf_first_line( $read['bytes'] );
			unset( $read );

			if ( '' === $line ) {
				ner_michoel_summary_mark( $post_id, 'no-text' );
				continue;
			}

			$saved = $wpdb->update( $wpdb->posts, array( 'post_excerpt' => $line ), array( 'ID' => $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			if ( false === $saved ) {
				// Not marked, so it isn't left looking done. Skipped for this run only.
				$skip[] = $post_id;
				continue;
			}

			clean_post_cache( $post_id );
			ner_michoel_summary_mark( $post_id, 'filled' );
		}
	}

	return ner_michoel_summary_batch_pending_count();
}

function ner_michoel_summary_mark( $post_id, $result ) {
	update_post_meta( $post_id, '_nm_summary_checked', time() );
	update_post_meta( $post_id, '_nm_summary_result', $result );
}

/**
 * The PDF's bytes, from the local copy when there is one (the Bunny offload
 * removes it after upload), else from its public URL. Returns status and bytes:
 * ok, unreadable (try again later), or too-large.
 */
function ner_michoel_summary_read_pdf( $attachment_id ) {
	if ( $attachment_id <= 0 ) {
		return array( 'status' => 'unreadable', 'bytes' => '' );
	}

	$path = get_attached_file( $attachment_id );
	if ( $path && is_readable( $path ) ) {
		if ( filesize( $path ) > NER_MICHOEL_SUMMARY_MAX_BYTES ) {
			return array( 'status' => 'too-large', 'bytes' => '' );
		}
		$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( is_string( $bytes ) && '' !== $bytes ) {
			return array( 'status' => 'ok', 'bytes' => $bytes );
		}
	}

	$url = wp_get_attachment_url( $attachment_id );
	if ( ! $url ) {
		return array( 'status' => 'unreadable', 'bytes' => '' );
	}

	$response = wp_remote_get(
		$url,
		array(
			'timeout'             => 60,
			'limit_response_size' => NER_MICHOEL_SUMMARY_MAX_BYTES,
		)
	);
	if ( is_wp_error( $response ) ) {
		return array( 'status' => 'unreadable', 'bytes' => '' );
	}
	if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return array( 'status' => 'unreadable', 'bytes' => '' );
	}

	$body = wp_remote_retrieve_body( $response );
	if ( '' === $body ) {
		return array( 'status' => 'unreadable', 'bytes' => '' );
	}

	return array( 'status' => 'ok', 'bytes' => $body );
}
