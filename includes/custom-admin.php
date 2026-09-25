<?php
/**
 * A genuinely custom admin UI at /admin — not a redirect into wp-admin.
 * Replaces ner_michoel_admin_shortcut_redirect()'s old behavior (which
 * always bounced a logged-in admin straight into wp-admin's own Site
 * Control Panel) with a real, standalone page: categories across the
 * top, sub-tabs within each, all still at the single /admin URL via
 * ?cat=&tab= query args.
 *
 * Technical note this whole file exists to solve: the settings screens
 * this reuses (Appearance, Live Shiur, Storage, etc.) all depend on
 * WordPress's own admin CSS classes (.button, .form-table, .notice)
 * and admin JS (wp.media, wp-color-picker) for their look and
 * behavior — none of which is normally available outside the real
 * wp-admin page framework. wp_admin_css() is the same function
 * wp-login.php uses to get genuine admin styling on a front-end page;
 * the scripts are enqueued and flushed the normal front-end way
 * (wp_enqueue_scripts / wp_head() / wp_footer()), not via
 * admin_enqueue_scripts, since this renders through template_redirect.
 *
 * Content types that are really about editing individual posts
 * (Shiurim, Speakers, Galleries, Mazal Tov, News, Submissions) use the
 * 'cms_type' tab type: our own list + edit UI (assets/custom-admin-
 * cms.js, styled by custom-admin-cms.css), talking to WordPress only
 * through a REST API underneath (includes/custom-admin-api.php) — not
 * wp-admin's own list tables/block editor. That replaced an earlier
 * "link/iframe into the real wp-admin screen" approach: linking away
 * defeated the point of a custom admin, and embedding the real screen
 * in an iframe still visibly read as WordPress, chrome-hiding CSS or
 * not. The remaining 'callback' tabs (quick-add forms, settings pages)
 * were already self-contained and didn't need rebuilding.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The whole nav: categories in order, each with its sub-tabs in order.
 * A tab is either 'callback' (rendered inline, reusing an existing
 * settings-page function) or 'link' (a deliberate jump to a real
 * wp-admin screen). 'capability' defaults to edit_posts if omitted —
 * checked *before* calling a callback, not left to the callback's own
 * internal wp_die(), since a wp_die() mid-render here would cut off
 * this page's own HTML with no footer.
 */
function ner_michoel_custom_admin_structure() {
	return array(
		'overview'   => array(
			'label' => __( 'Overview', 'ner-michoel-core' ),
			'tabs'  => array(
				''  => array( 'label' => __( 'Overview', 'ner-michoel-core' ), 'callback' => 'ner_michoel_render_custom_admin_overview' ),
			),
		),
		'content'    => array(
			'label' => __( 'Content', 'ner-michoel-core' ),
			'tabs'  => array(
				'shiurim'     => array( 'label' => __( 'Shiurim', 'ner-michoel-core' ), 'cms_type' => 'shiur' ),
				'speakers'    => array( 'label' => __( 'Speakers', 'ner-michoel-core' ), 'cms_type' => 'speaker', 'capability' => 'manage_categories' ),
				'series'      => array( 'label' => __( 'Series', 'ner-michoel-core' ), 'cms_type' => 'series', 'capability' => 'manage_categories' ),
				'galleries'   => array( 'label' => __( 'Galleries', 'ner-michoel-core' ), 'cms_type' => 'gallery' ),
				'mazaltov'    => array( 'label' => __( 'Mazal Tov', 'ner-michoel-core' ), 'cms_type' => 'mazal_tov' ),
				'mazaltovadd' => array( 'label' => __( 'Post a Mazal Tov', 'ner-michoel-core' ), 'callback' => 'ner_michoel_render_mazal_tov_quick_add_page' ),
				'newslist'    => array( 'label' => __( 'News Posts', 'ner-michoel-core' ), 'cms_type' => 'post_news' ),
				'news'        => array( 'label' => __( 'Post News', 'ner-michoel-core' ), 'callback' => 'ner_michoel_render_post_news_page' ),
				'bulk'        => array( 'label' => __( 'Bulk Upload', 'ner-michoel-core' ), 'callback' => 'ner_michoel_render_bulk_upload_page' ),
			),
		),
		'appearance' => array(
			'label' => __( 'Appearance', 'ner-michoel-core' ),
			'tabs'  => array(
				'colors' => array( 'label' => __( 'Colors & Font', 'ner-michoel-core' ), 'settings_type' => 'appearance', 'capability' => 'manage_options' ),
				'hero'   => array( 'label' => __( 'Hero Slider', 'ner-michoel-core' ), 'settings_type' => 'hero_slider' ),
				'layout' => array( 'label' => __( 'Layout Toggle', 'ner-michoel-core' ), 'settings_type' => 'layout_toggle' ),
			),
		),
		'site'       => array(
			'label' => __( 'Site Settings', 'ner-michoel-core' ),
			'tabs'  => array(
				'live'  => array( 'label' => __( 'Live Shiur / Zoom', 'ner-michoel-core' ), 'callback' => 'ner_michoel_render_live_shiur_page' ),
				'login' => array( 'label' => __( 'Admin Login Shortcut', 'ner-michoel-core' ), 'settings_type' => 'admin_login', 'capability' => 'manage_options' ),
			),
		),
		'data'       => array(
			'label' => __( 'Data & Migration', 'ner-michoel-core' ),
			'tabs'  => array(
				'sample'  => array( 'label' => __( 'Import Sample Content', 'ner-michoel-core' ), 'callback' => 'ner_michoel_render_sample_content_page', 'capability' => 'manage_options' ),
				'library' => array( 'label' => __( 'Full Library Import', 'ner-michoel-core' ), 'callback' => 'ner_michoel_render_library_import_page', 'capability' => 'manage_options' ),
				'storage' => array( 'label' => __( 'Storage (Bunny)', 'ner-michoel-core' ), 'settings_type' => 'storage', 'capability' => 'manage_options' ),
			),
		),
		'analytics'  => array(
			'label' => __( 'Analytics', 'ner-michoel-core' ),
			'tabs'  => array(
				'stats'     => array( 'label' => __( 'Site Statistics', 'ner-michoel-core' ), 'callback' => 'ner_michoel_render_site_stats_page', 'capability' => 'manage_options' ),
				'listened'  => array( 'label' => __( 'Most Listened', 'ner-michoel-core' ), 'cms_type' => 'shiur', 'cms_args' => array( 'orderby' => 'plays' ) ),
				'missing'   => array( 'label' => __( 'Missing Audio', 'ner-michoel-core' ), 'cms_type' => 'shiur', 'cms_args' => array( 'missing_audio' => 1 ) ),
				'inquiries' => array( 'label' => __( 'Recent Submissions', 'ner-michoel-core' ), 'cms_type' => 'nm_submission' ),
			),
		),
	);
}

function ner_michoel_render_custom_admin_overview() {
	?>
	<h1><?php esc_html_e( 'Overview', 'ner-michoel-core' ); ?></h1>
	<p><?php esc_html_e( 'Welcome to the Site Control Panel. Use the tabs above to manage every part of the site.', 'ner-michoel-core' ); ?></p>
	<p class="description"><?php esc_html_e( 'Anything involving individual posts (Shiurim, Speakers, Galleries) opens WordPress\'s own editor — everything else stays right here.', 'ner-michoel-core' ); ?></p>
	<?php
}

/**
 * Loads exactly the CSS/JS this page's content might need. Broad
 * rather than per-tab-precise on purpose — this is one page load, not
 * a performance-sensitive front-end template, and getting the exact
 * per-tab dependency list right isn't worth the fragility of trying.
 */
function ner_michoel_enqueue_custom_admin_assets() {
	wp_enqueue_style( 'ner-michoel-admin', NER_MICHOEL_CORE_URL . 'assets/admin.css', array(), NER_MICHOEL_CORE_VERSION );
	wp_enqueue_style( 'wp-color-picker' );
	wp_enqueue_script( 'wp-color-picker' );
	wp_enqueue_script( 'jquery-ui-sortable' );
	wp_enqueue_script( 'ner-michoel-admin', NER_MICHOEL_CORE_URL . 'assets/admin.js', array( 'jquery', 'jquery-ui-sortable' ), NER_MICHOEL_CORE_VERSION, true );
	if ( function_exists( 'ner_michoel_enqueue_media_pickers_script' ) ) {
		ner_michoel_enqueue_media_pickers_script();
	}

	// Our own content screens (list + edit) for the Content/Analytics
	// tabs — talks to WordPress only through the REST API underneath
	// (see includes/custom-admin-api.php), not wp-admin's own screens.
	wp_enqueue_media();
	wp_enqueue_style( 'ner-michoel-admin-cms', NER_MICHOEL_CORE_URL . 'assets/custom-admin-cms.css', array(), NER_MICHOEL_CORE_VERSION );
	wp_enqueue_script( 'ner-michoel-admin-cms', NER_MICHOEL_CORE_URL . 'assets/custom-admin-cms.js', array( 'jquery', 'jquery-ui-sortable' ), NER_MICHOEL_CORE_VERSION, true );
	wp_localize_script(
		'ner-michoel-admin-cms',
		'nmCmsConfig',
		array(
			'restUrl' => esc_url_raw( rest_url( 'ner-michoel/v1' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
		)
	);

	// Our own Settings screens (Appearance, Layout Toggle, Hero Slider,
	// Admin Login Shortcut, Storage) — see includes/custom-admin-
	// settings-api.php. Same REST auth as the CMS screens above.
	wp_enqueue_script( 'ner-michoel-admin-settings', NER_MICHOEL_CORE_URL . 'assets/custom-admin-settings.js', array( 'jquery', 'jquery-ui-sortable' ), NER_MICHOEL_CORE_VERSION, true );
	wp_localize_script(
		'ner-michoel-admin-settings',
		'nmSettingsConfig',
		array(
			'restUrl'          => esc_url_raw( rest_url( 'ner-michoel/v1' ) ),
			'nonce'            => wp_create_nonce( 'wp_rest' ),
			'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
			'searchPagesNonce' => wp_create_nonce( 'nm_search_pages' ),
		)
	);
}

function ner_michoel_render_custom_admin_ui() {
	$structure = ner_michoel_custom_admin_structure();

	$cat = isset( $_GET['cat'] ) ? sanitize_key( $_GET['cat'] ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! isset( $structure[ $cat ] ) ) {
		$cat = 'overview';
	}
	$tabs       = $structure[ $cat ]['tabs'];
	$tab_keys   = array_keys( $tabs );
	$tab        = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : $tab_keys[0]; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! isset( $tabs[ $tab ] ) ) {
		$tab = $tab_keys[0];
	}
	$active = $tabs[ $tab ];

	$required_cap = isset( $active['capability'] ) ? $active['capability'] : 'edit_posts';
	$has_access   = current_user_can( $required_cap );

	ner_michoel_enqueue_custom_admin_assets();

	nocache_headers();
	?>
	<!DOCTYPE html>
	<html <?php language_attributes(); ?>>
	<head>
		<meta charset="<?php bloginfo( 'charset' ); ?>" />
		<meta name="viewport" content="width=device-width, initial-scale=1" />
		<title><?php echo esc_html( get_bloginfo( 'name' ) . ' — ' . __( 'Site Control Panel', 'ner-michoel-core' ) ); ?></title>
		<?php
		wp_admin_css( 'common' );
		wp_admin_css( 'forms' );
		wp_admin_css( 'l10n' );
		wp_admin_css();
		wp_head();
		?>
		<style>
			body.wp-core-ui { background: #f0f0f1; margin: 0; }
			.nm-admin-shell { max-width: 1200px; margin: 0 auto; padding: 0 24px 60px; }
			.nm-admin-header { display: flex; align-items: center; justify-content: space-between; padding: 18px 0; }
			.nm-admin-header__title { font-size: 1.3rem; font-weight: 600; color: #1d2327; }
			.nm-admin-header__logout { font-size: 0.85rem; }
			.nm-admin-cats { display: flex; flex-wrap: wrap; gap: 4px; border-bottom: 1px solid #dcdcde; margin-bottom: 0; }
			.nm-admin-cats a { display: inline-block; padding: 10px 16px; text-decoration: none; color: #50575e; border: 1px solid transparent; border-bottom: none; border-radius: 4px 4px 0 0; font-weight: 500; }
			.nm-admin-cats a.is-active { background: #fff; color: #1d2327; border-color: #dcdcde; margin-bottom: -1px; }
			.nm-admin-subtabs { background: #fff; border: 1px solid #dcdcde; border-top: none; padding: 14px 20px 0; }
			.nm-admin-subtabs .nav-tab-wrapper { margin: 0; border: none; }
			.nm-admin-content { background: #fff; border: 1px solid #dcdcde; border-top: none; padding: 24px 20px 32px; }
			.nm-admin-content .wrap.nm-dashboard { margin: 0; }
			.nm-admin-denied { padding: 40px 0; text-align: center; color: #646970; }
			.nm-admin-content--iframe { padding: 0; }
			.nm-admin-content--iframe #nm-admin-iframe { display: block; width: 100%; height: 600px; border: none; }
			.nm-admin-content--iframe .nm-admin-iframe-fallback { margin: 0; padding: 8px 16px; font-size: 0.8rem; text-align: right; border-top: 1px solid #f0f0f1; }
			.nm-cms-root { min-height: 200px; }
		</style>
	</head>
	<body class="wp-admin wp-core-ui no-js">
		<div class="nm-admin-shell">
			<div class="nm-admin-header">
				<div class="nm-admin-header__title"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></div>
				<div class="nm-admin-header__logout"><a href="<?php echo esc_url( wp_logout_url( home_url( '/admin' ) ) ); ?>"><?php esc_html_e( 'Log Out', 'ner-michoel-core' ); ?></a></div>
			</div>

			<div class="nm-admin-cats">
				<?php foreach ( $structure as $cat_key => $cat_data ) : ?>
					<a href="<?php echo esc_url( add_query_arg( array( 'cat' => $cat_key ), home_url( '/admin' ) ) ); ?>" class="<?php echo $cat_key === $cat ? 'is-active' : ''; ?>">
						<?php echo esc_html( $cat_data['label'] ); ?>
					</a>
				<?php endforeach; ?>
			</div>

			<?php if ( count( $tabs ) > 1 ) : ?>
				<div class="nm-admin-subtabs">
					<h2 class="nav-tab-wrapper">
						<?php foreach ( $tabs as $tab_key => $tab_data ) : ?>
							<a href="<?php echo esc_url( add_query_arg( array( 'cat' => $cat, 'tab' => $tab_key ), home_url( '/admin' ) ) ); ?>" class="nav-tab <?php echo $tab_key === $tab ? 'nav-tab-active' : ''; ?>">
								<?php echo esc_html( $tab_data['label'] ); ?>
							</a>
						<?php endforeach; ?>
					</h2>
				</div>
			<?php endif; ?>

			<div class="nm-admin-content<?php echo isset( $active['iframe'] ) ? ' nm-admin-content--iframe' : ''; ?>">
				<?php if ( isset( $active['cms_type'] ) ) : ?>
					<?php if ( ! $has_access ) : ?>
						<div class="nm-admin-denied"><?php esc_html_e( 'You don\'t have permission to view this section.', 'ner-michoel-core' ); ?></div>
					<?php else : ?>
						<div class="nm-cms-root" data-cms-type="<?php echo esc_attr( $active['cms_type'] ); ?>" data-cms-args="<?php echo esc_attr( wp_json_encode( isset( $active['cms_args'] ) ? $active['cms_args'] : array() ) ); ?>"></div>
					<?php endif; ?>
				<?php elseif ( isset( $active['settings_type'] ) ) : ?>
					<?php if ( ! $has_access ) : ?>
						<div class="nm-admin-denied"><?php esc_html_e( 'You don\'t have permission to view this section.', 'ner-michoel-core' ); ?></div>
					<?php else : ?>
						<div class="nm-settings-root" data-settings-type="<?php echo esc_attr( $active['settings_type'] ); ?>"></div>
					<?php endif; ?>
				<?php elseif ( isset( $active['iframe'] ) ) : ?>
					<iframe id="nm-admin-iframe" src="<?php echo esc_url( $active['iframe'] ); ?>" title="<?php echo esc_attr( $active['label'] ); ?>"></iframe>
					<p class="nm-admin-iframe-fallback"><a href="<?php echo esc_url( $active['iframe'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open in a new tab', 'ner-michoel-core' ); ?> →</a></p>
					<script>
					( function () {
						var frame = document.getElementById( 'nm-admin-iframe' );
						frame.addEventListener( 'load', function () {
							try {
								var doc = frame.contentDocument;
								if ( ! doc ) { return; }
								var style = doc.createElement( 'style' );
								// Hides WordPress's own chrome (top bar, left
								// menu, footer, screen-options tray) so the
								// embedded screen reads as part of this UI
								// instead of "wp-admin inside wp-admin".
								style.textContent = '#wpadminbar,#adminmenumain,#adminmenuback,#adminmenuwrap,#wpfooter,#screen-meta-links,#screen-meta{display:none!important;}' +
									'html.wp-toolbar{padding-top:0!important;}' +
									'#wpcontent,#wpbody{margin-left:0!important;}' +
									'#wpbody-content{padding-bottom:20px!important;}' +
									'body{min-width:0!important;}';
								doc.head.appendChild( style );
								frame.style.height = Math.max( 600, doc.body.scrollHeight + 40 ) + 'px';
							} catch ( e ) {
								// Cross-origin or otherwise inaccessible — the
								// visible fallback link above still works.
							}
						} );
					} )();
					</script>
				<?php elseif ( isset( $active['link'] ) ) : ?>
					<script>window.location.replace( <?php echo wp_json_encode( $active['link'] ); ?> );</script>
					<p><?php esc_html_e( 'Opening…', 'ner-michoel-core' ); ?> <a href="<?php echo esc_url( $active['link'] ); ?>"><?php esc_html_e( 'Click here if you\'re not redirected.', 'ner-michoel-core' ); ?></a></p>
				<?php elseif ( ! $has_access ) : ?>
					<div class="nm-admin-denied"><?php esc_html_e( 'You don\'t have permission to view this section.', 'ner-michoel-core' ); ?></div>
				<?php elseif ( isset( $active['callback'] ) && function_exists( $active['callback'] ) ) : ?>
					<?php call_user_func( $active['callback'] ); ?>
				<?php else : ?>
					<div class="nm-admin-denied"><?php esc_html_e( 'This section isn\'t available right now.', 'ner-michoel-core' ); ?></div>
				<?php endif; ?>
			</div>
		</div>
		<?php wp_footer(); ?>
	</body>
	</html>
	<?php
	exit;
}
