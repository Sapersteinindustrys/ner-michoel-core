<?php
/**
 * The order the series show in on the Shiurim page, and which series sits inside which
 * (Yoni, 2026-11-04 and 2026-11-05): Control Panel > Shiurim > Series Order.
 *
 * The Shiurim page shows the series in rows, one row per top-level series ("Gemara",
 * "Parsha", ...), each with cards of the series inside it. Here the admin sees every series
 * as a tree of folders: drags them into order, and moves a series into another one ("Move
 * into…"), with everything inside it and all of its shiurim. Shiurim stay in the series
 * they are in; only the series around them move. Kept in each series' term meta nm_order:
 * 1, 2, 3... among its sisters; 0 (or none) for a series nobody placed, which comes after
 * the placed ones in the page's own order (rows biggest first, cards by name).
 * "Back to the automatic order" clears them all.
 *
 * The theme reads ner_michoel_series_admin_order() (inc/shiurim-studio.php, and the
 * Series list in the Shiurim sidebar).
 *
 * Also here: the routes that move shiurim from one series to another (the Control Panel's
 * Series and Shiurim screens, and the shiur's own page for someone who can edit it).
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

/**
 * Saves the tree: kids[parent][] is the series inside each series (0 for the top), in order.
 * A series sent under a new parent is moved there. The whole new tree is checked first, so a
 * series can never end up inside itself; nothing is changed when it would.
 */
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
	if ( isset( $_POST['ids'] ) ) {
		$lists[0] = array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) );
	}
	if ( isset( $_POST['kids'] ) && is_array( $_POST['kids'] ) ) {
		foreach ( wp_unslash( $_POST['kids'] ) as $parent => $ids ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each id goes through absint.
			$lists[ absint( $parent ) ] = array_map( 'absint', (array) $ids );
		}
	}

	// Every series' parent now, and as sent.
	$parent_of = array();
	foreach ( ner_michoel_series_all_terms() as $term ) {
		$parent_of[ (int) $term->term_id ] = (int) $term->parent;
	}
	$wanted = $parent_of;
	foreach ( $lists as $parent => $ids ) {
		if ( $parent && ! isset( $parent_of[ $parent ] ) ) {
			continue;
		}
		foreach ( $ids as $id ) {
			if ( isset( $parent_of[ $id ] ) && $id !== $parent ) {
				$wanted[ $id ] = (int) $parent;
			}
		}
	}

	// No series inside itself, however deep.
	foreach ( $wanted as $id => $parent ) {
		$seen = array( $id => true );
		$up   = $parent;
		while ( $up ) {
			if ( isset( $seen[ $up ] ) || ! isset( $wanted[ $up ] ) ) {
				if ( isset( $seen[ $up ] ) ) {
					ner_michoel_series_order_back( array( 'saved' => 'loop' ) );
				}
				break;
			}
			$seen[ $up ] = true;
			$up          = $wanted[ $up ];
		}
	}

	$moved  = 0;
	$failed = array();
	foreach ( $wanted as $id => $parent ) {
		if ( $parent_of[ $id ] === $parent ) {
			continue;
		}
		$term   = get_term( $id, 'series' );
		$result = wp_update_term(
			$id,
			'series',
			array(
				'parent' => $parent,
				'slug'   => $term && ! is_wp_error( $term ) ? $term->slug : '',
			)
		);
		if ( is_wp_error( $result ) ) {
			$failed[] = $id;
			continue;
		}
		++$moved;
		// A moved series goes to the end of its new sisters, unless it was placed below.
		delete_term_meta( $id, 'nm_order' );
	}
	if ( $moved && function_exists( 'ner_michoel_series_label_map' ) ) {
		ner_michoel_series_label_map( true );
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
	$args = array(
		'saved' => '1',
		'moved' => $moved,
	);
	if ( $failed ) {
		$args['failed'] = implode( ',', array_slice( $failed, 0, 20 ) );
	}
	ner_michoel_series_order_back( $args );
}
add_action( 'admin_post_nm_series_order', 'ner_michoel_handle_series_order' );

/** How many shiurim are in a series and in every series inside it, by series. */
function ner_michoel_series_order_totals( array $kids, array $own ) {
	$totals = array();
	$sum    = function ( $id, $depth ) use ( &$sum, &$totals, $kids, $own ) {
		if ( isset( $totals[ $id ] ) ) {
			return $totals[ $id ];
		}
		$total = isset( $own[ $id ] ) ? $own[ $id ] : 0;
		if ( $depth < 20 && ! empty( $kids[ $id ] ) ) {
			foreach ( $kids[ $id ] as $kid ) {
				$total += $sum( $kid->term_id, $depth + 1 );
			}
		}
		$totals[ $id ] = $total;
		return $total;
	};
	foreach ( array_keys( $own ) as $id ) {
		$sum( $id, 0 );
	}
	return $totals;
}

/**
 * One series and the series inside it, as lines of the tree.
 *
 * @param WP_Term $term   The series.
 * @param array   $kids   Parent id => its series, in order.
 * @param array   $totals Series id => shiurim in it and inside it.
 * @param int     $depth  How deep.
 */
function ner_michoel_render_series_order_item( $term, array $kids, array $totals, $depth = 0 ) {
	$id     = (int) $term->term_id;
	$inside = isset( $kids[ $id ] ) ? $kids[ $id ] : array();
	$name   = html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' );
	?>
	<li class="nm-so__item" draggable="true" data-so-id="<?php echo (int) $id; ?>" data-so-name="<?php echo esc_attr( $name ); ?>" data-so-own="<?php echo (int) $term->count; ?>">
		<div class="nm-so__line">
			<input type="hidden" name="kids[<?php echo (int) $term->parent; ?>][]" value="<?php echo (int) $id; ?>" data-so-input />
			<span class="nm-so__grip" aria-hidden="true">&#8942;&#8942;</span>
			<span class="nm-so__name">
				<span class="nm-so__folder" aria-hidden="true">&#128193;</span>
				<?php if ( 0 === $depth ) : ?>
					<strong><?php echo esc_html( $name ); ?></strong>
				<?php else : ?>
					<?php echo esc_html( $name ); ?>
				<?php endif; ?>
				<span class="nm-so__count" data-so-count>
					<?php
					$bits = array();
					if ( $inside ) {
						/* translators: %s: number of series */
						$bits[] = sprintf( _n( '%s series inside', '%s series inside', count( $inside ), 'ner-michoel-core' ), number_format_i18n( count( $inside ) ) );
					}
					$total  = isset( $totals[ $id ] ) ? (int) $totals[ $id ] : 0;
					/* translators: %s: number of shiurim */
					$bits[] = sprintf( _n( '%s shiur', '%s shiurim', $total, 'ner-michoel-core' ), number_format_i18n( $total ) );
					echo esc_html( implode( ' · ', $bits ) );
					?>
				</span>
			</span>
			<span class="nm-so__moves">
				<button type="button" class="button button-small" data-so-open aria-expanded="false"<?php echo $inside ? '' : ' hidden'; ?>><?php esc_html_e( 'Open', 'ner-michoel-core' ); ?></button>
				<button type="button" class="button button-small" data-so-move-into><?php esc_html_e( 'Move into…', 'ner-michoel-core' ); ?></button>
				<button type="button" class="button button-small" data-move="top"><?php esc_html_e( 'Top', 'ner-michoel-core' ); ?></button>
				<button type="button" class="button button-small" data-move="up" aria-label="<?php esc_attr_e( 'Move up', 'ner-michoel-core' ); ?>">&uarr;</button>
				<button type="button" class="button button-small" data-move="down" aria-label="<?php esc_attr_e( 'Move down', 'ner-michoel-core' ); ?>">&darr;</button>
			</span>
		</div>
		<ol class="nm-so__list nm-so__list--inner" data-so-list data-so-parent="<?php echo (int) $id; ?>" hidden>
			<?php
			foreach ( $inside as $kid ) {
				ner_michoel_render_series_order_item( $kid, $kids, $totals, $depth + 1 );
			}
			?>
		</ol>
	</li>
	<?php
}

/** Control Panel > Shiurim > Series Order. */
function ner_michoel_render_series_order_page() {
	$all  = ner_michoel_series_all_terms();
	$kids = array();
	$own  = array();
	$ids  = array();
	foreach ( $all as $term ) {
		$ids[ (int) $term->term_id ] = true;
	}
	foreach ( $all as $term ) {
		$parent            = isset( $ids[ (int) $term->parent ] ) ? (int) $term->parent : 0;
		$kids[ $parent ][] = $term;
		$own[ (int) $term->term_id ] = (int) $term->count;
	}
	foreach ( $kids as $parent => $list ) {
		usort(
			$list,
			function ( $a, $b ) {
				return strnatcasecmp( html_entity_decode( $a->name, ENT_QUOTES, 'UTF-8' ), html_entity_decode( $b->name, ENT_QUOTES, 'UTF-8' ) );
			}
		);
		$kids[ $parent ] = ner_michoel_series_sort_by_admin_order( $list );
	}
	$rows   = isset( $kids[0] ) ? $kids[0] : array();
	$totals = ner_michoel_series_order_totals( $kids, $own );
	$custom = ner_michoel_series_order_is_custom();
	$saved  = isset( $_GET['saved'] ) ? sanitize_key( wp_unslash( $_GET['saved'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$moved  = isset( $_GET['moved'] ) ? absint( $_GET['moved'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$failed = isset( $_GET['failed'] ) ? array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_GET['failed'] ) ) ) ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	wp_enqueue_script( 'ner-michoel-series-order', NER_MICHOEL_CORE_URL . 'assets/series-order.js', array(), NER_MICHOEL_CORE_VERSION, true );
	wp_add_inline_script(
		'ner-michoel-series-order',
		'window.nmSeriesOrder = ' . wp_json_encode(
			array(
				'top'      => __( 'The top (its own row on the Shiurim page)', 'ner-michoel-core' ),
				'pick'     => __( 'Move it into…', 'ner-michoel-core' ),
				'cancel'   => __( 'Cancel', 'ner-michoel-core' ),
				'moved'    => __( 'Moved. Press Save order to keep it.', 'ner-michoel-core' ),
				'unsaved'  => __( 'You have changes that are not saved yet.', 'ner-michoel-core' ),
				/* translators: %s: number of series */
				'inside'   => __( '%s series inside', 'ner-michoel-core' ),
				/* translators: %s: number of shiurim */
				'shiur'    => __( '%s shiur', 'ner-michoel-core' ),
				/* translators: %s: number of shiurim */
				'shiurim'  => __( '%s shiurim', 'ner-michoel-core' ),
			)
		) . ';',
		'before'
	);
	?>
	<div class="wrap nm-dashboard nm-series-order">
		<?php if ( '1' === $saved ) : ?>
			<div class="notice notice-success"><p>
				<?php
				esc_html_e( 'Saved. The Shiurim page shows the series in this order.', 'ner-michoel-core' );
				if ( $moved ) {
					echo ' ' . esc_html( sprintf( /* translators: %s: number of series */ _n( '%s series was moved into another one, with its shiurim.', '%s series were moved into other ones, with their shiurim.', $moved, 'ner-michoel-core' ), number_format_i18n( $moved ) ) );
				}
				?>
			</p></div>
		<?php elseif ( 'reset' === $saved ) : ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Back to the automatic order.', 'ner-michoel-core' ); ?></p></div>
		<?php elseif ( 'loop' === $saved ) : ?>
			<div class="notice notice-error"><p><?php esc_html_e( 'Nothing was saved: a series can’t go inside a series that is inside it. Please try again.', 'ner-michoel-core' ); ?></p></div>
		<?php endif; ?>
		<?php if ( $failed ) : ?>
			<div class="notice notice-warning"><p>
				<?php
				$names = array();
				foreach ( $failed as $fid ) {
					$fterm = get_term( $fid, 'series' );
					if ( $fterm && ! is_wp_error( $fterm ) ) {
						$names[] = '“' . html_entity_decode( $fterm->name, ENT_QUOTES, 'UTF-8' ) . '”';
					}
				}
				/* translators: %s: series names */
				echo esc_html( sprintf( __( 'These could not be moved, because the series they were moved into already has one with the same name: %s. Rename one of them, or move it somewhere else.', 'ner-michoel-core' ), implode( ', ', $names ) ) );
				?>
			</p></div>
		<?php endif; ?>

		<p><?php esc_html_e( 'Every series is a folder. The top lines are the rows on the Shiurim page; press Open to see the series inside one. Drag a series up or down (or use Top and the arrows) to put it in order among its sisters. Press “Move into…” to put a series inside another one: everything inside it, and all its shiurim, go with it. Then press Save order.', 'ner-michoel-core' ); ?></p>
		<p class="description"><?php echo $custom ? esc_html__( 'The Shiurim page uses your order. A new series goes after the ones you placed.', 'ner-michoel-core' ) : esc_html__( 'Right now the page puts the biggest rows first and the cards by name.', 'ner-michoel-core' ); ?></p>

		<?php if ( ! $rows ) : ?>
			<p><?php esc_html_e( 'There are no series yet.', 'ner-michoel-core' ); ?></p>
		<?php else : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-so-form>
			<?php wp_nonce_field( 'nm_series_order', 'nm_series_order_nonce' ); ?>
			<input type="hidden" name="action" value="nm_series_order" />
			<?php if ( function_exists( 'ner_michoel_panel_form_field' ) ) { ner_michoel_panel_form_field(); } ?>
			<p class="nm-so__tools">
				<button type="button" class="button" data-so-all="open"><?php esc_html_e( 'Open all', 'ner-michoel-core' ); ?></button>
				<button type="button" class="button" data-so-all="close"><?php esc_html_e( 'Close all', 'ner-michoel-core' ); ?></button>
				<span class="nm-so__status" data-so-status aria-live="polite"></span>
			</p>
			<ol class="nm-so__list" data-so-list data-so-parent="0">
				<?php
				foreach ( $rows as $row ) {
					ner_michoel_render_series_order_item( $row, $kids, $totals, 0 );
				}
				?>
			</ol>
			<p class="nm-so__save">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save order', 'ner-michoel-core' ); ?></button>
				<?php if ( $custom ) : ?>
					<button type="submit" name="reset" value="1" class="button" onclick="return confirm('<?php echo esc_js( __( 'Go back to the automatic order (biggest rows first, cards by name)? Series you moved into other series stay where they are.', 'ner-michoel-core' ) ); ?>');"><?php esc_html_e( 'Back to the automatic order', 'ner-michoel-core' ); ?></button>
				<?php endif; ?>
			</p>
		</form>
		<?php endif; ?>
	</div>
	<style>
		.nm-so__list { margin: 12px 0; padding: 0; list-style: none; max-width: 980px; counter-reset: so; }
		.nm-so__list--inner { margin: 6px 0 4px 30px; padding-left: 10px; border-left: 2px solid #e0e3e7; }
		.nm-so__list--inner:empty { display: none; }
		.nm-so__item { margin: 0 0 6px; }
		.nm-so__line { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 10px; padding: 8px 10px; background: #fff; border: 1px solid #dcdcde; border-radius: 6px; cursor: grab; }
		.nm-so__line::before { counter-increment: so; content: counter(so) "."; color: #646970; min-width: 2em; }
		.nm-so__item.is-dragging > .nm-so__line { opacity: .5; }
		.nm-so__item.is-moved > .nm-so__line { border-color: #2271b1; box-shadow: 0 0 0 1px #2271b1; }
		.nm-so__grip { color: #a7aaad; letter-spacing: -3px; }
		.nm-so__name { flex: 1 1 260px; min-width: 0; }
		.nm-so__folder { margin-right: 4px; }
		.nm-so__count { margin-left: 8px; color: #646970; font-size: 12px; }
		.nm-so__moves { display: flex; flex-wrap: wrap; gap: 4px; flex: none; }
		.nm-so__pick { display: flex; flex: 1 1 100%; gap: 6px; align-items: center; padding-top: 6px; }
		.nm-so__pick select { flex: 1 1 auto; max-width: 560px; }
		.nm-so__tools { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
		.nm-so__status { color: #2271b1; font-weight: 600; }
		.nm-so__save { position: sticky; bottom: 0; margin: 0; padding: 12px 0; background: #fff; border-top: 1px solid #e0e3e7; }
	</style>
	<?php
}

/* ------------------------------------------------------------------
 * Moving shiurim from one series to another.
 * ------------------------------------------------------------------ */

function ner_michoel_register_shiur_series_routes() {
	register_rest_route(
		'ner-michoel/v1',
		'/series-shiurim',
		array(
			'methods'             => 'GET',
			'callback'            => 'ner_michoel_series_shiurim_rest',
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
		)
	);
	register_rest_route(
		'ner-michoel/v1',
		'/shiur-series',
		array(
			'methods'             => 'POST',
			'callback'            => 'ner_michoel_shiur_series_rest',
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
		)
	);
}
add_action( 'rest_api_init', 'ner_michoel_register_shiur_series_routes' );

/** A shiur as the move screens list it. */
function ner_michoel_shiur_series_row( WP_Post $post ) {
	$terms = get_the_terms( $post, 'series' );
	$label = array();
	if ( $terms && ! is_wp_error( $terms ) ) {
		foreach ( $terms as $term ) {
			$label[] = html_entity_decode( function_exists( 'ner_michoel_series_label' ) ? ner_michoel_series_label( $term ) : $term->name, ENT_QUOTES, 'UTF-8' );
		}
	}
	return array(
		'id'     => (int) $post->ID,
		'title'  => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
		'date'   => get_the_date( 'Y-m-d', $post ),
		'type'   => $post->post_type,
		'status' => $post->post_status,
		'series' => $terms && ! is_wp_error( $terms ) ? array_map( 'intval', wp_list_pluck( $terms, 'term_id' ) ) : array(),
		'label'  => implode( ', ', $label ),
		'link'   => get_permalink( $post ),
	);
}

/** The shiurim (and written shiurim) in one series itself, not in the series inside it. */
function ner_michoel_series_shiurim_rest( WP_REST_Request $request ) {
	$series = absint( $request->get_param( 'series' ) );
	$term   = $series ? get_term( $series, 'series' ) : null;
	if ( ! $term || is_wp_error( $term ) ) {
		return new WP_Error( 'nm_series_missing', __( 'That series was not found.', 'ner-michoel-core' ), array( 'status' => 404 ) );
	}
	$posts = get_posts(
		array(
			'post_type'      => array( 'shiur', 'written_shiur' ),
			'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
			'posts_per_page' => 500,
			'orderby'        => array( 'menu_order' => 'ASC', 'date' => 'DESC' ),
			'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy'         => 'series',
					'field'            => 'term_id',
					'terms'            => $series,
					'include_children' => false,
				),
			),
		)
	);
	return new WP_REST_Response(
		array(
			'series' => array(
				'id'   => (int) $term->term_id,
				'path' => html_entity_decode( function_exists( 'ner_michoel_series_label' ) ? ner_michoel_series_label( $term ) : $term->name, ENT_QUOTES, 'UTF-8' ),
			),
			'items'  => array_map( 'ner_michoel_shiur_series_row', $posts ),
			'more'   => count( $posts ) >= 500,
		),
		200
	);
}

/**
 * Puts shiurim (ids) into one series (series; 0 takes them out of every series). Each shiur
 * is in that one series afterwards, in place of the one it was in. Only shiurim the person
 * may edit.
 */
function ner_michoel_shiur_series_rest( WP_REST_Request $request ) {
	$ids    = array_slice( array_unique( array_filter( array_map( 'absint', (array) $request->get_param( 'ids' ) ) ) ), 0, 500 );
	$series = absint( $request->get_param( 'series' ) );
	if ( ! $ids ) {
		return new WP_Error( 'nm_move_none', __( 'Choose at least one shiur to move.', 'ner-michoel-core' ), array( 'status' => 400 ) );
	}
	if ( $series ) {
		$term = get_term( $series, 'series' );
		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_Error( 'nm_move_series', __( 'That series was not found. Choose another one.', 'ner-michoel-core' ), array( 'status' => 400 ) );
		}
	}

	$moved = array();
	$skip  = 0;
	foreach ( $ids as $id ) {
		$post = get_post( $id );
		if ( ! $post || ! in_array( $post->post_type, array( 'shiur', 'written_shiur' ), true ) || ! current_user_can( 'edit_post', $id ) ) {
			++$skip;
			continue;
		}
		$result = wp_set_object_terms( $id, $series ? array( $series ) : array(), 'series', false );
		if ( is_wp_error( $result ) ) {
			++$skip;
			continue;
		}
		clean_post_cache( $id );
		$moved[] = ner_michoel_shiur_series_row( get_post( $id ) );
	}

	if ( $moved && function_exists( 'ner_michoel_purge_page_caches' ) ) {
		ner_michoel_purge_page_caches();
	}

	return new WP_REST_Response(
		array(
			'moved'   => $moved,
			'skipped' => $skip,
		),
		200
	);
}
