<?php
/**
 * Add New Shiur: upload the recording in the form itself, and let the tags inside the file fill the form.
 *
 * An audio or video file can carry its own title, artist and album (ID3 for an mp3, the same idea for m4a, flac, ogg
 * and video). WordPress already reads them when a file is uploaded and keeps them in the attachment's metadata; this
 * file turns them into the shiur's fields:
 *
 *   title  ->  the shiur's Title
 *   artist ->  the Speaker  (the closest speaker already in the list; none close enough: the form asks whether to add one)
 *   album  ->  the Series   (the same way)
 *
 * Three routes serve the form (assets/custom-admin-cms.js):
 *   POST shiur-file/upload    upload one audio or video file; the reply carries what it found
 *   GET  shiur-file/tags      the same reply for a file that is already in the Library
 *   POST shiur-file/add-term  add a speaker or a series (after the person says yes)
 *
 * The matching is plain functions with no WordPress calls beyond remove_accents(), so it can be tested on its own.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * ----------------------------------------------------------------------
 * Reading the tags
 * ----------------------------------------------------------------------
 */

/**
 * Tag values that say nothing ("Unknown Artist", "Track 01"). Not worth filling a field with.
 */
function ner_michoel_tag_is_placeholder( $value, $is_title = false ) {
	$key = strtolower( trim( (string) preg_replace( '/[^a-z0-9]+/i', ' ', (string) $value ) ) );
	if ( '' === $key ) {
		return true;
	}
	$empty = array( 'unknown', 'unknown artist', 'unknown album', 'various', 'various artists', 'artist', 'album', 'title', 'untitled', 'n a', 'na', 'none', 'null', 'no artist', 'no album' );
	if ( in_array( $key, $empty, true ) ) {
		return true;
	}
	return $is_title && 1 === preg_match( '/^(track|audio|recording|voice|untitled)\s*[0-9]*$/', $key );
}

/**
 * One tag as plain text: no markup, one line, no stray spaces. Empty when it says nothing.
 */
function ner_michoel_clean_tag( $value, $is_title = false ) {
	if ( ! is_string( $value ) ) {
		return '';
	}
	$value = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $value ) : strip_tags( $value );
	$value = trim( (string) preg_replace( '/\s+/u', ' ', $value ) );
	return ner_michoel_tag_is_placeholder( $value, $is_title ) ? '' : $value;
}

/**
 * The title, artist and album of a Library file. WordPress keeps them in the attachment metadata when the file is
 * uploaded; a file that has none stored but is still on this server's disk is read once more.
 */
function ner_michoel_attachment_tags( $attachment_id ) {
	$attachment_id = (int) $attachment_id;
	$meta          = wp_get_attachment_metadata( $attachment_id );
	$meta          = is_array( $meta ) ? $meta : array();

	if ( ! isset( $meta['title'] ) && ! isset( $meta['artist'] ) && ! isset( $meta['album'] ) ) {
		$file = get_attached_file( $attachment_id );
		if ( $file && is_readable( $file ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			if ( wp_attachment_is( 'audio', $attachment_id ) ) {
				$read = wp_read_audio_metadata( $file );
			} elseif ( wp_attachment_is( 'video', $attachment_id ) ) {
				$read = wp_read_video_metadata( $file );
			} else {
				$read = array();
			}
			if ( is_array( $read ) ) {
				$meta = $read;
			}
		}
	}

	$artist = isset( $meta['artist'] ) ? ner_michoel_clean_tag( $meta['artist'] ) : '';
	if ( '' === $artist && isset( $meta['band'] ) ) {
		$artist = ner_michoel_clean_tag( $meta['band'] );
	}

	return array(
		'title'  => isset( $meta['title'] ) ? ner_michoel_clean_tag( $meta['title'], true ) : '',
		'artist' => $artist,
		'album'  => isset( $meta['album'] ) ? ner_michoel_clean_tag( $meta['album'] ) : '',
	);
}

/*
 * ----------------------------------------------------------------------
 * Matching a tag to the names already on the site
 * ----------------------------------------------------------------------
 */

/**
 * One word made easier to compare: doubled letters count once and a closing h is dropped, so the usual spelling
 * differences of a Hebrew word ("Kama" / "Kamma", "Metzia" / "Metziah") become the same word.
 */
function ner_michoel_tag_fold_token( $token ) {
	$token = (string) preg_replace( '/([^\W\d_])\1+/u', '$1', $token );
	if ( strlen( $token ) > 3 && 'h' === substr( $token, -1 ) ) {
		$token = substr( $token, 0, -1 );
	}
	return $token;
}

/**
 * The words of a name, lower case, without accents, quotes or punctuation. For a person the titles before a name
 * (Rabbi, Rav, R', Reb, Dr.) are left out, so "R' Klein", "Rav Klein" and "Rabbi Klein" are the same; a name that is
 * only such words ("Rosh HaYeshiva") keeps them.
 */
function ner_michoel_tag_tokens( $text, $is_person = false ) {
	$text = html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' );
	if ( function_exists( 'remove_accents' ) ) {
		$text = remove_accents( $text );
	}
	$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	$text = (string) preg_replace( '/[\x{2018}\x{2019}\x{02BC}\x{201C}\x{201D}\'`"]+/u', '', $text );
	$text = (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text );

	$words = preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
	$words = is_array( $words ) ? $words : array();
	$words = array_values( array_diff( $words, array( 'and', 'the', 'of', 'a' ) ) );

	if ( $is_person ) {
		$titles = array( 'rabbi', 'rav', 'harav', 'horav', 'harabbi', 'hagaon', 'hagoan', 'reb', 'rebbe', 'rabbeinu', 'maran', 'dr', 'mr', 'mrs', 'r', 'ha' );
		$bare   = array_values( array_diff( $words, $titles ) );
		if ( $bare ) {
			$words = $bare;
		}
	}

	$tokens = array();
	foreach ( $words as $word ) {
		$tokens[] = ner_michoel_tag_fold_token( $word );
	}
	return $tokens;
}

/**
 * Two words that are the same, or one letter apart (two for a long word), so "Eliyahu" and "Eliahu" match. Words
 * with anything but plain letters and digits, and short words, must be identical.
 */
function ner_michoel_tag_tokens_match( $a, $b ) {
	if ( $a === $b ) {
		return true;
	}
	if ( 1 === preg_match( '/[^a-z0-9]/', $a . $b ) ) {
		return false;
	}
	$shorter = min( strlen( $a ), strlen( $b ) );
	if ( $shorter < 5 ) {
		return false;
	}
	return levenshtein( $a, $b ) <= ( $shorter >= 7 ? 2 : 1 );
}

/**
 * How alike two word lists are, 0 to 1: twice the words they share over the words in both. Identical words first,
 * then near-identical ones.
 */
function ner_michoel_tag_similarity( array $a, array $b ) {
	if ( ! $a || ! $b ) {
		return 0.0;
	}
	$hits   = 0;
	$left   = array();
	$taken  = array();
	foreach ( $a as $word ) {
		$found = false;
		foreach ( $b as $i => $other ) {
			if ( ! isset( $taken[ $i ] ) && $word === $other ) {
				$taken[ $i ] = true;
				$hits++;
				$found       = true;
				break;
			}
		}
		if ( ! $found ) {
			$left[] = $word;
		}
	}
	foreach ( $left as $word ) {
		foreach ( $b as $i => $other ) {
			if ( ! isset( $taken[ $i ] ) && ner_michoel_tag_tokens_match( $word, $other ) ) {
				$taken[ $i ] = true;
				$hits++;
				break;
			}
		}
	}
	return ( 2 * $hits ) / ( count( $a ) + count( $b ) );
}

/**
 * Which of the names in $terms (term id => name) a tag means.
 *
 *   matched    one name is the clear closest: 'term' is it ('exact' when it is the same words; otherwise it is the
 *              closest, and the person is told so)
 *   ambiguous  several names are about equally close ("Rabbi Meiselman" when there are three Meiselmans): nothing is
 *              picked, 'candidates' lists them
 *   none       nothing close: the person is asked whether to add the tag as a new name ('candidates' holds any
 *              names that are a little alike, to mention in the question)
 */
function ner_michoel_match_tag_to_terms( $tag, array $terms, $is_person = false ) {
	$result = array(
		'tag'        => (string) $tag,
		'status'     => 'none',
		'term'       => null,
		'exact'      => false,
		'candidates' => array(),
	);

	$wanted = ner_michoel_tag_tokens( $tag, $is_person );
	if ( ! $wanted ) {
		return $result;
	}
	$sorted_wanted = $wanted;
	sort( $sorted_wanted );

	$scored = array();
	foreach ( $terms as $id => $name ) {
		$tokens = ner_michoel_tag_tokens( $name, $is_person );
		if ( ! $tokens ) {
			continue;
		}
		$score = ner_michoel_tag_similarity( $wanted, $tokens );
		if ( $score < 0.4 ) {
			continue;
		}
		$sorted = $tokens;
		sort( $sorted );
		$scored[] = array(
			'id'    => (int) $id,
			'name'  => html_entity_decode( (string) $name, ENT_QUOTES, 'UTF-8' ),
			'score' => $score,
			'same'  => $sorted === $sorted_wanted,
		);
	}
	if ( ! $scored ) {
		return $result;
	}

	usort(
		$scored,
		function ( $x, $y ) {
			if ( $x['score'] === $y['score'] ) {
				return $x['id'] - $y['id'];
			}
			return $x['score'] > $y['score'] ? -1 : 1;
		}
	);

	$pick = function ( $row ) {
		return array( 'id' => $row['id'], 'name' => $row['name'] );
	};

	$best   = $scored[0];
	$second = isset( $scored[1] ) ? $scored[1]['score'] : 0.0;

	if ( $best['score'] >= 0.999 || ( $best['score'] >= 0.6 && $best['score'] - $second >= 0.1 ) ) {
		$result['status'] = 'matched';
		$result['term']   = $pick( $best );
		$result['exact']  = $best['same'];
		return $result;
	}

	if ( $best['score'] >= 0.6 ) {
		$result['status'] = 'ambiguous';
		foreach ( $scored as $row ) {
			if ( $row['score'] >= 0.6 && $best['score'] - $row['score'] < 0.1 && count( $result['candidates'] ) < 4 ) {
				$result['candidates'][] = $pick( $row );
			}
		}
		return $result;
	}

	foreach ( array_slice( $scored, 0, 3 ) as $row ) {
		$result['candidates'][] = $pick( $row );
	}
	return $result;
}

/*
 * ----------------------------------------------------------------------
 * The routes
 * ----------------------------------------------------------------------
 */

/**
 * What the form needs to know about one audio or video file in the Library: where it is, what its tags say, and
 * which speaker and series those tags mean.
 */
function ner_michoel_shiur_file_info( $attachment_id ) {
	$attachment_id = (int) $attachment_id;
	$tags          = ner_michoel_attachment_tags( $attachment_id );

	$match = function ( $tag, $taxonomy, $is_person ) {
		if ( '' === $tag || ! taxonomy_exists( $taxonomy ) ) {
			return null;
		}
		$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'fields' => 'id=>name' ) );
		return ner_michoel_match_tag_to_terms( $tag, is_wp_error( $terms ) ? array() : (array) $terms, $is_person );
	};

	$stored = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );

	return array(
		'id'       => $attachment_id,
		'filename' => '' !== $stored ? wp_basename( $stored ) : '',
		'url'      => (string) wp_get_attachment_url( $attachment_id ),
		'title'    => (string) get_the_title( $attachment_id ),
		'tags'     => $tags,
		'speaker'  => $match( $tags['artist'], 'speaker', true ),
		'series'   => $match( $tags['album'], 'series', false ),
	);
}

function ner_michoel_shiur_file_can_use() {
	return current_user_can( 'edit_posts' ) && current_user_can( 'upload_files' );
}

function ner_michoel_register_shiur_file_routes() {
	register_rest_route(
		'ner-michoel/v1',
		'/shiur-file/upload',
		array(
			'methods'             => 'POST',
			'callback'            => 'ner_michoel_shiur_file_route_upload',
			'permission_callback' => 'ner_michoel_shiur_file_can_use',
		)
	);
	register_rest_route(
		'ner-michoel/v1',
		'/shiur-file/tags',
		array(
			'methods'             => 'GET',
			'callback'            => 'ner_michoel_shiur_file_route_tags',
			'permission_callback' => 'ner_michoel_shiur_file_can_use',
			'args'                => array(
				'attachment' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
			),
		)
	);
	register_rest_route(
		'ner-michoel/v1',
		'/shiur-file/add-term',
		array(
			'methods'             => 'POST',
			'callback'            => 'ner_michoel_shiur_file_route_add_term',
			'permission_callback' => 'ner_michoel_shiur_file_can_use',
			'args'                => array(
				'taxonomy' => array( 'required' => true, 'sanitize_callback' => 'sanitize_key' ),
				'name'     => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
			),
		)
	);
}
add_action( 'rest_api_init', 'ner_michoel_register_shiur_file_routes' );

/**
 * Saves the uploaded file the way the media window does (the same-name check, the clean title, the Bunny copy and
 * the tag reading all run on the upload itself), then answers with what the tags say.
 */
function ner_michoel_shiur_file_route_upload( WP_REST_Request $request ) {
	$files = $request->get_file_params();
	if ( empty( $files['file'] ) || ! is_array( $files['file'] ) ) {
		return new WP_Error(
			'nm_no_file',
			sprintf(
				/* translators: %s: the largest file this server takes, for example 64 MB */
				__( 'No file arrived. It may be bigger than this server takes (%s).', 'ner-michoel-core' ),
				size_format( wp_max_upload_size() )
			),
			array( 'status' => 400 )
		);
	}
	$file = $files['file'];

	if ( ! empty( $file['error'] ) ) {
		$too_big = in_array( (int) $file['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true );
		return new WP_Error(
			'nm_upload_error',
			$too_big
				? sprintf(
					/* translators: %s: the largest file this server takes, for example 64 MB */
					__( 'That file is bigger than this server takes (%s).', 'ner-michoel-core' ),
					size_format( wp_max_upload_size() )
				)
				: __( 'The file did not arrive in one piece. Please try again.', 'ner-michoel-core' ),
			array( 'status' => 400 )
		);
	}

	$kind = function_exists( 'ner_michoel_media_kind' ) ? ner_michoel_media_kind( (string) $file['name'] ) : '';
	if ( 'media' !== $kind ) {
		return new WP_Error( 'nm_not_media', __( 'That is not an audio or video file. Choose an mp3, m4a, wav, mp4 or similar file.', 'ner-michoel-core' ), array( 'status' => 400 ) );
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';

	$attachment_id = media_handle_upload( 'file', 0 );
	if ( is_wp_error( $attachment_id ) ) {
		return new WP_Error( 'nm_upload_failed', $attachment_id->get_error_message(), array( 'status' => 400 ) );
	}

	return new WP_REST_Response( ner_michoel_shiur_file_info( $attachment_id ), 200 );
}

function ner_michoel_shiur_file_route_tags( WP_REST_Request $request ) {
	$attachment_id = (int) $request->get_param( 'attachment' );
	$post          = $attachment_id ? get_post( $attachment_id ) : null;
	if ( ! $post || 'attachment' !== $post->post_type || ! function_exists( 'ner_michoel_media_kind' ) || 'media' !== ner_michoel_media_kind( (string) $post->post_mime_type ) ) {
		return new WP_Error( 'nm_not_media', __( 'That is not an audio or video file in the library.', 'ner-michoel-core' ), array( 'status' => 404 ) );
	}
	return new WP_REST_Response( ner_michoel_shiur_file_info( $attachment_id ), 200 );
}

/**
 * Adds a speaker or a series the person agreed to add. A name that is already there comes back as it is.
 */
function ner_michoel_shiur_file_route_add_term( WP_REST_Request $request ) {
	$taxonomy = (string) $request->get_param( 'taxonomy' );
	$name     = trim( (string) $request->get_param( 'name' ) );

	if ( ! in_array( $taxonomy, array( 'speaker', 'series' ), true ) || ! taxonomy_exists( $taxonomy ) ) {
		return new WP_Error( 'nm_bad_taxonomy', __( 'Only speakers and series can be added here.', 'ner-michoel-core' ), array( 'status' => 400 ) );
	}
	if ( '' === $name ) {
		return new WP_Error( 'nm_no_name', __( 'There is no name to add.', 'ner-michoel-core' ), array( 'status' => 400 ) );
	}
	$tax = get_taxonomy( $taxonomy );
	if ( ! $tax || ! current_user_can( $tax->cap->edit_terms ) ) {
		return new WP_Error( 'nm_forbidden', __( "You don't have permission to do that.", 'ner-michoel-core' ), array( 'status' => 403 ) );
	}

	$existing = term_exists( $name, $taxonomy );
	if ( $existing ) {
		$term_id = (int) ( is_array( $existing ) ? $existing['term_id'] : $existing );
		$added   = false;
	} else {
		$made = wp_insert_term( $name, $taxonomy );
		if ( is_wp_error( $made ) ) {
			return new WP_Error( 'nm_add_failed', $made->get_error_message(), array( 'status' => 400 ) );
		}
		$term_id = (int) $made['term_id'];
		$added   = true;
	}

	$term = get_term( $term_id, $taxonomy );
	return new WP_REST_Response(
		array(
			'id'    => $term_id,
			'name'  => $term && ! is_wp_error( $term ) ? html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ) : $name,
			'added' => $added,
		),
		200
	);
}
