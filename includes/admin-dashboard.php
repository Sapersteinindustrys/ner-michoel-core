<?php
/**
 * "Site Control Panel" — a single, plain-language admin menu that
 * gathers everything an editor touches day-to-day (shiurim, speakers,
 * series, galleries, mazal tov, homepage slider) instead of scattered
 * top-level menus and WordPress jargon. Also makes /admin redirect
 * straight here (login-gated, same as any wp-admin page).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NER_MICHOEL_DASHBOARD_SLUG', 'nm-dashboard' );

/**
 * Shared by every screen with a wp.media() "Choose Image/File(s)"
 * button (gallery.php, shiur-meta.php, term-meta.php, bulk-upload.php)
 * — one script (assets/media-pickers.js), enqueued alongside
 * wp_enqueue_media() wherever any of those buttons actually appear.
 * Safe to call more than once per page load; wp_enqueue_script() and
 * wp_localize_script() are both no-ops on a repeat call for the same
 * handle.
 */
function ner_michoel_enqueue_media_pickers_script() {
	wp_enqueue_media();
	wp_enqueue_script( 'ner-michoel-media-pickers', NER_MICHOEL_CORE_URL . 'assets/media-pickers.js', array( 'jquery', 'jquery-ui-sortable' ), NER_MICHOEL_CORE_VERSION, true );
	wp_localize_script(
		'ner-michoel-media-pickers',
		'nmMediaPickers',
		array(
			'galleryTitle'     => __( 'Select gallery images', 'ner-michoel-core' ),
			'audioTitle'       => __( 'Select or upload an audio or video file', 'ner-michoel-core' ),
			'noFileSelected'   => __( 'No file selected.', 'ner-michoel-core' ),
			'coverImageTitle'  => __( 'Select or upload a cover image', 'ner-michoel-core' ),
			'bulkUploadTitle'  => __( 'Select or upload audio/video files', 'ner-michoel-core' ),
		)
	);
}

function ner_michoel_register_dashboard_menu() {
	add_menu_page(
		__( 'Site Control Panel', 'ner-michoel-core' ),
		__( 'Site Control Panel', 'ner-michoel-core' ),
		'edit_posts',
		NER_MICHOEL_DASHBOARD_SLUG,
		'ner_michoel_render_dashboard_home',
		'dashicons-star-filled',
		3
	);

	add_submenu_page( NER_MICHOEL_DASHBOARD_SLUG, __( 'Overview', 'ner-michoel-core' ), __( 'Overview', 'ner-michoel-core' ), 'edit_posts', NER_MICHOEL_DASHBOARD_SLUG, 'ner_michoel_render_dashboard_home' );
	add_submenu_page( NER_MICHOEL_DASHBOARD_SLUG, __( 'Home Page', 'ner-michoel-core' ), __( 'Home Page', 'ner-michoel-core' ), 'edit_posts', 'nm-homepage-settings', 'ner_michoel_render_homepage_settings_page' );
	add_submenu_page( NER_MICHOEL_DASHBOARD_SLUG, __( 'Live Shiur / Zoom', 'ner-michoel-core' ), __( 'Live Shiur / Zoom', 'ner-michoel-core' ), 'edit_posts', 'nm-live-shiur', 'ner_michoel_render_live_shiur_page' );
	add_submenu_page( NER_MICHOEL_DASHBOARD_SLUG, __( 'Appearance', 'ner-michoel-core' ), __( 'Appearance', 'ner-michoel-core' ), 'edit_posts', 'nm-appearance', 'ner_michoel_render_appearance_settings_page' );
	add_submenu_page( NER_MICHOEL_DASHBOARD_SLUG, __( 'Layout Toggle', 'ner-michoel-core' ), __( 'Layout Toggle', 'ner-michoel-core' ), 'edit_posts', 'nm-layout-toggle', 'ner_michoel_render_layout_toggle_settings_page' );
	add_submenu_page( NER_MICHOEL_DASHBOARD_SLUG, __( 'Shiurim', 'ner-michoel-core' ), __( 'Shiurim', 'ner-michoel-core' ), 'edit_posts', 'edit.php?post_type=shiur' );
	add_submenu_page( NER_MICHOEL_DASHBOARD_SLUG, __( 'Speakers', 'ner-michoel-core' ), __( 'Speakers', 'ner-michoel-core' ), 'edit_posts', 'edit-tags.php?taxonomy=speaker&post_type=shiur' );
	add_submenu_page( NER_MICHOEL_DASHBOARD_SLUG, __( 'Series', 'ner-michoel-core' ), __( 'Series', 'ner-michoel-core' ), 'edit_posts', 'edit-tags.php?taxonomy=series&post_type=shiur' );
	add_submenu_page( NER_MICHOEL_DASHBOARD_SLUG, __( 'Photo & Video Galleries', 'ner-michoel-core' ), __( 'Galleries', 'ner-michoel-core' ), 'edit_posts', 'edit.php?post_type=gallery' );
	add_submenu_page( NER_MICHOEL_DASHBOARD_SLUG, __( 'Post News', 'ner-michoel-core' ), __( 'Post News', 'ner-michoel-core' ), 'edit_posts', 'nm-post-news', 'ner_michoel_render_post_news_page' );
	add_submenu_page( NER_MICHOEL_DASHBOARD_SLUG, __( 'Mazal Tov Announcements', 'ner-michoel-core' ), __( 'Mazal Tov', 'ner-michoel-core' ), 'edit_posts', 'edit.php?post_type=mazal_tov' );
	add_submenu_page( NER_MICHOEL_DASHBOARD_SLUG, __( 'Post a Mazal Tov', 'ner-michoel-core' ), __( 'Post a Mazal Tov', 'ner-michoel-core' ), 'edit_posts', 'nm-mazal-tov-quick-add', 'ner_michoel_render_mazal_tov_quick_add_page' );
	add_submenu_page( NER_MICHOEL_DASHBOARD_SLUG, __( 'Upload Pictures', 'ner-michoel-core' ), __( 'Upload Pictures', 'ner-michoel-core' ), 'upload_files', 'upload.php' );
	add_submenu_page( NER_MICHOEL_DASHBOARD_SLUG, __( 'Recent Submissions', 'ner-michoel-core' ), __( 'Submissions', 'ner-michoel-core' ), 'edit_posts', 'edit.php?post_type=nm_submission' );
	add_submenu_page( NER_MICHOEL_DASHBOARD_SLUG, __( 'Bulk Upload Shiurim', 'ner-michoel-core' ), __( 'Bulk Upload', 'ner-michoel-core' ), 'edit_posts', 'nm-bulk-upload', 'ner_michoel_render_bulk_upload_page' );
	add_submenu_page( NER_MICHOEL_DASHBOARD_SLUG, __( 'Most Listened Shiurim', 'ner-michoel-core' ), __( 'Most Listened', 'ner-michoel-core' ), 'edit_posts', 'edit.php?post_type=shiur&orderby=nm_plays&order=desc' );

	// manage_options — traffic data reads more like site-operator info
	// than day-to-day content editing, same reasoning as Import Sample
	// Content above.
	add_submenu_page( NER_MICHOEL_DASHBOARD_SLUG, __( 'Site Statistics', 'ner-michoel-core' ), __( 'Site Statistics', 'ner-michoel-core' ), 'manage_options', 'nm-site-stats', 'ner_michoel_render_site_stats_page' );

	// manage_options (not the panel's usual edit_posts) — this is a
	// one-time migration action, not routine editing.
	add_submenu_page( NER_MICHOEL_DASHBOARD_SLUG, __( 'Import Sample Content', 'ner-michoel-core' ), __( 'Import Sample Content', 'ner-michoel-core' ), 'manage_options', 'nm-import-sample-content', 'ner_michoel_render_sample_content_page' );
	add_submenu_page( NER_MICHOEL_DASHBOARD_SLUG, __( 'Full Library Import', 'ner-michoel-core' ), __( 'Full Library Import', 'ner-michoel-core' ), 'manage_options', 'nm-library-import', 'ner_michoel_render_library_import_page' );
	add_submenu_page( NER_MICHOEL_DASHBOARD_SLUG, __( 'Storage (Bunny)', 'ner-michoel-core' ), __( 'Storage', 'ner-michoel-core' ), 'manage_options', 'nm-storage', 'ner_michoel_render_storage_settings_page' );
	add_submenu_page( NER_MICHOEL_DASHBOARD_SLUG, __( 'Admin Login Shortcut', 'ner-michoel-core' ), __( 'Admin Login Shortcut', 'ner-michoel-core' ), 'manage_options', 'nm-admin-login', 'ner_michoel_render_admin_panel_login_settings_page' );

	// Registered under the same parent (so its screen ID still matches
	// NER_MICHOEL_DASHBOARD_SLUG for asset-loading below) but hidden from
	// the nav — only reachable by the redirect at the end of that flow.
	add_submenu_page( NER_MICHOEL_DASHBOARD_SLUG, __( 'Assign Batch', 'ner-michoel-core' ), __( 'Assign Batch', 'ner-michoel-core' ), 'edit_posts', 'nm-bulk-assign', 'ner_michoel_render_bulk_assign_page' );
	remove_submenu_page( NER_MICHOEL_DASHBOARD_SLUG, 'nm-bulk-assign' );
}
add_action( 'admin_menu', 'ner_michoel_register_dashboard_menu' );

function ner_michoel_render_dashboard_home() {
	$cards = array(
		array(
			'title' => __( 'Shiurim', 'ner-michoel-core' ),
			'desc'  => __( 'Add or edit audio shiurim, and assign a speaker and series.', 'ner-michoel-core' ),
			'url'   => admin_url( 'edit.php?post_type=shiur' ),
		),
		array(
			'title' => __( 'Bulk Upload Shiurim', 'ner-michoel-core' ),
			'desc'  => __( 'Upload several audio or video files at once, then assign a speaker and series to the whole batch.', 'ner-michoel-core' ),
			'url'   => admin_url( 'admin.php?page=nm-bulk-upload' ),
		),
		array(
			'title' => __( 'Most Listened Shiurim', 'ner-michoel-core' ),
			'desc'  => __( 'See play counts and how many listeners finished each shiur.', 'ner-michoel-core' ),
			'url'   => admin_url( 'edit.php?post_type=shiur&orderby=nm_plays&order=desc' ),
		),
		array(
			'title' => __( 'Speakers', 'ner-michoel-core' ),
			'desc'  => __( 'Manage the list of speakers and their photos.', 'ner-michoel-core' ),
			'url'   => admin_url( 'edit-tags.php?taxonomy=speaker&post_type=shiur' ),
		),
		array(
			'title' => __( 'Series', 'ner-michoel-core' ),
			'desc'  => __( 'Manage shiur series (courses/topics) and their cover art.', 'ner-michoel-core' ),
			'url'   => admin_url( 'edit-tags.php?taxonomy=series&post_type=shiur' ),
		),
		array(
			'title' => __( 'Photo & Video Galleries', 'ner-michoel-core' ),
			'desc'  => __( 'Upload event photos, or add video links, organized into galleries.', 'ner-michoel-core' ),
			'url'   => admin_url( 'edit.php?post_type=gallery' ),
		),
		array(
			'title' => __( 'Post News', 'ner-michoel-core' ),
			'desc'  => __( 'Start a News & Events post — filed under the right category automatically.', 'ner-michoel-core' ),
			'url'   => admin_url( 'admin.php?page=nm-post-news' ),
		),
		array(
			'title' => __( 'Missing Audio', 'ner-michoel-core' ),
			'desc'  => __( 'See which Shiurim have no audio or video file attached yet.', 'ner-michoel-core' ),
			'url'   => admin_url( 'edit.php?post_type=shiur&nm_missing_audio=1' ),
		),
		array(
			'title' => __( 'Mazal Tov Announcements', 'ner-michoel-core' ),
			'desc'  => __( 'Post engagement, birth, and other simcha announcements.', 'ner-michoel-core' ),
			'url'   => admin_url( 'edit.php?post_type=mazal_tov' ),
		),
		array(
			'title' => __( 'Post a Mazal Tov (Quick)', 'ner-michoel-core' ),
			'desc'  => __( 'A 4-field shortcut for the common case: honoree, relationship, type, years.', 'ner-michoel-core' ),
			'url'   => admin_url( 'admin.php?page=nm-mazal-tov-quick-add' ),
		),
		array(
			'title' => __( 'Home Page', 'ner-michoel-core' ),
			'desc'  => __( 'Change the rotating hero banner (images, text, buttons) and other homepage settings.', 'ner-michoel-core' ),
			'url'   => admin_url( 'admin.php?page=nm-homepage-settings' ),
		),
		array(
			'title' => __( 'Live Shiur / Zoom', 'ner-michoel-core' ),
			'desc'  => __( 'Update the Zoom link, meeting ID, and schedule shown on the site.', 'ner-michoel-core' ),
			'url'   => admin_url( 'admin.php?page=nm-live-shiur' ),
		),
		array(
			'title' => __( 'Appearance', 'ner-michoel-core' ),
			'desc'  => __( 'Colors and font for the Shiurim app and player bar.', 'ner-michoel-core' ),
			'url'   => admin_url( 'admin.php?page=nm-appearance' ),
		),
		array(
			'title' => __( 'Upload Pictures', 'ner-michoel-core' ),
			'desc'  => __( 'Add new photos to the media library, to use anywhere on the site.', 'ner-michoel-core' ),
			'url'   => admin_url( 'upload.php' ),
		),
		array(
			'title' => __( 'Recent Submissions', 'ner-michoel-core' ),
			'desc'  => __( 'See Contact and Email-a-Magid-Shiur form submissions, in case an email never arrives.', 'ner-michoel-core' ),
			'url'   => admin_url( 'edit.php?post_type=nm_submission' ),
		),
	);

	if ( current_user_can( 'manage_options' ) ) {
		$cards[] = array(
			'title' => __( 'Import Sample Content', 'ner-michoel-core' ),
			'desc'  => __( 'One-time: seed this site with real sample shiurim, speakers, and a series.', 'ner-michoel-core' ),
			'url'   => admin_url( 'admin.php?page=nm-import-sample-content' ),
		);
		$cards[] = array(
			'title' => __( 'Full Library Import', 'ner-michoel-core' ),
			'desc'  => __( 'Background import of the entire nermichoel.org shiur archive (16,756 items).', 'ner-michoel-core' ),
			'url'   => admin_url( 'admin.php?page=nm-library-import' ),
		);
		$cards[] = array(
			'title' => __( 'Storage (Bunny)', 'ner-michoel-core' ),
			'desc'  => __( 'Offload new media uploads to Bunny Storage instead of local disk.', 'ner-michoel-core' ),
			'url'   => admin_url( 'admin.php?page=nm-storage' ),
		);
		$cards[] = array(
			'title' => __( 'Admin Login Shortcut', 'ner-michoel-core' ),
			'desc'  => __( 'Let /admin be reached with a single password instead of the full login.', 'ner-michoel-core' ),
			'url'   => admin_url( 'admin.php?page=nm-admin-login' ),
		);
		$cards[] = array(
			'title' => __( 'Site Statistics', 'ner-michoel-core' ),
			'desc'  => __( 'Traffic, top pages, referrers, and page load time — self-hosted, no third party.', 'ner-michoel-core' ),
			'url'   => admin_url( 'admin.php?page=nm-site-stats' ),
		);
	}
	?>
	<div class="wrap nm-dashboard">
		<h1><?php esc_html_e( 'Site Control Panel', 'ner-michoel-core' ); ?></h1>
		<p><?php esc_html_e( 'Everything you can edit on the site, in one place.', 'ner-michoel-core' ); ?></p>
		<div class="nm-dashboard__grid">
			<?php foreach ( $cards as $card ) : ?>
				<a class="nm-dashboard__card" href="<?php echo esc_url( $card['url'] ); ?>">
					<h2><?php echo esc_html( $card['title'] ); ?></h2>
					<p><?php echo esc_html( $card['desc'] ); ?></p>
				</a>
			<?php endforeach; ?>
		</div>
	</div>
	<?php
}

/**
 * Loads the dashboard's plain admin.css on every nm-dashboard screen,
 * and the repeater JS (+ media picker + sortable) only on the
 * Homepage Slider page specifically.
 */
function ner_michoel_dashboard_admin_assets() {
	$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	// Was gated on get_current_screen()->id containing NER_MICHOEL_DASHBOARD_SLUG
	// ('nm-dashboard') — but WordPress derives a submenu's screen id from
	// the top-level menu's *title* text ("Site Control Panel", sanitized
	// to "site-control-panel"), not its slug, so that string never
	// actually appeared in $screen->id. The check silently failed on
	// every Site Control Panel screen, so admin.css/admin.js/the media
	// picker never loaded anywhere in here — not just on this page.
	// $page (from the URL's own ?page=) doesn't have that problem, since
	// it's exactly what we registered every submenu's slug as.
	if ( '' === $page || 0 !== strpos( $page, 'nm-' ) ) {
		return;
	}

	wp_enqueue_style( 'ner-michoel-admin', NER_MICHOEL_CORE_URL . 'assets/admin.css', array(), NER_MICHOEL_CORE_VERSION );

	if ( 'nm-homepage-settings' === $page ) {
		wp_enqueue_media();
		wp_enqueue_script( 'jquery-ui-sortable' );
		wp_enqueue_script( 'ner-michoel-admin', NER_MICHOEL_CORE_URL . 'assets/admin.js', array( 'jquery', 'jquery-ui-sortable' ), NER_MICHOEL_CORE_VERSION, true );
		wp_localize_script(
			'ner-michoel-admin',
			'nmHomepageSlider',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'nm_search_pages' ),
			)
		);
	} elseif ( 'nm-bulk-upload' === $page ) {
		ner_michoel_enqueue_media_pickers_script();
	}
}
add_action( 'admin_enqueue_scripts', 'ner_michoel_dashboard_admin_assets' );

/**
 * Makes site-url/admin redirect into the dashboard — straight there if
 * already logged in with edit access; otherwise, if a shortcut
 * password has been configured (see includes/admin-panel-login.php), a
 * plain one-field password form instead of the full WP login; if no
 * shortcut password was ever set, falls back to the normal login
 * screen unchanged (which then lands here after signing in). Note:
 * this will shadow any actual page/post whose slug happens to be
 * "admin".
 */
function ner_michoel_admin_shortcut_redirect() {
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}

	$target_path  = untrailingslashit( (string) wp_parse_url( home_url( '/admin' ), PHP_URL_PATH ) );
	$request_path = untrailingslashit( (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) );

	if ( '' === $request_path || $request_path !== $target_path ) {
		return;
	}

	$dashboard_url = admin_url( 'admin.php?page=' . NER_MICHOEL_DASHBOARD_SLUG );

	if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
		wp_safe_redirect( $dashboard_url );
		exit;
	}

	if ( get_option( 'nm_admin_panel_password' ) && function_exists( 'ner_michoel_render_admin_panel_login' ) ) {
		ner_michoel_render_admin_panel_login( $dashboard_url ); // Always exits.
	}

	wp_safe_redirect( wp_login_url( $dashboard_url ) );
	exit;
}
add_action( 'template_redirect', 'ner_michoel_admin_shortcut_redirect' );
