<?php
/**
 * Plugin Name:       Ner Michoel Core
 * Plugin URI:
 * Description:       Site functionality (custom post types, forms, integrations) for the Ner Michoel rebuild. Kept independent of the theme so content/data survive a future redesign.
 * Version:           0.7.0
 * Requires at least: 6.0
 * Requires PHP:       7.4
 * Author:             Tomo
 * Text Domain:       ner-michoel-core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NER_MICHOEL_CORE_VERSION', '0.7.0' );
define( 'NER_MICHOEL_CORE_PATH', plugin_dir_path( __FILE__ ) );
define( 'NER_MICHOEL_CORE_URL', plugin_dir_url( __FILE__ ) );

require_once NER_MICHOEL_CORE_PATH . 'includes/post-types.php';
require_once NER_MICHOEL_CORE_PATH . 'includes/shiur-meta.php';
require_once NER_MICHOEL_CORE_PATH . 'includes/term-meta.php';
require_once NER_MICHOEL_CORE_PATH . 'includes/shiur-functions.php';
require_once NER_MICHOEL_CORE_PATH . 'includes/gallery.php';
require_once NER_MICHOEL_CORE_PATH . 'includes/image-processing.php';
require_once NER_MICHOEL_CORE_PATH . 'includes/mazal-tov.php';
require_once NER_MICHOEL_CORE_PATH . 'includes/admin-dashboard.php';
require_once NER_MICHOEL_CORE_PATH . 'includes/homepage-slider.php';
require_once NER_MICHOEL_CORE_PATH . 'includes/downloads.php';
require_once NER_MICHOEL_CORE_PATH . 'includes/submissions.php';
require_once NER_MICHOEL_CORE_PATH . 'includes/bulk-upload.php';
require_once NER_MICHOEL_CORE_PATH . 'includes/sample-content.php';
require_once NER_MICHOEL_CORE_PATH . 'includes/mazal-tov-quick-add.php';
require_once NER_MICHOEL_CORE_PATH . 'includes/news-quick-post.php';
require_once NER_MICHOEL_CORE_PATH . 'includes/live-shiur.php';
require_once NER_MICHOEL_CORE_PATH . 'includes/missing-audio-report.php';
require_once NER_MICHOEL_CORE_PATH . 'includes/content-editor-role.php';
require_once NER_MICHOEL_CORE_PATH . 'includes/shiur-stats.php';
require_once NER_MICHOEL_CORE_PATH . 'includes/site-stats.php';
require_once NER_MICHOEL_CORE_PATH . 'includes/appearance-settings.php';
require_once NER_MICHOEL_CORE_PATH . 'includes/library-import.php';
require_once NER_MICHOEL_CORE_PATH . 'includes/bunny-storage.php';
require_once NER_MICHOEL_CORE_PATH . 'includes/admin-panel-login.php';
require_once NER_MICHOEL_CORE_PATH . 'includes/forms.php';
require_once NER_MICHOEL_CORE_PATH . 'includes/updates.php';

/**
 * The News & Events page (page-templates/news-events.php in the
 * theme) queries posts by category_name => 'news' — seed that
 * category so a fresh install has something for it to find instead
 * of silently showing an empty News section. Safe to call repeatedly.
 */
function ner_michoel_create_default_news_category() {
	if ( ! term_exists( 'news', 'category' ) ) {
		wp_insert_term( __( 'News', 'ner-michoel-core' ), 'category', array( 'slug' => 'news' ) );
	}
}

/**
 * Flush rewrite rules once after the CPTs/taxonomies register, so
 * /shiurim/, /speaker/..., /series/..., /galleries/..., /mazal-tov/
 * work without a manual visit to Settings > Permalinks. Default
 * gallery-type, mazal-tov-type, and "News" categories are seeded at
 * the same time.
 */
function ner_michoel_core_activate() {
	ner_michoel_register_shiur_post_type();
	ner_michoel_register_shiur_taxonomies();
	ner_michoel_register_gallery_post_type();
	ner_michoel_register_gallery_type_taxonomy();
	ner_michoel_register_mazal_tov_post_type();
	ner_michoel_register_mazal_tov_taxonomy();
	ner_michoel_create_default_gallery_types();
	ner_michoel_create_default_mazal_tov_types();
	ner_michoel_create_default_news_category();
	ner_michoel_register_content_editor_role();
	ner_michoel_create_pageviews_table();
	ner_michoel_schedule_pageviews_pruning();
	ner_michoel_create_import_queue_table();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'ner_michoel_core_activate' );

function ner_michoel_core_deactivate() {
	wp_clear_scheduled_hook( 'nm_prune_pageviews' );
	wp_clear_scheduled_hook( 'nm_import_discover_page' );
	wp_clear_scheduled_hook( 'nm_import_process_batch' );
	update_option( 'nm_import_running', false );
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'ner_michoel_core_deactivate' );
