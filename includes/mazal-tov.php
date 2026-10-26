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

/**
 * How an announcement reads: the person's name with his years in brackets, then
 * what the Mazal Tov is.
 *
 *     Moshe Cohen ('95) on his engagement to Sarah Levy
 *
 * The parts are kept as post meta (_mazal_tov_name, _mazal_tov_years,
 * _mazal_tov_what, and the older _mazal_tov_relationship, "Rabbi & Mrs.", which
 * goes before the name) so an announcement can be edited, and the finished
 * sentence is also kept as the post title, so everything that prints a title
 * (search, feeds, the REST API) reads the same. An announcement made before the
 * parts existed has no _mazal_tov_name; its title is the name (or a whole
 * sentence) and its years are put in brackets after it.
 */
function ner_michoel_mazal_tov_lead( $name, $years, $relationship = '' ) {
	$lead  = trim( trim( (string) $relationship ) . ' ' . trim( (string) $name ) );
	$years = trim( (string) $years );
	if ( '' !== $lead && '' !== $years ) {
		$lead .= ' (' . $years . ')';
	}
	return $lead;
}

/**
 * The place in a stored "what" where the person's name goes is written {name}, for
 * example "Mazel tov to Rabbi & Mrs. {name} on the birth of a baby boy!". Without it, the
 * name (and his years) go in front of the "what", as in "Moshe Cohen ('95) on his engagement".
 */
function ner_michoel_mazal_tov_name_regex() {
	return '/\{\s*name\s*\}/i';
}

/**
 * A preset's wording as the sentence kept on an announcement: the blank where the person's
 * name goes becomes {name}. That blank is the one labeled {name} if there is one, otherwise
 * the first blank. Any other blank stays, for the admin to fill in before it is posted. A
 * wording with no blanks comes back as it is (the name is then put in front of it).
 * Mirrors nameSlot() in assets/mazal-tov-presets.js.
 */
function ner_michoel_mazal_tov_wording_template( $wording ) {
	$wording = (string) $wording;
	if ( ! preg_match_all( '/\{([^{}]{0,60})\}|_{2,}/', $wording, $found, PREG_OFFSET_CAPTURE ) || empty( $found[0] ) ) {
		return $wording;
	}
	$slot = 0;
	foreach ( $found[0] as $i => $hit ) {
		$label = isset( $found[1][ $i ][0] ) ? strtolower( trim( preg_replace( '/\s+/', ' ', $found[1][ $i ][0] ) ) ) : '';
		if ( 'name' === $label ) {
			$slot = $i;
			break;
		}
	}
	return substr_replace( $wording, '{name}', $found[0][ $slot ][1], strlen( $found[0][ $slot ][0] ) );
}

/** A stored "what" cut at the place for the name: array( the words before it, the words after it, whether it has one ). */
function ner_michoel_mazal_tov_split_what( $what ) {
	$what = trim( (string) $what );
	if ( ! preg_match( ner_michoel_mazal_tov_name_regex(), $what, $hit, PREG_OFFSET_CAPTURE ) ) {
		return array( '', $what, false );
	}
	$before = trim( substr( $what, 0, $hit[0][1] ) );
	$after  = trim( substr( $what, $hit[0][1] + strlen( $hit[0][0] ) ) );
	return array( $before, $after, true );
}

/** Every {name} in $words replaced by $lead, as typed (a $1 or a backslash in a name is not a pattern). */
function ner_michoel_mazal_tov_put_name( $words, $lead ) {
	return preg_replace_callback(
		ner_michoel_mazal_tov_name_regex(),
		function () use ( $lead ) {
			return $lead;
		},
		$words
	);
}

/** Words put together with one space, none before punctuation that follows ("{name}!"). */
function ner_michoel_mazal_tov_join( $a, $b ) {
	if ( '' === $a || '' === $b ) {
		return $a . $b;
	}
	return preg_match( '/^[.,;:!?)\]”"\']/u', $b ) ? $a . $b : $a . ' ' . $b;
}

/** The finished sentence (the post title) from its parts. */
function ner_michoel_mazal_tov_compose_title( $name, $years, $what, $relationship = '' ) {
	$lead = ner_michoel_mazal_tov_lead( $name, $years, $relationship );
	list( $before, $after, $has_place ) = ner_michoel_mazal_tov_split_what( $what );
	if ( $has_place ) {
		// A second {name} in the same sentence says the name again.
		$after = ner_michoel_mazal_tov_put_name( $after, $lead );
	}
	return trim( preg_replace( '/\s+/', ' ', ner_michoel_mazal_tov_join( ner_michoel_mazal_tov_join( $before, $lead ), $after ) ) );
}

/**
 * The pieces the pages print, in reading order: 'before' (words ahead of the name, like
 * "Mazel tov to Rabbi & Mrs."), 'lead' (the name with his years, shown bold) and 'after'
 * (what the Mazal Tov is). 'rest' is 'after' (and 'before' when there is one) joined, for a
 * theme that only knows lead and rest. Plain text: the caller escapes it. No date is part
 * of it on purpose: an announcement is not shown with when it was posted.
 */
function ner_michoel_mazal_tov_parts( $post ) {
	$post = get_post( $post );
	if ( ! $post ) {
		return array( 'before' => '', 'lead' => '', 'after' => '', 'rest' => '' );
	}
	$name         = (string) get_post_meta( $post->ID, '_mazal_tov_name', true );
	$years        = ner_michoel_get_mazal_tov_years( $post->ID );
	$relationship = ner_michoel_get_mazal_tov_relationship( $post->ID );
	if ( '' === trim( $name ) ) {
		return array(
			'before' => '',
			'lead'   => ner_michoel_mazal_tov_lead( get_the_title( $post ), $years, $relationship ),
			'after'  => '',
			'rest'   => '',
		);
	}
	$lead = ner_michoel_mazal_tov_lead( $name, $years, $relationship );
	list( $before, $after, $has_place ) = ner_michoel_mazal_tov_split_what( get_post_meta( $post->ID, '_mazal_tov_what', true ) );
	if ( $has_place ) {
		$after = ner_michoel_mazal_tov_put_name( $after, $lead );
	}
	return array(
		'before' => $before,
		'lead'   => $lead,
		'after'  => $after,
		'rest'   => trim( $before . ' ' . $after ),
	);
}

/**
 * Writes an announcement's parts and its finished title. $parts: name, years, what,
 * relationship (any may be ''). Meta that is empty is removed. Returns the title.
 */
function ner_michoel_mazal_tov_save_parts( $post_id, array $parts ) {
	$map = array(
		'name'         => '_mazal_tov_name',
		'years'        => '_mazal_tov_years',
		'what'         => '_mazal_tov_what',
		'relationship' => '_mazal_tov_relationship',
	);
	$clean = array();
	foreach ( $map as $key => $meta_key ) {
		$clean[ $key ] = isset( $parts[ $key ] ) ? sanitize_text_field( (string) $parts[ $key ] ) : '';
		if ( '' !== $clean[ $key ] ) {
			update_post_meta( $post_id, $meta_key, $clean[ $key ] );
		} else {
			delete_post_meta( $post_id, $meta_key );
		}
	}
	$title = ner_michoel_mazal_tov_compose_title( $clean['name'], $clean['years'], $clean['what'], $clean['relationship'] );
	if ( get_post_field( 'post_title', $post_id ) !== $title ) {
		wp_update_post( array( 'ID' => $post_id, 'post_title' => $title ) );
	}
	return $title;
}

/**
 * For the panel's Add New / Edit form (includes/custom-admin-api.php, 'title_from'):
 * the title from what was sent, filling anything the request leaves out from what is
 * stored. A WP_Error when the name or the "what" is missing or still has a blank.
 */
function ner_michoel_mazal_tov_cms_title( array $body, $id ) {
	// A request that touches none of the parts (a status change) leaves the title as it is.
	if ( $id && ! array_intersect( array( 'name', 'years', 'what', 'relationship' ), array_keys( $body ) ) ) {
		return (string) get_post_field( 'post_title', $id );
	}
	// An announcement made before the parts existed has no stored name: it may be saved without a "what".
	$legacy = $id && '' === trim( (string) get_post_meta( $id, '_mazal_tov_name', true ) );
	$pick   = function ( $key, $meta_key ) use ( $body, $id ) {
		if ( array_key_exists( $key, $body ) ) {
			return sanitize_text_field( (string) $body[ $key ] );
		}
		return $id ? (string) get_post_meta( $id, $meta_key, true ) : '';
	};
	$name = $pick( 'name', '_mazal_tov_name' );
	$what = $pick( 'what', '_mazal_tov_what' );
	if ( '' === trim( $name ) ) {
		return new WP_Error( 'nm_cms_missing_field', __( '“Name” is required.', 'ner-michoel-core' ), array( 'status' => 400 ) );
	}
	if ( '' === trim( $what ) && ! $legacy ) {
		return new WP_Error( 'nm_cms_missing_field', __( '“What the Mazal Tov is” is required.', 'ner-michoel-core' ), array( 'status' => 400 ) );
	}
	if ( function_exists( 'ner_michoel_mazal_tov_has_blank' ) && ( ner_michoel_mazal_tov_has_blank( $what ) || ner_michoel_mazal_tov_has_blank( $name ) ) ) {
		return new WP_Error( 'nm_cms_unfilled_blank', __( 'The sentence still has a blank (___) to fill in.', 'ner-michoel-core' ), array( 'status' => 400 ) );
	}
	return ner_michoel_mazal_tov_compose_title( $name, $pick( 'years', '_mazal_tov_years' ), $what, $pick( 'relationship', '_mazal_tov_relationship' ) );
}
