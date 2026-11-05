<?php
/**
 * The order the series show in on the Shiurim page (Yoni, 2026-11-04): Control Panel >
 * Shiurim > Series Order.
 *
 * The Shiurim page shows the series in rows, one row per top-level series ("Gemara",
 * "Parsha", ...), each with cards of the series inside it. Here the admin drags the rows
 * into order, and the cards inside each row. Kept in each series' term meta nm_order:
 * 1, 2, 3... among its sisters; 0 (or none) for a series nobody placed, which comes after
 * the placed ones in the page's own order (rows biggest first, cards by name).
 * "Back to the automatic order" clears them all.
 *
 * The theme reads ner_michoel_series_admin_order() (inc/shiurim-studio.php, and the
 * Series list in the Shiurim sidebar).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** A series' place among its sisters, 0 when it has none. */
function ner_michoel_series_admin_order( $term_id ) {
	return (int) get_term_meta( (int) $term_id, 'nm_order', true );
}

/** Has the admin placed any series? */
function ner_michoel_series_order_is_custom() {
	global $wpdb;
	return (bool) $wpdb->get_var( "SELECT 1 FROM {$wpdb->termmeta} WHERE meta_key = 'nm_order' AND meta_value <> '0' AND meta_value <> '' LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

/**
 * Sorts series terms by the admin's order (placed ones first, by place), keeping the order
 * they came in for the rest.
 *
 * @param WP_Term[] $terms
 * @return WP_Term[]
 */
function ner_michoel_series_sort_by_admin_order( array $terms ) {
	$pos = array();
	foreach ( array_values( $terms ) as $i => $term ) {
		$order               = ner_michoel_series_admin_order( $term->term_id );
		$pos[ $term->term_id ] = array( $order > 0 ? 0 : 1, $order, $i );
	}
	usort(
		$terms,
		function ( $a, $b ) use ( $pos ) {
			return $pos[ $a->term_id ] <=> $pos[ $b->term_id ];
		}
	);
	return $terms;
}

/** The series under a parent (0 for the top level), in the admin's order. */
function ner_michoel_series_order_children( $parent ) {
	$terms = get_terms(
		array(
			'taxonomy'   => 'series',
			'hide_empty' => false,
			'parent'     => (int) $parent,
			'orderby'    => 'name',
		)
	);
	if ( is_wp_error( $terms ) ) {
		return array();
	}
	return ner_michoel_series_sort_by_admin_order( $terms );
}

function ner_michoel_series_order_back( $args = array() ) {
	wp_safe_redirect( esc_url_raw( ner_michoel_custom_admin_url( 'seriesorder', $args ) ) );
	exit;
}

/** Saves the rows (ids[]) and the cards inside each (kids[parent][]). */
function ner_michoel_handle_series_order() {
	if ( ! current_user_can( 'manage_categories' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'ner-michoel-core' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'nm_series_order', 'nm_series_order_nonce' );

	if ( isset( $_POST['reset'] ) ) {
		delete_metadata( 'term', 0, 'nm_order', '', true );
		ner_michoel_series_order_back( array( 'saved' => 'reset' ) );
	}

	$lists = array();
	$lists[0] = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : array();
	if ( isset( $_POST['kids'] ) && is_array( $_POST['kids'] ) ) {
		foreach ( wp_unslash( $_POST['kids'] ) as $parent => $ids ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each id goes through absint.
			$lists[ absint( $parent ) ] = array_map( 'absint', (array) $ids );
		}
	}

	foreach ( $lists as $parent => $ids ) {
		$place = 0;
		foreach ( array_unique( array_filter( $ids ) ) as $id ) {
			$term = get_term( $id, 'series' );
			if ( ! $term || is_wp_error( $term ) || (int) $term->parent !== (int) $parent ) {
				continue;
			}
			update_term_meta( $id, 'nm_order', ++$place );
		}
	}

	if ( function_exists( 'ner_michoel_purge_page_caches' ) ) {
		ner_michoel_purge_page_caches();
	}
	ner_michoel_series_order_back( array( 'saved' => '1' ) );
}
add_action( 'admin_post_nm_series_order', 'ner_michoel_handle_series_order' );

/** Control Panel > Shiurim > Series Order. */
function ner_michoel_render_series_order_page() {
	$rows   = ner_michoel_series_order_children( 0 );
	$custom = ner_michoel_series_order_is_custom();
	$saved  = isset( $_GET['saved'] ) ? sanitize_key( wp_unslash( $_GET['saved'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	wp_enqueue_script( 'ner-michoel-series-order', NER_MICHOEL_CORE_URL . 'assets/series-order.js', array(), NER_MICHOEL_CORE_VERSION, true );
	?>
	<div class="wrap nm-dashboard nm-series-order">
		<?php if ( '1' === $saved ) : ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Saved. The Shiurim page shows the series in this order.', 'ner-michoel-core' ); ?></p></div>
		<?php elseif ( 'reset' === $saved ) : ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Back to the automatic order.', 'ner-michoel-core' ); ?></p></div>
		<?php endif; ?>

		<p><?php esc_html_e( 'Each line is a row on the Shiurim page. Drag a row up or down (or use Top and the arrows). Press “Series inside” to put the cards of that row in order too. Then press Save order.', 'ner-michoel-core' ); ?></p>
		<p class="description"><?php echo $custom ? esc_html__( 'The Shiurim page uses your order. A new series goes after the ones you placed.', 'ner-michoel-core' ) : esc_html__( 'Right now the page puts the biggest rows first and the cards by name.', 'ner-michoel-core' ); ?></p>

		<?php if ( ! $rows ) : ?>
			<p><?php esc_html_e( 'There are no series yet.', 'ner-michoel-core' ); ?></p>
		<?php else : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'nm_series_order', 'nm_series_order_nonce' ); ?>
			<input type="hidden" name="action" value="nm_series_order" />
			<?php if ( function_exists( 'ner_michoel_panel_form_field' ) ) { ner_michoel_panel_form_field(); } ?>
			<ol class="nm-so__list" data-so-list>
				<?php foreach ( $rows as $row ) : ?>
					<?php $kids = ner_michoel_series_order_children( $row->term_id ); ?>
					<li class="nm-so__item" draggable="true">
						<div class="nm-so__line">
							<input type="hidden" name="ids[]" value="<?php echo (int) $row->term_id; ?>" />
							<span class="nm-so__grip" aria-hidden="true">&#8942;&#8942;</span>
							<span class="nm-so__name"><strong><?php echo esc_html( html_entity_decode( $row->name, ENT_QUOTES, 'UTF-8' ) ); ?></strong>
								<?php if ( $kids ) : ?>
									<span class="nm-so__count"><?php echo esc_html( sprintf( /* translators: %s: number of series */ _n( '%s series inside', '%s series inside', count( $kids ), 'ner-michoel-core' ), number_format_i18n( count( $kids ) ) ) ); ?></span>
								<?php endif; ?>
							</span>
							<span class="nm-so__moves">
								<?php if ( $kids ) : ?>
									<button type="button" class="button button-small" data-so-open aria-expanded="false"><?php esc_html_e( 'Series inside', 'ner-michoel-core' ); ?></button>
								<?php endif; ?>
								<button type="button" class="button button-small" data-move="top"><?php esc_html_e( 'Top', 'ner-michoel-core' ); ?></button>
								<button type="button" class="button button-small" data-move="up" aria-label="<?php esc_attr_e( 'Move up', 'ner-michoel-core' ); ?>">&uarr;</button>
								<button type="button" class="button button-small" data-move="down" aria-label="<?php esc_attr_e( 'Move down', 'ner-michoel-core' ); ?>">&darr;</button>
							</span>
						</div>
						<?php if ( $kids ) : ?>
							<ol class="nm-so__list nm-so__list--inner" data-so-list hidden>
								<?php foreach ( $kids as $kid ) : ?>
									<li class="nm-so__item" draggable="true">
										<div class="nm-so__line">
											<input type="hidden" name="kids[<?php echo (int) $row->term_id; ?>][]" value="<?php echo (int) $kid->term_id; ?>" />
											<span class="nm-so__grip" aria-hidden="true">&#8942;&#8942;</span>
											<span class="nm-so__name"><?php echo esc_html( html_entity_decode( $kid->name, ENT_QUOTES, 'UTF-8' ) ); ?></span>
											<span class="nm-so__moves">
												<button type="button" class="button button-small" data-move="top"><?php esc_html_e( 'Top', 'ner-michoel-core' ); ?></button>
												<button type="button" class="button button-small" data-move="up" aria-label="<?php esc_attr_e( 'Move up', 'ner-michoel-core' ); ?>">&uarr;</button>
												<button type="button" class="button button-small" data-move="down" aria-label="<?php esc_attr_e( 'Move down', 'ner-michoel-core' ); ?>">&darr;</button>
											</span>
										</div>
									</li>
								<?php endforeach; ?>
							</ol>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ol>
			<p>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save order', 'ner-michoel-core' ); ?></button>
				<?php if ( $custom ) : ?>
					<button type="submit" name="reset" value="1" class="button" onclick="return confirm('<?php echo esc_js( __( 'Go back to the automatic order (biggest rows first, cards by name)?', 'ner-michoel-core' ) ); ?>');"><?php esc_html_e( 'Back to the automatic order', 'ner-michoel-core' ); ?></button>
				<?php endif; ?>
			</p>
		</form>
		<?php endif; ?>
	</div>
	<style>
		.nm-so__list { margin: 12px 0; padding: 0; list-style: none; max-width: 900px; counter-reset: so; }
		.nm-so__list--inner { margin: 8px 0 4px 28px; }
		.nm-so__item { margin: 0 0 6px; }
		.nm-so__line { display: flex; align-items: center; gap: 10px; padding: 8px 10px; background: #fff; border: 1px solid #dcdcde; border-radius: 6px; cursor: grab; }
		.nm-so__line::before { counter-increment: so; content: counter(so) "."; color: #646970; min-width: 2em; }
		.nm-so__item.is-dragging > .nm-so__line { opacity: .5; }
		.nm-so__grip { color: #a7aaad; letter-spacing: -3px; }
		.nm-so__name { flex: 1 1 auto; min-width: 0; }
		.nm-so__count { margin-left: 8px; color: #646970; font-size: 12px; }
		.nm-so__moves { display: flex; gap: 4px; flex: none; }
	</style>
	<?php
}
