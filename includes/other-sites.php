<?php
/**
 * "Other Tomo Projects": the yeshiva's other websites and tools, for admins
 * only. A page of its own in the WordPress admin menu (cards: name, a short
 * description, the whole card opens the site in a new tab), plus the same
 * links as a card on the Site Control Panel home and a WordPress dashboard
 * widget.
 *
 * The list can be changed with the ner_michoel_other_sites filter
 * (an array of array( 'label' => ..., 'url' => ..., 'desc' => ... )).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ner_michoel_other_sites() {
	$sites = array(
		array(
			'label' => __( 'Toras Moshe Giving', 'ner-michoel-core' ),
			'url'   => 'https://lqe.zkn.mybluehost.me/website_75442b6d/',
			'desc'  => __( 'Secure online fundraising for the yeshiva: campaigns, online donations and receipts. Demo, not taking real money yet.', 'ner-michoel-core' ),
		),
		array(
			'label' => __( 'Toras Moshe Dinner', 'ner-michoel-core' ),
			'url'   => 'https://lqe.zkn.mybluehost.me/website_5062d3d5/',
			'desc'  => __( 'The annual dinner site: honorees, reservations, sponsorships, journal ads and gifts. Demo with sample content.', 'ner-michoel-core' ),
		),
		array(
			'label' => __( 'Shiur Listen & Correct (transcriber)', 'ner-michoel-core' ),
			'url'   => 'https://lqe.zkn.mybluehost.me/website_6a0fe25e/',
			'desc'  => __( 'Listen to shiur clips and fix the computer transcript. The fixes train our own shiur transcription model.', 'ner-michoel-core' ),
		),
		array(
			'label' => __( 'Handwritten notes to text (beta)', 'ner-michoel-core' ),
			'url'   => 'https://lqe.zkn.mybluehost.me/website_6a0fe25e/handwriting-beta/',
			'desc'  => __( 'Upload a photo of handwritten notes in Hebrew, Yiddish or Aramaic and get typed text back.', 'ner-michoel-core' ),
		),
	);
	$sites = (array) apply_filters( 'ner_michoel_other_sites', $sites );
	return array_values(
		array_filter(
			$sites,
			function ( $site ) {
				return ! empty( $site['url'] ) && ! empty( $site['label'] );
			}
		)
	);
}

/** The links as a list; the Control Panel card and the dashboard widget share it. */
function ner_michoel_other_sites_list() {
	$sites = ner_michoel_other_sites();
	if ( ! $sites ) {
		return;
	}
	echo '<ul class="nm-mini-list nm-other-sites">';
	foreach ( $sites as $site ) {
		printf(
			'<li><a href="%1$s" target="_blank" rel="noopener noreferrer"><span class="nm-mini-list__main"><strong>%2$s</strong><span>%3$s</span></span></a></li>',
			esc_url( $site['url'] ),
			esc_html( $site['label'] ),
			esc_html( isset( $site['desc'] ) ? $site['desc'] : '' )
		);
	}
	echo '</ul>';
}

/** The card on the Site Control Panel home (ner_michoel_render_custom_admin_overview()). */
function ner_michoel_other_sites_card() {
	if ( ! current_user_can( 'manage_options' ) || ! ner_michoel_other_sites() ) {
		return;
	}
	?>
	<section class="nm-card" aria-labelledby="nm-other-sites-title">
		<div class="nm-card__head">
			<h2 class="nm-card__title" id="nm-other-sites-title"><?php echo function_exists( 'ner_michoel_admin_icon' ) ? ner_michoel_admin_icon( 'external' ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Other Tomo Projects', 'ner-michoel-core' ); ?></h2>
		</div>
		<?php ner_michoel_other_sites_list(); ?>
	</section>
	<?php
}

function ner_michoel_other_sites_dashboard_widget() {
	if ( current_user_can( 'manage_options' ) && ner_michoel_other_sites() ) {
		wp_add_dashboard_widget( 'ner_michoel_other_sites', __( 'Other Tomo Projects', 'ner-michoel-core' ), 'ner_michoel_other_sites_list' );
	}
}
add_action( 'wp_dashboard_setup', 'ner_michoel_other_sites_dashboard_widget' );

function ner_michoel_other_sites_menu() {
	add_menu_page(
		__( 'Other Tomo Projects', 'ner-michoel-core' ),
		__( 'Other Tomo Projects', 'ner-michoel-core' ),
		'manage_options',
		'nm-other-projects',
		'ner_michoel_other_sites_page',
		'dashicons-external',
		3
	);
}
add_action( 'admin_menu', 'ner_michoel_other_sites_menu' );

/** The menu page: one card per site, the whole card is the link. */
function ner_michoel_other_sites_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Other Tomo Projects', 'ner-michoel-core' ); ?></h1>
		<p><?php esc_html_e( 'The yeshiva’s other sites and tools. Each one opens in a new tab.', 'ner-michoel-core' ); ?></p>
		<style>
			.nm-op-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px;margin-top:16px;max-width:1100px}
			.nm-op-card{display:block;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px 20px;text-decoration:none;color:#1d2327;box-shadow:0 1px 2px rgba(0,0,0,.04)}
			.nm-op-card:hover,.nm-op-card:focus{border-color:#2271b1;box-shadow:0 0 0 1px #2271b1}
			.nm-op-card h2{margin:0 0 6px;font-size:16px;color:#2271b1}
			.nm-op-card p{margin:0 0 10px;color:#50575e}
			.nm-op-card small{color:#787c82;word-break:break-all}
		</style>
		<div class="nm-op-grid">
			<?php foreach ( ner_michoel_other_sites() as $site ) : ?>
				<a class="nm-op-card" href="<?php echo esc_url( $site['url'] ); ?>" target="_blank" rel="noopener noreferrer">
					<h2><?php echo esc_html( $site['label'] ); ?> <span class="dashicons dashicons-external" aria-hidden="true"></span></h2>
					<?php if ( ! empty( $site['desc'] ) ) : ?>
						<p><?php echo esc_html( $site['desc'] ); ?></p>
					<?php endif; ?>
					<small><?php echo esc_html( preg_replace( '#^https?://#', '', untrailingslashit( $site['url'] ) ) ); ?></small>
				</a>
			<?php endforeach; ?>
		</div>
	</div>
	<?php
}
