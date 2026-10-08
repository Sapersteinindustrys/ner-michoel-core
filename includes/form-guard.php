<?php
/**
 * Bot and spam protection for the public forms: Contact, Email a Magid
 * Shiur, and the Account page's Sign Up, Log In and Forgot Password.
 *
 * Layers, each cheap for a person and costly for a bot. A submission is
 * turned away at the first one it fails:
 *
 *   1. The honeypot: each form's hidden field (checked in forms.php and
 *      accounts.php). Anything in it is a bot, and is told it succeeded.
 *   2. A browser check (proof of work). The page asks for a signed
 *      challenge (GET ner-michoel/v1/form-challenge) and the visitor's
 *      browser finds a number whose SHA-256 with it starts with enough zero
 *      bits (assets/form-guard.js). About a second for a browser, while the
 *      visitor types; real CPU for a bot sending thousands. Each challenge
 *      is signed, works once, and can't be used in the first few seconds,
 *      so a form sent the instant the page opens is turned away too.
 *   3. Cloudflare Turnstile, when keys are saved (Site Control Panel > Site
 *      Settings > Security). Usually invisible.
 *   4. Rate limits per IP address: how often each form can be sent.
 *   5. Content checks on messages (links, markup, repeats), in forms.php.
 *      Flagged messages are kept under Submissions as "spam" and not emailed,
 *      so a false alarm is never lost.
 *
 * Every login (this site's, wp-login.php, anything through wp_signon()) is
 * also locked for a while after repeated wrong passwords from one address.
 * And two switches, both on by default: hide who the site's users are (the
 * public REST users list, ?author=N, the users sitemap, oEmbed's author),
 * and turn off XML-RPC, which bots use to guess passwords in bulk.
 *
 * Layers 2 and 3 are only required once the theme renders their fields
 * (it declares add_theme_support( 'nm-form-guard' )), so the plugin and the
 * theme can be updated in either order without a form breaking.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * How long a challenge stays usable after it's issued.
 */
define( 'NER_MICHOEL_FORM_GUARD_MAX_AGE', 6 * HOUR_IN_SECONDS );

/**
 * Wrong passwords allowed from one address in 15 minutes before its logins
 * are refused for the rest of that time. High enough that a school or office
 * sharing one address isn't locked out by a few typos.
 */
define( 'NER_MICHOEL_LOGIN_FAIL_LIMIT', 10 );

/**
 * The guarded forms. min_seconds: how soon after its challenge a form may be
 * sent (the script waits it out for a person; a bot posting instantly is
 * refused). limit / window: sends allowed per IP address.
 */
function ner_michoel_form_guard_forms() {
	return array(
		'contact' => array( 'min_seconds' => 4, 'limit' => 5, 'window' => HOUR_IN_SECONDS ),
		'magid'   => array( 'min_seconds' => 4, 'limit' => 5, 'window' => HOUR_IN_SECONDS ),
		'signup'  => array( 'min_seconds' => 3, 'limit' => 5, 'window' => HOUR_IN_SECONDS ),
		'login'   => array( 'min_seconds' => 0, 'limit' => 30, 'window' => 15 * MINUTE_IN_SECONDS ),
		'forgot'  => array( 'min_seconds' => 2, 'limit' => 5, 'window' => HOUR_IN_SECONDS ),
	);
}

/**
 * Zero bits the browser check needs: each extra bit doubles the work.
 * 17 takes a phone a second or two, and runs while the form is filled in.
 */
function ner_michoel_form_guard_bits() {
	return max( 8, min( 24, (int) apply_filters( 'ner_michoel_form_guard_bits', 17 ) ) );
}

function ner_michoel_form_guard_settings() {
	$saved = get_option( 'nm_form_guard', array() );
	return wp_parse_args(
		is_array( $saved ) ? $saved : array(),
		array(
			'turnstile_site_key'   => '',
			'turnstile_secret_key' => '',
			'hide_users'           => true,
			'disable_xmlrpc'       => true,
		)
	);
}

function ner_michoel_turnstile_enabled() {
	$settings = ner_michoel_form_guard_settings();
	return '' !== $settings['turnstile_site_key'] && '' !== $settings['turnstile_secret_key'];
}

/**
 * True once the theme renders the guard's fields in its forms.
 */
function ner_michoel_form_guard_theme_ready() {
	return current_theme_supports( 'nm-form-guard' );
}

/* ------------------------------------------------------------------
 * The visitor's address, and rate limits keyed on it.
 * ------------------------------------------------------------------ */

/**
 * REMOTE_ADDR, unless that's a private address (a proxy on the host's own
 * network). Then the visitor's address is the last public one in
 * X-Forwarded-For: the proxy appends it, and only the entries before it
 * could have been made up by the visitor.
 */
function ner_michoel_client_ip() {
	$is_public = function ( $ip ) {
		return (bool) filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	};

	$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated as an IP below.
	if ( $is_public( $remote ) ) {
		return $remote;
	}

	foreach ( array( 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP' ) as $header ) {
		if ( empty( $_SERVER[ $header ] ) ) {
			continue;
		}
		$ips = array_reverse( array_map( 'trim', explode( ',', wp_unslash( $_SERVER[ $header ] ) ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each entry validated as an IP.
		foreach ( $ips as $ip ) {
			if ( $is_public( $ip ) ) {
				return $ip;
			}
		}
	}

	return filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '0.0.0.0';
}

function ner_michoel_rate_key( $bucket, $id ) {
	return 'nm_rl_' . $bucket . '_' . md5( (string) $id );
}

/**
 * Hits so far in the current window for one bucket (a form, failed logins)
 * and one id (an address, an email).
 */
function ner_michoel_rate_count( $bucket, $id ) {
	$data = get_transient( ner_michoel_rate_key( $bucket, $id ) );
	return ( is_array( $data ) && isset( $data['until'], $data['count'] ) && $data['until'] > time() ) ? (int) $data['count'] : 0;
}

/**
 * Adds a hit. The window starts at the first hit and doesn't slide.
 */
function ner_michoel_rate_bump( $bucket, $id, $window ) {
	$key  = ner_michoel_rate_key( $bucket, $id );
	$data = get_transient( $key );
	if ( ! is_array( $data ) || ! isset( $data['until'], $data['count'] ) || $data['until'] <= time() ) {
		$data = array(
			'count' => 0,
			'until' => time() + (int) $window,
		);
	}
	++$data['count'];
	set_transient( $key, $data, max( 1, $data['until'] - time() ) );
	return $data['count'];
}

function ner_michoel_rate_clear( $bucket, $id ) {
	delete_transient( ner_michoel_rate_key( $bucket, $id ) );
}

/* ------------------------------------------------------------------
 * The browser check (proof of work).
 * ------------------------------------------------------------------ */

function ner_michoel_form_guard_key() {
	return hash( 'sha256', wp_salt( 'nonce' ) . '|nm-form-guard' );
}

/**
 * A signed challenge: "v1|form|issued|bits|salt|signature". The browser
 * hashes it with ":" and a number until enough leading bits are zero.
 */
function ner_michoel_form_guard_challenge( $form ) {
	$payload = implode(
		'|',
		array( 'v1', $form, time(), ner_michoel_form_guard_bits(), wp_generate_password( 16, false, false ) )
	);
	return $payload . '|' . hash_hmac( 'sha256', $payload, ner_michoel_form_guard_key() );
}

function ner_michoel_form_guard_leading_zero_bits( $binary ) {
	$bits   = 0;
	$length = strlen( $binary );
	for ( $i = 0; $i < $length; $i++ ) {
		$byte = ord( $binary[ $i ] );
		if ( 0 === $byte ) {
			$bits += 8;
			continue;
		}
		return $bits + 8 - strlen( decbin( $byte ) );
	}
	return $bits;
}

/**
 * True, or why the browser check failed. A good answer is used up here, so
 * it can't be sent twice.
 */
function ner_michoel_form_guard_check_work( $form, $token, $answer ) {
	$parts = explode( '|', $token );
	if ( '' === $token || 6 !== count( $parts ) || 'v1' !== $parts[0] ) {
		return 'work_missing';
	}

	$payload = implode( '|', array_slice( $parts, 0, 5 ) );
	if ( ! hash_equals( hash_hmac( 'sha256', $payload, ner_michoel_form_guard_key() ), $parts[5] ) || $parts[1] !== $form ) {
		return 'work_invalid';
	}

	$forms = ner_michoel_form_guard_forms();
	$age   = time() - (int) $parts[2];
	if ( $age < $forms[ $form ]['min_seconds'] ) {
		return 'too_fast';
	}
	if ( $age > NER_MICHOEL_FORM_GUARD_MAX_AGE ) {
		return 'work_expired';
	}

	$bits = (int) $parts[3];
	if ( $bits < ner_michoel_form_guard_bits() || ! preg_match( '/^\d{1,12}$/', $answer )
		|| ner_michoel_form_guard_leading_zero_bits( hash( 'sha256', $token . ':' . $answer, true ) ) < $bits ) {
		return 'work_invalid';
	}

	$used = 'nm_fg_used_' . substr( $parts[5], 0, 40 );
	if ( get_transient( $used ) ) {
		return 'work_reused';
	}
	set_transient( $used, 1, NER_MICHOEL_FORM_GUARD_MAX_AGE );

	return true;
}

function ner_michoel_register_form_guard_routes() {
	register_rest_route(
		'ner-michoel/v1',
		'/form-challenge',
		array(
			'methods'             => 'GET',
			'callback'            => 'ner_michoel_handle_form_challenge',
			'permission_callback' => '__return_true',
			'args'                => array(
				'form' => array(
					'required'          => true,
					'validate_callback' => function ( $value ) {
						return is_string( $value ) && isset( ner_michoel_form_guard_forms()[ $value ] );
					},
				),
			),
		)
	);

	register_rest_route(
		'ner-michoel/v1',
		'/form-guard-settings',
		array(
			'methods'             => 'POST',
			'callback'            => 'ner_michoel_handle_form_guard_settings_rest',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		)
	);
}
add_action( 'rest_api_init', 'ner_michoel_register_form_guard_routes' );

function ner_michoel_handle_form_challenge( WP_REST_Request $request ) {
	$form     = $request->get_param( 'form' );
	$forms    = ner_michoel_form_guard_forms();
	$response = new WP_REST_Response(
		array(
			'token' => ner_michoel_form_guard_challenge( $form ),
			'bits'  => ner_michoel_form_guard_bits(),
			'wait'  => $forms[ $form ]['min_seconds'],
			'ttl'   => NER_MICHOEL_FORM_GUARD_MAX_AGE,
		),
		200
	);
	// Every visitor needs a challenge of their own: never from a cache.
	$response->header( 'Cache-Control', 'no-store, max-age=0' );
	return $response;
}

/* ------------------------------------------------------------------
 * Cloudflare Turnstile.
 * ------------------------------------------------------------------ */

/**
 * Asks Cloudflare whether the widget's token is good. True, or why not.
 * If Cloudflare can't be reached, or rejects the saved secret key, the
 * form goes on to the other layers rather than turning everyone away;
 * a rejected key is noted for the Security screen.
 */
function ner_michoel_turnstile_check( $token, $action ) {
	if ( '' === $token ) {
		return 'turnstile_missing';
	}

	$result = ner_michoel_turnstile_siteverify( ner_michoel_form_guard_settings()['turnstile_secret_key'], $token );
	if ( null === $result ) {
		return true;
	}

	$codes = isset( $result['error-codes'] ) ? (array) $result['error-codes'] : array();
	if ( array_intersect( $codes, array( 'invalid-input-secret', 'missing-input-secret' ) ) ) {
		update_option( 'nm_form_guard_turnstile_problem', time(), false );
		return true;
	}

	if ( empty( $result['success'] ) || ( ! empty( $result['action'] ) && $result['action'] !== $action ) ) {
		return 'turnstile_failed';
	}
	return true;
}

/**
 * Cloudflare's answer as an array, or null when it couldn't be reached.
 * Read whatever the status: a bad secret key comes back as a 400 with its
 * reason in the JSON (invalid-input-secret).
 */
function ner_michoel_turnstile_siteverify( $secret, $token ) {
	$response = wp_remote_post(
		'https://challenges.cloudflare.com/turnstile/v0/siteverify',
		array(
			'timeout' => 8,
			'body'    => array(
				'secret'   => $secret,
				'response' => $token,
				'remoteip' => ner_michoel_client_ip(),
			),
		)
	);
	if ( is_wp_error( $response ) ) {
		return null;
	}
	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	return ( is_array( $body ) && array_key_exists( 'success', $body ) ) ? $body : null;
}

/* ------------------------------------------------------------------
 * Checking a submission.
 * ------------------------------------------------------------------ */

/**
 * Runs the browser check, the rate limit and Turnstile for one submission.
 * $fields: what was posted ($_POST unslashed, or a REST request's params).
 * True, or the reason it was turned away (logged for the Security screen).
 */
function ner_michoel_form_guard_verify( $form, array $fields ) {
	$forms = ner_michoel_form_guard_forms();
	if ( ! isset( $forms[ $form ] ) ) {
		return true;
	}
	$read = function ( $key ) use ( $fields ) {
		return ( isset( $fields[ $key ] ) && is_string( $fields[ $key ] ) ) ? trim( $fields[ $key ] ) : '';
	};

	if ( ner_michoel_form_guard_theme_ready() ) {
		$work = ner_michoel_form_guard_check_work( $form, $read( 'nm_fg_token' ), $read( 'nm_fg_answer' ) );
		if ( true !== $work ) {
			return ner_michoel_form_guard_block( $work );
		}
	}

	// Counted after the browser check, so only sends from real browsers use up
	// an address's allowance (a school sharing one address keeps its share).
	$ip = ner_michoel_client_ip();
	if ( ner_michoel_rate_count( 'form_' . $form, $ip ) >= $forms[ $form ]['limit'] ) {
		return ner_michoel_form_guard_block( 'rate_limited' );
	}
	ner_michoel_rate_bump( 'form_' . $form, $ip, $forms[ $form ]['window'] );

	if ( ner_michoel_form_guard_theme_ready() && ner_michoel_turnstile_enabled() ) {
		$turnstile = ner_michoel_turnstile_check( $read( 'cf-turnstile-response' ), $form );
		if ( true !== $turnstile ) {
			return ner_michoel_form_guard_block( $turnstile );
		}
	}

	return true;
}

/**
 * Logs a turned-away submission and passes its reason back.
 */
function ner_michoel_form_guard_block( $reason ) {
	ner_michoel_form_guard_log( $reason );
	return $reason;
}

/**
 * Message checks for Contact and Email a Magid Shiur: '' when it reads like
 * a person, otherwise why it looks like spam. $raw_message is before
 * sanitizing, so markup is still there to see.
 */
function ner_michoel_form_guard_spam_reason( $name, $raw_message ) {
	$link = '#https?://|www\.[a-z0-9-]+\.#i';
	if ( preg_match( $link, $name ) ) {
		return 'links';
	}
	if ( preg_match_all( $link, $raw_message ) > 2 ) {
		return 'links';
	}
	if ( preg_match( '#\[url[=\]]|\[link[=\]]|<a\s[^>]*href#i', $raw_message ) ) {
		return 'markup';
	}
	return '';
}

/**
 * True when the same message came through this form in the last day (a
 * double send, or a bot repeating itself). Remembers it otherwise.
 */
function ner_michoel_form_guard_is_repeat( $form, $message ) {
	$normal = strtolower( preg_replace( '/\s+/u', ' ', trim( $message ) ) );
	$key    = 'nm_fg_seen_' . md5( $form . '|' . $normal );
	if ( get_transient( $key ) ) {
		return true;
	}
	set_transient( $key, 1, DAY_IN_SECONDS );
	return false;
}

/* ------------------------------------------------------------------
 * The record behind the Security screen.
 * ------------------------------------------------------------------ */

function ner_michoel_form_guard_reasons() {
	return array(
		'honeypot'          => __( 'filled in the hidden trap field', 'ner-michoel-core' ),
		'work_missing'      => __( 'sent without the browser check (usually a bot without a real browser)', 'ner-michoel-core' ),
		'work_invalid'      => __( 'failed the browser check', 'ner-michoel-core' ),
		'work_reused'       => __( 'reused an old browser check', 'ner-michoel-core' ),
		'work_expired'      => __( 'sent from a page left open too long', 'ner-michoel-core' ),
		'too_fast'          => __( 'sent too fast to be a person', 'ner-michoel-core' ),
		'turnstile_missing' => __( 'sent without the Cloudflare check', 'ner-michoel-core' ),
		'turnstile_failed'  => __( 'failed the Cloudflare check', 'ner-michoel-core' ),
		'rate_limited'      => __( 'too many sends from one address', 'ner-michoel-core' ),
		'spam_content'      => __( 'looked like spam (links or code), kept under Submissions', 'ner-michoel-core' ),
		'duplicate'         => __( 'repeated a message already received', 'ner-michoel-core' ),
		'login_locked'      => __( 'login refused after repeated wrong passwords', 'ner-michoel-core' ),
		'email_limited'     => __( 'too many password-reset emails to one address', 'ner-michoel-core' ),
	);
}

function ner_michoel_form_guard_log( $reason ) {
	$stats = get_option( 'nm_form_guard_stats', array() );
	if ( ! is_array( $stats ) || empty( $stats['since'] ) ) {
		$stats = array(
			'since'   => time(),
			'total'   => 0,
			'reasons' => array(),
			'days'    => array(),
		);
	}
	$day = gmdate( 'Y-m-d' );

	++$stats['total'];
	$stats['reasons'][ $reason ] = ( isset( $stats['reasons'][ $reason ] ) ? (int) $stats['reasons'][ $reason ] : 0 ) + 1;
	$stats['days'][ $day ]       = ( isset( $stats['days'][ $day ] ) ? (int) $stats['days'][ $day ] : 0 ) + 1;
	$stats['days']               = array_slice( $stats['days'], -30, null, true );

	update_option( 'nm_form_guard_stats', $stats, false );
}

/* ------------------------------------------------------------------
 * The fields a form carries (the theme calls this inside each form).
 * ------------------------------------------------------------------ */

/**
 * The browser check's hidden fields, Turnstile's place when it's on, a
 * status line the script fills in, and a note for visitors without
 * JavaScript. $form: a key of ner_michoel_form_guard_forms().
 */
function ner_michoel_form_guard_fields( $form ) {
	if ( ! isset( ner_michoel_form_guard_forms()[ $form ] ) ) {
		return;
	}
	ner_michoel_form_guard_enqueue();
	$settings = ner_michoel_form_guard_settings();
	?>
	<div class="nm-guard" data-nm-guard="<?php echo esc_attr( $form ); ?>">
		<input type="hidden" name="nm_fg_token" value="" />
		<input type="hidden" name="nm_fg_answer" value="" />
		<?php if ( ner_michoel_turnstile_enabled() ) : ?>
			<div class="nm-guard__turnstile" data-sitekey="<?php echo esc_attr( $settings['turnstile_site_key'] ); ?>"></div>
		<?php endif; ?>
		<p class="nm-guard__status" role="status" hidden></p>
		<noscript><p class="nm-guard__noscript nm-form-notice nm-form-notice--error"><?php esc_html_e( 'This form needs JavaScript turned on. It’s how we tell people from spam bots.', 'ner-michoel-core' ); ?></p></noscript>
	</div>
	<?php
}

function ner_michoel_form_guard_enqueue() {
	if ( wp_script_is( 'ner-michoel-form-guard', 'enqueued' ) ) {
		return;
	}
	wp_enqueue_script( 'ner-michoel-form-guard', NER_MICHOEL_CORE_URL . 'assets/form-guard.js', array(), NER_MICHOEL_CORE_VERSION, true );
	$settings = array(
		'challengeUrl' => rest_url( 'ner-michoel/v1/form-challenge' ),
		'checking'     => __( 'Checking you’re not a bot…', 'ner-michoel-core' ),
		'failed'       => __( 'We couldn’t run the spam check. Please reload the page and try again.', 'ner-michoel-core' ),
		'turnstile'    => __( 'Please complete the check above, then send again.', 'ner-michoel-core' ),
	);
	if ( ner_michoel_turnstile_enabled() ) {
		// Cloudflare asks that this be loaded from its own address, unversioned.
		// The script adds it the first time a form needs the widget.
		$settings['turnstileApi'] = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&onload=nmFormGuardTurnstile';
	}
	wp_localize_script( 'ner-michoel-form-guard', 'nmFormGuardSettings', $settings );
}

/**
 * The theme's page router (nm-router.js) swaps a page's content in without
 * running that page's scripts, so a visitor who clicks through to Contact never
 * gets the browser check if only the Contact page loads it. A theme that carries
 * the check in its forms gets the script on every page; it looks for new forms
 * after each swap.
 */
function ner_michoel_form_guard_enqueue_site_wide() {
	if ( ner_michoel_form_guard_theme_ready() ) {
		ner_michoel_form_guard_enqueue();
	}
}
add_action( 'wp_enqueue_scripts', 'ner_michoel_form_guard_enqueue_site_wide' );

/* ------------------------------------------------------------------
 * Logins: a lockout after repeated wrong passwords.
 * ------------------------------------------------------------------ */

/**
 * Last in the authenticate chain, so it holds whatever the earlier steps
 * decided, even a correct password, while the address is locked out.
 * Application-password requests (the deploy calls) don't pass through here.
 */
function ner_michoel_form_guard_login_lockout( $user ) {
	if ( ner_michoel_rate_count( 'login_fail', ner_michoel_client_ip() ) >= NER_MICHOEL_LOGIN_FAIL_LIMIT ) {
		ner_michoel_form_guard_log( 'login_locked' );
		return new WP_Error( 'nm_too_many_attempts', __( 'Too many failed login attempts from your network. Please wait 15 minutes and try again.', 'ner-michoel-core' ) );
	}
	return $user;
}
add_filter( 'authenticate', 'ner_michoel_form_guard_login_lockout', 99 );

function ner_michoel_form_guard_count_failed_login() {
	ner_michoel_rate_bump( 'login_fail', ner_michoel_client_ip(), 15 * MINUTE_IN_SECONDS );
}
add_action( 'wp_login_failed', 'ner_michoel_form_guard_count_failed_login' );

function ner_michoel_form_guard_clear_failed_logins() {
	ner_michoel_rate_clear( 'login_fail', ner_michoel_client_ip() );
}
add_action( 'wp_login', 'ner_michoel_form_guard_clear_failed_logins' );

/* ------------------------------------------------------------------
 * Hardening switches.
 * ------------------------------------------------------------------ */

/**
 * Hide the users: the public REST users list (its usernames are half of a
 * login), ?author=N (redirects to /author/username/), the users sitemap, and
 * oEmbed's author fields. Staff who can edit posts still see the REST list,
 * which the editor's author picker uses.
 */
function ner_michoel_form_guard_hide_users() {
	return (bool) ner_michoel_form_guard_settings()['hide_users'] && ! current_user_can( 'edit_posts' );
}

function ner_michoel_form_guard_rest_endpoints( $endpoints ) {
	if ( ner_michoel_form_guard_hide_users() ) {
		unset( $endpoints['/wp/v2/users'], $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );
	}
	return $endpoints;
}
add_filter( 'rest_endpoints', 'ner_michoel_form_guard_rest_endpoints' );

function ner_michoel_form_guard_author_redirect() {
	if ( ! ner_michoel_form_guard_hide_users() ) {
		return;
	}
	if ( is_author() || isset( $_GET['author'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing check.
		wp_safe_redirect( home_url( '/' ), 301 );
		exit;
	}
}
// Before redirect_canonical (priority 10), which would send ?author=1 to /author/username/.
add_action( 'template_redirect', 'ner_michoel_form_guard_author_redirect', 1 );

function ner_michoel_form_guard_sitemap_providers( $provider, $name ) {
	return ( 'users' === $name && ner_michoel_form_guard_settings()['hide_users'] ) ? false : $provider;
}
add_filter( 'wp_sitemaps_add_provider', 'ner_michoel_form_guard_sitemap_providers', 10, 2 );

function ner_michoel_form_guard_oembed_data( $data ) {
	if ( ner_michoel_form_guard_settings()['hide_users'] ) {
		unset( $data['author_name'], $data['author_url'] );
	}
	return $data;
}
add_filter( 'oembed_response_data', 'ner_michoel_form_guard_oembed_data' );

/**
 * XML-RPC: nothing on this site uses it (the deploy calls and the Site
 * Control Panel use the REST API), and bots use its multicall to try many
 * passwords in one request. Off: no methods, no pingbacks.
 */
function ner_michoel_form_guard_xmlrpc_off() {
	return (bool) ner_michoel_form_guard_settings()['disable_xmlrpc'];
}

function ner_michoel_form_guard_xmlrpc_enabled( $enabled ) {
	return ner_michoel_form_guard_xmlrpc_off() ? false : $enabled;
}
add_filter( 'xmlrpc_enabled', 'ner_michoel_form_guard_xmlrpc_enabled' );

function ner_michoel_form_guard_xmlrpc_methods( $methods ) {
	return ner_michoel_form_guard_xmlrpc_off() ? array() : $methods;
}
add_filter( 'xmlrpc_methods', 'ner_michoel_form_guard_xmlrpc_methods' );

function ner_michoel_form_guard_headers( $headers ) {
	if ( ner_michoel_form_guard_xmlrpc_off() ) {
		unset( $headers['X-Pingback'] );
	}
	return $headers;
}
add_filter( 'wp_headers', 'ner_michoel_form_guard_headers' );

/* ------------------------------------------------------------------
 * Settings (Site Control Panel > Site Settings > Security).
 * ------------------------------------------------------------------ */

/**
 * Saves the screen. A blank secret keeps the saved one; clearing the site key
 * turns Turnstile off. A new secret is tried against Cloudflare first, so a
 * mistyped one is caught here, not by visitors.
 */
function ner_michoel_handle_form_guard_settings_rest( WP_REST_Request $request ) {
	$settings = ner_michoel_form_guard_settings();
	$clean    = function ( $value ) {
		return preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $value );
	};

	$site_key = $clean( $request->get_param( 'turnstile_site_key' ) );
	$secret   = $clean( $request->get_param( 'turnstile_secret_key' ) );

	if ( '' === $site_key ) {
		$settings['turnstile_site_key']   = '';
		$settings['turnstile_secret_key'] = '';
	} else {
		if ( '' !== $secret ) {
			$check = ner_michoel_turnstile_siteverify( $secret, 'nm-key-check' );
			$codes = ( $check && isset( $check['error-codes'] ) ) ? (array) $check['error-codes'] : array();
			if ( in_array( 'invalid-input-secret', $codes, true ) ) {
				return new WP_REST_Response( array( 'message' => __( 'Cloudflare didn’t accept that secret key. Check it and save again.', 'ner-michoel-core' ) ), 400 );
			}
			$settings['turnstile_secret_key'] = $secret;
		}
		if ( '' === $settings['turnstile_secret_key'] ) {
			return new WP_REST_Response( array( 'message' => __( 'Add the secret key too. Turnstile needs both keys.', 'ner-michoel-core' ) ), 400 );
		}
		$settings['turnstile_site_key'] = $site_key;
	}

	$settings['hide_users']     = rest_sanitize_boolean( $request->get_param( 'hide_users' ) );
	$settings['disable_xmlrpc'] = rest_sanitize_boolean( $request->get_param( 'disable_xmlrpc' ) );

	update_option( 'nm_form_guard', $settings, false );
	delete_option( 'nm_form_guard_turnstile_problem' );

	return new WP_REST_Response( array( 'saved' => true ), 200 );
}

/**
 * The status lines over the Security screen's fields: what's on, and what
 * it has turned away.
 */
function ner_michoel_form_guard_status_lines() {
	$lines = array();

	$lines[] = ner_michoel_form_guard_theme_ready()
		? __( 'Browser check: on, for Contact, Email a Magid Shiur, Sign Up, Log In and Forgot Password.', 'ner-michoel-core' )
		: __( 'Browser check: waiting for the theme update that adds it to the forms. Rate limits, the login lockout and the message checks are on.', 'ner-michoel-core' );

	$lines[] = ner_michoel_turnstile_enabled()
		? __( 'Cloudflare Turnstile: on. After changing keys, send a test message from the Contact page.', 'ner-michoel-core' )
		: __( 'Cloudflare Turnstile: off. Add both keys below to turn it on.', 'ner-michoel-core' );

	// The rate limits count per address, so they rely on seeing each visitor's
	// own. If this shows the server's address instead, they'd count everyone
	// together.
	$lines[] = sprintf(
		/* translators: %s: an IP address */
		__( 'This site sees your internet address as %s. The per-address limits rely on that being your own address (compare it with a “what is my IP” site).', 'ner-michoel-core' ),
		ner_michoel_client_ip()
	);

	$problem = (int) get_option( 'nm_form_guard_turnstile_problem' );
	if ( $problem ) {
		/* translators: %s: date and time */
		$lines[] = sprintf( __( 'Warning: Cloudflare rejected the saved secret key (%s), so Turnstile is being skipped. Save the correct key.', 'ner-michoel-core' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $problem ) );
	}

	$stats = get_option( 'nm_form_guard_stats', array() );
	if ( ! is_array( $stats ) || empty( $stats['total'] ) ) {
		$lines[] = __( 'Nothing turned away yet.', 'ner-michoel-core' );
		return $lines;
	}

	$week = 0;
	foreach ( $stats['days'] as $day => $count ) {
		if ( strtotime( $day . ' 00:00:00 UTC' ) >= strtotime( '-6 days 00:00:00 UTC' ) ) {
			$week += (int) $count;
		}
	}
	$lines[] = sprintf(
		/* translators: 1: total count, 2: date, 3: count in the last 7 days */
		__( 'Turned away %1$s since %2$s, %3$s of them in the last 7 days:', 'ner-michoel-core' ),
		number_format_i18n( (int) $stats['total'] ),
		wp_date( get_option( 'date_format' ), (int) $stats['since'] ),
		number_format_i18n( $week )
	);

	$labels  = ner_michoel_form_guard_reasons();
	$reasons = $stats['reasons'];
	arsort( $reasons );
	foreach ( $reasons as $reason => $count ) {
		$lines[] = sprintf( '%s: %s', number_format_i18n( (int) $count ), isset( $labels[ $reason ] ) ? $labels[ $reason ] : $reason );
	}

	return $lines;
}
