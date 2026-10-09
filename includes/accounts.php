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
 * New accounts are verified by email (account-verification.php): sign-up
 * creates the account switched off and emails a 6-digit code, and
 * account-verify turns it on and signs the person in.
 *
 * Nonces: register/login/forgot-password have no prior session to
 * protect, so they're public (permission_callback => '__return_true').
 * They're guarded instead by the bot check (form-guard.php): the browser
 * check, a rate limit and Turnstile on each, the login lockout, and a cap on
 * reset emails to any one address.
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
		'/account-verify',
		array(
			'methods'             => 'POST',
			'callback'            => 'ner_michoel_handle_account_verify',
			'permission_callback' => '__return_true',
		)
	);

	register_rest_route(
		'ner-michoel/v1',
		'/account-resend-code',
		array(
			'methods'             => 'POST',
			'callback'            => 'ner_michoel_handle_account_resend_code',
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
 * Sign up: name, email (which is also the username) and password. Creates a
 * `subscriber`.
 *
 * With email verification on (account-verification.php, once the theme has the
 * code step) the account is created switched off, a 6-digit code is emailed, and
 * the reply says so; nobody is signed in until the code is entered. Without it
 * (an older theme), they're signed straight in, as before.
 */
function ner_michoel_handle_account_register( WP_REST_Request $request ) {
	// Honeypot: same off-screen-field trick as the contact form
	// (nm_contact_hp in forms.php) — real visitors never see or reach
	// it, so anything filled in here is a bot. Reported as success with
	// no account created, rather than an error that would teach it to
	// adapt.
	if ( ! empty( $request->get_param( 'website' ) ) ) {
		ner_michoel_form_guard_log( 'honeypot' );
		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	$guard = ner_michoel_form_guard_verify( 'signup', $request->get_params() );
	if ( true !== $guard ) {
		return ner_michoel_account_guard_error( $guard );
	}

	$name     = sanitize_text_field( (string) $request->get_param( 'name' ) );
	$email    = strtolower( sanitize_email( (string) $request->get_param( 'email' ) ) );
	$password = (string) $request->get_param( 'password' );
	$verify   = ner_michoel_email_verification_enabled();

	if ( '' === $name ) {
		return ner_michoel_account_error( 'missing_name', __( 'Please enter your name.', 'ner-michoel-core' ) );
	}
	if ( ! is_email( $email ) ) {
		return ner_michoel_account_error( 'invalid_email', __( 'Please enter a valid email address.', 'ner-michoel-core' ) );
	}

	// An address that signed up but never entered its code can sign up again:
	// the newest sign-up (new password, new code) replaces the old one. An
	// address with a confirmed account can't.
	$pending  = false;
	$existing = get_user_by( 'email', $email );
	if ( $existing ) {
		if ( ! $verify || ! ner_michoel_user_is_unverified( $existing->ID ) ) {
			return ner_michoel_account_error( 'email_taken', __( 'An account with this email already exists. Try logging in instead.', 'ner-michoel-core' ) );
		}
		$pending = $existing;
	}
	if ( strlen( $password ) < 8 ) {
		return ner_michoel_account_error( 'weak_password', __( 'Password must be at least 8 characters.', 'ner-michoel-core' ) );
	}

	// Two separate, optional choices made at sign-up: general updates, and
	// alerts when new shiurim are posted. Kept as separate flags so either
	// can be honoured on its own later. Sending is not built yet.
	$prefs = array(
		'updates' => $request->get_param( 'pref_updates' ) ? 1 : 0,
		'alerts'  => $request->get_param( 'pref_new_shiur_alerts' ) ? 1 : 0,
	);

	if ( $pending ) {
		$user_id = $pending->ID;
		wp_set_password( $password, $user_id ); // No "password changed" email, and any old session is cleared.
		wp_update_user(
			array(
				'ID'           => $user_id,
				'display_name' => $name,
				'first_name'   => $name,
			)
		);
		update_user_meta( $user_id, 'nm_verify_prefs', $prefs );
	} else {
		$userdata = array(
			'user_login'    => ner_michoel_generate_username( $email ),
			'user_nicename' => ner_michoel_generate_nicename( $name ),
			'user_email'    => $email,
			'user_pass'     => $password,
			'display_name'  => $name,
			'first_name'    => $name,
			'role'          => 'subscriber',
		);
		if ( $verify ) {
			$userdata['meta_input'] = ner_michoel_verification_initial_meta( $prefs ); // On from the first moment: never an account without it.
		}
		$user_id = wp_insert_user( $userdata );

		if ( is_wp_error( $user_id ) ) {
			return ner_michoel_account_error( 'register_failed', $user_id->get_error_message() );
		}
	}

	if ( $verify ) {
		$begin = ner_michoel_verification_begin( $user_id );
		return new WP_REST_Response(
			array(
				'success'    => true,
				'verify'     => true,
				'email'      => $email,
				'token'      => $begin['token'],
				'status'     => $begin['status'],
				'resend_in'  => $begin['wait'],
				'expires_in' => NER_MICHOEL_VERIFY_CODE_TTL,
				'message'    => ner_michoel_verification_message( 'signup', $begin['status'], $email ),
			),
			200
		);
	}

	update_user_meta( $user_id, 'nm_pref_updates', $prefs['updates'] );
	update_user_meta( $user_id, 'nm_pref_new_shiur_alerts', $prefs['alerts'] );

	ner_michoel_sign_in_user( $user_id );

	return new WP_REST_Response( array( 'success' => true, 'name' => $name ), 200 );
}

/**
 * The email address is the username: it's what the person types to sign up
 * and to log in, and what wp-admin's Users screen shows. (WordPress accepts it
 * as the login name, and wp_authenticate_username_password() takes either.)
 *
 * Falls back to the part before the @, numbered if it's taken, when the address
 * can't be a login name as it stands: over WordPress's 60 characters, a
 * character WordPress strips from login names (a "+", say) that would make it
 * collide with another account, or a clash with someone else's login or email.
 */
function ner_michoel_generate_username( $email ) {
	$email = strtolower( $email );
	$whole = sanitize_user( $email, true );

	if ( '' !== $whole && strlen( $whole ) <= 60 && ! username_exists( $whole ) ) {
		$owner = email_exists( $whole ); // Someone else's address as this login would make that person's login ambiguous.
		if ( ! $owner || $whole === $email ) {
			return $whole;
		}
	}

	$base = sanitize_user( current( explode( '@', $email ) ), true );
	if ( '' === $base ) {
		$base = 'member';
	}
	$base = substr( $base, 0, 50 );

	$username = $base;
	$suffix   = 1;
	while ( username_exists( $username ) ) {
		++$suffix;
		$username = $base . $suffix;
	}

	return $username;
}

/**
 * The URL-safe name WordPress keeps beside the login (it's in the address of an
 * author page). From the person's name, so an email address never appears
 * there; WordPress numbers it if another account has the same.
 */
function ner_michoel_generate_nicename( $name ) {
	$nicename = sanitize_title( $name );
	return '' === $nicename ? 'member' : substr( $nicename, 0, 50 );
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
	$guard = ner_michoel_form_guard_verify( 'login', $request->get_params() );
	if ( true !== $guard ) {
		return ner_michoel_account_guard_error( $guard );
	}

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

	if ( is_wp_error( $user ) && 'nm_too_many_attempts' === $user->get_error_code() ) {
		return ner_michoel_account_error( 'too_many_attempts', $user->get_error_message(), 429 );
	}
	if ( is_wp_error( $user ) && 'nm_email_unverified' === $user->get_error_code() ) {
		return ner_michoel_account_verification_required( $email );
	}
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
	$guard = ner_michoel_form_guard_verify( 'forgot', $request->get_params() );
	if ( true !== $guard ) {
		return ner_michoel_account_guard_error( $guard );
	}

	$email = sanitize_email( (string) $request->get_param( 'email' ) );

	// At most three reset emails an hour to one address, however many
	// networks ask: the form can't be used to flood someone's inbox.
	if ( is_email( $email ) && email_exists( $email ) ) {
		if ( ner_michoel_rate_count( 'forgot_email', strtolower( $email ) ) >= 3 ) {
			ner_michoel_form_guard_log( 'email_limited' );
		} else {
			ner_michoel_rate_bump( 'forgot_email', strtolower( $email ), HOUR_IN_SECONDS );
			retrieve_password( $email );
		}
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

/**
 * They gave the right password for an account whose email isn't confirmed yet
 * (its login is refused: account-verification.php). Sends them to the code step,
 * with a new code if one may be sent now. Only reached with the right password,
 * so it tells nobody else that the account exists.
 */
function ner_michoel_account_verification_required( $login ) {
	$user = is_email( $login ) ? get_user_by( 'email', $login ) : get_user_by( 'login', $login );
	if ( ! $user || ! ner_michoel_user_is_unverified( $user->ID ) ) {
		return ner_michoel_account_error( 'login_failed', __( 'Incorrect email or password.', 'ner-michoel-core' ) );
	}

	if ( ! ner_michoel_email_verification_enabled() ) {
		// A theme without the code step: a reset link does the same job.
		return ner_michoel_account_error( 'unverified', __( 'Your email address isn’t confirmed yet. Use “Forgot password?” and we’ll email you a link that confirms it.', 'ner-michoel-core' ) );
	}

	$begin = ner_michoel_verification_begin( $user->ID );
	return new WP_REST_Response(
		array(
			'success'    => false,
			'verify'     => true,
			'code'       => 'verification_required',
			'email'      => strtolower( $user->user_email ),
			'token'      => $begin['token'],
			'status'     => $begin['status'],
			'resend_in'  => $begin['wait'],
			'expires_in' => NER_MICHOEL_VERIFY_CODE_TTL,
			'message'    => ner_michoel_verification_message( 'login', $begin['status'], $user->user_email ),
		),
		200
	);
}

function ner_michoel_account_error( $code, $message, $status = 400, array $extra = array() ) {
	return new WP_REST_Response( array_merge( array( 'success' => false, 'code' => $code, 'message' => $message ), $extra ), $status );
}

/**
 * The bot check turned a logged-out form away (form-guard.php): what the
 * person sees. account.js gets a fresh check ready, so trying again works.
 */
function ner_michoel_account_guard_error( $reason ) {
	if ( 'rate_limited' === $reason ) {
		return ner_michoel_account_error( 'rate_limited', __( 'Too many tries from your network. Please wait a while and try again.', 'ner-michoel-core' ), 429 );
	}
	return ner_michoel_account_error( 'verify_failed', __( 'We couldn’t confirm you’re a person. Please try again.', 'ner-michoel-core' ) );
}
