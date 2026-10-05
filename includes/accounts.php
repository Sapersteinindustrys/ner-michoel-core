<?php
/**
 * Front-end accounts: sign up, log in, forgot password, and a profile
 * (name + photo + password) — ordinary WordPress users under the
 * built-in `subscriber` role, not a parallel auth system. That keeps
 * every WP primitive working for free: wp_signon()/wp_set_auth_cookie()
 * for sessions, is_user_logged_in()/current_user_can() for any future
 * gating, retrieve_password() for reset emails, and the native
 * wp-login.php reset-password step (secure, already built, not worth
 * reimplementing).
 *
 * Rendered by ner-michoel-child (page-templates/account.php). Every
 * route below is read by that page's assets/js/account.js.
 *
 * Nonces: register/login/forgot-password have no prior session to
 * protect, so they're public (permission_callback => '__return_true').
 * update-profile and avatar require is_user_logged_in(); WordPress's
 * own REST cookie-auth middleware (rest_cookie_check_errors(), always
 * active) already rejects those with a bad/missing X-WP-Nonce header
 * before this file's callbacks ever run — nothing extra to check here.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NER_MICHOEL_AVATAR_META_KEY', '_nm_avatar_id' );

/**
 * Logged-in visitors with no admin-side capability (i.e. plain
 * accounts, not staff) never see the dashboard or the front-end admin
 * bar — both are confusing clutter for someone who just wants their
 * profile, and wp-admin's menu is entirely staff tooling they have no
 * use for. Real staff accounts (edit_posts and up) are untouched.
 */
function ner_michoel_hide_admin_bar_for_members( $show ) {
	if ( is_user_logged_in() && ! current_user_can( 'edit_posts' ) ) {
		return false;
	}
	return $show;
}
add_filter( 'show_admin_bar', 'ner_michoel_hide_admin_bar_for_members' );

function ner_michoel_redirect_members_out_of_admin() {
	if ( ! is_admin() || wp_doing_ajax() || current_user_can( 'edit_posts' ) ) {
		return;
	}
	if ( ! is_user_logged_in() ) {
		return; // wp-admin's own auth redirect to wp-login.php handles this.
	}
	wp_safe_redirect( home_url( '/account/' ) );
	exit;
}
add_action( 'admin_init', 'ner_michoel_redirect_members_out_of_admin' );

/**
 * REST routes.
 */
function ner_michoel_register_account_routes() {
	register_rest_route(
		'ner-michoel/v1',
		'/account-register',
		array(
			'methods'             => 'POST',
			'callback'            => 'ner_michoel_handle_account_register',
			'permission_callback' => '__return_true',
		)
	);

	register_rest_route(
		'ner-michoel/v1',
		'/account-login',
		array(
			'methods'             => 'POST',
			'callback'            => 'ner_michoel_handle_account_login',
			'permission_callback' => '__return_true',
		)
	);

	register_rest_route(
		'ner-michoel/v1',
		'/account-forgot-password',
		array(
			'methods'             => 'POST',
			'callback'            => 'ner_michoel_handle_account_forgot_password',
			'permission_callback' => '__return_true',
		)
	);

	register_rest_route(
		'ner-michoel/v1',
		'/account-update-profile',
		array(
			'methods'             => 'POST',
			'callback'            => 'ner_michoel_handle_account_update_profile',
			'permission_callback' => function () {
				return is_user_logged_in();
			},
		)
	);

	register_rest_route(
		'ner-michoel/v1',
		'/account-avatar',
		array(
			'methods'             => 'POST',
			'callback'            => 'ner_michoel_handle_account_avatar',
			'permission_callback' => function () {
				return is_user_logged_in();
			},
		)
	);
}
add_action( 'rest_api_init', 'ner_michoel_register_account_routes' );

/**
 * Sign up: name, email, password. Creates a `subscriber`, then signs
 * them straight in — an extra "now go log in" step buys nothing here.
 */
function ner_michoel_handle_account_register( WP_REST_Request $request ) {
	// Honeypot: same off-screen-field trick as the contact form
	// (nm_contact_hp in forms.php) — real visitors never see or reach
	// it, so anything filled in here is a bot. Reported as success with
	// no account created, rather than an error that would teach it to
	// adapt.
	if ( ! empty( $request->get_param( 'website' ) ) ) {
		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	$name     = sanitize_text_field( (string) $request->get_param( 'name' ) );
	$email    = sanitize_email( (string) $request->get_param( 'email' ) );
	$password = (string) $request->get_param( 'password' );

	if ( '' === $name ) {
		return ner_michoel_account_error( 'missing_name', __( 'Please enter your name.', 'ner-michoel-core' ) );
	}
	if ( ! is_email( $email ) ) {
		return ner_michoel_account_error( 'invalid_email', __( 'Please enter a valid email address.', 'ner-michoel-core' ) );
	}
	if ( email_exists( $email ) ) {
		return ner_michoel_account_error( 'email_taken', __( 'An account with this email already exists. Try logging in instead.', 'ner-michoel-core' ) );
	}
	if ( strlen( $password ) < 8 ) {
		return ner_michoel_account_error( 'weak_password', __( 'Password must be at least 8 characters.', 'ner-michoel-core' ) );
	}

	$user_id = wp_insert_user(
		array(
			'user_login'   => ner_michoel_generate_username( $email ),
			'user_email'   => $email,
			'user_pass'    => $password,
			'display_name' => $name,
			'first_name'   => $name,
			'role'         => 'subscriber',
		)
	);

	if ( is_wp_error( $user_id ) ) {
		return ner_michoel_account_error( 'register_failed', $user_id->get_error_message() );
	}

	ner_michoel_sign_in_user( $user_id );

	return new WP_REST_Response( array( 'success' => true, 'name' => $name ), 200 );
}

/**
 * A free-text username field is one more thing to fill in and one more
 * thing to forget — the email address already uniquely identifies the
 * account (wp_authenticate_username_password() accepts either), so the
 * login name is derived from it instead and never shown to the user.
 */
function ner_michoel_generate_username( $email ) {
	$base = sanitize_user( current( explode( '@', $email ) ), true );
	if ( '' === $base ) {
		$base = 'member';
	}

	$username = $base;
	$suffix   = 1;
	while ( username_exists( $username ) ) {
		++$suffix;
		$username = $base . $suffix;
	}

	return $username;
}

function ner_michoel_sign_in_user( $user_id ) {
	wp_set_current_user( $user_id );
	wp_set_auth_cookie( $user_id, true ); // true: remembered, same as checking "Remember Me".
}

/**
 * Log in with email or username — wp_signon()/wp_authenticate() both
 * already accept either (an '@' in the login routes to get_user_by(
 * 'email', ... ) internally), so the form just has one "Email" field.
 */
function ner_michoel_handle_account_login( WP_REST_Request $request ) {
	$email    = sanitize_text_field( (string) $request->get_param( 'email' ) );
	$password = (string) $request->get_param( 'password' );

	if ( '' === $email || '' === $password ) {
		return ner_michoel_account_error( 'missing_fields', __( 'Please enter your email and password.', 'ner-michoel-core' ) );
	}

	$user = wp_signon(
		array(
			'user_login'    => $email,
			'user_password' => $password,
			'remember'      => true,
		),
		is_ssl()
	);

	if ( is_wp_error( $user ) ) {
		// Same message either way — confirming "that email isn't
		// registered" to an anonymous caller is a free account-enumeration
		// tool, and isn't something the visitor can act on differently.
		return ner_michoel_account_error( 'login_failed', __( 'Incorrect email or password.', 'ner-michoel-core' ) );
	}

	return new WP_REST_Response( array( 'success' => true, 'name' => $user->display_name ), 200 );
}

/**
 * Always reports success, whether or not the email is registered —
 * same account-enumeration reasoning as login. retrieve_password()
 * accepts an email directly (same email-or-login handling as login).
 */
function ner_michoel_handle_account_forgot_password( WP_REST_Request $request ) {
	$email = sanitize_email( (string) $request->get_param( 'email' ) );

	if ( is_email( $email ) && email_exists( $email ) ) {
		retrieve_password( $email );
	}

	return new WP_REST_Response(
		array(
			'success' => true,
			'message' => __( 'If that email has an account, a reset link is on its way.', 'ner-michoel-core' ),
		),
		200
	);
}

/**
 * Name and/or password, from the logged-in profile screen. A password
 * change requires the current password, same as any "change password"
 * screen — being logged in isn't by itself proof you're not someone
 * who grabbed an unattended, unlocked browser.
 */
function ner_michoel_handle_account_update_profile( WP_REST_Request $request ) {
	$user_id = get_current_user_id();
	$name    = $request->get_param( 'name' );
	$updates = array( 'ID' => $user_id );

	if ( null !== $name ) {
		$name = sanitize_text_field( (string) $name );
		if ( '' === $name ) {
			return ner_michoel_account_error( 'missing_name', __( 'Please enter your name.', 'ner-michoel-core' ) );
		}
		$updates['display_name'] = $name;
		$updates['first_name']   = $name;
	}

	$new_password     = (string) $request->get_param( 'new_password' );
	$current_password = (string) $request->get_param( 'current_password' );

	if ( '' !== $new_password ) {
		$user = get_userdata( $user_id );
		if ( ! $user || ! wp_check_password( $current_password, $user->user_pass, $user_id ) ) {
			return ner_michoel_account_error( 'wrong_current_password', __( 'Current password is incorrect.', 'ner-michoel-core' ) );
		}
		if ( strlen( $new_password ) < 8 ) {
			return ner_michoel_account_error( 'weak_password', __( 'New password must be at least 8 characters.', 'ner-michoel-core' ) );
		}
		$updates['user_pass'] = $new_password;
	}

	$result = wp_update_user( $updates );
	if ( is_wp_error( $result ) ) {
		return ner_michoel_account_error( 'update_failed', $result->get_error_message() );
	}

	// A password change invalidates every session (including this
	// one's cookie) — re-issue it so the visitor isn't logged out by
	// the very form that just confirmed their password.
	if ( '' !== $new_password ) {
		wp_clear_auth_cookie();
		ner_michoel_sign_in_user( $user_id );
	}

	$user = get_userdata( $user_id );
	return new WP_REST_Response( array( 'success' => true, 'name' => $user->display_name ), 200 );
}

/**
 * Profile photo: a direct file upload (not wp.media()'s library
 * picker — that browses every attachment on the site, meant for staff
 * curating content, not a visitor picking their own photo). Replaces
 * any previous photo rather than accumulating one per upload.
 */
function ner_michoel_handle_account_avatar( WP_REST_Request $request ) {
	$files = $request->get_file_params();
	if ( empty( $files['photo'] ) || UPLOAD_ERR_OK !== $files['photo']['error'] ) {
		return ner_michoel_account_error( 'no_file', __( 'Please choose a photo.', 'ner-michoel-core' ) );
	}

	$type = wp_check_filetype( $files['photo']['name'] );
	if ( ! in_array( $type['type'], array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ), true ) ) {
		return ner_michoel_account_error( 'bad_type', __( 'Please choose a JPG, PNG, GIF, or WebP image.', 'ner-michoel-core' ) );
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$user_id       = get_current_user_id();
	$attachment_id = media_handle_upload( 'photo', 0 );
	if ( is_wp_error( $attachment_id ) ) {
		return ner_michoel_account_error( 'upload_failed', $attachment_id->get_error_message() );
	}

	$previous = (int) get_user_meta( $user_id, NER_MICHOEL_AVATAR_META_KEY, true );
	update_user_meta( $user_id, NER_MICHOEL_AVATAR_META_KEY, $attachment_id );
	if ( $previous && $previous !== $attachment_id ) {
		wp_delete_attachment( $previous, true );
	}

	return new WP_REST_Response(
		array(
			'success'  => true,
			'avatarUrl' => wp_get_attachment_image_url( $attachment_id, 'thumbnail' ),
		),
		200
	);
}

/**
 * Serves the uploaded photo in place of Gravatar wherever WordPress
 * renders an avatar for this user (admin bar, comments, etc.) — not
 * just on the account page. Falls through to Gravatar/the default
 * untouched when no photo has been set.
 */
function ner_michoel_filter_avatar_url( $url, $id_or_email, $args ) {
	$user = false;
	if ( is_numeric( $id_or_email ) ) {
		$user = get_userdata( $id_or_email );
	} elseif ( is_object( $id_or_email ) && isset( $id_or_email->user_id ) ) {
		$user = get_userdata( $id_or_email->user_id );
	} elseif ( is_string( $id_or_email ) ) {
		$user = get_user_by( 'email', $id_or_email );
	}
	if ( ! $user ) {
		return $url;
	}

	$attachment_id = (int) get_user_meta( $user->ID, NER_MICHOEL_AVATAR_META_KEY, true );
	if ( ! $attachment_id ) {
		return $url;
	}

	$size  = isset( $args['size'] ) ? (int) $args['size'] : 96;
	$image = wp_get_attachment_image_src( $attachment_id, array( $size, $size ) );
	return $image ? $image[0] : $url;
}
add_filter( 'get_avatar_url', 'ner_michoel_filter_avatar_url', 10, 3 );

/**
 * Account info for the logged-in visitor, read by account.php on page
 * load (server-rendered, not fetched) — name, email, and photo URL.
 */
function ner_michoel_get_current_account() {
	if ( ! is_user_logged_in() ) {
		return null;
	}
	$user = wp_get_current_user();
	return array(
		'name'      => $user->display_name,
		'email'     => $user->user_email,
		'avatarUrl' => get_avatar_url( $user->ID, array( 'size' => 160 ) ),
	);
}

function ner_michoel_account_error( $code, $message ) {
	return new WP_REST_Response( array( 'success' => false, 'code' => $code, 'message' => $message ), 400 );
}
