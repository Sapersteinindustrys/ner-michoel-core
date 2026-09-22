<?php
/**
 * Custom post types & taxonomies (e.g. staff, events, programs) get
 * registered here via register_post_type() / register_taxonomy(),
 * hooked to 'init'.
 *
 * Shiur (plural: Shiurim) — a single audio lecture. Grouped by:
 * - `speaker`: who is giving the shiur (acts like a streaming "Artist").
 * - `series`:  the course/topic it belongs to (acts like an "Album").
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ner_michoel_register_shiur_post_type() {
	register_post_type(
		'shiur',
		array(
			'labels'       => array(
				'name'                  => __( 'Shiurim', 'ner-michoel-core' ),
				'singular_name'         => __( 'Shiur', 'ner-michoel-core' ),
				'add_new_item'          => __( 'Add New Shiur', 'ner-michoel-core' ),
				'edit_item'             => __( 'Edit Shiur', 'ner-michoel-core' ),
				'new_item'              => __( 'New Shiur', 'ner-michoel-core' ),
				'view_item'             => __( 'View Shiur', 'ner-michoel-core' ),
				'search_items'          => __( 'Search Shiurim', 'ner-michoel-core' ),
				'not_found'             => __( 'No shiurim found', 'ner-michoel-core' ),
				'not_found_in_trash'    => __( 'No shiurim found in Trash', 'ner-michoel-core' ),
				'all_items'             => __( 'All Shiurim', 'ner-michoel-core' ),
				'menu_name'             => __( 'Shiurim', 'ner-michoel-core' ),
			),
			'public'       => true,
			'has_archive'  => 'shiurim',
			'rewrite'      => array( 'slug' => 'shiurim', 'with_front' => false ),
			'menu_icon'    => 'dashicons-format-audio',
			'supports'     => array( 'title', 'editor', 'excerpt', 'thumbnail', 'page-attributes' ),
			'show_in_rest' => true,
			'menu_position' => 20,
		)
	);
}
add_action( 'init', 'ner_michoel_register_shiur_post_type' );

function ner_michoel_register_shiur_taxonomies() {
	register_taxonomy(
		'speaker',
		'shiur',
		array(
			'labels'            => array(
				'name'          => __( 'Speakers', 'ner-michoel-core' ),
				'singular_name' => __( 'Speaker', 'ner-michoel-core' ),
				'add_new_item'  => __( 'Add New Speaker', 'ner-michoel-core' ),
				'edit_item'     => __( 'Edit Speaker', 'ner-michoel-core' ),
				'search_items'  => __( 'Search Speakers', 'ner-michoel-core' ),
				'all_items'     => __( 'All Speakers', 'ner-michoel-core' ),
			),
			'hierarchical'      => false,
			'public'            => true,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'rewrite'           => array( 'slug' => 'speaker', 'with_front' => false ),
		)
	);

	register_taxonomy(
		'series',
		'shiur',
		array(
			'labels'            => array(
				'name'          => __( 'Series', 'ner-michoel-core' ),
				'singular_name' => __( 'Series', 'ner-michoel-core' ),
				'add_new_item'  => __( 'Add New Series', 'ner-michoel-core' ),
				'edit_item'     => __( 'Edit Series', 'ner-michoel-core' ),
				'search_items'  => __( 'Search Series', 'ner-michoel-core' ),
				'all_items'     => __( 'All Series', 'ner-michoel-core' ),
			),
			'hierarchical'      => true,
			'public'            => true,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'rewrite'           => array( 'slug' => 'series', 'with_front' => false ),
		)
	);
}
add_action( 'init', 'ner_michoel_register_shiur_taxonomies' );
