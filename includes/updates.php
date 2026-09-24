<?php
/**
 * Self-hosted auto-updates via GitHub — so a new version can be
 * pushed and tagged without manually re-uploading a zip through
 * wp-admin. Uses the "Plugin Update Checker" library (MIT, vendored
 * in vendor/plugin-update-checker/), pointed at a public release repo
 * dedicated to this plugin.
 *
 * How to ship an update:
 * 1. Bump the `Version:` header in ner-michoel-core.php.
 * 2. Push the plugin's files to https://github.com/Sapersteinindustrys/ner-michoel-core
 * 3. Tag the commit with the same version number (e.g. `v0.2.0`) and push the tag.
 * WordPress then shows "Update Available" (Dashboard > Updates) within
 * ~12 hours on its own, or immediately if you click "Check Again".
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs immediately (not deferred to a hook) — PUC registers its own
 * hooks into WordPress' update-check lifecycle internally, and needs
 * to do that as early as the plugin loads, the same way its own
 * documented usage pattern does.
 */
function ner_michoel_init_update_checker() {
	$library = NER_MICHOEL_CORE_PATH . 'vendor/plugin-update-checker/plugin-update-checker.php';
	if ( ! file_exists( $library ) ) {
		return;
	}
	require_once $library;

	if ( ! class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
		return;
	}

	$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/Sapersteinindustrys/ner-michoel-core/',
		NER_MICHOEL_CORE_PATH . 'ner-michoel-core.php',
		'ner-michoel-core'
	);

	$GLOBALS['ner_michoel_update_checker'] = $checker;
}
ner_michoel_init_update_checker();

/**
 * Diagnostic only — captures whatever the checker last hit trouble
 * with (GitHub API errors: rate limiting, network failure, a
 * malformed request, etc.) so "why didn't this update?" has a real
 * answer instead of silent guessing. PUC itself doesn't surface API
 * errors anywhere in the admin UI; a failed check just quietly looks
 * identical to "already up to date".
 */
function ner_michoel_log_update_check_error( $error, $http_response, $url, $slug ) {
	if ( 'ner-michoel-core' !== $slug || ! is_wp_error( $error ) ) {
		return;
	}
	update_option(
		'nm_last_update_check_error',
		array(
			'time'    => current_time( 'mysql', true ),
			'url'     => $url,
			'message' => $error->get_error_message(),
			'code'    => $http_response ? wp_remote_retrieve_response_code( $http_response ) : null,
		)
	);
}
add_action( 'puc_api_error', 'ner_michoel_log_update_check_error', 10, 4 );

/**
 * REST route (manage_options-gated) for actually diagnosing an update
 * check remotely — forces a fresh check the same way "Check Again"
 * does, then reports the real result: current version, whatever
 * update (if any) PUC now sees, and the last API error captured above
 * if one happened during this check.
 */
function ner_michoel_register_update_debug_route() {
	register_rest_route(
		'ner-michoel/v1',
		'/update-check-debug',
		array(
			'methods'             => 'GET',
			'callback'            => 'ner_michoel_handle_update_debug_rest',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		)
	);
}
add_action( 'rest_api_init', 'ner_michoel_register_update_debug_route' );

function ner_michoel_handle_update_debug_rest( WP_REST_Request $request ) {
	delete_option( 'nm_last_update_check_error' );

	$checker = isset( $GLOBALS['ner_michoel_update_checker'] ) ? $GLOBALS['ner_michoel_update_checker'] : null;
	$update  = null;

	if ( $checker ) {
		$checker->checkForUpdates(); // Forces a fresh check, bypassing PUC's own cache — same as "Check Again".
		$found = $checker->getUpdate();
		if ( $found ) {
			$update = array(
				'new_version' => isset( $found->version ) ? $found->version : null,
				'download_url' => isset( $found->download_url ) ? $found->download_url : null,
			);
		}
	}

	return new WP_REST_Response(
		array(
			'installed_version' => defined( 'NER_MICHOEL_CORE_VERSION' ) ? NER_MICHOEL_CORE_VERSION : null,
			'update_found'      => $update,
			'last_api_error'    => get_option( 'nm_last_update_check_error', null ),
		),
		200
	);
}

/**
 * REST route (manage_options-gated) that actually performs the
 * upgrade — the same WP_Upgrader machinery wp-admin's "Update Now"
 * button calls, triggered by one authenticated request instead of a
 * manual click, since Application Passwords can't drive that button
 * itself (it's a nonce+session AJAX action, not a REST call). POST
 * only: this changes files on disk, a GET must stay side-effect-free.
 */
function ner_michoel_register_update_now_route() {
	register_rest_route(
		'ner-michoel/v1',
		'/update-now',
		array(
			'methods'             => 'POST',
			'callback'            => 'ner_michoel_handle_update_now_rest',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		)
	);
}
add_action( 'rest_api_init', 'ner_michoel_register_update_now_route' );

function ner_michoel_handle_update_now_rest( WP_REST_Request $request ) {
	$checker = isset( $GLOBALS['ner_michoel_update_checker'] ) ? $GLOBALS['ner_michoel_update_checker'] : null;
	if ( ! $checker ) {
		return new WP_REST_Response( array( 'success' => false, 'message' => 'Update checker not initialized.' ), 500 );
	}

	$checker->checkForUpdates();
	if ( ! $checker->getUpdate() ) {
		return new WP_REST_Response( array( 'success' => false, 'message' => 'No update available.' ), 200 );
	}

	// Forces the direct filesystem method rather than letting WP fall
	// back to prompting for FTP credentials — there's no form to fill
	// out on the other end of a REST call. Fine on hosts (like this
	// one) where PHP already owns the files it's replacing.
	if ( ! defined( 'FS_METHOD' ) ) {
		define( 'FS_METHOD', 'direct' );
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';
	require_once ABSPATH . 'wp-admin/includes/plugin.php';

	$plugin_file = plugin_basename( NER_MICHOEL_CORE_PATH . 'ner-michoel-core.php' );
	$was_active  = is_plugin_active( $plugin_file );

	// Automatic_Upgrader_Skin is core's own headless skin (built for
	// background auto-updates) — it swallows the HTML progress output
	// a normal admin-page skin would print, so upgrade() doesn't leak
	// markup into this JSON response.
	$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
	$result   = $upgrader->upgrade( $plugin_file );

	if ( $was_active && ! is_plugin_active( $plugin_file ) ) {
		activate_plugin( $plugin_file );
	}

	if ( is_wp_error( $result ) ) {
		return new WP_REST_Response( array( 'success' => false, 'message' => $result->get_error_message() ), 500 );
	}

	if ( false === $result ) {
		$errors = $upgrader->skin->get_errors();
		return new WP_REST_Response(
			array(
				'success' => false,
				'message' => $errors ? implode( ' ', $errors ) : 'Upgrade failed for an unknown reason.',
			),
			500
		);
	}

	// Re-reads the version from the upgraded file on disk rather than
	// the NER_MICHOEL_CORE_VERSION constant, which reflects whatever
	// was loaded into memory at the start of this request, not what's
	// actually on disk after upgrade() just replaced it.
	$data = get_plugin_data( NER_MICHOEL_CORE_PATH . 'ner-michoel-core.php', false, false );

	return new WP_REST_Response(
		array(
			'success'            => true,
			'installed_version'  => isset( $data['Version'] ) ? $data['Version'] : null,
		),
		200
	);
}
