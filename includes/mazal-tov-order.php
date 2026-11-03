<?php
/**
 * The order Mazal Tovs show in (Yoni, 2026-11-03): newest first, unless the admin
 * arranges them (Control Panel > Announcements > Mazal Tov Order).
 *
 * Kept in each announcement's menu_order: the ones the admin arranged are 1, 2, 3...
 * in that order, and every other published one gets the number after the last. A new
 * announcement is 0, so it goes on top, the way it always did; "Back to newest first"
 * sets them all to 0 again. The pages list by menu_order, then newest:
 * ner_michoel_mazal_tov_order_args() for a list in code (the homepage), and the
 * /mazal-tov/ page below.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The query arguments that put Mazal Tovs in the admin's order. */
function ner_michoel_mazal_tov_order_args() {
	return array(
		'orderby' => array(
			'menu_order' => 'ASC',
			'date'       => 'DESC',
		),
	);
}

/** The /mazal-tov/ page lists in that order too. */
function ner_michoel_mazal_tov_order_archive( $query ) {
	if ( is_admin() || ! $query->is_main_query() || ! $query->is_post_type_archive( 'mazal_tov' ) || $query->get( 'orderby' ) ) {
		return;
	}
	$query->set( 'orderby', array( 'menu_order' => 'ASC', 'date' => 'DESC' ) );
}
add_action( 'pre_get_posts', 'ner_michoel_mazal_tov_order_archive' );

/** Has the admin arranged them (any published one with a number)? */
function ner_michoel_mazal_tov_order_is_custom() {
	global $wpdb;
	return (bool) $wpdb->get_var( "SELECT 1 FROM {$wpdb->posts} WHERE post_type = 'mazal_tov' AND post_status = 'publish' AND menu_order > 0 LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

/** Saves an order: $ids first (1, 2, 3...), every other published one after them. */
function ner_michoel_mazal_tov_order_save( array $ids ) {
	global $wpdb;
	$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	$pos = 0;
	foreach ( $ids as $id ) {
		if ( 'mazal_tov' !== get_post_type( $id ) ) {
			continue;
		}
		$pos++;
		$wpdb->update( $wpdb->posts, array( 'menu_order' => $pos ), array( 'ID' => $id ) );
		clean_post_cache( $id );
	}
	$rest = $pos + 1;
	$skip = $ids ? ' AND ID NOT IN (' . implode( ',', $ids ) . ')' : '';
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET menu_order = %d WHERE post_type = 'mazal_tov'{$skip}", $rest ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- $skip is integers only.
	wp_cache_set( 'last_changed', microtime(), 'posts' );
}

/** Newest first again. */
function ner_michoel_mazal_tov_order_reset() {
	global $wpdb;
	$wpdb->query( "UPDATE {$wpdb->posts} SET menu_order = 0 WHERE post_type = 'mazal_tov'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	wp_cache_set( 'last_changed', microtime(), 'posts' );
}

function ner_michoel_mazal_tov_order_back( $args = array() ) {
	wp_safe_redirect( esc_url_raw( ner_michoel_custom_admin_url( 'mazaltovorder', $args ) ) );
	exit;
}

function ner_michoel_handle_mazal_tov_order() {
	if ( ! current_user_can( 'edit_others_posts' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'ner-michoel-core' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'nm_mazal_tov_order', 'nm_mazal_tov_order_nonce' );
	if ( isset( $_POST['reset'] ) ) {
		ner_michoel_mazal_tov_order_reset();
		ner_michoel_mazal_tov_order_back( array( 'saved' => 'reset' ) );
	}
	$ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : array();
	ner_michoel_mazal_tov_order_save( $ids );
	ner_michoel_mazal_tov_order_back( array( 'saved' => '1' ) );
}
add_action( 'admin_post_nm_mazal_tov_order', 'ner_michoel_handle_mazal_tov_order' );

/** Control Panel > Announcements > Mazal Tov Order. */
function ner_michoel_render_mazal_tov_order_page() {
	$posts = get_posts(
		array_merge(
			array(
				'post_type'      => 'mazal_tov',
				'post_status'    => 'publish',
				'posts_per_page' => 60,
				'no_found_rows'  => true,
			),
			ner_michoel_mazal_tov_order_args()
		)
	);
	$total  = (int) wp_count_posts( 'mazal_tov' )->publish;
	$custom = ner_michoel_mazal_tov_order_is_custom();
	$saved  = isset( $_GET['saved'] ) ? sanitize_key( wp_unslash( $_GET['saved'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	wp_enqueue_script( 'ner-michoel-mazal-tov-order', NER_MICHOEL_CORE_URL . 'assets/mazal-tov-order.js', array(), NER_MICHOEL_CORE_VERSION, true );
	?>
	<div class="wrap nm-dashboard nm-mt-order">
		<?php if ( '1' === $saved ) : ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Saved. The site shows the Mazal Tovs in this order. New ones go on top.', 'ner-michoel-core' ); ?></p></div>
		<?php elseif ( 'reset' === $saved ) : ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Back to newest first.', 'ner-michoel-core' ); ?></p></div>
		<?php endif; ?>

		<p><?php echo $custom ? esc_html__( 'The site shows them in the order you set. A new Mazal Tov goes on top.', 'ner-michoel-core' ) : esc_html__( 'The site shows them newest first. Arrange them below and press Save to choose your own order.', 'ner-michoel-core' ); ?></p>

		<?php if ( ! $posts ) : ?>
			<p><?php esc_html_e( 'No Mazal Tovs are posted yet.', 'ner-michoel-core' ); ?></p>
		<?php else : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'nm_mazal_tov_order', 'nm_mazal_tov_order_nonce' ); ?>
			<input type="hidden" name="action" value="nm_mazal_tov_order" />
			<?php ner_michoel_panel_form_field(); ?>
			<ol class="nm-mt-order__list" data-mt-order>
				<?php foreach ( $posts as $i => $post ) : ?>
					<?php $parts = function_exists( 'ner_michoel_mazal_tov_parts' ) ? ner_michoel_mazal_tov_parts( $post ) : array( 'before' => '', 'lead' => get_the_title( $post ), 'after' => '' ); ?>
					<li class="nm-mt-order__item" draggable="true">
						<input type="hidden" name="ids[]" value="<?php echo (int) $post->ID; ?>" />
						<span class="nm-mt-order__grip" aria-hidden="true">&#8942;&#8942;</span>
						<span class="nm-mt-order__text" dir="auto"><?php echo esc_html( trim( $parts['before'] . ' ' ) ); ?> <strong><?php echo esc_html( $parts['lead'] ); ?></strong> <?php echo esc_html( $parts['after'] ); ?></span>
						<span class="nm-mt-order__moves">
							<button type="button" class="button button-small" data-move="top" title="<?php esc_attr_e( 'Move to the top', 'ner-michoel-core' ); ?>"><?php esc_html_e( 'Top', 'ner-michoel-core' ); ?></button>
							<button type="button" class="button button-small" data-move="up" aria-label="<?php esc_attr_e( 'Move up', 'ner-michoel-core' ); ?>">&uarr;</button>
							<button type="button" class="button button-small" data-move="down" aria-label="<?php esc_attr_e( 'Move down', 'ner-michoel-core' ); ?>">&darr;</button>
						</span>
					</li>
				<?php endforeach; ?>
			</ol>
			<?php if ( $total > count( $posts ) ) : ?>
				<p class="description"><?php echo esc_html( sprintf( /* translators: 1: shown, 2: total */ __( 'The first %1$s of %2$s are shown. The older ones stay after these, newest first.', 'ner-michoel-core' ), number_format_i18n( count( $posts ) ), number_format_i18n( $total ) ) ); ?></p>
			<?php endif; ?>
			<p>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save order', 'ner-michoel-core' ); ?></button>
				<?php if ( $custom ) : ?>
					<button type="submit" name="reset" value="1" class="button" onclick="return confirm('<?php echo esc_js( __( 'Show the Mazal Tovs newest first again?', 'ner-michoel-core' ) ); ?>');"><?php esc_html_e( 'Back to newest first', 'ner-michoel-core' ); ?></button>
				<?php endif; ?>
			</p>
		</form>
		<?php endif; ?>
	</div>
	<style>
		.nm-mt-order__list { margin: 12px 0; padding: 0; list-style: none; max-width: 900px; counter-reset: mt; }
		.nm-mt-order__item { display: flex; align-items: center; gap: 10px; padding: 8px 10px; margin: 0 0 6px; background: #fff; border: 1px solid #dcdcde; border-radius: 6px; cursor: grab; }
		.nm-mt-order__item::before { counter-increment: mt; content: counter(mt) "."; color: #646970; min-width: 2em; }
		.nm-mt-order__item.is-dragging { opacity: .5; }
		.nm-mt-order__grip { color: #a7aaad; letter-spacing: -3px; }
		.nm-mt-order__text { flex: 1 1 auto; min-width: 0; }
		.nm-mt-order__moves { display: flex; gap: 4px; flex: none; }
	</style>
	<?php
}
