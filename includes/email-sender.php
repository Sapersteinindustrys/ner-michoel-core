<?php
/**
 * Who the site's emails say they are from.
 *
 * WordPress sends every message as "WordPress" unless told otherwise, which is
 * not what someone who has just signed up expects to find in their inbox. Mail
 * from this site comes from Yeshivas Toras Moshe: that is the sender name shown
 * in the inbox list, and the verification code email also says it in its subject
 * and in the message itself.
 *
 * The name can be changed without touching code: set the option
 * nm_email_sender_name, or use the ner_michoel_email_sender_name filter. The
 * address the mail is sent from is left to WordPress and the host.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The name emails come from: "Yeshivas Toras Moshe", unless the option
 * nm_email_sender_name holds another or the filter changes it. Plain text, no
 * HTML entities and no line breaks (it ends up in a mail header); whoever uses
 * it escapes it for where it goes.
 */
function ner_michoel_email_sender_name() {
	$name = trim( wp_strip_all_tags( (string) get_option( 'nm_email_sender_name', '' ) ) );
	if ( '' === $name ) {
		$name = 'Yeshivas Toras Moshe';
	}

	$name = (string) apply_filters( 'ner_michoel_email_sender_name', $name );
	$name = trim( preg_replace( '/\s+/', ' ', wp_specialchars_decode( $name, ENT_QUOTES ) ) );

	return '' === $name ? 'Yeshivas Toras Moshe' : $name;
}

/**
 * wp_mail_from_name: the "WordPress" WordPress falls back to becomes the sender
 * name. A message that picks its own name (another plugin's, or a mail plugin's
 * setting) keeps it.
 */
function ner_michoel_email_from_name( $name ) {
	return 'WordPress' === $name ? ner_michoel_email_sender_name() : $name;
}
add_filter( 'wp_mail_from_name', 'ner_michoel_email_from_name' );
