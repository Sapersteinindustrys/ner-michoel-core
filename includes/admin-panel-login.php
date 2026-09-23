<?php
/**
 * A single shared password for reaching /admin, instead of the full
 * WordPress username+password login — easier for a non-technical admin
 * to remember/share. The real WP login (/wp-login.php) is completely
 * untouched and always still works; this only changes what happens at
 * the /admin shortcut specifically.
 *
 * Security shape (this repo is public, so nothing here can live in
 * source): the password is never stored or compared in plaintext — only
 * its wp_hash_password() hash, set through the settings screen below
 * while already logged in normally. A correct password doesn't create
 * its own account; it logs the visitor in *as* a configured WP user
 * (ner_michoel_admin_panel_login_user_id()) via the same
 * wp_set_auth_cookie() any normal login uses, since the Site Control
 * Panel needs real capabilities to do anything.
 *
 * Until a password is ever set, /admin behaves exactly as before
 * (falls back to wp-login.php) — nothing breaks pre-setup.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ner_michoel_admin_panel_login_user_id() {
	$user_id = (int) get_option( 'nm_admin_panel_user_id' );
	if ( $user_id && get_userdata( $user_id ) ) {
		return $user_id;
	}
	$admins = get_users(
		array(
			'capability' => 'manage_options',
			'number'     => 1,
			'fields'     => 'ID',
			'orderby'    => 'ID',
			'order'      => 'ASC',
		)
	);
	return $admins ? (int) $admins[0] : 0;
}

/**
 * Per-IP failed-attempt limiter — a shared password is weaker than
 * real per-user auth, so this exists, but capped low enough (5 per
 * 15 min) that a few genuine typos never lock the real admin out.
 */
function ner_michoel_admin_panel_rate_limit_key() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	return 'nm_admin_login_' . md5( $ip );
}

/**
 * Renders (and handles the POST for) the plain password form shown at
 * /admin once a shortcut password has been configured. Always exits —
 * either via a successful-login redirect, or by finishing the HTML
 * output for the form/error state.
 */
function ner_michoel_render_admin_panel_login( $dashboard_url ) {
	$error = '';

	if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['nm_admin_panel_password'] ) ) {
		$rate_key = ner_michoel_admin_panel_rate_limit_key();
		$attempts = (int) get_transient( $rate_key );

		if ( $attempts >= 5 ) {
			$error = __( 'Too many attempts. Please wait 15 minutes and try again.', 'ner-michoel-core' );
		} elseif ( ! isset( $_POST['nm_admin_panel_nonce'] ) || ! wp_verify_nonce( $_POST['nm_admin_panel_nonce'], 'nm_admin_panel_login' ) ) {
			$error = __( 'Please try again.', 'ner-michoel-core' );
		} else {
			$password_hash = get_option( 'nm_admin_panel_password' );
			$submitted     = (string) wp_unslash( $_POST['nm_admin_panel_password'] );

			if ( $password_hash && wp_check_password( $submitted, $password_hash ) ) {
				delete_transient( $rate_key );
				$user_id = ner_michoel_admin_panel_login_user_id();
				if ( $user_id ) {
					wp_set_current_user( $user_id );
					wp_set_auth_cookie( $user_id );
					do_action( 'wp_login', get_userdata( $user_id )->user_login, get_userdata( $user_id ) );
					wp_safe_redirect( $dashboard_url );
					exit;
				}
				$error = __( 'No admin account is set up to log in as yet. Please use the regular login instead.', 'ner-michoel-core' );
			} else {
				set_transient( $rate_key, $attempts + 1, 15 * MINUTE_IN_SECONDS );
				$error = __( 'Incorrect password.', 'ner-michoel-core' );
			}
		}
	}

	nocache_headers();
	?>
	<!DOCTYPE html>
	<html <?php language_attributes(); ?>>
	<head>
		<meta charset="<?php bloginfo( 'charset' ); ?>" />
		<meta name="viewport" content="width=device-width, initial-scale=1" />
		<title><?php echo esc_html( get_bloginfo( 'name' ) ); ?></title>
		<style>
			html, body { height: 100%; margin: 0; }
			body { display: flex; align-items: center; justify-content: center; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background: #f0f0f1; }
			.nm-login-box { background: #fff; padding: 32px; border-radius: 8px; box-shadow: 0 2px 12px rgba(0,0,0,.08); width: 100%; max-width: 340px; box-sizing: border-box; }
			.nm-login-box h1 { font-size: 1.15rem; margin: 0 0 20px; text-align: center; }
			.nm-login-box input[type="password"] { width: 100%; box-sizing: border-box; padding: 10px 12px; font-size: 1rem; border: 1px solid #ccd0d4; border-radius: 4px; margin-bottom: 14px; }
			.nm-login-box button { width: 100%; padding: 10px; font-size: 1rem; background: #2f8f5b; color: #fff; border: none; border-radius: 4px; cursor: pointer; }
			.nm-login-box button:hover { background: #267249; }
			.nm-login-error { color: #b32d2e; font-size: .9rem; margin: 0 0 14px; }
		</style>
	</head>
	<body>
		<div class="nm-login-box">
			<h1><?php echo esc_html( get_bloginfo( 'name' ) ); ?></h1>
			<?php if ( $error ) : ?>
				<p class="nm-login-error"><?php echo esc_html( $error ); ?></p>
			<?php endif; ?>
			<form method="post">
				<?php wp_nonce_field( 'nm_admin_panel_login', 'nm_admin_panel_nonce' ); ?>
				<input type="password" name="nm_admin_panel_password" placeholder="<?php esc_attr_e( 'Password', 'ner-michoel-core' ); ?>" autofocus required />
				<button type="submit"><?php esc_html_e( 'Log In', 'ner-michoel-core' ); ?></button>
			</form>
		</div>
	</body>
	</html>
	<?php
	exit;
}

/**
 * Admin: settings screen (Site Control Panel > Admin Login Shortcut).
 * manage_options-gated — same sensitivity level as the other
 * migration/security-adjacent screens (Import Sample Content, Storage).
 */
function ner_michoel_render_admin_panel_login_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'ner-michoel-core' ) );
	}

	$result = '';
	if ( isset( $_POST['nm_admin_panel_settings_nonce'] ) && wp_verify_nonce( $_POST['nm_admin_panel_settings_nonce'], 'nm_save_admin_panel_settings' ) ) {
		$result = ner_michoel_save_admin_panel_login_settings();
	}

	$has_password        = (bool) get_option( 'nm_admin_panel_password' );
	$configured_user_id  = (int) get_option( 'nm_admin_panel_user_id' );
	$admins              = get_users( array( 'capability' => 'manage_options' ) );
	$admin_url_shortcut  = trailingslashit( home_url() ) . 'admin';
	?>
	<div class="wrap nm-dashboard">
		<h1><?php esc_html_e( 'Admin Login Shortcut', 'ner-michoel-core' ); ?></h1>
		<p>
			<?php
			echo wp_kses(
				sprintf(
					/* translators: %s: the /admin URL */
					__( 'Lets <code>%s</code> be reached with just this one password instead of the full WordPress login — easier to remember and share with a non-technical admin. The real WordPress login always still works too, this is just a shortcut.', 'ner-michoel-core' ),
					esc_html( $admin_url_shortcut )
				),
				array( 'code' => array() )
			);
			?>
		</p>

		<?php if ( 'saved' === $result ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved.', 'ner-michoel-core' ); ?></p></div>
		<?php elseif ( 'mismatch' === $result ) : ?>
			<div class="notice notice-error"><p><?php esc_html_e( 'The two passwords didn\'t match — nothing was changed.', 'ner-michoel-core' ); ?></p></div>
		<?php endif; ?>

		<p>
			<strong><?php esc_html_e( 'Status:', 'ner-michoel-core' ); ?></strong>
			<?php echo $has_password ? esc_html__( 'A shortcut password is set.', 'ner-michoel-core' ) : esc_html__( 'Not set up yet — /admin currently falls back to the normal WordPress login.', 'ner-michoel-core' ); ?>
		</p>

		<form method="post" style="max-width:500px;">
			<?php wp_nonce_field( 'nm_save_admin_panel_settings', 'nm_admin_panel_settings_nonce' ); ?>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="nm_new_password"><?php esc_html_e( 'New Password', 'ner-michoel-core' ); ?></label></th>
					<td><input type="password" id="nm_new_password" name="nm_new_password" class="regular-text" autocomplete="new-password" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="nm_new_password_confirm"><?php esc_html_e( 'Confirm Password', 'ner-michoel-core' ); ?></label></th>
					<td><input type="password" id="nm_new_password_confirm" name="nm_new_password_confirm" class="regular-text" autocomplete="new-password" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="nm_login_as"><?php esc_html_e( 'Logs In As', 'ner-michoel-core' ); ?></label></th>
					<td>
						<select id="nm_login_as" name="nm_login_as">
							<option value="0"><?php esc_html_e( '— First available admin —', 'ner-michoel-core' ); ?></option>
							<?php foreach ( $admins as $admin_user ) : ?>
								<option value="<?php echo esc_attr( $admin_user->ID ); ?>" <?php selected( $configured_user_id, $admin_user->ID ); ?>><?php echo esc_html( $admin_user->display_name ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'A shared password isn\'t tied to one account, so correct entry logs the visitor in as whoever\'s chosen here.', 'ner-michoel-core' ); ?></p>
					</td>
				</tr>
			</table>
			<p class="description"><?php esc_html_e( 'Leave both password fields blank and save to remove the shortcut password entirely (reverts /admin to the normal WordPress login).', 'ner-michoel-core' ); ?></p>
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'ner-michoel-core' ); ?></button></p>
		</form>
	</div>
	<?php
}

/**
 * Returns 'saved', 'mismatch' (passwords didn't match — nothing
 * changed), or 'error' (no permission).
 */
function ner_michoel_save_admin_panel_login_settings() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return 'error';
	}

	$password = isset( $_POST['nm_new_password'] ) ? (string) wp_unslash( $_POST['nm_new_password'] ) : '';
	$confirm  = isset( $_POST['nm_new_password_confirm'] ) ? (string) wp_unslash( $_POST['nm_new_password_confirm'] ) : '';

	if ( '' !== $password || '' !== $confirm ) {
		if ( $password !== $confirm ) {
			return 'mismatch';
		}
		update_option( 'nm_admin_panel_password', wp_hash_password( $password ) );
	} else {
		delete_option( 'nm_admin_panel_password' );
	}

	$login_as = isset( $_POST['nm_login_as'] ) ? absint( $_POST['nm_login_as'] ) : 0;
	if ( $login_as ) {
		update_option( 'nm_admin_panel_user_id', $login_as );
	} else {
		delete_option( 'nm_admin_panel_user_id' );
	}

	return 'saved';
}
