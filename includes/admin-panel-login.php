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
		<meta name="robots" content="noindex, nofollow" />
		<style>
			html, body { height: 100%; margin: 0; }
			body { display: flex; align-items: center; justify-content: center; padding: 24px; box-sizing: border-box; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI Variable Text", "Segoe UI", Inter, Roboto, Helvetica, Arial, sans-serif; color: #17211d; background: radial-gradient(120% 90% at 0% 0%, #e3f3ea 0%, rgba(227,243,234,0) 60%), radial-gradient(100% 80% at 100% 100%, #e8efe9 0%, rgba(232,239,233,0) 55%), #f4f6f5; -webkit-font-smoothing: antialiased; }
			.nm-login-box { background: #fff; padding: 36px 34px 30px; border-radius: 22px; border: 1px solid #e3e8e5; box-shadow: 0 24px 60px -24px rgba(16,32,24,.28), 0 4px 12px rgba(16,32,24,.05); width: 100%; max-width: 380px; box-sizing: border-box; text-align: center; }
			.nm-login-mark { width: 52px; height: 52px; margin: 0 auto 18px; border-radius: 15px; display: grid; place-items: center; background: linear-gradient(140deg, #2a9a6a, #1e7d55 55%, #155e40); color: #fff; font-size: 22px; font-weight: 700; box-shadow: 0 10px 22px -10px rgba(30,125,85,.8); }
			.nm-login-box h1 { font-size: 1.35rem; margin: 0 0 6px; letter-spacing: -.01em; }
			.nm-login-box p.nm-login-sub { margin: 0 0 24px; color: #4b5852; font-size: .95rem; line-height: 1.5; }
			.nm-login-box label { display: block; text-align: left; font-size: .9rem; font-weight: 600; margin-bottom: 7px; }
			.nm-login-box input[type="password"] { width: 100%; box-sizing: border-box; padding: 12px 14px; font-size: 1rem; border: 1px solid #cdd5d1; border-radius: 12px; margin-bottom: 16px; font-family: inherit; transition: border-color .15s, box-shadow .15s; }
			.nm-login-box input[type="password"]:focus { outline: none; border-color: #1e7d55; box-shadow: 0 0 0 4px rgba(30,125,85,.22); }
			.nm-login-box button { width: 100%; padding: 13px; font-size: 1rem; font-weight: 600; font-family: inherit; background: #1e7d55; color: #fff; border: none; border-radius: 12px; cursor: pointer; transition: background .15s, box-shadow .15s; }
			.nm-login-box button:hover { background: #176645; box-shadow: 0 8px 18px -8px rgba(30,125,85,.7); }
			.nm-login-error { display: flex; gap: 8px; align-items: center; text-align: left; color: #8f2626; background: #fdeded; border: 1px solid #f6c9c9; border-radius: 12px; padding: 10px 12px; font-size: .9rem; margin: 0 0 18px; }
			.nm-login-foot { margin: 20px 0 0; font-size: .85rem; color: #75827c; }
			.nm-login-foot a { color: #1e7d55; font-weight: 600; text-decoration: none; }
			.nm-login-foot a:hover { text-decoration: underline; }
		</style>
	</head>
	<body>
		<div class="nm-login-box">
			<div class="nm-login-mark" aria-hidden="true"><?php echo esc_html( strtoupper( mb_substr( get_bloginfo( 'name' ) ? get_bloginfo( 'name' ) : 'N', 0, 1 ) ) ); ?></div>
			<h1><?php echo esc_html( get_bloginfo( 'name' ) ); ?></h1>
			<p class="nm-login-sub"><?php esc_html_e( 'Welcome! Enter the control panel password to continue.', 'ner-michoel-core' ); ?></p>
			<?php if ( $error ) : ?>
				<p class="nm-login-error" role="alert"><?php echo esc_html( $error ); ?></p>
			<?php endif; ?>
			<form method="post">
				<?php wp_nonce_field( 'nm_admin_panel_login', 'nm_admin_panel_nonce' ); ?>
				<label for="nm_admin_panel_password"><?php esc_html_e( 'Password', 'ner-michoel-core' ); ?></label>
				<input type="password" id="nm_admin_panel_password" name="nm_admin_panel_password" autocomplete="current-password" autofocus required />
				<button type="submit"><?php esc_html_e( 'Open the Control Panel', 'ner-michoel-core' ); ?></button>
			</form>
			<p class="nm-login-foot"><?php esc_html_e( 'Have your own account?', 'ner-michoel-core' ); ?> <a href="<?php echo esc_url( wp_login_url( $dashboard_url ) ); ?>"><?php esc_html_e( 'Log in with it instead', 'ner-michoel-core' ); ?></a></p>
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

/**
 * REST route for setting these options programmatically, for the
 * custom /admin Settings UI (custom-admin-settings-api.php) — same
 * validation as ner_michoel_save_admin_panel_login_settings() above,
 * reading a JSON body instead of $_POST.
 */
function ner_michoel_register_admin_login_rest_route() {
	register_rest_route(
		'ner-michoel/v1',
		'/admin-login-settings',
		array(
			'methods'             => 'POST',
			'callback'            => 'ner_michoel_handle_admin_login_settings_rest',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		)
	);
}
add_action( 'rest_api_init', 'ner_michoel_register_admin_login_rest_route' );

/**
 * Unlike the wp-admin form above, empty password boxes here keep the
 * current password: the panel's form always shows them empty, so a save
 * made only to change "Logs In As" mustn't switch the shortcut off. It's
 * switched off only by ticking 'remove_password'.
 */
function ner_michoel_handle_admin_login_settings_rest( WP_REST_Request $request ) {
	$password = (string) $request->get_param( 'new_password' );
	$confirm  = (string) $request->get_param( 'confirm_password' );
	$remove   = (bool) $request->get_param( 'remove_password' );

	if ( $remove ) {
		delete_option( 'nm_admin_panel_password' );
	} elseif ( '' !== $password || '' !== $confirm ) {
		if ( $password !== $confirm ) {
			return new WP_Error( 'nm_password_mismatch', __( "The two passwords didn't match — nothing was changed.", 'ner-michoel-core' ), array( 'status' => 400 ) );
		}
		update_option( 'nm_admin_panel_password', wp_hash_password( $password ) );
	}

	$login_as = absint( $request->get_param( 'login_as' ) );
	if ( $login_as ) {
		update_option( 'nm_admin_panel_user_id', $login_as );
	} else {
		delete_option( 'nm_admin_panel_user_id' );
	}

	return new WP_REST_Response( array( 'saved' => true ), 200 );
}
