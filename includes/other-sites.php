<?php
/**
 * "Our other sites": links to the yeshiva's other websites (the fundraising
 * and dinner sites), for admins only. Shown as a card on the Site Control
 * Panel home and as a widget on the WordPress dashboard. Each link opens in
 * a new tab.
 *
 * The list can be changed with the ner_michoel_other_sites filter
 * (an array of array( 'label' => ..., 'url' => ... )).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ner_michoel_other_sites() {
	$sites = array(
		array(
			'label' => __( 'Fundraising site (demo)', 'ner-michoel-core' ),
			'url'   => 'https://lqe.zkn.mybluehost.me/website_75442b6d/',
		),
		array(
			'label' => __( 'Dinner site (demo)', 'ner-michoel-core' ),
			'url'   => 'https://lqe.zkn.mybluehost.me/website_5062d3d5/',
		),
	);
	return (array) apply_filters( 'ner_michoel_other_sites', $sites );
}

/** The links as a list; the Control Panel card and the dashboard widget share it. */
function ner_michoel_other_sites_list() {
	$sites = ner_michoel_other_sites();
	if ( ! $sites ) {
		return;
	}
	echo '<ul class="nm-mini-list nm-other-sites">';
	foreach ( $sites as $site ) {
		if ( empty( $site['url'] ) || empty( $site['label'] ) ) {
			continue;
		}
		printf(
			'<li><a href="%1$s" target="_blank" rel="noopener noreferrer"><span class="nm-mini-list__main"><strong>%2$s</strong><span>%3$s</span></span></a></li>',
			esc_url( $site['url'] ),
			esc_html( $site['label'] ),
			esc_html( preg_replace( '#^https?://#', '', untrailingslashit( $site['url'] ) ) )
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
			<h2 class="nm-card__title" id="nm-other-sites-title"><?php echo function_exists( 'ner_michoel_admin_icon' ) ? ner_michoel_admin_icon( 'external' ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Our other sites', 'ner-michoel-core' ); ?></h2>
		</div>
		<?php ner_michoel_other_sites_list(); ?>
	</section>
	<?php
}

function ner_michoel_other_sites_dashboard_widget() {
	if ( current_user_can( 'manage_options' ) && ner_michoel_other_sites() ) {
		wp_add_dashboard_widget( 'ner_michoel_other_sites', __( 'Our other sites', 'ner-michoel-core' ), 'ner_michoel_other_sites_list' );
	}
}
add_action( 'wp_dashboard_setup', 'ner_michoel_other_sites_dashboard_widget' );
