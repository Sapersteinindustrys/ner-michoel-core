<?php
/**
 * Beta testing mode: a banner across the top of every page saying the site
 * is a beta, with a "Report a problem" button that opens a pop-up form.
 *
 * The form asks for a name (required), an optional email, what kind of
 * problem and what happened. With it the browser sends, on its own: the page
 * address and title, the device (browser, system, screen and window size,
 * touch, language, time zone, the visitor's own clock) and the last few
 * script errors on the page. The server adds the time, the signed-in user (if
 * any) and the address it came from.
 *
 * Reports are kept as a private post type and read on the "Beta reports"
 * admin page (wp-admin menu, admins only), where the banner is also switched
 * off when testing is over. Spam: the site's own form guard (browser check +
 * rate limit + Turnstile when that's on, form-guard.php), a hidden trap field,
 * the link / markup check, and a repeat check. Nothing from a plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const NER_MICHOEL_BETA_POST_TYPE = 'nm_beta_report';

/* ------------------------------------------------------------------
 * Settings.
 * ------------------------------------------------------------------ */

function ner_michoel_beta_defaults() {
	return array(
		'enabled'     => true,
		'message'     => __( 'You’re looking at the new Ner Michoel website in beta testing. Spotted a mistake, a bug or a problem?', 'ner-michoel-core' ),
		'button'      => __( 'Report it', 'ner-michoel-core' ),
		'notify'      => false,
		'notify_to'   => '',
	);
}

function ner_michoel_beta_settings() {
	$saved = get_option( 'nm_beta_mode', array() );
	return wp_parse_args( is_array( $saved ) ? $saved : array(), ner_michoel_beta_defaults() );
}

function ner_michoel_beta_enabled() {
	return (bool) ner_michoel_beta_settings()['enabled'];
}

/** The kinds of report a visitor can pick. */
function ner_michoel_beta_types() {
	return array(
		'mistake'    => __( 'A mistake (wrong name, date, title, text…)', 'ner-michoel-core' ),
		'bug'        => __( 'Something doesn’t work', 'ner-michoel-core' ),
		'problem'    => __( 'Hard to use or find', 'ner-michoel-core' ),
		'suggestion' => __( 'A suggestion', 'ner-michoel-core' ),
	);
}

function ner_michoel_beta_type_short( $type ) {
	$short = array(
		'mistake'    => __( 'Mistake', 'ner-michoel-core' ),
		'bug'        => __( 'Bug', 'ner-michoel-core' ),
		'problem'    => __( 'Problem', 'ner-michoel-core' ),
		'suggestion' => __( 'Suggestion', 'ner-michoel-core' ),
	);
	return isset( $short[ $type ] ) ? $short[ $type ] : __( 'Other', 'ner-michoel-core' );
}

/* ------------------------------------------------------------------
 * Storage.
 * ------------------------------------------------------------------ */

function ner_michoel_beta_register_post_type() {
	register_post_type(
		NER_MICHOEL_BETA_POST_TYPE,
		array(
			'labels'       => array(
				'name'          => __( 'Beta reports', 'ner-michoel-core' ),
				'singular_name' => __( 'Beta report', 'ner-michoel-core' ),
			),
			'public'       => false,
			'show_ui'      => false,
			'show_in_rest' => false,
			'has_archive'  => false,
			'rewrite'      => false,
			'query_var'    => false,
			'supports'     => array( 'title' ),
		)
	);
}
add_action( 'init', 'ner_michoel_beta_register_post_type' );

/**
 * The fields the browser sends besides what the visitor typed, with the
 * longest each may be. Anything longer is cut.
 */
function ner_michoel_beta_context_fields() {
	return array(
		'page_url'   => 1000,
		'page_title' => 300,
		'referrer'   => 1000,
		'user_agent' => 600,
		'platform'   => 100,
		'screen'     => 60,
		'viewport'   => 60,
		'touch'      => 20,
		'language'   => 60,
		'timezone'   => 80,
		'local_time' => 80,
		'errors'     => 2000,
	);
}

function ner_michoel_beta_cut( $text, $max ) {
	$text = (string) $text;
	return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max ) : substr( $text, 0, $max );
}

/**
 * "Chrome 131 on Windows" from a user-agent string. Enough to see at a glance
 * what someone was on; the full string is kept beside it.
 */
function ner_michoel_beta_device_label( $ua ) {
	$ua = (string) $ua;
	if ( '' === $ua ) {
		return __( 'Unknown device', 'ner-michoel-core' );
	}

	$os = __( 'unknown system', 'ner-michoel-core' );
	if ( preg_match( '/iPhone|iPod/i', $ua ) ) {
		$os = 'iPhone';
	} elseif ( preg_match( '/iPad/i', $ua ) ) {
		$os = 'iPad';
	} elseif ( preg_match( '/Android/i', $ua ) ) {
		$os = preg_match( '/Mobile/i', $ua ) ? __( 'Android phone', 'ner-michoel-core' ) : __( 'Android tablet', 'ner-michoel-core' );
	} elseif ( preg_match( '/CrOS/i', $ua ) ) {
		$os = 'Chromebook';
	} elseif ( preg_match( '/Windows/i', $ua ) ) {
		$os = 'Windows';
	} elseif ( preg_match( '/Macintosh|Mac OS X/i', $ua ) ) {
		$os = 'Mac';
	} elseif ( preg_match( '/Linux/i', $ua ) ) {
		$os = 'Linux';
	}

	$browser = __( 'Unknown browser', 'ner-michoel-core' );
	$checks  = array(
		'Edge'             => '/Edg(?:e|A|iOS)?\/(\d+)/',
		'Opera'            => '/(?:OPR|Opera)\/(\d+)/',
		'Samsung Internet' => '/SamsungBrowser\/(\d+)/',
		'Firefox'          => '/(?:Firefox|FxiOS)\/(\d+)/',
		'Chrome'           => '/(?:Chrome|CriOS)\/(\d+)/',
		'Safari'           => '/Version\/(\d+)[.\d]* (?:Mobile\/\S+ )?Safari/',
	);
	foreach ( $checks as $name => $pattern ) {
		if ( preg_match( $pattern, $ua, $m ) ) {
			$browser = $name . ' ' . $m[1];
			break;
		}
	}

	/* translators: 1: browser and version, 2: operating system */
	return sprintf( __( '%1$s on %2$s', 'ner-michoel-core' ), $browser, $os );
}

/* ------------------------------------------------------------------
 * The banner and the pop-up form.
 * ------------------------------------------------------------------ */

/** Pages the banner stays off: the admin, the login screen, feeds, embeds. */
function ner_michoel_beta_shows_here() {
	if ( ! ner_michoel_beta_enabled() || is_admin() || is_feed() || is_embed() || wp_doing_ajax() ) {
		return false;
	}
	return (bool) apply_filters( 'ner_michoel_beta_show_banner', true );
}

function ner_michoel_beta_enqueue() {
	if ( ! ner_michoel_beta_shows_here() ) {
		return;
	}
	wp_enqueue_style( 'ner-michoel-beta', NER_MICHOEL_CORE_URL . 'assets/beta-feedback.css', array(), NER_MICHOEL_CORE_VERSION );
	wp_enqueue_script( 'ner-michoel-beta', NER_MICHOEL_CORE_URL . 'assets/beta-feedback.js', array(), NER_MICHOEL_CORE_VERSION, true );
	if ( function_exists( 'ner_michoel_form_guard_enqueue' ) ) {
		ner_michoel_form_guard_enqueue();
	}
	wp_localize_script(
		'ner-michoel-beta',
		'nmBetaSettings',
		array(
			'url'      => admin_url( 'admin-ajax.php' ),
			'sending'  => __( 'Sending…', 'ner-michoel-core' ),
			'failed'   => __( 'Sorry, that didn’t go through. Please try again in a minute.', 'ner-michoel-core' ),
			'needName' => __( 'Please type your name.', 'ner-michoel-core' ),
			'needText' => __( 'Please tell us what happened.', 'ner-michoel-core' ),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'ner_michoel_beta_enqueue' );

/**
 * Catches script errors from the very top of the page, so a report can say
 * what broke. Tiny, inline, and only while beta mode is on.
 */
function ner_michoel_beta_error_catcher() {
	if ( ! ner_michoel_beta_shows_here() ) {
		return;
	}
	?>
	<script id="nm-beta-errors">(function(){var l=window.nmBetaErrors=[];function a(m){if(l.length>=5){l.shift();}l.push(new Date().toISOString().slice(11,19)+' '+String(m).slice(0,300));}window.addEventListener('error',function(e){a((e.message||'error')+(e.filename?' @ '+e.filename.split('/').pop()+':'+e.lineno:''));});window.addEventListener('unhandledrejection',function(e){a('promise: '+(e.reason&&e.reason.message?e.reason.message:e.reason));});})();</script>
	<?php
}
add_action( 'wp_head', 'ner_michoel_beta_error_catcher', 1 );

/** True once the banner has been printed (wp_body_open), so the footer doesn't add it again. */
function ner_michoel_beta_printed( $set = null ) {
	static $printed = false;
	if ( null !== $set ) {
		$printed = (bool) $set;
	}
	return $printed;
}

function ner_michoel_beta_banner_html() {
	$s = ner_michoel_beta_settings();
	?>
	<div class="nm-beta-bar" id="nm-beta-bar" role="region" aria-label="<?php esc_attr_e( 'Beta testing', 'ner-michoel-core' ); ?>">
		<div class="nm-beta-bar__inner">
			<span class="nm-beta-bar__tag"><?php esc_html_e( 'Beta', 'ner-michoel-core' ); ?></span>
			<span class="nm-beta-bar__text"><?php echo esc_html( $s['message'] ); ?></span>
			<span class="nm-beta-bar__short"><?php esc_html_e( 'Testing the new site. Spotted a problem?', 'ner-michoel-core' ); ?></span>
			<button type="button" class="nm-beta-bar__report" data-nm-beta-open><?php echo esc_html( $s['button'] ); ?></button>
		</div>
	</div>
	<?php
}

function ner_michoel_beta_banner() {
	if ( ! ner_michoel_beta_shows_here() || ner_michoel_beta_printed() ) {
		return;
	}
	ner_michoel_beta_printed( true );
	ner_michoel_beta_banner_html();
}
add_action( 'wp_body_open', 'ner_michoel_beta_banner', 1 );

/**
 * The pop-up form (and the banner, for a page that had no wp_body_open; the
 * script moves it to the top). Printed after #content, so the site's router,
 * which swaps only #content, never removes it.
 */
function ner_michoel_beta_footer() {
	if ( ! ner_michoel_beta_shows_here() ) {
		return;
	}
	if ( ! ner_michoel_beta_printed() ) {
		ner_michoel_beta_printed( true );
		ner_michoel_beta_banner_html();
	}

	$name = '';
	$mail = '';
	if ( is_user_logged_in() ) {
		$user = wp_get_current_user();
		$name = $user->display_name;
		$mail = $user->user_email;
	}
	?>
	<button type="button" class="nm-beta-tab" data-nm-beta-open hidden><?php esc_html_e( 'Report a problem', 'ner-michoel-core' ); ?></button>

	<div class="nm-beta-modal" id="nm-beta-modal" hidden>
		<div class="nm-beta-modal__backdrop" data-nm-beta-close></div>
		<div class="nm-beta-modal__box" role="dialog" aria-modal="true" aria-labelledby="nm-beta-title">
			<button type="button" class="nm-beta-modal__x" data-nm-beta-close aria-label="<?php esc_attr_e( 'Close', 'ner-michoel-core' ); ?>">&times;</button>

			<form class="nm-beta-form" id="nm-beta-form" novalidate>
				<h2 class="nm-beta-form__title" id="nm-beta-title"><?php esc_html_e( 'Report a mistake, bug or problem', 'ner-michoel-core' ); ?></h2>
				<p class="nm-beta-form__intro"><?php esc_html_e( 'Thank you for helping us test the new site. We’ll automatically include the page you’re on, the time, and what device and browser you’re using, so we know exactly where to look.', 'ner-michoel-core' ); ?></p>

				<p class="nm-beta-form__row">
					<label for="nm-beta-name"><?php esc_html_e( 'Your name', 'ner-michoel-core' ); ?> <span class="nm-beta-req" aria-hidden="true">*</span></label>
					<input type="text" id="nm-beta-name" name="name" maxlength="100" required autocomplete="name" value="<?php echo esc_attr( $name ); ?>" />
				</p>
				<p class="nm-beta-form__row">
					<label for="nm-beta-email"><?php esc_html_e( 'Email (optional, if you’d like us to get back to you)', 'ner-michoel-core' ); ?></label>
					<input type="email" id="nm-beta-email" name="email" maxlength="200" autocomplete="email" value="<?php echo esc_attr( $mail ); ?>" />
				</p>
				<p class="nm-beta-form__row">
					<label for="nm-beta-type"><?php esc_html_e( 'What kind of problem?', 'ner-michoel-core' ); ?></label>
					<select id="nm-beta-type" name="type">
						<option value=""><?php esc_html_e( 'Pick one (optional)', 'ner-michoel-core' ); ?></option>
						<?php foreach ( ner_michoel_beta_types() as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p class="nm-beta-form__row">
					<label for="nm-beta-message"><?php esc_html_e( 'What happened?', 'ner-michoel-core' ); ?> <span class="nm-beta-req" aria-hidden="true">*</span></label>
					<textarea id="nm-beta-message" name="message" rows="5" maxlength="5000" required placeholder="<?php esc_attr_e( 'e.g. The shiur on this page won’t play, or the date says 2017 but it was given last week.', 'ner-michoel-core' ); ?>"></textarea>
				</p>

				<p class="nm-beta-form__page"><?php esc_html_e( 'Page:', 'ner-michoel-core' ); ?> <span data-nm-beta-page></span></p>

				<?php // A trap for bots: hidden from people, so anything typed here is from a bot. ?>
				<p class="nm-beta-trap" aria-hidden="true">
					<label for="nm-beta-website"><?php esc_html_e( 'Leave this empty', 'ner-michoel-core' ); ?></label>
					<input type="text" id="nm-beta-website" name="website" tabindex="-1" autocomplete="off" value="" />
				</p>

				<?php
				if ( function_exists( 'ner_michoel_form_guard_fields' ) ) {
					ner_michoel_form_guard_fields( 'bugreport' );
				}
				?>

				<p class="nm-beta-form__status" role="status" aria-live="polite"></p>
				<p class="nm-beta-form__actions">
					<button type="submit" class="nm-beta-form__send"><?php esc_html_e( 'Send report', 'ner-michoel-core' ); ?></button>
					<button type="button" class="nm-beta-form__cancel" data-nm-beta-close><?php esc_html_e( 'Cancel', 'ner-michoel-core' ); ?></button>
				</p>
			</form>

			<div class="nm-beta-done" hidden>
				<h2 class="nm-beta-form__title"><?php esc_html_e( 'Thank you!', 'ner-michoel-core' ); ?></h2>
				<p><?php esc_html_e( 'We got your report and will look into it.', 'ner-michoel-core' ); ?></p>
				<p class="nm-beta-form__actions">
					<button type="button" class="nm-beta-form__send" data-nm-beta-close><?php esc_html_e( 'Close', 'ner-michoel-core' ); ?></button>
					<button type="button" class="nm-beta-form__cancel" data-nm-beta-again><?php esc_html_e( 'Report something else', 'ner-michoel-core' ); ?></button>
				</p>
			</div>
		</div>
	</div>
	<?php
}
add_action( 'wp_footer', 'ner_michoel_beta_footer', 5 );

/* ------------------------------------------------------------------
 * Receiving a report (admin-ajax, so a signed-in tester is known
 * without a REST nonce, and a plain form post, which this host's
 * firewall lets through where it blocks JSON).
 * ------------------------------------------------------------------ */

function ner_michoel_beta_fail( $message, $status = 400 ) {
	wp_send_json_error( array( 'message' => $message ), $status );
}

function ner_michoel_beta_handle_submit() {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- public form; guarded by form-guard (signed, single-use browser check) instead of a nonce, which a cached page would carry stale.
	nocache_headers();

	if ( ! ner_michoel_beta_enabled() ) {
		ner_michoel_beta_fail( __( 'Beta reporting is closed. Thank you for helping!', 'ner-michoel-core' ), 403 );
	}

	$post = wp_unslash( $_POST );
	$read = function ( $key ) use ( $post ) {
		return ( isset( $post[ $key ] ) && is_string( $post[ $key ] ) ) ? trim( $post[ $key ] ) : '';
	};

	// The trap field: say thanks, keep nothing.
	if ( '' !== $read( 'website' ) ) {
		if ( function_exists( 'ner_michoel_form_guard_log' ) ) {
			ner_michoel_form_guard_log( 'honeypot' );
		}
		wp_send_json_success( array( 'ok' => true ) );
	}

	$name    = sanitize_text_field( ner_michoel_beta_cut( $read( 'name' ), 100 ) );
	$message = ner_michoel_beta_cut( $read( 'message' ), 5000 );
	if ( '' === $name ) {
		ner_michoel_beta_fail( __( 'Please type your name.', 'ner-michoel-core' ) );
	}
	if ( '' === trim( sanitize_textarea_field( $message ) ) ) {
		ner_michoel_beta_fail( __( 'Please tell us what happened.', 'ner-michoel-core' ) );
	}

	if ( function_exists( 'ner_michoel_form_guard_verify' ) ) {
		$guard = ner_michoel_form_guard_verify( 'bugreport', $post );
		if ( true !== $guard ) {
			if ( 'rate_limited' === $guard ) {
				ner_michoel_beta_fail( __( 'That’s a lot of reports in a short time. Please wait a little and send it again.', 'ner-michoel-core' ), 429 );
			}
			ner_michoel_beta_fail( __( 'We couldn’t confirm you’re not a bot. Please reload the page and try again.', 'ner-michoel-core' ), 403 );
		}
	}

	$spam = function_exists( 'ner_michoel_form_guard_spam_reason' ) ? ner_michoel_form_guard_spam_reason( $name, $message ) : '';

	if ( function_exists( 'ner_michoel_form_guard_is_repeat' ) && ner_michoel_form_guard_is_repeat( 'bugreport', $name . '|' . $message ) ) {
		if ( function_exists( 'ner_michoel_form_guard_log' ) ) {
			ner_michoel_form_guard_log( 'duplicate' );
		}
		wp_send_json_success( array( 'ok' => true ) );
	}

	$type = sanitize_key( $read( 'type' ) );
	if ( ! isset( ner_michoel_beta_types()[ $type ] ) ) {
		$type = 'other';
	}

	$context = array();
	foreach ( ner_michoel_beta_context_fields() as $key => $max ) {
		$value = ner_michoel_beta_cut( $read( $key ), $max );
		$context[ $key ] = in_array( $key, array( 'page_url', 'referrer' ), true ) ? esc_url_raw( $value ) : sanitize_textarea_field( $value );
	}
	if ( '' === $context['user_agent'] && isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
		$context['user_agent'] = sanitize_text_field( ner_michoel_beta_cut( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ), 600 ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}
	if ( '' === $context['page_url'] ) {
		$context['page_url'] = esc_url_raw( wp_get_referer() );
	}

	$excerpt = wp_trim_words( sanitize_textarea_field( $message ), 10, '…' );
	$post_id = wp_insert_post(
		array(
			'post_type'    => NER_MICHOEL_BETA_POST_TYPE,
			'post_title'   => $name . ': ' . $excerpt,
			'post_status'  => 'private',
			'post_content' => '',
			'post_author'  => get_current_user_id(),
		),
		true
	);
	if ( is_wp_error( $post_id ) ) {
		ner_michoel_beta_fail( __( 'Sorry, that didn’t go through. Please try again in a minute.', 'ner-michoel-core' ), 500 );
	}

	$meta = array(
		'_nm_br_status'   => '' !== $spam ? 'spam' : 'new',
		'_nm_br_name'     => $name,
		'_nm_br_email'    => sanitize_email( ner_michoel_beta_cut( $read( 'email' ), 200 ) ),
		'_nm_br_type'     => $type,
		'_nm_br_message'  => sanitize_textarea_field( $message ),
		'_nm_br_time_utc' => gmdate( 'Y-m-d H:i:s' ),
		'_nm_br_device'   => ner_michoel_beta_device_label( $context['user_agent'] ),
		'_nm_br_ip'       => function_exists( 'ner_michoel_client_ip' ) ? ner_michoel_client_ip() : '',
		'_nm_br_user'     => is_user_logged_in() ? wp_get_current_user()->user_login : '',
	);
	if ( '' !== $spam ) {
		$meta['_nm_br_spam_reason'] = $spam;
		if ( function_exists( 'ner_michoel_form_guard_log' ) ) {
			ner_michoel_form_guard_log( 'spam_content' );
		}
	}
	foreach ( $context as $key => $value ) {
		$meta[ '_nm_br_' . $key ] = $value;
	}
	foreach ( $meta as $key => $value ) {
		update_post_meta( $post_id, $key, wp_slash( $value ) );
	}

	if ( '' === $spam ) {
		ner_michoel_beta_notify( $post_id );
	}

	wp_send_json_success( array( 'ok' => true ) );
	// phpcs:enable
}
add_action( 'wp_ajax_nm_beta_report', 'ner_michoel_beta_handle_submit' );
add_action( 'wp_ajax_nopriv_nm_beta_report', 'ner_michoel_beta_handle_submit' );

/** Emails a new report, when that's switched on. */
function ner_michoel_beta_notify( $post_id ) {
	$s = ner_michoel_beta_settings();
	if ( empty( $s['notify'] ) ) {
		return;
	}
	$to = is_email( $s['notify_to'] ) ? $s['notify_to'] : get_option( 'admin_email' );
	$r  = ner_michoel_beta_report( $post_id );

	$lines = array(
		sprintf( 'From: %s%s', $r['name'], $r['email'] ? ' <' . $r['email'] . '>' : '' ),
		sprintf( 'Kind: %s', ner_michoel_beta_type_short( $r['type'] ) ),
		sprintf( 'When: %s', $r['when'] ),
		sprintf( 'Page: %s', $r['page_url'] ),
		sprintf( 'Device: %s (screen %s, window %s)', $r['device'], $r['screen'], $r['viewport'] ),
		'',
		$r['message'],
		'',
		'All reports: ' . admin_url( 'admin.php?page=nm-beta-reports' ),
	);
	$reply = $r['email'] ? array( 'Reply-To: ' . $r['email'] ) : array();
	wp_mail(
		$to,
		/* translators: 1: kind, 2: name */
		sprintf( __( '[Beta report] %1$s from %2$s', 'ner-michoel-core' ), ner_michoel_beta_type_short( $r['type'] ), $r['name'] ),
		implode( "\n", $lines ),
		$reply
	);
}

/** One report as a plain array, for the admin page, the email and the CSV. */
function ner_michoel_beta_report( $post_id ) {
	$get = function ( $key ) use ( $post_id ) {
		return (string) get_post_meta( $post_id, '_nm_br_' . $key, true );
	};
	$utc  = $get( 'time_utc' );
	$when = $utc ? wp_date( 'D j M Y, g:i:s a', strtotime( $utc . ' UTC' ) ) : get_the_date( 'D j M Y, g:i a', $post_id );

	$out = array(
		'id'          => (int) $post_id,
		'status'      => $get( 'status' ) ? $get( 'status' ) : 'new',
		'when'        => $when,
		'time_utc'    => $utc,
		'name'        => $get( 'name' ),
		'email'       => $get( 'email' ),
		'type'        => $get( 'type' ),
		'message'     => $get( 'message' ),
		'device'      => $get( 'device' ),
		'ip'          => $get( 'ip' ),
		'user'        => $get( 'user' ),
		'spam_reason' => $get( 'spam_reason' ),
		'note'        => $get( 'note' ),
	);
	foreach ( array_keys( ner_michoel_beta_context_fields() ) as $key ) {
		$out[ $key ] = $get( $key );
	}
	return $out;
}

/* ------------------------------------------------------------------
 * The admin page: switch, reports, actions, CSV.
 * ------------------------------------------------------------------ */

function ner_michoel_beta_count( $status ) {
	$q = new WP_Query(
		array(
			'post_type'      => NER_MICHOEL_BETA_POST_TYPE,
			'post_status'    => 'private',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => false,
			'meta_query'     => 'all' === $status ? array() : array( array( 'key' => '_nm_br_status', 'value' => $status ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		)
	);
	return (int) $q->found_posts;
}

function ner_michoel_beta_admin_menu() {
	$new    = ner_michoel_beta_count( 'new' );
	$bubble = $new ? ' <span class="awaiting-mod count-' . $new . '"><span class="pending-count">' . number_format_i18n( $new ) . '</span></span>' : '';
	add_menu_page(
		__( 'Beta reports', 'ner-michoel-core' ),
		__( 'Beta reports', 'ner-michoel-core' ) . $bubble,
		'manage_options',
		'nm-beta-reports',
		'ner_michoel_beta_admin_page',
		'dashicons-flag',
		3
	);
}
add_action( 'admin_menu', 'ner_michoel_beta_admin_menu' );

/** The page's address, keeping the current filter. */
function ner_michoel_beta_admin_url( $args = array() ) {
	return add_query_arg( $args, admin_url( 'admin.php?page=nm-beta-reports' ) );
}

function ner_michoel_beta_guard( $action ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'ner-michoel-core' ), 403 );
	}
	check_admin_referer( $action );
}

function ner_michoel_beta_save_settings() {
	ner_michoel_beta_guard( 'nm_beta_settings' );
	$d = ner_michoel_beta_defaults();
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in ner_michoel_beta_guard().
	$message = isset( $_POST['message'] ) ? sanitize_text_field( wp_unslash( $_POST['message'] ) ) : '';
	$button  = isset( $_POST['button'] ) ? sanitize_text_field( wp_unslash( $_POST['button'] ) ) : '';
	$to      = isset( $_POST['notify_to'] ) ? sanitize_email( wp_unslash( $_POST['notify_to'] ) ) : '';
	update_option(
		'nm_beta_mode',
		array(
			'enabled'   => ! empty( $_POST['enabled'] ),
			'message'   => '' !== $message ? $message : $d['message'],
			'button'    => '' !== $button ? $button : $d['button'],
			'notify'    => ! empty( $_POST['notify'] ),
			'notify_to' => $to,
		)
	);
	// phpcs:enable
	wp_safe_redirect( ner_michoel_beta_admin_url( array( 'saved' => 1 ) ) );
	exit;
}
add_action( 'admin_post_nm_beta_settings', 'ner_michoel_beta_save_settings' );

function ner_michoel_beta_set_status() {
	ner_michoel_beta_guard( 'nm_beta_status' );
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- checked in ner_michoel_beta_guard().
	$id     = isset( $_REQUEST['id'] ) ? absint( $_REQUEST['id'] ) : 0;
	$to     = isset( $_REQUEST['to'] ) ? sanitize_key( $_REQUEST['to'] ) : '';
	$back   = isset( $_REQUEST['view'] ) ? sanitize_key( $_REQUEST['view'] ) : 'new';
	$note   = isset( $_REQUEST['note'] ) ? sanitize_textarea_field( wp_unslash( $_REQUEST['note'] ) ) : null;
	// phpcs:enable
	if ( ! $id || NER_MICHOEL_BETA_POST_TYPE !== get_post_type( $id ) ) {
		wp_die( esc_html__( 'That report wasn’t found.', 'ner-michoel-core' ), 404 );
	}
	if ( 'delete' === $to ) {
		wp_delete_post( $id, true );
	} elseif ( in_array( $to, array( 'new', 'fixed', 'spam' ), true ) ) {
		update_post_meta( $id, '_nm_br_status', $to );
	}
	if ( null !== $note ) {
		update_post_meta( $id, '_nm_br_note', wp_slash( $note ) );
	}
	wp_safe_redirect( ner_michoel_beta_admin_url( array( 'view' => $back ) ) . '#nm-br-' . $id );
	exit;
}
add_action( 'admin_post_nm_beta_status', 'ner_michoel_beta_set_status' );

function ner_michoel_beta_export_csv() {
	ner_michoel_beta_guard( 'nm_beta_csv' );
	$ids = get_posts(
		array(
			'post_type'      => NER_MICHOEL_BETA_POST_TYPE,
			'post_status'    => 'private',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'orderby'        => 'date',
			'order'          => 'DESC',
		)
	);
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="beta-reports-' . gmdate( 'Y-m-d' ) . '.csv"' );
	$out  = fopen( 'php://output', 'w' );
	$cols = array( 'id', 'status', 'when', 'name', 'email', 'type', 'message', 'page_url', 'page_title', 'device', 'screen', 'viewport', 'touch', 'platform', 'language', 'timezone', 'local_time', 'user_agent', 'referrer', 'errors', 'user', 'ip', 'note', 'spam_reason' );
	fwrite( $out, "\xEF\xBB\xBF" ); // So Excel reads it as UTF-8 (Hebrew names).
	fputcsv( $out, $cols );
	foreach ( $ids as $id ) {
		$r   = ner_michoel_beta_report( $id );
		$row = array();
		foreach ( $cols as $c ) {
			$v = isset( $r[ $c ] ) ? (string) $r[ $c ] : '';
			// A cell starting with = + - @ would run as a formula in Excel.
			$row[] = preg_match( '/^[=+\-@]/', $v ) ? "'" . $v : $v;
		}
		fputcsv( $out, $row );
	}
	fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	exit;
}
add_action( 'admin_post_nm_beta_csv', 'ner_michoel_beta_export_csv' );

function ner_michoel_beta_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$s     = ner_michoel_beta_settings();
	$views = array(
		'new'   => __( 'New', 'ner-michoel-core' ),
		'fixed' => __( 'Fixed / done', 'ner-michoel-core' ),
		'spam'  => __( 'Spam', 'ner-michoel-core' ),
		'all'   => __( 'All', 'ner-michoel-core' ),
	);
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only filter.
	$view = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : 'new';
	if ( ! isset( $views[ $view ] ) ) {
		$view = 'new';
	}
	$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$query = new WP_Query(
		array(
			'post_type'      => NER_MICHOEL_BETA_POST_TYPE,
			'post_status'    => 'private',
			'posts_per_page' => 50,
			'paged'          => $paged,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => 'all' === $view ? array() : array( array( 'key' => '_nm_br_status', 'value' => $view ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		)
	);
	?>
	<div class="wrap nm-br">
		<style>
			.nm-br .nm-br-card{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:16px 20px;margin:16px 0;max-width:1100px}
			.nm-br .nm-br-switch{display:flex;gap:10px;align-items:center;font-size:15px}
			.nm-br .nm-br-on{color:#0a7a2f;font-weight:600}.nm-br .nm-br-off{color:#a00;font-weight:600}
			.nm-br table.nm-br-list{max-width:1100px}
			.nm-br .nm-br-msg{white-space:pre-wrap;max-width:420px}
			.nm-br details{margin-top:6px}.nm-br details summary{cursor:pointer;color:#2271b1}
			.nm-br dl.nm-br-dl{display:grid;grid-template-columns:130px 1fr;gap:4px 12px;margin:8px 0;font-size:12px}
			.nm-br dl.nm-br-dl dt{font-weight:600;color:#50575e}.nm-br dl.nm-br-dl dd{margin:0;word-break:break-all;white-space:pre-wrap}
			.nm-br .nm-br-tag{display:inline-block;padding:1px 8px;border-radius:10px;background:#f0f0f1;font-size:12px}
			.nm-br .nm-br-tag--bug{background:#fde8e8}.nm-br .nm-br-tag--mistake{background:#fff4d6}.nm-br .nm-br-tag--problem{background:#e7f0fb}.nm-br .nm-br-tag--suggestion{background:#e6f6ea}
			.nm-br .nm-br-actions form{display:inline}.nm-br .nm-br-actions .button{margin:2px 4px 2px 0}
		</style>
		<h1><?php esc_html_e( 'Beta reports', 'ner-michoel-core' ); ?></h1>
		<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved.', 'ner-michoel-core' ); ?></p></div>
		<?php endif; ?>

		<div class="nm-br-card">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="nm_beta_settings" />
				<?php wp_nonce_field( 'nm_beta_settings' ); ?>
				<p class="nm-br-switch">
					<label><input type="checkbox" name="enabled" value="1" <?php checked( $s['enabled'] ); ?> /> <strong><?php esc_html_e( 'Beta testing mode', 'ner-michoel-core' ); ?></strong></label>
					<?php if ( $s['enabled'] ) : ?>
						<span class="nm-br-on"><?php esc_html_e( 'ON: the beta banner and the report form show on every page.', 'ner-michoel-core' ); ?></span>
					<?php else : ?>
						<span class="nm-br-off"><?php esc_html_e( 'OFF: no banner, and the form is closed.', 'ner-michoel-core' ); ?></span>
					<?php endif; ?>
				</p>
				<p><label><?php esc_html_e( 'Banner message', 'ner-michoel-core' ); ?><br />
					<input type="text" class="large-text" name="message" value="<?php echo esc_attr( $s['message'] ); ?>" /></label></p>
				<p><label><?php esc_html_e( 'Button text', 'ner-michoel-core' ); ?><br />
					<input type="text" class="regular-text" name="button" value="<?php echo esc_attr( $s['button'] ); ?>" /></label></p>
				<p><label><input type="checkbox" name="notify" value="1" <?php checked( $s['notify'] ); ?> /> <?php esc_html_e( 'Email me each new report', 'ner-michoel-core' ); ?></label>
					<input type="email" class="regular-text" name="notify_to" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" value="<?php echo esc_attr( $s['notify_to'] ); ?>" />
					<span class="description"><?php esc_html_e( 'Empty = the site’s admin email.', 'ner-michoel-core' ); ?></span></p>
				<?php submit_button( __( 'Save', 'ner-michoel-core' ), 'primary', 'submit', false ); ?>
			</form>
		</div>

		<ul class="subsubsub">
			<?php
			$links = array();
			foreach ( $views as $key => $label ) {
				$links[] = sprintf(
					'<li><a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
					esc_url( ner_michoel_beta_admin_url( array( 'view' => $key ) ) ),
					$key === $view ? ' class="current" aria-current="page"' : '',
					esc_html( $label ),
					esc_html( number_format_i18n( ner_michoel_beta_count( $key ) ) )
				);
			}
			echo implode( ' | </li>', $links ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
			?>
		</ul>
		<p style="float:right;margin:8px 0">
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=nm_beta_csv' ), 'nm_beta_csv' ) ); ?>"><?php esc_html_e( 'Download all as CSV', 'ner-michoel-core' ); ?></a>
		</p>

		<table class="widefat striped nm-br-list">
			<thead><tr>
				<th style="width:150px"><?php esc_html_e( 'When', 'ner-michoel-core' ); ?></th>
				<th style="width:140px"><?php esc_html_e( 'From', 'ner-michoel-core' ); ?></th>
				<th><?php esc_html_e( 'What happened', 'ner-michoel-core' ); ?></th>
				<th style="width:230px"><?php esc_html_e( 'Where', 'ner-michoel-core' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( ! $query->have_posts() ) : ?>
				<tr><td colspan="4"><?php esc_html_e( 'No reports here yet.', 'ner-michoel-core' ); ?></td></tr>
			<?php endif; ?>
			<?php
			foreach ( $query->posts as $p ) :
				$r      = ner_michoel_beta_report( $p->ID );
				$path   = '';
				if ( $r['page_url'] ) {
					$parts = wp_parse_url( $r['page_url'] );
					$path  = ( isset( $parts['path'] ) ? $parts['path'] : '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
				}
				$action = admin_url( 'admin-post.php' );
				?>
				<tr id="nm-br-<?php echo (int) $r['id']; ?>">
					<td><?php echo esc_html( $r['when'] ); ?><br /><span class="nm-br-tag nm-br-tag--<?php echo esc_attr( $r['type'] ); ?>"><?php echo esc_html( ner_michoel_beta_type_short( $r['type'] ) ); ?></span>
						<?php if ( 'spam' === $r['status'] ) : ?><br /><span class="description"><?php echo esc_html( sprintf( /* translators: %s: reason */ __( 'Spam: %s', 'ner-michoel-core' ), $r['spam_reason'] ? $r['spam_reason'] : __( 'marked by hand', 'ner-michoel-core' ) ) ); ?></span><?php endif; ?>
					</td>
					<td><strong><?php echo esc_html( $r['name'] ); ?></strong>
						<?php if ( $r['email'] ) : ?><br /><a href="mailto:<?php echo esc_attr( $r['email'] ); ?>"><?php echo esc_html( $r['email'] ); ?></a><?php endif; ?>
						<?php if ( $r['user'] ) : ?><br /><span class="description"><?php echo esc_html( sprintf( /* translators: %s: username */ __( 'signed in as %s', 'ner-michoel-core' ), $r['user'] ) ); ?></span><?php endif; ?>
					</td>
					<td>
						<div class="nm-br-msg"><?php echo esc_html( $r['message'] ); ?></div>
						<?php if ( $r['note'] ) : ?><p><em><?php esc_html_e( 'Note:', 'ner-michoel-core' ); ?></em> <?php echo esc_html( $r['note'] ); ?></p><?php endif; ?>
						<div class="nm-br-actions">
							<?php
							$buttons = array();
							if ( 'fixed' !== $r['status'] && 'spam' !== $r['status'] ) {
								$buttons['fixed'] = __( 'Mark fixed', 'ner-michoel-core' );
							}
							if ( 'new' !== $r['status'] ) {
								$buttons['new'] = 'spam' === $r['status'] ? __( 'Not spam', 'ner-michoel-core' ) : __( 'Reopen', 'ner-michoel-core' );
							}
							if ( 'spam' !== $r['status'] ) {
								$buttons['spam'] = __( 'Spam', 'ner-michoel-core' );
							}
							$buttons['delete'] = __( 'Delete', 'ner-michoel-core' );
							foreach ( $buttons as $to => $label ) :
								?>
								<form method="post" action="<?php echo esc_url( $action ); ?>"<?php echo 'delete' === $to ? ' onsubmit="return confirm(\'' . esc_js( __( 'Delete this report for good?', 'ner-michoel-core' ) ) . '\')"' : ''; ?>>
									<input type="hidden" name="action" value="nm_beta_status" />
									<input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>" />
									<input type="hidden" name="to" value="<?php echo esc_attr( $to ); ?>" />
									<input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>" />
									<?php wp_nonce_field( 'nm_beta_status' ); ?>
									<button type="submit" class="button<?php echo 'fixed' === $to ? ' button-primary' : ''; ?>"><?php echo esc_html( $label ); ?></button>
								</form>
							<?php endforeach; ?>
						</div>
						<details>
							<summary><?php esc_html_e( 'Add a note', 'ner-michoel-core' ); ?></summary>
							<form method="post" action="<?php echo esc_url( $action ); ?>">
								<input type="hidden" name="action" value="nm_beta_status" />
								<input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>" />
								<input type="hidden" name="to" value="" />
								<input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>" />
								<?php wp_nonce_field( 'nm_beta_status' ); ?>
								<textarea name="note" rows="2" class="large-text"><?php echo esc_textarea( $r['note'] ); ?></textarea>
								<button type="submit" class="button"><?php esc_html_e( 'Save note', 'ner-michoel-core' ); ?></button>
							</form>
						</details>
					</td>
					<td>
						<?php if ( $r['page_url'] ) : ?>
							<a href="<?php echo esc_url( $r['page_url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $r['page_title'] ? $r['page_title'] : $path ); ?></a><br />
							<code style="font-size:11px"><?php echo esc_html( $path ); ?></code><br />
						<?php endif; ?>
						<?php echo esc_html( $r['device'] ); ?><br />
						<span class="description"><?php echo esc_html( sprintf( /* translators: 1: screen size, 2: window size */ __( 'screen %1$s · window %2$s', 'ner-michoel-core' ), $r['screen'] ? $r['screen'] : '?', $r['viewport'] ? $r['viewport'] : '?' ) ); ?></span>
						<details>
							<summary><?php esc_html_e( 'All details', 'ner-michoel-core' ); ?></summary>
							<dl class="nm-br-dl">
								<?php
								$details = array(
									__( 'Full address', 'ner-michoel-core' )   => $r['page_url'],
									__( 'Came from', 'ner-michoel-core' )      => $r['referrer'],
									__( 'Their clock', 'ner-michoel-core' )    => trim( $r['local_time'] . ' ' . $r['timezone'] ),
									__( 'Server time', 'ner-michoel-core' )    => $r['time_utc'] ? $r['time_utc'] . ' UTC' : '',
									__( 'Browser string', 'ner-michoel-core' ) => $r['user_agent'],
									__( 'System', 'ner-michoel-core' )         => $r['platform'],
									__( 'Touch screen', 'ner-michoel-core' )   => $r['touch'],
									__( 'Language', 'ner-michoel-core' )       => $r['language'],
									__( 'Page errors', 'ner-michoel-core' )    => $r['errors'] ? $r['errors'] : __( 'none', 'ner-michoel-core' ),
									__( 'IP address', 'ner-michoel-core' )     => $r['ip'],
								);
								foreach ( $details as $label => $value ) {
									if ( '' === (string) $value ) {
										continue;
									}
									printf( '<dt>%s</dt><dd>%s</dd>', esc_html( $label ), esc_html( $value ) );
								}
								?>
							</dl>
						</details>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		if ( $query->max_num_pages > 1 ) {
			echo '<p>' . paginate_links( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				array(
					'base'    => add_query_arg( 'paged', '%#%', ner_michoel_beta_admin_url( array( 'view' => $view ) ) ),
					'current' => $paged,
					'total'   => $query->max_num_pages,
				)
			) . '</p>';
		}
		?>
	</div>
	<?php
}

/** A line on the WordPress dashboard while there are new reports. */
function ner_michoel_beta_admin_notice() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! current_user_can( 'manage_options' ) || ! $screen || 'dashboard' !== $screen->id ) {
		return;
	}
	$new = ner_michoel_beta_count( 'new' );
	if ( ! $new ) {
		return;
	}
	printf(
		'<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
		esc_html( sprintf( /* translators: %d: count */ _n( '%d new beta report.', '%d new beta reports.', $new, 'ner-michoel-core' ), $new ) ),
		esc_url( ner_michoel_beta_admin_url() ),
		esc_html__( 'Read them', 'ner-michoel-core' )
	);
}
add_action( 'admin_notices', 'ner_michoel_beta_admin_notice' );
