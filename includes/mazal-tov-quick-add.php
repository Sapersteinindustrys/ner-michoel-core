<?php
/**
 * Simplified Mazal Tov quick-add — the full CPT editor (title, content
 * editor, thumbnail, taxonomy metabox, custom-fields metabox) is more
 * screen than posting an announcement needs, since it's really just 4
 * fields: honoree, relationship, type, years. A small form embedded
 * right in the Site Control Panel (post via admin-post, no wp-admin
 * post editor at all) matches how the rest of the panel works.
 *
 * No featured-image field here on purpose — that's the one thing the
 * full editor still does better, and this form is for the common case
 * (text-only announcement), not a replacement for the editor.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ner_michoel_render_mazal_tov_quick_add_page() {
	$types = get_terms( array( 'taxonomy' => 'mazal_tov_type', 'hide_empty' => false ) );
	?>
	<div class="wrap nm-dashboard">
		<h1><?php esc_html_e( 'Post a Mazal Tov', 'ner-michoel-core' ); ?></h1>

		<?php if ( isset( $_GET['nm_mazal_tov'] ) && 'posted' === $_GET['nm_mazal_tov'] ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Posted.', 'ner-michoel-core' ); ?></p></div>
		<?php elseif ( isset( $_GET['nm_mazal_tov'] ) && 'error' === $_GET['nm_mazal_tov'] ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-error"><p><?php esc_html_e( 'Please enter who this is for.', 'ner-michoel-core' ); ?></p></div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:600px;">
			<?php wp_nonce_field( 'nm_mazal_tov_quick_add', 'nm_mazal_tov_quick_add_nonce' ); ?>
			<input type="hidden" name="action" value="nm_mazal_tov_quick_add" />
			<table class="form-table">
				<tr>
					<th scope="row"><label for="nm_mt_honoree"><?php esc_html_e( 'Honoree', 'ner-michoel-core' ); ?></label></th>
					<td><input type="text" id="nm_mt_honoree" name="nm_mt_honoree" class="regular-text" required /></td>
				</tr>
				<tr>
					<th scope="row"><label for="nm_mt_relationship"><?php esc_html_e( 'Relationship', 'ner-michoel-core' ); ?></label></th>
					<td>
						<input type="text" id="nm_mt_relationship" name="nm_mt_relationship" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. "Rabbi & Mrs."', 'ner-michoel-core' ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="nm_mt_type"><?php esc_html_e( 'Type', 'ner-michoel-core' ); ?></label></th>
					<td>
						<select id="nm_mt_type" name="nm_mt_type">
							<option value=""><?php esc_html_e( '— None —', 'ner-michoel-core' ); ?></option>
							<?php foreach ( $types as $term ) : ?>
								<option value="<?php echo esc_attr( $term->term_id ); ?>"><?php echo esc_html( $term->name ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'More types can be added from the Mazal Tov screen\'s Types box.', 'ner-michoel-core' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="nm_mt_years"><?php esc_html_e( 'Years', 'ner-michoel-core' ); ?></label></th>
					<td>
						<input type="text" id="nm_mt_years" name="nm_mt_years" class="regular-text" placeholder="<?php esc_attr_e( "e.g. \"'05, '08\"", 'ner-michoel-core' ); ?>" />
					</td>
				</tr>
			</table>
			<p>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Post Mazal Tov', 'ner-michoel-core' ); ?></button>
			</p>
		</form>

		<p class="description">
			<?php
			printf(
				/* translators: %s: link to the full Mazal Tov editor */
				wp_kses(
					__( 'Need a photo on the announcement? Use the <a href="%s">full Mazal Tov screen</a> instead.', 'ner-michoel-core' ),
					array( 'a' => array( 'href' => array() ) )
				),
				esc_url( admin_url( 'post-new.php?post_type=mazal_tov' ) )
			);
			?>
		</p>
	</div>
	<?php
}

function ner_michoel_handle_mazal_tov_quick_add() {
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'ner-michoel-core' ) );
	}
	check_admin_referer( 'nm_mazal_tov_quick_add', 'nm_mazal_tov_quick_add_nonce' );

	$redirect = admin_url( 'admin.php?page=nm-mazal-tov-quick-add' );

	$honoree      = isset( $_POST['nm_mt_honoree'] ) ? sanitize_text_field( wp_unslash( $_POST['nm_mt_honoree'] ) ) : '';
	$relationship = isset( $_POST['nm_mt_relationship'] ) ? sanitize_text_field( wp_unslash( $_POST['nm_mt_relationship'] ) ) : '';
	$years        = isset( $_POST['nm_mt_years'] ) ? sanitize_text_field( wp_unslash( $_POST['nm_mt_years'] ) ) : '';
	$type_id      = isset( $_POST['nm_mt_type'] ) ? absint( $_POST['nm_mt_type'] ) : 0;

	if ( '' === $honoree ) {
		wp_safe_redirect( esc_url_raw( add_query_arg( 'nm_mazal_tov', 'error', $redirect ) ) );
		exit;
	}

	$post_id = wp_insert_post(
		array(
			'post_type'   => 'mazal_tov',
			'post_title'  => $honoree,
			'post_status' => 'publish',
		),
		true
	);

	if ( is_wp_error( $post_id ) ) {
		wp_safe_redirect( esc_url_raw( add_query_arg( 'nm_mazal_tov', 'error', $redirect ) ) );
		exit;
	}

	if ( '' !== $relationship ) {
		update_post_meta( $post_id, '_mazal_tov_relationship', $relationship );
	}
	if ( '' !== $years ) {
		update_post_meta( $post_id, '_mazal_tov_years', $years );
	}
	if ( $type_id ) {
		wp_set_object_terms( $post_id, array( $type_id ), 'mazal_tov_type' );
	}

	wp_safe_redirect( esc_url_raw( add_query_arg( 'nm_mazal_tov', 'posted', $redirect ) ) );
	exit;
}
add_action( 'admin_post_nm_mazal_tov_quick_add', 'ner_michoel_handle_mazal_tov_quick_add' );
