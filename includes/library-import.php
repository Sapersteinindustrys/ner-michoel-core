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
 * Vimeo ID. Written/PDF shiurim become `written_shiur` posts with their
 * PDF attached (see ner_michoel_process_queue_row()).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NER_MICHOEL_IMPORT_QUEUE_DB_VERSION', '1.0' );
define( 'NER_MICHOEL_IMPORT_LISTING_BASE', 'https://nermichoel.org/index/shiur' );
define( 'NER_MICHOEL_IMPORT_TOTAL_PAGES', 671 ); // From the archive's own "25 of 16756 results" — re-verify if this changes.
define( 'NER_MICHOEL_IMPORT_CHECK_PAGES', 2 ); // The listing is newest first, so page 1 and 2 cover the latest 50 shiurim for the new-shiur check.

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
	if ( function_exists( 'ner_michoel_speed_defer_if_busy' ) && ner_michoel_speed_defer_if_busy( 'nm_import_discover_page', __( 'Library import: reading the archive', 'ner-michoel-core' ) ) ) {
		return; // The site is busy: back in 5 minutes (site-speed.php).
	}

	$page = (int) get_option( 'nm_import_discovery_page', 1 );

	if ( $page > ner_michoel_import_total_pages() ) {
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
		if ( 1 === $page ) {
			ner_michoel_import_note_total( wp_remote_retrieve_body( $response ) );
		}
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

/**
 * Queues listing items that aren't queued yet. Returns how many were new, so
 * the new-shiur check knows whether there's anything to process.
 */
function ner_michoel_queue_discovered_items( array $items ) {
	global $wpdb;
	$table = ner_michoel_import_queue_table_name();
	$added = 0;

	foreach ( $items as $item ) {
		if ( empty( $item['source_url'] ) ) {
			continue;
		}
		// INSERT IGNORE via the unique source_url key — safe to
		// re-run discovery without duplicating already-queued rows.
		$result = $wpdb->query(
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
		$added += (int) $result;
	}

	return $added;
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
 * The post this queue row already became, if any: one of the same post type
 * and title AND the same kind. The origin site lists the audio and the video
 * of one shiur as two rows with the same title ("Corresponding video"). Before
 * this, whichever came second was skipped as "already exists", so 400+ videos
 * had no audio shiur (Missing Audio) and some audio shiurim no video.
 *
 * - video: a shiur with this Vimeo ID, or with this title and any Vimeo ID.
 * - audio: a shiur with this title that is not a Vimeo video.
 * - pdf:   a written shiur with this title (it is its own post type).
 *
 * Returns an array with the post ID, or an empty array.
 */
function ner_michoel_import_existing_post( $post_type, $title, $media_type, $vimeo_id = '' ) {
	$args = array(
		'post_type'   => $post_type,
		'title'       => $title,
		'post_status' => 'any',
		'numberposts' => 1,
		'fields'      => 'ids',
	);

	if ( 'video' === $media_type ) {
		if ( '' !== (string) $vimeo_id ) {
			$same_video = get_posts(
				array(
					'post_type'   => $post_type,
					'post_status' => 'any',
					'numberposts' => 1,
					'fields'      => 'ids',
					'meta_key'    => '_shiur_vimeo_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'  => (string) $vimeo_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			);
			if ( $same_video ) {
				return $same_video;
			}
		}
		$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			array(
				'key'     => '_shiur_vimeo_id',
				'value'   => '',
				'compare' => '!=',
			),
		);
	} elseif ( 'shiur' === $post_type ) {
		$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'relation' => 'OR',
			array(
				'key'     => '_shiur_vimeo_id',
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => '_shiur_vimeo_id',
				'value'   => '',
				'compare' => '=',
			),
		);
	}

	$found = get_posts( $args );
	if ( ! $found && post_type_exists( 'alumni_shiur' ) ) {
		// A shiur moved into the Alumni section (alumni-section.php) still counts: it is never imported again as a public copy.
		$args['post_type']  = 'alumni_shiur';
		$from               = array(
			'key'   => '_nm_alumni_from',
			'value' => $post_type,
		);
		$args['meta_query'] = isset( $args['meta_query'] ) ? array( 'relation' => 'AND', $from, $args['meta_query'] ) : array( $from ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		$found              = get_posts( $args );
		if ( ! $found && 'video' === $media_type && '' !== (string) $vimeo_id ) {
			$found = get_posts(
				array(
					'post_type'   => 'alumni_shiur',
					'post_status' => 'any',
					'numberposts' => 1,
					'fields'      => 'ids',
					'meta_key'    => '_shiur_vimeo_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'  => (string) $vimeo_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			);
		}
	}
	return $found;
}

/**
 * Rows skipped as "already exists" only because the existing post was the
 * other kind (an audio row whose title belonged to a video shiur, or the other
 * way round) go back in the queue, once, so the processor makes the missing
 * audio / video shiur. Same title, speaker and series as the origin site, so
 * the Listen / Watch switch (shiur-formats.php) joins the two by itself.
 * A skip against a post of the same kind is left alone.
 */
function ner_michoel_requeue_other_kind_rows() {
	if ( get_option( 'nm_import_kind_requeue_v1' ) ) {
		return;
	}
	if ( get_option( 'nm_import_queue_db_version' ) !== NER_MICHOEL_IMPORT_QUEUE_DB_VERSION ) {
		return; // No queue table yet: nothing was ever skipped.
	}

	global $wpdb;
	$table   = ner_michoel_import_queue_table_name();
	$skipped = 'A post with this title already exists';

	// Audio rows whose "existing" post is a Vimeo video, and video rows whose
	// "existing" post is not one. Plain subqueries, no UPDATE ... JOIN.
	$vimeo_posts = "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_shiur_vimeo_id' AND meta_value <> ''";
	$set         = "SET status = 'discovered', attempts = 0, error_message = NULL, processed_at = NULL, created_post_id = NULL";

	$audio = (int) $wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->prepare( "UPDATE {$table} {$set} WHERE status = 'skipped' AND media_type = 'audio' AND error_message = %s AND created_post_id IN ({$vimeo_posts})", $skipped )
	);
	$video = (int) $wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->prepare( "UPDATE {$table} {$set} WHERE status = 'skipped' AND media_type = 'video' AND error_message = %s AND created_post_id IS NOT NULL AND created_post_id NOT IN ({$vimeo_posts})", $skipped )
	);

	update_option( 'nm_import_kind_requeue_v1', array( 'audio' => $audio, 'video' => $video, 'time' => time() ), false );

	// Start the processor now if the importer is on; otherwise Start / Resume does.
	if ( ( $audio || $video ) && ner_michoel_import_is_running() && ! wp_next_scheduled( 'nm_import_process_batch' ) ) {
		wp_schedule_single_event( time() + 5, 'nm_import_process_batch' );
	}
}
add_action( 'admin_init', 'ner_michoel_requeue_other_kind_rows', 21 );

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
	 * Written (PDF) shiurim are their own post type (written-shiurim.php),
	 * not `shiur` — a PDF forced in as `_shiur_audio_id` would be silently
	 * misread as an audio shiur. Everything below works off $post_type.
	 */
	$post_type = 'pdf' === $media_type ? 'written_shiur' : 'shiur';

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

	// Idempotency across retries/reprocessing: a post of the same kind with
	// this title (ner_michoel_import_existing_post()). The audio and the video
	// of one shiur share a title on the origin site, so the kind matters.
	$existing = ner_michoel_import_existing_post( $post_type, $row->title, $media_type, $vimeo_id );
	if ( $existing ) {
		ner_michoel_mark_queue_row( $row->id, 'skipped', 'A post with this title already exists', $existing[0] );
		return;
	}

	$post_id = wp_insert_post(
		array(
			'post_type'   => $post_type,
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
	// The series as the tree is today: a folder that was moved is found by its last part, and a video
	// goes with its audio (ner_michoel_import_series_for_row()).
	if ( $row->series || 'video' === $media_type ) {
		$series_id = ner_michoel_import_series_for_row( $row );
		if ( $series_id ) {
			wp_set_object_terms( $post_id, array( $series_id ), 'series' );
		}
	}
	// Topics from the old site's categories in the series name (topics.php).
	// A video filed only under "Shiurim Videos" takes them from the series it was put in.
	$topic_path = ( ! empty( $series_id ) && ner_michoel_import_is_video_bucket( $row->series ) && function_exists( 'ner_michoel_series_label' ) ) ? html_entity_decode( ner_michoel_series_label( (int) $series_id ), ENT_QUOTES, 'UTF-8' ) : $row->series;
	if ( $topic_path && function_exists( 'ner_michoel_add_topics_from_series' ) ) {
		ner_michoel_add_topics_from_series( $post_id, $topic_path );
	}

	if ( 'pdf' === $media_type ) {
		$attachment_id = ner_michoel_import_sideload_pdf( $media_url, $post_id, $row->title );
		if ( $attachment_id ) {
			update_post_meta( $post_id, '_written_pdf_id', $attachment_id );
			ner_michoel_mark_queue_row( $row->id, 'imported', '', $post_id );
		} else {
			// Same as the audio case below: the post exists, the file didn't
			// come through. Visible in Written Shiurim with no PDF, not lost.
			ner_michoel_mark_queue_row( $row->id, 'imported', 'Post created but PDF download failed', $post_id );
		}
		return;
	}

	if ( 'video' === $media_type ) {
		/*
		 * No file to sideload — Vimeo-hosted, not self-hosted. Stored
		 * as its own meta rather than forced into `_shiur_audio_id`,
		 * with ner_michoel_get_shiur_media_type() (shiur-meta.php)
		 * extended to recognize it as a distinct 'video-embed' type.
		 * single-shiur.php renders it as a Vimeo <iframe>.
		 */
		update_post_meta( $post_id, '_shiur_vimeo_id', sanitize_text_field( $vimeo_id ) );
		ner_michoel_mark_queue_row( $row->id, 'imported', '', $post_id );
		return;
	}

	$attachment_id = function_exists( 'ner_michoel_sample_content_sideload_audio' )
		? ner_michoel_sample_content_sideload_audio( $media_url, $post_id, $row->title )
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

/**
 * Downloads a written shiur's PDF into the media library, attached to
 * its post. Named after the title and always .pdf: the origin server's
 * filename isn't reliable, and a source URL with no extension would
 * otherwise fail WordPress's file-type check. Returns the attachment ID,
 * or 0 on failure. The download itself is the same as the audio path.
 */
function ner_michoel_import_sideload_pdf( $url, $post_id, $title ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$tmp_file = download_url( $url );
	if ( is_wp_error( $tmp_file ) ) {
		return 0;
	}

	$slug = sanitize_title( $title );
	if ( '' === $slug ) {
		$slug = 'written-shiur-' . $post_id;
	}

	// The Media Library entry takes the shiur's own title (the file name is only a slug of it).
	$attachment_id = media_handle_sideload(
		array(
			'name'     => $slug . '.pdf',
			'tmp_name' => $tmp_file,
		),
		$post_id,
		'' !== trim( (string) $title ) ? $title : null
	);

	if ( is_wp_error( $attachment_id ) ) {
		if ( file_exists( $tmp_file ) ) {
			wp_delete_file( $tmp_file );
		}
		return 0;
	}

	return $attachment_id;
}

/**
 * Rows for PDF shiurim were parked as 'skipped' while written shiurim had
 * no post type. Put those back in the queue, once, so the processor picks
 * them up. Only touches rows parked for that reason, never other skips
 * (e.g. a title that already existed).
 */
function ner_michoel_requeue_skipped_pdf_rows() {
	if ( get_option( 'nm_import_pdf_requeue_v1' ) ) {
		return;
	}

	global $wpdb;
	$table = ner_michoel_import_queue_table_name();
	$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->prepare(
			"UPDATE {$table} SET status = 'discovered', attempts = 0, error_message = NULL, processed_at = NULL WHERE media_type = %s AND status = 'skipped' AND error_message LIKE %s",
			'pdf',
			$wpdb->esc_like( 'Written/PDF shiur' ) . '%'
		)
	);

	update_option( 'nm_import_pdf_requeue_v1', 1 );
}
add_action( 'admin_init', 'ner_michoel_requeue_skipped_pdf_rows', 20 );

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
 * How long one cron run keeps working before handing over to the next. Kept
 * under the usual 30-second PHP limit on shared hosting. Filterable with
 * nm_import_tick_seconds.
 */
function ner_michoel_import_tick_seconds() {
	return max( 5, (int) apply_filters( 'nm_import_tick_seconds', 25 ) );
}

/**
 * Queue rows still to process. Rows that have failed three times are left out,
 * so they can't hold the importer up.
 */
function ner_michoel_import_discovered_remaining() {
	global $wpdb;
	$table = ner_michoel_import_queue_table_name();
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'discovered' AND attempts < 3" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}

/**
 * One cron run: works through the queue until the time budget is used up, then
 * schedules the next run. Non-stop while there's work: each run hands over to
 * the next straight away, after the short request delay. Once the queue is
 * empty and the listing is done, it hands over to the summary batch instead.
 */
function ner_michoel_run_processing_tick() {
	if ( ! ner_michoel_import_is_running() ) {
		return;
	}
	if ( function_exists( 'ner_michoel_speed_defer_if_busy' ) && ner_michoel_speed_defer_if_busy( 'nm_import_process_batch', __( 'Library import: adding shiurim', 'ner-michoel-core' ) ) ) {
		return; // The site is busy: back in 5 minutes (site-speed.php).
	}

	global $wpdb;
	$table      = ner_michoel_import_queue_table_name();
	$batch_size = max( 1, (int) get_option( 'nm_import_batch_size', 3 ) );
	$deadline   = time() + ner_michoel_import_tick_seconds();
	$worked     = false;

	while ( time() < $deadline && ner_michoel_import_is_running() ) {
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = 'discovered' AND attempts < 3 ORDER BY id ASC LIMIT %d", $batch_size ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $rows ) {
			break;
		}

		foreach ( $rows as $row ) {
			// A beat between items, not just between runs: each one can mean
			// downloading a whole media file from someone else's server.
			if ( $worked ) {
				sleep( 1 );
			}
			$worked = true;

			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET attempts = attempts + 1 WHERE id = %d", $row->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ner_michoel_process_queue_row( $row );

			if ( time() >= $deadline || ! ner_michoel_import_is_running() ) {
				break 2;
			}
		}
	}

	if ( ! ner_michoel_import_is_running() ) {
		return;
	}

	$delay = max( 1, (int) get_option( 'nm_import_request_delay', 5 ) );

	if ( ner_michoel_import_discovered_remaining() > 0 ) {
		if ( ! wp_next_scheduled( 'nm_import_process_batch' ) ) {
			wp_schedule_single_event( time() + $delay, 'nm_import_process_batch' );
		}
	} elseif ( ! get_option( 'nm_import_discovery_done' ) ) {
		// Queue empty but the listing is still being crawled: check back for rows
		// it adds, once a minute.
		if ( ! wp_next_scheduled( 'nm_import_process_batch' ) ) {
			wp_schedule_single_event( time() + 60, 'nm_import_process_batch' );
		}
	} else {
		// New files are all in. The summary batch runs next, and the processing
		// tick schedules it again after each drain.
		ner_michoel_schedule_summary_batch( 1 );
	}
}
add_action( 'nm_import_process_batch', 'ner_michoel_run_processing_tick' );

/**
 * ============================================================
 * ONGOING: check for new shiurim on every cron run, then the summary batch.
 * ============================================================
 */

function ner_michoel_import_cron_schedules( $schedules ) {
	$schedules['nm_every_15_minutes'] = array(
		'interval' => 15 * MINUTE_IN_SECONDS,
		'display'  => __( 'Every 15 minutes', 'ner-michoel-core' ),
	);
	return $schedules;
}
add_filter( 'cron_schedules', 'ner_michoel_import_cron_schedules' );

/**
 * Keeps the recurring new-shiur check scheduled. Runs on every load, which is
 * cheap, so existing installs get it without reactivating.
 */
function ner_michoel_import_ensure_cron() {
	if ( ! wp_next_scheduled( 'nm_import_check_new' ) ) {
		wp_schedule_event( time() + 60, 'nm_every_15_minutes', 'nm_import_check_new' );
	}
}
add_action( 'init', 'ner_michoel_import_ensure_cron' );

function ner_michoel_schedule_summary_batch( $delay = 5 ) {
	if ( ! wp_next_scheduled( 'nm_import_summary_batch' ) ) {
		wp_schedule_single_event( time() + max( 1, (int) $delay ), 'nm_import_summary_batch' );
	}
}

/**
 * Every 15 minutes: checks the newest listing pages for shiurim not yet queued,
 * and queues them. Also restarts the importer if its chain stopped (a PHP limit
 * or a host restart can end a run), and the summary batch if it has work left.
 * Does nothing until the first full crawl is done, because the crawl already
 * covers everything then.
 */
function ner_michoel_run_new_check_tick() {
	if ( ! ner_michoel_import_is_running() || ! get_option( 'nm_import_discovery_done' ) ) {
		return;
	}

	$added = 0;
	$error = '';

	// Newest first. It reads on while a page still has shiurim that are not in the queue, so a run of
	// more than 50 new ones (the importer paused for a while, a week of uploads at once) is not left
	// with a gap. A long run carries on from its page on the next check.
	$page     = max( 1, (int) get_option( 'nm_import_check_page', 1 ) );
	$deadline = time() + ner_michoel_import_tick_seconds();
	$finished = false;
	while ( time() < $deadline ) {
		$response = wp_remote_get(
			ner_michoel_import_listing_url( $page ),
			array(
				'timeout'    => 30,
				'user-agent' => 'NerMichoelSiteMigration/1.0 (+https://nermichoel.org)',
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			$error = sprintf( 'Page %d: %s', $page, is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_response_code( $response ) );
			break;
		}

		$body = wp_remote_retrieve_body( $response );
		if ( 1 === $page ) {
			ner_michoel_import_note_total( $body );
		}
		$new    = ner_michoel_queue_discovered_items( ner_michoel_extract_listing_items( $body ) );
		$added += $new;
		if ( ( $page >= NER_MICHOEL_IMPORT_CHECK_PAGES && 0 === $new ) || $page >= ner_michoel_import_total_pages() ) {
			$finished = true;
			break;
		}
		++$page;
		sleep( 1 );
	}
	update_option( 'nm_import_check_page', $finished || $error ? 1 : $page, false );

	update_option( 'nm_import_last_check', time() );
	update_option( 'nm_import_last_check_added', $added );
	update_option( 'nm_import_last_check_error', $error );

	if ( ner_michoel_import_discovered_remaining() > 0 ) {
		if ( ! wp_next_scheduled( 'nm_import_process_batch' ) ) {
			wp_schedule_single_event( time() + 1, 'nm_import_process_batch' );
		}
	} elseif ( ner_michoel_summary_batch_pending_count() > 0 ) {
		ner_michoel_schedule_summary_batch( 1 );
	}
}
add_action( 'nm_import_check_new', 'ner_michoel_run_new_check_tick' );

/**
 * The summary batch: only once the queue is drained (new files are imported
 * first). Works until its time budget is used up, then comes back.
 */
function ner_michoel_run_summary_batch_tick() {
	if ( ! ner_michoel_import_is_running() || ! get_option( 'nm_import_discovery_done' ) ) {
		return;
	}
	if ( ner_michoel_import_discovered_remaining() > 0 ) {
		return;
	}
	if ( function_exists( 'ner_michoel_speed_defer_if_busy' ) && ner_michoel_speed_defer_if_busy( 'nm_import_summary_batch', __( 'Written summaries from PDFs', 'ner-michoel-core' ) ) ) {
		return; // The site is busy: back in 5 minutes (site-speed.php).
	}

	$pending = ner_michoel_summary_batch_run( time() + ner_michoel_import_tick_seconds() );

	if ( $pending > 0 && ner_michoel_import_is_running() ) {
		ner_michoel_schedule_summary_batch( 5 );
	}
}
add_action( 'nm_import_summary_batch', 'ner_michoel_run_summary_batch_tick' );

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

	wp_safe_redirect( ner_michoel_panel_redirect_url( admin_url( 'admin.php?page=nm-library-import' ), 'library' ) );
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
					'enum'     => array( 'start', 'pause', 'reset', 'status', 'find_missing' ),
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'ner_michoel_register_import_control_route' );

function ner_michoel_handle_import_control_rest( WP_REST_Request $request ) {
	$action = $request->get_param( 'action' );

	if ( 'find_missing' === $action ) {
		ner_michoel_import_find_missing();
	} elseif ( 'status' !== $action ) {
		ner_michoel_apply_import_action( $action );
	}

	return new WP_REST_Response(
		array(
			'running'            => ner_michoel_import_is_running(),
			'discovery_page'     => (int) get_option( 'nm_import_discovery_page', 1 ),
			'discovery_done'     => (bool) get_option( 'nm_import_discovery_done' ),
			'total_pages'        => ner_michoel_import_total_pages(),
			'find_missing'       => get_option( 'nm_import_find_missing', null ),
			'counts'             => ner_michoel_import_counts(),
			'last_discovery_error' => get_option( 'nm_import_last_discovery_error', '' ),
			'summary_pending'    => ner_michoel_summary_batch_pending_count(),
			'last_check'         => (int) get_option( 'nm_import_last_check', 0 ),
			'last_check_added'   => (int) get_option( 'nm_import_last_check_added', 0 ),
			'last_check_error'   => get_option( 'nm_import_last_check_error', '' ),
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
	$last_check = (int) get_option( 'nm_import_last_check', 0 );
	// From the Site Control Panel, the buttons come back to the panel.
	$toggle_url = function ( $action ) {
		$args = array( 'action' => 'nm_import_toggle', 'nm_action' => $action );
		if ( ner_michoel_is_custom_admin_render() ) {
			$args['nm_from_panel'] = 1;
		}
		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin.php' ) ), 'nm_import_toggle' );
	};
	?>
	<div class="wrap nm-dashboard">
		<h1><?php esc_html_e( 'Full Library Import', 'ner-michoel-core' ); ?></h1>

		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'Verify on a small run first.', 'ner-michoel-core' ); ?></strong>
				<?php esc_html_e( 'The parser is implemented against a real sample page, but has not run against the live site\'s full crawl yet. Start it, let it discover a page or two, then check the Shiurim list and a few actual posts before leaving it running unattended for the full 671 pages. Written (PDF) shiurim are imported as Written Shiurim, with their PDF attached.', 'ner-michoel-core' ); ?>
			</p>
		</div>

		<p><?php esc_html_e( 'Imports the entire nermichoel.org shiur archive in the background — this runs over hours to days, not instantly, and is safe to pause/resume. Once the archive is in, it checks for new shiurim every 15 minutes and imports them, then fills in the Summary of written shiurim from their PDFs.', 'ner-michoel-core' ); ?></p>

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
							ner_michoel_import_total_pages()
						);
					}
					?>
				</td></tr>
				<tr><th><?php esc_html_e( 'Discovered (queued)', 'ner-michoel-core' ); ?></th><td><?php echo esc_html( number_format_i18n( $counts['discovered'] ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Imported', 'ner-michoel-core' ); ?></th><td><?php echo esc_html( number_format_i18n( $counts['imported'] ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Skipped (already existed)', 'ner-michoel-core' ); ?></th><td><?php echo esc_html( number_format_i18n( $counts['skipped'] ) ); ?></td></tr>
				<?php $kind_requeue = get_option( 'nm_import_kind_requeue_v1' ); ?>
				<?php if ( is_array( $kind_requeue ) && ( $kind_requeue['audio'] || $kind_requeue['video'] ) ) : ?>
					<tr><th><?php esc_html_e( 'Audio / video put back in the queue', 'ner-michoel-core' ); ?></th><td><?php echo esc_html( sprintf( /* translators: 1: audio rows, 2: video rows, 3: date */ __( '%1$d audio and %2$d video files that were skipped because the other kind had the same title (%3$s). They are imported as their own shiurim, and the shiur page offers Listen / Watch.', 'ner-michoel-core' ), (int) $kind_requeue['audio'], (int) $kind_requeue['video'], wp_date( 'j M Y', (int) $kind_requeue['time'] ) ) ); ?></td></tr>
				<?php endif; ?>
				<tr><th><?php esc_html_e( 'Failed', 'ner-michoel-core' ); ?></th><td><?php echo esc_html( number_format_i18n( $counts['failed'] ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Written summaries waiting', 'ner-michoel-core' ); ?></th><td><?php echo esc_html( number_format_i18n( ner_michoel_summary_batch_pending_count() ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Last new-shiur check', 'ner-michoel-core' ); ?></th><td><?php echo $last_check ? esc_html( wp_date( 'j M Y, H:i', $last_check ) ) . ' — ' . esc_html( sprintf( /* translators: %d: number of new shiurim found */ __( '%d new', 'ner-michoel-core' ), (int) get_option( 'nm_import_last_check_added', 0 ) ) ) : esc_html__( 'Not yet', 'ner-michoel-core' ); ?></td></tr>
			</tbody>
		</table>

		<?php if ( $last_error ) : ?>
			<p class="description"><?php esc_html_e( 'Last discovery error:', 'ner-michoel-core' ); ?> <?php echo esc_html( $last_error ); ?></p>
		<?php endif; ?>

		<p>
			<?php if ( $running ) : ?>
				<a class="button" href="<?php echo esc_url( $toggle_url( 'pause' ) ); ?>"><?php esc_html_e( 'Pause', 'ner-michoel-core' ); ?></a>
			<?php else : ?>
				<a class="button button-primary" href="<?php echo esc_url( $toggle_url( 'start' ) ); ?>"><?php esc_html_e( 'Start / Resume', 'ner-michoel-core' ); ?></a>
			<?php endif; ?>
			<a class="button button-link-delete" href="<?php echo esc_url( $toggle_url( 'reset' ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Clear the entire import queue and start over? Already-imported shiurim are not affected.', 'ner-michoel-core' ) ); ?>');"><?php esc_html_e( 'Reset Queue', 'ner-michoel-core' ); ?></a>
		</p>

		<p class="description"><?php esc_html_e( 'Relies on WP-Cron, which fires on site visits — a real system cron hitting wp-cron.php periodically keeps this moving even with low admin traffic.', 'ner-michoel-core' ); ?></p>

		<?php
		// Find missing shiurim, and videos with their audio (below).
		ner_michoel_render_import_tools_card();

		// Clean titles for the files already in the library (includes/tidy-titles.php).
		if ( function_exists( 'ner_michoel_render_tidy_titles_card' ) ) {
			ner_michoel_render_tidy_titles_card();
		}
		?>
	</div>
	<?php
}

/**
 * ============================================================
 * 2026-11: the import against today's series tree, missing shiurim,
 * and videos with their audio.
 * ============================================================
 */

/**
 * How many listing pages the old site has now. The constant was the count
 * when the importer was written (16,756 shiurim = 671 pages). The site grows
 * every week, and new shiurim push the oldest ones onto later pages, so the
 * crawl reads the real count from the page ("25 of 16885 results").
 */
function ner_michoel_import_total_pages() {
	return max( (int) NER_MICHOEL_IMPORT_TOTAL_PAGES, (int) get_option( 'nm_import_total_pages', 0 ) );
}

/** Notes the page count a listing page gives, if it gives one. */
function ner_michoel_import_note_total( $html ) {
	if ( preg_match( '/\bof\s+([\d,]+)\s+results/i', (string) $html, $m ) ) {
		$total = (int) str_replace( ',', '', $m[1] );
		if ( $total > 0 ) {
			update_option( 'nm_import_total_pages', (int) ceil( $total / 25 ), false );
		}
	}
}

/**
 * A series name that is only a video bucket on the old site ("Shiurim Videos",
 * "Videos", "Rabbi Twersky Videos"): the old site filed every video there,
 * not in the series its audio is in.
 */
function ner_michoel_import_is_video_bucket( $path ) {
	$parts = function_exists( 'ner_michoel_series_split_name' ) ? ner_michoel_series_split_name( $path ) : array( trim( (string) $path ) );
	return 1 === count( $parts ) && (bool) preg_match( '/\bvideos?$/i', trim( (string) $parts[0] ) );
}

/**
 * A folder name that is the same in many places ("Perek 2", "Part 3", "Daf 4"):
 * never matched by its name alone, only by its place in the tree.
 */
function ner_michoel_import_is_generic_series_name( $name ) {
	return (bool) preg_match( '/^(perek|chelek|part|siman|daf|shiur|vol(ume)?|#)\s*[\w\']*\s*\d|^\d+$/i', trim( (string) $name ) );
}

/**
 * The series a path from the old site belongs in today. In order:
 *
 * 1. The whole path, if it is in the tree ("Gemara / Bava Kamma / Perek 1").
 * 2. The path's last part ("Shiur Klali"), if the folders above it were moved
 *    or merged on the Series screens: a series with that name whose folders
 *    above it all appear, in order, in the path. "Gemara / Chullin / Perek 1 /
 *    Shiur Klali" goes into a top-level "Shiur Klali", or into "Gemara /
 *    Shiur Klali", but never into "Halacha / Shiur Klali". With several, the
 *    one with the most of the path above it wins, then the one with the most
 *    shiurim.
 * 3. The only series anywhere with that last name, unless the name is one
 *    that repeats in many places ("Perek 2").
 * 4. Otherwise the path is made, as before.
 *
 * @return int Term ID, or 0.
 */
function ner_michoel_import_series_for_path( $path ) {
	$path = trim( (string) $path );
	if ( '' === $path || ! function_exists( 'ner_michoel_series_find_or_create_path' ) ) {
		return ( '' !== $path && function_exists( 'ner_michoel_sample_content_get_or_create_term' ) ) ? (int) ner_michoel_sample_content_get_or_create_term( $path, 'series' ) : 0;
	}

	$found = ner_michoel_series_find_or_create_path( $path, 0, false );
	if ( is_array( $found ) ) {
		return (int) $found['term_id'];
	}

	$parts = ner_michoel_series_split_name( $path );
	if ( count( $parts ) > 1 ) {
		$picked = ner_michoel_import_series_by_last_part( $parts );
		if ( $picked ) {
			return $picked;
		}
	}

	$made = ner_michoel_series_find_or_create_path( $path, 0, true );
	return is_wp_error( $made ) ? 0 : (int) $made['term_id'];
}

/**
 * Step 2 and 3 above. $parts is the path, split.
 *
 * @param string[] $parts
 * @return int Term ID, or 0.
 */
function ner_michoel_import_series_by_last_part( array $parts ) {
	$last    = ner_michoel_series_key( end( $parts ) );
	$above   = array_map( 'ner_michoel_series_key', array_slice( $parts, 0, -1 ) );
	$labels  = ner_michoel_series_label_map();
	$counts  = array();
	$same    = array();
	foreach ( ner_michoel_series_all_terms() as $term ) {
		if ( ner_michoel_series_key( $term->name ) === $last ) {
			$same[]                          = (int) $term->term_id;
			$counts[ (int) $term->term_id ] = (int) $term->count;
		}
	}
	if ( ! $same ) {
		return 0;
	}

	$best       = 0;
	$best_depth = -1;
	foreach ( $same as $id ) {
		$chain = isset( $labels[ $id ] ) ? array_map( 'ner_michoel_series_key', array_slice( ner_michoel_series_split_name( $labels[ $id ] ), 0, -1 ) ) : array();
		// Its folders above, in order, inside the path's folders above.
		$at = 0;
		$ok = true;
		foreach ( $chain as $name ) {
			$hit = array_search( $name, array_slice( $above, $at ), true );
			if ( false === $hit ) {
				$ok = false;
				break;
			}
			$at += (int) $hit + 1;
		}
		if ( ! $ok ) {
			continue;
		}
		$depth = count( $chain );
		if ( $depth > $best_depth || ( $depth === $best_depth && $counts[ $id ] > $counts[ $best ] ) ) {
			$best       = $id;
			$best_depth = $depth;
		}
	}
	if ( $best ) {
		return $best;
	}

	if ( 1 === count( $same ) && ! ner_michoel_import_is_generic_series_name( end( $parts ) ) ) {
		return $same[0];
	}
	return 0;
}

/**
 * The series of the audio shiur that a video is the video of: the same title
 * (case and punctuation ignored) and the same speaker, the rule the Listen /
 * Watch switch uses (shiur-formats.php). An audio shiur already on the site
 * comes first, then an audio row of the import queue. 0 when there is none,
 * or when the audio shiurim with that title are in more than one series.
 */
function ner_michoel_import_audio_twin_series( $title, $speaker_name ) {
	global $wpdb;
	if ( ! function_exists( 'ner_michoel_shiur_format_key' ) ) {
		return 0;
	}
	$speaker = $speaker_name ? get_term_by( 'name', $speaker_name, 'speaker' ) : false;
	$speaker = $speaker ? (int) $speaker->term_id : 0;
	$key     = ner_michoel_shiur_format_key( $title, $speaker );
	if ( '' === $key || ! $speaker ) {
		return 0;
	}

	// Titles that start the same way, then the exact key.
	$start = $wpdb->esc_like( mb_substr( trim( (string) $title ), 0, 12 ) ) . '%';
	$ids   = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = '_shiur_vimeo_id'
			WHERE p.post_type = 'shiur' AND p.post_status = 'publish' AND p.post_title LIKE %s AND ( v.meta_value IS NULL OR v.meta_value = '' )",
			$start
		)
	);
	$series = array();
	foreach ( $ids as $id ) {
		$speakers = wp_get_object_terms( (int) $id, 'speaker', array( 'fields' => 'ids' ) );
		$sid      = ( ! is_wp_error( $speakers ) && $speakers ) ? (int) $speakers[0] : 0;
		if ( ner_michoel_shiur_format_key( get_post_field( 'post_title', (int) $id ), $sid ) !== $key ) {
			continue;
		}
		foreach ( (array) wp_get_object_terms( (int) $id, 'series', array( 'fields' => 'ids' ) ) as $term_id ) {
			$series[ (int) $term_id ] = true;
		}
	}
	if ( 1 === count( $series ) ) {
		return (int) key( $series );
	}
	if ( $series ) {
		return 0;
	}

	// Not on the site yet: the audio row in the queue, by the same title and speaker.
	$table = ner_michoel_import_queue_table_name();
	$path  = $wpdb->get_var( $wpdb->prepare( "SELECT series FROM {$table} WHERE media_type = 'audio' AND title = %s AND speaker = %s AND series <> '' ORDER BY id DESC LIMIT 1", $title, $speaker_name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return $path && ! ner_michoel_import_is_video_bucket( $path ) ? ner_michoel_import_series_for_path( $path ) : 0;
}

/**
 * The series a queue row's shiur goes in: a video goes with its audio when
 * the old site filed it only under a video bucket; everything else by its
 * path (ner_michoel_import_series_for_path()).
 */
function ner_michoel_import_series_for_row( $row ) {
	if ( 'video' === $row->media_type && ( '' === (string) $row->series || ner_michoel_import_is_video_bucket( $row->series ) ) ) {
		$twin = ner_michoel_import_audio_twin_series( $row->title, $row->speaker );
		if ( $twin ) {
			return $twin;
		}
	}
	return '' === (string) $row->series ? 0 : ner_michoel_import_series_for_path( $row->series );
}

/**
 * "Find missing shiurim": reads the whole old listing again (only shiurim not
 * yet in the queue are added) and puts back in the queue:
 * - rows that failed three times (a download that timed out, the old site busy);
 * - rows skipped as "already exists" when what exists is the other kind (an
 *   audio row skipped because of a video with its title, or the reverse).
 * Rows whose shiur was imported are left alone, so a shiur someone deleted on
 * purpose is not brought back.
 *
 * @return array Counts.
 */
function ner_michoel_import_find_missing() {
	global $wpdb;
	$table   = ner_michoel_import_queue_table_name();
	$set     = "SET status = 'discovered', attempts = 0, error_message = NULL, processed_at = NULL";
	$vimeo   = "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_shiur_vimeo_id' AND meta_value <> ''";
	$skipped = 'A post with this title already exists';

	$failed = (int) $wpdb->query( "UPDATE {$table} {$set} WHERE status = 'failed' OR ( status = 'discovered' AND attempts >= 3 )" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$kind   = (int) $wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->prepare( "UPDATE {$table} {$set}, created_post_id = NULL WHERE status = 'skipped' AND error_message = %s AND ( ( media_type = 'audio' AND created_post_id IN ({$vimeo}) ) OR ( media_type = 'video' AND created_post_id IS NOT NULL AND created_post_id NOT IN ({$vimeo}) ) )", $skipped )
	);

	update_option( 'nm_import_discovery_page', 1 );
	delete_option( 'nm_import_discovery_done' );
	$result = array(
		'failed' => $failed,
		'kind'   => $kind,
		'time'   => time(),
	);
	update_option( 'nm_import_find_missing', $result, false );
	ner_michoel_apply_import_action( 'start' );
	return $result;
}

/**
 * Videos that sit in a video bucket (or in no series) while their audio is in
 * a series: video ID => array( 'to' => series ID, 'from' => series IDs ).
 * $within limits it to audio in that series or a series inside it (0: all).
 *
 * @return array
 */
function ner_michoel_import_video_moves( $within = 0 ) {
	global $wpdb;
	if ( ! function_exists( 'ner_michoel_shiur_format_key' ) ) {
		return array();
	}
	$rows = $wpdb->get_results(
		"SELECT p.ID, p.post_title, v.meta_value AS vimeo FROM {$wpdb->posts} p
		LEFT JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = '_shiur_vimeo_id'
		WHERE p.post_type = 'shiur' AND p.post_status = 'publish'",
		ARRAY_A
	);
	$terms = $wpdb->get_results(
		"SELECT tr.object_id, tt.taxonomy, tt.term_id FROM {$wpdb->term_relationships} tr
		INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
		WHERE tt.taxonomy IN ('speaker', 'series')",
		ARRAY_A
	);
	$speaker = array();
	$series  = array();
	foreach ( (array) $terms as $t ) {
		if ( 'speaker' === $t['taxonomy'] ) {
			if ( ! isset( $speaker[ $t['object_id'] ] ) ) {
				$speaker[ $t['object_id'] ] = (int) $t['term_id'];
			}
		} else {
			$series[ $t['object_id'] ][] = (int) $t['term_id'];
		}
	}

	$labels = function_exists( 'ner_michoel_series_label_map' ) ? ner_michoel_series_label_map() : array();
	$bucket = function ( $ids ) use ( $labels ) {
		foreach ( $ids as $id ) {
			if ( ! isset( $labels[ $id ] ) || ! ner_michoel_import_is_video_bucket( html_entity_decode( $labels[ $id ], ENT_QUOTES, 'UTF-8' ) ) ) {
				return false;
			}
		}
		return true;
	};

	$audio  = array();
	$videos = array();
	foreach ( (array) $rows as $r ) {
		$id  = (int) $r['ID'];
		$key = ner_michoel_shiur_format_key( $r['post_title'], isset( $speaker[ $id ] ) ? $speaker[ $id ] : 0 );
		if ( '' === $key || empty( $speaker[ $id ] ) ) {
			continue;
		}
		$in = isset( $series[ $id ] ) ? $series[ $id ] : array();
		if ( '' !== (string) $r['vimeo'] ) {
			if ( $bucket( $in ) ) {
				$videos[ $id ] = array( $key, $in );
			}
		} elseif ( $in && ! $bucket( $in ) ) {
			foreach ( $in as $sid ) {
				$audio[ $key ][ $sid ] = true;
			}
		}
	}

	$moves = array();
	foreach ( $videos as $id => $video ) {
		list( $key, $from ) = $video;
		if ( empty( $audio[ $key ] ) || 1 !== count( $audio[ $key ] ) ) {
			continue; // No audio, or audio in two series: left where it is.
		}
		$to = (int) key( $audio[ $key ] );
		if ( $within && $to !== (int) $within && ! term_is_ancestor_of( (int) $within, $to, 'series' ) ) {
			continue;
		}
		$moves[ $id ] = array(
			'to'   => $to,
			'from' => $from,
		);
	}
	return $moves;
}

/**
 * Moves the videos (ner_michoel_import_video_moves()) and keeps a note of
 * where each was, for Undo. Returns how many moved.
 */
function ner_michoel_import_move_videos( $within = 0 ) {
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
	$moves = ner_michoel_import_video_moves( $within );
	$log   = array();
	foreach ( $moves as $id => $move ) {
		$done = wp_set_object_terms( (int) $id, array( (int) $move['to'] ), 'series' );
		if ( ! is_wp_error( $done ) ) {
			$log[ (int) $id ] = array_map( 'intval', $move['from'] );
		}
	}
	if ( $log ) {
		update_option( 'nm_import_video_moves_log', array( 'time' => time(), 'moves' => $log ), false );
	}
	return count( $log );
}

/** Puts the last moved videos back in the series they were in. Returns how many. */
function ner_michoel_import_undo_video_moves() {
	$log = get_option( 'nm_import_video_moves_log' );
	if ( ! is_array( $log ) || empty( $log['moves'] ) ) {
		return 0;
	}
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
	$n = 0;
	foreach ( $log['moves'] as $id => $from ) {
		if ( get_post( (int) $id ) ) {
			wp_set_object_terms( (int) $id, array_map( 'intval', (array) $from ), 'series' );
			++$n;
		}
	}
	delete_option( 'nm_import_video_moves_log' );
	return $n;
}

/** The buttons' handler: Find missing, Move videos, Undo. */
function ner_michoel_handle_import_tools() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'ner-michoel-core' ) );
	}
	check_admin_referer( 'nm_import_tools' );
	$tool = isset( $_REQUEST['nm_tool'] ) ? sanitize_key( wp_unslash( $_REQUEST['nm_tool'] ) ) : '';
	$note = '';
	if ( 'find_missing' === $tool ) {
		$r    = ner_michoel_import_find_missing();
		$note = sprintf( /* translators: 1: failed rows, 2: rows of the other kind */ __( 'Looking for missing shiurim: the old site\'s whole list is being read again. %1$d failed files and %2$d skipped audio / video files went back in the queue.', 'ner-michoel-core' ), $r['failed'], $r['kind'] );
	} elseif ( 'move_videos' === $tool ) {
		$within = isset( $_REQUEST['nm_within'] ) ? absint( $_REQUEST['nm_within'] ) : 0;
		$moved  = ner_michoel_import_move_videos( $within );
		/* translators: %d: videos moved */
		$note = sprintf( _n( '%d video was moved into its audio\'s series.', '%d videos were moved into their audio\'s series.', $moved, 'ner-michoel-core' ), $moved );
	} elseif ( 'undo_videos' === $tool ) {
		$note = sprintf( /* translators: %d: videos moved back */ __( '%d videos were put back where they were.', 'ner-michoel-core' ), ner_michoel_import_undo_video_moves() );
	} elseif ( 'combine_av' === $tool && function_exists( 'ner_michoel_av_combine_run' ) ) {
		$note = sprintf( /* translators: %d: shiurim */ __( '%d audio shiurim now have their video too. The video shiurim were set to Draft.', 'ner-michoel-core' ), ner_michoel_av_combine_run() );
	} elseif ( 'undo_av' === $tool && function_exists( 'ner_michoel_av_combine_undo' ) ) {
		$note = sprintf( /* translators: %d: shiurim */ __( '%d audio and video shiurim were put back as two.', 'ner-michoel-core' ), ner_michoel_av_combine_undo() );
	}
	if ( $note ) {
		set_transient( 'nm_import_tools_note_' . get_current_user_id(), $note, 300 );
	}
	wp_safe_redirect( ner_michoel_panel_redirect_url( admin_url( 'admin.php?page=nm-library-import' ), 'library' ) );
	exit;
}
add_action( 'admin_action_nm_import_tools', 'ner_michoel_handle_import_tools' );

/** The card on the Library Import screen for the three tools. */
function ner_michoel_render_import_tools_card() {
	$note = get_transient( 'nm_import_tools_note_' . get_current_user_id() );
	if ( $note ) {
		delete_transient( 'nm_import_tools_note_' . get_current_user_id() );
	}
	$moves   = ner_michoel_import_video_moves();
	$targets = array();
	foreach ( $moves as $move ) {
		$targets[ $move['to'] ] = isset( $targets[ $move['to'] ] ) ? $targets[ $move['to'] ] + 1 : 1;
	}
	// Group by the top folder too, so "Shiur Klali" or "Gemara" can be picked whole.
	$labels = function_exists( 'ner_michoel_series_label_map' ) ? ner_michoel_series_label_map() : array();
	$groups = array();
	foreach ( $targets as $to => $n ) {
		$term = get_term( (int) $to, 'series' );
		$top  = $term && ! is_wp_error( $term ) ? (int) $to : 0;
		while ( $term && ! is_wp_error( $term ) && $term->parent ) {
			$top  = (int) $term->parent;
			$term = get_term( $top, 'series' );
		}
		if ( $top ) {
			$groups[ $top ] = ( isset( $groups[ $top ] ) ? $groups[ $top ] : 0 ) + $n;
		}
		// The folder itself, when it is not a top folder (e.g. a "Shiur Klali" inside Gemara).
		if ( $top !== (int) $to ) {
			$groups[ (int) $to ] = ( isset( $groups[ (int) $to ] ) ? $groups[ (int) $to ] : 0 ) + $n;
		}
	}
	arsort( $groups );
	$log      = get_option( 'nm_import_video_moves_log' );
	$found    = get_option( 'nm_import_find_missing' );
	$form_url = admin_url( 'admin.php' );
	$hidden   = function ( $tool ) {
		echo '<input type="hidden" name="action" value="nm_import_tools" /><input type="hidden" name="nm_tool" value="' . esc_attr( $tool ) . '" />';
		if ( function_exists( 'ner_michoel_is_custom_admin_render' ) && ner_michoel_is_custom_admin_render() ) {
			echo '<input type="hidden" name="nm_from_panel" value="1" />';
		}
		wp_nonce_field( 'nm_import_tools' );
	};
	?>
	<div class="card nm-import-tools" style="max-width:700px;margin-top:24px;">
		<?php if ( $note ) : ?>
			<div class="notice notice-success inline"><p><?php echo esc_html( $note ); ?></p></div>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Find missing shiurim', 'ner-michoel-core' ); ?></h2>
		<p><?php esc_html_e( 'Reads the old site\'s whole list again and brings in every shiur that is not here yet. Files that failed to download, and audio or video files that were skipped because the other kind had the same title, are tried again. Shiurim that were imported and then deleted here are not brought back.', 'ner-michoel-core' ); ?></p>
		<?php if ( is_array( $found ) ) : ?>
			<p class="description"><?php echo esc_html( sprintf( /* translators: 1: date, 2: failed, 3: other kind */ __( 'Last run %1$s: %2$d failed and %3$d skipped files went back in the queue.', 'ner-michoel-core' ), wp_date( 'j M Y, H:i', (int) $found['time'] ), (int) $found['failed'], (int) $found['kind'] ) ); ?></p>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( $form_url ); ?>">
			<?php $hidden( 'find_missing' ); ?>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Find missing shiurim', 'ner-michoel-core' ); ?></button>
		</form>

		<h2 style="margin-top:28px;"><?php esc_html_e( 'Videos with their audio', 'ner-michoel-core' ); ?></h2>
		<p><?php esc_html_e( 'The old site filed every video under "Shiurim Videos", not in the series its audio is in. New videos now go in their audio\'s series by themselves. This moves the videos already here: a video goes into the series of the audio shiur with the same title and speaker. A video with no audio, or whose audio is in two series, stays where it is.', 'ner-michoel-core' ); ?></p>
		<?php if ( $moves ) : ?>
			<form method="post" action="<?php echo esc_url( $form_url ); ?>">
				<?php $hidden( 'move_videos' ); ?>
				<label for="nm-within"><?php esc_html_e( 'Which videos', 'ner-michoel-core' ); ?></label>
				<select id="nm-within" name="nm_within">
					<?php foreach ( $groups as $id => $n ) : ?>
						<option value="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( sprintf( '%s (%d)', html_entity_decode( isset( $labels[ $id ] ) ? $labels[ $id ] : '', ENT_QUOTES, 'UTF-8' ), $n ) ); ?></option>
					<?php endforeach; ?>
					<option value="0"><?php echo esc_html( sprintf( /* translators: %d: videos */ __( 'All videos (%d)', 'ner-michoel-core' ), count( $moves ) ) ); ?></option>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Move them', 'ner-michoel-core' ); ?></button>
			</form>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'No video is waiting to be moved.', 'ner-michoel-core' ); ?></p>
		<?php endif; ?>
		<?php if ( is_array( $log ) && ! empty( $log['moves'] ) ) : ?>
			<form method="post" action="<?php echo esc_url( $form_url ); ?>" style="margin-top:10px;">
				<?php $hidden( 'undo_videos' ); ?>
				<span class="description"><?php echo esc_html( sprintf( /* translators: 1: videos, 2: date */ __( '%1$d videos were moved on %2$s.', 'ner-michoel-core' ), count( $log['moves'] ), wp_date( 'j M Y, H:i', (int) $log['time'] ) ) ); ?></span>
				<button type="submit" class="button button-link"><?php esc_html_e( 'Undo', 'ner-michoel-core' ); ?></button>
			</form>
		<?php endif; ?>

		<?php
		if ( function_exists( 'ner_michoel_render_av_combine_section' ) ) {
			ner_michoel_render_av_combine_section( $form_url, $hidden );
		}
		?>
	</div>
	<?php
}
