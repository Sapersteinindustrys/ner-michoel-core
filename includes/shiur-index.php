<?php
/**
 * Search index for the Shiurim search page: one compact list of every published
 * shiur and written shiur, served as JSON from ner-michoel/v1/shiur-index. The
 * page loads it once, then filters in the browser as the reader types, so a
 * search doesn't need a server query on every keystroke. With 16,000+ shiurim,
 * a server query per keystroke was the slow part.
 *
 * Built in a few bulk queries and saved gzipped in a non-autoloaded option (not
 * a transient: with a persistent object cache a transient lives only in the
 * cache, and one over the cache's item limit would be lost on every save). The
 * build time is the version: the page asks for ?v=<version>, which browsers may
 * keep for a year, because a rebuild changes the URL.
 *
 * A change (a shiur or written shiur published, edited or removed, its media or
 * duration changed, a speaker or series edited) schedules a rebuild a minute
 * later, or 15 minutes later while the importer is working through its queue.
 * Until then the previous index is served, which is fine for a search.
 *
 * Keys are short to keep the JSON small:
 *   i  post ID              m  title
 *   a  speaker term ID      s  series term ID (0 if none)
 *   t  the post's date and time read as UTC (show it with timeZone 'UTC'
 *      and it's the date get_the_date() shows)
 *   c  kind: a audio, v video, w written (PDF)
 *   u  relative link        d  duration, when known
 * The speaker and series names are in `speakers` and `series`, keyed by term ID.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NER_MICHOEL_SHIUR_INDEX_OPTION', 'nm_shiur_index_data' );
define( 'NER_MICHOEL_SHIUR_INDEX_VERSION_OPTION', 'nm_shiur_index_version' );

// Meta keys whose change alters what the index holds (media kind, duration).
define(
	'NER_MICHOEL_SHIUR_INDEX_META_KEYS',
	array( '_shiur_audio_id', '_shiur_vimeo_id', '_written_pdf_id', '_shiur_duration' )
);

/**
 * Builds the index JSON. Rows are read as plain arrays (much lighter than
 * objects at this size), and the speaker, series and media of every post come
 * from one query each, not one per shiur.
 */
function ner_michoel_shiur_index_build( $version ) {
	global $wpdb;

	wp_raise_memory_limit( 'ner_michoel_shiur_index' );

	$posts = $wpdb->get_results( "SELECT ID, post_title, post_name, post_date, post_type, post_parent FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('shiur','written_shiur') ORDER BY post_date DESC, ID DESC", ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

	// Speaker and series: the first of each by name, which is the order
	// get_the_terms() gives everywhere else on the site.
	$first = array();
	$names = array(
		'speaker' => array(),
		'series'  => array(),
	);
	$rows  = $wpdb->get_results( "SELECT tr.object_id, tt.taxonomy, t.term_id, t.name FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE tt.taxonomy IN ('speaker','series') ORDER BY t.name ASC, t.term_id ASC", ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	foreach ( (array) $rows as $row ) {
		$object_id = (int) $row[0];
		$taxonomy  = $row[1];
		$term_id   = (int) $row[2];
		if ( ! isset( $first[ $object_id ][ $taxonomy ] ) ) {
			$first[ $object_id ][ $taxonomy ] = $term_id;
		}
		$names[ $taxonomy ][ $term_id ] = $row[3];
	}
	unset( $rows );

	$meta_keys    = NER_MICHOEL_SHIUR_INDEX_META_KEYS;
	$placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );
	$meta         = array();
	$rows         = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ({$placeholders})", $meta_keys ), ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the placeholders are generated from a fixed list.
	foreach ( (array) $rows as $row ) {
		$meta[ (int) $row[0] ][ $row[1] ] = $row[2];
	}
	unset( $rows );

	// Which audio attachments are really video files, as wp_attachment_is( 'video' )
	// would say, without a query per shiur.
	$attachment_ids = array();
	foreach ( $meta as $values ) {
		if ( ! empty( $values['_shiur_audio_id'] ) ) {
			$attachment_ids[ (int) $values['_shiur_audio_id'] ] = true;
		}
	}
	$video_files = array();
	foreach ( array_chunk( array_keys( $attachment_ids ), 1000 ) as $chunk ) {
		$in = implode( ',', array_map( 'absint', $chunk ) );
		foreach ( (array) $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE ID IN ({$in}) AND post_mime_type LIKE 'video/%'" ) as $video_id ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$video_files[ (int) $video_id ] = true;
		}
	}
	unset( $attachment_ids );

	$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
	$parts = array();
	$used  = array(
		'speaker' => array(),
		'series'  => array(),
	);

	foreach ( (array) $posts as $row ) {
		$id     = (int) $row[0];
		$values = isset( $meta[ $id ] ) ? $meta[ $id ] : array();

		if ( 'written_shiur' === $row[4] ) {
			$kind = 'w';
		} elseif ( ! empty( $values['_shiur_vimeo_id'] ) ) {
			$kind = 'v';
		} else {
			$attachment = isset( $values['_shiur_audio_id'] ) ? (int) $values['_shiur_audio_id'] : 0;
			$kind       = ( $attachment && isset( $video_files[ $attachment ] ) ) ? 'v' : 'a';
		}

		// A post object made from the row, so get_permalink() doesn't fetch the post
		// again. get_post() takes it as it is only because 'filter' is 'raw'.
		$post = new WP_Post(
			(object) array(
				'ID'          => $id,
				'post_name'   => $row[2],
				'post_date'   => $row[3],
				'post_type'   => $row[4],
				'post_status' => 'publish',
				'post_parent' => (int) $row[5],
				'filter'      => 'raw',
			)
		);
		$link = get_permalink( $post );

		$speaker = isset( $first[ $id ]['speaker'] ) ? $first[ $id ]['speaker'] : 0;
		$series  = isset( $first[ $id ]['series'] ) ? $first[ $id ]['series'] : 0;

		$item = array(
			'i' => $id,
			'm' => html_entity_decode( (string) $row[1], ENT_QUOTES, 'UTF-8' ),
			'a' => $speaker,
			's' => $series,
			't' => (int) strtotime( $row[3] . ' UTC' ),
			'c' => $kind,
			'u' => $link ? wp_make_link_relative( $link ) : '',
		);
		if ( ! empty( $values['_shiur_duration'] ) ) {
			$item['d'] = (string) $values['_shiur_duration'];
		}

		$json = wp_json_encode( $item, $flags );
		if ( false === $json ) {
			continue;
		}
		$parts[] = $json;

		if ( $speaker ) {
			$used['speaker'][ $speaker ] = true;
		}
		if ( $series ) {
			$used['series'][ $series ] = true;
		}
	}
	unset( $posts, $meta, $first );

	// Names only for terms the items use, decoded (names can be stored as &#8217;).
	$lists = array();
	foreach ( array( 'speaker', 'series' ) as $taxonomy ) {
		$list = array();
		foreach ( array_keys( $used[ $taxonomy ] ) as $term_id ) {
			$list[ $term_id ] = html_entity_decode( (string) $names[ $taxonomy ][ $term_id ], ENT_QUOTES, 'UTF-8' );
		}
		// As an object, so an empty list is {} and term IDs stay keys.
		$lists[ $taxonomy ] = wp_json_encode( (object) $list, $flags );
	}

	return '{"v":' . (int) $version . ',"speakers":' . $lists['speaker'] . ',"series":' . $lists['series'] . ',"items":[' . implode( ',', $parts ) . ']}';
}

/**
 * Builds and saves the index. Returns the JSON.
 */
function ner_michoel_shiur_index_rebuild() {
	$version = time();
	$json    = ner_michoel_shiur_index_build( $version );

	// Gzipped it's about a fifth of the size; base64 keeps it safe in a text column.
	// Saved plain where zlib is missing, with a prefix that says which.
	$stored = function_exists( 'gzencode' ) ? 'gz:' . base64_encode( gzencode( $json, 6 ) ) : 'js:' . $json; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- storage encoding, not obfuscation.

	update_option( NER_MICHOEL_SHIUR_INDEX_OPTION, $stored, false );
	update_option( NER_MICHOEL_SHIUR_INDEX_VERSION_OPTION, $version );

	return $json;
}
add_action( 'nm_shiur_index_rebuild', 'ner_michoel_shiur_index_rebuild' );

/**
 * The saved index: array( gzip bytes or '', JSON or '' ). Exactly one is filled,
 * so a gzip-capable reader is served the stored bytes as they are. Builds the
 * index first when there isn't one yet (the first request after an update).
 */
function ner_michoel_shiur_index_stored() {
	$stored = get_option( NER_MICHOEL_SHIUR_INDEX_OPTION, '' );

	if ( is_string( $stored ) && 0 === strpos( $stored, 'gz:' ) ) {
		$gzip = base64_decode( substr( $stored, 3 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- storage encoding, not obfuscation.
		if ( is_string( $gzip ) && '' !== $gzip ) {
			return array( $gzip, '' );
		}
	} elseif ( is_string( $stored ) && 0 === strpos( $stored, 'js:' ) && strlen( $stored ) > 3 ) {
		return array( '', substr( $stored, 3 ) );
	}

	return array( '', ner_michoel_shiur_index_rebuild() );
}

/**
 * URL of the index for the current version. Browsers may keep it for a year,
 * because the next rebuild gives a new URL.
 */
function ner_michoel_shiur_index_url() {
	return add_query_arg( 'v', (int) get_option( NER_MICHOEL_SHIUR_INDEX_VERSION_OPTION, 0 ), rest_url( 'ner-michoel/v1/shiur-index' ) );
}

/**
 * Asks for a rebuild shortly. Changes in a row share one rebuild, because the
 * event is only scheduled when there isn't one already.
 */
function ner_michoel_shiur_index_schedule() {
	if ( wp_next_scheduled( 'nm_shiur_index_rebuild' ) ) {
		return;
	}

	// While the importer works through its queue a post arrives every few seconds;
	// a rebuild every 15 minutes is plenty then.
	$importing = function_exists( 'ner_michoel_import_is_running' ) && function_exists( 'ner_michoel_import_discovered_remaining' )
		&& ner_michoel_import_is_running() && ner_michoel_import_discovered_remaining() > 0;

	wp_schedule_single_event( time() + ( $importing ? 15 * MINUTE_IN_SECONDS : MINUTE_IN_SECONDS ), 'nm_shiur_index_rebuild' );
}

/**
 * Builds the first index in the background after this version is installed, so
 * the first search doesn't wait for it.
 */
function ner_michoel_shiur_index_ensure() {
	if ( ! get_option( NER_MICHOEL_SHIUR_INDEX_VERSION_OPTION ) ) {
		ner_michoel_shiur_index_schedule();
	}
}
add_action( 'init', 'ner_michoel_shiur_index_ensure' );

function ner_michoel_shiur_index_on_status( $new_status, $old_status, $post ) {
	if ( in_array( $post->post_type, array( 'shiur', 'written_shiur' ), true ) && ( 'publish' === $new_status || 'publish' === $old_status ) ) {
		ner_michoel_shiur_index_schedule();
	}
}
add_action( 'transition_post_status', 'ner_michoel_shiur_index_on_status', 10, 3 );

// Before the post is gone, so its type can still be read. A trashed post is
// covered by the status change above.
function ner_michoel_shiur_index_on_delete( $post_id ) {
	if ( in_array( get_post_type( $post_id ), array( 'shiur', 'written_shiur' ), true ) ) {
		ner_michoel_shiur_index_schedule();
	}
}
add_action( 'delete_post', 'ner_michoel_shiur_index_on_delete' );

function ner_michoel_shiur_index_on_meta( $meta_id, $post_id, $meta_key ) {
	if ( in_array( $meta_key, NER_MICHOEL_SHIUR_INDEX_META_KEYS, true ) ) {
		ner_michoel_shiur_index_schedule();
	}
}
add_action( 'added_post_meta', 'ner_michoel_shiur_index_on_meta', 10, 3 );
add_action( 'updated_post_meta', 'ner_michoel_shiur_index_on_meta', 10, 3 );
add_action( 'deleted_post_meta', 'ner_michoel_shiur_index_on_meta', 10, 3 );

function ner_michoel_shiur_index_on_terms( $object_id, $terms, $tt_ids, $taxonomy ) {
	if ( in_array( $taxonomy, array( 'speaker', 'series' ), true ) ) {
		ner_michoel_shiur_index_schedule();
	}
}
add_action( 'set_object_terms', 'ner_michoel_shiur_index_on_terms', 10, 4 );

function ner_michoel_shiur_index_on_term_change( $term_id, $tt_id = 0, $taxonomy = '' ) {
	if ( in_array( $taxonomy, array( 'speaker', 'series' ), true ) ) {
		ner_michoel_shiur_index_schedule();
	}
}
add_action( 'created_term', 'ner_michoel_shiur_index_on_term_change', 10, 3 );
add_action( 'edited_term', 'ner_michoel_shiur_index_on_term_change', 10, 3 );
add_action( 'delete_term', 'ner_michoel_shiur_index_on_term_change', 10, 3 );

function ner_michoel_register_shiur_index_route() {
	register_rest_route(
		'ner-michoel/v1',
		'/shiur-index',
		array(
			'methods'             => 'GET',
			'callback'            => 'ner_michoel_shiur_index_rest',
			// Public on purpose: it holds only what the public site already shows.
			'permission_callback' => '__return_true',
		)
	);
}
add_action( 'rest_api_init', 'ner_michoel_register_shiur_index_route' );

/**
 * Picks the body and its headers. The body goes out as it is, not JSON-encoded
 * a second time (ner_michoel_shiur_index_serve_raw()). Gzip is sent when the
 * browser takes it and PHP isn't already compressing the output; servers leave
 * a response that already has a Content-Encoding alone.
 */
function ner_michoel_shiur_index_rest( WP_REST_Request $request ) {
	list( $gzip, $json ) = ner_michoel_shiur_index_stored();

	// zlib.output_compression can be On, 1, or a buffer size such as 4096; any of
	// those means PHP compresses the output itself.
	$zlib           = strtolower( trim( (string) ini_get( 'zlib.output_compression' ) ) );
	$php_compresses = ! in_array( $zlib, array( '', '0', 'off', 'false', 'no' ), true ) || in_array( 'ob_gzhandler', (array) ob_list_handlers(), true );
	$wants_gzip     = false !== stripos( (string) $request->get_header( 'accept_encoding' ), 'gzip' );

	$headers = array( 'Vary' => 'Accept-Encoding' );

	if ( '' !== $gzip && $wants_gzip && ! $php_compresses ) {
		$body                        = $gzip;
		$headers['Content-Encoding'] = 'gzip';
	} else {
		$body = '' !== $gzip ? (string) gzdecode( $gzip ) : $json;
	}

	$current                  = (int) get_option( NER_MICHOEL_SHIUR_INDEX_VERSION_OPTION, 0 );
	$headers['Cache-Control'] = ( $current && (int) $request->get_param( 'v' ) === $current )
		? 'public, max-age=31536000, immutable'
		: 'public, max-age=300';

	return new WP_REST_Response( $body, 200, $headers );
}

function ner_michoel_shiur_index_serve_raw( $served, $result, $request ) {
	if ( $served || '/ner-michoel/v1/shiur-index' !== $request->get_route() || 'HEAD' === $request->get_method() ) {
		return $served;
	}
	if ( ! $result instanceof WP_HTTP_Response || 200 !== $result->get_status() || ! is_string( $result->get_data() ) ) {
		return $served;
	}

	echo $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON (or its gzip bytes) built by this file, served as application/json.
	return true;
}
// Late, so WordPress's own rest_pre_serve_request filters (the CORS headers) have
// sent their headers before the body starts.
add_filter( 'rest_pre_serve_request', 'ner_michoel_shiur_index_serve_raw', 100, 3 );
