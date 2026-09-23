<?php
/**
 * "Content Editor" role — everything the built-in `editor` role can
 * already do (which covers Shiurim/Galleries/Mazal Tov/News, since
 * those CPTs use the default 'post' capability type and so already
 * follow editor-level post capabilities) minus anything that could
 * touch plugins, themes, or site settings. Useful once day-to-day
 * content upkeep is handed off to a volunteer who isn't the site's
 * actual admin.
 *
 * Capabilities that could grant that access are explicitly stripped
 * after cloning `editor`, rather than just trusting a fresh `editor`
 * clone to lack them — some plugins add capabilities directly onto the
 * built-in `editor` role, and stripping keeps this role safe even if
 * that happens.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NER_MICHOEL_CONTENT_EDITOR_ROLE', 'nm_content_editor' );

function ner_michoel_register_content_editor_role() {
	$editor = get_role( 'editor' );
	if ( ! $editor ) {
		return;
	}

	$capabilities = $editor->capabilities;

	$denied = array(
		'manage_options',
		'edit_theme_options',
		'switch_themes',
		'edit_themes',
		'install_themes',
		'update_themes',
		'delete_themes',
		'edit_plugins',
		'install_plugins',
		'activate_plugins',
		'update_plugins',
		'delete_plugins',
		'edit_users',
		'create_users',
		'delete_users',
		'list_users',
		'promote_users',
		'remove_users',
		'edit_files',
		'update_core',
		'import',
		'export',
	);
	foreach ( $denied as $cap ) {
		unset( $capabilities[ $cap ] );
	}

	// Re-added on every activation so a denylist change (or an editor
	// capability change from a future WP release) actually takes effect
	// on upgrade, rather than only ever applying to a fresh install.
	if ( get_role( NER_MICHOEL_CONTENT_EDITOR_ROLE ) ) {
		remove_role( NER_MICHOEL_CONTENT_EDITOR_ROLE );
	}

	add_role( NER_MICHOEL_CONTENT_EDITOR_ROLE, __( 'Content Editor', 'ner-michoel-core' ), $capabilities );
}

/**
 * Backstop for a site where the plugin was already active before this
 * role existed — the full denylist re-sync above only runs on
 * (re)activation, but this guarantees the role exists at all without
 * requiring a manual deactivate/reactivate.
 */
function ner_michoel_maybe_register_content_editor_role() {
	if ( ! get_role( NER_MICHOEL_CONTENT_EDITOR_ROLE ) ) {
		ner_michoel_register_content_editor_role();
	}
}
add_action( 'admin_init', 'ner_michoel_maybe_register_content_editor_role' );
