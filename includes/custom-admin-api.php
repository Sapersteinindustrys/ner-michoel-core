<?php
/**
 * Generic REST CRUD engine behind the custom /admin UI's content
 * screens (custom-admin.php). One schema registry below drives both
 * the REST behavior here AND the form/list rendering in
 * assets/custom-admin-cms.js — a content type is a config entry, not
 * bespoke code, so "add a field" or "add a type" doesn't mean touching
 * the JS.
 *
 * Deliberately its own REST surface (namespace ner-michoel/v1, not the
 * default /wp/v2/<type> controllers) rather than turning on core's
 * REST controllers for every post type/taxonomy — this shapes requests
 * and responses exactly the way the custom UI needs (one request per
 * save, including taxonomy + meta together) instead of fighting core's
 * generic meta/taxonomy REST exposure requirements field by field.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The whole content-type registry. 'kind' is 'post' or 'taxonomy'.
 * 'fields' drives the edit form; a field maps to either a post
 * column ('target': post_title/post_content/post_excerpt/menu_order/
 * status/thumbnail), a post meta key ('meta'), a taxonomy ('taxonomy'),
 * or term meta ('term_meta') — exactly one of those per field.
 */
function ner_michoel_cms_registry() {
	static $registry = null;
	if ( null !== $registry ) {
		return $registry;
	}

	$status_options = array(
		'publish' => __( 'Published', 'ner-michoel-core' ),
		'draft'   => __( 'Draft', 'ner-michoel-core' ),
	);

	$registry = array(
		'shiur'         => array(
			'kind'         => 'post',
			'post_type'    => 'shiur',
			'capability'   => 'edit_posts',
			'label'        => __( 'Shiur', 'ner-michoel-core' ),
			'label_plural' => __( 'Shiurim', 'ner-michoel-core' ),
			'taxonomies'   => array( 'speaker', 'series' ),
			'list_columns' => array(
				array( 'key' => 'title', 'label' => __( 'Title', 'ner-michoel-core' ), 'render' => 'title' ),
				array( 'key' => 'speaker', 'label' => __( 'Speaker', 'ner-michoel-core' ), 'render' => 'taxonomy', 'taxonomy' => 'speaker' ),
				array( 'key' => 'series', 'label' => __( 'Series', 'ner-michoel-core' ), 'render' => 'taxonomy', 'taxonomy' => 'series' ),
				array( 'key' => 'plays', 'label' => __( 'Plays', 'ner-michoel-core' ), 'render' => 'number' ),
				array( 'key' => 'status', 'label' => __( 'Status', 'ner-michoel-core' ), 'render' => 'status' ),
				array( 'key' => 'date', 'label' => __( 'Date', 'ner-michoel-core' ), 'render' => 'date' ),
			),
			'fields'       => array(
				'title'      => array( 'type' => 'text', 'label' => __( 'Title', 'ner-michoel-core' ), 'required' => true, 'target' => 'post_title' ),
				'content'    => array( 'type' => 'textarea', 'label' => __( 'Description', 'ner-michoel-core' ), 'target' => 'post_content', 'rows' => 5 ),
				'speaker'    => array( 'type' => 'taxonomy', 'label' => __( 'Speaker', 'ner-michoel-core' ), 'taxonomy' => 'speaker' ),
				'series'     => array( 'type' => 'taxonomy', 'label' => __( 'Series', 'ner-michoel-core' ), 'taxonomy' => 'series' ),
				'audio'      => array( 'type' => 'media', 'label' => __( 'Audio / Video File', 'ner-michoel-core' ), 'meta' => '_shiur_audio_id' ),
				'vimeo_id'   => array( 'type' => 'text', 'label' => __( 'Vimeo ID (externally-hosted video, no file upload)', 'ner-michoel-core' ), 'meta' => '_shiur_vimeo_id' ),
				'duration'   => array( 'type' => 'text', 'label' => __( 'Duration (mm:ss)', 'ner-michoel-core' ), 'meta' => '_shiur_duration' ),
				'dedication' => array( 'type' => 'textarea', 'label' => __( 'Dedication (optional)', 'ner-michoel-core' ), 'meta' => '_shiur_dedication', 'rows' => 3 ),
				'thumbnail'  => array( 'type' => 'image', 'label' => __( 'Featured Image', 'ner-michoel-core' ), 'target' => 'thumbnail' ),
				'menu_order' => array( 'type' => 'number', 'label' => __( 'Order (within series)', 'ner-michoel-core' ), 'target' => 'menu_order' ),
				'status'     => array( 'type' => 'select', 'label' => __( 'Status', 'ner-michoel-core' ), 'target' => 'status', 'options' => $status_options ),
			),
		),
		'gallery'       => array(
			'kind'         => 'post',
			'post_type'    => 'gallery',
			'capability'   => 'edit_posts',
			'label'        => __( 'Gallery', 'ner-michoel-core' ),
			'label_plural' => __( 'Galleries', 'ner-michoel-core' ),
			'taxonomies'   => array( 'gallery_type' ),
			'list_columns' => array(
				array( 'key' => 'title', 'label' => __( 'Title', 'ner-michoel-core' ), 'render' => 'title' ),
				array( 'key' => 'gallery_type', 'label' => __( 'Type', 'ner-michoel-core' ), 'render' => 'taxonomy', 'taxonomy' => 'gallery_type' ),
				array( 'key' => 'status', 'label' => __( 'Status', 'ner-michoel-core' ), 'render' => 'status' ),
				array( 'key' => 'date', 'label' => __( 'Date', 'ner-michoel-core' ), 'render' => 'date' ),
			),
			'fields'       => array(
				'title'        => array( 'type' => 'text', 'label' => __( 'Title', 'ner-michoel-core' ), 'required' => true, 'target' => 'post_title' ),
				'content'      => array( 'type' => 'textarea', 'label' => __( 'Description', 'ner-michoel-core' ), 'target' => 'post_content', 'rows' => 4 ),
				'gallery_type' => array( 'type' => 'taxonomy', 'label' => __( 'Gallery Type', 'ner-michoel-core' ), 'taxonomy' => 'gallery_type' ),
				'images'       => array( 'type' => 'media_multi', 'label' => __( 'Gallery Images (drag to reorder)', 'ner-michoel-core' ), 'meta' => '_nm_gallery_image_ids' ),
				'video_urls'   => array( 'type' => 'lines', 'label' => __( 'Video URLs — one per line (YouTube/Vimeo; only used when Type is Video)', 'ner-michoel-core' ), 'meta' => '_nm_gallery_video_urls' ),
				'thumbnail'    => array( 'type' => 'image', 'label' => __( 'Cover Image', 'ner-michoel-core' ), 'target' => 'thumbnail' ),
				'status'       => array( 'type' => 'select', 'label' => __( 'Status', 'ner-michoel-core' ), 'target' => 'status', 'options' => $status_options ),
			),
		),
		'mazal_tov'     => array(
			'kind'         => 'post',
			'post_type'    => 'mazal_tov',
			'capability'   => 'edit_posts',
			'label'        => __( 'Mazal Tov', 'ner-michoel-core' ),
			'label_plural' => __( 'Mazal Tov Announcements', 'ner-michoel-core' ),
			'taxonomies'   => array( 'mazal_tov_type' ),
			'list_columns' => array(
				array( 'key' => 'title', 'label' => __( 'Headline', 'ner-michoel-core' ), 'render' => 'title' ),
				array( 'key' => 'mazal_tov_type', 'label' => __( 'Type', 'ner-michoel-core' ), 'render' => 'taxonomy', 'taxonomy' => 'mazal_tov_type' ),
				array( 'key' => 'status', 'label' => __( 'Status', 'ner-michoel-core' ), 'render' => 'status' ),
				array( 'key' => 'date', 'label' => __( 'Date', 'ner-michoel-core' ), 'render' => 'date' ),
			),
			'fields'       => array(
				'title'          => array( 'type' => 'text', 'label' => __( 'Headline', 'ner-michoel-core' ), 'required' => true, 'target' => 'post_title' ),
				'mazal_tov_type' => array( 'type' => 'taxonomy', 'label' => __( 'Type', 'ner-michoel-core' ), 'taxonomy' => 'mazal_tov_type' ),
				'relationship'   => array( 'type' => 'text', 'label' => __( 'Relationship (e.g. "Rabbi & Mrs. Cohen")', 'ner-michoel-core' ), 'meta' => '_mazal_tov_relationship' ),
				'years'          => array( 'type' => 'text', 'label' => __( "Years (e.g. '05, '08)", 'ner-michoel-core' ), 'meta' => '_mazal_tov_years' ),
				'thumbnail'      => array( 'type' => 'image', 'label' => __( 'Photo (optional)', 'ner-michoel-core' ), 'target' => 'thumbnail' ),
				'status'         => array( 'type' => 'select', 'label' => __( 'Status', 'ner-michoel-core' ), 'target' => 'status', 'options' => $status_options ),
			),
		),
		'post_news'     => array(
			'kind'            => 'post',
			'post_type'       => 'post',
			'capability'      => 'edit_posts',
			'label'           => __( 'News Post', 'ner-michoel-core' ),
			'label_plural'    => __( 'News Posts', 'ner-michoel-core' ),
			'fixed_category'  => 'news',
			'list_columns'    => array(
				array( 'key' => 'title', 'label' => __( 'Title', 'ner-michoel-core' ), 'render' => 'title' ),
				array( 'key' => 'status', 'label' => __( 'Status', 'ner-michoel-core' ), 'render' => 'status' ),
				array( 'key' => 'date', 'label' => __( 'Date', 'ner-michoel-core' ), 'render' => 'date' ),
			),
			'fields'          => array(
				'title'     => array( 'type' => 'text', 'label' => __( 'Title', 'ner-michoel-core' ), 'required' => true, 'target' => 'post_title' ),
				'content'   => array( 'type' => 'textarea', 'label' => __( 'Content', 'ner-michoel-core' ), 'target' => 'post_content', 'rows' => 8 ),
				'excerpt'   => array( 'type' => 'textarea', 'label' => __( 'Excerpt (optional)', 'ner-michoel-core' ), 'target' => 'post_excerpt', 'rows' => 2 ),
				'thumbnail' => array( 'type' => 'image', 'label' => __( 'Featured Image', 'ner-michoel-core' ), 'target' => 'thumbnail' ),
				'status'    => array( 'type' => 'select', 'label' => __( 'Status', 'ner-michoel-core' ), 'target' => 'status', 'options' => $status_options ),
			),
		),
		'nm_submission' => array(
			'kind'         => 'post',
			'post_type'    => 'nm_submission',
			'capability'   => 'edit_posts',
			'readonly'     => true,
			'label'        => __( 'Submission', 'ner-michoel-core' ),
			'label_plural' => __( 'Recent Submissions', 'ner-michoel-core' ),
			'list_columns' => array(
				array( 'key' => 'nm_type', 'label' => __( 'Type', 'ner-michoel-core' ), 'render' => 'meta', 'meta' => '_nm_submission_type' ),
				array( 'key' => 'nm_from', 'label' => __( 'From', 'ner-michoel-core' ), 'render' => 'meta', 'meta' => '_nm_submission_name' ),
				array( 'key' => 'nm_message', 'label' => __( 'Message', 'ner-michoel-core' ), 'render' => 'meta_trim', 'meta' => '_nm_submission_message' ),
				array( 'key' => 'date', 'label' => __( 'Date', 'ner-michoel-core' ), 'render' => 'date' ),
			),
			'fields'       => array(
				'type'        => array( 'type' => 'readonly', 'label' => __( 'Type', 'ner-michoel-core' ), 'meta' => '_nm_submission_type' ),
				'name'        => array( 'type' => 'readonly', 'label' => __( 'Name', 'ner-michoel-core' ), 'meta' => '_nm_submission_name' ),
				'email'       => array( 'type' => 'readonly', 'label' => __( 'Email', 'ner-michoel-core' ), 'meta' => '_nm_submission_email' ),
				'phone'       => array( 'type' => 'readonly', 'label' => __( 'Phone', 'ner-michoel-core' ), 'meta' => '_nm_submission_phone' ),
				'speaker'     => array( 'type' => 'readonly', 'label' => __( 'Speaker', 'ner-michoel-core' ), 'meta' => '_nm_submission_speaker' ),
				'shiur_title' => array( 'type' => 'readonly', 'label' => __( 'Re: Shiur', 'ner-michoel-core' ), 'meta' => '_nm_submission_shiur_title' ),
				'message'     => array( 'type' => 'readonly_textarea', 'label' => __( 'Message', 'ner-michoel-core' ), 'meta' => '_nm_submission_message' ),
			),
		),
		'speaker'       => array(
			'kind'         => 'taxonomy',
			'taxonomy'     => 'speaker',
			'capability'   => 'manage_categories',
			'label'        => __( 'Speaker', 'ner-michoel-core' ),
			'label_plural' => __( 'Speakers', 'ner-michoel-core' ),
			'list_columns' => array(
				array( 'key' => 'name', 'label' => __( 'Name', 'ner-michoel-core' ), 'render' => 'title' ),
				array( 'key' => 'email', 'label' => __( 'Email', 'ner-michoel-core' ), 'render' => 'term_meta' ),
				array( 'key' => 'count', 'label' => __( 'Shiurim', 'ner-michoel-core' ), 'render' => 'term_count' ),
			),
			'fields'       => array(
				'name'        => array( 'type' => 'text', 'label' => __( 'Name', 'ner-michoel-core' ), 'required' => true, 'target' => 'name' ),
				'description' => array( 'type' => 'textarea', 'label' => __( 'Bio (optional)', 'ner-michoel-core' ), 'target' => 'description', 'rows' => 4 ),
				'image'       => array( 'type' => 'image', 'label' => __( 'Photo', 'ner-michoel-core' ), 'term_meta' => 'ner_michoel_image_id' ),
				'email'       => array( 'type' => 'email', 'label' => __( 'Forwarding Email (optional — "Email a Magid Shiur" goes here)', 'ner-michoel-core' ), 'term_meta' => '_speaker_email' ),
			),
		),
		'series'        => array(
			'kind'         => 'taxonomy',
			'taxonomy'     => 'series',
			'capability'   => 'manage_categories',
			'hierarchical' => true,
			'label'        => __( 'Series', 'ner-michoel-core' ),
			'label_plural' => __( 'Series', 'ner-michoel-core' ),
			'list_columns' => array(
				array( 'key' => 'name', 'label' => __( 'Name', 'ner-michoel-core' ), 'render' => 'title' ),
				array( 'key' => 'count', 'label' => __( 'Shiurim', 'ner-michoel-core' ), 'render' => 'term_count' ),
			),
			'fields'       => array(
				'name'        => array( 'type' => 'text', 'label' => __( 'Name', 'ner-michoel-core' ), 'required' => true, 'target' => 'name' ),
				'description' => array( 'type' => 'textarea', 'label' => __( 'Description (optional)', 'ner-michoel-core' ), 'target' => 'description', 'rows' => 4 ),
				'parent'      => array( 'type' => 'term_parent', 'label' => __( 'Parent Series (optional)', 'ner-michoel-core' ) ),
				'image'       => array( 'type' => 'image', 'label' => __( 'Cover Image', 'ner-michoel-core' ), 'term_meta' => 'ner_michoel_image_id' ),
			),
		),
	);

	return $registry;
}

function ner_michoel_cms_get_config( $type_key ) {
	$registry = ner_michoel_cms_registry();
	return isset( $registry[ $type_key ] ) ? $registry[ $type_key ] : null;
}

function ner_michoel_cms_permission_check( WP_REST_Request $request ) {
	$config = ner_michoel_cms_get_config( $request->get_param( 'type' ) );
	if ( ! $config ) {
		return new WP_Error( 'nm_cms_unknown_type', __( 'Unknown content type.', 'ner-michoel-core' ), array( 'status' => 404 ) );
	}
	$cap = isset( $config['capability'] ) ? $config['capability'] : 'edit_posts';
	if ( ! current_user_can( $cap ) ) {
		return new WP_Error( 'nm_cms_forbidden', __( "You don't have permission to do that.", 'ner-michoel-core' ), array( 'status' => 403 ) );
	}
	return true;
}

function ner_michoel_register_cms_routes() {
	register_rest_route(
		'ner-michoel/v1',
		'/cms/(?P<type>[a-z_]+)/schema',
		array(
			'methods'             => 'GET',
			'callback'            => 'ner_michoel_cms_route_schema',
			'permission_callback' => 'ner_michoel_cms_permission_check',
		)
	);

	register_rest_route(
		'ner-michoel/v1',
		'/cms/(?P<type>[a-z_]+)',
		array(
			array(
				'methods'             => 'GET',
				'callback'            => 'ner_michoel_cms_route_list',
				'permission_callback' => 'ner_michoel_cms_permission_check',
			),
			array(
				'methods'             => 'POST',
				'callback'            => 'ner_michoel_cms_route_create',
				'permission_callback' => 'ner_michoel_cms_permission_check',
			),
		)
	);

	register_rest_route(
		'ner-michoel/v1',
		'/cms/(?P<type>[a-z_]+)/(?P<id>\d+)',
		array(
			array(
				'methods'             => 'GET',
				'callback'            => 'ner_michoel_cms_route_get',
				'permission_callback' => 'ner_michoel_cms_permission_check',
			),
			array(
				'methods'             => 'POST',
				'callback'            => 'ner_michoel_cms_route_update',
				'permission_callback' => 'ner_michoel_cms_permission_check',
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => 'ner_michoel_cms_route_delete',
				'permission_callback' => 'ner_michoel_cms_permission_check',
			),
		)
	);

	register_rest_route(
		'ner-michoel/v1',
		'/terms/(?P<taxonomy>[a-z_]+)',
		array(
			'methods'             => 'GET',
			'callback'            => 'ner_michoel_cms_route_terms',
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
		)
	);
}
add_action( 'rest_api_init', 'ner_michoel_register_cms_routes' );

function ner_michoel_cms_route_terms( WP_REST_Request $request ) {
	$taxonomy = sanitize_key( $request->get_param( 'taxonomy' ) );
	if ( ! taxonomy_exists( $taxonomy ) ) {
		return new WP_Error( 'nm_cms_bad_taxonomy', __( 'Unknown taxonomy.', 'ner-michoel-core' ), array( 'status' => 404 ) );
	}
	$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
	if ( is_wp_error( $terms ) ) {
		return new WP_REST_Response( array(), 200 );
	}
	$out = array();
	foreach ( $terms as $term ) {
		$out[] = array(
			'id'     => $term->term_id,
			'name'   => $term->name,
			'parent' => $term->parent,
			'count'  => $term->count,
		);
	}
	return new WP_REST_Response( $out, 200 );
}

function ner_michoel_cms_route_schema( WP_REST_Request $request ) {
	$config = ner_michoel_cms_get_config( $request->get_param( 'type' ) );
	return new WP_REST_Response(
		array(
			'label'        => $config['label'],
			'label_plural' => $config['label_plural'],
			'kind'         => $config['kind'],
			'readonly'     => ! empty( $config['readonly'] ),
			'hierarchical' => ! empty( $config['hierarchical'] ),
			'list_columns' => $config['list_columns'],
			'fields'       => $config['fields'],
			'taxonomies'   => isset( $config['taxonomies'] ) ? $config['taxonomies'] : array(),
		),
		200
	);
}

function ner_michoel_cms_route_list( WP_REST_Request $request ) {
	$type_key = $request->get_param( 'type' );
	$config   = ner_michoel_cms_get_config( $type_key );

	if ( 'taxonomy' === $config['kind'] ) {
		return ner_michoel_cms_list_terms( $config, $request );
	}
	return ner_michoel_cms_list_posts( $config, $request );
}

function ner_michoel_cms_list_posts( $config, WP_REST_Request $request ) {
	$paged    = max( 1, absint( $request->get_param( 'page' ) ) );
	$per_page = min( 100, max( 1, absint( $request->get_param( 'per_page' ) ? $request->get_param( 'per_page' ) : 20 ) ) );
	$search   = sanitize_text_field( (string) $request->get_param( 'search' ) );

	$args = array(
		'post_type'      => $config['post_type'],
		'post_status'    => 'nm_submission' === $config['post_type'] ? array( 'publish' ) : array( 'publish', 'draft', 'pending', 'future' ),
		'paged'          => $paged,
		'posts_per_page' => $per_page,
		'orderby'        => 'date',
		'order'          => 'DESC',
	);
	if ( '' !== $search ) {
		$args['s'] = $search;
	}

	if ( ! empty( $config['fixed_category'] ) ) {
		$args['category_name'] = $config['fixed_category'];
	}

	if ( ! empty( $config['taxonomies'] ) ) {
		$tax_query = array();
		foreach ( $config['taxonomies'] as $taxonomy ) {
			$term_id = absint( $request->get_param( $taxonomy ) );
			if ( $term_id ) {
				$tax_query[] = array(
					'taxonomy' => $taxonomy,
					'field'    => 'term_id',
					'terms'    => $term_id,
				);
			}
		}
		if ( $tax_query ) {
			$args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}
	}

	if ( 'shiur' === $config['post_type'] ) {
		if ( '1' === (string) $request->get_param( 'missing_audio' ) ) {
			$args['meta_query'] = ner_michoel_missing_audio_meta_query(); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}
		if ( 'plays' === $request->get_param( 'orderby' ) ) {
			$args['meta_key'] = '_shiur_play_count'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$args['orderby']  = 'meta_value_num';
			$args['order']    = 'DESC';
		}
	}

	$query = new WP_Query( $args );

	$items = array();
	foreach ( $query->posts as $post ) {
		$items[] = ner_michoel_cms_format_post_row( $post, $config );
	}

	return new WP_REST_Response(
		array(
			'items'       => $items,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'page'        => $paged,
		),
		200
	);
}

function ner_michoel_cms_list_terms( $config, WP_REST_Request $request ) {
	$search = sanitize_text_field( (string) $request->get_param( 'search' ) );
	$args   = array(
		'taxonomy'   => $config['taxonomy'],
		'hide_empty' => false,
		'orderby'    => 'name',
	);
	if ( '' !== $search ) {
		$args['search'] = $search;
	}
	$terms = get_terms( $args );
	if ( is_wp_error( $terms ) ) {
		$terms = array();
	}

	$items = array();
	foreach ( $terms as $term ) {
		$row = array(
			'id'     => $term->term_id,
			'title'  => $term->name,
			'name'   => $term->name,
			'count'  => $term->count,
			'parent' => $term->parent,
		);
		foreach ( $config['fields'] as $key => $field ) {
			if ( ! isset( $field['term_meta'] ) ) {
				continue;
			}
			$row[ $key ] = get_term_meta( $term->term_id, $field['term_meta'], true );
		}
		$items[] = $row;
	}

	return new WP_REST_Response(
		array(
			'items'       => $items,
			'total'       => count( $items ),
			'total_pages' => 1,
			'page'        => 1,
		),
		200
	);
}

function ner_michoel_cms_format_post_row( $post, $config ) {
	$row = array(
		'id'     => $post->ID,
		'title'  => $post->post_title ? $post->post_title : __( '(no title)', 'ner-michoel-core' ),
		'status' => $post->post_status,
		'date'   => get_the_date( 'Y-m-d', $post ),
	);

	foreach ( $config['list_columns'] as $col ) {
		if ( isset( $row[ $col['key'] ] ) ) {
			continue;
		}
		switch ( $col['render'] ) {
			case 'taxonomy':
				$terms              = get_the_terms( $post->ID, $col['taxonomy'] );
				$row[ $col['key'] ] = ( $terms && ! is_wp_error( $terms ) ) ? wp_list_pluck( $terms, 'name' ) : array();
				break;
			case 'number':
				$row[ $col['key'] ] = 'plays' === $col['key'] ? ner_michoel_get_shiur_play_count( $post->ID ) : 0;
				break;
			case 'meta':
				$row[ $col['key'] ] = get_post_meta( $post->ID, $col['meta'], true );
				break;
			case 'meta_trim':
				$row[ $col['key'] ] = wp_trim_words( get_post_meta( $post->ID, $col['meta'], true ), 16 );
				break;
		}
	}

	$row['edit_link'] = 'nm_submission' === $config['post_type'] ? '' : get_permalink( $post->ID );
	$row['thumbnail']  = has_post_thumbnail( $post->ID ) ? wp_get_attachment_image_url( get_post_thumbnail_id( $post->ID ), 'thumbnail' ) : '';

	return $row;
}

function ner_michoel_cms_format_post_full( $post, $config ) {
	$data = array(
		'id'         => $post->ID,
		'title'      => $post->post_title,
		'content'    => $post->post_content,
		'excerpt'    => $post->post_excerpt,
		'status'     => $post->post_status,
		'menu_order' => $post->menu_order,
		'thumbnail'  => has_post_thumbnail( $post->ID ) ? array(
			'id'  => (int) get_post_thumbnail_id( $post->ID ),
			'url' => wp_get_attachment_image_url( get_post_thumbnail_id( $post->ID ), 'medium' ),
		) : null,
	);

	foreach ( $config['fields'] as $key => $field ) {
		if ( array_key_exists( $key, $data ) ) {
			continue;
		}
		if ( 'taxonomy' === $field['type'] ) {
			$terms        = get_the_terms( $post->ID, $field['taxonomy'] );
			$data[ $key ] = ( $terms && ! is_wp_error( $terms ) ) ? (int) $terms[0]->term_id : 0;
		} elseif ( isset( $field['meta'] ) ) {
			$val = get_post_meta( $post->ID, $field['meta'], true );
			if ( 'media' === $field['type'] ) {
				$url          = $val ? wp_get_attachment_url( $val ) : '';
				$data[ $key ] = $val ? array(
					'id'       => (int) $val,
					'url'      => $url,
					'filename' => $url ? basename( $url ) : '',
				) : null;
			} elseif ( 'media_multi' === $field['type'] ) {
				$ids = is_array( $val ) ? $val : array();
				$out = array();
				foreach ( $ids as $img_id ) {
					$out[] = array( 'id' => (int) $img_id, 'url' => wp_get_attachment_image_url( $img_id, 'thumbnail' ) );
				}
				$data[ $key ] = $out;
			} elseif ( 'lines' === $field['type'] ) {
				$data[ $key ] = is_array( $val ) ? implode( "\n", $val ) : '';
			} else {
				$data[ $key ] = $val;
			}
		}
	}

	return $data;
}

function ner_michoel_cms_format_term_full( $term, $config ) {
	$data = array(
		'id'          => $term->term_id,
		'name'        => $term->name,
		'description' => $term->description,
		'parent'      => $term->parent,
	);

	foreach ( $config['fields'] as $key => $field ) {
		if ( array_key_exists( $key, $data ) || ! isset( $field['term_meta'] ) ) {
			continue;
		}
		$val = get_term_meta( $term->term_id, $field['term_meta'], true );
		if ( 'image' === $field['type'] ) {
			$data[ $key ] = $val ? array( 'id' => (int) $val, 'url' => wp_get_attachment_image_url( $val, 'thumbnail' ) ) : null;
		} else {
			$data[ $key ] = $val;
		}
	}

	return $data;
}

function ner_michoel_cms_route_get( WP_REST_Request $request ) {
	$config = ner_michoel_cms_get_config( $request->get_param( 'type' ) );
	$id     = absint( $request->get_param( 'id' ) );

	if ( 'taxonomy' === $config['kind'] ) {
		$term = get_term( $id, $config['taxonomy'] );
		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_Error( 'nm_cms_not_found', __( 'Not found.', 'ner-michoel-core' ), array( 'status' => 404 ) );
		}
		return new WP_REST_Response( ner_michoel_cms_format_term_full( $term, $config ), 200 );
	}

	$post = get_post( $id );
	if ( ! $post || $post->post_type !== $config['post_type'] ) {
		return new WP_Error( 'nm_cms_not_found', __( 'Not found.', 'ner-michoel-core' ), array( 'status' => 404 ) );
	}
	return new WP_REST_Response( ner_michoel_cms_format_post_full( $post, $config ), 200 );
}

function ner_michoel_cms_route_create( WP_REST_Request $request ) {
	$type_key = $request->get_param( 'type' );
	$config   = ner_michoel_cms_get_config( $type_key );
	if ( ! empty( $config['readonly'] ) ) {
		return new WP_Error( 'nm_cms_readonly', __( 'This list is read-only.', 'ner-michoel-core' ), array( 'status' => 403 ) );
	}
	return 'taxonomy' === $config['kind']
		? ner_michoel_cms_save_term( 0, $config, $request )
		: ner_michoel_cms_save_post( 0, $config, $request );
}

function ner_michoel_cms_route_update( WP_REST_Request $request ) {
	$config = ner_michoel_cms_get_config( $request->get_param( 'type' ) );
	$id     = absint( $request->get_param( 'id' ) );
	if ( ! empty( $config['readonly'] ) ) {
		return new WP_Error( 'nm_cms_readonly', __( 'This list is read-only.', 'ner-michoel-core' ), array( 'status' => 403 ) );
	}
	return 'taxonomy' === $config['kind']
		? ner_michoel_cms_save_term( $id, $config, $request )
		: ner_michoel_cms_save_post( $id, $config, $request );
}

function ner_michoel_cms_save_post( $id, $config, WP_REST_Request $request ) {
	$body = $request->get_json_params();
	if ( ! is_array( $body ) ) {
		$body = array();
	}

	$postarr = array( 'post_type' => $config['post_type'] );
	if ( $id ) {
		$postarr['ID'] = $id;
	}

	foreach ( $config['fields'] as $key => $field ) {
		if ( ! array_key_exists( $key, $body ) || ! isset( $field['target'] ) ) {
			continue;
		}
		$value = $body[ $key ];
		switch ( $field['target'] ) {
			case 'post_title':
				$postarr['post_title'] = sanitize_text_field( (string) $value );
				break;
			case 'post_content':
				$postarr['post_content'] = wp_kses_post( (string) $value );
				break;
			case 'post_excerpt':
				$postarr['post_excerpt'] = sanitize_textarea_field( (string) $value );
				break;
			case 'menu_order':
				$postarr['menu_order'] = absint( $value );
				break;
			case 'status':
				$allowed                = array_keys( $field['options'] );
				$postarr['post_status'] = in_array( $value, $allowed, true ) ? $value : 'draft';
				break;
		}
	}

	if ( ! $id && empty( $postarr['post_status'] ) ) {
		$postarr['post_status'] = 'draft';
	}
	if ( ! $id && empty( $postarr['post_title'] ) ) {
		return new WP_Error( 'nm_cms_missing_title', __( 'A title is required.', 'ner-michoel-core' ), array( 'status' => 400 ) );
	}

	$post_id = $id ? wp_update_post( $postarr, true ) : wp_insert_post( $postarr, true );
	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}

	if ( ! empty( $config['fixed_category'] ) ) {
		$term = get_term_by( 'slug', $config['fixed_category'], 'category' );
		if ( $term ) {
			wp_set_post_categories( $post_id, array( $term->term_id ) );
		}
	}

	foreach ( $config['fields'] as $key => $field ) {
		if ( ! array_key_exists( $key, $body ) ) {
			continue;
		}
		$value = $body[ $key ];

		if ( 'taxonomy' === $field['type'] ) {
			$term_id = absint( $value );
			wp_set_object_terms( $post_id, $term_id ? array( $term_id ) : array(), $field['taxonomy'] );
		} elseif ( 'media' === $field['type'] && isset( $field['meta'] ) ) {
			$attachment_id = absint( $value );
			if ( $attachment_id ) {
				update_post_meta( $post_id, $field['meta'], $attachment_id );
			} else {
				delete_post_meta( $post_id, $field['meta'] );
			}
		} elseif ( 'media_multi' === $field['type'] && isset( $field['meta'] ) ) {
			$ids = is_array( $value ) ? array_values( array_filter( array_map( 'absint', $value ) ) ) : array();
			update_post_meta( $post_id, $field['meta'], $ids );
		} elseif ( 'lines' === $field['type'] && isset( $field['meta'] ) ) {
			$lines = preg_split( '/\r\n|\r|\n/', (string) $value );
			$urls  = array_values( array_filter( array_map( 'esc_url_raw', array_map( 'trim', $lines ) ) ) );
			update_post_meta( $post_id, $field['meta'], $urls );
		} elseif ( 'image' === $field['type'] && isset( $field['target'] ) && 'thumbnail' === $field['target'] ) {
			$attachment_id = absint( $value );
			if ( $attachment_id ) {
				set_post_thumbnail( $post_id, $attachment_id );
			} else {
				delete_post_thumbnail( $post_id );
			}
		} elseif ( isset( $field['meta'] ) && ! isset( $field['target'] ) ) {
			$sanitized = 'textarea' === $field['type'] ? sanitize_textarea_field( (string) $value ) : sanitize_text_field( (string) $value );
			if ( '' !== $sanitized ) {
				update_post_meta( $post_id, $field['meta'], $sanitized );
			} else {
				delete_post_meta( $post_id, $field['meta'] );
			}
		}
	}

	$post = get_post( $post_id );
	return new WP_REST_Response( ner_michoel_cms_format_post_full( $post, $config ), $id ? 200 : 201 );
}

function ner_michoel_cms_save_term( $id, $config, WP_REST_Request $request ) {
	$body = $request->get_json_params();
	if ( ! is_array( $body ) ) {
		$body = array();
	}

	$name        = isset( $body['name'] ) ? sanitize_text_field( (string) $body['name'] ) : '';
	$description = isset( $body['description'] ) ? sanitize_textarea_field( (string) $body['description'] ) : '';
	$parent      = isset( $body['parent'] ) ? absint( $body['parent'] ) : 0;

	if ( ! $id && '' === $name ) {
		return new WP_Error( 'nm_cms_missing_name', __( 'A name is required.', 'ner-michoel-core' ), array( 'status' => 400 ) );
	}

	$args = array( 'description' => $description );
	if ( ! empty( $config['hierarchical'] ) ) {
		$args['parent'] = ( $parent && $parent !== $id ) ? $parent : 0;
	}

	if ( $id ) {
		if ( $name ) {
			$args['name'] = $name;
		}
		$result  = wp_update_term( $id, $config['taxonomy'], $args );
		$term_id = is_wp_error( $result ) ? 0 : $result['term_id'];
	} else {
		$result  = wp_insert_term( $name, $config['taxonomy'], $args );
		$term_id = is_wp_error( $result ) ? 0 : $result['term_id'];
	}

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	foreach ( $config['fields'] as $key => $field ) {
		if ( ! array_key_exists( $key, $body ) || ! isset( $field['term_meta'] ) ) {
			continue;
		}
		$value = $body[ $key ];
		if ( 'image' === $field['type'] ) {
			$attachment_id = absint( $value );
			if ( $attachment_id ) {
				update_term_meta( $term_id, $field['term_meta'], $attachment_id );
			} else {
				delete_term_meta( $term_id, $field['term_meta'] );
			}
		} elseif ( 'email' === $field['type'] ) {
			$email = sanitize_email( (string) $value );
			if ( $email ) {
				update_term_meta( $term_id, $field['term_meta'], $email );
			} else {
				delete_term_meta( $term_id, $field['term_meta'] );
			}
		} else {
			$sanitized = sanitize_text_field( (string) $value );
			if ( '' !== $sanitized ) {
				update_term_meta( $term_id, $field['term_meta'], $sanitized );
			} else {
				delete_term_meta( $term_id, $field['term_meta'] );
			}
		}
	}

	$term = get_term( $term_id, $config['taxonomy'] );
	return new WP_REST_Response( ner_michoel_cms_format_term_full( $term, $config ), $id ? 200 : 201 );
}

function ner_michoel_cms_route_delete( WP_REST_Request $request ) {
	$config = ner_michoel_cms_get_config( $request->get_param( 'type' ) );
	$id     = absint( $request->get_param( 'id' ) );

	if ( 'taxonomy' === $config['kind'] ) {
		$result = wp_delete_term( $id, $config['taxonomy'] );
		if ( is_wp_error( $result ) || ! $result ) {
			return new WP_Error( 'nm_cms_delete_failed', __( 'Could not delete.', 'ner-michoel-core' ), array( 'status' => 400 ) );
		}
		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	$post = get_post( $id );
	if ( ! $post || $post->post_type !== $config['post_type'] ) {
		return new WP_Error( 'nm_cms_not_found', __( 'Not found.', 'ner-michoel-core' ), array( 'status' => 404 ) );
	}
	$force  = 'nm_submission' === $config['post_type'];
	$result = wp_delete_post( $id, $force );
	if ( ! $result ) {
		return new WP_Error( 'nm_cms_delete_failed', __( 'Could not delete.', 'ner-michoel-core' ), array( 'status' => 400 ) );
	}
	return new WP_REST_Response( array( 'deleted' => true ), 200 );
}
