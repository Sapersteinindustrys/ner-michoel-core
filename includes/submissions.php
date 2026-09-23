<?php
/**
 * Read-only log of Contact / "Email a Magid Shiur" form submissions,
 * so the admin has a record inside the Site Control Panel even if an
 * email bounces or lands in spam — wp_mail() is fire-and-forget with
 * no delivery confirmation. Recorded in ner_michoel_handle_contact_submit()
 * and ner_michoel_handle_email_magid_submit() (forms.php) regardless of
 * whether the send itself succeeded.
 *
 * A private CPT rather than a custom table — it's a handful of records
 * a year for a shul-sized site, and this gets a list screen, trash, and
 * search for free from core WP instead of hand-rolling one.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ner_michoel_register_submission_post_type() {
	register_post_type(
		'nm_submission',
		array(
			'labels'       => array(
				'name'               => __( 'Submissions', 'ner-michoel-core' ),
				'singular_name'      => __( 'Submission', 'ner-michoel-core' ),
				'view_item'          => __( 'View Submission', 'ner-michoel-core' ),
				'search_items'       => __( 'Search Submissions', 'ner-michoel-core' ),
				'not_found'          => __( 'No submissions yet', 'ner-michoel-core' ),
				'not_found_in_trash' => __( 'No submissions found in Trash', 'ner-michoel-core' ),
				'all_items'          => __( 'Submissions', 'ner-michoel-core' ),
				'menu_name'          => __( 'Submissions', 'ner-michoel-core' ),
			),
			'public'       => false,
			'show_ui'      => true,
			'show_in_menu' => false,
			'has_archive'  => false,
			'rewrite'      => false,
			'query_var'    => false,
			'supports'     => array( 'title' ),
			'capabilities' => array(
				'create_posts' => 'do_not_allow',
			),
			'map_meta_cap' => true,
		)
	);
}
add_action( 'init', 'ner_michoel_register_submission_post_type' );

/**
 * Records one submission. $type is 'contact' or 'magid'. $data keys:
 * name, email, phone (optional), message, speaker (magid only, the
 * speaker term's display name — stored as a plain string snapshot so
 * the record still reads correctly if that speaker is later renamed
 * or deleted).
 */
function ner_michoel_record_submission( $type, array $data ) {
	$name = isset( $data['name'] ) ? $data['name'] : '';

	if ( 'magid' === $type && ! empty( $data['speaker'] ) ) {
		$title = sprintf(
			/* translators: 1: sender name, 2: speaker name */
			__( 'Magid Shiur request: %1$s → %2$s', 'ner-michoel-core' ),
			$name,
			$data['speaker']
		);
	} else {
		$title = sprintf(
			/* translators: %s: sender name */
			__( 'Contact form: %s', 'ner-michoel-core' ),
			$name
		);
	}

	$post_id = wp_insert_post(
		array(
			'post_type'   => 'nm_submission',
			'post_title'  => $title,
			'post_status' => 'publish',
		),
		true
	);

	if ( is_wp_error( $post_id ) ) {
		return;
	}

	update_post_meta( $post_id, '_nm_submission_type', sanitize_key( $type ) );
	update_post_meta( $post_id, '_nm_submission_name', sanitize_text_field( $name ) );
	update_post_meta( $post_id, '_nm_submission_email', isset( $data['email'] ) ? sanitize_email( $data['email'] ) : '' );
	update_post_meta( $post_id, '_nm_submission_phone', isset( $data['phone'] ) ? sanitize_text_field( $data['phone'] ) : '' );
	update_post_meta( $post_id, '_nm_submission_message', isset( $data['message'] ) ? sanitize_textarea_field( $data['message'] ) : '' );
	if ( ! empty( $data['speaker'] ) ) {
		update_post_meta( $post_id, '_nm_submission_speaker', sanitize_text_field( $data['speaker'] ) );
	}
}

/**
 * List-screen columns: swap the default Title for a purpose-built set
 * so the admin can scan a submission without opening it.
 */
function ner_michoel_submission_columns( $columns ) {
	unset( $columns['title'], $columns['date'] );
	return array_merge(
		$columns,
		array(
			'nm_type'    => __( 'Type', 'ner-michoel-core' ),
			'nm_from'    => __( 'From', 'ner-michoel-core' ),
			'nm_message' => __( 'Message', 'ner-michoel-core' ),
			'date'       => __( 'Date', 'ner-michoel-core' ),
		)
	);
}
add_filter( 'manage_nm_submission_posts_columns', 'ner_michoel_submission_columns' );

function ner_michoel_submission_column_content( $column, $post_id ) {
	switch ( $column ) {
		case 'nm_type':
			$type = get_post_meta( $post_id, '_nm_submission_type', true );
			echo 'magid' === $type
				? esc_html__( 'Email a Magid Shiur', 'ner-michoel-core' )
				: esc_html__( 'Contact', 'ner-michoel-core' );
			if ( 'magid' === $type ) {
				$speaker = get_post_meta( $post_id, '_nm_submission_speaker', true );
				if ( $speaker ) {
					echo '<br /><span class="description">' . esc_html( $speaker ) . '</span>';
				}
			}
			break;

		case 'nm_from':
			$name  = get_post_meta( $post_id, '_nm_submission_name', true );
			$email = get_post_meta( $post_id, '_nm_submission_email', true );
			$phone = get_post_meta( $post_id, '_nm_submission_phone', true );
			echo esc_html( $name );
			if ( $email ) {
				echo '<br /><a href="' . esc_url( 'mailto:' . $email ) . '">' . esc_html( $email ) . '</a>';
			}
			if ( $phone ) {
				echo '<br />' . esc_html( $phone );
			}
			break;

		case 'nm_message':
			$message = get_post_meta( $post_id, '_nm_submission_message', true );
			echo esc_html( wp_trim_words( $message, 20 ) );
			break;
	}
}
add_action( 'manage_nm_submission_posts_custom_column', 'ner_michoel_submission_column_content', 10, 2 );

function ner_michoel_submission_sortable_columns( $columns ) {
	$columns['nm_type'] = 'nm_type';
	return $columns;
}
add_filter( 'manage_edit-nm_submission_sortable_columns', 'ner_michoel_submission_sortable_columns' );
