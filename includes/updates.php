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
 * ~12 hours on its own, or immediately if you click "Check Again" —
 * no manual zip upload needed either way.
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

	\YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/Sapersteinindustrys/ner-michoel-core/',
		NER_MICHOEL_CORE_PATH . 'ner-michoel-core.php',
		'ner-michoel-core'
	);
}
ner_michoel_init_update_checker();
