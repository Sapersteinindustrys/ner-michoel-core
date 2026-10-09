<?php
/**
 * Email verification for new accounts.
 *
 * Sign up (accounts.php) asks for a name, an email address (which is also the
 * username) and a password. The account is created but stays switched off until
 * its owner shows they can read that mailbox: a 6-digit code is emailed, and
 * typing it into the Account page activates the account and signs them in.
 * Until then nobody can log in to it.
 *
 * How it holds together:
 *
 *   - A pending account is an ordinary subscriber with the user meta
 *     nm_email_unverified. One `authenticate` filter refuses every login to it
 *     (wp-login.php, wp_signon(), the Account page), so there is no way in
 *     without the code. Accounts that existed before this shipped have no flag
 *     and are untouched.
 *   - The code is 6 digits from random_int(), kept only as an HMAC, good for 30
 *     minutes, and used up by 5 wrong tries (a new code resets the tries).
 *   - Asking for a code is rate limited per account (one a minute, five an hour
 *     to one address), so the form can't be used to flood someone's inbox. A
 *     pending account also gets at most 10 codes in all, so at most 50 guesses
 *     over its whole life. After that, "Forgot password" (which also confirms the
 *     address) or an admin's "Confirm email" row action on the Users screen is
 *     the way in.
 *   - Tries and send slots are claimed with single UPDATE statements, not
 *     read-then-write, so a burst of parallel requests still gets exactly 5
 *     tries. A plain counter would let every request in the burst through.
 *   - Each sign-up (or login to a pending account) hands the browser a random
 *     token that must come back with the code. Signing up again for the same
 *     address replaces the password and the token, so the person who types the
 *     code is always the person who chose the password. Somebody else
 *     re-submitting a stranger's address can't get a verified account from it.
 *   - Resetting the password through the emailed link also verifies the
 *     account: it proves the same thing.
 *   - Pending accounts nobody finished are deleted after 7 days.
 *
 * It only switches on once the theme says it renders the code step
 * (add_theme_support( 'nm-email-verification' )), so the plugin and the theme
 * can be updated in either order without sign-up breaking: until then, sign-up
 * works as it did before.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Digits in the emailed code. */
define( 'NER_MICHOEL_VERIFY_CODE_LENGTH', 6 );

/** How long a code works. Long enough for slow mail, short enough to not linger in an inbox. */
define( 'NER_MICHOEL_VERIFY_CODE_TTL', 30 * MINUTE_IN_SECONDS );

/** Wrong tries one code allows. */
define( 'NER_MICHOEL_VERIFY_MAX_ATTEMPTS', 5 );

/** Seconds before another code can be sent to the same account. */
define( 'NER_MICHOEL_VERIFY_RESEND_COOLDOWN', 60 );

/** Codes one pending account can ever be sent (each gives 5 guesses). */
define( 'NER_MICHOEL_VERIFY_MAX_SENDS', 10 );

/** Codes emailed to one address in an hour, however they were asked for. */
define( 'NER_MICHOEL_VERIFY_EMAILS_PER_HOUR', 5 );

/** Verify and resend requests from one address in 15 minutes (a backstop; each account has its own limits). */
define( 'NER_MICHOEL_VERIFY_IP_LIMIT', 60 );

/** Pending accounts nobody finished are removed after this long. */
define( 'NER_MICHOEL_VERIFY_STALE_AFTER', 7 * DAY_IN_SECONDS );

/**
 * True once the theme renders the code step. Until then sign-up behaves as it
 * did before verification existed.
 */
function ner_michoel_email_verification_enabled() {
	return (bool) apply_filters( 'ner_michoel_email_verification_enabled', current_theme_supports( 'nm-email-verification' ) );
}

/* ------------------------------------------------------------------
 * A pending account.
 * ------------------------------------------------------------------ */

function ner_michoel_user_is_unverified( $user_id ) {
	return '1' === (string) get_user_meta( (int) $user_id, 'nm_email_unverified', true );
}

/**
 * The user meta a new pending account is created with. Passed to
 * wp_insert_user() as meta_input, so the account is never there without it.
 */
function ner_michoel_verification_initial_meta( array $prefs ) {
	return array(
		'nm_email_unverified' => '1',
		'nm_verify_prefs'     => $prefs,
		'nm_verify_attempts'  => 0,
		'nm_verify_sent_at'   => 0,
		'nm_verify_sends'     => 0,
		'nm_verify_touched'   => time(),
	);
}

/** Everything a pending account carries, so none of it outlives the verification. */
function ner_michoel_verification_meta_keys() {
	return array(
		'nm_email_unverified',
		'nm_verify_token',
		'nm_verify_code',
		'nm_verify_expires',
		'nm_verify_attempts',
		'nm_verify_sent_at',
		'nm_verify_sends',
		'nm_verify_prefs',
		'nm_verify_touched',
	);
}

/**
 * Turns a pending account on. Its sign-up choices (updates, new-shiur alerts)
 * only start to count now: before this nobody has shown the address is theirs,
 * so it must never end up on a mailing list.
 */
function ner_michoel_verification_complete( $user_id ) {
	$user_id = (int) $user_id;
	$prefs   = get_user_meta( $user_id, 'nm_verify_prefs', true );
	$prefs   = is_array( $prefs ) ? $prefs : array();

	update_user_meta( $user_id, 'nm_pref_updates', ! empty( $prefs['updates'] ) ? 1 : 0 );
	update_user_meta( $user_id, 'nm_pref_new_shiur_alerts', ! empty( $prefs['alerts'] ) ? 1 : 0 );
	update_user_meta( $user_id, 'nm_email_verified_at', time() );

	foreach ( ner_michoel_verification_meta_keys() as $key ) {
		delete_user_meta( $user_id, $key );
	}

	do_action( 'ner_michoel_email_verified', $user_id );
}

/* ------------------------------------------------------------------
 * Codes and tokens.
 * ------------------------------------------------------------------ */

function ner_michoel_verification_generate_code() {
	return str_pad( (string) random_int( 0, (int) pow( 10, NER_MICHOEL_VERIFY_CODE_LENGTH ) - 1 ), NER_MICHOEL_VERIFY_CODE_LENGTH, '0', STR_PAD_LEFT );
}

/** What is stored in place of a code. Bound to the account, so one account's code is no use on another. */
function ner_michoel_verification_hash_code( $user_id, $code ) {
	return hash_hmac( 'sha256', (int) $user_id . '|' . $code, wp_salt( 'auth' ) . '|nm-verify-code' );
}

function ner_michoel_verification_new_token() {
	return bin2hex( random_bytes( 16 ) );
}

function ner_michoel_verification_hash_token( $token ) {
	return hash( 'sha256', (string) $token );
}

/**
 * The pending account for this email, if $token is the one it was last given.
 * False in every other case, with nothing to tell the cases apart.
 */
function ner_michoel_verification_find_session( $email, $token ) {
	if ( ! is_email( $email ) || ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
		return false;
	}
	$user = get_user_by( 'email', $email );
	if ( ! $user || ! ner_michoel_user_is_unverified( $user->ID ) ) {
		return false;
	}
	$stored = (string) get_user_meta( $user->ID, 'nm_verify_token', true );
	if ( '' === $stored || ! hash_equals( $stored, ner_michoel_verification_hash_token( $token ) ) ) {
		return false;
	}
	return $user;
}

/* ------------------------------------------------------------------
 * Counting, atomically.
 * ------------------------------------------------------------------ */

/**
 * Uses up one of the account's tries. True if there was one left.
 *
 * One UPDATE that raises the counter only while it is below the limit, so
 * however many requests arrive together, exactly NER_MICHOEL_VERIFY_MAX_ATTEMPTS
 * of them get a try.
 */
function ner_michoel_verification_take_attempt( $user_id ) {
	global $wpdb;

	add_user_meta( $user_id, 'nm_verify_attempts', 0, true ); // Only if the row isn't there yet.
	$changed = $wpdb->query(
		$wpdb->prepare(
			"UPDATE {$wpdb->usermeta} SET meta_value = CAST(meta_value AS UNSIGNED) + 1 WHERE user_id = %d AND meta_key = 'nm_verify_attempts' AND CAST(meta_value AS UNSIGNED) < %d",
			$user_id,
			NER_MICHOEL_VERIFY_MAX_ATTEMPTS
		)
	);
	wp_cache_delete( $user_id, 'user_meta' );

	return 1 === (int) $changed;
}

function ner_michoel_verification_attempts_used( $user_id ) {
	wp_cache_delete( $user_id, 'user_meta' );
	return (int) get_user_meta( $user_id, 'nm_verify_attempts', true );
}

/**
 * Claims the right to send a code now: true for exactly one of any number of
 * simultaneous requests, and for none until the cooldown has passed.
 */
function ner_michoel_verification_claim_send_slot( $user_id, $now ) {
	global $wpdb;

	add_user_meta( $user_id, 'nm_verify_sent_at', 0, true );
	$changed = $wpdb->query(
		$wpdb->prepare(
			"UPDATE {$wpdb->usermeta} SET meta_value = %d WHERE user_id = %d AND meta_key = 'nm_verify_sent_at' AND CAST(meta_value AS UNSIGNED) <= %d",
			$now,
			$user_id,
			$now - NER_MICHOEL_VERIFY_RESEND_COOLDOWN
		)
	);
	wp_cache_delete( $user_id, 'user_meta' );

	return 1 === (int) $changed;
}

/** Codes this account has been sent so far. */
function ner_michoel_verification_sends_used( $user_id ) {
	wp_cache_delete( $user_id, 'user_meta' );
	return (int) get_user_meta( $user_id, 'nm_verify_sends', true );
}

/**
 * Counts one more code against the account's lifetime allowance. True if there
 * was one left. A single UPDATE, like take_attempt(), so a burst can't overshoot.
 */
function ner_michoel_verification_claim_total_send( $user_id ) {
	global $wpdb;

	add_user_meta( $user_id, 'nm_verify_sends', 0, true );
	$changed = $wpdb->query(
		$wpdb->prepare(
			"UPDATE {$wpdb->usermeta} SET meta_value = CAST(meta_value AS UNSIGNED) + 1 WHERE user_id = %d AND meta_key = 'nm_verify_sends' AND CAST(meta_value AS UNSIGNED) < %d",
			$user_id,
			NER_MICHOEL_VERIFY_MAX_SENDS
		)
	);
	wp_cache_delete( $user_id, 'user_meta' );

	return 1 === (int) $changed;
}

/** Seconds until another code may be sent to this account (0 when one may be sent now). */
function ner_michoel_verification_wait( $user_id ) {
	wp_cache_delete( $user_id, 'user_meta' );
	$sent_at = (int) get_user_meta( $user_id, 'nm_verify_sent_at', true );
	return max( 0, $sent_at + NER_MICHOEL_VERIFY_RESEND_COOLDOWN - time() );
}

/* ------------------------------------------------------------------
 * Sending a code.
 * ------------------------------------------------------------------ */

/**
 * Emails a new code to a pending account, if one may be sent now. A new code
 * replaces the old one and gives back all the tries.
 *
 * Returns array( 'status' => 'sent' | 'cooldown' | 'capped' | 'exhausted' | 'failed',
 *                'wait'   => seconds before another may be asked for ).
 */
function ner_michoel_verification_send_code( $user_id ) {
	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return array( 'status' => 'failed', 'wait' => 0 );
	}
	$email = strtolower( $user->user_email );
	$now   = time();

	if ( ner_michoel_verification_sends_used( $user_id ) >= NER_MICHOEL_VERIFY_MAX_SENDS ) {
		return array( 'status' => 'exhausted', 'wait' => 0 );
	}
	if ( ner_michoel_rate_count( 'verify_email', $email ) >= NER_MICHOEL_VERIFY_EMAILS_PER_HOUR ) {
		return array( 'status' => 'capped', 'wait' => 0 );
	}

	if ( ! ner_michoel_verification_claim_send_slot( $user_id, $now ) ) {
		return array( 'status' => 'cooldown', 'wait' => ner_michoel_verification_wait( $user_id ) );
	}
	if ( ! ner_michoel_verification_claim_total_send( $user_id ) ) {
		return array( 'status' => 'exhausted', 'wait' => 0 );
	}

	$code = ner_michoel_verification_generate_code();
	update_user_meta( $user_id, 'nm_verify_code', ner_michoel_verification_hash_code( $user_id, $code ) );
	update_user_meta( $user_id, 'nm_verify_expires', $now + NER_MICHOEL_VERIFY_CODE_TTL );
	update_user_meta( $user_id, 'nm_verify_attempts', 0 );
	ner_michoel_rate_bump( 'verify_email', $email, HOUR_IN_SECONDS );

	if ( ! ner_michoel_verification_send_email( $user, $code ) ) {
		// Not sent: let them try again in a few seconds, not after a whole cooldown.
		update_user_meta( $user_id, 'nm_verify_sent_at', $now - NER_MICHOEL_VERIFY_RESEND_COOLDOWN + 10 );
		return array( 'status' => 'failed', 'wait' => 10 );
	}

	return array( 'status' => 'sent', 'wait' => NER_MICHOEL_VERIFY_RESEND_COOLDOWN );
}

/**
 * Starts (or restarts) verification for a pending account: a new token for
 * this browser, and a new code if one may be sent now. The previous token stops
 * working. Returns send_code()'s array plus 'token'.
 */
function ner_michoel_verification_begin( $user_id ) {
	$token = ner_michoel_verification_new_token();
	update_user_meta( $user_id, 'nm_verify_token', ner_michoel_verification_hash_token( $token ) );
	update_user_meta( $user_id, 'nm_verify_touched', time() );
	ner_michoel_verification_schedule_cleanup();

	$sent          = ner_michoel_verification_send_code( $user_id );
	$sent['token'] = $token;
	return $sent;
}

/**
 * The email: HTML with a plain-text alternative, the code large and alone.
 * It says it is from Yeshivas Toras Moshe (ner_michoel_email_sender_name()) in the
 * sender name, the subject, the message and its footer.
 * wp_mail()'s content type and PHPMailer's AltBody are set for this one message
 * and taken off again, so nothing else the site sends is touched.
 */
function ner_michoel_verification_send_email( $user, $code ) {
	$brand   = ner_michoel_email_sender_name();
	$name    = $user->display_name ? $user->display_name : $user->user_email;
	$minutes = (int) ( NER_MICHOEL_VERIFY_CODE_TTL / MINUTE_IN_SECONDS );
	$home    = home_url( '/' );

	/* translators: 1: the 6-digit code, 2: who the email is from (Yeshivas Toras Moshe) */
	$subject = sprintf( __( '%1$s is your %2$s verification code', 'ner-michoel-core' ), $code, $brand );

	$text  = sprintf( /* translators: %s: the person's name */ __( 'Hi %s,', 'ner-michoel-core' ), $name ) . "\n\n";
	$text .= sprintf( /* translators: %s: who the email is from (Yeshivas Toras Moshe) */ __( 'Thanks for signing up with %s. Your verification code is:', 'ner-michoel-core' ), $brand ) . "\n\n";
	$text .= '    ' . $code . "\n\n";
	$text .= __( 'Enter it on the sign-up page to finish creating your account.', 'ner-michoel-core' ) . ' ';
	$text .= sprintf( /* translators: %d: minutes */ _n( 'It works for %d minute.', 'It works for %d minutes.', $minutes, 'ner-michoel-core' ), $minutes ) . "\n\n";
	$text .= __( 'If you didn’t try to sign up, you can ignore this email. Nothing happens without the code.', 'ner-michoel-core' ) . "\n\n";
	$text .= $brand . "\n" . $home . "\n";

	// What the inbox list shows after the subject. Hidden in the message itself.
	/* translators: %s: who the email is from (Yeshivas Toras Moshe) */
	$preheader = sprintf( __( 'Enter this code to finish creating your %s account.', 'ner-michoel-core' ), $brand );
	/* translators: %s: who the email is from (Yeshivas Toras Moshe) */
	$sent_by = sprintf( __( 'Sent by %s', 'ner-michoel-core' ), $brand );

	$font  = '-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif';
	$html  = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>';
	$html .= '<body style="margin:0;padding:0;background:#f3f5f4;">';
	$html .= '<div style="display:none;max-height:0;overflow:hidden;opacity:0;font-size:1px;line-height:1px;color:#f3f5f4;">' . esc_html( $preheader ) . '</div>';
	$html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f3f5f4;padding:24px 12px;"><tr><td align="center">';
	$html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:480px;background:#ffffff;border:1px solid #e3e8e5;border-radius:14px;"><tr>';
	$html .= '<td style="padding:30px 28px 26px;font-family:' . $font . ';">';
	$html .= '<div style="font-size:18px;font-weight:700;line-height:1.3;color:#2f8f5b;">' . esc_html( $brand ) . '</div>';
	$html .= '<h1 style="margin:14px 0 10px;font-size:22px;line-height:1.3;color:#17211d;">' . esc_html__( 'Confirm your email', 'ner-michoel-core' ) . '</h1>';
	$html .= '<p style="margin:0 0 20px;font-size:15px;line-height:1.6;color:#4b5852;">' . esc_html( sprintf( /* translators: 1: the person's name, 2: who the email is from (Yeshivas Toras Moshe) */ __( 'Hi %1$s, thanks for signing up with %2$s. Enter this code on the sign-up page to finish creating your account:', 'ner-michoel-core' ), $name, $brand ) ) . '</p>';
	$html .= '<div style="margin:0 0 20px;padding:18px 12px;text-align:center;background:#eaf6ee;border-radius:12px;font-family:Consolas,Menlo,\'Courier New\',monospace;font-size:36px;font-weight:700;letter-spacing:10px;color:#1d5c38;">' . esc_html( $code ) . '</div>';
	$html .= '<p style="margin:0 0 8px;font-size:14px;line-height:1.5;color:#4b5852;">' . esc_html( sprintf( /* translators: %d: minutes */ _n( 'The code works for %d minute.', 'The code works for %d minutes.', $minutes, 'ner-michoel-core' ), $minutes ) ) . '</p>';
	$html .= '<p style="margin:0;font-size:14px;line-height:1.5;color:#75827c;">' . esc_html__( 'Didn’t try to sign up? You can ignore this email. Nothing happens without the code.', 'ner-michoel-core' ) . '</p>';
	$html .= '</td></tr></table>';
	$html .= '<p style="margin:16px 0 0;font-family:' . $font . ';font-size:12px;color:#75827c;">' . esc_html( $sent_by ) . ' &middot; <a href="' . esc_url( $home ) . '" style="color:#75827c;">' . esc_html( wp_parse_url( $home, PHP_URL_HOST ) ) . '</a></p>';
	$html .= '</td></tr></table></body></html>';

	$content_type = function () {
		return 'text/html';
	};
	$alt_body     = function ( $mailer ) use ( $text ) {
		$mailer->AltBody = $text; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer's property.
	};

	$error  = '';
	$failed = function ( $wp_error ) use ( &$error ) {
		$error = $wp_error->get_error_message();
	};

	add_filter( 'wp_mail_content_type', $content_type );
	add_action( 'phpmailer_init', $alt_body );
	add_action( 'wp_mail_failed', $failed );
	$sent = wp_mail( $user->user_email, $subject, $html );
	remove_filter( 'wp_mail_content_type', $content_type );
	remove_action( 'phpmailer_init', $alt_body );
	remove_action( 'wp_mail_failed', $failed );

	ner_michoel_verification_note_mail_result( (bool) $sent, $error );

	return (bool) $sent;
}

/**
 * Keeps track of whether the site's email works, because if it doesn't nobody
 * can finish signing up. A failure is remembered (with the mail system's own
 * explanation) until the next code goes out; the Control Panel's Home shows it.
 */
function ner_michoel_verification_note_mail_result( $sent, $error ) {
	if ( $sent ) {
		delete_option( 'nm_verify_mail_failure' );
		return;
	}
	$previous = get_option( 'nm_verify_mail_failure' );
	update_option(
		'nm_verify_mail_failure',
		array(
			'time'  => time(),
			'count' => ( is_array( $previous ) && ! empty( $previous['count'] ) ? (int) $previous['count'] : 0 ) + 1,
			'error' => substr( wp_strip_all_tags( (string) $error ), 0, 200 ),
		),
		false
	);
}

/**
 * The last time a code couldn't be emailed, if that was in the last day and no
 * code has gone out since: array( 'time', 'count', 'error' ). Otherwise false.
 */
function ner_michoel_verification_mail_problem() {
	$problem = get_option( 'nm_verify_mail_failure' );
	if ( is_array( $problem ) && ! empty( $problem['time'] ) && $problem['time'] > time() - DAY_IN_SECONDS ) {
		return $problem;
	}
	return false;
}

/**
 * What the person is told after a send was tried. $context: 'signup' (the
 * account was just made), 'login' (they came back to an account that isn't
 * confirmed yet) or 'resend' (they asked for another code). Whole sentences
 * for each, so they can be translated as they read.
 */
function ner_michoel_verification_message( $context, $status, $email ) {
	if ( 'sent' === $status ) {
		// The address is shown under it, in the code step itself, so it isn't repeated here.
		return 'login' === $context
			? __( 'Your email isn’t confirmed yet. We’ve emailed you a new 6-digit code.', 'ner-michoel-core' )
			: __( 'Account created! We’ve emailed you a 6-digit code.', 'ner-michoel-core' );
	}

	if ( 'cooldown' === $status ) {
		return 'login' === $context
			? __( 'Your email isn’t confirmed yet. We emailed you a code a moment ago, and it still works. Enter it below.', 'ner-michoel-core' )
			: __( 'We emailed you a code a moment ago, and it still works. Enter it below.', 'ner-michoel-core' );
	}

	if ( 'capped' === $status ) {
		return 'login' === $context
			? __( 'Your email isn’t confirmed yet. We’ve already sent several codes to this address, so please check your inbox and spam folder, or try again in an hour.', 'ner-michoel-core' )
			: __( 'We’ve already sent several codes to this address. Please check your inbox and spam folder, or try again in an hour.', 'ner-michoel-core' );
	}

	if ( 'exhausted' === $status ) {
		return 'login' === $context
			? __( 'Your email isn’t confirmed yet, and we’ve sent the most codes we can for this sign-up. Use “Forgot password?” instead: we’ll email you a link that confirms your email.', 'ner-michoel-core' )
			: __( 'We’ve sent the most codes we can for this sign-up. Use “Forgot password?” instead: we’ll email you a link that confirms your email.', 'ner-michoel-core' );
	}

	return 'login' === $context
		? __( 'Your email isn’t confirmed yet, and we couldn’t send you a code just now. Please press “Resend code” in a few seconds.', 'ner-michoel-core' )
		: __( 'We couldn’t send the email just now. Please press “Resend code” in a few seconds.', 'ner-michoel-core' );
}

/* ------------------------------------------------------------------
 * REST: entering the code, and asking for another.
 * ------------------------------------------------------------------ */

/**
 * Backstop on both endpoints: how many requests one address may make.
 * True if there's room (and counts this one).
 */
function ner_michoel_verification_ip_allowed() {
	$ip = ner_michoel_client_ip();
	if ( ner_michoel_rate_count( 'verify_ip', $ip ) >= NER_MICHOEL_VERIFY_IP_LIMIT ) {
		return false;
	}
	ner_michoel_rate_bump( 'verify_ip', $ip, 15 * MINUTE_IN_SECONDS );
	return true;
}

function ner_michoel_verification_session_error() {
	return ner_michoel_account_error(
		'invalid_session',
		__( 'That sign-up session has ended. Please log in with your email and password, and we’ll send you a new code.', 'ner-michoel-core' ),
		400
	);
}

function ner_michoel_handle_account_verify( WP_REST_Request $request ) {
	if ( ! ner_michoel_email_verification_enabled() ) {
		return ner_michoel_verification_session_error();
	}
	if ( ! ner_michoel_verification_ip_allowed() ) {
		return ner_michoel_account_error( 'rate_limited', __( 'Too many tries from your network. Please wait a while and try again.', 'ner-michoel-core' ), 429 );
	}

	$email = strtolower( sanitize_email( (string) $request->get_param( 'email' ) ) );
	$token = (string) $request->get_param( 'token' );
	$code  = preg_replace( '/\D/', '', (string) $request->get_param( 'code' ) );

	$user = ner_michoel_verification_find_session( $email, $token );
	if ( ! $user ) {
		return ner_michoel_verification_session_error();
	}

	if ( strlen( $code ) !== NER_MICHOEL_VERIFY_CODE_LENGTH ) {
		return ner_michoel_account_error( 'bad_format', __( 'Please enter the 6-digit code from the email.', 'ner-michoel-core' ) );
	}

	$stored  = (string) get_user_meta( $user->ID, 'nm_verify_code', true );
	$expires = (int) get_user_meta( $user->ID, 'nm_verify_expires', true );
	if ( '' === $stored || $expires < time() ) {
		return ner_michoel_account_error(
			'expired',
			__( 'That code has expired. Please press “Resend code” to get a new one.', 'ner-michoel-core' ),
			400,
			array( 'resend_in' => ner_michoel_verification_wait( $user->ID ) )
		);
	}

	// A try is used up before the code is looked at, atomically (see take_attempt()).
	if ( ! ner_michoel_verification_take_attempt( $user->ID ) ) {
		return ner_michoel_account_error(
			'locked',
			__( 'Too many wrong codes. Please press “Resend code” to get a new one.', 'ner-michoel-core' ),
			400,
			array( 'attempts_left' => 0, 'resend_in' => ner_michoel_verification_wait( $user->ID ) )
		);
	}

	if ( ! hash_equals( $stored, ner_michoel_verification_hash_code( $user->ID, $code ) ) ) {
		$left = max( 0, NER_MICHOEL_VERIFY_MAX_ATTEMPTS - ner_michoel_verification_attempts_used( $user->ID ) );
		if ( 0 === $left ) {
			$message = __( 'That code isn’t right, and you’re out of tries. Please press “Resend code” to get a new one.', 'ner-michoel-core' );
		} else {
			$message = sprintf(
				/* translators: %d: tries left */
				_n( 'That code isn’t right. You have %d try left.', 'That code isn’t right. You have %d tries left.', $left, 'ner-michoel-core' ),
				$left
			);
		}
		return ner_michoel_account_error( 'wrong_code', $message, 400, array( 'attempts_left' => $left, 'resend_in' => ner_michoel_verification_wait( $user->ID ) ) );
	}

	ner_michoel_verification_complete( $user->ID );
	ner_michoel_sign_in_user( $user->ID );

	return new WP_REST_Response( array( 'success' => true, 'name' => $user->display_name ), 200 );
}

function ner_michoel_handle_account_resend_code( WP_REST_Request $request ) {
	if ( ! ner_michoel_email_verification_enabled() ) {
		return ner_michoel_verification_session_error();
	}
	if ( ! ner_michoel_verification_ip_allowed() ) {
		return ner_michoel_account_error( 'rate_limited', __( 'Too many tries from your network. Please wait a while and try again.', 'ner-michoel-core' ), 429 );
	}

	$email = strtolower( sanitize_email( (string) $request->get_param( 'email' ) ) );
	$user  = ner_michoel_verification_find_session( $email, (string) $request->get_param( 'token' ) );
	if ( ! $user ) {
		return ner_michoel_verification_session_error();
	}

	$result = ner_michoel_verification_send_code( $user->ID );

	switch ( $result['status'] ) {
		case 'sent':
			return new WP_REST_Response(
				array(
					'success'   => true,
					'message'   => sprintf( /* translators: %s: email address */ __( 'A new code is on its way to %s.', 'ner-michoel-core' ), $user->user_email ),
					'resend_in' => $result['wait'],
				),
				200
			);
		case 'cooldown':
			return ner_michoel_account_error(
				'cooldown',
				sprintf(
					/* translators: %d: seconds */
					_n( 'Please wait %d second before asking for another code.', 'Please wait %d seconds before asking for another code.', $result['wait'], 'ner-michoel-core' ),
					$result['wait']
				),
				429,
				array( 'resend_in' => $result['wait'] )
			);
		case 'capped':
			return ner_michoel_account_error( 'capped', ner_michoel_verification_message( 'resend', 'capped', $user->user_email ), 429 );
		case 'exhausted':
			return ner_michoel_account_error( 'exhausted', ner_michoel_verification_message( 'resend', 'exhausted', $user->user_email ), 429 );
		default:
			return ner_michoel_account_error( 'send_failed', ner_michoel_verification_message( 'resend', 'failed', $user->user_email ), 500, array( 'resend_in' => $result['wait'] ) );
	}
}

/* ------------------------------------------------------------------
 * Keeping the door shut, and tidying up.
 * ------------------------------------------------------------------ */

/**
 * No login to a pending account, by any route. After the password check (so a
 * wrong password is still just a wrong password), before the lockout at 99.
 * The Account page's login handler recognizes this error and sends the visitor
 * to the code step; anywhere else (wp-login.php) it's the message below.
 */
function ner_michoel_verification_block_login( $user ) {
	if ( $user instanceof WP_User && ner_michoel_user_is_unverified( $user->ID ) ) {
		return new WP_Error(
			'nm_email_unverified',
			__( 'Your email address isn’t confirmed yet. Log in from the Account page and we’ll send you a code.', 'ner-michoel-core' )
		);
	}
	return $user;
}
add_filter( 'authenticate', 'ner_michoel_verification_block_login', 98 );

/**
 * A password reset goes through a link emailed to the address, which shows the
 * same thing as the code does.
 */
function ner_michoel_verification_after_password_reset( $user ) {
	if ( $user instanceof WP_User && ner_michoel_user_is_unverified( $user->ID ) ) {
		ner_michoel_verification_complete( $user->ID );
	}
}
add_action( 'after_password_reset', 'ner_michoel_verification_after_password_reset' );

/* ------------------------------------------------------------------
 * wp-admin Users screen: see who is waiting, and confirm by hand.
 * ------------------------------------------------------------------ */

/**
 * For the person whose email never arrives: an admin can confirm the account
 * from the Users screen (the same effect as the code), and sees who is waiting.
 */
function ner_michoel_verification_users_column( $columns ) {
	$columns['nm_email_status'] = __( 'Email', 'ner-michoel-core' );
	return $columns;
}
add_filter( 'manage_users_columns', 'ner_michoel_verification_users_column' );

function ner_michoel_verification_users_column_value( $value, $column, $user_id ) {
	if ( 'nm_email_status' !== $column ) {
		return $value;
	}
	if ( ner_michoel_user_is_unverified( $user_id ) ) {
		return '<span style="color:#b26200;font-weight:600;">' . esc_html__( 'Waiting for code', 'ner-michoel-core' ) . '</span>';
	}
	return get_user_meta( $user_id, 'nm_email_verified_at', true )
		? '<span style="color:#1e7d55;">' . esc_html__( 'Confirmed', 'ner-michoel-core' ) . '</span>'
		: '<span style="color:#75827c;">—</span>';
}
add_filter( 'manage_users_custom_column', 'ner_michoel_verification_users_column_value', 10, 3 );

function ner_michoel_verification_row_actions( $actions, $user ) {
	if ( current_user_can( 'edit_user', $user->ID ) && ner_michoel_user_is_unverified( $user->ID ) ) {
		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'nm_confirm_email',
					'user'   => $user->ID,
				),
				admin_url( 'admin.php' )
			),
			'nm_confirm_email_' . $user->ID
		);
		$actions['nm_confirm_email'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Confirm email', 'ner-michoel-core' ) . '</a>';
	}
	return $actions;
}
add_filter( 'user_row_actions', 'ner_michoel_verification_row_actions', 10, 2 );

function ner_michoel_verification_handle_confirm_action() {
	$user_id = isset( $_GET['user'] ) ? absint( $_GET['user'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked just below.
	check_admin_referer( 'nm_confirm_email_' . $user_id );
	if ( ! $user_id || ! current_user_can( 'edit_user', $user_id ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'ner-michoel-core' ) );
	}
	if ( ner_michoel_user_is_unverified( $user_id ) ) {
		ner_michoel_verification_complete( $user_id );
	}
	wp_safe_redirect( add_query_arg( 'nm_confirmed', '1', admin_url( 'users.php' ) ) );
	exit;
}
add_action( 'admin_action_nm_confirm_email', 'ner_michoel_verification_handle_confirm_action' );

function ner_michoel_verification_confirmed_notice() {
	if ( isset( $_GET['nm_confirmed'] ) && current_user_can( 'edit_users' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only a message.
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Email confirmed. They can log in now.', 'ner-michoel-core' ) . '</p></div>';
	}
}
add_action( 'admin_notices', 'ner_michoel_verification_confirmed_notice' );

function ner_michoel_verification_schedule_cleanup() {
	if ( ! wp_next_scheduled( 'nm_purge_unverified_accounts' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'nm_purge_unverified_accounts' );
	}
}
// Also checked when an admin screen loads, like the plugin's other daily jobs.
add_action( 'admin_init', 'ner_michoel_verification_schedule_cleanup' );

/**
 * Deletes pending accounts nobody finished. Only a subscriber with no content
 * and no confirmed email is ever removed, never anyone who got further than
 * the sign-up form.
 */
function ner_michoel_purge_unverified_accounts() {
	$ids = get_users(
		array(
			'fields'     => 'ID',
			'number'     => 200,
			'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- a daily job over a small set.
				'relation' => 'AND',
				array(
					'key'   => 'nm_email_unverified',
					'value' => '1',
				),
				array(
					'key'     => 'nm_verify_touched',
					'value'   => time() - NER_MICHOEL_VERIFY_STALE_AFTER,
					'compare' => '<',
					'type'    => 'NUMERIC',
				),
			),
		)
	);
	if ( ! $ids ) {
		return;
	}

	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $ids as $id ) {
		$user = get_userdata( $id );
		if ( $user && array( 'subscriber' ) === array_values( (array) $user->roles ) && 0 === (int) count_user_posts( $id ) && ner_michoel_user_is_unverified( $id ) ) {
			wp_delete_user( $id );
		}
	}
}
add_action( 'nm_purge_unverified_accounts', 'ner_michoel_purge_unverified_accounts' );
