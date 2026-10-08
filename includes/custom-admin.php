<?php
/**
 * The Site Control Panel at /admin — a standalone page, not a redirect
 * into wp-admin.
 *
 * Layout: a sidebar of task groups (Shiurim, Announcements, Messages,
 * Homepage & Look, Reports, and a collapsed Advanced group for the
 * rarely-needed tools), a top bar (search, View site, Help, account), and
 * the page itself under a header that says what the page is for. Every
 * page carries its own plain-language help (the Help drawer), and the
 * shell adds a first-visit guided tour, a Ctrl+K "jump to" search, and
 * friendly toasts and confirm dialogs (assets/custom-admin-shell.js,
 * styled by assets/custom-admin-shell.css).
 *
 * Routing: everything stays at the single /admin URL with ?tab=<key>.
 * Tab keys are unique across groups, so the group is found from the tab.
 * Older links that used ?cat=&tab= still land on the same page.
 *
 * Page types: 'cms_type' (our own list + edit UI, assets/custom-admin-
 * cms.js, over the REST API in includes/custom-admin-api.php),
 * 'settings_type' (one settings form, assets/custom-admin-settings.js,
 * over includes/custom-admin-settings-api.php), and 'callback' (an
 * existing self-contained screen rendered inline). wp_admin_css() is the
 * same function wp-login.php uses to get admin styling on a front-end
 * page; the callback screens and the media library modal still need it.
 *
 * The theme's own front-end assets (and the player bar it prints in the
 * footer) are kept off this page, so its button and heading styles can't
 * leak into the panel.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ------------------------------------------------------------------ */
/* Icons                                                               */
/* ------------------------------------------------------------------ */

/**
 * Line icons (24×24, stroke), printed once as an SVG sprite and used by
 * both the PHP markup and the panel's JS (<use href="#nm-i-NAME">).
 */
function ner_michoel_admin_icon_paths() {
	return array(
		'home'        => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/><path d="M10 21v-6h4v6"/>',
		'headphones'  => '<path d="M3 14h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-7a9 9 0 0 1 18 0v7a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3"/>',
		'file-text'   => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/>',
		'upload'      => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5"/><path d="M12 3v12"/>',
		'mic'         => '<rect x="9" y="2" width="6" height="12" rx="3"/><path d="M19 10v1a7 7 0 0 1-14 0v-1"/><path d="M12 18v4"/><path d="M8 22h8"/>',
		'layers'      => '<path d="m12 2 10 5-10 5L2 7z"/><path d="m2 17 10 5 10-5"/><path d="m2 12 10 5 10-5"/>',
		'tag'         => '<path d="M20.59 13.41 13.42 20.58a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><circle cx="7" cy="7" r="1.5"/>',
		'image'       => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.09-3.09a2 2 0 0 0-2.82 0L6 21"/>',
		'sparkles'    => '<path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/><path d="M19 3v4"/><path d="M21 5h-4"/><path d="M5 17v4"/><path d="M7 19H3"/>',
		'zap'         => '<path d="M13 2 3 14h9l-1 8 10-12h-9z"/>',
		'newspaper'   => '<path d="M4 22h16a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2H8a2 2 0 0 0-2 2v16a2 2 0 0 1-2 2zm0 0a2 2 0 0 1-2-2v-9c0-1.1.9-2 2-2h2"/><path d="M18 14h-8"/><path d="M15 18h-5"/><path d="M10 6h8v4h-8z"/>',
		'inbox'       => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
		'banner'      => '<rect x="2" y="4" width="20" height="13" rx="2"/><path d="m2 14 5-4 4 3 4-5 7 6"/><path d="M8 21h8"/>',
		'video'       => '<path d="m22 8-6 4 6 4V8z"/><rect x="2" y="6" width="14" height="12" rx="2"/>',
		'palette'     => '<circle cx="13.5" cy="6.5" r="1"/><circle cx="17.5" cy="10.5" r="1"/><circle cx="8.5" cy="7.5" r="1"/><circle cx="6.5" cy="12.5" r="1"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.93 0 1.65-.75 1.65-1.69 0-.44-.18-.84-.44-1.13-.29-.29-.44-.65-.44-1.13a1.64 1.64 0 0 1 1.67-1.67h2c3.05 0 5.56-2.5 5.56-5.55C21.97 6.01 17.46 2 12 2z"/>',
		'list'        => '<path d="M8 6h13"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M3 6h.01"/><path d="M3 12h.01"/><path d="M3 18h.01"/>',
		'toggle'      => '<rect x="2" y="7" width="20" height="10" rx="5"/><circle cx="16" cy="12" r="3"/>',
		'chart'       => '<path d="M3 3v18h18"/><path d="M8 16v-4"/><path d="M13 16V8"/><path d="M18 16V5"/>',
		'trending'    => '<path d="m22 7-8.5 8.5-5-5L2 17"/><path d="M16 7h6v6"/>',
		'alert'       => '<circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/>',
		'warning'     => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
		'shield'      => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
		'key'         => '<circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6"/><path d="m15.5 7.5 3 3L22 7l-3-3"/>',
		'cloud'       => '<path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9z"/>',
		'download'    => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
		'database'    => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/><path d="M3 12c0 1.66 4 3 9 3s9-1.34 9-3"/>',
		'search'      => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
		'help'        => '<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/>',
		'external'    => '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
		'logout'      => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
		'chevron'     => '<path d="m6 9 6 6 6-6"/>',
		'chevron-r'   => '<path d="m9 18 6-6-6-6"/>',
		'chevron-l'   => '<path d="m15 18-6-6 6-6"/>',
		'x'           => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
		'plus'        => '<path d="M12 5v14"/><path d="M5 12h14"/>',
		'check'       => '<path d="M20 6 9 17l-5-5"/>',
		'check-circle' => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="M22 4 12 14.01l-3-3"/>',
		'pencil'      => '<path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5z"/>',
		'trash'       => '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
		'eye'         => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
		'menu'        => '<path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h16"/>',
		'bulb'        => '<path d="M9 18h6"/><path d="M10 22h4"/><path d="M15.09 14c.18-.98.65-1.74 1.41-2.5A4.65 4.65 0 0 0 18 8 6 6 0 0 0 6 8c0 1 .23 2.23 1.5 3.5A4.61 4.61 0 0 1 8.91 14"/>',
		'compass'     => '<circle cx="12" cy="12" r="10"/><path d="m16.24 7.76-2.12 6.36-6.36 2.12 2.12-6.36z"/>',
		'megaphone'   => '<path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
		'settings'    => '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
		'arrow-r'     => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
		'info'        => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
		'grip'        => '<circle cx="9" cy="6" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="18" r="1"/>',
		'globe'       => '<circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
		'wrench'      => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
		'book'        => '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>',
		'clock'       => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
	);
}

function ner_michoel_admin_icon( $name, $class = '' ) {
	return '<svg class="nm-ico' . ( $class ? ' ' . esc_attr( $class ) : '' ) . '" aria-hidden="true" focusable="false"><use href="#nm-i-' . esc_attr( $name ) . '"></use></svg>';
}

function ner_michoel_admin_icon_sprite() {
	echo '<svg xmlns="http://www.w3.org/2000/svg" style="position:absolute;width:0;height:0;overflow:hidden" aria-hidden="true"><defs>';
	foreach ( ner_michoel_admin_icon_paths() as $name => $paths ) {
		// Paths are fixed strings defined above, not user input.
		echo '<symbol id="nm-i-' . esc_attr( $name ) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $paths . '</symbol>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	echo '</defs></svg>';
}

/* ------------------------------------------------------------------ */
/* Navigation                                                          */
/* ------------------------------------------------------------------ */

/**
 * The whole nav: groups in sidebar order, each with its pages in order.
 *
 * Per page: 'label' (page title), 'nav' (shorter sidebar label, optional),
 * 'icon', 'desc' (one sentence: what this page is for, shown under the
 * title), 'help' (steps + an optional tip, shown in the Help drawer),
 * 'keywords' (extra words the Ctrl+K search matches), 'capability'
 * (default edit_posts — pages the user can't open are left out of the
 * nav), and 'hidden' (reachable by link, not listed in the nav).
 */
function ner_michoel_custom_admin_structure() {
	return array(
		'home'          => array(
			'label' => __( 'Home', 'ner-michoel-core' ),
			'icon'  => 'home',
			'tabs'  => array(
				'home' => array(
					'label'    => __( 'Home', 'ner-michoel-core' ),
					'icon'     => 'home',
					'callback' => 'ner_michoel_render_custom_admin_overview',
					'desc'     => __( 'Your starting point: shortcuts, a quick summary, and help getting started.', 'ner-michoel-core' ),
					'keywords' => 'dashboard start overview welcome',
					'help'     => array(
						'steps' => array(
							__( 'The big buttons under “What would you like to do?” are the most common jobs. Click one to go straight there.', 'ner-michoel-core' ),
							__( 'The menu on the left has everything else, grouped by topic. Click a group’s name to open it.', 'ner-michoel-core' ),
							__( 'Press Ctrl + K (⌘ + K on a Mac) on any page to find a page by typing its name.', 'ner-michoel-core' ),
							__( 'Every page has a Help button like this one, explaining what the page is for, step by step.', 'ner-michoel-core' ),
						),
						'tip'   => __( 'You can’t break anything by looking around. Changes only happen when you press Save, Post, or Delete — and Delete always asks first.', 'ner-michoel-core' ),
					),
				),
			),
		),
		'shiurim'       => array(
			'label' => __( 'Shiurim', 'ner-michoel-core' ),
			'icon'  => 'headphones',
			'tabs'  => array(
				'shiurim'    => array(
					'label'    => __( 'Audio & Video Shiurim', 'ner-michoel-core' ),
					'nav'      => __( 'Audio & Video', 'ner-michoel-core' ),
					'icon'     => 'headphones',
					'cms_type' => 'shiur',
					'desc'     => __( 'Every audio and video shiur on the site. Add new ones, fix details, or take one down.', 'ner-michoel-core' ),
					'keywords' => 'audio video lecture mp3 recording add edit',
					'help'     => array(
						'steps' => array(
							__( 'Click “Add New Shiur”. Give it a title, pick the speaker and series, then choose the audio or video file.', 'ner-michoel-core' ),
							__( 'To change a shiur, click its row. Make your changes and press Save.', 'ner-michoel-core' ),
							__( 'Use the search box, or the Speaker and Series filters, to find a shiur quickly.', 'ner-michoel-core' ),
							__( 'Set Status to Draft to hide a shiur from visitors without deleting it.', 'ner-michoel-core' ),
						),
						'tip'   => __( 'Adding lots of files? “Upload Many at Once” does a whole batch in a few clicks.', 'ner-michoel-core' ),
					),
				),
				'written'    => array(
					'label'    => __( 'Written Shiurim', 'ner-michoel-core' ),
					'nav'      => __( 'Written (PDF)', 'ner-michoel-core' ),
					'icon'     => 'file-text',
					'cms_type' => 'written_shiur',
					'desc'     => __( 'Shiurim published as PDF files that visitors can read or download.', 'ner-michoel-core' ),
					'keywords' => 'pdf written article document',
					'help'     => array(
						'steps' => array(
							__( 'Click “Add New Written Shiur” and type a title.', 'ner-michoel-core' ),
							__( 'Choose the PDF file. Its first line is filled into the Summary for you, and you can change it.', 'ner-michoel-core' ),
							__( 'Pick the speaker and series, set Status to Published, and press Save.', 'ner-michoel-core' ),
						),
						'tip'   => __( 'The Summary is the short line shown on the homepage card.', 'ner-michoel-core' ),
					),
				),
				'bulk'       => array(
					'label'    => __( 'Upload Many at Once', 'ner-michoel-core' ),
					'icon'     => 'upload',
					'callback' => 'ner_michoel_render_bulk_upload_page',
					'desc'     => __( 'Add a whole batch of audio or video files in one go, then give them all the same speaker and series.', 'ner-michoel-core' ),
					'keywords' => 'bulk batch multiple files upload many',
					'help'     => array(
						'steps' => array(
							__( 'Click “Choose Files” and pick (or drag in) all the files.', 'ner-michoel-core' ),
							__( 'Check the titles. They’re made from the file names, and you can change them.', 'ner-michoel-core' ),
							__( 'Click “Create Shiurim”. On the next screen choose the speaker and series for the whole batch, then press “Assign & Publish”.', 'ner-michoel-core' ),
						),
						'tip'   => __( 'The new shiurim stay hidden (as drafts) until that last step, so there’s no rush.', 'ner-michoel-core' ),
					),
				),
				'bulkassign' => array(
					'label'    => __( 'Finish Your Upload', 'ner-michoel-core' ),
					'icon'     => 'upload',
					'callback' => 'ner_michoel_render_bulk_assign_page',
					'hidden'   => true,
					'desc'     => __( 'Choose the speaker and series for the shiurim you just uploaded, then publish them.', 'ner-michoel-core' ),
					'help'     => array(
						'steps' => array(
							__( 'Pick the speaker and the series that every shiur in this batch belongs to.', 'ner-michoel-core' ),
							__( 'Press “Assign & Publish”. The shiurim go live on the site.', 'ner-michoel-core' ),
						),
						'tip'   => __( 'Need to fix one title? You can edit any shiur afterwards from Audio & Video.', 'ner-michoel-core' ),
					),
				),
				'speakers'   => array(
					'label'      => __( 'Speakers', 'ner-michoel-core' ),
					'icon'       => 'mic',
					'cms_type'   => 'speaker',
					'capability' => 'manage_categories',
					'desc'       => __( 'The rabbis and speakers whose shiurim are on the site, with their photos.', 'ner-michoel-core' ),
					'keywords'   => 'rabbi magid speaker photo bio',
					'help'       => array(
						'steps' => array(
							__( 'Click “Add New Speaker”, type the name, and add a photo.', 'ner-michoel-core' ),
							__( 'Add a forwarding email if visitors should be able to “Email a Magid Shiur”.', 'ner-michoel-core' ),
							__( 'Click any speaker to edit them. The number shows how many shiurim they have.', 'ner-michoel-core' ),
						),
						'tip'   => __( 'Deleting a speaker doesn’t delete their shiurim — those just won’t show a speaker any more.', 'ner-michoel-core' ),
					),
				),
				'series'     => array(
					'label'      => __( 'Series', 'ner-michoel-core' ),
					'icon'       => 'layers',
					'cms_type'   => 'series',
					'capability' => 'manage_categories',
					'desc'       => __( 'Groups of shiurim that belong together, like a course, a sefer, or a yearly cycle.', 'ner-michoel-core' ),
					'keywords'   => 'course sefer group collection',
					'help'       => array(
						'steps' => array(
							__( 'Click “Add New Series” and give it a name and a cover image.', 'ner-michoel-core' ),
							__( 'A series can sit inside a bigger one using “Parent Series”, which is handy for sub-sections.', 'ner-michoel-core' ),
							__( 'Shiurim are put into a series from the shiur’s own form (the Series box).', 'ner-michoel-core' ),
						),
						'tip'   => __( 'The order of shiurim inside a series comes from each shiur’s “Order” number.', 'ner-michoel-core' ),
					),
				),
				'topics'     => array(
					'label'      => __( 'Topics', 'ner-michoel-core' ),
					'icon'       => 'tag',
					'cms_type'   => 'topic',
					'capability' => 'manage_categories',
					'desc'       => __( 'Subject tags, like Shabbos or Pesach. Some can appear on the homepage at the right time of year.', 'ner-michoel-core' ),
					'keywords'   => 'tag subject season yom tov holiday',
					'help'       => array(
						'steps' => array(
							__( 'Click “Add New Topic” and name it.', 'ner-michoel-core' ),
							__( 'To feature it on the homepage, set “Show on the homepage” to Always, or to the Hebrew dates when it’s in season.', 'ner-michoel-core' ),
							__( 'Tag shiurim with topics from the shiur’s own form.', 'ner-michoel-core' ),
						),
					),
				),
			),
		),
		'announcements' => array(
			'label' => __( 'Announcements', 'ner-michoel-core' ),
			'icon'  => 'megaphone',
			'tabs'  => array(
				'mazaltov'    => array(
					'label'    => __( 'Mazal Tov', 'ner-michoel-core' ),
					'icon'     => 'sparkles',
					'cms_type' => 'mazal_tov',
					'desc'     => __( 'Simcha announcements: engagements, weddings, births, and more.', 'ner-michoel-core' ),
					'keywords' => 'simcha engagement wedding birth bar mitzvah',
					'help'     => array(
						'steps' => array(
							__( 'Click “Add New Mazal Tov” for an announcement with a photo.', 'ner-michoel-core' ),
							__( 'Or use “Quick Mazal Tov” for a fast, four-box version.', 'ner-michoel-core' ),
							__( 'Click any announcement to edit it, or set it to Draft to take it down.', 'ner-michoel-core' ),
						),
					),
				),
				'mazaltovadd' => array(
					'label'    => __( 'Quick Mazal Tov', 'ner-michoel-core' ),
					'icon'     => 'zap',
					'callback' => 'ner_michoel_render_mazal_tov_quick_add_page',
					'desc'     => __( 'Post a simcha announcement in four boxes. No photo needed.', 'ner-michoel-core' ),
					'keywords' => 'simcha quick post mazal tov fast',
					'help'     => array(
						'steps' => array(
							__( 'Type who it’s for in the Honoree box.', 'ner-michoel-core' ),
							__( 'Add the relationship and years if you like, for example “Rabbi & Mrs.” and “’05, ’08”.', 'ner-michoel-core' ),
							__( 'Pick the type of simcha and press “Post Mazal Tov”. It goes live straight away.', 'ner-michoel-core' ),
						),
					),
				),
				'newslist'    => array(
					'label'     => __( 'News & Events', 'ner-michoel-core' ),
					'icon'      => 'newspaper',
					'cms_type'  => 'post_news',
					'desc'      => __( 'News posts and event announcements shown on the News & Events page.', 'ner-michoel-core' ),
					'keywords'  => 'news event post article announcement',
					'help'      => array(
						'steps' => array(
							__( 'Click “Add New News Post” and write a title and the text.', 'ner-michoel-core' ),
							__( 'Add a featured image to make it stand out.', 'ner-michoel-core' ),
							__( 'Set Status to Published and press Save. It’s filed under News for you.', 'ner-michoel-core' ),
						),
						'tip'       => __( 'Need pictures inside the text, or headings and bold? Use the full editor instead.', 'ner-michoel-core' ),
						'tip_link'  => array(
							'tab'   => 'news',
							'label' => __( 'Open the full editor', 'ner-michoel-core' ),
						),
					),
				),
				'news'        => array(
					'label'    => __( 'Full News Editor', 'ner-michoel-core' ),
					'icon'     => 'newspaper',
					'callback' => 'ner_michoel_render_post_news_page',
					'hidden'   => true,
					'desc'     => __( 'Write a news post in WordPress’s full editor, with pictures and formatting.', 'ner-michoel-core' ),
					'help'     => array(
						'steps' => array(
							__( 'Press the button below. A new post is started, already filed under News.', 'ner-michoel-core' ),
							__( 'WordPress’s editor opens. Write the post, then press Publish.', 'ner-michoel-core' ),
						),
						'tip'   => __( 'For a simple post, “News & Events” → “Add New News Post” is quicker and stays in this panel.', 'ner-michoel-core' ),
					),
				),
				'galleries'   => array(
					'label'    => __( 'Photo & Video Galleries', 'ner-michoel-core' ),
					'nav'      => __( 'Galleries', 'ner-michoel-core' ),
					'icon'     => 'image',
					'cms_type' => 'gallery',
					'desc'     => __( 'Photo albums and video collections from events.', 'ner-michoel-core' ),
					'keywords' => 'photos pictures album video event gallery',
					'help'     => array(
						'steps' => array(
							__( 'Click “Add New Gallery” and give it a title.', 'ner-michoel-core' ),
							__( 'Choose the Gallery Type: photos or video.', 'ner-michoel-core' ),
							__( 'For photos, click “Add Images” and pick several at once, then drag them into order. For videos, paste one YouTube or Vimeo link per line.', 'ner-michoel-core' ),
							__( 'Pick a cover image, set Status to Published, and press Save.', 'ner-michoel-core' ),
						),
					),
				),
			),
		),
		'inbox'         => array(
			'label' => __( 'Messages', 'ner-michoel-core' ),
			'icon'  => 'inbox',
			'tabs'  => array(
				'inquiries' => array(
					'label'    => __( 'Messages', 'ner-michoel-core' ),
					'icon'     => 'inbox',
					'cms_type' => 'nm_submission',
					'desc'     => __( 'Messages people sent through the Contact and “Email a Magid Shiur” forms.', 'ner-michoel-core' ),
					'keywords' => 'contact form submissions inquiries email inbox',
					'help'     => array(
						'steps' => array(
							__( 'Click a message to read all of it.', 'ner-michoel-core' ),
							__( 'These are a backup copy. The emails are still sent as usual.', 'ner-michoel-core' ),
							__( 'Delete messages you no longer need.', 'ner-michoel-core' ),
						),
						'tip'   => __( 'If an email never arrived, you’ll still find the message here.', 'ner-michoel-core' ),
					),
				),
			),
		),
		'website'       => array(
			'label' => __( 'Homepage & Look', 'ner-michoel-core' ),
			'icon'  => 'palette',
			'tabs'  => array(
				'hero'   => array(
					'label'         => __( 'Homepage Banner', 'ner-michoel-core' ),
					'icon'          => 'banner',
					'settings_type' => 'hero_slider',
					'desc'          => __( 'The big rotating pictures at the top of the homepage.', 'ner-michoel-core' ),
					'keywords'      => 'hero slider slides banner homepage pictures carousel',
					'help'          => array(
						'steps' => array(
							__( 'Click “Add Slide”, choose a picture, and add a heading and a short line of text.', 'ner-michoel-core' ),
							__( 'Want a button on the slide? Fill in the button text and link. You can search for a page by name.', 'ner-michoel-core' ),
							__( 'Drag a slide by its handle to change the order, then press Save.', 'ner-michoel-core' ),
						),
						'tip'   => __( 'Wide (landscape) pictures look best.', 'ner-michoel-core' ),
					),
				),
				'live'   => array(
					'label'         => __( 'Live Shiur / Zoom', 'ner-michoel-core' ),
					'icon'          => 'video',
					'settings_type' => 'live_shiur',
					'desc'          => __( 'The Zoom link and schedule for live shiurim, shown on the homepage and on News & Events.', 'ner-michoel-core' ),
					'keywords'      => 'zoom live meeting link schedule stream',
					'help'          => array(
						'steps' => array(
							__( 'Paste the Zoom link and the Meeting ID.', 'ner-michoel-core' ),
							__( 'Type the schedule in plain words, like “Sundays 8:00 PM ET”.', 'ner-michoel-core' ),
							__( 'Press Save. Clear the boxes and save to hide the Live Shiur banner.', 'ner-michoel-core' ),
						),
					),
				),
				'colors' => array(
					'label'         => __( 'Colors & Font', 'ner-michoel-core' ),
					'icon'          => 'palette',
					'settings_type' => 'appearance',
					'capability'    => 'manage_options',
					'desc'          => __( 'The colors and font of the Shiurim pages and the audio player.', 'ner-michoel-core' ),
					'keywords'      => 'colour color theme font appearance design',
					'help'          => array(
						'steps' => array(
							__( 'Click one of the ready-made color sets to try it. The preview updates straight away.', 'ner-michoel-core' ),
							__( 'Or pick each color yourself.', 'ner-michoel-core' ),
							__( 'Press Save to put it on the site.', 'ner-michoel-core' ),
						),
						'tip'   => __( 'Nothing changes on the site until you press Save.', 'ner-michoel-core' ),
					),
				),
				'menu'   => array(
					'label'         => __( 'Menu', 'ner-michoel-core' ),
					'icon'          => 'list',
					'settings_type' => 'navigation',
					'capability'    => 'manage_options',
					'desc'          => __( 'Choose what appears in the site’s top menu.', 'ner-michoel-core' ),
					'keywords'      => 'menu navigation header links',
					'help'          => array(
						'steps' => array(
							__( 'Tick or untick the option, then press Save.', 'ner-michoel-core' ),
						),
					),
				),
				'layout' => array(
					'label'         => __( 'Layout Switch', 'ner-michoel-core' ),
					'icon'          => 'toggle',
					'settings_type' => 'layout_toggle',
					'desc'          => __( 'The small button visitors use to switch between page layouts (Modern, Classic, and so on).', 'ner-michoel-core' ),
					'keywords'      => 'layout toggle modern classic 24six studio switch',
					'help'          => array(
						'steps' => array(
							__( 'Choose where the switch sits on the screen.', 'ner-michoel-core' ),
							__( 'Set how dark its background is.', 'ner-michoel-core' ),
							__( 'Press Save.', 'ner-michoel-core' ),
						),
					),
				),
			),
		),
		'reports'       => array(
			'label' => __( 'Reports', 'ner-michoel-core' ),
			'icon'  => 'chart',
			'tabs'  => array(
				'stats'    => array(
					'label'      => __( 'Site Visitors', 'ner-michoel-core' ),
					'icon'       => 'chart',
					'callback'   => 'ner_michoel_render_site_stats_page',
					'capability' => 'manage_options',
					'desc'       => __( 'How many people visit the site, what they read, and where they came from.', 'ner-michoel-core' ),
					'keywords'   => 'statistics stats analytics traffic visitors pageviews',
					'help'       => array(
						'steps' => array(
							__( 'The coloured boxes at the top cover the last 30 days.', 'ner-michoel-core' ),
							__( 'The chart shows each of the last 14 days.', 'ner-michoel-core' ),
							__( 'Top Pages and Top Referrers show what’s popular and which sites send visitors your way.', 'ner-michoel-core' ),
						),
						'tip'   => __( 'Counted privately on this site. No outside tracking service is used.', 'ner-michoel-core' ),
					),
				),
				'listened' => array(
					'label'    => __( 'Most Listened', 'ner-michoel-core' ),
					'icon'     => 'trending',
					'cms_type' => 'shiur',
					'cms_args' => array( 'orderby' => 'plays' ),
					'desc'     => __( 'Shiurim ranked by how many times they’ve been played.', 'ner-michoel-core' ),
					'keywords' => 'popular plays top listened',
					'help'     => array(
						'steps' => array(
							__( 'The most-played shiur is at the top.', 'ner-michoel-core' ),
							__( 'Click a shiur to see or edit its details.', 'ner-michoel-core' ),
						),
					),
				),
				'missing'  => array(
					'label'    => __( 'Missing Audio', 'ner-michoel-core' ),
					'icon'     => 'alert',
					'cms_type' => 'shiur',
					'cms_args' => array( 'missing_audio' => 1 ),
					'desc'     => __( 'Shiurim that don’t have an audio or video file yet.', 'ner-michoel-core' ),
					'keywords' => 'missing audio no file broken empty',
					'help'     => array(
						'steps' => array(
							__( 'Click a shiur, then “Choose File” to attach its audio or video.', 'ner-michoel-core' ),
							__( 'Press Save. Once it has a file, it drops off this list.', 'ner-michoel-core' ),
						),
						'tip'   => __( 'An empty list here is good news!', 'ner-michoel-core' ),
					),
				),
			),
		),
		'advanced'      => array(
			'label'    => __( 'Advanced', 'ner-michoel-core' ),
			'icon'     => 'settings',
			'advanced' => true,
			'tabs'     => array(
				'security' => array(
					'label'         => __( 'Security', 'ner-michoel-core' ),
					'icon'          => 'shield',
					'settings_type' => 'security',
					'capability'    => 'manage_options',
					'desc'          => __( 'Protection for the site’s forms and logins against bots and spam.', 'ner-michoel-core' ),
					'keywords'      => 'spam bots turnstile cloudflare xmlrpc protection',
					'help'          => array(
						'steps' => array(
							__( 'The box at the top shows what’s switched on and what’s been blocked.', 'ner-michoel-core' ),
							__( 'The two recommended switches should normally stay ticked.', 'ner-michoel-core' ),
							__( 'Cloudflare Turnstile is optional. Only add keys if you’ve set it up in Cloudflare.', 'ner-michoel-core' ),
						),
					),
				),
				'login'    => array(
					'label'         => __( 'Login Shortcut', 'ner-michoel-core' ),
					'icon'          => 'key',
					'settings_type' => 'admin_login',
					'capability'    => 'manage_options',
					'desc'          => __( 'Let people open this control panel with one shared password instead of a full WordPress login.', 'ner-michoel-core' ),
					'keywords'      => 'password login shortcut access',
					'help'          => array(
						'steps' => array(
							__( 'Type a new password twice and choose which account it logs in as.', 'ner-michoel-core' ),
							__( 'Press Save. Anyone with the password can now open this panel.', 'ner-michoel-core' ),
							__( 'To switch the shortcut off, tick “Turn off the shortcut password” and press Save.', 'ner-michoel-core' ),
						),
						'tip'   => __( 'Leaving the password boxes empty keeps the current password.', 'ner-michoel-core' ),
					),
				),
				'storage'  => array(
					'label'         => __( 'Storage (Bunny)', 'ner-michoel-core' ),
					'icon'          => 'cloud',
					'settings_type' => 'storage',
					'capability'    => 'manage_options',
					'desc'          => __( 'Keep new uploads on Bunny’s fast file storage instead of this server.', 'ner-michoel-core' ),
					'keywords'      => 'bunny cdn storage offload files',
					'help'          => array(
						'steps' => array(
							__( 'Fill in the details from your bunny.net account.', 'ner-michoel-core' ),
							__( 'Tick “Offload new uploads” and press Save.', 'ner-michoel-core' ),
						),
						'tip'   => __( 'Only change this if you have the Bunny account details. If you’re not sure, ask whoever set up the site.', 'ner-michoel-core' ),
					),
				),
				'sample'   => array(
					'label'      => __( 'Sample Content', 'ner-michoel-core' ),
					'icon'       => 'download',
					'callback'   => 'ner_michoel_render_sample_content_page',
					'capability' => 'manage_options',
					'desc'       => __( 'One-time: fill a brand-new site with a few real shiurim so it isn’t empty.', 'ner-michoel-core' ),
					'keywords'   => 'import sample demo seed',
					'help'       => array(
						'steps' => array(
							__( 'Look over what will be added, then press “Import Sample Content Now”.', 'ner-michoel-core' ),
						),
						'tip'   => __( 'Safe to run twice. Anything already there is skipped, not duplicated.', 'ner-michoel-core' ),
					),
				),
				'library'  => array(
					'label'      => __( 'Library Import', 'ner-michoel-core' ),
					'icon'       => 'database',
					'callback'   => 'ner_michoel_render_library_import_page',
					'capability' => 'manage_options',
					'desc'       => __( 'Bring the whole nermichoel.org archive into this site, in the background.', 'ner-michoel-core' ),
					'keywords'   => 'import archive migration library nermichoel',
					'help'       => array(
						'steps' => array(
							__( 'Press “Start / Resume”. It works through the archive bit by bit, over hours or days.', 'ner-michoel-core' ),
							__( 'You can press Pause at any time and come back later.', 'ner-michoel-core' ),
						),
						'tip'   => __( 'Check a few imported shiurim early on to make sure they look right.', 'ner-michoel-core' ),
					),
				),
			),
		),
	);
}

/**
 * Old ?cat= values (before the sidebar) and the page each one opened.
 */
function ner_michoel_custom_admin_legacy_cats() {
	return array(
		'overview'   => 'home',
		'content'    => 'shiurim',
		'appearance' => 'colors',
		'site'       => 'live',
		'data'       => 'sample',
		'analytics'  => 'stats',
	);
}

/**
 * The panel URL for a page. 'home' (or empty) is plain /admin.
 */
function ner_michoel_custom_admin_url( $tab = '', $args = array() ) {
	$url = home_url( '/admin' );
	if ( '' !== $tab && 'home' !== $tab ) {
		$args = array_merge( array( 'tab' => $tab ), $args );
	}
	return $args ? add_query_arg( $args, $url ) : $url;
}

function ner_michoel_custom_admin_tab_cap( $tab_data ) {
	return isset( $tab_data['capability'] ) ? $tab_data['capability'] : 'edit_posts';
}

/**
 * Which page this request is for: array( group key, tab key ).
 */
function ner_michoel_custom_admin_resolve( $structure ) {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
	$cat = isset( $_GET['cat'] ) ? sanitize_key( wp_unslash( $_GET['cat'] ) ) : '';
	// phpcs:enable

	if ( '' !== $tab ) {
		foreach ( $structure as $group_key => $group ) {
			if ( isset( $group['tabs'][ $tab ] ) ) {
				return array( $group_key, $tab );
			}
		}
	}

	$legacy = ner_michoel_custom_admin_legacy_cats();
	if ( '' !== $cat ) {
		if ( isset( $legacy[ $cat ] ) ) {
			return ner_michoel_custom_admin_resolve_tab( $structure, $legacy[ $cat ] );
		}
		if ( isset( $structure[ $cat ] ) ) {
			$keys = array_keys( $structure[ $cat ]['tabs'] );
			return array( $cat, $keys[0] );
		}
	}

	return array( 'home', 'home' );
}

function ner_michoel_custom_admin_resolve_tab( $structure, $tab ) {
	foreach ( $structure as $group_key => $group ) {
		if ( isset( $group['tabs'][ $tab ] ) ) {
			return array( $group_key, $tab );
		}
	}
	return array( 'home', 'home' );
}

/* ------------------------------------------------------------------ */
/* Forms that post from inside the panel                               */
/* ------------------------------------------------------------------ */

/**
 * True while the panel is rendering a page. The inline screens (Quick
 * Mazal Tov, Upload Many at Once, the imports) use it to send their
 * forms back to the panel instead of to the matching wp-admin screen.
 */
function ner_michoel_is_custom_admin_render( $set = null ) {
	static $rendering = false;
	if ( null !== $set ) {
		$rendering = (bool) $set;
	}
	return $rendering;
}

/**
 * Hidden field for a form inside the panel: its handler then returns to
 * the panel (see ner_michoel_panel_redirect_url()).
 */
function ner_michoel_panel_form_field() {
	if ( ner_michoel_is_custom_admin_render() ) {
		echo '<input type="hidden" name="nm_from_panel" value="1" />';
	}
}

/**
 * Where a form handler sends the admin next: the panel page $tab when the
 * form came from the panel, otherwise the wp-admin screen $fallback. $args
 * are added to either.
 */
function ner_michoel_panel_redirect_url( $fallback, $tab, $args = array() ) {
	if ( empty( $_REQUEST['nm_from_panel'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks the redirect target; each handler checks its own nonce.
		return $args ? add_query_arg( $args, $fallback ) : $fallback;
	}
	return ner_michoel_custom_admin_url( $tab, $args );
}

/* ------------------------------------------------------------------ */
/* Assets                                                              */
/* ------------------------------------------------------------------ */

/**
 * Keeps the theme's front-end CSS/JS (Astra and the child theme) and the
 * WordPress toolbar off the panel. Runs last on wp_enqueue_scripts.
 */
function ner_michoel_custom_admin_isolate_assets() {
	$themes_path = untrailingslashit( (string) wp_parse_url( get_theme_root_uri(), PHP_URL_PATH ) ) . '/';
	foreach ( array( wp_styles(), wp_scripts() ) as $deps ) {
		foreach ( (array) $deps->queue as $handle ) {
			$src = isset( $deps->registered[ $handle ] ) ? (string) $deps->registered[ $handle ]->src : '';
			if ( '' !== $src && '/' !== $themes_path && false !== strpos( $src, $themes_path ) ) {
				$deps->dequeue( $handle );
			}
		}
	}
	wp_dequeue_style( 'admin-bar' );
	wp_dequeue_script( 'admin-bar' );
	wp_dequeue_script( 'ner-michoel-form-guard' ); // public forms only
}

/**
 * Loads the CSS/JS this page's content might need. Broad rather than
 * per-tab-precise on purpose — this is one page load, not a front-end
 * template, and an exact per-tab list isn't worth the fragility.
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
	wp_enqueue_media();

	// The shell: layout, help drawer, tour, search, toasts and dialogs.
	// Loaded after the wp-admin and content stylesheets so its rules win.
	wp_enqueue_style( 'ner-michoel-admin-cms', NER_MICHOEL_CORE_URL . 'assets/custom-admin-cms.css', array(), NER_MICHOEL_CORE_VERSION );
	wp_enqueue_style( 'ner-michoel-series-picker', NER_MICHOEL_CORE_URL . 'assets/series-picker.css', array(), NER_MICHOEL_CORE_VERSION );
	wp_enqueue_style( 'ner-michoel-admin-shell', NER_MICHOEL_CORE_URL . 'assets/custom-admin-shell.css', array( 'ner-michoel-admin-cms', 'ner-michoel-series-picker' ), NER_MICHOEL_CORE_VERSION );
	wp_enqueue_script( 'ner-michoel-admin-shell', NER_MICHOEL_CORE_URL . 'assets/custom-admin-shell.js', array( 'jquery' ), NER_MICHOEL_CORE_VERSION, true );

	// Our own content screens (list + edit) — REST API underneath
	// (includes/custom-admin-api.php), not wp-admin's own screens.
	wp_enqueue_script( 'ner-michoel-series-picker', NER_MICHOEL_CORE_URL . 'assets/series-picker.js', array(), NER_MICHOEL_CORE_VERSION, true );
	wp_enqueue_script( 'ner-michoel-pdf-first-line', NER_MICHOEL_CORE_URL . 'assets/pdf-first-line.js', array(), NER_MICHOEL_CORE_VERSION, true );
	wp_enqueue_script( 'ner-michoel-admin-cms', NER_MICHOEL_CORE_URL . 'assets/custom-admin-cms.js', array( 'jquery', 'jquery-ui-sortable', 'ner-michoel-series-picker', 'ner-michoel-admin-shell' ), NER_MICHOEL_CORE_VERSION, true );
	wp_localize_script(
		'ner-michoel-admin-cms',
		'nmCmsConfig',
		array(
			'restUrl' => esc_url_raw( rest_url( 'ner-michoel/v1' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
		)
	);

	// Our own Settings screens — see includes/custom-admin-settings-api.php.
	wp_enqueue_script( 'ner-michoel-admin-settings', NER_MICHOEL_CORE_URL . 'assets/custom-admin-settings.js', array( 'jquery', 'jquery-ui-sortable', 'ner-michoel-admin-shell' ), NER_MICHOEL_CORE_VERSION, true );
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

/* ------------------------------------------------------------------ */
/* Numbers for Home and the nav                                        */
/* ------------------------------------------------------------------ */

function ner_michoel_custom_admin_term_total( $taxonomy ) {
	if ( ! taxonomy_exists( $taxonomy ) ) {
		return 0;
	}
	$count = wp_count_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
	return is_wp_error( $count ) ? 0 : (int) $count;
}

/**
 * Messages (form submissions) from the last 7 days. Shown as the
 * Messages badge and on Home, so it's worked out once per page load.
 */
function ner_michoel_custom_admin_messages_this_week() {
	static $count = null;
	if ( null === $count ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'nm_submission',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'date_query'     => array( array( 'after' => '7 days ago' ) ),
			)
		);
		$count = (int) $query->found_posts;
	}
	return $count;
}

/**
 * Shiurim with no audio/video file — the same list as Reports > Missing
 * Audio. Kept for 10 minutes, since it scans the shiur meta.
 */
function ner_michoel_custom_admin_missing_audio_count() {
	$cached = get_transient( 'nm_admin_missing_audio_count' );
	if ( false !== $cached ) {
		return (int) $cached;
	}
	$count = 0;
	if ( function_exists( 'ner_michoel_missing_audio_meta_query' ) ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'shiur',
				'post_status'    => array( 'publish', 'draft', 'pending', 'future' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => ner_michoel_missing_audio_meta_query(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);
		$count = (int) $query->found_posts;
	}
	set_transient( 'nm_admin_missing_audio_count', $count, 10 * MINUTE_IN_SECONDS );
	return $count;
}

/**
 * Success/error messages a form handler left in the URL, shown as a toast.
 */
function ner_michoel_custom_admin_flash() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$bulk = isset( $_GET['nm_bulk'] ) ? sanitize_key( wp_unslash( $_GET['nm_bulk'] ) ) : '';
	// phpcs:enable
	if ( 'done' === $bulk ) {
		return array( 'type' => 'success', 'message' => __( 'Done! Your new shiurim are published.', 'ner-michoel-core' ) );
	}
	if ( 'empty' === $bulk ) {
		return array( 'type' => 'error', 'message' => __( 'No files were chosen, so nothing was created.', 'ner-michoel-core' ) );
	}
	return null;
}

/* ------------------------------------------------------------------ */
/* Home                                                                */
/* ------------------------------------------------------------------ */

function ner_michoel_render_custom_admin_overview() {
	$structure = ner_michoel_custom_admin_structure();
	$can_tab   = function ( $tab ) use ( $structure ) {
		list( $group, $key ) = ner_michoel_custom_admin_resolve_tab( $structure, $tab );
		return $key === $tab && current_user_can( ner_michoel_custom_admin_tab_cap( $structure[ $group ]['tabs'][ $key ] ) );
	};

	$user       = wp_get_current_user();
	$first_name = $user->first_name ? $user->first_name : $user->display_name;

	$shiur_counts   = wp_count_posts( 'shiur' );
	$written_counts = wp_count_posts( 'written_shiur' );
	$shiurim        = isset( $shiur_counts->publish ) ? (int) $shiur_counts->publish : 0;
	$drafts         = isset( $shiur_counts->draft ) ? (int) $shiur_counts->draft : 0;
	$written        = isset( $written_counts->publish ) ? (int) $written_counts->publish : 0;
	$speakers       = ner_michoel_custom_admin_term_total( 'speaker' );
	$series         = ner_michoel_custom_admin_term_total( 'series' );
	$messages       = ner_michoel_custom_admin_messages_this_week();
	$missing        = ner_michoel_custom_admin_missing_audio_count();
	$slides         = function_exists( 'ner_michoel_get_homepage_slider' ) ? count( (array) ner_michoel_get_homepage_slider() ) : 0;
	$live           = function_exists( 'ner_michoel_get_live_shiur' ) ? ner_michoel_get_live_shiur() : array();
	$live_set       = ! empty( $live['zoom_link'] ) || ! empty( $live['schedule'] );

	$actions = array(
		array( 'tab' => 'shiurim', 'args' => array( 'new' => 1 ), 'icon' => 'headphones', 'title' => __( 'Add a shiur', 'ner-michoel-core' ), 'desc' => __( 'One audio or video shiur', 'ner-michoel-core' ), 'tone' => 'green' ),
		array( 'tab' => 'bulk', 'args' => array(), 'icon' => 'upload', 'title' => __( 'Upload many shiurim', 'ner-michoel-core' ), 'desc' => __( 'A whole batch at once', 'ner-michoel-core' ), 'tone' => 'blue' ),
		array( 'tab' => 'written', 'args' => array( 'new' => 1 ), 'icon' => 'file-text', 'title' => __( 'Add a written shiur', 'ner-michoel-core' ), 'desc' => __( 'Publish a PDF', 'ner-michoel-core' ), 'tone' => 'violet' ),
		array( 'tab' => 'mazaltovadd', 'args' => array(), 'icon' => 'sparkles', 'title' => __( 'Post a Mazal Tov', 'ner-michoel-core' ), 'desc' => __( 'Share a simcha in four boxes', 'ner-michoel-core' ), 'tone' => 'amber' ),
		array( 'tab' => 'newslist', 'args' => array( 'new' => 1 ), 'icon' => 'newspaper', 'title' => __( 'Post news', 'ner-michoel-core' ), 'desc' => __( 'For the News & Events page', 'ner-michoel-core' ), 'tone' => 'rose' ),
		array( 'tab' => 'hero', 'args' => array(), 'icon' => 'banner', 'title' => __( 'Change the homepage banner', 'ner-michoel-core' ), 'desc' => __( 'The big pictures on top', 'ner-michoel-core' ), 'tone' => 'teal' ),
		array( 'tab' => 'live', 'args' => array(), 'icon' => 'video', 'title' => __( 'Update the Zoom link', 'ner-michoel-core' ), 'desc' => __( 'Live shiur link and times', 'ner-michoel-core' ), 'tone' => 'blue' ),
		array( 'tab' => 'inquiries', 'args' => array(), 'icon' => 'inbox', 'title' => __( 'Read messages', 'ner-michoel-core' ), 'desc' => __( 'From the contact forms', 'ner-michoel-core' ), 'tone' => 'slate' ),
	);

	$stats = array(
		array( 'tab' => 'shiurim', 'icon' => 'headphones', 'label' => __( 'Shiurim', 'ner-michoel-core' ), 'value' => $shiurim, 'sub' => $drafts ? sprintf( /* translators: %s: number of drafts */ _n( '+ %s draft', '+ %s drafts', $drafts, 'ner-michoel-core' ), number_format_i18n( $drafts ) ) : __( 'published', 'ner-michoel-core' ) ),
		array( 'tab' => 'written', 'icon' => 'file-text', 'label' => __( 'Written shiurim', 'ner-michoel-core' ), 'value' => $written, 'sub' => __( 'published', 'ner-michoel-core' ) ),
		array( 'tab' => 'speakers', 'icon' => 'mic', 'label' => __( 'Speakers', 'ner-michoel-core' ), 'value' => $speakers, 'sub' => '' ),
		array( 'tab' => 'series', 'icon' => 'layers', 'label' => __( 'Series', 'ner-michoel-core' ), 'value' => $series, 'sub' => '' ),
		array( 'tab' => 'inquiries', 'icon' => 'inbox', 'label' => __( 'New messages', 'ner-michoel-core' ), 'value' => $messages, 'sub' => __( 'in the last 7 days', 'ner-michoel-core' ) ),
		array( 'tab' => 'missing', 'icon' => $missing ? 'warning' : 'check-circle', 'label' => __( 'Missing audio', 'ner-michoel-core' ), 'value' => $missing, 'sub' => $missing ? __( 'need a file — click to fix', 'ner-michoel-core' ) : __( 'all shiurim have a file', 'ner-michoel-core' ), 'tone' => $missing ? 'warn' : 'ok' ),
	);

	// Getting started: 'auto' items are done when the site already has the
	// thing; 'tour' and 'search' are ticked in the browser (shell JS).
	$checklist = array(
		array( 'key' => 'tour', 'title' => __( 'Take the 1-minute tour', 'ner-michoel-core' ), 'desc' => __( 'A quick look at where everything is.', 'ner-michoel-core' ), 'done' => null, 'tour' => true ),
		array( 'key' => 'speaker', 'tab' => 'speakers', 'args' => array( 'new' => 1 ), 'title' => __( 'Add a speaker', 'ner-michoel-core' ), 'desc' => __( 'Shiurim are listed under their speaker.', 'ner-michoel-core' ), 'done' => $speakers > 0 ),
		array( 'key' => 'shiur', 'tab' => 'shiurim', 'args' => array( 'new' => 1 ), 'title' => __( 'Add your first shiur', 'ner-michoel-core' ), 'desc' => __( 'Title, speaker, series, and the audio file.', 'ner-michoel-core' ), 'done' => ( $shiurim + $drafts ) > 0 ),
		array( 'key' => 'banner', 'tab' => 'hero', 'args' => array(), 'title' => __( 'Put a picture on the homepage banner', 'ner-michoel-core' ), 'desc' => __( 'The first thing visitors see.', 'ner-michoel-core' ), 'done' => $slides > 0 ),
		array( 'key' => 'live', 'tab' => 'live', 'args' => array(), 'title' => __( 'Add the Live Shiur / Zoom link', 'ner-michoel-core' ), 'desc' => __( 'So people can join live.', 'ner-michoel-core' ), 'done' => $live_set ),
		array( 'key' => 'search', 'title' => __( 'Try the quick search', 'ner-michoel-core' ), 'desc' => __( 'Press Ctrl + K and type what you’re looking for.', 'ner-michoel-core' ), 'done' => null, 'search' => true ),
	);
	$checklist = array_values(
		array_filter(
			$checklist,
			function ( $item ) use ( $can_tab ) {
				return empty( $item['tab'] ) || $can_tab( $item['tab'] );
			}
		)
	);

	$recent_shiurim = $can_tab( 'shiurim' ) ? get_posts(
		array(
			'post_type'   => 'shiur',
			'numberposts' => 5,
			'post_status' => array( 'publish', 'draft', 'pending', 'future' ),
			'orderby'     => 'date',
			'order'       => 'DESC',
		)
	) : array();

	$recent_messages = $can_tab( 'inquiries' ) ? get_posts(
		array(
			'post_type'   => 'nm_submission',
			'numberposts' => 3,
			'post_status' => 'publish',
		)
	) : array();

	$status_labels = array(
		'publish' => __( 'Published', 'ner-michoel-core' ),
		'draft'   => __( 'Draft', 'ner-michoel-core' ),
		'pending' => __( 'Waiting for review', 'ner-michoel-core' ),
		'future'  => __( 'Scheduled', 'ner-michoel-core' ),
	);

	$tips = array(
		__( 'Press Ctrl + K (⌘ + K on a Mac) to jump to any page by typing its name.', 'ner-michoel-core' ),
		__( 'Drafts are hidden from visitors, which is perfect for getting things ready ahead of time.', 'ner-michoel-core' ),
		__( 'Every page has a Help button at the top right that explains it step by step.', 'ner-michoel-core' ),
		__( 'Gallery photos and banner slides can be dragged into a new order.', 'ner-michoel-core' ),
		__( 'Uploading lots of shiurim? “Upload Many at Once” saves a lot of clicks.', 'ner-michoel-core' ),
		__( 'Click “View site” at the top to see your changes the way visitors do.', 'ner-michoel-core' ),
	);
	$tip = $tips[ array_rand( $tips ) ];
	?>
	<section class="nm-hero" aria-labelledby="nm-hero-title">
		<div class="nm-hero__text">
			<p class="nm-hero__eyebrow" data-nm-date></p>
			<h1 id="nm-hero-title" class="nm-hero__title" data-nm-greeting data-name="<?php echo esc_attr( $first_name ); ?>">
				<?php
				/* translators: %s: the admin's first name */
				echo esc_html( sprintf( __( 'Welcome back, %s', 'ner-michoel-core' ), $first_name ) );
				?>
			</h1>
			<p class="nm-hero__sub"><?php esc_html_e( 'What would you like to do today? Pick a shortcut below, or use the menu on the left.', 'ner-michoel-core' ); ?></p>
		</div>
		<div class="nm-hero__actions">
			<button type="button" class="nm-btn nm-btn--primary" data-tour-start><?php echo ner_michoel_admin_icon( 'compass' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php esc_html_e( 'Show me around', 'ner-michoel-core' ); ?></span></button>
			<button type="button" class="nm-btn nm-btn--ghost" data-palette-open><?php echo ner_michoel_admin_icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php esc_html_e( 'Find a page', 'ner-michoel-core' ); ?></span></button>
		</div>
	</section>

	<section class="nm-section" data-tour="quick" aria-labelledby="nm-quick-title">
		<h2 class="nm-section__title" id="nm-quick-title"><?php esc_html_e( 'What would you like to do?', 'ner-michoel-core' ); ?></h2>
		<div class="nm-quick-grid">
			<?php foreach ( $actions as $action ) : ?>
				<?php
				if ( ! $can_tab( $action['tab'] ) ) {
					continue;
				}
				?>
				<a class="nm-quick nm-tone--<?php echo esc_attr( $action['tone'] ); ?>" href="<?php echo esc_url( ner_michoel_custom_admin_url( $action['tab'], $action['args'] ) ); ?>">
					<span class="nm-quick__icon"><?php echo ner_michoel_admin_icon( $action['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<span class="nm-quick__text">
						<strong><?php echo esc_html( $action['title'] ); ?></strong>
						<span><?php echo esc_html( $action['desc'] ); ?></span>
					</span>
					<span class="nm-quick__arrow"><?php echo ner_michoel_admin_icon( 'arrow-r' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				</a>
			<?php endforeach; ?>
		</div>
	</section>

	<section class="nm-section" aria-labelledby="nm-glance-title">
		<h2 class="nm-section__title" id="nm-glance-title"><?php esc_html_e( 'At a glance', 'ner-michoel-core' ); ?></h2>
		<div class="nm-stat-grid">
			<?php foreach ( $stats as $stat ) : ?>
				<?php
				$linked = $can_tab( $stat['tab'] );
				$tag    = $linked ? 'a' : 'div';
				$tone   = isset( $stat['tone'] ) ? ' nm-stat--' . $stat['tone'] : '';
				?>
				<<?php echo $tag; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 'a' or 'div' ?> class="nm-stat<?php echo esc_attr( $tone ); ?>"<?php echo $linked ? ' href="' . esc_url( ner_michoel_custom_admin_url( $stat['tab'] ) ) . '"' : ''; ?>>
					<span class="nm-stat__icon"><?php echo ner_michoel_admin_icon( $stat['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<span class="nm-stat__value"><?php echo esc_html( number_format_i18n( $stat['value'] ) ); ?></span>
					<span class="nm-stat__label"><?php echo esc_html( $stat['label'] ); ?></span>
					<?php if ( $stat['sub'] ) : ?>
						<span class="nm-stat__sub"><?php echo esc_html( $stat['sub'] ); ?></span>
					<?php endif; ?>
				</<?php echo $tag; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<?php endforeach; ?>
		</div>
	</section>

	<div class="nm-home-cols">
		<section class="nm-card nm-checklist" data-checklist aria-labelledby="nm-checklist-title">
			<div class="nm-card__head">
				<div>
					<h2 class="nm-card__title" id="nm-checklist-title"><?php echo ner_michoel_admin_icon( 'compass' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Getting started', 'ner-michoel-core' ); ?></h2>
					<p class="nm-card__sub" data-checklist-summary><?php esc_html_e( 'A few small steps to find your way around.', 'ner-michoel-core' ); ?></p>
				</div>
				<button type="button" class="nm-icon-btn" data-checklist-hide title="<?php esc_attr_e( 'Hide this list', 'ner-michoel-core' ); ?>" aria-label="<?php esc_attr_e( 'Hide this list', 'ner-michoel-core' ); ?>"><?php echo ner_michoel_admin_icon( 'x' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
			</div>
			<div class="nm-progress" aria-hidden="true"><span class="nm-progress__bar" data-checklist-bar></span></div>
			<ol class="nm-checklist__list">
				<?php foreach ( $checklist as $item ) : ?>
					<?php
					$done = true === $item['done'];
					if ( ! empty( $item['tour'] ) ) {
						$href  = '#';
						$attrs = ' data-tour-start';
					} elseif ( ! empty( $item['search'] ) ) {
						$href  = '#';
						$attrs = ' data-palette-open';
					} else {
						$href  = ner_michoel_custom_admin_url( $item['tab'], $item['args'] );
						$attrs = '';
					}
					?>
					<li class="nm-checklist__item<?php echo $done ? ' is-done' : ''; ?>" data-check="<?php echo esc_attr( $item['key'] ); ?>"<?php echo null === $item['done'] ? ' data-check-local' : ''; ?>>
						<span class="nm-checklist__tick" aria-hidden="true"><?php echo ner_michoel_admin_icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<a class="nm-checklist__link" href="<?php echo esc_url( $href ); ?>"<?php echo $attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute names ?>>
							<strong><?php echo esc_html( $item['title'] ); ?></strong>
							<span><?php echo esc_html( $item['desc'] ); ?></span>
						</a>
						<span class="nm-checklist__state"><?php echo $done ? esc_html__( 'Done', 'ner-michoel-core' ) : ner_michoel_admin_icon( 'chevron-r' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					</li>
				<?php endforeach; ?>
			</ol>
			<div class="nm-checklist__done" data-checklist-complete hidden>
				<?php echo ner_michoel_admin_icon( 'sparkles' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<div>
					<strong><?php esc_html_e( 'You’re all set!', 'ner-michoel-core' ); ?></strong>
					<span><?php esc_html_e( 'You know your way around. You can hide this list now.', 'ner-michoel-core' ); ?></span>
				</div>
			</div>
		</section>

		<div class="nm-home-side">
			<?php if ( $can_tab( 'shiurim' ) ) : ?>
				<section class="nm-card" aria-labelledby="nm-recent-title">
					<div class="nm-card__head">
						<h2 class="nm-card__title" id="nm-recent-title"><?php echo ner_michoel_admin_icon( 'clock' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Recently added shiurim', 'ner-michoel-core' ); ?></h2>
						<a class="nm-link" href="<?php echo esc_url( ner_michoel_custom_admin_url( 'shiurim' ) ); ?>"><?php esc_html_e( 'See all', 'ner-michoel-core' ); ?></a>
					</div>
					<?php if ( $recent_shiurim ) : ?>
						<ul class="nm-mini-list">
							<?php foreach ( $recent_shiurim as $post ) : ?>
								<?php
								$speaker_terms = get_the_terms( $post->ID, 'speaker' );
								$speaker_name  = ( $speaker_terms && ! is_wp_error( $speaker_terms ) ) ? $speaker_terms[0]->name : '';
								?>
								<li>
									<a href="<?php echo esc_url( ner_michoel_custom_admin_url( 'shiurim', array( 'edit' => $post->ID ) ) ); ?>">
										<span class="nm-mini-list__main">
											<strong><?php echo esc_html( $post->post_title ? $post->post_title : __( '(no title)', 'ner-michoel-core' ) ); ?></strong>
											<span><?php echo esc_html( trim( $speaker_name . ( $speaker_name ? ' · ' : '' ) . get_the_date( '', $post ) ) ); ?></span>
										</span>
										<span class="nm-pill nm-pill--<?php echo esc_attr( $post->post_status ); ?>"><?php echo esc_html( isset( $status_labels[ $post->post_status ] ) ? $status_labels[ $post->post_status ] : $post->post_status ); ?></span>
									</a>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php else : ?>
						<p class="nm-empty-note"><?php esc_html_e( 'No shiurim yet. Your first one will show up here.', 'ner-michoel-core' ); ?></p>
					<?php endif; ?>
				</section>
			<?php endif; ?>

			<?php if ( $can_tab( 'inquiries' ) ) : ?>
				<section class="nm-card" aria-labelledby="nm-messages-title">
					<div class="nm-card__head">
						<h2 class="nm-card__title" id="nm-messages-title"><?php echo ner_michoel_admin_icon( 'inbox' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Latest messages', 'ner-michoel-core' ); ?></h2>
						<a class="nm-link" href="<?php echo esc_url( ner_michoel_custom_admin_url( 'inquiries' ) ); ?>"><?php esc_html_e( 'See all', 'ner-michoel-core' ); ?></a>
					</div>
					<?php if ( $recent_messages ) : ?>
						<ul class="nm-mini-list">
							<?php foreach ( $recent_messages as $post ) : ?>
								<?php
								$from = get_post_meta( $post->ID, '_nm_submission_name', true );
								$kind = get_post_meta( $post->ID, '_nm_submission_type', true );
								$body = wp_trim_words( (string) get_post_meta( $post->ID, '_nm_submission_message', true ), 12 );
								?>
								<li>
									<a href="<?php echo esc_url( ner_michoel_custom_admin_url( 'inquiries', array( 'edit' => $post->ID ) ) ); ?>">
										<span class="nm-avatar nm-avatar--sm" aria-hidden="true"><?php echo esc_html( strtoupper( mb_substr( $from ? $from : '?', 0, 1 ) ) ); ?></span>
										<span class="nm-mini-list__main">
											<strong><?php echo esc_html( $from ? $from : __( 'Someone', 'ner-michoel-core' ) ); ?><?php echo $kind ? ' <em>· ' . esc_html( $kind ) . '</em>' : ''; ?></strong>
											<span><?php echo esc_html( $body ? $body : get_the_date( '', $post ) ); ?></span>
										</span>
									</a>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php else : ?>
						<p class="nm-empty-note"><?php esc_html_e( 'No messages yet. When someone uses the contact form, a copy appears here.', 'ner-michoel-core' ); ?></p>
					<?php endif; ?>
				</section>
			<?php endif; ?>

			<aside class="nm-tip-card">
				<span class="nm-tip-card__icon"><?php echo ner_michoel_admin_icon( 'bulb' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<div>
					<strong><?php esc_html_e( 'Tip', 'ner-michoel-core' ); ?></strong>
					<p><?php echo esc_html( $tip ); ?></p>
				</div>
			</aside>
		</div>
	</div>
	<?php
}

/* ------------------------------------------------------------------ */
/* The page                                                            */
/* ------------------------------------------------------------------ */

function ner_michoel_render_custom_admin_ui() {
	$structure = ner_michoel_custom_admin_structure();
	list( $group_key, $tab_key ) = ner_michoel_custom_admin_resolve( $structure );

	$group      = $structure[ $group_key ];
	$active     = $group['tabs'][ $tab_key ];
	$has_access = current_user_can( ner_michoel_custom_admin_tab_cap( $active ) );
	$is_home    = 'home' === $tab_key;

	// The nav and the search list only offer pages this user can open.
	$nav = array();
	foreach ( $structure as $g_key => $g ) {
		$items = array();
		foreach ( $g['tabs'] as $t_key => $t ) {
			if ( ! empty( $t['hidden'] ) || ! current_user_can( ner_michoel_custom_admin_tab_cap( $t ) ) ) {
				continue;
			}
			$items[ $t_key ] = $t;
		}
		if ( $items ) {
			$nav[ $g_key ] = array_merge( $g, array( 'tabs' => $items ) );
		}
	}

	$messages_week = isset( $nav['inbox'] ) ? ner_michoel_custom_admin_messages_this_week() : 0;

	$search_items = array();
	foreach ( $nav as $g_key => $g ) {
		foreach ( $g['tabs'] as $t_key => $t ) {
			$search_items[] = array(
				'label'    => $t['label'],
				'group'    => 'home' === $g_key ? '' : $g['label'],
				'desc'     => isset( $t['desc'] ) ? $t['desc'] : '',
				'icon'     => $t['icon'],
				'url'      => ner_michoel_custom_admin_url( $t_key ),
				'keywords' => isset( $t['keywords'] ) ? $t['keywords'] : '',
			);
		}
	}
	$quick_actions = array(
		array( 'tab' => 'shiurim', 'label' => __( 'Add a new shiur', 'ner-michoel-core' ), 'icon' => 'plus', 'keywords' => 'audio video upload create' ),
		array( 'tab' => 'written', 'label' => __( 'Add a new written shiur', 'ner-michoel-core' ), 'icon' => 'plus', 'keywords' => 'pdf create' ),
		array( 'tab' => 'speakers', 'label' => __( 'Add a new speaker', 'ner-michoel-core' ), 'icon' => 'plus', 'keywords' => 'rabbi create' ),
		array( 'tab' => 'series', 'label' => __( 'Add a new series', 'ner-michoel-core' ), 'icon' => 'plus', 'keywords' => 'create course' ),
		array( 'tab' => 'galleries', 'label' => __( 'Add a new gallery', 'ner-michoel-core' ), 'icon' => 'plus', 'keywords' => 'photos create album' ),
		array( 'tab' => 'newslist', 'label' => __( 'Write a news post', 'ner-michoel-core' ), 'icon' => 'plus', 'keywords' => 'create article event' ),
		array( 'tab' => 'mazaltov', 'label' => __( 'Add a Mazal Tov with a photo', 'ner-michoel-core' ), 'icon' => 'plus', 'keywords' => 'simcha create' ),
	);
	foreach ( $quick_actions as $qa ) {
		list( $qa_group, $qa_tab ) = ner_michoel_custom_admin_resolve_tab( $structure, $qa['tab'] );
		if ( $qa_tab !== $qa['tab'] || ! isset( $nav[ $qa_group ]['tabs'][ $qa_tab ] ) ) {
			continue;
		}
		$search_items[] = array(
			'label'    => $qa['label'],
			'group'    => __( 'Quick action', 'ner-michoel-core' ),
			'desc'     => '',
			'icon'     => $qa['icon'],
			'url'      => ner_michoel_custom_admin_url( $qa['tab'], array( 'new' => 1 ) ),
			'keywords' => $qa['keywords'],
			'action'   => true,
		);
	}

	$user     = wp_get_current_user();
	$name     = $user->display_name ? $user->display_name : $user->user_login;
	$initials = '';
	foreach ( array_slice( preg_split( '/\s+/', trim( $name ) ), 0, 2 ) as $part ) {
		$initials .= mb_substr( $part, 0, 1 );
	}
	$initials  = strtoupper( $initials ? $initials : '?' );
	$site_name = get_bloginfo( 'name' );
	$site_icon = get_site_icon_url( 64 );
	$help      = isset( $active['help'] ) ? $active['help'] : array();

	ner_michoel_enqueue_custom_admin_assets();
	wp_localize_script(
		'ner-michoel-admin-shell',
		'nmAdminShell',
		array(
			'userId'  => get_current_user_id(),
			'tab'     => $tab_key,
			'isHome'  => $is_home,
			'search'  => $search_items,
			'flash'   => ner_michoel_custom_admin_flash(),
			'homeUrl' => ner_michoel_custom_admin_url(),
		)
	);

	// Theme assets, the toolbar, and the theme's footer player stay off this page.
	add_filter( 'show_admin_bar', '__return_false' );
	add_action( 'wp_enqueue_scripts', 'ner_michoel_custom_admin_isolate_assets', 9999 );
	remove_action( 'wp_footer', 'ner_michoel_render_player_bar' );
	remove_action( 'wp_head', 'ner_michoel_render_appearance_overrides', 20 );

	nocache_headers();
	?>
	<!DOCTYPE html>
	<html <?php language_attributes(); ?>>
	<head>
		<meta charset="<?php bloginfo( 'charset' ); ?>" />
		<meta name="viewport" content="width=device-width, initial-scale=1" />
		<meta name="robots" content="noindex, nofollow" />
		<title><?php echo esc_html( ( $is_home ? '' : $active['label'] . ' · ' ) . $site_name . ' — ' . __( 'Control Panel', 'ner-michoel-core' ) ); ?></title>
		<?php
		wp_admin_css( 'common' );
		wp_admin_css( 'forms' );
		wp_admin_css( 'l10n' );
		wp_admin_css();
		wp_head();
		?>
	</head>
	<body class="wp-admin wp-core-ui no-js nm-app-body">
		<?php ner_michoel_admin_icon_sprite(); ?>
		<a class="nm-skip" href="#nm-main"><?php esc_html_e( 'Skip to content', 'ner-michoel-core' ); ?></a>
		<div class="nm-app" id="nm-app">
			<aside class="nm-sidebar" id="nm-sidebar" aria-label="<?php esc_attr_e( 'Control panel menu', 'ner-michoel-core' ); ?>">
				<div class="nm-sidebar__brand">
					<a class="nm-brand" href="<?php echo esc_url( ner_michoel_custom_admin_url() ); ?>">
						<?php if ( $site_icon ) : ?>
							<img class="nm-brand__mark nm-brand__mark--img" src="<?php echo esc_url( $site_icon ); ?>" alt="" />
						<?php else : ?>
							<span class="nm-brand__mark" aria-hidden="true"><?php echo esc_html( strtoupper( mb_substr( $site_name ? $site_name : 'N', 0, 1 ) ) ); ?></span>
						<?php endif; ?>
						<span class="nm-brand__text">
							<strong><?php echo esc_html( $site_name ); ?></strong>
							<small><?php esc_html_e( 'Control Panel', 'ner-michoel-core' ); ?></small>
						</span>
					</a>
					<button type="button" class="nm-icon-btn nm-sidebar__close" data-sidebar-close aria-label="<?php esc_attr_e( 'Close menu', 'ner-michoel-core' ); ?>"><?php echo ner_michoel_admin_icon( 'x' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
				</div>

				<nav class="nm-nav" data-tour="nav">
					<?php foreach ( $nav as $g_key => $g ) : ?>
						<?php
						$is_current = $g_key === $group_key;
						$single     = 1 === count( $g['tabs'] );
						if ( ! empty( $g['advanced'] ) ) {
							echo '<div class="nm-nav__divider" role="presentation"><span>' . esc_html__( 'Rarely needed', 'ner-michoel-core' ) . '</span></div>';
						}
						?>
						<?php if ( $single ) : ?>
							<?php
							$only_key = key( $g['tabs'] );
							$only     = current( $g['tabs'] );
							?>
							<a class="nm-nav__link nm-nav__link--top<?php echo $only_key === $tab_key ? ' is-active' : ''; ?>" href="<?php echo esc_url( ner_michoel_custom_admin_url( $only_key ) ); ?>"<?php echo $only_key === $tab_key ? ' aria-current="page"' : ''; ?>>
								<?php echo ner_michoel_admin_icon( $g['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<span class="nm-nav__label"><?php echo esc_html( $g['label'] ); ?></span>
								<?php if ( 'inbox' === $g_key && $messages_week ) : ?>
									<span class="nm-badge" title="<?php esc_attr_e( 'New in the last 7 days', 'ner-michoel-core' ); ?>"><?php echo esc_html( $messages_week > 99 ? '99+' : $messages_week ); ?></span>
								<?php endif; ?>
							</a>
						<?php else : ?>
							<div class="nm-nav__group<?php echo $is_current ? ' is-open is-current' : ''; ?><?php echo ! empty( $g['advanced'] ) ? ' nm-nav__group--advanced' : ''; ?>" data-nav-group="<?php echo esc_attr( $g_key ); ?>">
								<button type="button" class="nm-nav__group-btn" aria-expanded="<?php echo $is_current ? 'true' : 'false'; ?>" aria-controls="nm-nav-<?php echo esc_attr( $g_key ); ?>">
									<?php echo ner_michoel_admin_icon( $g['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<span class="nm-nav__label"><?php echo esc_html( $g['label'] ); ?></span>
									<?php echo ner_michoel_admin_icon( 'chevron', 'nm-nav__chev' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								</button>
								<div class="nm-nav__items" id="nm-nav-<?php echo esc_attr( $g_key ); ?>"<?php echo $is_current ? '' : ' hidden'; ?>>
									<?php foreach ( $g['tabs'] as $t_key => $t ) : ?>
										<?php $here = $t_key === $tab_key || ( 'bulkassign' === $tab_key && 'bulk' === $t_key ) || ( 'news' === $tab_key && 'newslist' === $t_key ); ?>
										<a class="nm-nav__link<?php echo $here ? ' is-active' : ''; ?>" href="<?php echo esc_url( ner_michoel_custom_admin_url( $t_key ) ); ?>"<?php echo $here ? ' aria-current="page"' : ''; ?>>
											<?php echo ner_michoel_admin_icon( $t['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
											<span class="nm-nav__label"><?php echo esc_html( isset( $t['nav'] ) ? $t['nav'] : $t['label'] ); ?></span>
										</a>
									<?php endforeach; ?>
								</div>
							</div>
						<?php endif; ?>
					<?php endforeach; ?>
				</nav>

				<div class="nm-sidebar__help">
					<span class="nm-sidebar__help-icon"><?php echo ner_michoel_admin_icon( 'compass' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<strong><?php esc_html_e( 'New here?', 'ner-michoel-core' ); ?></strong>
					<p><?php esc_html_e( 'A one-minute tour shows you where everything is.', 'ner-michoel-core' ); ?></p>
					<button type="button" class="nm-btn nm-btn--soft nm-btn--block" data-tour-start><?php esc_html_e( 'Take the tour', 'ner-michoel-core' ); ?></button>
				</div>
			</aside>
			<div class="nm-scrim" data-sidebar-close hidden></div>

			<div class="nm-main-col">
				<header class="nm-topbar">
					<button type="button" class="nm-icon-btn nm-topbar__menu" data-sidebar-open aria-label="<?php esc_attr_e( 'Open menu', 'ner-michoel-core' ); ?>" aria-controls="nm-sidebar"><?php echo ner_michoel_admin_icon( 'menu' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
					<button type="button" class="nm-search-trigger" data-palette-open data-tour="search">
						<?php echo ner_michoel_admin_icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<span class="nm-search-trigger__text"><?php esc_html_e( 'Search or jump to…', 'ner-michoel-core' ); ?></span>
						<kbd class="nm-kbd" data-kbd-hint>Ctrl K</kbd>
					</button>
					<div class="nm-topbar__spacer"></div>
					<a class="nm-topbtn" href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" rel="noopener" data-tour="site">
						<?php echo ner_michoel_admin_icon( 'globe' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<span><?php esc_html_e( 'View site', 'ner-michoel-core' ); ?></span>
					</a>
					<button type="button" class="nm-topbtn" data-help-open data-tour="help" aria-controls="nm-help">
						<?php echo ner_michoel_admin_icon( 'help' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<span><?php esc_html_e( 'Help', 'ner-michoel-core' ); ?></span>
					</button>
					<div class="nm-user">
						<button type="button" class="nm-user__btn" data-user-menu aria-expanded="false" aria-controls="nm-user-menu" aria-label="<?php esc_attr_e( 'Your account', 'ner-michoel-core' ); ?>">
							<span class="nm-avatar" aria-hidden="true"><?php echo esc_html( $initials ); ?></span>
							<?php echo ner_michoel_admin_icon( 'chevron', 'nm-user__chev' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</button>
						<div class="nm-user__menu" id="nm-user-menu" hidden>
							<div class="nm-user__who">
								<strong><?php echo esc_html( $name ); ?></strong>
								<span><?php echo esc_html( $user->user_email ); ?></span>
							</div>
							<button type="button" class="nm-menu-item" data-tour-start><?php echo ner_michoel_admin_icon( 'compass' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Take the tour again', 'ner-michoel-core' ); ?></button>
							<button type="button" class="nm-menu-item" data-checklist-show><?php echo ner_michoel_admin_icon( 'check-circle' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Show “Getting started”', 'ner-michoel-core' ); ?></button>
							<a class="nm-menu-item" href="<?php echo esc_url( admin_url() ); ?>"><?php echo ner_michoel_admin_icon( 'wrench' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'WordPress dashboard', 'ner-michoel-core' ); ?></a>
							<a class="nm-menu-item nm-menu-item--danger" href="<?php echo esc_url( wp_logout_url( home_url( '/admin' ) ) ); ?>"><?php echo ner_michoel_admin_icon( 'logout' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Log out', 'ner-michoel-core' ); ?></a>
						</div>
					</div>
				</header>

				<main class="nm-main" id="nm-main" tabindex="-1">
					<?php if ( ! $is_home ) : ?>
						<div class="nm-page-head">
							<nav class="nm-crumbs" aria-label="<?php esc_attr_e( 'You are here', 'ner-michoel-core' ); ?>">
								<a href="<?php echo esc_url( ner_michoel_custom_admin_url() ); ?>"><?php esc_html_e( 'Home', 'ner-michoel-core' ); ?></a>
								<?php if ( 1 < count( $group['tabs'] ) ) : ?>
									<?php echo ner_michoel_admin_icon( 'chevron-r' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<span><?php echo esc_html( $group['label'] ); ?></span>
								<?php endif; ?>
								<?php echo ner_michoel_admin_icon( 'chevron-r' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<span aria-current="page"><?php echo esc_html( $active['label'] ); ?></span>
							</nav>
							<div class="nm-page-head__row">
								<span class="nm-page-head__icon"><?php echo ner_michoel_admin_icon( $active['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
								<div class="nm-page-head__text">
									<h1><?php echo esc_html( $active['label'] ); ?></h1>
									<?php if ( ! empty( $active['desc'] ) ) : ?>
										<p><?php echo esc_html( $active['desc'] ); ?></p>
									<?php endif; ?>
								</div>
								<?php if ( $help ) : ?>
									<button type="button" class="nm-btn nm-btn--soft nm-page-head__help" data-help-open>
										<?php echo ner_michoel_admin_icon( 'bulb' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
										<span><?php esc_html_e( 'How does this page work?', 'ner-michoel-core' ); ?></span>
									</button>
								<?php endif; ?>
							</div>
						</div>
					<?php endif; ?>

					<?php
					$body_type = isset( $active['cms_type'] ) ? 'cms' : ( isset( $active['settings_type'] ) ? 'settings' : 'callback' );
					if ( $is_home ) {
						$body_type = 'home';
					}
					?>
					<div class="nm-page-body nm-page-body--<?php echo esc_attr( $body_type ); ?>">
						<?php if ( ! $has_access ) : ?>
							<div class="nm-card nm-state">
								<span class="nm-state__icon"><?php echo ner_michoel_admin_icon( 'key' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
								<h2><?php esc_html_e( 'This page isn’t open to your account', 'ner-michoel-core' ); ?></h2>
								<p><?php esc_html_e( 'Ask the site’s main administrator if you need it.', 'ner-michoel-core' ); ?></p>
								<a class="nm-btn nm-btn--primary" href="<?php echo esc_url( ner_michoel_custom_admin_url() ); ?>"><?php esc_html_e( 'Back to Home', 'ner-michoel-core' ); ?></a>
							</div>
						<?php elseif ( isset( $active['cms_type'] ) ) : ?>
							<div class="nm-cms-root" data-cms-type="<?php echo esc_attr( $active['cms_type'] ); ?>" data-cms-args="<?php echo esc_attr( wp_json_encode( isset( $active['cms_args'] ) ? $active['cms_args'] : array() ) ); ?>"></div>
						<?php elseif ( isset( $active['settings_type'] ) ) : ?>
							<div class="nm-settings-root" data-settings-type="<?php echo esc_attr( $active['settings_type'] ); ?>"></div>
						<?php elseif ( isset( $active['callback'] ) && function_exists( $active['callback'] ) ) : ?>
							<?php if ( $is_home ) : ?>
								<?php call_user_func( $active['callback'] ); ?>
							<?php else : ?>
								<div class="nm-card nm-legacy">
									<?php
									ner_michoel_is_custom_admin_render( true );
									call_user_func( $active['callback'] );
									ner_michoel_is_custom_admin_render( false );
									?>
								</div>
							<?php endif; ?>
						<?php else : ?>
							<div class="nm-card nm-state">
								<span class="nm-state__icon"><?php echo ner_michoel_admin_icon( 'info' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
								<h2><?php esc_html_e( 'This page isn’t available right now', 'ner-michoel-core' ); ?></h2>
								<a class="nm-btn nm-btn--primary" href="<?php echo esc_url( ner_michoel_custom_admin_url() ); ?>"><?php esc_html_e( 'Back to Home', 'ner-michoel-core' ); ?></a>
							</div>
						<?php endif; ?>
					</div>
				</main>
			</div>
		</div>

		<?php // Help drawer: what this page is for, step by step. ?>
		<div class="nm-help-backdrop" data-help-close hidden></div>
		<aside class="nm-help" id="nm-help" role="dialog" aria-modal="true" aria-labelledby="nm-help-title" hidden>
			<div class="nm-help__head">
				<span class="nm-help__badge"><?php echo ner_michoel_admin_icon( 'bulb' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<div>
					<p class="nm-help__eyebrow"><?php esc_html_e( 'Help', 'ner-michoel-core' ); ?></p>
					<h2 id="nm-help-title"><?php echo esc_html( $active['label'] ); ?></h2>
				</div>
				<button type="button" class="nm-icon-btn" data-help-close aria-label="<?php esc_attr_e( 'Close help', 'ner-michoel-core' ); ?>"><?php echo ner_michoel_admin_icon( 'x' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
			</div>
			<div class="nm-help__body">
				<?php if ( ! empty( $active['desc'] ) ) : ?>
					<p class="nm-help__intro"><?php echo esc_html( $active['desc'] ); ?></p>
				<?php endif; ?>
				<?php if ( ! empty( $help['steps'] ) ) : ?>
					<h3><?php esc_html_e( 'Step by step', 'ner-michoel-core' ); ?></h3>
					<ol class="nm-help__steps">
						<?php foreach ( $help['steps'] as $step ) : ?>
							<li><?php echo esc_html( $step ); ?></li>
						<?php endforeach; ?>
					</ol>
				<?php endif; ?>
				<?php if ( ! empty( $help['tip'] ) ) : ?>
					<div class="nm-help__tip">
						<?php echo ner_michoel_admin_icon( 'bulb' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<div>
							<strong><?php esc_html_e( 'Good to know', 'ner-michoel-core' ); ?></strong>
							<p><?php echo esc_html( $help['tip'] ); ?></p>
							<?php if ( ! empty( $help['tip_link'] ) ) : ?>
								<a class="nm-link" href="<?php echo esc_url( ner_michoel_custom_admin_url( $help['tip_link']['tab'] ) ); ?>"><?php echo esc_html( $help['tip_link']['label'] ); ?> →</a>
							<?php endif; ?>
						</div>
					</div>
				<?php endif; ?>
				<h3><?php esc_html_e( 'More help', 'ner-michoel-core' ); ?></h3>
				<div class="nm-help__more">
					<button type="button" class="nm-help__card" data-tour-start>
						<?php echo ner_michoel_admin_icon( 'compass' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<span><strong><?php esc_html_e( 'Take the tour', 'ner-michoel-core' ); ?></strong><small><?php esc_html_e( 'One minute, shows where everything is', 'ner-michoel-core' ); ?></small></span>
					</button>
					<button type="button" class="nm-help__card" data-palette-open>
						<?php echo ner_michoel_admin_icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<span><strong><?php esc_html_e( 'Find a page', 'ner-michoel-core' ); ?></strong><small><?php esc_html_e( 'Type what you’re looking for', 'ner-michoel-core' ); ?></small></span>
					</button>
					<?php if ( ! $is_home ) : ?>
						<a class="nm-help__card" href="<?php echo esc_url( ner_michoel_custom_admin_url() ); ?>">
							<?php echo ner_michoel_admin_icon( 'home' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<span><strong><?php esc_html_e( 'Back to Home', 'ner-michoel-core' ); ?></strong><small><?php esc_html_e( 'Shortcuts for the common jobs', 'ner-michoel-core' ); ?></small></span>
						</a>
					<?php endif; ?>
				</div>
			</div>
		</aside>

		<div class="nm-wp-footer">
			<?php wp_footer(); ?>
		</div>
	</body>
	</html>
	<?php
	exit;
}
