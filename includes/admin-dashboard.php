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
	add_submenu_page( NER_MICHOEL_DASHBOARD_SLUG, __( 'Homepage Slider', 'ner-michoel-core' ), __( 'Homepage Slider', 'ner-michoel-core' ), 'edit_posts', 'nm-homepage-slider', 'ner_michoel_render_homepage_slider_page' );
	add_submenu_page( NER_MICHOEL_DASHBOARD_SLUG, __( 'Shiurim', 'ner-michoel-core' ), __( 'Shiurim', 'ner-michoel-core' ), 'edit_posts', 'edit.php?post_type=shiur' );
	add_submenu_page( NER_MICHOEL_DASHBOARD_SLUG, __( 'Speakers', 'ner-michoel-core' ), __( 'Speakers', 'ner-michoel-core' ), 'edit_posts', 'edit-tags.php?taxonomy=speaker&post_type=shiur' );
	add_submenu_page( NER_MICHOEL_DASHBOARD_SLUG, __( 'Series', 'ner-michoel-core' ), __( 'Series', 'ner-michoel-core' ), 'edit_posts', 'edit-tags.php?taxonomy=series&post_type=shiur' );
	add_submenu_page( NER_MICHOEL_DASHBOARD_SLUG, __( 'Photo & Video Galleries', 'ner-michoel-core' ), __( 'Galleries', 'ner-michoel-core' ), 'edit_posts', 'edit.php?post_type=gallery' );
	add_submenu_page( NER_MICHOEL_DASHBOARD_SLUG, __( 'Mazal Tov Announcements', 'ner-michoel-core' ), __( 'Mazal Tov', 'ner-michoel-core' ), 'edit_posts', 'edit.php?post_type=mazal_tov' );
	add_submenu_page( NER_MICHOEL_DASHBOARD_SLUG, __( 'Upload Pictures', 'ner-michoel-core' ), __( 'Upload Pictures', 'ner-michoel-core' ), 'upload_files', 'upload.php' );
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
			'title' => __( 'Mazal Tov Announcements', 'ner-michoel-core' ),
			'desc'  => __( 'Post engagement, birth, and other simcha announcements.', 'ner-michoel-core' ),
			'url'   => admin_url( 'edit.php?post_type=mazal_tov' ),
		),
		array(
			'title' => __( 'Homepage Slider', 'ner-michoel-core' ),
			'desc'  => __( 'Change the rotating banner images (and their text) on the homepage.', 'ner-michoel-core' ),
			'url'   => admin_url( 'admin.php?page=nm-homepage-slider' ),
		),
		array(
			'title' => __( 'Upload Pictures', 'ner-michoel-core' ),
			'desc'  => __( 'Add new photos to the media library, to use anywhere on the site.', 'ner-michoel-core' ),
			'url'   => admin_url( 'upload.php' ),
		),
	);
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
	$screen = get_current_screen();
	if ( ! $screen || false === strpos( $screen->id, NER_MICHOEL_DASHBOARD_SLUG ) ) {
		return;
	}

	wp_enqueue_style( 'ner-michoel-admin', NER_MICHOEL_CORE_URL . 'assets/admin.css', array(), NER_MICHOEL_CORE_VERSION );

	if ( isset( $_GET['page'] ) && 'nm-homepage-slider' === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		wp_enqueue_media();
		wp_enqueue_script( 'jquery-ui-sortable' );
		wp_enqueue_script( 'ner-michoel-admin', NER_MICHOEL_CORE_URL . 'assets/admin.js', array( 'jquery', 'jquery-ui-sortable' ), NER_MICHOEL_CORE_VERSION, true );
	}
}
add_action( 'admin_enqueue_scripts', 'ner_michoel_dashboard_admin_assets' );

/**
 * Makes site-url/admin redirect into the dashboard — straight there
 * if already logged in with edit access, otherwise to the normal
 * login screen (which then lands here after signing in). Note: this
 * will shadow any actual page/post whose slug happens to be "admin".
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
	} else {
		wp_safe_redirect( wp_login_url( $dashboard_url ) );
	}
	exit;
}
add_action( 'template_redirect', 'ner_michoel_admin_shortcut_redirect' );
