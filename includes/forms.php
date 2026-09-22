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
		wp_safe_redirect( esc_url_raw( add_query_arg( 'nm_contact', 'sent', $redirect ) ) );
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
