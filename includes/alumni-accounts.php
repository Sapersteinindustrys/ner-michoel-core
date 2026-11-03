<?php
/**
 * Alumni-only accounts (R' Ariel Katz's request, 2026-10-31).
 *
 * With the switch on (Control Panel > Alumni > Sign-ups & Access):
 *  - A new sign-up is checked against the Years List (years-list.php): the same
 *    email, or the same whole name. A match can log in as before.
 *  - No match: the account waits for the office. It can't log in until someone
 *    presses Approve on the panel screen; the office gets an email about it.
 *  - Accounts made before the switch was turned on are not touched.
 * With the switch off nothing changes (and waiting accounts can log in).
 *
 * Also here: phone and address on the account's Profile tab, and an email to
 * the office whenever someone changes them (accounts.php calls
 * ner_michoel_alumni_save_contact()).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NER_MICHOEL_ALUMNI_ONLY_OPTION', 'nm_alumni_only' );
define( 'NER_MICHOEL_ALUMNI_OFFICE_OPTION', 'nm_alumni_office_email' );

/** Is the alumni-only switch on? */
function ner_michoel_alumni_only() {
	return (bool) get_option( NER_MICHOEL_ALUMNI_ONLY_OPTION, 0 );
}

/** Where office notices go: the address set on the screen, else the site's admin email. */
function ner_michoel_alumni_office_email() {
	$email = (string) get_option( NER_MICHOEL_ALUMNI_OFFICE_OPTION, '' );
	return is_email( $email ) ? $email : (string) get_option( 'admin_email' );
}

/**
 * Is this name / email in the Years List? Returns array( 'by' => 'email'|'name',
 * 'name' => as in the list, 'years' => ... ) or null. A name counts only when it
 * is the whole name (either order), never a "maybe".
 */
function ner_michoel_alumni_match( $name, $email ) {
	if ( ! function_exists( 'ner_michoel_years_get_list' ) ) {
		return null;
	}
	$list = ner_michoel_years_get_list();
	if ( ! $list['count'] ) {
		return null;
	}
	$email = strtolower( trim( (string) $email ) );
	if ( '' !== $email ) {
		foreach ( $list['rows'] as $row ) {
			if ( ! empty( $row['e'] ) && $row['e'] === $email ) {
				return array( 'by' => 'email', 'name' => $row['n'], 'years' => $row['y'] );
			}
		}
	}
	$found = ner_michoel_years_find( $list['rows'], array( (string) $name ) );
	foreach ( $found['strong'] as $item ) {
		if ( 0 === (int) $item['rank'] ) {
			return array( 'by' => 'name', 'name' => $item['name'], 'years' => $item['years'] );
		}
	}
	return null;
}

/** Status of an account: 'matched', 'approved', 'pending', or '' (made before the switch, or by staff). */
function ner_michoel_alumni_status( $user_id ) {
	return (string) get_user_meta( (int) $user_id, 'nm_alumni_status', true );
}

/** Is this account kept out until the office approves it? */
function ner_michoel_alumni_is_blocked( $user_id ) {
	return ner_michoel_alumni_only() && 'pending' === ner_michoel_alumni_status( $user_id );
}

/** What a waiting person is told (sign-up, code step and log in). */
function ner_michoel_alumni_pending_message() {
	return __( 'Thank you for signing up. Accounts are for alumni of the yeshiva, and we couldn’t find your name or email in our alumni list, so the office will look at it. We’ll email you when your account is ready.', 'ner-michoel-core' );
}

/**
 * Called by the sign-up route once the account exists. Marks it matched or
 * waiting. Returns true when it is waiting (the person is not signed in).
 * $tell_office: send the office notice now (sign-ups with no email code step;
 * with the code step it goes once the email is confirmed, so a typo'd address
 * never reaches the office).
 */
function ner_michoel_alumni_on_signup( $user_id, $name, $email, $tell_office ) {
	if ( ! ner_michoel_alumni_only() ) {
		return false;
	}
	$match = ner_michoel_alumni_match( $name, $email );
	if ( $match ) {
		update_user_meta( $user_id, 'nm_alumni_status', 'matched' );
		update_user_meta( $user_id, 'nm_alumni_match', $match );
		return false;
	}
	update_user_meta( $user_id, 'nm_alumni_status', 'pending' );
	delete_user_meta( $user_id, 'nm_alumni_match' );
	delete_user_meta( $user_id, 'nm_alumni_office_told' );
	if ( $tell_office ) {
		ner_michoel_alumni_tell_office_pending( $user_id );
	}
	return true;
}

/** "A sign-up is waiting" email to the office, once per account. */
function ner_michoel_alumni_tell_office_pending( $user_id ) {
	if ( get_user_meta( $user_id, 'nm_alumni_office_told', true ) ) {
		return;
	}
	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return;
	}
	update_user_meta( $user_id, 'nm_alumni_office_told', time() );
	$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	$body = sprintf(
		/* translators: 1: name, 2: email, 3: link */
		__( "Someone signed up who isn't in the alumni list (Years List):\n\nName: %1\$s\nEmail: %2\$s\n\nTo let them in or turn them down, open:\n%3\$s\n", 'ner-michoel-core' ),
		$user->display_name,
		$user->user_email,
		ner_michoel_custom_admin_url( 'alumni' )
	);
	wp_mail( ner_michoel_alumni_office_email(), sprintf( /* translators: %s: site name */ __( '[%s] New sign-up waiting for approval', 'ner-michoel-core' ), $site ), $body );
}

/** Keeps a waiting account out, on every log-in form (before the sign-in code step). */
function ner_michoel_alumni_block_login( $user ) {
	// Email not confirmed yet: the code step comes first (account-verification.php); the office hears once it is confirmed.
	if ( $user instanceof WP_User && ner_michoel_alumni_is_blocked( $user->ID ) && ! ( function_exists( 'ner_michoel_user_is_unverified' ) && ner_michoel_user_is_unverified( $user->ID ) ) ) {
		return new WP_Error( 'nm_alumni_pending', ner_michoel_alumni_pending_message() );
	}
	return $user;
}
add_filter( 'authenticate', 'ner_michoel_alumni_block_login', 97 );

/* ------------------------------------------------------------------ */
/* Phone and address                                                   */
/* ------------------------------------------------------------------ */

/** The account's phone and address. */
function ner_michoel_alumni_contact( $user_id ) {
	return array(
		'phone'   => (string) get_user_meta( $user_id, 'nm_phone', true ),
		'address' => (string) get_user_meta( $user_id, 'nm_address', true ),
	);
}

/**
 * Saves phone and/or address (null = not sent, left as it is) and emails the
 * office what changed. Returns the list of changes.
 */
function ner_michoel_alumni_save_contact( $user_id, $phone, $address ) {
	$before  = ner_michoel_alumni_contact( $user_id );
	$changes = array();
	if ( null !== $phone ) {
		$phone = substr( sanitize_text_field( (string) $phone ), 0, 40 );
		if ( $phone !== $before['phone'] ) {
			update_user_meta( $user_id, 'nm_phone', $phone );
			$changes[] = array( __( 'Phone', 'ner-michoel-core' ), $before['phone'], $phone );
		}
	}
	if ( null !== $address ) {
		$address = substr( sanitize_textarea_field( (string) $address ), 0, 400 );
		if ( $address !== $before['address'] ) {
			update_user_meta( $user_id, 'nm_address', $address );
			$changes[] = array( __( 'Address', 'ner-michoel-core' ), $before['address'], $address );
		}
	}
	if ( $changes ) {
		$user  = get_userdata( $user_id );
		$lines = array();
		foreach ( $changes as $c ) {
			$lines[] = sprintf( "%s\n  Was: %s\n  Now: %s", $c[0], '' !== $c[1] ? str_replace( "\n", ', ', $c[1] ) : '(empty)', '' !== $c[2] ? str_replace( "\n", ', ', $c[2] ) : '(empty)' );
		}
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		wp_mail(
			ner_michoel_alumni_office_email(),
			sprintf( /* translators: 1: site name, 2: person's name */ __( '[%1$s] %2$s changed their contact details', 'ner-michoel-core' ), $site, $user ? $user->display_name : '' ),
			sprintf( "%s (%s) changed their contact details on the website:\n\n%s\n", $user ? $user->display_name : '', $user ? $user->user_email : '', implode( "\n\n", $lines ) )
		);
	}
	return $changes;
}

/** Phone and address on wp-admin's user screen too, for staff. */
function ner_michoel_alumni_user_profile_fields( $user ) {
	if ( ! current_user_can( 'edit_users' ) ) {
		return;
	}
	$c      = ner_michoel_alumni_contact( $user->ID );
	$status = ner_michoel_alumni_status( $user->ID );
	?>
	<h2><?php esc_html_e( 'Alumni contact details', 'ner-michoel-core' ); ?></h2>
	<table class="form-table">
		<tr><th><?php esc_html_e( 'Phone', 'ner-michoel-core' ); ?></th><td><?php echo esc_html( $c['phone'] ); ?></td></tr>
		<tr><th><?php esc_html_e( 'Address', 'ner-michoel-core' ); ?></th><td><?php echo nl2br( esc_html( $c['address'] ) ); ?></td></tr>
		<?php if ( '' !== $status ) : ?>
		<tr><th><?php esc_html_e( 'Alumni sign-up', 'ner-michoel-core' ); ?></th><td><?php echo esc_html( $status ); ?></td></tr>
		<?php endif; ?>
	</table>
	<?php
}
add_action( 'show_user_profile', 'ner_michoel_alumni_user_profile_fields' );
add_action( 'edit_user_profile', 'ner_michoel_alumni_user_profile_fields' );

/* ------------------------------------------------------------------ */
/* The panel screen                                                    */
/* ------------------------------------------------------------------ */

function ner_michoel_alumni_back( $args = array() ) {
	// The Alumni Shiurim screen's form says to come back to it; everything else is on Sign-ups & Access.
	$tab = isset( $_POST['nm_back'] ) && 'alumnisection' === $_POST['nm_back'] ? 'alumnisection' : 'alumni'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- picks the redirect only.
	$url = ner_michoel_panel_redirect_url( ner_michoel_custom_admin_url( $tab ), $tab, $args );
	wp_safe_redirect( esc_url_raw( $url ) );
	exit;
}

function ner_michoel_alumni_flash( $message, $error = false ) {
	set_transient( 'nm_alumni_flash_' . get_current_user_id(), array( 'error' => $error, 'message' => $message ), 300 );
}

/** Saves the switch and the office address. */
function ner_michoel_handle_alumni_settings() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'ner-michoel-core' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'nm_alumni_settings', 'nm_alumni_nonce' );
	update_option( NER_MICHOEL_ALUMNI_ONLY_OPTION, empty( $_POST['alumni_only'] ) ? 0 : 1 );
	$office = isset( $_POST['office_email'] ) ? sanitize_email( wp_unslash( $_POST['office_email'] ) ) : '';
	update_option( NER_MICHOEL_ALUMNI_OFFICE_OPTION, is_email( $office ) ? $office : '', false );
	ner_michoel_alumni_flash( __( 'Saved.', 'ner-michoel-core' ) );
	ner_michoel_alumni_back();
}
add_action( 'admin_post_nm_alumni_settings', 'ner_michoel_handle_alumni_settings' );

/** Approve (lets them in, emails them) or Turn down (deletes the waiting account). */
function ner_michoel_handle_alumni_decide() {
	if ( ! current_user_can( 'edit_users' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'ner-michoel-core' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'nm_alumni_decide', 'nm_alumni_nonce' );
	$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
	$do      = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
	$user    = $user_id ? get_userdata( $user_id ) : null;
	if ( ! $user || 'pending' !== ner_michoel_alumni_status( $user_id ) ) {
		ner_michoel_alumni_flash( __( 'That sign-up is no longer waiting.', 'ner-michoel-core' ), true );
		ner_michoel_alumni_back();
	}
	$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	if ( 'approve' === $do ) {
		update_user_meta( $user_id, 'nm_alumni_status', 'approved' );
		update_user_meta( $user_id, 'nm_alumni_approved_by', get_current_user_id() );
		wp_mail(
			$user->user_email,
			sprintf( /* translators: %s: site name */ __( '[%s] Your account is ready', 'ner-michoel-core' ), $site ),
			sprintf( /* translators: 1: name, 2: link */ __( "Hello %1\$s,\n\nYour account has been approved. You can log in here:\n%2\$s\n", 'ner-michoel-core' ), $user->display_name, home_url( '/account/' ) )
		);
		ner_michoel_alumni_flash( sprintf( /* translators: %s: name */ __( '%s can log in now. They were sent an email.', 'ner-michoel-core' ), $user->display_name ) );
	} elseif ( 'decline' === $do ) {
		if ( user_can( $user, 'edit_posts' ) ) {
			ner_michoel_alumni_flash( __( 'Staff accounts are not deleted from here.', 'ner-michoel-core' ), true );
			ner_michoel_alumni_back();
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $user_id );
		ner_michoel_alumni_flash( sprintf( /* translators: %s: name */ __( 'The sign-up from %s was turned down and removed.', 'ner-michoel-core' ), $user->display_name ) );
	}
	ner_michoel_alumni_back();
}
add_action( 'admin_post_nm_alumni_decide', 'ner_michoel_handle_alumni_decide' );

/** Control Panel > Alumni > Sign-ups & Access. */
function ner_michoel_render_alumni_page() {
	$key   = 'nm_alumni_flash_' . get_current_user_id();
	$flash = get_transient( $key );
	if ( $flash ) {
		delete_transient( $key );
	}
	$post_to = admin_url( 'admin-post.php' );
	$waiting = get_users(
		array(
			'meta_key'   => 'nm_alumni_status', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value' => 'pending',          // phpcs:ignore WordPress.DB.SlowDBQuery
			'orderby'    => 'registered',
			'order'      => 'DESC',
			'number'     => 200,
		)
	);
	$years = function_exists( 'ner_michoel_years_get_list' ) ? ner_michoel_years_get_list() : array( 'count' => 0, 'rows' => array() );
	$with_email = 0;
	foreach ( $years['rows'] as $row ) {
		if ( ! empty( $row['e'] ) ) {
			$with_email++;
		}
	}
	?>
	<div class="wrap nm-dashboard nm-alumni">
		<?php if ( is_array( $flash ) ) : ?>
			<div class="notice notice-<?php echo $flash['error'] ? 'error' : 'success'; ?>"><p><?php echo esc_html( $flash['message'] ); ?></p></div>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Waiting for approval', 'ner-michoel-core' ); ?></h2>
		<?php if ( ! $waiting ) : ?>
			<p><?php esc_html_e( 'Nobody is waiting.', 'ner-michoel-core' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Name', 'ner-michoel-core' ); ?></th><th><?php esc_html_e( 'Email', 'ner-michoel-core' ); ?></th><th><?php esc_html_e( 'Signed up', 'ner-michoel-core' ); ?></th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $waiting as $u ) : ?>
					<tr>
						<td><span dir="auto"><?php echo esc_html( $u->display_name ); ?></span></td>
						<td><?php echo esc_html( $u->user_email ); ?><?php echo function_exists( 'ner_michoel_user_is_unverified' ) && ner_michoel_user_is_unverified( $u->ID ) ? ' <em>(' . esc_html__( 'email not confirmed yet', 'ner-michoel-core' ) . ')</em>' : ''; ?></td>
						<td><?php echo esc_html( wp_date( 'j M Y', strtotime( $u->user_registered . ' UTC' ) ) ); ?></td>
						<td>
							<form method="post" action="<?php echo esc_url( $post_to ); ?>" style="display:inline">
								<?php wp_nonce_field( 'nm_alumni_decide', 'nm_alumni_nonce' ); ?>
								<input type="hidden" name="action" value="nm_alumni_decide" />
								<input type="hidden" name="user_id" value="<?php echo (int) $u->ID; ?>" />
								<?php ner_michoel_panel_form_field(); ?>
								<button type="submit" name="do" value="approve" class="button button-primary"><?php esc_html_e( 'Approve', 'ner-michoel-core' ); ?></button>
								<button type="submit" name="do" value="decline" class="button button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Turn down this sign-up and remove the account?', 'ner-michoel-core' ) ); ?>');"><?php esc_html_e( 'Turn down', 'ner-michoel-core' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ( current_user_can( 'manage_options' ) ) : ?>
		<h2><?php esc_html_e( 'Settings', 'ner-michoel-core' ); ?></h2>
		<form method="post" action="<?php echo esc_url( $post_to ); ?>">
			<?php wp_nonce_field( 'nm_alumni_settings', 'nm_alumni_nonce' ); ?>
			<input type="hidden" name="action" value="nm_alumni_settings" />
			<?php ner_michoel_panel_form_field(); ?>
			<p><label><input type="checkbox" name="alumni_only" value="1" <?php checked( ner_michoel_alumni_only() ); ?> /> <?php esc_html_e( 'Accounts are for alumni only: a sign-up not found in the alumni sheet or the Years List waits for approval', 'ner-michoel-core' ); ?></label></p>
			<p>
				<label for="nm_alumni_office"><?php esc_html_e( 'Office email (gets new sign-ups and changed contact details)', 'ner-michoel-core' ); ?></label><br />
				<input type="email" id="nm_alumni_office" name="office_email" class="regular-text" value="<?php echo esc_attr( (string) get_option( NER_MICHOEL_ALUMNI_OFFICE_OPTION, '' ) ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" />
			</p>
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'ner-michoel-core' ); ?></button></p>
		</form>
		<?php endif; ?>

		<p class="description">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: names in the Years List, 2: how many have an email */
					__( 'The Alumni Directory has %3$s alumni: a sign-up is found there by its email, or by two of its names, and asked “Is this you?”. The Years List has %1$s names, %2$s with an email: a sign-up also matches when its email is in that list, or its whole name is. Accounts made before the switch was turned on are not affected.', 'ner-michoel-core' ),
					number_format_i18n( (int) $years['count'] ),
					number_format_i18n( $with_email ),
					number_format_i18n( function_exists( 'ner_michoel_alumni_dir_count' ) ? ner_michoel_alumni_dir_count() : 0 )
				)
			);
			?>
		</p>

		<?php
		if ( function_exists( 'ner_michoel_render_alumni_section_settings' ) ) {
			ner_michoel_render_alumni_section_settings( 'access' ); // alumni-section.php
		}
		?>
	</div>
	<?php
}
