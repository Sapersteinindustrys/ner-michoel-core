<?php
/**
 * Custom, lightweight site-wide traffic + page-speed tracker — kept
 * entirely in the plugin (no theme changes needed) since this is
 * infrastructure that should survive a future redesign, same reasoning
 * as the CPTs.
 *
 * Deliberately not a full analytics platform (no bot-network detection,
 * no session stitching, no geo lookups). What it does give: pageviews,
 * approximate unique visitors, top pages, top external referrers, and
 * real client-reported page load time — all queried straight from one
 * table in this site's own database, nothing sent to a third party.
 *
 * Privacy notes (worth knowing before relying on this):
 * - No raw IP is ever stored. `visitor_hash` = md5(IP + user agent +
 *   today's date + a site secret) — it changes every day by design, so
 *   it's good for "how many different visitors today" but can't be used
 *   to follow one visitor across multiple days or build a profile.
 * - Logged-in users who can edit content (admins/editors) are never
 *   tracked, so testing/editing the site doesn't inflate the numbers.
 * - Obvious bots (crawlers, uptime monitors, link-preview fetchers) are
 *   filtered by user-agent match before a row is ever written.
 * - Rows older than 90 days are pruned automatically by a daily cron.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NER_MICHOEL_PAGEVIEWS_DB_VERSION', '1.0' );

function ner_michoel_pageviews_table_name() {
	global $wpdb;
	return $wpdb->prefix . 'nm_pageviews';
}

/**
 * Creates/upgrades the pageviews table. Runs on plugin activation, and
 * also has an `admin_init` backstop (below) for a site where the
 * plugin was already active before this shipped — dbDelta() is safe to
 * call repeatedly, it only applies the diff.
 */
function ner_michoel_create_pageviews_table() {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table_name      = ner_michoel_pageviews_table_name();
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table_name} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		created_at DATETIME NOT NULL,
		url VARCHAR(255) NOT NULL,
		referrer_domain VARCHAR(255) NULL,
		visitor_hash CHAR(32) NOT NULL,
		load_time_ms INT UNSIGNED NULL,
		PRIMARY KEY  (id),
		KEY created_at (created_at),
		KEY visitor_hash (visitor_hash)
	) {$charset_collate};";

	dbDelta( $sql );

	update_option( 'nm_pageviews_db_version', NER_MICHOEL_PAGEVIEWS_DB_VERSION );
}

function ner_michoel_maybe_create_pageviews_table() {
	if ( get_option( 'nm_pageviews_db_version' ) !== NER_MICHOEL_PAGEVIEWS_DB_VERSION ) {
		ner_michoel_create_pageviews_table();
	}
}
add_action( 'admin_init', 'ner_michoel_maybe_create_pageviews_table' );

/**
 * Daily pruning cron — scheduled on activation, cleared on
 * deactivation (see ner-michoel-core.php).
 */
function ner_michoel_schedule_pageviews_pruning() {
	if ( ! wp_next_scheduled( 'nm_prune_pageviews' ) ) {
		wp_schedule_event( time(), 'daily', 'nm_prune_pageviews' );
	}
}

/**
 * Backstop, same reasoning as the table-version check above — a site
 * where the plugin was already active before this shipped still gets
 * the pruning cron scheduled, without needing a manual
 * deactivate/reactivate.
 */
function ner_michoel_maybe_schedule_pageviews_pruning() {
	if ( ! wp_next_scheduled( 'nm_prune_pageviews' ) ) {
		ner_michoel_schedule_pageviews_pruning();
	}
}
add_action( 'admin_init', 'ner_michoel_maybe_schedule_pageviews_pruning' );

function ner_michoel_prune_pageviews() {
	global $wpdb;
	$table = ner_michoel_pageviews_table_name();
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', strtotime( '-90 days' ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
}
add_action( 'nm_prune_pageviews', 'ner_michoel_prune_pageviews' );

/**
 * Front-end tracking snippet — every front-end page except for a
 * logged-in editor/admin (so working on the site doesn't skew the
 * numbers).
 */
function ner_michoel_enqueue_site_stats_script() {
	if ( is_admin() ) {
		return;
	}
	if ( current_user_can( 'edit_posts' ) ) {
		return;
	}

	wp_enqueue_script( 'ner-michoel-site-stats', NER_MICHOEL_CORE_URL . 'assets/site-stats.js', array(), NER_MICHOEL_CORE_VERSION, true );
	wp_localize_script(
		'ner-michoel-site-stats',
		'nmSiteStats',
		array(
			'endpoint' => esc_url_raw( rest_url( 'ner-michoel/v1/pageview' ) ),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'ner_michoel_enqueue_site_stats_script' );

function ner_michoel_register_pageview_route() {
	register_rest_route(
		'ner-michoel/v1',
		'/pageview',
		array(
			'methods'             => 'POST',
			'callback'            => 'ner_michoel_handle_pageview',
			'permission_callback' => '__return_true',
			'args'                => array(
				'url'       => array( 'required' => true ),
				'referrer'  => array( 'required' => false ),
				'load_time' => array( 'required' => false ),
			),
		)
	);
}
add_action( 'rest_api_init', 'ner_michoel_register_pageview_route' );

/**
 * User-agent substrings for common crawlers, uptime monitors, and
 * link-preview fetchers — not exhaustive (no bot list ever is), just
 * enough to keep the obvious ones out of "visitor" counts.
 */
function ner_michoel_is_bot_user_agent( $user_agent ) {
	if ( '' === $user_agent ) {
		return true; // A real browser always sends one.
	}
	$needles = array(
		'bot', 'spider', 'crawl', 'slurp', 'facebookexternalhit', 'whatsapp',
		'telegrambot', 'pingdom', 'uptimerobot', 'bingpreview', 'ahrefs',
		'semrush', 'mj12bot', 'petalbot', 'yandex', 'headlesschrome',
		'phantomjs', 'python-requests', 'curl/', 'wget/',
	);
	$user_agent = strtolower( $user_agent );
	foreach ( $needles as $needle ) {
		if ( false !== strpos( $user_agent, $needle ) ) {
			return true;
		}
	}
	return false;
}

function ner_michoel_handle_pageview( WP_REST_Request $request ) {
	if ( current_user_can( 'edit_posts' ) ) {
		return new WP_REST_Response( array( 'recorded' => false ), 200 );
	}

	$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
	if ( ner_michoel_is_bot_user_agent( $user_agent ) ) {
		return new WP_REST_Response( array( 'recorded' => false ), 200 );
	}

	$url = (string) $request->get_param( 'url' );
	$url = wp_strip_all_tags( $url );
	$url = substr( $url, 0, 255 );
	if ( '' === $url ) {
		return new WP_REST_Response( array( 'recorded' => false ), 200 );
	}

	$referrer_domain = null;
	$referrer        = (string) $request->get_param( 'referrer' );
	if ( '' !== $referrer ) {
		$referrer_host = wp_parse_url( $referrer, PHP_URL_HOST );
		$site_host     = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( $referrer_host && $referrer_host !== $site_host ) {
			$referrer_domain = substr( preg_replace( '/^www\./', '', strtolower( $referrer_host ) ), 0, 255 );
		}
	}

	$load_time = $request->get_param( 'load_time' );
	$load_time = ( is_numeric( $load_time ) && $load_time >= 0 && $load_time < 300000 ) ? (int) $load_time : null;

	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$visitor_hash = md5( $ip . '|' . $user_agent . '|' . gmdate( 'Y-m-d' ) . '|' . wp_salt() );

	global $wpdb;
	$wpdb->insert(
		ner_michoel_pageviews_table_name(),
		array(
			'created_at'      => current_time( 'mysql', true ),
			'url'             => $url,
			'referrer_domain' => $referrer_domain,
			'visitor_hash'    => $visitor_hash,
			'load_time_ms'    => $load_time,
		),
		array( '%s', '%s', '%s', '%s', '%d' )
	);

	return new WP_REST_Response( array( 'recorded' => true ), 200 );
}

/**
 * Admin: "Site Statistics" page.
 */
function ner_michoel_render_site_stats_page() {
	global $wpdb;
	$table = ner_michoel_pageviews_table_name();

	$since_7  = gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) );
	$since_30 = gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) );

	$pageviews_7  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s", $since_7 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$pageviews_30 = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s", $since_30 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$visitors_7   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT visitor_hash) FROM {$table} WHERE created_at >= %s", $since_7 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$visitors_30  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT visitor_hash) FROM {$table} WHERE created_at >= %s", $since_30 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$avg_load_ms  = $wpdb->get_var( $wpdb->prepare( "SELECT AVG(load_time_ms) FROM {$table} WHERE created_at >= %s AND load_time_ms IS NOT NULL", $since_30 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	$top_pages = $wpdb->get_results( $wpdb->prepare( "SELECT url, COUNT(*) AS views FROM {$table} WHERE created_at >= %s GROUP BY url ORDER BY views DESC LIMIT 10", $since_30 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	$top_referrers = $wpdb->get_results( $wpdb->prepare( "SELECT referrer_domain, COUNT(*) AS views FROM {$table} WHERE created_at >= %s AND referrer_domain IS NOT NULL GROUP BY referrer_domain ORDER BY views DESC LIMIT 10", $since_30 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	$daily = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(created_at) AS day, COUNT(*) AS views, COUNT(DISTINCT visitor_hash) AS visitors FROM {$table} WHERE created_at >= %s GROUP BY DATE(created_at) ORDER BY day DESC LIMIT 14", $since_30 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$daily = array_reverse( $daily ); // Oldest first, so the bar chart reads left-to-right chronologically.

	$daily_max     = 1;
	$daily_max_vis = 1;
	foreach ( $daily as $row ) {
		$daily_max     = max( $daily_max, (int) $row->views );
		$daily_max_vis = max( $daily_max_vis, (int) $row->visitors );
	}
	$pages_max     = $top_pages ? max( wp_list_pluck( $top_pages, 'views' ) ) : 1;
	$referrer_max  = $top_referrers ? max( wp_list_pluck( $top_referrers, 'views' ) ) : 1;
	?>
	<div class="wrap nm-dashboard">
		<h1><?php esc_html_e( 'Site Statistics', 'ner-michoel-core' ); ?></h1>
		<p class="description">
			<?php esc_html_e( 'Self-hosted, kept entirely in this site\'s own database. "Visitors" is an approximate daily count (the same person visiting on two different days counts twice, by design — see the note in dev-notes.md).', 'ner-michoel-core' ); ?>
		</p>

		<style>
			.nm-stat-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin: 20px 0 32px; max-width: 900px; }
			.nm-stat-card { border-radius: 10px; padding: 20px 22px; color: #fff; box-shadow: 0 2px 10px rgba(0,0,0,.08); }
			.nm-stat-card--views { background: linear-gradient(135deg, #2271b1, #135e96); }
			.nm-stat-card--visitors { background: linear-gradient(135deg, #00a32a, #007017); }
			.nm-stat-card--speed { background: linear-gradient(135deg, #8c5cd6, #5e35b1); }
			.nm-stat-card__label { font-size: .78rem; text-transform: uppercase; letter-spacing: .04em; opacity: .85; margin-bottom: 6px; }
			.nm-stat-card__value { font-size: 2rem; font-weight: 700; line-height: 1.1; }
			.nm-stat-card__sub { font-size: .8rem; opacity: .8; margin-top: 4px; }

			.nm-chart-panel { background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 20px 24px 8px; margin-bottom: 28px; max-width: 900px; }
			.nm-chart-panel h2 { margin-top: 0; }

			.nm-bar-chart { display: flex; align-items: flex-end; gap: 6px; height: 160px; padding-top: 10px; }
			.nm-bar-chart__col { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: flex-end; height: 100%; min-width: 0; }
			.nm-bar-chart__bar { width: 100%; max-width: 26px; border-radius: 4px 4px 0 0; background: linear-gradient(180deg, #4f9fe8, #2271b1); position: relative; transition: opacity .15s ease; }
			.nm-bar-chart__bar:hover { opacity: .8; }
			.nm-bar-chart__bar[data-visitors="1"] { background: linear-gradient(180deg, #4bd07a, #00a32a); }
			.nm-bar-chart__val { font-size: .65rem; color: #50575e; margin-bottom: 2px; white-space: nowrap; }
			.nm-bar-chart__label { font-size: .65rem; color: #8c8f94; margin-top: 6px; white-space: nowrap; transform: rotate(-40deg); transform-origin: top left; }
			.nm-chart-legend { display: flex; gap: 18px; font-size: .8rem; color: #50575e; margin: 10px 0 20px; }
			.nm-chart-legend span { display: inline-flex; align-items: center; gap: 6px; }
			.nm-chart-legend i { width: 10px; height: 10px; border-radius: 2px; display: inline-block; }

			.nm-hbar-list { display: flex; flex-direction: column; gap: 10px; margin: 16px 0 20px; }
			.nm-hbar-row { display: grid; grid-template-columns: 220px 1fr 60px; align-items: center; gap: 10px; }
			.nm-hbar-row__label { font-size: .85rem; color: #1d2327; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
			.nm-hbar-row__track { background: #f0f0f1; border-radius: 4px; height: 18px; overflow: hidden; }
			.nm-hbar-row__fill { height: 100%; border-radius: 4px; background: linear-gradient(90deg, #2271b1, #72aee6); }
			.nm-hbar-row__val { font-size: .8rem; color: #50575e; text-align: right; }
		</style>

		<div class="nm-stat-cards">
			<div class="nm-stat-card nm-stat-card--views">
				<div class="nm-stat-card__label"><?php esc_html_e( 'Pageviews', 'ner-michoel-core' ); ?></div>
				<div class="nm-stat-card__value"><?php echo esc_html( number_format_i18n( $pageviews_30 ) ); ?></div>
				<div class="nm-stat-card__sub"><?php echo esc_html( number_format_i18n( $pageviews_7 ) ); ?> <?php esc_html_e( 'in the last 7 days', 'ner-michoel-core' ); ?></div>
			</div>
			<div class="nm-stat-card nm-stat-card--visitors">
				<div class="nm-stat-card__label"><?php esc_html_e( 'Visitors (approx.)', 'ner-michoel-core' ); ?></div>
				<div class="nm-stat-card__value"><?php echo esc_html( number_format_i18n( $visitors_30 ) ); ?></div>
				<div class="nm-stat-card__sub"><?php echo esc_html( number_format_i18n( $visitors_7 ) ); ?> <?php esc_html_e( 'in the last 7 days', 'ner-michoel-core' ); ?></div>
			</div>
			<div class="nm-stat-card nm-stat-card--speed">
				<div class="nm-stat-card__label"><?php esc_html_e( 'Avg. Load Time', 'ner-michoel-core' ); ?></div>
				<div class="nm-stat-card__value">
					<?php
					echo $avg_load_ms
						? esc_html( number_format_i18n( $avg_load_ms / 1000, 2 ) . 's' )
						: esc_html__( '—', 'ner-michoel-core' );
					?>
				</div>
				<div class="nm-stat-card__sub"><?php esc_html_e( 'reported by visitors\' browsers', 'ner-michoel-core' ); ?></div>
			</div>
		</div>

		<div class="nm-chart-panel">
			<h2><?php esc_html_e( 'Last 14 Days', 'ner-michoel-core' ); ?></h2>
			<?php if ( $daily ) : ?>
				<div class="nm-chart-legend">
					<span><i style="background:#2271b1;"></i><?php esc_html_e( 'Pageviews', 'ner-michoel-core' ); ?></span>
					<span><i style="background:#00a32a;"></i><?php esc_html_e( 'Visitors', 'ner-michoel-core' ); ?></span>
				</div>
				<div class="nm-bar-chart">
					<?php foreach ( $daily as $row ) : ?>
						<?php
						$views_pct = max( 4, round( ( (int) $row->views / $daily_max ) * 100 ) );
						$vis_pct   = max( 4, round( ( (int) $row->visitors / $daily_max_vis ) * 100 ) );
						?>
						<div class="nm-bar-chart__col">
							<div class="nm-bar-chart__val"><?php echo esc_html( number_format_i18n( $row->views ) ); ?></div>
							<div style="display:flex;align-items:flex-end;gap:2px;width:100%;height:100%;">
								<div class="nm-bar-chart__bar" style="height:<?php echo esc_attr( $views_pct ); ?>%;"></div>
								<div class="nm-bar-chart__bar" data-visitors="1" style="height:<?php echo esc_attr( $vis_pct ); ?>%;"></div>
							</div>
							<div class="nm-bar-chart__label"><?php echo esc_html( mysql2date( 'M j', $row->day ) ); ?></div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php else : ?>
				<p><?php esc_html_e( 'No data yet.', 'ner-michoel-core' ); ?></p>
			<?php endif; ?>
		</div>

		<div class="nm-chart-panel">
			<h2><?php esc_html_e( 'Top Pages (30 days)', 'ner-michoel-core' ); ?></h2>
			<?php if ( $top_pages ) : ?>
				<div class="nm-hbar-list">
					<?php foreach ( $top_pages as $row ) : ?>
						<div class="nm-hbar-row">
							<div class="nm-hbar-row__label" title="<?php echo esc_attr( $row->url ); ?>"><?php echo esc_html( $row->url ); ?></div>
							<div class="nm-hbar-row__track"><div class="nm-hbar-row__fill" style="width:<?php echo esc_attr( round( ( $row->views / $pages_max ) * 100 ) ); ?>%;"></div></div>
							<div class="nm-hbar-row__val"><?php echo esc_html( number_format_i18n( $row->views ) ); ?></div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php else : ?>
				<p><?php esc_html_e( 'No data yet.', 'ner-michoel-core' ); ?></p>
			<?php endif; ?>
		</div>

		<div class="nm-chart-panel">
			<h2><?php esc_html_e( 'Top Referrers (30 days)', 'ner-michoel-core' ); ?></h2>
			<?php if ( $top_referrers ) : ?>
				<div class="nm-hbar-list">
					<?php foreach ( $top_referrers as $row ) : ?>
						<div class="nm-hbar-row">
							<div class="nm-hbar-row__label"><?php echo esc_html( $row->referrer_domain ); ?></div>
							<div class="nm-hbar-row__track"><div class="nm-hbar-row__fill" style="width:<?php echo esc_attr( round( ( $row->views / $referrer_max ) * 100 ) ); ?>%;"></div></div>
							<div class="nm-hbar-row__val"><?php echo esc_html( number_format_i18n( $row->views ) ); ?></div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php else : ?>
				<p><?php esc_html_e( 'No external referrers recorded yet (direct traffic and internal navigation aren\'t counted here).', 'ner-michoel-core' ); ?></p>
			<?php endif; ?>
		</div>
	</div>
	<?php
}
