<?php
/**
 * The phone app's API: what the Ner Michoel app for iPhone and Android reads from
 * the site, and how it signs in. Everything is under ner-michoel/v1/app/.
 *
 * The app keeps the whole library list on the phone (the search page's index,
 * ner-michoel/v1/shiur-index, unchanged) and browses and searches it without the
 * network. It asks these routes only for what that list doesn't carry:
 *
 *   GET  app/manifest       one small call per launch: the library list's version and
 *                           address, the homepage banner and Zoom line, website links
 *   GET  app/home           This Week (parsha / Yom Tov), New Shiurim, Mazal Tov
 *   GET  app/terms          speakers (photo), series (parent, order, cover), topics
 *   GET  app/shiur/<id>     one shiur in full: audio / video / PDF addresses with the
 *                           site's backup route for each, sizes, dedication, Hebrew
 *                           date, its other formats
 *   POST app/login          email + password; answers with an app token, or with the
 *                           emailed-code step (login-codes.php), exactly as the site
 *   POST app/login-code     the 6-digit code; answers with an app token
 *   POST app/logout         ends this phone's token
 *   GET  app/library        Saved and History, as ids and times
 *   POST app/saved          sets one shiur saved or not (set, not toggle, so a change
 *                           made offline can be sent twice without undoing itself)
 *   POST app/events         plays, completes and reads, up to 50 at once (the phone
 *                           keeps them while offline); same counting as shiur-event
 *   POST app/delete-account deletes the signed-in account (store rule: an app that
 *                           makes accounts must let people delete them)
 *
 * A resend of the emailed code uses the site's own route, account-login-code-resend.
 *
 * Signing in. The app has no cookies, so after the code step it gets a token of its
 * own: "nma_<user id>_<64 hex>", sent back on every call as
 *   Authorization: Bearer <token>   or   X-NM-App-Token: <token>
 * (the second because some Apache setups drop Authorization before PHP sees it). Only
 * a hash is stored (user meta nm_app_tokens), at most 10 phones per account, each
 * forgotten after 180 days unused. A token signs in ONLY on app/ routes, never on the
 * rest of the site or wp-admin. Changing or resetting the password, or "Forget
 * remembered computers" on the Account page, ends every app token of that account.
 * A phone is a remembered computer in all but name, so the code is asked for once
 * per phone, as the website does once per computer.
 *
 * Nothing here writes to a shiur or a setting; public routes answer only what the
 * public site already shows (published shiur and written_shiur, never alumni_shiur).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Bumped when an answer changes shape, so an old app can tell. */
define( 'NER_MICHOEL_APP_API_VERSION', 1 );

/** How many phones one account may have signed in at once (the oldest goes first). */
define( 'NER_MICHOEL_APP_TOKEN_MAX', 10 );

/** A token not used for this long stops working. */
define( 'NER_MICHOEL_APP_TOKEN_IDLE', 180 * DAY_IN_SECONDS );

/* ------------------------------------------------------------------
 * Tokens
 * ------------------------------------------------------------------ */

/** The stored form of a token's secret part. */
function ner_michoel_app_token_hash( $secret ) {
	return hash_hmac( 'sha256', (string) $secret, wp_salt( 'auth' ) );
}

/** This account's tokens: hash => array( label, created, used ). */
function ner_michoel_app_tokens( $user_id ) {
	$list = get_user_meta( (int) $user_id, 'nm_app_tokens', true );
	return is_array( $list ) ? $list : array();
}

/**
 * A new token for this account. Returns the token itself, which is shown once (to
 * the app) and never stored.
 */
function ner_michoel_app_token_issue( $user_id, $label ) {
	$user_id = (int) $user_id;
	$secret  = bin2hex( random_bytes( 32 ) );
	$now     = time();
	$list    = ner_michoel_app_tokens( $user_id );

	foreach ( $list as $hash => $row ) {
		if ( empty( $row['used'] ) || (int) $row['used'] < $now - NER_MICHOEL_APP_TOKEN_IDLE ) {
			unset( $list[ $hash ] );
		}
	}
	// The oldest-used go first when the account is at the limit.
	uasort(
		$list,
		function ( $a, $b ) {
			return (int) $b['used'] - (int) $a['used'];
		}
	);
	$list = array_slice( $list, 0, NER_MICHOEL_APP_TOKEN_MAX - 1, true );

	$list[ ner_michoel_app_token_hash( $secret ) ] = array(
		'label'   => substr( sanitize_text_field( (string) $label ), 0, 60 ),
		'created' => $now,
		'used'    => $now,
	);
	update_user_meta( $user_id, 'nm_app_tokens', $list );

	return 'nma_' . $user_id . '_' . $secret;
}

/** Ends every app token of this account. */
function ner_michoel_app_tokens_forget( $user_id ) {
	delete_user_meta( (int) $user_id, 'nm_app_tokens' );
}

/** The token sent with this request, or ''. */
function ner_michoel_app_request_token() {
	$raw = '';
	if ( ! empty( $_SERVER['HTTP_X_NM_APP_TOKEN'] ) ) {
		$raw = (string) wp_unslash( $_SERVER['HTTP_X_NM_APP_TOKEN'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked against a strict pattern below.
	} else {
		foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) && preg_match( '/^Bearer\s+(\S+)$/i', trim( (string) wp_unslash( $_SERVER[ $key ] ) ), $m ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$raw = $m[1];
				break;
			}
		}
	}
	return preg_match( '/^nma_\d{1,12}_[a-f0-9]{64}$/', $raw ) ? $raw : '';
}

/** True when this request is for one of the app's routes (the only place a token counts). */
function ner_michoel_app_is_app_request() {
	$route = '';
	if ( isset( $_GET['rest_route'] ) && is_string( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$route = (string) wp_unslash( $_GET['rest_route'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	} elseif ( isset( $_SERVER['REQUEST_URI'] ) ) {
		$path   = (string) wp_parse_url( (string) wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$prefix = '/' . trim( rest_get_url_prefix(), '/' ) . '/';
		$at     = strpos( $path, $prefix );
		$route  = false === $at ? '' : '/' . substr( $path, $at + strlen( $prefix ) );
	}
	return 0 === strpos( $route, '/ner-michoel/v1/app/' );
}

/**
 * Checks the token and returns its account, or 0. Marks the token used (at most
 * once an hour, to keep writes down).
 */
function ner_michoel_app_token_user( $token ) {
	if ( ! preg_match( '/^nma_(\d{1,12})_([a-f0-9]{64})$/', (string) $token, $m ) ) {
		return 0;
	}
	$user_id = (int) $m[1];
	$hash    = ner_michoel_app_token_hash( $m[2] );
	$list    = ner_michoel_app_tokens( $user_id );
	if ( ! $user_id || ! isset( $list[ $hash ] ) || ! get_userdata( $user_id ) ) {
		return 0;
	}
	$now  = time();
	$used = (int) $list[ $hash ]['used'];
	if ( $used < $now - NER_MICHOEL_APP_TOKEN_IDLE ) {
		unset( $list[ $hash ] );
		update_user_meta( $user_id, 'nm_app_tokens', $list );
		return 0;
	}
	if ( $used < $now - HOUR_IN_SECONDS ) {
		$list[ $hash ]['used'] = $now;
		update_user_meta( $user_id, 'nm_app_tokens', $list );
	}
	return $user_id;
}

/**
 * Signs the request in from its app token, on app/ routes only, and only when
 * nothing else signed it in. A token that doesn't check out leaves it signed out
 * and is remembered, so app routes can answer "sign in again" (401 app_token_invalid)
 * instead of quietly treating the phone as a visitor.
 */
function ner_michoel_app_determine_user( $user_id ) {
	if ( $user_id || ! ner_michoel_app_is_app_request() ) {
		return $user_id;
	}
	$token = ner_michoel_app_request_token();
	if ( '' === $token ) {
		if ( ! empty( $_SERVER['HTTP_X_NM_APP_TOKEN'] ) || ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$GLOBALS['nm_app_token_bad'] = true;
		}
		return $user_id;
	}
	$found = ner_michoel_app_token_user( $token );
	if ( ! $found ) {
		$GLOBALS['nm_app_token_bad'] = true;
		return $user_id;
	}
	$GLOBALS['nm_app_token'] = $token;
	return $found;
}
add_filter( 'determine_current_user', 'ner_michoel_app_determine_user', 30 );

/** permission_callback of the routes that need a signed-in phone. */
function ner_michoel_app_require_token() {
	if ( ! empty( $GLOBALS['nm_app_token'] ) && is_user_logged_in() ) {
		return true;
	}
	return new WP_Error(
		'app_token_invalid',
		__( 'Please sign in again.', 'ner-michoel-core' ),
		array( 'status' => 401 )
	);
}

/** A changed or reset password ends the app sign-ins too, as it ends remembered computers. */
function ner_michoel_app_forget_after_reset( $user ) {
	if ( $user instanceof WP_User ) {
		ner_michoel_app_tokens_forget( $user->ID );
	}
}
add_action( 'after_password_reset', 'ner_michoel_app_forget_after_reset' );

function ner_michoel_app_forget_after_update( $user_id, $old_user_data ) {
	$user = get_userdata( $user_id );
	if ( $user && $old_user_data instanceof WP_User && $old_user_data->user_pass !== $user->user_pass ) {
		ner_michoel_app_tokens_forget( $user_id );
	}
}
add_action( 'profile_update', 'ner_michoel_app_forget_after_update', 10, 2 );

/** The Account page's "Forget remembered computers" forgets the phones as well. */
function ner_michoel_app_forget_with_computers( $response, $handler, $request ) {
	if ( '/ner-michoel/v1/account-forget-computers' === $request->get_route()
		&& $response instanceof WP_REST_Response && 200 === $response->get_status() && is_user_logged_in() ) {
		ner_michoel_app_tokens_forget( get_current_user_id() );
	}
	return $response;
}
add_filter( 'rest_request_after_callbacks', 'ner_michoel_app_forget_with_computers', 10, 3 );

/* ------------------------------------------------------------------
 * Shiurim as the app reads them
 * ------------------------------------------------------------------ */

/** A published shiur or written shiur, or null. Never alumni_shiur, drafts or password pages. */
function ner_michoel_app_public_post( $post_id ) {
	$post = get_post( (int) $post_id );
	if ( ! $post || ! in_array( $post->post_type, array( 'shiur', 'written_shiur' ), true ) ) {
		return null;
	}
	if ( 'publish' !== $post->post_status || '' !== $post->post_password ) {
		return null;
	}
	return $post;
}

/** The first term of a taxonomy as array( id, name ), or null. */
function ner_michoel_app_first_term( $post_id, $taxonomy ) {
	$terms = get_the_terms( $post_id, $taxonomy );
	if ( ! $terms || is_wp_error( $terms ) ) {
		return null;
	}
	return array(
		'id'   => (int) $terms[0]->term_id,
		'name' => html_entity_decode( $terms[0]->name, ENT_QUOTES, 'UTF-8' ),
	);
}

/** "a" audio, "v" video, "w" written: the same letters as the library list. */
function ner_michoel_app_kind( WP_Post $post ) {
	if ( 'written_shiur' === $post->post_type ) {
		return 'w';
	}
	$type = function_exists( 'ner_michoel_get_shiur_media_type' ) ? ner_michoel_get_shiur_media_type( $post->ID ) : 'audio';
	return in_array( $type, array( 'video', 'video-embed' ), true ) ? 'v' : 'a';
}

/** The post's date as a Hebrew date, "19 Cheshvan 5787", or ''. */
function ner_michoel_app_hebrew_date( WP_Post $post ) {
	if ( ! function_exists( 'ner_michoel_hebrew_date_label' ) || ! function_exists( 'ner_michoel_fixed_from_gregorian' ) ) {
		return '';
	}
	$time = strtotime( $post->post_date );
	if ( ! $time ) {
		return '';
	}
	return ner_michoel_hebrew_date_label( ner_michoel_fixed_from_gregorian( (int) gmdate( 'Y', $time ), (int) gmdate( 'n', $time ), (int) gmdate( 'j', $time ) ) );
}

/** One shiur for a list: what a row shows. */
function ner_michoel_app_row( WP_Post $post ) {
	return array(
		'id'       => (int) $post->ID,
		'kind'     => ner_michoel_app_kind( $post ),
		'title'    => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
		'date'     => mysql2date( 'Y-m-d', $post->post_date, false ),
		'speaker'  => ner_michoel_app_first_term( $post->ID, 'speaker' ),
		'series'   => ner_michoel_app_first_term( $post->ID, 'series' ),
		'duration' => 'shiur' === $post->post_type && function_exists( 'ner_michoel_get_shiur_duration' ) ? ner_michoel_get_shiur_duration( $post->ID ) : '',
	);
}

/**
 * A media file as the app needs it: its address (Bunny, or wherever the site serves
 * it), the site's backup route for it (media.php, for a phone whose filter blocks
 * Bunny; '' when the file isn't on Bunny or the route can't take it), its size in
 * bytes when known (0 when not), and its type. Null when there's no file.
 */
function ner_michoel_app_file( $attachment_id, $url ) {
	$attachment_id = (int) $attachment_id;
	if ( ! $attachment_id || '' === (string) $url ) {
		return null;
	}

	$relay = '';
	if ( function_exists( 'ner_michoel_bunny_storage_is_configured' ) && ner_michoel_bunny_storage_is_configured()
		&& function_exists( 'ner_michoel_relay_clean_path' ) && function_exists( 'ner_michoel_bunny_remote_path_for_attachment' ) ) {
		$path = ner_michoel_relay_clean_path( ner_michoel_bunny_remote_path_for_attachment( $attachment_id ) );
		if ( '' !== $path ) {
			$relay = add_query_arg( 'p', rawurlencode( $path ), NER_MICHOEL_CORE_URL . 'media.php' );
		}
	}

	$meta = wp_get_attachment_metadata( $attachment_id );
	$size = is_array( $meta ) && ! empty( $meta['filesize'] ) ? (int) $meta['filesize'] : 0;

	return array(
		'url'   => esc_url_raw( $url ),
		'relay' => $relay,
		'size'  => $size,
		'mime'  => (string) get_post_mime_type( $attachment_id ),
	);
}

/** One shiur in full: the row plus everything its page shows. */
function ner_michoel_app_shiur_full( WP_Post $post ) {
	$out = ner_michoel_app_row( $post );

	$out['hebrew_date'] = ner_michoel_app_hebrew_date( $post );
	$out['link']        = get_permalink( $post );
	$out['art']         = function_exists( 'ner_michoel_get_shiur_art_url' ) ? ner_michoel_get_shiur_art_url( $post, 'medium' ) : '';
	$out['dedication']  = function_exists( 'ner_michoel_get_shiur_dedication' ) ? ner_michoel_get_shiur_dedication( $post->ID ) : '';
	$out['audio']       = null;
	$out['video']       = null;
	$out['pdf']         = null;

	if ( 'written_shiur' === $post->post_type ) {
		$pdf_id = function_exists( 'ner_michoel_get_written_shiur_pdf_id' ) ? ner_michoel_get_written_shiur_pdf_id( $post->ID ) : 0;
		if ( $pdf_id ) {
			$out['pdf'] = ner_michoel_app_file( $pdf_id, ner_michoel_get_written_shiur_pdf_url( $post->ID ) );
		}
		$out['summary'] = wp_strip_all_tags( get_the_excerpt( $post ) );
	} else {
		$file_id = (int) get_post_meta( $post->ID, '_shiur_audio_id', true );
		$vimeo   = ner_michoel_get_shiur_vimeo_id( $post->ID );
		// A shiur can have an audio file and a Vimeo video both (one post for both, 2026-11-11).
		$is_video_file = $file_id && wp_attachment_is( 'video', $file_id );
		if ( $file_id && ! $is_video_file ) {
			$out['audio'] = ner_michoel_app_file( $file_id, ner_michoel_get_shiur_audio_url( $post->ID ) );
		}
		$embed = ( '' !== $vimeo && function_exists( 'ner_michoel_shiur_video_embed' ) ) ? ner_michoel_shiur_video_embed( $vimeo ) : null;
		if ( '' !== $vimeo ) {
			$out['video'] = array(
				'vimeo_id' => ( $embed && 'vimeo' === $embed['type'] ) ? $vimeo : '',
				'embed'    => ( $embed && 'vimeo' === $embed['type'] ) ? $embed['src'] : '',
				'link'     => ( $embed && 'link' === $embed['type'] ) ? $embed['url'] : '',
				'file'     => null,
			);
		} elseif ( $is_video_file ) {
			$out['video'] = array(
				'vimeo_id' => '',
				'embed'    => '',
				'link'     => '',
				'file'     => ner_michoel_app_file( $file_id, ner_michoel_get_shiur_video_url( $post->ID ) ),
			);
		}
		// A public audio shiur whose speaker's videos are for alumni: the video is left out, as on its page.
		if ( $out['audio'] && $out['video'] && function_exists( 'ner_michoel_alumni_video_hidden' ) && ner_michoel_alumni_video_hidden( $post->ID ) ) {
			$out['video']             = null;
			$out['video_alumni_only'] = true;
		}
	}

	// The same shiur in its other formats (audio and written, say): id per kind letter.
	$out['formats'] = array();
	if ( function_exists( 'ner_michoel_shiur_formats' ) ) {
		$letters = array(
			'a' => 'a',
			'v' => 'v',
			'w' => 'w',
		);
		foreach ( ner_michoel_shiur_formats( $post->ID, true ) as $kind => $id ) {
			if ( isset( $letters[ $kind ] ) ) {
				$out['formats'][ $letters[ $kind ] ] = (int) $id;
			}
		}
	}

	$topics        = get_the_terms( $post->ID, 'topic' );
	$out['topics'] = ( $topics && ! is_wp_error( $topics ) ) ? array_map( 'intval', wp_list_pluck( $topics, 'term_id' ) ) : array();

	return $out;
}

/* ------------------------------------------------------------------
 * Routes
 * ------------------------------------------------------------------ */

function ner_michoel_app_register_routes() {
	$ns     = 'ner-michoel/v1';
	$public = '__return_true';
	$signed = 'ner_michoel_app_require_token';

	register_rest_route( $ns, '/app/manifest', array( 'methods' => 'GET', 'callback' => 'ner_michoel_app_manifest_rest', 'permission_callback' => $public ) );
	register_rest_route( $ns, '/app/home', array( 'methods' => 'GET', 'callback' => 'ner_michoel_app_home_rest', 'permission_callback' => $public ) );
	register_rest_route( $ns, '/app/terms', array( 'methods' => 'GET', 'callback' => 'ner_michoel_app_terms_rest', 'permission_callback' => $public ) );
	register_rest_route( $ns, '/app/shiur/(?P<id>\d+)', array( 'methods' => 'GET', 'callback' => 'ner_michoel_app_shiur_rest', 'permission_callback' => $public ) );
	register_rest_route( $ns, '/app/login', array( 'methods' => 'POST', 'callback' => 'ner_michoel_app_login_rest', 'permission_callback' => $public ) );
	register_rest_route( $ns, '/app/login-code', array( 'methods' => 'POST', 'callback' => 'ner_michoel_app_login_code_rest', 'permission_callback' => $public ) );
	register_rest_route( $ns, '/app/events', array( 'methods' => 'POST', 'callback' => 'ner_michoel_app_events_rest', 'permission_callback' => $public ) );
	register_rest_route( $ns, '/app/logout', array( 'methods' => 'POST', 'callback' => 'ner_michoel_app_logout_rest', 'permission_callback' => $signed ) );
	register_rest_route( $ns, '/app/library', array( 'methods' => 'GET', 'callback' => 'ner_michoel_app_library_rest', 'permission_callback' => $signed ) );
	register_rest_route( $ns, '/app/saved', array( 'methods' => 'POST', 'callback' => 'ner_michoel_app_saved_rest', 'permission_callback' => $signed ) );
	register_rest_route( $ns, '/app/delete-account', array( 'methods' => 'POST', 'callback' => 'ner_michoel_app_delete_account_rest', 'permission_callback' => $signed ) );
}
add_action( 'rest_api_init', 'ner_michoel_app_register_routes' );

/** An answer that may be kept a short while by the phone and the host's cache. */
function ner_michoel_app_cached( $data, $seconds = 300 ) {
	$response = new WP_REST_Response( $data, 200 );
	$response->header( 'Cache-Control', 'public, max-age=' . (int) $seconds );
	return $response;
}

/** An answer that is this account's own: never cached. */
function ner_michoel_app_private( $data, $status = 200 ) {
	$response = new WP_REST_Response( $data, $status );
	$response->header( 'Cache-Control', 'private, no-store' );
	return $response;
}

function ner_michoel_app_error( $code, $message, $status = 400, array $extra = array() ) {
	return ner_michoel_app_private( array_merge( array( 'success' => false, 'code' => $code, 'message' => $message ), $extra ), $status );
}

function ner_michoel_app_manifest_rest() {
	$version = (int) get_option( defined( 'NER_MICHOEL_SHIUR_INDEX_VERSION_OPTION' ) ? NER_MICHOEL_SHIUR_INDEX_VERSION_OPTION : 'nm_shiur_index_version', 0 );
	$home    = function_exists( 'ner_michoel_home_banner_public' ) ? ner_michoel_home_banner_public() : array();

	$banner = isset( $home['banner'] ) && is_array( $home['banner'] ) ? $home['banner'] : null;
	if ( $banner ) {
		unset( $banner['place'] ); // The app shows it on Home wherever the website puts it.
	}

	return ner_michoel_app_cached(
		array(
			'api'       => NER_MICHOEL_APP_API_VERSION,
			'site'      => html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ),
			'index'     => array(
				'version' => $version,
				'url'     => function_exists( 'ner_michoel_shiur_index_url' ) ? ner_michoel_shiur_index_url() : '',
			),
			'banner'    => $banner,
			'live'      => isset( $home['zoom'] ) ? $home['zoom'] : null,
			'links'     => array(
				'website'    => home_url( '/' ),
				'account'    => home_url( '/account/' ),
				'contribute' => home_url( '/contribute/' ),
				'contact'    => home_url( '/contact/' ),
				'mazal_tov'  => home_url( '/mazal-tov/' ),
			),
		),
		300
	);
}

function ner_michoel_app_home_rest() {
	$week = null;
	if ( function_exists( 'ner_michoel_get_week_regions' ) ) {
		$regions = ner_michoel_get_week_regions( 10 );
		$pick    = isset( $regions['default'], $regions[ $regions['default'] ] ) ? $regions[ $regions['default'] ] : null;
		if ( is_array( $pick ) ) {
			$posts = array();
			foreach ( (array) $pick['posts'] as $p ) {
				if ( $p instanceof WP_Post && ner_michoel_app_public_post( $p->ID ) ) {
					$posts[] = ner_michoel_app_row( $p );
				}
			}
			$week = array(
				'title'  => $pick['title'],
				'date'   => $pick['date'],
				'hebrew' => $pick['hebrew'],
				'count'  => (int) $pick['count'],
				'link'   => $pick['link'],
				'shiurim' => $posts,
			);
		}
	}

	$new = array();
	foreach ( get_posts( array( 'post_type' => 'shiur', 'post_status' => 'publish', 'posts_per_page' => 15, 'orderby' => 'date', 'order' => 'DESC', 'has_password' => false, 'no_found_rows' => true ) ) as $p ) {
		$new[] = ner_michoel_app_row( $p );
	}

	$mazal = array();
	if ( post_type_exists( 'mazal_tov' ) ) {
		foreach ( get_posts( array( 'post_type' => 'mazal_tov', 'post_status' => 'publish', 'posts_per_page' => 10, 'orderby' => 'date', 'order' => 'DESC', 'has_password' => false, 'no_found_rows' => true ) ) as $p ) {
			$mazal[] = array(
				'id'    => (int) $p->ID,
				'title' => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
				'date'  => mysql2date( 'Y-m-d', $p->post_date, false ),
			);
		}
	}

	return ner_michoel_app_cached(
		array(
			'week'      => $week,
			'new'       => $new,
			'mazal_tov' => $mazal,
		),
		300
	);
}

function ner_michoel_app_terms_rest() {
	$speakers = array();
	foreach ( get_terms( array( 'taxonomy' => 'speaker', 'hide_empty' => true ) ) as $t ) {
		$speakers[] = array(
			'id'    => (int) $t->term_id,
			'name'  => html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ),
			'count' => (int) $t->count,
			'photo' => function_exists( 'ner_michoel_get_speaker_photo_url' ) ? ner_michoel_get_speaker_photo_url( $t->term_id ) : '',
		);
	}

	$series = array();
	foreach ( get_terms( array( 'taxonomy' => 'series', 'hide_empty' => false ) ) as $t ) {
		$series[] = array(
			'id'     => (int) $t->term_id,
			'name'   => html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ),
			'parent' => (int) $t->parent,
			'count'  => (int) $t->count,
			'order'  => function_exists( 'ner_michoel_series_admin_order' ) ? (int) ner_michoel_series_admin_order( $t->term_id ) : 0,
			'cover'  => function_exists( 'ner_michoel_get_series_cover_url' ) ? ner_michoel_get_series_cover_url( $t->term_id ) : '',
		);
	}

	$topics = array();
	if ( taxonomy_exists( 'topic' ) ) {
		foreach ( get_terms( array( 'taxonomy' => 'topic', 'hide_empty' => true ) ) as $t ) {
			$topics[] = array(
				'id'    => (int) $t->term_id,
				'name'  => html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ),
				'count' => (int) $t->count,
			);
		}
	}

	return ner_michoel_app_cached(
		array(
			'speakers'     => $speakers,
			'series'       => $series,
			'series_order' => function_exists( 'ner_michoel_series_order_is_custom' ) && ner_michoel_series_order_is_custom() ? 'custom' : 'automatic',
			'topics'       => $topics,
		),
		900
	);
}

function ner_michoel_app_shiur_rest( WP_REST_Request $request ) {
	$post = ner_michoel_app_public_post( (int) $request['id'] );
	if ( ! $post ) {
		return ner_michoel_app_error( 'not_found', __( 'That shiur is not on the site any more.', 'ner-michoel-core' ), 404 );
	}
	return ner_michoel_app_cached( ner_michoel_app_shiur_full( $post ), 300 );
}

/** What the phone calls itself in the account's list of signed-in phones. */
function ner_michoel_app_device_label( WP_REST_Request $request ) {
	$label = trim( sanitize_text_field( (string) $request->get_param( 'device' ) ) );
	return '' !== $label ? $label : __( 'Phone app', 'ner-michoel-core' );
}

/** The answer to a sign-in that worked: the token and who signed in. */
function ner_michoel_app_signed_in( WP_User $user, WP_REST_Request $request ) {
	return ner_michoel_app_private(
		array(
			'success' => true,
			'token'   => ner_michoel_app_token_issue( $user->ID, ner_michoel_app_device_label( $request ) ),
			'name'    => $user->display_name,
			'email'   => $user->user_email,
		)
	);
}

function ner_michoel_app_rate_limited() {
	return ner_michoel_app_error( 'rate_limited', __( 'Too many tries from your network. Please wait a while and try again.', 'ner-michoel-core' ), 429 );
}

/**
 * Email and password. Goes through WordPress's own sign-in checks and every filter
 * the site adds (too many tries, email not verified, alumni waiting, the emailed
 * code), without making a browser session. When the code is needed, the answer
 * carries the same short-lived "token" the Account page gets, for app/login-code.
 */
function ner_michoel_app_login_rest( WP_REST_Request $request ) {
	if ( function_exists( 'ner_michoel_login_code_ip_allowed' ) && ! ner_michoel_login_code_ip_allowed() ) {
		return ner_michoel_app_rate_limited();
	}

	$email    = sanitize_text_field( (string) $request->get_param( 'email' ) );
	$password = (string) $request->get_param( 'password' );
	if ( '' === $email || '' === $password ) {
		return ner_michoel_app_error( 'missing_fields', __( 'Please enter your email and password.', 'ner-michoel-core' ) );
	}

	$user = wp_authenticate( $email, $password );

	if ( is_wp_error( $user ) ) {
		switch ( $user->get_error_code() ) {
			case 'nm_login_code_needed':
				$data = (array) $user->get_error_data();
				return ner_michoel_app_private(
					array(
						'success'    => false,
						'login_code' => true,
						'code'       => 'login_code_required',
						'email'      => isset( $data['email'] ) ? $data['email'] : '',
						'email_hint' => isset( $data['email'] ) && function_exists( 'ner_michoel_login_code_mask_email' ) ? ner_michoel_login_code_mask_email( $data['email'] ) : '',
						'token'      => isset( $data['token'] ) ? $data['token'] : '',
						'status'     => isset( $data['status'] ) ? $data['status'] : 'failed',
						'resend_in'  => isset( $data['resend_in'] ) ? (int) $data['resend_in'] : 0,
						'expires_in' => defined( 'NER_MICHOEL_LOGIN_CODE_TTL' ) ? NER_MICHOEL_LOGIN_CODE_TTL : 600,
						'message'    => $user->get_error_message(),
					)
				);
			case 'nm_too_many_attempts':
				return ner_michoel_app_error( 'too_many_attempts', $user->get_error_message(), 429 );
			case 'nm_email_unverified':
				return ner_michoel_app_error( 'email_unverified', __( 'Please confirm your email first: open the link we emailed you, or sign in once on the website.', 'ner-michoel-core' ), 403 );
			case 'nm_alumni_pending':
				return ner_michoel_app_error( 'alumni_pending', $user->get_error_message(), 403 );
			default:
				// Same words whatever went wrong: no telling which emails have accounts.
				return ner_michoel_app_error( 'login_failed', __( 'Incorrect email or password.', 'ner-michoel-core' ), 401 );
		}
	}

	return ner_michoel_app_signed_in( $user, $request );
}

/** The emailed code, with the token from app/login. A right code gives the phone its app token. */
function ner_michoel_app_login_code_rest( WP_REST_Request $request ) {
	if ( ! function_exists( 'ner_michoel_login_code_verify' ) || ! function_exists( 'ner_michoel_login_code_find_session' ) ) {
		return ner_michoel_app_error( 'invalid_session', __( 'Please sign in again.', 'ner-michoel-core' ) );
	}
	if ( ! ner_michoel_login_code_ip_allowed() ) {
		return ner_michoel_app_rate_limited();
	}

	$email = strtolower( sanitize_email( (string) $request->get_param( 'email' ) ) );
	$user  = ner_michoel_login_code_find_session( $email, (string) $request->get_param( 'token' ) );
	if ( ! $user ) {
		return ner_michoel_app_error( 'invalid_session', __( 'That sign-in has ended. Please sign in with your email and password again, and we’ll send a new code.', 'ner-michoel-core' ) );
	}

	$result = ner_michoel_login_code_verify( $user->ID, (string) $request->get_param( 'code' ) );
	if ( 'ok' !== $result['status'] ) {
		$extra = array( 'resend_in' => ner_michoel_login_code_wait( $user->ID ) );
		if ( 'wrong' === $result['status'] ) {
			ner_michoel_rate_bump( 'login_fail', ner_michoel_client_ip(), 15 * MINUTE_IN_SECONDS );
			$extra['attempts_left'] = $result['left'];
		} elseif ( 'locked' === $result['status'] ) {
			$extra['attempts_left'] = 0;
		}
		$codes = array(
			'bad_format' => 'bad_format',
			'expired'    => 'expired',
			'locked'     => 'locked',
			'wrong'      => 'wrong_code',
		);
		return ner_michoel_app_error( $codes[ $result['status'] ], ner_michoel_login_code_verify_message( $result ), 400, $extra );
	}

	return ner_michoel_app_signed_in( $user, $request );
}

function ner_michoel_app_logout_rest() {
	$token = isset( $GLOBALS['nm_app_token'] ) ? (string) $GLOBALS['nm_app_token'] : '';
	if ( preg_match( '/^nma_(\d{1,12})_([a-f0-9]{64})$/', $token, $m ) ) {
		$list = ner_michoel_app_tokens( (int) $m[1] );
		unset( $list[ ner_michoel_app_token_hash( $m[2] ) ] );
		if ( $list ) {
			update_user_meta( (int) $m[1], 'nm_app_tokens', $list );
		} else {
			delete_user_meta( (int) $m[1], 'nm_app_tokens' );
		}
	}
	return ner_michoel_app_private( array( 'success' => true ) );
}

function ner_michoel_app_library_rest() {
	$user_id = get_current_user_id();
	$saved   = array();
	foreach ( ner_michoel_get_user_saved( $user_id ) as $row ) {
		$saved[] = array(
			'id'   => (int) $row->post_id,
			'kind' => 'written_shiur' === $row->post_type ? 'w' : 's',
			'at'   => mysql2date( 'c', $row->saved_at, false ),
		);
	}
	$history = array();
	foreach ( ner_michoel_get_user_history( $user_id, 200 ) as $row ) {
		$history[] = array(
			'id'   => (int) $row->post_id,
			'kind' => 'written_shiur' === $row->post_type ? 'w' : 's',
			'at'   => mysql2date( 'c', $row->viewed_at, false ),
		);
	}
	$user = wp_get_current_user();
	return ner_michoel_app_private(
		array(
			'name'    => $user->display_name,
			'email'   => $user->user_email,
			'saved'   => $saved,
			'history' => $history,
		)
	);
}

/** Sets one item saved (saved=1) or not (saved=0). */
function ner_michoel_app_saved_rest( WP_REST_Request $request ) {
	$post = ner_michoel_app_public_post( absint( $request->get_param( 'post_id' ) ) );
	if ( ! $post ) {
		return ner_michoel_app_error( 'not_found', __( 'That shiur is not on the site any more.', 'ner-michoel-core' ), 404 );
	}
	$want    = rest_sanitize_boolean( $request->get_param( 'saved' ) );
	$user_id = get_current_user_id();
	if ( ner_michoel_is_post_saved_by_user( $post->ID, $user_id ) !== $want ) {
		ner_michoel_toggle_saved( $user_id, $post->ID );
	}
	return ner_michoel_app_private( array( 'success' => true, 'saved' => $want ) );
}

/**
 * Plays, completes and reads: events = [ { post_id, event: play|complete|read }, … ],
 * at most 50. A play or complete counts exactly as the website player's does
 * (shiur-stats.php: one per network per shiur per 20 seconds) and a signed-in play
 * goes in History; a read (a written shiur opened) goes in History.
 */
function ner_michoel_app_events_rest( WP_REST_Request $request ) {
	$events = $request->get_param( 'events' );
	if ( ! is_array( $events ) ) {
		return ner_michoel_app_error( 'missing_events', __( 'No events were sent.', 'ner-michoel-core' ) );
	}
	$events   = array_slice( $events, 0, 50 );
	$recorded = 0;
	foreach ( $events as $event ) {
		if ( ! is_array( $event ) || empty( $event['post_id'] ) || empty( $event['event'] ) ) {
			continue;
		}
		$post = ner_michoel_app_public_post( absint( $event['post_id'] ) );
		$kind = (string) $event['event'];
		if ( ! $post ) {
			continue;
		}
		if ( 'read' === $kind && 'written_shiur' === $post->post_type ) {
			if ( is_user_logged_in() ) {
				ner_michoel_record_history( get_current_user_id(), $post->ID );
			}
			++$recorded;
		} elseif ( in_array( $kind, array( 'play', 'complete' ), true ) && 'shiur' === $post->post_type ) {
			$inner = new WP_REST_Request( 'POST', '/ner-michoel/v1/shiur-event' );
			$inner->set_param( 'post_id', $post->ID );
			$inner->set_param( 'event', $kind );
			ner_michoel_handle_shiur_event( $inner );
			++$recorded;
		}
	}
	return ner_michoel_app_private( array( 'success' => true, 'recorded' => $recorded ) );
}

/**
 * Deletes the signed-in account, its Saved list and History. Needs confirm=DELETE.
 * Staff accounts (anyone who can edit posts) are not deleted from the app: that is
 * done in wp-admin, so a lost phone can never remove an editor.
 */
function ner_michoel_app_delete_account_rest( WP_REST_Request $request ) {
	if ( 'DELETE' !== (string) $request->get_param( 'confirm' ) ) {
		return ner_michoel_app_error( 'confirm_needed', __( 'Please confirm that you want to delete your account.', 'ner-michoel-core' ) );
	}
	$user_id = get_current_user_id();
	if ( user_can( $user_id, 'edit_posts' ) ) {
		return ner_michoel_app_error( 'staff_account', __( 'Staff accounts can’t be deleted from the app. Please ask the site’s administrator.', 'ner-michoel-core' ), 403 );
	}

	global $wpdb;
	foreach ( array( ner_michoel_history_table_name(), ner_michoel_saved_table_name() ) as $table ) {
		$wpdb->delete( $table, array( 'user_id' => $user_id ), array( '%d' ) );
	}

	require_once ABSPATH . 'wp-admin/includes/user.php';
	ner_michoel_app_tokens_forget( $user_id );
	wp_delete_user( $user_id );

	return ner_michoel_app_private( array( 'success' => true ) );
}
