<?php
/**
 * Clean titles from file names, and no two shiur files with the same name.
 *
 * "bava-kama.mp3" is a file name, not a title. The dashes, underscores and periods are there to keep the
 * file name safe; people should read "Bava Kama". Two things live here:
 *
 * 1. Titles. ner_michoel_title_from_filename() turns a file name into a clean title: no extension, no
 *    dashes, underscores, periods or plus signs between words, no upload stamp ("1787166936_") in front, each
 *    word starting with a capital. The one dash it keeps is a range between two page numbers ("45b-46a"),
 *    because that dash is part of the title. A newly uploaded audio, video or PDF file gets that title in the
 *    Media Library, so the Library and the file pickers show "Bava Kama" while the file itself stays
 *    bava-kama.mp3. assets/file-names.js is the same function for the browser (Upload Many, the shiur form).
 *
 * 2. Same name. Two audio/video files, or two PDFs, with the same name (ignoring case and punctuation) look
 *    identical in every list, and WordPress would quietly rename the second "bava-kama-1.mp3" and title it
 *    "bava kama 1". So an upload whose name is already in the Library is refused with a plain message that
 *    says which file it is, and the person gives the new file its own name. Nothing is ever numbered. Photos
 *    and other files are left to WordPress. When files are kept on Bunny (bunny-storage.php) the local copy is
 *    deleted after the upload, so WordPress can no longer see that a name is taken and would write the new file
 *    over the old one on the CDN; the stored name is also checked against the Library so that can't happen.
 *
 * The first half of this file (down to the "WordPress" banner) uses only PHP, so it can be tested without WordPress.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The file endings a title never shows. A closed list, so "bava.kama" (no ending) is not mistaken for a file
 * called "bava" with the ending ".kama".
 */
function ner_michoel_filename_extensions() {
	return 'mp3|m4a|m4b|mp4|m4v|mov|wav|wma|wax|wmv|wm|wmx|aac|ogg|oga|ogv|opus|flac|mka|mkv|webm|avi|divx|flv|asf|asx|3gp|3gpp|3g2|3gp2|amr|ra|ram|mid|midi|mp2|mpg|mpeg|mpe|mpga|aif|aiff|pdf';
}

/**
 * What separates the words in a file name: any white space, _ . + ~ | and every kind of dash. Written out
 * (not \s) so PHP and the browser agree on exactly the same characters. Used inside [...].
 */
function ner_michoel_filename_separators() {
	return '\x{0009}-\x{000D}\x{0020}\x{0085}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}_.+~|\x{2010}-\x{2015}\x{2212}\-';
}

/**
 * True for a dash that may stand for "to" between two page numbers: a hyphen, or the dash characters that
 * look like one, with any spaces around it.
 */
function ner_michoel_filename_is_dash( $separator ) {
	$bare = preg_replace( '/[\x{0009}-\x{000D}\x{0020}\x{0085}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+/u', '', (string) $separator );
	return in_array( $bare, array( '-', "\u{2010}", "\u{2011}", "\u{2012}", "\u{2013}" ), true );
}

/**
 * A page of a Gemara ("28a", "45b", "27"): one to three digits, no leading zero (a leading zero is a counter,
 * "025"), an optional a or b.
 */
function ner_michoel_filename_is_page( $token ) {
	return 1 === preg_match( '/^[1-9][0-9]{0,2}[ab]?$/i', $token );
}

/** Digits with an optional a or b at the end: a page, a counter or a year. */
function ner_michoel_filename_is_number( $token ) {
	return 1 === preg_match( '/^[0-9]+[ab]?$/i', $token );
}

/** Where a page falls in the book: 27 and 27a are the front of daf 27, 27b its back. Only used to order two pages. */
function ner_michoel_filename_page_value( $token ) {
	return 2 * (int) $token + ( 'b' === strtolower( substr( $token, -1 ) ) ? 1 : 0 );
}

/**
 * Capitalises the first letter of a word when it is a plain lowercase a to z, after any opening bracket or
 * quote ("(night)" becomes "(Night)"). Hebrew and other letters, digits, and words already capitalised are
 * left alone.
 */
function ner_michoel_filename_capitalise( $token ) {
	preg_match( '/^[^\p{L}\p{N}]*/u', $token, $lead );
	$lead  = $lead[0];
	$rest  = substr( $token, strlen( $lead ) );
	$first = '' === $rest || ! preg_match( '/^[\s\S]/u', $rest, $letter ) ? '' : $letter[0];
	if ( 1 === strlen( $first ) && $first >= 'a' && $first <= 'z' ) {
		return $lead . strtoupper( $first ) . substr( $rest, 1 );
	}
	return $token;
}

/**
 * A clean title from a file name (or from an attachment title that is only a file name).
 *
 *   bava-kama.mp3                              Bava Kama
 *   Bava_Kama.MP3  /  bava.kama.mp3            Bava Kama
 *   1787166936_01-Introduction-to-the-Vaadim   01 Introduction To The Vaadim
 *   019-Bava-Kamma-45b-46a.mp3                 019 Bava Kamma 45b-46a
 *   2026-10-12 Rabbi Klein.mp3                 2026 10 12 Rabbi Klein
 *
 * The dash between two page numbers stays, because "45b-46a" is a range and the same shiurim on the old site
 * are titled that way. It stays only for two pages side by side, the second after the first (not for a counter
 * such as "025", not for "28a-1", and not in a run of three or more numbers such as a date).
 */
function ner_michoel_title_from_filename( $name ) {
	$s = trim( (string) $name );
	if ( 1 !== preg_match( '//u', $s ) ) {
		$s = preg_replace( '/[\x80-\xFF]+/', '', $s ); // Not valid text: keep only the plain letters.
	}

	// A path leaves only its last part ("C:\fakepath\bava-kama.mp3").
	$s = preg_replace( '#^.*[\\\\/]#su', '', $s );

	// "bava%20kama" from a web address reads as letters, but only when every % is a real escape and the result is valid text.
	if ( preg_match( '/%[0-9A-Fa-f]{2}/', $s ) && ! preg_match( '/%(?![0-9A-Fa-f]{2})/', $s ) ) {
		$decoded = rawurldecode( $s );
		if ( 1 === preg_match( '//u', $decoded ) ) {
			$s = $decoded;
		}
	}

	// The file ending (twice over for "name.m4a.mp3").
	for ( $i = 0; $i < 3; $i++ ) {
		$trimmed = preg_replace( '/\.(?:' . ner_michoel_filename_extensions() . ')$/iu', '', $s );
		if ( null === $trimmed || $trimmed === $s ) {
			break;
		}
		$s = $trimmed;
	}

	// The upload stamp the old site put in front of its file names ("1787166936_").
	$s = preg_replace( '/^[0-9]{9,13}[_-]/u', '', $s );

	// Words and the separators between them: word, separator, word, separator, ...
	$parts  = preg_split( '/([' . ner_michoel_filename_separators() . ']+)/u', $s, -1, PREG_SPLIT_DELIM_CAPTURE );
	$tokens = array();
	$seps   = array();
	for ( $i = 0, $n = count( $parts ); $i < $n; $i += 2 ) {
		if ( '' === $parts[ $i ] ) {
			continue; // Nothing before the first separator, or after the last one.
		}
		$tokens[] = $parts[ $i ];
		$seps[]   = isset( $parts[ $i + 1 ] ) ? $parts[ $i + 1 ] : '';
	}

	$count = count( $tokens );
	$out   = '';
	for ( $k = 0; $k < $count; $k++ ) {
		$out .= ner_michoel_filename_capitalise( $tokens[ $k ] );
		if ( $k + 1 >= $count ) {
			break;
		}
		$range = ner_michoel_filename_is_dash( $seps[ $k ] )
			&& ner_michoel_filename_is_page( $tokens[ $k ] )
			&& ner_michoel_filename_is_page( $tokens[ $k + 1 ] )
			&& ner_michoel_filename_page_value( $tokens[ $k + 1 ] ) > ner_michoel_filename_page_value( $tokens[ $k ] )
			&& ! ( $k > 0 && ner_michoel_filename_is_dash( $seps[ $k - 1 ] ) && ner_michoel_filename_is_number( $tokens[ $k - 1 ] ) )
			&& ! ( $k + 2 < $count && ner_michoel_filename_is_dash( $seps[ $k + 1 ] ) && ner_michoel_filename_is_number( $tokens[ $k + 2 ] ) );
		$out  .= $range ? '-' : ' ';
	}

	return $out;
}

/**
 * What two names must share to count as the same name: the clean title in lowercase with every run of
 * punctuation or spaces as one dash. "Bava-Kama.mp3", "bava kama.MP3" and "1787166936_bava_kama.m4a" are all
 * "bava-kama". The file ending is not part of it (an audio file and its PDF are told apart by kind instead).
 */
function ner_michoel_name_key( $name ) {
	$title = ner_michoel_title_from_filename( $name );
	if ( '' === $title ) {
		return '';
	}
	$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $title, 'UTF-8' ) : strtolower( $title );
	$key   = preg_replace( '/[^\p{L}\p{N}\p{M}]+/u', '-', $lower );
	return trim( (string) $key, '-' );
}

/**
 * Is $title just the name of the file $file: the same words, or the same words with the number WordPress adds to a
 * stored file whose name was taken ("bava-kama" and bava-kama-1.mp3)? That number belongs to the file, never to the title.
 */
function ner_michoel_name_matches_file( $title, $file ) {
	$title_key = ner_michoel_name_key( $title );
	$file_key  = ner_michoel_name_key( $file );
	if ( '' === $title_key || '' === $file_key ) {
		return false;
	}
	return $file_key === $title_key || ( 0 === strpos( $file_key, $title_key . '-' ) && 1 === preg_match( '/^[0-9]{1,3}$/', substr( $file_key, strlen( $title_key ) + 1 ) ) );
}

/*
 * ----------------------------------------------------------------------
 * WordPress
 * ----------------------------------------------------------------------
 */

/**
 * 'media' for audio and video, 'pdf' for a PDF, '' for anything else (photos, zip files). Takes a mime type or a
 * file name.
 */
function ner_michoel_media_kind( $value ) {
	$value = (string) $value;
	if ( preg_match( '#^(?:audio|video|application)/[\w.+-]+$#', $value ) ) {
		$mime = $value;
	} else {
		$type = wp_check_filetype( $value );
		$mime = (string) $type['type'];
	}
	if ( 0 === strpos( $mime, 'audio/' ) || 0 === strpos( $mime, 'video/' ) ) {
		return 'media';
	}
	return 'application/pdf' === $mime ? 'pdf' : '';
}

/**
 * The title for an attachment: its own title when that is only its file name (cleaned), otherwise the cleaned
 * file name. Used where a shiur needs a title and none was typed.
 */
function ner_michoel_title_for_attachment( $attachment_id, $fallback = '' ) {
	$post = get_post( (int) $attachment_id );
	$file = (string) get_post_meta( (int) $attachment_id, '_wp_attached_file', true );
	$base = '' === $file ? '' : wp_basename( $file );
	if ( $post && '' !== trim( $post->post_title ) && '' !== $base && ner_michoel_name_matches_file( $post->post_title, $base ) ) {
		$title = ner_michoel_title_from_filename( $post->post_title );
	} else {
		$title = ner_michoel_title_from_filename( $base );
	}
	return '' !== $title ? $title : $fallback;
}

/**
 * The name of the file being uploaded in this request, set when the upload starts and used (once) when its
 * Media Library entry is made. Only a title WordPress made from that name is cleaned: a title given in code, the
 * shiur's own title on an imported file, or one read from the recording's tags is left alone.
 */
function ner_michoel_upload_original_name( $set = null ) {
	static $name = '';
	if ( null !== $set ) {
		$name = (string) $set;
	}
	return $name;
}

/**
 * An audio, video or PDF file already in the Library with the same name (same kind), or null. Looks at the file's
 * name and at the Library title, so a file whose title was typed over is still found. The database narrows the
 * search by the longest word; the exact comparison is done here.
 */
function ner_michoel_find_same_name_attachment( $name, $kind ) {
	global $wpdb;

	$key = ner_michoel_name_key( $name );
	if ( '' === $key || ! in_array( $kind, array( 'media', 'pdf' ), true ) ) {
		return null;
	}

	$needle = '';
	foreach ( explode( '-', $key ) as $word ) {
		if ( strlen( $word ) > strlen( $needle ) ) {
			$needle = $word;
		}
	}
	$like = '%' . $wpdb->esc_like( $needle ) . '%';
	// The kind clause is fixed text (%% is a literal percent sign for prepare()); the search word is a placeholder.
	$mime = 'pdf' === $kind ? "p.post_mime_type = 'application/pdf'" : "( p.post_mime_type LIKE 'audio/%%' OR p.post_mime_type LIKE 'video/%%' )";
	$sql  = "SELECT p.ID, p.post_title, p.post_date, m.meta_value AS file
		FROM {$wpdb->posts} p
		LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attached_file'
		WHERE p.post_type = 'attachment' AND p.post_status <> 'trash' AND {$mime}
		AND ( m.meta_value LIKE %s OR p.post_title LIKE %s )
		ORDER BY p.ID ASC LIMIT 10000";
	$rows = $wpdb->get_results( $wpdb->prepare( $sql, $like, $like ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

	foreach ( (array) $rows as $row ) {
		if ( ( '' !== (string) $row->file && ner_michoel_name_key( wp_basename( $row->file ) ) === $key ) || ner_michoel_name_key( $row->post_title ) === $key ) {
			return $row;
		}
	}
	return null;
}

/**
 * What the person is told when a file is refused. Plain text: the uploader shows it as it is.
 */
function ner_michoel_same_name_message( $existing ) {
	// A title somebody wrote is shown as written; a bare file name is shown cleaned.
	$shown = trim( (string) $existing->post_title );
	if ( '' === $shown ) {
		$shown = wp_basename( (string) $existing->file );
	}
	if ( ! preg_match( '/\s/u', $shown ) ) {
		$shown = ner_michoel_title_from_filename( $shown );
	}
	return sprintf(
		/* translators: 1: the name of the file already in the Library, 2: the date it was added */
		__( '“%1$s” is already in the library (added %2$s), so this file was not uploaded again. If it is the same recording, use the one that is already there. If it is a different recording, give this file its own name (add the date, the daf or the speaker) and upload it again.', 'ner-michoel-core' ),
		$shown,
		mysql2date( get_option( 'date_format' ), $existing->post_date )
	);
}

/**
 * Upload gate: remember the name, then refuse a file whose name is already in the Library. Runs for every upload
 * (the media window, Media > Add New, the REST API), so the check is the same wherever the file comes from.
 */
function ner_michoel_check_upload_name( $file ) {
	if ( ! is_array( $file ) || empty( $file['name'] ) ) {
		return $file;
	}

	ner_michoel_upload_original_name( $file['name'] );

	if ( ! empty( $file['error'] ) ) {
		return $file; // Already refused for another reason.
	}

	$kind = ner_michoel_media_kind( $file['name'] );
	if ( '' === $kind || ! apply_filters( 'ner_michoel_refuse_same_name_media', true, $file ) ) {
		return $file;
	}

	$existing = ner_michoel_find_same_name_attachment( $file['name'], $kind );
	if ( $existing ) {
		$file['error'] = ner_michoel_same_name_message( $existing );
	}
	return $file;
}
add_filter( 'wp_handle_upload_prefilter', 'ner_michoel_check_upload_name' );

/**
 * WordPress titles a new upload after the file ("bava-kama"). For audio, video and PDF files that title is made
 * clean ("Bava Kama") as the Library entry is created.
 */
function ner_michoel_clean_uploaded_media_title( $data, $postarr = array(), $unsanitized = array(), $update = false ) {
	$original = ner_michoel_upload_original_name();
	if ( '' === $original || $update || ! empty( $postarr['ID'] ) ) {
		return $data;
	}
	ner_michoel_upload_original_name( '' ); // One file, one entry.

	if ( empty( $data['post_mime_type'] ) || '' === ner_michoel_media_kind( $data['post_mime_type'] ) ) {
		return $data;
	}

	$title = trim( (string) wp_unslash( isset( $data['post_title'] ) ? $data['post_title'] : '' ) );
	if ( '' !== $title && ner_michoel_name_key( $title ) !== ner_michoel_name_key( $original ) ) {
		return $data; // Not made from the file's name (the recording's own tags, for one).
	}

	$clean = sanitize_text_field( ner_michoel_title_from_filename( '' !== $title ? $title : $original ) );
	if ( '' !== $clean ) {
		$data['post_title'] = wp_slash( $clean );
	}
	return $data;
}
add_filter( 'wp_insert_attachment_data', 'ner_michoel_clean_uploaded_media_title', 10, 4 );

/**
 * True when files are kept on Bunny, where WordPress can no longer see them on this server's disk.
 */
function ner_michoel_names_checked_in_database() {
	$on = function_exists( 'ner_michoel_bunny_storage_is_configured' ) && ner_michoel_bunny_storage_is_configured();
	return (bool) apply_filters( 'ner_michoel_check_stored_names_in_database', $on );
}

/**
 * With files on Bunny the local copy is deleted after the upload, and WordPress only checks the local disk when it
 * makes a stored name unique. A new file with the same name and month would then be stored under the old file's
 * path and replace it on the CDN. This also checks the names the Library already holds and, if one is taken, adds
 * the usual -1, -2 to the file (never to a title).
 */
function ner_michoel_keep_stored_names_unique( $filename, $ext = '', $dir = '' ) {
	if ( ! ner_michoel_names_checked_in_database() || '' === (string) $dir ) {
		return $filename;
	}

	global $wpdb;
	$uploads = wp_get_upload_dir();
	$base    = wp_normalize_path( $uploads['basedir'] );
	$here    = untrailingslashit( wp_normalize_path( $dir ) );
	if ( 0 !== strpos( $here, $base ) ) {
		return $filename;
	}
	$sub    = trim( substr( $here, strlen( $base ) ), '/' );
	$prefix = '' === $sub ? '' : $sub . '/';

	$taken = function ( $candidate ) use ( $wpdb, $prefix, $here ) {
		return file_exists( $here . '/' . $candidate )
			|| (bool) $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1", $prefix . $candidate ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	};

	if ( ! $taken( $filename ) ) {
		return $filename;
	}

	$stem = ( '' !== (string) $ext && substr( $filename, -strlen( $ext ) ) === $ext ) ? substr( $filename, 0, -strlen( $ext ) ) : $filename;
	for ( $number = 1; $number <= 200; $number++ ) {
		$candidate = $stem . '-' . $number . $ext;
		if ( ! $taken( $candidate ) ) {
			return $candidate;
		}
	}
	return $filename;
}
add_filter( 'wp_unique_filename', 'ner_michoel_keep_stored_names_unique', 20, 3 );

/**
 * assets/file-names.js is loaded wherever the file pickers or the Control Panel's forms are, after those have
 * queued their scripts (the pickers only call it when something is clicked, so the order is free).
 */
function ner_michoel_enqueue_file_names_script() {
	if ( wp_script_is( 'ner-michoel-media-pickers', 'enqueued' ) || wp_script_is( 'ner-michoel-admin-cms', 'enqueued' ) ) {
		wp_enqueue_script( 'ner-michoel-file-names', NER_MICHOEL_CORE_URL . 'assets/file-names.js', array(), NER_MICHOEL_CORE_VERSION, true );
	}
}
add_action( 'admin_enqueue_scripts', 'ner_michoel_enqueue_file_names_script', 99 );
add_action( 'wp_enqueue_scripts', 'ner_michoel_enqueue_file_names_script', 99 );
