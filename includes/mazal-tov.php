<?php
/**
 * Mazal Tov post type — engagement/birth/marriage/etc. announcements
 * shown alongside News on the site's News & Events page. The post's
 * own date field is the announcement date; no separate date meta.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ner_michoel_register_mazal_tov_post_type() {
	register_post_type(
		'mazal_tov',
		array(
			'labels'        => array(
				'name'               => __( 'Mazal Tov Announcements', 'ner-michoel-core' ),
				'singular_name'      => __( 'Mazal Tov', 'ner-michoel-core' ),
				'add_new_item'       => __( 'Add New Mazal Tov', 'ner-michoel-core' ),
				'edit_item'          => __( 'Edit Mazal Tov', 'ner-michoel-core' ),
				'view_item'          => __( 'View Mazal Tov', 'ner-michoel-core' ),
				'search_items'       => __( 'Search Mazal Tov Announcements', 'ner-michoel-core' ),
				'not_found'          => __( 'No announcements found', 'ner-michoel-core' ),
				'not_found_in_trash' => __( 'No announcements found in Trash', 'ner-michoel-core' ),
				'all_items'          => __( 'All Mazal Tov Announcements', 'ner-michoel-core' ),
				'menu_name'          => __( 'Mazal Tov', 'ner-michoel-core' ),
			),
			'public'        => true,
			'has_archive'   => 'mazal-tov',
			'rewrite'       => array( 'slug' => 'mazal-tov', 'with_front' => false ),
			'menu_icon'     => 'dashicons-awards',
			'supports'      => array( 'title', 'thumbnail' ),
			'show_in_rest'  => true,
			'menu_position' => 22,
		)
	);
}
add_action( 'init', 'ner_michoel_register_mazal_tov_post_type' );

function ner_michoel_register_mazal_tov_taxonomy() {
	register_taxonomy(
		'mazal_tov_type',
		'mazal_tov',
		array(
			'labels'            => array(
				'name'          => __( 'Types', 'ner-michoel-core' ),
				'singular_name' => __( 'Type', 'ner-michoel-core' ),
				'add_new_item'  => __( 'Add New Type', 'ner-michoel-core' ),
				'edit_item'     => __( 'Edit Type', 'ner-michoel-core' ),
				'search_items'  => __( 'Search Types', 'ner-michoel-core' ),
				'all_items'     => __( 'All Types', 'ner-michoel-core' ),
			),
			'hierarchical'      => false,
			'public'            => true,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'rewrite'           => array( 'slug' => 'mazal-tov-type', 'with_front' => false ),
		)
	);
}
add_action( 'init', 'ner_michoel_register_mazal_tov_taxonomy' );

/**
 * Seeds the common announcement types so the type filter isn't empty
 * on a fresh install. Safe to call repeatedly.
 */
function ner_michoel_create_default_mazal_tov_types() {
	$defaults = array( 'Engagement', 'Birth', 'Marriage', 'Bar Mitzvah' );
	foreach ( $defaults as $name ) {
		if ( ! term_exists( $name, 'mazal_tov_type' ) ) {
			wp_insert_term( $name, 'mazal_tov_type' );
		}
	}
}

/**
 * Admin meta box: the relationship line and years, e.g.
 * "Rabbi & Mrs. Cohen" / "'05, '08" — free text since these read as
 * prose, not structured data.
 */
function ner_michoel_add_mazal_tov_meta_box() {
	add_meta_box(
		'ner_michoel_mazal_tov_details',
		__( 'Announcement Details', 'ner-michoel-core' ),
		'ner_michoel_render_mazal_tov_meta_box',
		'mazal_tov',
		'side',
		'high'
	);
}
add_action( 'add_meta_boxes', 'ner_michoel_add_mazal_tov_meta_box' );

function ner_michoel_render_mazal_tov_meta_box( $post ) {
	wp_nonce_field( 'ner_michoel_save_mazal_tov_meta', 'ner_michoel_mazal_tov_meta_nonce' );
	$relationship = get_post_meta( $post->ID, '_mazal_tov_relationship', true );
	$years        = get_post_meta( $post->ID, '_mazal_tov_years', true );
	?>
	<p>
		<label for="ner_michoel_mazal_tov_relationship"><?php esc_html_e( 'Relationship (e.g. "Rabbi & Mrs.")', 'ner-michoel-core' ); ?></label><br />
		<input type="text" id="ner_michoel_mazal_tov_relationship" name="ner_michoel_mazal_tov_relationship" value="<?php echo esc_attr( $relationship ); ?>" style="width:100%;" />
	</p>
	<p>
		<label for="ner_michoel_mazal_tov_years"><?php esc_html_e( 'Years (e.g. "\'05, \'08")', 'ner-michoel-core' ); ?></label><br />
		<input type="text" id="ner_michoel_mazal_tov_years" name="ner_michoel_mazal_tov_years" value="<?php echo esc_attr( $years ); ?>" style="width:100%;" />
	</p>
	<?php
}

function ner_michoel_save_mazal_tov_meta( $post_id ) {
	if ( ! isset( $_POST['ner_michoel_mazal_tov_meta_nonce'] ) ||
		! wp_verify_nonce( $_POST['ner_michoel_mazal_tov_meta_nonce'], 'ner_michoel_save_mazal_tov_meta' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	if ( isset( $_POST['ner_michoel_mazal_tov_relationship'] ) ) {
		update_post_meta( $post_id, '_mazal_tov_relationship', sanitize_text_field( wp_unslash( $_POST['ner_michoel_mazal_tov_relationship'] ) ) );
	}
	if ( isset( $_POST['ner_michoel_mazal_tov_years'] ) ) {
		update_post_meta( $post_id, '_mazal_tov_years', sanitize_text_field( wp_unslash( $_POST['ner_michoel_mazal_tov_years'] ) ) );
	}
}
add_action( 'save_post_mazal_tov', 'ner_michoel_save_mazal_tov_meta' );

/**
 * Front-end accessors.
 */
function ner_michoel_get_mazal_tov_relationship( $post_id ) {
	return sanitize_text_field( get_post_meta( $post_id, '_mazal_tov_relationship', true ) );
}

function ner_michoel_get_mazal_tov_years( $post_id ) {
	return sanitize_text_field( get_post_meta( $post_id, '_mazal_tov_years', true ) );
}
