<?php
/**
 * Full-library import from nermichoel.org — a background crawler +
 * batched importer for the entire historical archive (16,756 shiurim
 * at last count, across ~671 listing pages), not a one-click action.
 *
 * Two phases, both resumable and both paced to be polite to
 * nermichoel.org's server rather than hammering it:
 *
 * 1. Discovery — crawls the listing pages (nm_import_discover_page,
 *    a self-rescheduling single WP-Cron event, one listing page per
 *    tick) and writes what each page tells us about every shiur into
 *    the wp_nm_import_queue table with status 'discovered'. Cheap:
 *    just an HTML fetch + parse, no media downloads yet.
 *
 * 2. Processing — nm_import_process_batch (a self-rescheduling single
 *    event) pulls a small batch of 'discovered' rows, fetches the
 *    shiur's detail page if the listing page didn't already give us a
 *    direct media URL, sideloads the audio/video/PDF into the media
 *    library, creates the `shiur` post + speaker/series terms, and
 *    marks the row 'imported' or 'failed' (with an error message, not
 *    a silent drop).
 *
 * The HTML parsing in ner_michoel_extract_listing_items() and
 * ner_michoel_extract_shiur_detail() is written against a real saved
 * sample page (see dev-notes.md), not guessed — but has only been
 * checked by eye against that one sample, not run against the live
 * site's actual crawl. Verify on a small run (a page or two) before
 * leaving this unattended for all 671 pages. Three media types exist:
 * audio and written/PDF shiurim link straight to their file from the
 * listing page; video shiurim are Vimeo-hosted (no downloadable file
 * at all) and need a second fetch of their detail page to pull the
 * Vimeo ID. Written/PDF shiurim are discovered but deliberately not
 * turned into posts yet — see the note in ner_michoel_process_queue_row().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NER_MICHOEL_IMPORT_QUEUE_DB_VERSION', '1.0' );
define( 'NER_MICHOEL_IMPORT_LISTING_BASE', 'https://nermichoel.org/index/shiur' );
define( 'NER_MICHOEL_IMPORT_TOTAL_PAGES', 671 ); // From the archive's own "25 of 16756 results" — re-verify if this changes.

function ner_michoel_import_queue_table_name() {
	global $wpdb;
	return $wpdb->prefix . 'nm_import_queue';
}

function ner_michoel_create_import_queue_table() {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table_name      = ner_michoel_import_queue_table_name();
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table_name} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		source_url VARCHAR(500) NOT NULL,
		title VARCHAR(500) NULL,
		speaker VARCHAR(255) NULL,
		series VARCHAR(255) NULL,
		shiur_date VARCHAR(50) NULL,
		duration VARCHAR(20) NULL,
		media_url VARCHAR(500) NULL,
		media_type VARCHAR(20) NULL,
		status VARCHAR(20) NOT NULL DEFAULT 'discovered',
		attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
		error_message TEXT NULL,
		created_post_id BIGINT UNSIGNED NULL,
		discovered_at DATETIME NOT NULL,
		processed_at DATETIME NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY source_url (source_url(191)),
		KEY status (status)
	) {$charset_collate};";

	dbDelta( $sql );

	update_option( 'nm_import_queue_db_version', NER_MICHOEL_IMPORT_QUEUE_DB_VERSION );
}

function ner_michoel_maybe_create_import_queue_table() {
	if ( get_option( 'nm_import_queue_db_version' ) !== NER_MICHOEL_IMPORT_QUEUE_DB_VERSION ) {
		ner_michoel_create_import_queue_table();
	}
}
add_action( 'admin_init', 'ner_michoel_maybe_create_import_queue_table' );

/**
 * Master on/off switch, checked at the top of both cron jobs so
 * "Pause" actually stops work rather than just hiding the UI.
 */
function ner_michoel_import_is_running() {
	return (bool) get_option( 'nm_import_running', false );
}

/**
 * ============================================================
 * PHASE 1: DISCOVERY
 * ============================================================
 */

/**
 * Confirmed real structure (from a saved sample page, see dev-notes.md):
 * each shiur is a <tr class="odd|even"> with 5 <td> — Title (plain
 * text; a stray unmatched </a> in their template, no real link to
 * follow), Magid Shiur (plain text), Date (two <p>: Gregorian then
 * Hebrew), a media cell whose *class* tells you the type, and a
 * "download" cell. Category/subcategory live in the NEXT row
 * (<tr class="categories odd|even">), not the shiur's own row.
 *
 * Media cell / type:
 * - Audio:  class="stream", has <audio data-duration="MM:SS"><source src="...">.
 *   Direct file URL is also in the download cell — used from there for
 *   consistency with the PDF case below.
 * - Written/PDF: class="written-shiurim-read-header". Its own <a href>
 *   is a page route ending in .pdf, NOT the raw file — the real file
 *   URL is in the download cell, same as audio.
 * - Video: no distinguishing class, contains a thumbnail linking to
 *   /galleries/videoshiurim-detail/id/{ID}. Download cell is empty
 *   (&nbsp;) — there's no downloadable file at all, it's Vimeo-hosted.
 *   Needs a Phase 2 detail-page fetch to get the Vimeo ID.
 */
function ner_michoel_extract_listing_items( $html ) {
	if ( '' === trim( (string) $html ) ) {
		return array();
	}

	$doc = new DOMDocument();
	$prev_errors = libxml_use_internal_errors( true );
	$doc->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
	libxml_clear_errors();
	libxml_use_internal_errors( $prev_errors );

	$xpath = new DOMXPath( $doc );
	$rows  = $xpath->query( "//tr[@class='odd' or @class='even']" );

	$items = array();

	foreach ( $rows as $row ) {
		$cells = $xpath->query( './td', $row );
		if ( $cells->length < 5 ) {
			continue; // Not a shiur row in the expected shape — skip, don't guess.
		}

		$title   = trim( $cells->item( 0 )->textContent );
		$speaker = trim( $cells->item( 1 )->textContent );

		$date_paras     = $xpath->query( './/p', $cells->item( 2 ) );
		$shiur_date_raw = $date_paras->length ? trim( $date_paras->item( 0 )->textContent ) : '';
		$shiur_date     = ner_michoel_parse_import_date( $shiur_date_raw );

		$media_cell   = $cells->item( 3 );
		$media_class  = $media_cell->getAttribute( 'class' );
		$audio_el     = $xpath->query( './/audio', $media_cell )->item( 0 );
		$download_url = trim( $xpath->query( './/a[contains(@class,"noOffsiteLink")]/@href', $cells->item( 4 ) )->item( 0 ) ? $xpath->query( './/a[contains(@class,"noOffsiteLink")]/@href', $cells->item( 4 ) )->item( 0 )->nodeValue : '' );

		$media_type = '';
		$duration   = '';
		$media_url  = '';
		$source_url = '';

		if ( $audio_el ) {
			$media_type = 'audio';
			$duration   = $audio_el->getAttribute( 'data-duration' );
			$media_url  = $download_url;
			$source_url = $download_url;
		} elseif ( false !== strpos( $media_class, 'written-shiurim-read-header' ) ) {
			$media_type = 'pdf';
			$media_url  = $download_url;
			$source_url = $download_url;
		} else {
			$video_href = $xpath->query( './/a[contains(@href,"/galleries/videoshiurim-detail/")]/@href', $media_cell )->item( 0 );
			if ( $video_href ) {
				$media_type = 'video';
				$source_url = ner_michoel_absolutize_import_url( trim( $video_href->nodeValue ) );
			}
		}

		if ( '' === $title || '' === $media_type || '' === $source_url ) {
			continue; // Couldn't identify this row confidently — skip rather than guess.
		}

		// Category/subcategory from the following "categories" sibling row.
		$series = '';
		$next   = $row->nextSibling;
		while ( $next && XML_ELEMENT_NODE !== $next->nodeType ) {
			$next = $next->nextSibling;
		}
		if ( $next && 'tr' === $next->nodeName && false !== strpos( $next->getAttribute( 'class' ), 'categories' ) ) {
			$names = array();
			foreach ( $xpath->query( './/a', $next ) as $a ) {
				$names[] = trim( $a->textContent );
			}
			$series = implode( ' / ', $names );
		}

		$items[] = array(
			'source_url' => $source_url,
			'title'      => $title,
			'speaker'    => $speaker,
			'series'     => $series,
			'shiur_date' => $shiur_date,
			'duration'   => $duration,
			'media_url'  => $media_url,
			'media_type' => $media_type,
		);
	}

	return $items;
}

/**
 * "09/18/2026" (the format actually used) → "2026-09-18", or '' if it
 * doesn't parse — a row with an unparseable date still gets queued,
 * just falls back to today's date at insert time (see
 * ner_michoel_process_queue_row()) rather than being dropped entirely.
 */
function ner_michoel_parse_import_date( $raw ) {
	$date = DateTime::createFromFormat( 'm/d/Y', trim( $raw ) );
	return $date ? $date->format( 'Y-m-d' ) : '';
}

function ner_michoel_absolutize_import_url( $href ) {
	if ( '' === $href ) {
		return '';
	}
	if ( 0 === strpos( $href, 'http' ) ) {
		return $href;
	}
	return 'https://nermichoel.org' . ( '/' === $href[0] ? '' : '/' ) . $href;
}

function ner_michoel_import_listing_url( $page ) {
	return 1 === $page
		? NER_MICHOEL_IMPORT_LISTING_BASE
		: trailingslashit( NER_MICHOEL_IMPORT_LISTING_BASE ) . 'page/' . absint( $page );
}

/**
 * One tick: fetch one listing page, queue its items, schedule the
 * next page (or mark discovery done). Politeness delay between pages
 * is configurable — default 3s — since this is someone else's server.
 */
function ner_michoel_run_discovery_tick() {
	if ( ! ner_michoel_import_is_running() ) {
		return;
	}

	$page = (int) get_option( 'nm_import_discovery_page', 1 );

	if ( $page > NER_MICHOEL_IMPORT_TOTAL_PAGES ) {
		update_option( 'nm_import_discovery_done', true );
		return;
	}

	$response = wp_remote_get(
		ner_michoel_import_listing_url( $page ),
		array(
			'timeout'    => 30,
			'user-agent' => 'NerMichoelSiteMigration/1.0 (+https://nermichoel.org)',
		)
	);

	if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
		$items = ner_michoel_extract_listing_items( wp_remote_retrieve_body( $response ) );
		ner_michoel_queue_discovered_items( $items );
		update_option( 'nm_import_discovery_page', $page + 1 );
	} else {
		// Don't silently stall forever on one bad page — log and move on.
		update_option( 'nm_import_last_discovery_error', sprintf( 'Page %d: %s', $page, is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_response_code( $response ) ) );
		update_option( 'nm_import_discovery_page', $page + 1 );
	}

	if ( ner_michoel_import_is_running() ) {
		$delay = (int) get_option( 'nm_import_request_delay', 5 );
		wp_schedule_single_event( time() + max( 1, $delay ), 'nm_import_discover_page' );
	}
}
add_action( 'nm_import_discover_page', 'ner_michoel_run_discovery_tick' );

function ner_michoel_queue_discovered_items( array $items ) {
	global $wpdb;
	$table = ner_michoel_import_queue_table_name();

	foreach ( $items as $item ) {
		if ( empty( $item['source_url'] ) ) {
			continue;
		}
		// INSERT IGNORE via the unique source_url key — safe to
		// re-run discovery without duplicating already-queued rows.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (source_url, title, speaker, series, shiur_date, duration, media_url, media_type, status, discovered_at) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, 'discovered', %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$item['source_url'],
				isset( $item['title'] ) ? $item['title'] : '',
				isset( $item['speaker'] ) ? $item['speaker'] : '',
				isset( $item['series'] ) ? $item['series'] : '',
				isset( $item['shiur_date'] ) ? $item['shiur_date'] : '',
				isset( $item['duration'] ) ? $item['duration'] : '',
				isset( $item['media_url'] ) ? $item['media_url'] : '',
				isset( $item['media_type'] ) ? $item['media_type'] : '',
				current_time( 'mysql', true )
			)
		);
	}
}

/**
 * ============================================================
 * PHASE 2: PROCESSING
 * ============================================================
 */

/**
 * Only ever called for 'video' rows (audio/pdf already have their file
 * URL from the listing page). Confirmed structure: the video detail
 * page embeds Vimeo via <iframe src="//player.vimeo.com/video/{ID}?...">
 * — there's no downloadable file at all (it's Vimeo-hosted, not
 * self-hosted), so this returns a Vimeo ID for an embed, not a
 * media_url to sideload. ner_michoel_process_queue_row() below handles
 * that distinction.
 */
function ner_michoel_extract_shiur_detail( $html ) {
	if ( preg_match( '#player\.vimeo\.com/video/(\d+)#', (string) $html, $m ) ) {
		return array(
			'vimeo_id' => $m[1],
		);
	}
	return array();
}

/**
 * Processes one queued row into a real shiur post. Mirrors
 * ner_michoel_sample_content_get_or_create_term() /
 * ner_michoel_sample_content_sideload_audio() in sample-content.php —
 * same approach, just driven from the queue table instead of a
 * hardcoded array.
 */
function ner_michoel_process_queue_row( $row ) {
	$media_url  = $row->media_url;
	$media_type = $row->media_type;

	/*
	 * "Written shiurim" (PDFs) have no home in the `shiur` post type
	 * yet — it's built entirely around audio/video playback (queue,
	 * play button, download link). Forcing a PDF in as `_shiur_audio_id`
	 * would get silently misclassified as an audio shiur by
	 * ner_michoel_get_shiur_media_type() (it only distinguishes
	 * video-vs-not, defaults everything else to 'audio'). Skipped, not
	 * dropped — stays in the queue as a real discovered count, ready to
	 * process once/if written shiurim get their own content model.
	 */
	if ( 'pdf' === $media_type ) {
		ner_michoel_mark_queue_row( $row->id, 'skipped', 'Written/PDF shiur — not yet supported by the shiur post type, see dev-notes.md' );
		return;
	}

	$vimeo_id = '';
	if ( 'video' === $media_type ) {
		$response = wp_remote_get(
			$row->source_url,
			array(
				'timeout'    => 30,
				'user-agent' => 'NerMichoelSiteMigration/1.0 (+https://nermichoel.org)',
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			ner_michoel_mark_queue_row( $row->id, 'failed', is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $response ) );
			return;
		}
		$detail = ner_michoel_extract_shiur_detail( wp_remote_retrieve_body( $response ) );
		if ( empty( $detail['vimeo_id'] ) ) {
			ner_michoel_mark_queue_row( $row->id, 'failed', 'No Vimeo ID found on video detail page' );
			return;
		}
		$vimeo_id = $detail['vimeo_id'];
	} elseif ( ! $media_url ) {
		ner_michoel_mark_queue_row( $row->id, 'failed', 'No media URL' );
		return;
	}

	if ( ! $row->title ) {
		ner_michoel_mark_queue_row( $row->id, 'failed', 'No title' );
		return;
	}

	// Idempotency across retries/reprocessing: title match, same
	// reasoning as ner_michoel_handle_import_sample_content().
	$existing = get_posts(
		array(
			'post_type'   => 'shiur',
			'title'       => $row->title,
			'post_status' => 'any',
			'numberposts' => 1,
			'fields'      => 'ids',
		)
	);
	if ( $existing ) {
		ner_michoel_mark_queue_row( $row->id, 'skipped', 'Shiur with this title already exists', $existing[0] );
		return;
	}

	$post_id = wp_insert_post(
		array(
			'post_type'   => 'shiur',
			'post_title'  => $row->title,
			'post_status' => 'publish',
			'post_date'   => $row->shiur_date ? $row->shiur_date . ' 00:00:00' : current_time( 'mysql' ),
		),
		true
	);

	if ( is_wp_error( $post_id ) ) {
		ner_michoel_mark_queue_row( $row->id, 'failed', $post_id->get_error_message() );
		return;
	}

	if ( $row->duration ) {
		update_post_meta( $post_id, '_shiur_duration', sanitize_text_field( $row->duration ) );
	}
	if ( $row->speaker && function_exists( 'ner_michoel_sample_content_get_or_create_term' ) ) {
		$speaker_id = ner_michoel_sample_content_get_or_create_term( $row->speaker, 'speaker' );
		if ( $speaker_id ) {
			wp_set_object_terms( $post_id, array( $speaker_id ), 'speaker' );
		}
	}
	if ( $row->series && function_exists( 'ner_michoel_sample_content_get_or_create_term' ) ) {
		$series_id = ner_michoel_sample_content_get_or_create_term( $row->series, 'series' );
		if ( $series_id ) {
			wp_set_object_terms( $post_id, array( $series_id ), 'series' );
		}
	}

	if ( 'video' === $media_type ) {
		/*
		 * No file to sideload — Vimeo-hosted, not self-hosted. Stored
		 * as its own meta rather than forced into `_shiur_audio_id`,
		 * with ner_michoel_get_shiur_media_type() (shiur-meta.php)
		 * extended to recognize it as a distinct 'video-embed' type.
		 * Theme-side rendering (an <iframe> instead of a native
		 * <video>) is still open — see dev-notes.md.
		 */
		update_post_meta( $post_id, '_shiur_vimeo_id', sanitize_text_field( $vimeo_id ) );
		ner_michoel_mark_queue_row( $row->id, 'imported', '', $post_id );
		return;
	}

	$attachment_id = function_exists( 'ner_michoel_sample_content_sideload_audio' )
		? ner_michoel_sample_content_sideload_audio( $media_url, $post_id )
		: 0;

	if ( $attachment_id ) {
		update_post_meta( $post_id, '_shiur_audio_id', $attachment_id );
		ner_michoel_mark_queue_row( $row->id, 'imported', '', $post_id );
	} else {
		// Post exists but media failed to attach — visible via the
		// Missing Audio report either way, not silently lost.
		ner_michoel_mark_queue_row( $row->id, 'imported', 'Post created but media download failed', $post_id );
	}
}

function ner_michoel_mark_queue_row( $id, $status, $error = '', $post_id = null ) {
	global $wpdb;
	$wpdb->update(
		ner_michoel_import_queue_table_name(),
		array(
			'status'          => $status,
			'error_message'   => $error,
			'created_post_id' => $post_id,
			'processed_at'    => current_time( 'mysql', true ),
		),
		array( 'id' => $id ),
		array( '%s', '%s', '%d', '%s' ),
		array( '%d' )
	);
}

/**
 * One tick: process a small batch (default 5) of 'discovered' rows,
 * then reschedule. Batch size and delay are both deliberately small —
 * each row can mean downloading a full audio file from someone else's
 * server, not just a page fetch.
 */
function ner_michoel_run_processing_tick() {
	if ( ! ner_michoel_import_is_running() ) {
		return;
	}

	global $wpdb;
	$table      = ner_michoel_import_queue_table_name();
	$batch_size = (int) get_option( 'nm_import_batch_size', 3 );

	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = 'discovered' AND attempts < 3 ORDER BY id ASC LIMIT %d", $batch_size ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	$first = true;
	foreach ( $rows as $row ) {
		// A beat between items within the same batch, not just between
		// batches — each one can mean downloading a full media file
		// from someone else's server, this is meant to run slowly.
		if ( ! $first ) {
			sleep( 1 );
		}
		$first = false;

		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET attempts = attempts + 1 WHERE id = %d", $row->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		ner_michoel_process_queue_row( $row );
	}

	if ( ner_michoel_import_is_running() ) {
		$delay = (int) get_option( 'nm_import_request_delay', 5 );
		wp_schedule_single_event( time() + max( 1, $delay ), 'nm_import_process_batch' );
	}
}
add_action( 'nm_import_process_batch', 'ner_michoel_run_processing_tick' );

/**
 * ============================================================
 * ADMIN: Start/Pause + progress.
 * ============================================================
 */

function ner_michoel_import_counts() {
	global $wpdb;
	$table = ner_michoel_import_queue_table_name();
	$rows  = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$counts = array(
		'discovered' => 0,
		'imported'   => 0,
		'failed'     => 0,
		'skipped'    => 0,
	);
	foreach ( (array) $rows as $r ) {
		if ( isset( $counts[ $r['status'] ] ) ) {
			$counts[ $r['status'] ] = (int) $r['n'];
		}
	}
	return $counts;
}

/**
 * Shared by the wp-admin link (nonce + browser session) and the REST
 * endpoint below (application-password auth, no browser needed) — one
 * place for what "start"/"pause"/"reset" actually do.
 */
function ner_michoel_apply_import_action( $action ) {
	if ( 'start' === $action ) {
		update_option( 'nm_import_running', true );
		if ( ! wp_next_scheduled( 'nm_import_discover_page' ) && ! get_option( 'nm_import_discovery_done' ) ) {
			wp_schedule_single_event( time(), 'nm_import_discover_page' );
		}
		if ( ! wp_next_scheduled( 'nm_import_process_batch' ) ) {
			wp_schedule_single_event( time(), 'nm_import_process_batch' );
		}
	} elseif ( 'pause' === $action ) {
		update_option( 'nm_import_running', false );
	} elseif ( 'reset' === $action ) {
		update_option( 'nm_import_running', false );
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . ner_michoel_import_queue_table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		update_option( 'nm_import_discovery_page', 1 );
		delete_option( 'nm_import_discovery_done' );
	}
}

function ner_michoel_handle_import_toggle() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'ner-michoel-core' ) );
	}
	check_admin_referer( 'nm_import_toggle' );

	ner_michoel_apply_import_action( isset( $_GET['nm_action'] ) ? sanitize_key( $_GET['nm_action'] ) : '' );

	wp_safe_redirect( admin_url( 'admin.php?page=nm-library-import' ) );
	exit;
}
add_action( 'admin_action_nm_import_toggle', 'ner_michoel_handle_import_toggle' );

/**
 * REST equivalent — manage_options-gated, works with an application
 * password. Exists because the wp-admin link needs a real browser
 * session (nonce tied to a page load), which isn't available to a
 * REST-only client.
 */
function ner_michoel_register_import_control_route() {
	register_rest_route(
		'ner-michoel/v1',
		'/import-control',
		array(
			'methods'             => 'POST',
			'callback'            => 'ner_michoel_handle_import_control_rest',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'args'                => array(
				'action' => array(
					'required' => true,
					'enum'     => array( 'start', 'pause', 'reset', 'status' ),
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'ner_michoel_register_import_control_route' );

function ner_michoel_handle_import_control_rest( WP_REST_Request $request ) {
	$action = $request->get_param( 'action' );

	if ( 'status' !== $action ) {
		ner_michoel_apply_import_action( $action );
	}

	return new WP_REST_Response(
		array(
			'running'            => ner_michoel_import_is_running(),
			'discovery_page'     => (int) get_option( 'nm_import_discovery_page', 1 ),
			'discovery_done'     => (bool) get_option( 'nm_import_discovery_done' ),
			'total_pages'        => (int) NER_MICHOEL_IMPORT_TOTAL_PAGES,
			'counts'             => ner_michoel_import_counts(),
			'last_discovery_error' => get_option( 'nm_import_last_discovery_error', '' ),
		),
		200
	);
}

function ner_michoel_render_library_import_page() {
	$counts       = ner_michoel_import_counts();
	$running      = ner_michoel_import_is_running();
	$disc_page  = (int) get_option( 'nm_import_discovery_page', 1 );
	$disc_done  = (bool) get_option( 'nm_import_discovery_done' );
	$last_error = get_option( 'nm_import_last_discovery_error', '' );
	?>
	<div class="wrap nm-dashboard">
		<h1><?php esc_html_e( 'Full Library Import', 'ner-michoel-core' ); ?></h1>

		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'Verify on a small run first.', 'ner-michoel-core' ); ?></strong>
				<?php esc_html_e( 'The parser is implemented against a real sample page, but has not run against the live site\'s full crawl yet. Start it, let it discover a page or two, then check the Shiurim list and a few actual posts before leaving it running unattended for the full 671 pages. Written/PDF shiurim are intentionally skipped (not yet supported by the shiur post type) — see dev-notes.md.', 'ner-michoel-core' ); ?>
			</p>
		</div>

		<p><?php esc_html_e( 'Imports the entire nermichoel.org shiur archive in the background — this runs over hours to days, not instantly, and is safe to pause/resume.', 'ner-michoel-core' ); ?></p>

		<table class="widefat striped" style="max-width:700px;">
			<tbody>
				<tr><th><?php esc_html_e( 'Status', 'ner-michoel-core' ); ?></th><td><?php echo $running ? esc_html__( 'Running', 'ner-michoel-core' ) : esc_html__( 'Paused', 'ner-michoel-core' ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Discovery progress', 'ner-michoel-core' ); ?></th><td>
					<?php
					if ( $disc_done ) {
						esc_html_e( 'Complete', 'ner-michoel-core' );
					} else {
						printf(
							/* translators: 1: current page, 2: total pages */
							esc_html__( 'Page %1$d of %2$d', 'ner-michoel-core' ),
							$disc_page,
							(int) NER_MICHOEL_IMPORT_TOTAL_PAGES
						);
					}
					?>
				</td></tr>
				<tr><th><?php esc_html_e( 'Discovered (queued)', 'ner-michoel-core' ); ?></th><td><?php echo esc_html( number_format_i18n( $counts['discovered'] ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Imported', 'ner-michoel-core' ); ?></th><td><?php echo esc_html( number_format_i18n( $counts['imported'] ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Skipped (already existed)', 'ner-michoel-core' ); ?></th><td><?php echo esc_html( number_format_i18n( $counts['skipped'] ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Failed', 'ner-michoel-core' ); ?></th><td><?php echo esc_html( number_format_i18n( $counts['failed'] ) ); ?></td></tr>
			</tbody>
		</table>

		<?php if ( $last_error ) : ?>
			<p class="description"><?php esc_html_e( 'Last discovery error:', 'ner-michoel-core' ); ?> <?php echo esc_html( $last_error ); ?></p>
		<?php endif; ?>

		<p>
			<?php if ( $running ) : ?>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?action=nm_import_toggle&nm_action=pause' ), 'nm_import_toggle' ) ); ?>"><?php esc_html_e( 'Pause', 'ner-michoel-core' ); ?></a>
			<?php else : ?>
				<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?action=nm_import_toggle&nm_action=start' ), 'nm_import_toggle' ) ); ?>"><?php esc_html_e( 'Start / Resume', 'ner-michoel-core' ); ?></a>
			<?php endif; ?>
			<a class="button button-link-delete" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?action=nm_import_toggle&nm_action=reset' ), 'nm_import_toggle' ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Clear the entire import queue and start over? Already-imported shiurim are not affected.', 'ner-michoel-core' ) ); ?>');"><?php esc_html_e( 'Reset Queue', 'ner-michoel-core' ); ?></a>
		</p>

		<p class="description"><?php esc_html_e( 'Relies on WP-Cron, which fires on site visits — a real system cron hitting wp-cron.php periodically keeps this moving even with low admin traffic.', 'ner-michoel-core' ); ?></p>
	</div>
	<?php
}
