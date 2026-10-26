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
	$types   = get_terms( array( 'taxonomy' => 'mazal_tov_type', 'hide_empty' => false ) );
	$presets = function_exists( 'ner_michoel_get_mazal_tov_presets' ) ? ner_michoel_get_mazal_tov_presets() : array();
	?>
	<div class="wrap nm-dashboard">
		<h1><?php esc_html_e( 'Post a Mazal Tov', 'ner-michoel-core' ); ?></h1>

		<?php if ( isset( $_GET['nm_mazal_tov'] ) && 'posted' === $_GET['nm_mazal_tov'] ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Posted.', 'ner-michoel-core' ); ?></p></div>
		<?php elseif ( isset( $_GET['nm_mazal_tov'] ) && 'error' === $_GET['nm_mazal_tov'] ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-error"><p><?php esc_html_e( 'Please enter who this is for (Name) and what the Mazal Tov is.', 'ner-michoel-core' ); ?></p></div>
		<?php elseif ( isset( $_GET['nm_mazal_tov'] ) && 'blank' === $_GET['nm_mazal_tov'] ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-error"><p><?php esc_html_e( 'Nothing was posted: the sentence still has a blank (___). Fill in every blank and post again.', 'ner-michoel-core' ); ?></p></div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:600px;">
			<?php wp_nonce_field( 'nm_mazal_tov_quick_add', 'nm_mazal_tov_quick_add_nonce' ); ?>
			<input type="hidden" name="action" value="nm_mazal_tov_quick_add" />
			<?php ner_michoel_panel_form_field(); ?>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="nm_mt_preset"><?php esc_html_e( 'Preset', 'ner-michoel-core' ); ?></label></th>
					<td>
						<?php // Drawn by assets/mazal-tov-presets.js: the menu of presets, and the sentence with a box in each blank. It writes the Honoree box and picks the Type. ?>
						<div
							data-nm-mt-fill
							data-presets="<?php echo esc_attr( wp_json_encode( $presets ) ); ?>"
							data-headline="#nm_mt_what"
							data-name-slot="1"
							data-type="#nm_mt_type"
							data-select-id="nm_mt_preset"
							data-manage-url="<?php echo esc_url( function_exists( 'ner_michoel_mazal_tov_presets_url' ) ? ner_michoel_mazal_tov_presets_url() : '' ); ?>"
						></div>
						<p class="description"><?php esc_html_e( 'Optional: pick a ready-made sentence. The Name above is put in its first blank, and you fill in any other blank.', 'ner-michoel-core' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="nm_mt_name"><?php esc_html_e( 'Name', 'ner-michoel-core' ); ?></label></th>
					<td>
						<input type="text" id="nm_mt_name" name="nm_mt_name" class="regular-text" required />
						<p class="description"><?php esc_html_e( 'Who the Mazal Tov is for, like Moshe Cohen. His years are put in brackets after it.', 'ner-michoel-core' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="nm_mt_years"><?php esc_html_e( 'Years', 'ner-michoel-core' ); ?></label></th>
					<td>
						<input type="text" id="nm_mt_years" name="nm_mt_years" class="regular-text" placeholder="<?php esc_attr_e( "e.g. \"'05, '08\"", 'ner-michoel-core' ); ?>" />
						<?php // Drawn by assets/years-list.js: if the name above is in the Years List, the years fill in from it. ?>
						<?php $years_cfg = ner_michoel_years_script_config(); ?>
						<div
							data-nm-years-lookup
							data-source="#nm_mt_name"
							data-target="#nm_mt_years"
							data-url="<?php echo esc_url( $years_cfg['url'] ); ?>"
							data-nonce="<?php echo esc_attr( $years_cfg['nonce'] ); ?>"
							data-manage-url="<?php echo esc_url( $years_cfg['manageUrl'] ); ?>"
							data-can-manage="<?php echo $years_cfg['canManage'] ? '1' : '0'; ?>"
						></div>
						<p class="description">
							<?php esc_html_e( 'Filled in from the Years List when the name above is in it. You can change it.', 'ner-michoel-core' ); ?>
							<?php if ( $years_cfg['canManage'] ) : ?>
								<a href="<?php echo esc_url( $years_cfg['manageUrl'] ); ?>"><?php esc_html_e( 'Upload or update the list', 'ner-michoel-core' ); ?></a>
							<?php endif; ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="nm_mt_what"><?php esc_html_e( 'What the Mazal Tov is', 'ner-michoel-core' ); ?></label></th>
					<td>
						<input type="text" id="nm_mt_what" name="nm_mt_what" class="large-text" required placeholder="<?php esc_attr_e( 'e.g. on his engagement to Sarah Levy', 'ner-michoel-core' ); ?>" />
						<p class="description"><?php esc_html_e( 'With a preset, it is written for you. Without one, write it to follow the name (the announcement reads “Name (years) …this…”), or put {name} where the name should go.', 'ner-michoel-core' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="nm_mt_relationship"><?php esc_html_e( 'Relationship', 'ner-michoel-core' ); ?></label></th>
					<td>
						<input type="text" id="nm_mt_relationship" name="nm_mt_relationship" class="regular-text" placeholder="<?php esc_attr_e( 'Optional, e.g. "Rabbi & Mrs."', 'ner-michoel-core' ); ?>" />
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
				esc_url( ner_michoel_is_custom_admin_render() ? ner_michoel_custom_admin_url( 'mazaltov', array( 'new' => 1 ) ) : admin_url( 'post-new.php?post_type=mazal_tov' ) )
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

	// Back to the Site Control Panel when the form was posted from there.
	$redirect = ner_michoel_panel_redirect_url( admin_url( 'admin.php?page=nm-mazal-tov-quick-add' ), 'mazaltovadd' );

	$name         = isset( $_POST['nm_mt_name'] ) ? sanitize_text_field( wp_unslash( $_POST['nm_mt_name'] ) ) : '';
	$what         = isset( $_POST['nm_mt_what'] ) ? sanitize_text_field( wp_unslash( $_POST['nm_mt_what'] ) ) : '';
	$relationship = isset( $_POST['nm_mt_relationship'] ) ? sanitize_text_field( wp_unslash( $_POST['nm_mt_relationship'] ) ) : '';
	$years        = isset( $_POST['nm_mt_years'] ) ? sanitize_text_field( wp_unslash( $_POST['nm_mt_years'] ) ) : '';
	$type_id      = isset( $_POST['nm_mt_type'] ) ? absint( $_POST['nm_mt_type'] ) : 0;

	if ( '' === $name || '' === $what ) {
		wp_safe_redirect( esc_url_raw( add_query_arg( 'nm_mazal_tov', 'error', $redirect ) ) );
		exit;
	}

	// A sentence written from a preset must have every blank filled in (includes/mazal-tov-presets.php).
	if ( function_exists( 'ner_michoel_mazal_tov_has_blank' ) && ner_michoel_mazal_tov_has_blank( $name . ' ' . $what ) ) {
		wp_safe_redirect( esc_url_raw( add_query_arg( 'nm_mazal_tov', 'blank', $redirect ) ) );
		exit;
	}

	$post_id = wp_insert_post(
		array(
			'post_type'   => 'mazal_tov',
			'post_title'  => ner_michoel_mazal_tov_compose_title( $name, $years, $what, $relationship ),
			'post_status' => 'publish',
		),
		true
	);

	if ( is_wp_error( $post_id ) ) {
		wp_safe_redirect( esc_url_raw( add_query_arg( 'nm_mazal_tov', 'error', $redirect ) ) );
		exit;
	}

	// The name, years, what it is and relationship are kept apart as well, so the announcement can be edited (includes/mazal-tov.php).
	ner_michoel_mazal_tov_save_parts(
		$post_id,
		array(
			'name'         => $name,
			'years'        => $years,
			'what'         => $what,
			'relationship' => $relationship,
		)
	);
	if ( $type_id ) {
		wp_set_object_terms( $post_id, array( $type_id ), 'mazal_tov_type' );
	}

	wp_safe_redirect( esc_url_raw( add_query_arg( 'nm_mazal_tov', 'posted', $redirect ) ) );
	exit;
}
add_action( 'admin_post_nm_mazal_tov_quick_add', 'ner_michoel_handle_mazal_tov_quick_add' );
