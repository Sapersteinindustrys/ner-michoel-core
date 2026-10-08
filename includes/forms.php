<?php
/**
 * Form handling (contact, donation, application, etc.) — submission
 * processing, validation, and any REST/AJAX endpoints they need.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contact page submission — posts to admin-post.php?action=nm_contact_submit
 * (see page-templates/contact.php in the theme). Fields: nm_name,
 * nm_email, nm_message required; nm_phone optional; nm_contact_hp is
 * a honeypot (real visitors never see/reach it — any value there is
 * treated as spam and silently dropped, no error shown, nothing sent).
 *
 * Then the bot check (includes/form-guard.php): the browser check, the rate
 * limit and Turnstile, which send a person back with ?nm_contact=verify or
 * ?nm_contact=slow. After the fields are validated, a message already
 * received in the last day is dropped, and one that reads like spam (links,
 * markup) is kept under Submissions as spam but not emailed. Both report
 * "sent", so a bot learns nothing from them.
 */
function ner_michoel_handle_contact_submit() {
	$redirect = wp_get_referer() ? wp_get_referer() : home_url( '/contact/' );

	if ( ! isset( $_POST['nm_contact_nonce'] ) || ! wp_verify_nonce( $_POST['nm_contact_nonce'], 'nm_contact_submit' ) ) {
		wp_safe_redirect( esc_url_raw( add_query_arg( 'nm_contact', 'error', $redirect ) ) );
		exit;
	}

	// Honeypot: bots fill every field, including this off-screen one.
	// Redirect as if it succeeded — telling a bot it failed just
	// teaches it to adapt.
	if ( ! empty( $_POST['nm_contact_hp'] ) ) {
		ner_michoel_form_guard_log( 'honeypot' );
		wp_safe_redirect( esc_url_raw( add_query_arg( 'nm_contact', 'sent', $redirect ) ) );
		exit;
	}

	$guard = ner_michoel_form_guard_verify( 'contact', wp_unslash( $_POST ) );
	if ( true !== $guard ) {
		wp_safe_redirect( esc_url_raw( add_query_arg( 'nm_contact', 'rate_limited' === $guard ? 'slow' : 'verify', $redirect ) ) );
		exit;
	}

	$name    = isset( $_POST['nm_name'] ) ? sanitize_text_field( wp_unslash( $_POST['nm_name'] ) ) : '';
	$email   = isset( $_POST['nm_email'] ) ? sanitize_email( wp_unslash( $_POST['nm_email'] ) ) : '';
	$phone   = isset( $_POST['nm_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['nm_phone'] ) ) : '';
	$message = isset( $_POST['nm_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['nm_message'] ) ) : '';

	if ( '' === $name || '' === $message || ! is_email( $email ) ) {
		wp_safe_redirect( esc_url_raw( add_query_arg( 'nm_contact', 'error', $redirect ) ) );
		exit;
	}

	$submission = array(
		'name'    => $name,
		'email'   => $email,
		'phone'   => $phone,
		'message' => $message,
	);
	if ( ner_michoel_form_message_is_spam( 'contact', $submission, $_POST ) ) {
		wp_safe_redirect( esc_url_raw( add_query_arg( 'nm_contact', 'sent', $redirect ) ) );
		exit;
	}

	ner_michoel_record_submission( 'contact', $submission );

	$body_lines = array(
		sprintf( 'Name: %s', $name ),
		sprintf( 'Email: %s', $email ),
	);
	if ( '' !== $phone ) {
		$body_lines[] = sprintf( 'Phone: %s', $phone );
	}
	$body_lines[] = '';
	$body_lines[] = $message;

	$sent = wp_mail(
		get_option( 'admin_email' ),
		sprintf( '[%s] New contact form submission from %s', get_bloginfo( 'name' ), $name ),
		implode( "\n", $body_lines ),
		array( 'Reply-To: ' . $name . ' <' . $email . '>' )
	);

	wp_safe_redirect( esc_url_raw( add_query_arg( 'nm_contact', $sent ? 'sent' : 'error', $redirect ) ) );
	exit;
}
add_action( 'admin_post_nm_contact_submit', 'ner_michoel_handle_contact_submit' );
add_action( 'admin_post_nopriv_nm_contact_submit', 'ner_michoel_handle_contact_submit' );

/**
 * "Email a Magid Shiur" submission — posts to
 * admin-post.php?action=nm_email_magid_submit (see
 * page-templates/email-magid-shiur.php in the theme). Sent to the
 * chosen speaker's email (ner_michoel_get_speaker_email()) if one is
 * set, otherwise to the site admin with a note that no direct email
 * is configured, so a message never just silently vanishes.
 */
function ner_michoel_handle_email_magid_submit() {
	$redirect = wp_get_referer() ? wp_get_referer() : home_url( '/' );

	if ( ! isset( $_POST['nm_email_magid_nonce'] ) || ! wp_verify_nonce( $_POST['nm_email_magid_nonce'], 'nm_email_magid_submit' ) ) {
		wp_safe_redirect( esc_url_raw( add_query_arg( 'nm_magid', 'error', $redirect ) ) );
		exit;
	}

	// Honeypot: redirect as if it succeeded so a bot doesn't learn to adapt.
	if ( ! empty( $_POST['nm_magid_hp'] ) ) {
		ner_michoel_form_guard_log( 'honeypot' );
		wp_safe_redirect( esc_url_raw( add_query_arg( 'nm_magid', 'sent', $redirect ) ) );
		exit;
	}

	$guard = ner_michoel_form_guard_verify( 'magid', wp_unslash( $_POST ) );
	if ( true !== $guard ) {
		wp_safe_redirect( esc_url_raw( add_query_arg( 'nm_magid', 'rate_limited' === $guard ? 'slow' : 'verify', $redirect ) ) );
		exit;
	}

	$speaker_id = isset( $_POST['nm_speaker_id'] ) ? absint( $_POST['nm_speaker_id'] ) : 0;
	$name       = isset( $_POST['nm_name'] ) ? sanitize_text_field( wp_unslash( $_POST['nm_name'] ) ) : '';
	$email      = isset( $_POST['nm_email'] ) ? sanitize_email( wp_unslash( $_POST['nm_email'] ) ) : '';
	$message    = isset( $_POST['nm_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['nm_message'] ) ) : '';
	$shiur_id   = isset( $_POST['nm_shiur_id'] ) ? absint( $_POST['nm_shiur_id'] ) : 0;

	$speaker = $speaker_id ? get_term( $speaker_id, 'speaker' ) : null;

	if ( ! $speaker_id || ! $speaker || is_wp_error( $speaker ) || '' === $name || '' === $message || ! is_email( $email ) ) {
		wp_safe_redirect( esc_url_raw( add_query_arg( 'nm_magid', 'error', $redirect ) ) );
		exit;
	}

	/*
	 * Optional — tagging a shiur is not required to send a message.
	 * Deliberately not filtered by media type: an audio or video shiur
	 * is equally valid to tag, so this only checks post_type/status.
	 */
	$shiur = ( $shiur_id && 'shiur' === get_post_type( $shiur_id ) && 'publish' === get_post_status( $shiur_id ) )
		? get_post( $shiur_id )
		: null;

	$submission = array(
		'name'        => $name,
		'email'       => $email,
		'message'     => $message,
		'speaker'     => $speaker->name,
		'shiur_title' => $shiur ? $shiur->post_title : '',
	);
	if ( ner_michoel_form_message_is_spam( 'magid', $submission, $_POST ) ) {
		wp_safe_redirect( esc_url_raw( add_query_arg( 'nm_magid', 'sent', $redirect ) ) );
		exit;
	}

	ner_michoel_record_submission( 'magid', $submission );

	$speaker_email = ner_michoel_get_speaker_email( $speaker_id );
	$to            = $speaker_email ? $speaker_email : get_option( 'admin_email' );
	$subject       = $speaker_email
		? sprintf( '[%s] Message for %s from %s', get_bloginfo( 'name' ), $speaker->name, $name )
		: sprintf( '[%s] Message for %s from %s (no direct email configured for this speaker)', get_bloginfo( 'name' ), $speaker->name, $name );

	$body_lines = array(
		sprintf( 'Speaker: %s', $speaker->name ),
	);
	if ( $shiur ) {
		$body_lines[] = sprintf( 'Regarding shiur: %s (%s)', $shiur->post_title, get_permalink( $shiur ) );
	}
	$body_lines[] = sprintf( 'From: %s', $name );
	$body_lines[] = sprintf( 'Email: %s', $email );
	$body_lines[] = '';
	$body_lines[] = $message;

	$body = implode( "\n", $body_lines );

	$sent = wp_mail( $to, $subject, $body, array( 'Reply-To: ' . $name . ' <' . $email . '>' ) );

	wp_safe_redirect( esc_url_raw( add_query_arg( 'nm_magid', $sent ? 'sent' : 'error', $redirect ) ) );
	exit;
}
add_action( 'admin_post_nm_email_magid_submit', 'ner_michoel_handle_email_magid_submit' );
add_action( 'admin_post_nopriv_nm_email_magid_submit', 'ner_michoel_handle_email_magid_submit' );

/**
 * The message checks shared by both forms, run once the fields are valid.
 * True when the message shouldn't be emailed: a repeat of one already
 * received in the last day (dropped), or one that reads like spam (kept under
 * Submissions as spam, for a look in case it's real). $posted is the raw
 * $_POST, so markup the sanitizing removed can still be seen.
 */
function ner_michoel_form_message_is_spam( $type, array $submission, array $posted ) {
	if ( ner_michoel_form_guard_is_repeat( $type, $submission['message'] ) ) {
		ner_michoel_form_guard_log( 'duplicate' );
		return true;
	}

	$raw_message = isset( $posted['nm_message'] ) && is_string( $posted['nm_message'] ) ? wp_unslash( $posted['nm_message'] ) : '';
	$reason      = ner_michoel_form_guard_spam_reason( $submission['name'], $raw_message );
	if ( '' === $reason ) {
		return false;
	}

	ner_michoel_form_guard_log( 'spam_content' );
	ner_michoel_record_submission( $type, $submission, $reason );
	return true;
}
