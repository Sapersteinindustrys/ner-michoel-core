<?php
/**
 * Two-step sign-in: a password alone no longer signs anyone in.
 *
 * After the right password, a 6-digit code is emailed to the account's address
 * and has to be typed in. The person can tick "Remember this computer": that
 * computer then signs in with the password alone until it expires or is
 * forgotten. It covers every way in that takes a password and gives a session:
 * the Account page, wp-login.php (staff), the /admin password shortcut, and
 * anything else that goes through wp_signon(). Nothing already signed in is
 * touched, and neither are Application Passwords (the deploy calls, the Site
 * Control Panel's REST calls), which never make a browser session.
 *
 * How it holds together:
 *
 *   - One `authenticate` filter, after the password has been checked, turns
 *     "right password" into "right password, code needed" unless the computer is
 *     remembered. It is the only gate, so no sign-in route can forget it.
 *   - The code is 6 digits from random_int(), kept only as an HMAC, good for 10
 *     minutes, used once, and used up by 5 wrong tries (a new code resets them).
 *     Tries are claimed with single UPDATE statements, so a burst of parallel
 *     guesses still gets exactly 5 (same method as account-verification.php).
 *   - Codes are rate limited per account (one a minute, six an hour to one
 *     address) and the checks per address, so the form can't flood an inbox.
 *   - The Account page and /admin get a random token with the "code needed"
 *     answer; it has to come back with the code, so the code only works in the
 *     browser that gave the password. wp-login.php has no second page, so it
 *     asks for the password again beside the code.
 *   - A remembered computer is a random 128-bit cookie (HttpOnly, SameSite=Lax,
 *     Secure on https). The server keeps only an HMAC of it per account, with the
 *     browser's name and the dates, so the cookie alone is no use on another
 *     account and a copy of the database holds nothing that can be replayed.
 *     It lasts 90 days from its last use (changeable), at most 10 per account.
 *     A changed or reset password forgets every remembered computer; so does the
 *     "Forget remembered computers" button (Account page, wp-admin profile).
 *   - Switch: Control Panel > Advanced > Security. If the site's email stops
 *     working nobody can get a code, so the switch, and a line in wp-config.php
 *     (define( 'NER_MICHOEL_LOGIN_CODE_OFF', true );), are the ways back in; the
 *     Security screen warns when the last email failed.
 *
 * The Account page needs the theme's code step: until the theme says it has one
 * (add_theme_support( 'nm-login-code' )) that page tells people to use the
 * regular login page instead, and sends no code it couldn't show.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Digits in the emailed code. */
define( 'NER_MICHOEL_LOGIN_CODE_LENGTH', 6 );

/** How long a code works. */
define( 'NER_MICHOEL_LOGIN_CODE_TTL', 10 * MINUTE_IN_SECONDS );

/** Wrong tries one code allows. */
define( 'NER_MICHOEL_LOGIN_CODE_MAX_ATTEMPTS', 5 );

/** Seconds before another code can be sent to the same account. */
define( 'NER_MICHOEL_LOGIN_CODE_RESEND_COOLDOWN', 60 );

/** Codes emailed to one address in an hour, however they were asked for. */
define( 'NER_MICHOEL_LOGIN_CODE_EMAILS_PER_HOUR', 6 );

/** Code and resend requests from one address in 15 minutes (a backstop; each account has its own limits). */
define( 'NER_MICHOEL_LOGIN_CODE_IP_LIMIT', 60 );

/** The remembered-computer cookie. */
define( 'NER_MICHOEL_TRUSTED_COOKIE', 'nm_trusted_computer' );

/** Remembered computers one account can have; the longest unused one goes first. */
define( 'NER_MICHOEL_TRUSTED_MAX', 10 );

/* ------------------------------------------------------------------
 * Settings.
 * ------------------------------------------------------------------ */

/**
 * On unless switched off on the Security screen, or in wp-config.php.
 */
function ner_michoel_login_code_enabled() {
	if ( defined( 'NER_MICHOEL_LOGIN_CODE_OFF' ) && NER_MICHOEL_LOGIN_CODE_OFF ) {
		return false;
	}
	$settings = ner_michoel_form_guard_settings();
	return (bool) apply_filters( 'ner_michoel_login_code_enabled', ! empty( $settings['login_code'] ) );
}

/** Days a remembered computer stays remembered after its last sign-in. */
function ner_michoel_trusted_days() {
	$settings = ner_michoel_form_guard_settings();
	$days     = isset( $settings['login_code_days'] ) ? (int) $settings['login_code_days'] : 90;
	return max( 1, min( 365, (int) apply_filters( 'ner_michoel_trusted_computer_days', $days ) ) );
}

/** True once the theme's Account page can take the code. */
function ner_michoel_login_code_theme_ready() {
	return (bool) apply_filters( 'ner_michoel_login_code_theme_ready', current_theme_supports( 'nm-login-code' ) );
}

/**
 * The lines the Security screen shows above its fields: whether the codes are on,
 * and a warning when the site's email last failed (then nobody can get a code).
 */
function ner_michoel_login_code_status_lines() {
	$lines = array();

	if ( defined( 'NER_MICHOEL_LOGIN_CODE_OFF' ) && NER_MICHOEL_LOGIN_CODE_OFF ) {
		$lines[] = __( 'Sign-in codes: off, because wp-config.php says NER_MICHOEL_LOGIN_CODE_OFF. The switch below does nothing until that line is removed.', 'ner-michoel-core' );
	} elseif ( ner_michoel_login_code_enabled() ) {
		$lines[] = __( 'Sign-in codes: on. After the right password a 6-digit code is emailed (Account page, wp-login.php and the /admin shortcut), unless the person remembered that computer.', 'ner-michoel-core' );
		if ( ! ner_michoel_login_code_theme_ready() ) {
			$lines[] = __( 'The Account page is waiting for the theme update that adds the code box. Until then it sends people to the regular login page.', 'ner-michoel-core' );
		}
	} else {
		$lines[] = __( 'Sign-in codes: off. A password alone signs people in.', 'ner-michoel-core' );
	}

	$problem = ner_michoel_verification_mail_problem();
	if ( $problem && ner_michoel_login_code_enabled() ) {
		$lines[] = sprintf(
			/* translators: %s: the mail system's own explanation */
			__( 'Warning: the site’s last email could not be sent (%s). While emails fail nobody can get a sign-in code: fix the email, or untick the box below, or add a line to wp-config.php that says define( "NER_MICHOEL_LOGIN_CODE_OFF", true );', 'ner-michoel-core' ),
			'' !== $problem['error'] ? $problem['error'] : __( 'no reason given', 'ner-michoel-core' )
		);
	}

	return $lines;
}

/* ------------------------------------------------------------------
 * Remembered computers.
 * ------------------------------------------------------------------ */

/** This browser's remembered-computer cookie, if it holds a well-formed one. */
function ner_michoel_trusted_cookie_id() {
	$value = isset( $_COOKIE[ NER_MICHOEL_TRUSTED_COOKIE ] ) ? (string) wp_unslash( $_COOKIE[ NER_MICHOEL_TRUSTED_COOKIE ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked against a strict pattern.
	return preg_match( '/^[a-f0-9]{32}$/', $value ) ? $value : '';
}

/** What is stored for one computer of one account: bound to both, never the cookie itself. */
function ner_michoel_trusted_hash( $user_id, $device_id ) {
	return hash_hmac( 'sha256', (int) $user_id . '|' . $device_id, wp_salt( 'auth' ) . '|nm-trusted-computer' );
}

/**
 * The account's remembered computers: hash => array( 'created', 'used', 'expires', 'label' ).
 * The expired ones are left out.
 */
function ner_michoel_trusted_list( $user_id ) {
	$list = get_user_meta( (int) $user_id, 'nm_trusted_computers', true );
	if ( ! is_array( $list ) ) {
		return array();
	}
	$now = time();
	foreach ( $list as $hash => $item ) {
		if ( ! is_array( $item ) || empty( $item['expires'] ) || (int) $item['expires'] <= $now ) {
			unset( $list[ $hash ] );
		}
	}
	return $list;
}

function ner_michoel_trusted_count( $user_id ) {
	return count( ner_michoel_trusted_list( $user_id ) );
}

/** True when this browser's cookie is one of the account's remembered computers. */
function ner_michoel_trusted_is_trusted( $user_id ) {
	$id = ner_michoel_trusted_cookie_id();
	if ( '' === $id ) {
		return false;
	}
	$list = ner_michoel_trusted_list( $user_id );
	return isset( $list[ ner_michoel_trusted_hash( $user_id, $id ) ] );
}

function ner_michoel_trusted_set_cookie( $device_id, $expires ) {
	if ( headers_sent() ) {
		return;
	}
	setcookie(
		NER_MICHOEL_TRUSTED_COOKIE,
		$device_id,
		array(
			'expires'  => $expires,
			'path'     => COOKIEPATH,
			'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		)
	);
	$_COOKIE[ NER_MICHOEL_TRUSTED_COOKIE ] = $device_id;
}

/**
 * Remembers this computer for the account (or renews it): a new cookie when the
 * browser has none, the dates moved on, the browser's name noted. Returns true.
 */
function ner_michoel_trusted_add( $user_id ) {
	$user_id = (int) $user_id;
	$id      = ner_michoel_trusted_cookie_id();
	if ( '' === $id ) {
		$id = bin2hex( random_bytes( 16 ) );
	}
	$now     = time();
	$expires = $now + ner_michoel_trusted_days() * DAY_IN_SECONDS;
	$hash    = ner_michoel_trusted_hash( $user_id, $id );
	$list    = ner_michoel_trusted_list( $user_id );

	$list[ $hash ] = array(
		'created' => isset( $list[ $hash ]['created'] ) ? (int) $list[ $hash ]['created'] : $now,
		'used'    => $now,
		'expires' => $expires,
		'label'   => ner_michoel_login_code_device_label(),
	);

	if ( count( $list ) > NER_MICHOEL_TRUSTED_MAX ) {
		uasort(
			$list,
			function ( $a, $b ) {
				return (int) $a['used'] <=> (int) $b['used'];
			}
		);
		$list = array_slice( $list, -NER_MICHOEL_TRUSTED_MAX, null, true );
	}

	update_user_meta( $user_id, 'nm_trusted_computers', $list );
	ner_michoel_trusted_set_cookie( $id, $expires );
	return true;
}

/**
 * Forgets the account's remembered computers: all of them, or all but this
 * browser's. Returns how many were forgotten.
 */
function ner_michoel_trusted_forget( $user_id, $keep_current = false ) {
	$user_id = (int) $user_id;
	$list    = ner_michoel_trusted_list( $user_id );
	if ( ! $list ) {
		delete_user_meta( $user_id, 'nm_trusted_computers' );
		return 0;
	}
	$keep = array();
	if ( $keep_current ) {
		$id = ner_michoel_trusted_cookie_id();
		if ( '' !== $id ) {
			$hash = ner_michoel_trusted_hash( $user_id, $id );
			if ( isset( $list[ $hash ] ) ) {
				$keep[ $hash ] = $list[ $hash ];
			}
		}
	}
	$forgotten = count( $list ) - count( $keep );
	if ( $keep ) {
		update_user_meta( $user_id, 'nm_trusted_computers', $keep );
	} else {
		delete_user_meta( $user_id, 'nm_trusted_computers' );
	}
	return $forgotten;
}

/** "Chrome on Windows", read from the browser's own words. Only a label for people, never a check. */
function ner_michoel_login_code_device_label() {
	$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only matched against fixed words.

	if ( preg_match( '/Edg(e|A|iOS)?\//', $ua ) ) {
		$browser = 'Edge';
	} elseif ( preg_match( '/OPR\/|Opera/', $ua ) ) {
		$browser = 'Opera';
	} elseif ( preg_match( '/Firefox|FxiOS/', $ua ) ) {
		$browser = 'Firefox';
	} elseif ( preg_match( '/Chrome|CriOS|Chromium/', $ua ) ) {
		$browser = 'Chrome';
	} elseif ( preg_match( '/Safari/', $ua ) ) {
		$browser = 'Safari';
	} else {
		$browser = __( 'A browser', 'ner-michoel-core' );
	}

	if ( preg_match( '/iPhone/', $ua ) ) {
		$system = 'iPhone';
	} elseif ( preg_match( '/iPad/', $ua ) ) {
		$system = 'iPad';
	} elseif ( preg_match( '/Android/', $ua ) ) {
		$system = 'Android';
	} elseif ( preg_match( '/Windows/', $ua ) ) {
		$system = 'Windows';
	} elseif ( preg_match( '/Mac OS X|Macintosh/', $ua ) ) {
		$system = 'Mac';
	} elseif ( preg_match( '/CrOS/', $ua ) ) {
		$system = 'Chromebook';
	} elseif ( preg_match( '/Linux|X11/', $ua ) ) {
		$system = 'Linux';
	} else {
		$system = '';
	}

	return '' === $system ? $browser : sprintf( /* translators: 1: a browser (Chrome), 2: a system (Windows) */ __( '%1$s on %2$s', 'ner-michoel-core' ), $browser, $system );
}

/* ------------------------------------------------------------------
 * Codes, counted atomically.
 * ------------------------------------------------------------------ */

function ner_michoel_login_code_generate() {
	return str_pad( (string) random_int( 0, (int) pow( 10, NER_MICHOEL_LOGIN_CODE_LENGTH ) - 1 ), NER_MICHOEL_LOGIN_CODE_LENGTH, '0', STR_PAD_LEFT );
}

/** What is stored in place of a code. Bound to the account. */
function ner_michoel_login_code_hash( $user_id, $code ) {
	return hash_hmac( 'sha256', (int) $user_id . '|' . $code, wp_salt( 'auth' ) . '|nm-login-code' );
}

function ner_michoel_login_code_hash_token( $token ) {
	return hash( 'sha256', (string) $token );
}

/** Everything a pending sign-in carries, so none of it outlives it. */
function ner_michoel_login_code_clear( $user_id ) {
	foreach ( array( 'nm_lc_code', 'nm_lc_expires', 'nm_lc_attempts', 'nm_lc_token' ) as $key ) {
		delete_user_meta( (int) $user_id, $key );
	}
}

/**
 * Uses up one of the code's tries. True if there was one left: one UPDATE that
 * raises the counter only while it is below the limit, so however many requests
 * arrive together exactly NER_MICHOEL_LOGIN_CODE_MAX_ATTEMPTS of them get a try.
 */
function ner_michoel_login_code_take_attempt( $user_id ) {
	global $wpdb;

	add_user_meta( $user_id, 'nm_lc_attempts', 0, true );
	$changed = $wpdb->query(
		$wpdb->prepare(
			"UPDATE {$wpdb->usermeta} SET meta_value = CAST(meta_value AS UNSIGNED) + 1 WHERE user_id = %d AND meta_key = 'nm_lc_attempts' AND CAST(meta_value AS UNSIGNED) < %d",
			$user_id,
			NER_MICHOEL_LOGIN_CODE_MAX_ATTEMPTS
		)
	);
	wp_cache_delete( $user_id, 'user_meta' );

	return 1 === (int) $changed;
}

function ner_michoel_login_code_attempts_used( $user_id ) {
	wp_cache_delete( $user_id, 'user_meta' );
	return (int) get_user_meta( $user_id, 'nm_lc_attempts', true );
}

/** Claims the right to send a code now: true for exactly one of any number of simultaneous requests. */
function ner_michoel_login_code_claim_send_slot( $user_id, $now ) {
	global $wpdb;

	add_user_meta( $user_id, 'nm_lc_sent_at', 0, true );
	$changed = $wpdb->query(
		$wpdb->prepare(
			"UPDATE {$wpdb->usermeta} SET meta_value = %d WHERE user_id = %d AND meta_key = 'nm_lc_sent_at' AND CAST(meta_value AS UNSIGNED) <= %d",
			$now,
			$user_id,
			$now - NER_MICHOEL_LOGIN_CODE_RESEND_COOLDOWN
		)
	);
	wp_cache_delete( $user_id, 'user_meta' );

	return 1 === (int) $changed;
}

/** Seconds until another code may be sent to this account (0 when one may be sent now). */
function ner_michoel_login_code_wait( $user_id ) {
	wp_cache_delete( $user_id, 'user_meta' );
	$sent_at = (int) get_user_meta( $user_id, 'nm_lc_sent_at', true );
	return max( 0, $sent_at + NER_MICHOEL_LOGIN_CODE_RESEND_COOLDOWN - time() );
}

/* ------------------------------------------------------------------
 * Sending a code.
 * ------------------------------------------------------------------ */

/**
 * Emails a new code, if one may be sent now. A new code replaces the old one
 * and gives back all the tries.
 *
 * Returns array( 'status' => 'sent' | 'cooldown' | 'capped' | 'failed',
 *                'wait'   => seconds before another may be asked for ).
 */
function ner_michoel_login_code_send( $user_id ) {
	$user = get_userdata( $user_id );
	if ( ! $user || ! is_email( $user->user_email ) ) {
		return array( 'status' => 'failed', 'wait' => 0 );
	}
	$email = strtolower( $user->user_email );
	$now   = time();

	if ( ner_michoel_rate_count( 'login_code_email', $email ) >= NER_MICHOEL_LOGIN_CODE_EMAILS_PER_HOUR ) {
		return array( 'status' => 'capped', 'wait' => 0 );
	}
	if ( ! ner_michoel_login_code_claim_send_slot( $user_id, $now ) ) {
		return array( 'status' => 'cooldown', 'wait' => ner_michoel_login_code_wait( $user_id ) );
	}

	$code = ner_michoel_login_code_generate();
	update_user_meta( $user_id, 'nm_lc_code', ner_michoel_login_code_hash( $user_id, $code ) );
	update_user_meta( $user_id, 'nm_lc_expires', $now + NER_MICHOEL_LOGIN_CODE_TTL );
	update_user_meta( $user_id, 'nm_lc_attempts', 0 );
	ner_michoel_rate_bump( 'login_code_email', $email, HOUR_IN_SECONDS );

	if ( ! ner_michoel_login_code_send_email( $user, $code ) ) {
		// Not sent: let them try again in a few seconds, not after a whole cooldown.
		update_user_meta( $user_id, 'nm_lc_sent_at', $now - NER_MICHOEL_LOGIN_CODE_RESEND_COOLDOWN + 10 );
		return array( 'status' => 'failed', 'wait' => 10 );
	}

	return array( 'status' => 'sent', 'wait' => NER_MICHOEL_LOGIN_CODE_RESEND_COOLDOWN );
}

/**
 * Starts a sign-in's code step: a new token for this browser (the old one stops
 * working) and a new code if one may be sent now. send()'s array plus 'token'.
 */
function ner_michoel_login_code_begin( $user_id ) {
	$token = bin2hex( random_bytes( 16 ) );
	update_user_meta( $user_id, 'nm_lc_token', ner_michoel_login_code_hash_token( $token ) );

	$sent          = ner_michoel_login_code_send( $user_id );
	$sent['token'] = $token;
	return $sent;
}

/** j***@gmail.com: enough to know which mailbox, not enough to learn the address. */
function ner_michoel_login_code_mask_email( $email ) {
	$parts = explode( '@', (string) $email, 2 );
	if ( 2 !== count( $parts ) || '' === $parts[0] ) {
		return (string) $email;
	}
	return mb_substr( $parts[0], 0, 1 ) . '***@' . $parts[1];
}

/**
 * The email: HTML with a plain-text alternative, the code large and alone, and
 * where the password was just used from, so a stranger's try is easy to spot.
 */
function ner_michoel_login_code_send_email( $user, $code ) {
	$brand   = ner_michoel_email_sender_name();
	$name    = $user->display_name ? $user->display_name : $user->user_email;
	$minutes = (int) ( NER_MICHOEL_LOGIN_CODE_TTL / MINUTE_IN_SECONDS );
	$home    = home_url( '/' );
	$where   = sprintf(
		/* translators: 1: a browser and system (Chrome on Windows), 2: an IP address */
		__( '%1$s, internet address %2$s', 'ner-michoel-core' ),
		ner_michoel_login_code_device_label(),
		ner_michoel_client_ip()
	);

	/* translators: 1: the 6-digit code, 2: who the email is from (Yeshivas Toras Moshe) */
	$subject = sprintf( __( '%1$s is your %2$s sign-in code', 'ner-michoel-core' ), $code, $brand );

	$text  = sprintf( /* translators: %s: the person's name */ __( 'Hi %s,', 'ner-michoel-core' ), $name ) . "\n\n";
	$text .= sprintf( /* translators: %s: who the email is from (Yeshivas Toras Moshe) */ __( 'Someone just entered your password to sign in to %s. Your sign-in code is:', 'ner-michoel-core' ), $brand ) . "\n\n";
	$text .= '    ' . $code . "\n\n";
	$text .= sprintf( /* translators: %d: minutes */ _n( 'It works for %d minute.', 'It works for %d minutes.', $minutes, 'ner-michoel-core' ), $minutes ) . "\n\n";
	$text .= sprintf( /* translators: %s: a browser, system and internet address */ __( 'The sign-in was made from: %s.', 'ner-michoel-core' ), $where ) . "\n\n";
	$text .= __( 'If that wasn’t you, don’t give anyone the code, and change your password with “Forgot password?” on the login page. The code alone doesn’t let anyone in.', 'ner-michoel-core' ) . "\n\n";
	$text .= $brand . "\n" . $home . "\n";

	/* translators: %s: who the email is from (Yeshivas Toras Moshe) */
	$preheader = sprintf( __( 'Enter this code to finish signing in to %s.', 'ner-michoel-core' ), $brand );
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
	$html .= '<h1 style="margin:14px 0 10px;font-size:22px;line-height:1.3;color:#17211d;">' . esc_html__( 'Your sign-in code', 'ner-michoel-core' ) . '</h1>';
	$html .= '<p style="margin:0 0 20px;font-size:15px;line-height:1.6;color:#4b5852;">' . esc_html( sprintf( /* translators: 1: the person's name, 2: who the email is from (Yeshivas Toras Moshe) */ __( 'Hi %1$s, someone just entered your password to sign in to %2$s. Enter this code to finish signing in:', 'ner-michoel-core' ), $name, $brand ) ) . '</p>';
	$html .= '<div style="margin:0 0 20px;padding:18px 12px;text-align:center;background:#eaf6ee;border-radius:12px;font-family:Consolas,Menlo,\'Courier New\',monospace;font-size:36px;font-weight:700;letter-spacing:10px;color:#1d5c38;">' . esc_html( $code ) . '</div>';
	$html .= '<p style="margin:0 0 8px;font-size:14px;line-height:1.5;color:#4b5852;">' . esc_html( sprintf( /* translators: %d: minutes */ _n( 'The code works for %d minute.', 'The code works for %d minutes.', $minutes, 'ner-michoel-core' ), $minutes ) ) . '</p>';
	$html .= '<p style="margin:0 0 8px;font-size:14px;line-height:1.5;color:#4b5852;">' . esc_html( sprintf( /* translators: %s: a browser, system and internet address */ __( 'The sign-in was made from: %s.', 'ner-michoel-core' ), $where ) ) . '</p>';
	$html .= '<p style="margin:0;font-size:14px;line-height:1.5;color:#75827c;">' . esc_html__( 'Wasn’t you? Don’t give anyone the code, and change your password with “Forgot password?” on the login page. The code alone doesn’t let anyone in.', 'ner-michoel-core' ) . '</p>';
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

	// The same note the sign-up code leaves, so the Control Panel's Home and the Security screen can warn.
	ner_michoel_verification_note_mail_result( (bool) $sent, $error );

	return (bool) $sent;
}

/**
 * What the person is told after a send was tried. $where: 'page' (the Account
 * page and /admin, which have a code box), or 'wp-login' (the password is typed
 * again beside the code). Whole sentences, so they can be translated as they read.
 */
function ner_michoel_login_code_message( $status, $email, $where = 'page' ) {
	$hint = ner_michoel_login_code_mask_email( $email );
	$tail = 'wp-login' === $where ? ' ' . __( 'Type your password again, add the code below and log in.', 'ner-michoel-core' ) : '';

	if ( 'sent' === $status ) {
		/* translators: %s: an email address with its name hidden (j***@gmail.com) */
		$line = 'wp-login' === $where
			? sprintf( __( 'We’ve emailed a 6-digit code to %s.', 'ner-michoel-core' ), $hint )
			: sprintf( __( 'We’ve emailed a 6-digit code to %s. Enter it to finish signing in.', 'ner-michoel-core' ), $hint );
		return $line . $tail;
	}
	if ( 'cooldown' === $status ) {
		return __( 'We emailed you a code a moment ago, and it still works.', 'ner-michoel-core' ) . $tail;
	}
	if ( 'capped' === $status ) {
		return __( 'We’ve already sent several sign-in codes to this address. Please check your inbox and spam folder, or try again in an hour.', 'ner-michoel-core' );
	}
	return __( 'We couldn’t email you a sign-in code just now. Please try again in a few seconds.', 'ner-michoel-core' );
}

/* ------------------------------------------------------------------
 * Checking a code.
 * ------------------------------------------------------------------ */

/**
 * Checks a typed code. Returns array( 'status' => 'ok' | 'bad_format' | 'expired' | 'locked' | 'wrong',
 * 'left' => tries left, after a wrong one ). A right code is used up.
 */
function ner_michoel_login_code_verify( $user_id, $code ) {
	$code = preg_replace( '/\D/', '', (string) $code );
	if ( strlen( $code ) !== NER_MICHOEL_LOGIN_CODE_LENGTH ) {
		return array( 'status' => 'bad_format' );
	}

	wp_cache_delete( $user_id, 'user_meta' );
	$stored  = (string) get_user_meta( $user_id, 'nm_lc_code', true );
	$expires = (int) get_user_meta( $user_id, 'nm_lc_expires', true );
	if ( '' === $stored || $expires < time() ) {
		return array( 'status' => 'expired' );
	}

	// A try is used up before the code is looked at, atomically.
	if ( ! ner_michoel_login_code_take_attempt( $user_id ) ) {
		return array( 'status' => 'locked' );
	}

	if ( ! hash_equals( $stored, ner_michoel_login_code_hash( $user_id, $code ) ) ) {
		return array(
			'status' => 'wrong',
			'left'   => max( 0, NER_MICHOEL_LOGIN_CODE_MAX_ATTEMPTS - ner_michoel_login_code_attempts_used( $user_id ) ),
		);
	}

	// One use: deleting the stored hash is a single statement, so of two parallel right answers only one wins.
	if ( ! delete_user_meta( $user_id, 'nm_lc_code', $stored ) ) {
		return array( 'status' => 'expired' );
	}
	ner_michoel_login_code_clear( $user_id );

	return array( 'status' => 'ok' );
}

/** What the person is told after a check that didn't pass. $where as in ner_michoel_login_code_message(). */
function ner_michoel_login_code_verify_message( array $result, $where = 'page' ) {
	switch ( $result['status'] ) {
		case 'bad_format':
			return __( 'Please enter the 6-digit code from the email.', 'ner-michoel-core' );
		case 'expired':
			return 'wp-login' === $where
				? __( 'That code has expired. Log in again with your password and we’ll email a new one.', 'ner-michoel-core' )
				: __( 'That code has expired. Please press “Resend code” to get a new one.', 'ner-michoel-core' );
		case 'locked':
			return 'wp-login' === $where
				? __( 'Too many wrong codes. Log in again with your password and we’ll email a new one.', 'ner-michoel-core' )
				: __( 'Too many wrong codes. Please press “Resend code” to get a new one.', 'ner-michoel-core' );
		default:
			$left = isset( $result['left'] ) ? (int) $result['left'] : 0;
			if ( 0 === $left ) {
				return 'wp-login' === $where
					? __( 'That code isn’t right, and you’re out of tries. Log in again with your password and we’ll email a new one.', 'ner-michoel-core' )
					: __( 'That code isn’t right, and you’re out of tries. Please press “Resend code” to get a new one.', 'ner-michoel-core' );
			}
			return sprintf(
				/* translators: %d: tries left */
				_n( 'That code isn’t right. You have %d try left.', 'That code isn’t right. You have %d tries left.', $left, 'ner-michoel-core' ),
				$left
			);
	}
}

/**
 * The account whose sign-in is waiting for this email and token, or false in
 * every case that isn't exactly that, with nothing to tell the cases apart.
 */
function ner_michoel_login_code_find_session( $email, $token ) {
	if ( ! is_email( $email ) || ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
		return false;
	}
	$user = get_user_by( 'email', $email );
	if ( ! $user ) {
		return false;
	}
	$stored = (string) get_user_meta( $user->ID, 'nm_lc_token', true );
	if ( '' === $stored || ! hash_equals( $stored, ner_michoel_login_code_hash_token( $token ) ) ) {
		return false;
	}
	return $user;
}

/** Backstop on the code routes: how many requests one address may make. True if there's room (and counts this one). */
function ner_michoel_login_code_ip_allowed() {
	$ip = ner_michoel_client_ip();
	if ( ner_michoel_rate_count( 'login_code_ip', $ip ) >= NER_MICHOEL_LOGIN_CODE_IP_LIMIT ) {
		return false;
	}
	ner_michoel_rate_bump( 'login_code_ip', $ip, 15 * MINUTE_IN_SECONDS );
	return true;
}

/**
 * Does this account need a code to sign in here and now? For sign-in routes that
 * don't go through wp_signon() (the /admin shortcut).
 */
function ner_michoel_login_code_needed_for( $user_id ) {
	$user = get_userdata( $user_id );
	return $user && is_email( $user->user_email ) && ner_michoel_login_code_enabled() && ! ner_michoel_trusted_is_trusted( $user_id );
}

/* ------------------------------------------------------------------
 * The gate: every password sign-in passes through here.
 * ------------------------------------------------------------------ */

/** True on wp-login.php, which has no second page: the code is typed beside the password. */
function ner_michoel_login_code_on_wp_login() {
	return isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'];
}

/**
 * After the password is right (priority 20) and before the lockout (99): right
 * password plus a computer we don't know becomes "code needed".
 *
 * Not for: an account waiting for its sign-up code (its own gate, a step
 * earlier), an address that is locked out (the lockout answers that, and no code
 * is emailed for it), a remembered computer, or a request signed in with an
 * Application Password (XML-RPC, if someone turns it on; the deploy calls never
 * pass here at all).
 */
function ner_michoel_login_code_gate( $user, $username = '', $password = '' ) {
	if ( ! $user instanceof WP_User || '' === (string) $password || ! ner_michoel_login_code_enabled() ) {
		return $user; // No password typed means no password sign-in (WordPress also passes a cookie's user through here).
	}
	if ( ! is_email( $user->user_email ) ) {
		return $user; // Nowhere to send a code: such an account could never sign in at all.
	}
	if ( function_exists( 'ner_michoel_user_is_unverified' ) && ner_michoel_user_is_unverified( $user->ID ) ) {
		return $user;
	}
	if ( ! empty( $GLOBALS['nm_lc_app_password'] ) && empty( $GLOBALS['nm_lc_session_login'] ) ) {
		return $user;
	}
	if ( ner_michoel_rate_count( 'login_fail', ner_michoel_client_ip() ) >= NER_MICHOEL_LOGIN_FAIL_LIMIT ) {
		return $user;
	}

	if ( ner_michoel_trusted_is_trusted( $user->ID ) ) {
		$GLOBALS['nm_lc_renew_trusted'] = $user->ID; // Its dates move on once the sign-in has worked (wp_login, below).
		return $user;
	}

	$on_wp_login = ner_michoel_login_code_on_wp_login();
	$where       = $on_wp_login ? 'wp-login' : 'page';

	// The Account page can only take a code once the theme has the step for it.
	if ( ! empty( $GLOBALS['nm_lc_session_login'] ) && ! ner_michoel_login_code_theme_ready() ) {
		return new WP_Error(
			'nm_login_code_unsupported',
			sprintf(
				/* translators: %s: the address of the regular login page */
				__( 'Signing in now needs a code that is emailed to you, and this page can’t ask for it yet. Please log in at %s instead.', 'ner-michoel-core' ),
				wp_login_url()
			)
		);
	}

	// wp-login.php's second try: the code typed beside the password.
	$typed = ( $on_wp_login && isset( $_POST['nm_login_code'] ) ) ? (string) wp_unslash( $_POST['nm_login_code'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- wp-login.php's own form; the password it carries is the proof.
	if ( '' !== trim( $typed ) ) {
		$result = ner_michoel_login_code_verify( $user->ID, $typed );
		if ( 'ok' === $result['status'] ) {
			$GLOBALS['nm_lc_verified'] = $user->ID; // wp_login, below, remembers the computer if asked to.
			return $user;
		}
		$GLOBALS['nm_lc_show_field'] = true;
		return new WP_Error( 'nm_login_code_wrong', ner_michoel_login_code_verify_message( $result, 'wp-login' ) );
	}

	$begin = ner_michoel_login_code_begin( $user->ID );
	$GLOBALS['nm_lc_show_field'] = true;

	return new WP_Error(
		'nm_login_code_needed',
		ner_michoel_login_code_message( $begin['status'], $user->user_email, $where ),
		array(
			'user_id'   => $user->ID,
			'email'     => strtolower( $user->user_email ),
			'token'     => $begin['token'],
			'status'    => $begin['status'],
			'resend_in' => $begin['wait'],
		)
	);
}
add_filter( 'authenticate', 'ner_michoel_login_code_gate', 98, 3 );

/** A request that signed in with an Application Password isn't a browser sign-in. */
function ner_michoel_login_code_note_app_password() {
	$GLOBALS['nm_lc_app_password'] = true;
}
add_action( 'application_password_did_authenticate', 'ner_michoel_login_code_note_app_password' );

/**
 * After a sign-in worked (wp-login.php, wp_signon()): remember the computer if a
 * code was just typed and the box ticked, or renew a remembered one.
 */
function ner_michoel_login_code_after_login( $user_login, $user ) {
	if ( ! $user instanceof WP_User ) {
		return;
	}
	if ( ! empty( $GLOBALS['nm_lc_verified'] ) && (int) $GLOBALS['nm_lc_verified'] === (int) $user->ID ) {
		if ( ! empty( $_POST['nm_remember_computer'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- wp-login.php's own form, already signed in.
			ner_michoel_trusted_add( $user->ID );
		}
		unset( $GLOBALS['nm_lc_verified'] );
		return;
	}
	if ( ! empty( $GLOBALS['nm_lc_renew_trusted'] ) && (int) $GLOBALS['nm_lc_renew_trusted'] === (int) $user->ID ) {
		ner_michoel_trusted_add( $user->ID );
		unset( $GLOBALS['nm_lc_renew_trusted'] );
	}
}
add_action( 'wp_login', 'ner_michoel_login_code_after_login', 20, 2 );

/**
 * wp-login.php: the code box and the remember box, printed under the password
 * once a code is wanted (and again after a wrong one).
 */
function ner_michoel_login_code_login_form() {
	if ( empty( $GLOBALS['nm_lc_show_field'] ) ) {
		return;
	}
	$days = ner_michoel_trusted_days();
	?>
	<p class="nm-login-code">
		<label for="nm_login_code"><?php esc_html_e( 'Sign-in code from the email', 'ner-michoel-core' ); ?></label>
		<input type="text" name="nm_login_code" id="nm_login_code" class="input" value="" size="20" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]*" maxlength="12" placeholder="123456" />
	</p>
	<p class="nm-login-code-remember forgetmenot">
		<label for="nm_remember_computer">
			<input type="checkbox" name="nm_remember_computer" id="nm_remember_computer" value="1" checked="checked" />
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: days */
					_n( 'Remember this computer for %d day', 'Remember this computer for %d days', $days, 'ner-michoel-core' ),
					$days
				)
			);
			?>
		</label>
	</p>
	<?php
	// WordPress empties the username box after any error but a wrong password, and puts the cursor there. Both
	// are put right in the browser: the username is what was typed (encoded so it can't close the script), and
	// the password, typed next, gets the cursor.
	$typed_login = isset( $_POST['log'] ) ? (string) wp_unslash( $_POST['log'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only echoed as an encoded string.
	if ( '' !== $typed_login ) {
		?>
		<script>
			( function () {
				var login = document.getElementById( 'user_login' );
				if ( login && ! login.value ) {
					login.value = <?php echo wp_json_encode( $typed_login, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?>;
				}
				window.setTimeout( function () {
					var pass = document.getElementById( 'user_pass' );
					if ( pass ) {
						pass.focus();
					}
				}, 400 );
			}() );
		</script>
		<?php
	}
}
add_action( 'login_form', 'ner_michoel_login_code_login_form' );

/* ------------------------------------------------------------------
 * A changed password forgets every remembered computer.
 * ------------------------------------------------------------------ */

function ner_michoel_login_code_forget_after_reset( $user ) {
	if ( $user instanceof WP_User ) {
		ner_michoel_trusted_forget( $user->ID );
	}
}
add_action( 'after_password_reset', 'ner_michoel_login_code_forget_after_reset' );

/**
 * wp_update_user() and the profile screens: only when the stored password really
 * changed. (Not wp_set_password(): WordPress calls it itself to re-hash an old
 * password at sign-in, which is no change of password.)
 */
function ner_michoel_login_code_forget_after_update( $user_id, $old_user_data ) {
	$user = get_userdata( $user_id );
	if ( $user && $old_user_data instanceof WP_User && $old_user_data->user_pass !== $user->user_pass ) {
		ner_michoel_trusted_forget( $user_id );
	}
}
add_action( 'profile_update', 'ner_michoel_login_code_forget_after_update', 10, 2 );

/* ------------------------------------------------------------------
 * REST: the Account page's code step.
 * ------------------------------------------------------------------ */

function ner_michoel_login_code_register_routes() {
	register_rest_route(
		'ner-michoel/v1',
		'/account-login-code',
		array(
			'methods'             => 'POST',
			'callback'            => 'ner_michoel_handle_login_code_verify',
			'permission_callback' => '__return_true',
		)
	);
	register_rest_route(
		'ner-michoel/v1',
		'/account-login-code-resend',
		array(
			'methods'             => 'POST',
			'callback'            => 'ner_michoel_handle_login_code_resend',
			'permission_callback' => '__return_true',
		)
	);
	register_rest_route(
		'ner-michoel/v1',
		'/login-code-settings',
		array(
			'methods'             => 'POST',
			'callback'            => 'ner_michoel_handle_login_code_settings',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		)
	);
	register_rest_route(
		'ner-michoel/v1',
		'/account-forget-computers',
		array(
			'methods'             => 'POST',
			'callback'            => 'ner_michoel_handle_forget_computers',
			'permission_callback' => function () {
				return is_user_logged_in();
			},
		)
	);
}
add_action( 'rest_api_init', 'ner_michoel_login_code_register_routes' );

function ner_michoel_login_code_session_error() {
	return ner_michoel_account_error(
		'invalid_session',
		__( 'That sign-in has ended. Please log in with your email and password again, and we’ll send a new code.', 'ner-michoel-core' ),
		400
	);
}

/**
 * What the Account page gets when the password was right and a code is needed:
 * the browser's token, the address the code went to and how long until another
 * can be asked for. $error is the gate's WP_Error.
 */
function ner_michoel_login_code_rest_response( WP_Error $error ) {
	$data = (array) $error->get_error_data();
	return new WP_REST_Response(
		array(
			'success'        => false,
			'login_code'     => true,
			'code'           => 'login_code_required',
			'email'          => isset( $data['email'] ) ? $data['email'] : '',
			'email_hint'     => isset( $data['email'] ) ? ner_michoel_login_code_mask_email( $data['email'] ) : '',
			'token'          => isset( $data['token'] ) ? $data['token'] : '',
			'status'         => isset( $data['status'] ) ? $data['status'] : 'failed',
			'resend_in'      => isset( $data['resend_in'] ) ? (int) $data['resend_in'] : 0,
			'expires_in'     => NER_MICHOEL_LOGIN_CODE_TTL,
			'remember_days'  => ner_michoel_trusted_days(),
			'message'        => $error->get_error_message(),
		),
		200
	);
}

function ner_michoel_handle_login_code_verify( WP_REST_Request $request ) {
	if ( ! ner_michoel_login_code_enabled() ) {
		return ner_michoel_login_code_session_error();
	}
	if ( ! ner_michoel_login_code_ip_allowed() ) {
		return ner_michoel_account_error( 'rate_limited', __( 'Too many tries from your network. Please wait a while and try again.', 'ner-michoel-core' ), 429 );
	}

	$email = strtolower( sanitize_email( (string) $request->get_param( 'email' ) ) );
	$user  = ner_michoel_login_code_find_session( $email, (string) $request->get_param( 'token' ) );
	if ( ! $user ) {
		return ner_michoel_login_code_session_error();
	}

	$result = ner_michoel_login_code_verify( $user->ID, (string) $request->get_param( 'code' ) );
	if ( 'ok' !== $result['status'] ) {
		$extra = array( 'resend_in' => ner_michoel_login_code_wait( $user->ID ) );
		if ( 'wrong' === $result['status'] ) {
			// Counts towards the address's lockout like a wrong password does.
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
		return ner_michoel_account_error( $codes[ $result['status'] ], ner_michoel_login_code_verify_message( $result ), 400, $extra );
	}

	if ( $request->get_param( 'remember' ) ) {
		ner_michoel_trusted_add( $user->ID );
	}
	ner_michoel_sign_in_user( $user->ID );
	do_action( 'wp_login', $user->user_login, $user );

	return new WP_REST_Response( array( 'success' => true, 'name' => $user->display_name ), 200 );
}

function ner_michoel_handle_login_code_resend( WP_REST_Request $request ) {
	if ( ! ner_michoel_login_code_enabled() ) {
		return ner_michoel_login_code_session_error();
	}
	if ( ! ner_michoel_login_code_ip_allowed() ) {
		return ner_michoel_account_error( 'rate_limited', __( 'Too many tries from your network. Please wait a while and try again.', 'ner-michoel-core' ), 429 );
	}

	$email = strtolower( sanitize_email( (string) $request->get_param( 'email' ) ) );
	$user  = ner_michoel_login_code_find_session( $email, (string) $request->get_param( 'token' ) );
	if ( ! $user ) {
		return ner_michoel_login_code_session_error();
	}

	$result = ner_michoel_login_code_send( $user->ID );

	switch ( $result['status'] ) {
		case 'sent':
			return new WP_REST_Response(
				array(
					'success'   => true,
					'message'   => sprintf( /* translators: %s: an email address with its name hidden (j***@gmail.com) */ __( 'A new code is on its way to %s.', 'ner-michoel-core' ), ner_michoel_login_code_mask_email( $user->user_email ) ),
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
			return ner_michoel_account_error( 'capped', ner_michoel_login_code_message( 'capped', $user->user_email ), 429 );
		default:
			return ner_michoel_account_error( 'send_failed', ner_michoel_login_code_message( 'failed', $user->user_email ), 500, array( 'resend_in' => $result['wait'] ) );
	}
}

/**
 * Changes only the sign-in code switch and the days (`enabled`, `days`; one may be left out), nothing else of the
 * Security screen. It is the way back in by Application Password if the site's email stops working.
 */
function ner_michoel_handle_login_code_settings( WP_REST_Request $request ) {
	$settings = ner_michoel_form_guard_settings();

	$enabled = $request->get_param( 'enabled' );
	if ( null !== $enabled ) {
		$settings['login_code'] = rest_sanitize_boolean( $enabled );
	}
	$days = $request->get_param( 'days' );
	if ( null !== $days && '' !== $days ) {
		$settings['login_code_days'] = max( 1, min( 365, (int) $days ) );
	}
	update_option( 'nm_form_guard', $settings, false );

	return new WP_REST_Response(
		array(
			'saved'   => true,
			'enabled' => (bool) $settings['login_code'],
			'days'    => (int) $settings['login_code_days'],
		),
		200
	);
}

/** The Account page's "Forget remembered computers": every computer, this one included. Stays signed in. */
function ner_michoel_handle_forget_computers() {
	$forgotten = ner_michoel_trusted_forget( get_current_user_id() );
	return new WP_REST_Response(
		array(
			'success'   => true,
			'forgotten' => $forgotten,
			'message'   => __( 'Done. Every computer will ask for a code the next time it signs in.', 'ner-michoel-core' ),
		),
		200
	);
}

/* ------------------------------------------------------------------
 * wp-admin: the profile screen shows the remembered computers and can forget them.
 * ------------------------------------------------------------------ */

function ner_michoel_login_code_profile_section( $profile_user ) {
	if ( ! $profile_user instanceof WP_User || ! current_user_can( 'edit_user', $profile_user->ID ) ) {
		return;
	}
	$list = ner_michoel_trusted_list( $profile_user->ID );
	$url  = wp_nonce_url(
		add_query_arg(
			array(
				'action' => 'nm_forget_computers',
				'user'   => $profile_user->ID,
			),
			admin_url( 'admin.php' )
		),
		'nm_forget_computers_' . $profile_user->ID
	);
	?>
	<h2><?php esc_html_e( 'Sign-in codes', 'ner-michoel-core' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Remembered computers', 'ner-michoel-core' ); ?></th>
			<td>
				<?php if ( $list ) : ?>
					<ul style="margin:0 0 8px;">
						<?php foreach ( $list as $item ) : ?>
							<li>
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: a browser and system, 2: the date it was last used */
										__( '%1$s, last used %2$s', 'ner-michoel-core' ),
										$item['label'],
										wp_date( get_option( 'date_format' ), (int) $item['used'] )
									)
								);
								?>
							</li>
						<?php endforeach; ?>
					</ul>
					<a class="button" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Forget remembered computers', 'ner-michoel-core' ); ?></a>
					<p class="description"><?php esc_html_e( 'These computers sign in with the password alone. Forgetting them makes every computer ask for an emailed code next time.', 'ner-michoel-core' ); ?></p>
				<?php else : ?>
					<?php esc_html_e( 'None. Every sign-in asks for an emailed code.', 'ner-michoel-core' ); ?>
				<?php endif; ?>
			</td>
		</tr>
	</table>
	<?php
}
add_action( 'show_user_profile', 'ner_michoel_login_code_profile_section' );
add_action( 'edit_user_profile', 'ner_michoel_login_code_profile_section' );

function ner_michoel_login_code_handle_forget_action() {
	$user_id = isset( $_GET['user'] ) ? absint( $_GET['user'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked just below.
	check_admin_referer( 'nm_forget_computers_' . $user_id );
	if ( ! $user_id || ! current_user_can( 'edit_user', $user_id ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'ner-michoel-core' ) );
	}
	ner_michoel_trusted_forget( $user_id );
	wp_safe_redirect( add_query_arg( 'nm_forgot', '1', get_edit_user_link( $user_id ) ) );
	exit;
}
add_action( 'admin_action_nm_forget_computers', 'ner_michoel_login_code_handle_forget_action' );

function ner_michoel_login_code_forgot_notice() {
	if ( isset( $_GET['nm_forgot'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only a message.
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Remembered computers forgotten. The next sign-in on each will ask for a code.', 'ner-michoel-core' ) . '</p></div>';
	}
}
add_action( 'admin_notices', 'ner_michoel_login_code_forgot_notice' );
