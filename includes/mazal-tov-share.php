<?php
/**
 * Visitors share a Mazal Tov; the admin looks it over before anyone sees it.
 *
 * The public form (drawn by the theme, template-parts/mazal-tov-share.php) posts
 * to admin-post.php?action=nm_mazal_tov_share. Nothing a visitor sends is
 * published: it is saved as a Mazal Tov with the status "pending", which no public
 * page lists, and the site's admin address gets an email saying so.
 *
 * The visitor types the person's name, picks the kind of simcha and says what it is.
 * Nobody types the years: they are looked up in the Years List (includes/years-list.php)
 * from the name, here when it arrives and again on the review screen. The headline is
 * built from the parts exactly as for a Mazal Tov posted by hand (includes/mazal-tov.php):
 * "Moshe Cohen ('95) on his engagement to Sarah Levy".
 *
 * Announcements > Review Mazal Tovs (the panel; wp-admin has the same page) lists what
 * is waiting, each as it will look on the site, with its sender, and lets the admin
 * Approve and post it, change any part and Save, or Block it. A blocked one is kept
 * out of sight (the trash) with a Restore button, and can be deleted for good.
 *
 * The bot checks are the Contact form's (includes/form-guard.php, form 'mazaltov'): the
 * hidden trap field, the browser check, Turnstile when it is set up, and a limit per
 * address. A message that reads like spam is kept under Submissions as spam.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** How many shared Mazal Tovs are waiting for an OK. */
function ner_michoel_mazal_tov_waiting_count() {
	$counts = wp_count_posts( 'mazal_tov' );
	return ( $counts && isset( $counts->pending ) ) ? (int) $counts->pending : 0;
}

/**
 * The years for a typed name, from the Years List. Returns years ('' unless the name
 * is in the list once, or every boy of that name has the same years) and note, a line
 * for the admin that says what was found (or why nothing was).
 */
function ner_michoel_mazal_tov_find_years( $name ) {
	$none = array( 'years' => '', 'note' => '' );
	$name = trim( (string) $name );
	if ( '' === $name || ! function_exists( 'ner_michoel_years_lookup' ) ) {
		return $none;
	}
	$found = ner_michoel_years_lookup( array( $name ) );
	if ( empty( $found['count'] ) ) {
		return array( 'years' => '', 'note' => __( 'There is no Years List on the site yet, so no years were filled in.', 'ner-michoel-core' ) );
	}

	$describe = function ( array $items ) {
		$out = array();
		foreach ( $items as $item ) {
			$out[] = $item['name'] . ' (' . $item['years'] . ')';
		}
		return implode( '; ', $out );
	};

	if ( ! empty( $found['strong'] ) ) {
		$years = array_values( array_unique( wp_list_pluck( $found['strong'], 'years' ) ) );
		if ( 1 === count( $years ) ) {
			return array(
				'years' => $years[0],
				/* translators: %s: the name as it is in the Years List */
				'note'  => sprintf( __( 'Found in the Years List as %s.', 'ner-michoel-core' ), $found['strong'][0]['name'] ),
			);
		}
		return array(
			'years' => '',
			/* translators: %s: names with their years */
			'note'  => sprintf( __( 'More than one boy has this name in the Years List, so none was chosen: %s. Type the right years in.', 'ner-michoel-core' ), $describe( $found['strong'] ) ),
		);
	}
	if ( ! empty( $found['maybe'] ) ) {
		return array(
			'years' => '',
			/* translators: %s: names with their years */
			'note'  => sprintf( __( 'Not in the Years List under this name. Close: %s. Type the years in if one is him.', 'ner-michoel-core' ), $describe( $found['maybe'] ) ),
		);
	}
	return array( 'years' => '', 'note' => __( 'Not in the Years List, so no years were filled in.', 'ner-michoel-core' ) );
}

/** A Type's term id when it really is a Mazal Tov Type, else 0. */
function ner_michoel_mazal_tov_valid_type( $term_id ) {
	$term_id = absint( $term_id );
	if ( ! $term_id || ! taxonomy_exists( 'mazal_tov_type' ) ) {
		return 0;
	}
	$term = get_term( $term_id, 'mazal_tov_type' );
	return ( $term && ! is_wp_error( $term ) ) ? (int) $term->term_id : 0;
}

/** The name of a post's Type, '' for none. */
function ner_michoel_mazal_tov_type_name( $post_id ) {
	$terms = taxonomy_exists( 'mazal_tov_type' ) ? get_the_terms( $post_id, 'mazal_tov_type' ) : false;
	return ( $terms && ! is_wp_error( $terms ) ) ? wp_specialchars_decode( $terms[0]->name, ENT_QUOTES ) : '';
}

/** Where the admin reviews them: the panel's page, or wp-admin's copy. */
function ner_michoel_mazal_tov_review_url( $args = array() ) {
	if ( function_exists( 'ner_michoel_custom_admin_url' ) ) {
		return ner_michoel_custom_admin_url( 'mazaltovreview', $args );
	}
	return add_query_arg( $args, admin_url( 'admin.php?page=nm-mazal-tov-review' ) );
}

/* ================================================================== */
/* The public form's handler                                            */
/* ================================================================== */

/**
 * Posts: nm_mt_name, nm_mt_what (required), nm_mt_type (a Type's id, optional),
 * nm_mt_from_name, nm_mt_from_email (required, so the admin can ask a question),
 * nm_mt_hp (the trap), the nonce and the bot check's fields. Sends the visitor back
 * with ?nm_mazal_tov=sent | error | verify | slow.
 */
function ner_michoel_handle_mazal_tov_share() {
	$redirect = wp_get_referer() ? wp_get_referer() : home_url( '/' );
	$back     = function ( $result ) use ( $redirect ) {
		wp_safe_redirect( esc_url_raw( add_query_arg( 'nm_mazal_tov', $result, remove_query_arg( 'nm_mazal_tov', $redirect ) ) ) );
		exit;
	};

	if ( ! isset( $_POST['nm_mazal_tov_share_nonce'] ) || ! wp_verify_nonce( $_POST['nm_mazal_tov_share_nonce'], 'nm_mazal_tov_share' ) ) {
		$back( 'error' );
	}

	// The trap field: told it worked, so a bot learns nothing.
	if ( ! empty( $_POST['nm_mt_hp'] ) ) {
		ner_michoel_form_guard_log( 'honeypot' );
		$back( 'sent' );
	}

	$guard = ner_michoel_form_guard_verify( 'mazaltov', wp_unslash( $_POST ) );
	if ( true !== $guard ) {
		$back( 'rate_limited' === $guard ? 'slow' : 'verify' );
	}

	$name       = isset( $_POST['nm_mt_name'] ) ? sanitize_text_field( wp_unslash( $_POST['nm_mt_name'] ) ) : '';
	$from_name  = isset( $_POST['nm_mt_from_name'] ) ? sanitize_text_field( wp_unslash( $_POST['nm_mt_from_name'] ) ) : '';
	$from_email = isset( $_POST['nm_mt_from_email'] ) ? sanitize_email( wp_unslash( $_POST['nm_mt_from_email'] ) ) : '';
	$name       = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 120 ) : substr( $name, 0, 120 );

	// The wording comes from the admin's preset for the kind of simcha the visitor picked: the visitor
	// gives the name only. The name goes in the preset's first blank (or in front of a wording with no
	// blank), and any other blank is left for the admin to fill in on the review screen. Only a site
	// with no presets at all asks for the words, so the form never has nothing to send.
	$what      = '';
	$type_id   = 0;
	$preset_id = 0;
	$free_text = '';
	if ( ner_michoel_mazal_tov_share_choices() ) {
		$preset_id = isset( $_POST['nm_mt_preset'] ) ? absint( wp_unslash( $_POST['nm_mt_preset'] ) ) : 0;
		foreach ( ner_michoel_get_mazal_tov_presets() as $preset ) {
			if ( (int) $preset['id'] === $preset_id ) {
				$what    = ner_michoel_mazal_tov_wording_template( $preset['wording'] );
				$type_id = (int) $preset['type_id'];
			}
		}
	} else {
		$free_text = isset( $_POST['nm_mt_what'] ) ? sanitize_text_field( wp_unslash( $_POST['nm_mt_what'] ) ) : '';
		$what      = function_exists( 'mb_substr' ) ? mb_substr( $free_text, 0, 200 ) : substr( $free_text, 0, 200 );
		$type_id   = isset( $_POST['nm_mt_type'] ) ? ner_michoel_mazal_tov_valid_type( wp_unslash( $_POST['nm_mt_type'] ) ) : 0;
		$free_text = $what;
	}

	if ( '' === $name || '' === $what || '' === $from_name || ! is_email( $from_email ) || ner_michoel_mazal_tov_has_blank( $name . ' ' . $free_text ) ) {
		$back( 'error' );
	}

	// Repeats and links/markup: kept under Submissions as spam (not made a Mazal Tov) so a false alarm is never lost.
	$record = array(
		'name'    => $from_name,
		'email'   => $from_email,
		'message' => sprintf( 'Mazal Tov for %s: %s', $name, $what ),
	);
	if ( ner_michoel_form_guard_is_repeat( 'mazaltov', $name . '|' . $preset_id . '|' . $free_text ) ) {
		ner_michoel_form_guard_log( 'duplicate' );
		$back( 'sent' );
	}
	$raw_text = ( isset( $_POST['nm_mt_name'] ) ? wp_unslash( (string) $_POST['nm_mt_name'] ) : '' ) . ' ' . ( isset( $_POST['nm_mt_what'] ) ? wp_unslash( (string) $_POST['nm_mt_what'] ) : '' );
	$reason   = ner_michoel_form_guard_spam_reason( $from_name, $raw_text );
	if ( '' !== $reason ) {
		ner_michoel_form_guard_log( 'spam_content' );
		ner_michoel_record_submission( 'mazaltov', $record, $reason );
		$back( 'sent' );
	}

	$years = ner_michoel_mazal_tov_find_years( $name );
	$title = ner_michoel_mazal_tov_compose_title( $name, $years['years'], $what );

	// A visitor is not signed in, so WordPress would turn the "&" in "Rabbi & Mrs." into "&amp;" in the saved title.
	// Everything in the sentence was cleaned above (tags stripped), so the filter is off while it is saved.
	kses_remove_filters();
	$post_id = wp_insert_post(
		array(
			'post_type'   => 'mazal_tov',
			'post_status' => 'pending',
			'post_title'  => $title,
		),
		true
	);
	if ( is_wp_error( $post_id ) ) {
		$back( 'error' );
	}

	ner_michoel_mazal_tov_save_parts( $post_id, array( 'name' => $name, 'years' => $years['years'], 'what' => $what ) );
	kses_init();
	if ( $type_id ) {
		wp_set_object_terms( $post_id, array( $type_id ), 'mazal_tov_type' );
	}
	update_post_meta( $post_id, '_nm_mt_submitted', 1 );
	update_post_meta( $post_id, '_nm_mt_from_name', $from_name );
	update_post_meta( $post_id, '_nm_mt_from_email', $from_email );

	ner_michoel_mazal_tov_email_admin( $post_id, $from_name, $from_email );

	$back( 'sent' );
}
add_action( 'admin_post_nm_mazal_tov_share', 'ner_michoel_handle_mazal_tov_share' );
add_action( 'admin_post_nopriv_nm_mazal_tov_share', 'ner_michoel_handle_mazal_tov_share' );

/**
 * Tells the site's admin address that one is waiting, with the finished look and a link.
 * A failed send does not lose anything: the Mazal Tov is waiting on the review screen either way.
 */
function ner_michoel_mazal_tov_email_admin( $post_id, $from_name, $from_email ) {
	$look = wp_specialchars_decode( (string) get_post_field( 'post_title', $post_id ), ENT_QUOTES );
	$type = ner_michoel_mazal_tov_type_name( $post_id );

	$lines = array(
		__( 'Someone shared a Mazal Tov on the site. It is not on the site yet: it is waiting for you to look it over.', 'ner-michoel-core' ),
		'',
		__( 'How it will look:', 'ner-michoel-core' ),
		( '' !== $type ? '[' . $type . '] ' : '' ) . $look,
		'',
		sprintf( /* translators: 1: name, 2: email */ __( 'Sent by: %1$s <%2$s>', 'ner-michoel-core' ), $from_name, $from_email ),
		'',
		__( 'Approve it, change it or block it here:', 'ner-michoel-core' ),
		ner_michoel_mazal_tov_review_url(),
	);

	return wp_mail(
		get_option( 'admin_email' ),
		sprintf( /* translators: 1: site name, 2: the headline */ __( '[%1$s] Mazal Tov to review: %2$s', 'ner-michoel-core' ), get_bloginfo( 'name' ), $look ),
		implode( "\n", $lines ),
		array( 'Reply-To: ' . $from_name . ' <' . $from_email . '>' )
	);
}

/* ================================================================== */
/* The review screen                                                    */
/* ================================================================== */

/** ", received 3 hours ago" for a post's received time (a waiting post has no GMT date, so its own date is read). '' when it has none. */
function ner_michoel_mazal_tov_received( $post ) {
	$time = (int) get_post_timestamp( $post );
	return $time ? ', ' . sprintf( /* translators: %s: how long ago */ __( 'received %s ago', 'ner-michoel-core' ), human_time_diff( $time, time() ) ) : '';
}

function ner_michoel_render_mazal_tov_review_page() {
	if ( ! current_user_can( 'edit_others_posts' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'ner-michoel-core' ) );
	}

	$waiting = get_posts(
		array(
			'post_type'      => 'mazal_tov',
			'post_status'    => 'pending',
			'posts_per_page' => 100,
			'orderby'        => 'date',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		)
	);
	$blocked = get_posts(
		array(
			'post_type'      => 'mazal_tov',
			'post_status'    => 'trash',
			'meta_key'       => '_nm_mt_submitted', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a handful of rows.
			'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'posts_per_page' => 50,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		)
	);
	$types   = taxonomy_exists( 'mazal_tov_type' ) ? get_terms( array( 'taxonomy' => 'mazal_tov_type', 'hide_empty' => false ) ) : array();
	$types   = is_wp_error( $types ) ? array() : $types;

	$messages = array(
		'approved' => array( 'success', __( 'Approved. It is on the site now.', 'ner-michoel-core' ) ),
		'saved'    => array( 'success', __( 'Saved. It is still waiting for your OK.', 'ner-michoel-core' ) ),
		'blocked'  => array( 'success', __( 'Blocked. It will not be shown. You can bring it back from “Blocked” below.', 'ner-michoel-core' ) ),
		'restored' => array( 'success', __( 'Brought back. It is waiting for your OK again.', 'ner-michoel-core' ) ),
		'deleted'  => array( 'success', __( 'Deleted for good.', 'ner-michoel-core' ) ),
		'error'    => array( 'error', __( 'That did not work. The name and what the Mazal Tov is cannot be empty, and cannot still have a blank (___).', 'ner-michoel-core' ) ),
	);
	$flash = isset( $_GET['nm_mt_review'] ) ? sanitize_key( wp_unslash( $_GET['nm_mt_review'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	?>
	<div class="wrap nm-dashboard nm-mtr">
		<h1><?php esc_html_e( 'Review Mazal Tovs', 'ner-michoel-core' ); ?></h1>
		<p class="description"><?php esc_html_e( 'Mazal Tovs that visitors share wait here. Nothing is shown on the site until you press “Approve and post”. Change anything you like first: the box at the top of each one shows it as it will look.', 'ner-michoel-core' ); ?></p>

		<?php if ( isset( $messages[ $flash ] ) ) : ?>
			<div class="notice notice-<?php echo esc_attr( $messages[ $flash ][0] ); ?>"><p><?php echo esc_html( $messages[ $flash ][1] ); ?></p></div>
		<?php endif; ?>

		<?php if ( ! $waiting ) : ?>
			<p class="nm-mtr__empty"><?php esc_html_e( 'Nothing is waiting. When someone shares a Mazal Tov from the site you will get an email, and it will show up here.', 'ner-michoel-core' ); ?></p>
		<?php endif; ?>

		<?php foreach ( $waiting as $post ) : ?>
			<?php
			$parts        = ner_michoel_mazal_tov_parts( $post );
			$name         = (string) get_post_meta( $post->ID, '_mazal_tov_name', true );
			$years        = ner_michoel_get_mazal_tov_years( $post->ID );
			$what         = (string) get_post_meta( $post->ID, '_mazal_tov_what', true );
			$relationship = ner_michoel_get_mazal_tov_relationship( $post->ID );
			$type_terms   = taxonomy_exists( 'mazal_tov_type' ) ? get_the_terms( $post->ID, 'mazal_tov_type' ) : false;
			$type_id      = ( $type_terms && ! is_wp_error( $type_terms ) ) ? (int) $type_terms[0]->term_id : 0;
			$from_name    = (string) get_post_meta( $post->ID, '_nm_mt_from_name', true );
			$from_email   = (string) get_post_meta( $post->ID, '_nm_mt_from_email', true );
			$found        = '' === $years ? ner_michoel_mazal_tov_find_years( $name ) : array( 'years' => $years, 'note' => '' );
			$uid          = 'nm-mtr-' . (int) $post->ID;
			?>
			<form class="nm-mtr__item" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-nm-mtr>
				<input type="hidden" name="nm_mazal_tov_review_nonce" value="<?php echo esc_attr( wp_create_nonce( 'nm_mazal_tov_review' ) ); ?>" />
				<input type="hidden" name="action" value="nm_mazal_tov_review" />
				<input type="hidden" name="nm_mt_id" value="<?php echo esc_attr( $post->ID ); ?>" />
				<?php ner_michoel_panel_form_field(); ?>
				<?php // Pressing Enter in a box sends the form's FIRST button: keep that one "Save changes" (off screen), never "Approve and post". ?>
				<button type="submit" name="nm_mt_do" value="save" class="nm-mtr__default" tabindex="-1" aria-hidden="true"><?php esc_html_e( 'Save changes', 'ner-michoel-core' ); ?></button>

				<div class="nm-mtr__look">
					<span class="nm-mtr__label"><?php esc_html_e( 'How it will look on the site', 'ner-michoel-core' ); ?></span>
					<div class="nm-mtr__card">
						<p class="nm-mtr__type" data-nm-mtr-type><?php echo esc_html( ner_michoel_mazal_tov_type_name( $post->ID ) ); ?></p>
						<p class="nm-mtr__text"><span data-nm-mtr-before><?php echo esc_html( $parts['before'] ); ?></span> <strong data-nm-mtr-lead><?php echo esc_html( $parts['lead'] ); ?></strong> <span data-nm-mtr-after><?php echo esc_html( $parts['after'] ); ?></span></p>
					</div>
				</div>

				<p class="nm-mtr__from">
					<?php
					printf(
						/* translators: 1: sender name, 2: sender email, 3: ", received 3 hours ago" (or nothing) */
						esc_html__( 'Shared by %1$s (%2$s)%3$s.', 'ner-michoel-core' ),
						'<strong>' . esc_html( $from_name ? $from_name : __( 'someone', 'ner-michoel-core' ) ) . '</strong>',
						$from_email ? '<a href="' . esc_url( 'mailto:' . $from_email ) . '">' . esc_html( $from_email ) . '</a>' : esc_html__( 'no email', 'ner-michoel-core' ),
						esc_html( ner_michoel_mazal_tov_received( $post ) )
					);
					?>
				</p>

				<table class="form-table">
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( $uid ); ?>-name"><?php esc_html_e( 'Name', 'ner-michoel-core' ); ?></label></th>
						<td><input type="text" id="<?php echo esc_attr( $uid ); ?>-name" name="nm_mt_name" class="regular-text" value="<?php echo esc_attr( $name ); ?>" data-nm-mtr-field="name" required /></td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( $uid ); ?>-years"><?php esc_html_e( 'Years', 'ner-michoel-core' ); ?></label></th>
						<td>
							<input type="text" id="<?php echo esc_attr( $uid ); ?>-years" name="nm_mt_years" class="regular-text" value="<?php echo esc_attr( $found['years'] ); ?>" data-nm-mtr-field="years" placeholder="<?php esc_attr_e( "e.g. '05, '08", 'ner-michoel-core' ); ?>" />
							<?php if ( '' !== $found['note'] ) : ?>
								<p class="description"><?php echo esc_html( $found['note'] ); ?></p>
							<?php elseif ( '' !== $years ) : ?>
								<p class="description"><?php esc_html_e( 'Filled in from the Years List when it was shared.', 'ner-michoel-core' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( $uid ); ?>-what"><?php esc_html_e( 'What the Mazal Tov is', 'ner-michoel-core' ); ?></label></th>
						<td>
							<input type="text" id="<?php echo esc_attr( $uid ); ?>-what" name="nm_mt_what" class="large-text" value="<?php echo esc_attr( $what ); ?>" data-nm-mtr-field="what" required />
							<p class="description"><?php esc_html_e( '{name} is where the name goes. The preset the visitor picked wrote this; you can change any of it.', 'ner-michoel-core' ); ?></p>
							<p class="nm-mtr__blank" data-nm-mtr-blank hidden><?php esc_html_e( 'There is still a blank (___) to fill in. “Approve and post” works once every blank is filled in.', 'ner-michoel-core' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( $uid ); ?>-rel"><?php esc_html_e( 'Relationship', 'ner-michoel-core' ); ?></label></th>
						<td><input type="text" id="<?php echo esc_attr( $uid ); ?>-rel" name="nm_mt_relationship" class="regular-text" value="<?php echo esc_attr( $relationship ); ?>" data-nm-mtr-field="relationship" placeholder="<?php esc_attr_e( 'Optional, e.g. "Rabbi & Mrs."', 'ner-michoel-core' ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( $uid ); ?>-type"><?php esc_html_e( 'Type', 'ner-michoel-core' ); ?></label></th>
						<td>
							<select id="<?php echo esc_attr( $uid ); ?>-type" name="nm_mt_type" data-nm-mtr-field="type">
								<option value=""><?php esc_html_e( '— None —', 'ner-michoel-core' ); ?></option>
								<?php foreach ( $types as $term ) : ?>
									<option value="<?php echo esc_attr( $term->term_id ); ?>"<?php selected( $type_id, (int) $term->term_id ); ?>><?php echo esc_html( $term->name ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				</table>

				<p class="nm-mtr__buttons">
					<button type="submit" name="nm_mt_do" value="approve" class="button button-primary"><?php esc_html_e( 'Approve and post', 'ner-michoel-core' ); ?></button>
					<button type="submit" name="nm_mt_do" value="save" class="button"><?php esc_html_e( 'Save changes', 'ner-michoel-core' ); ?></button>
					<button type="submit" name="nm_mt_do" value="block" class="button nm-mtr__block"><?php esc_html_e( 'Block', 'ner-michoel-core' ); ?></button>
				</p>
			</form>
		<?php endforeach; ?>

		<?php if ( $blocked ) : ?>
			<details class="nm-mtr__blocked">
				<summary><?php
					/* translators: %s: number blocked */
					echo esc_html( sprintf( __( 'Blocked (%s)', 'ner-michoel-core' ), number_format_i18n( count( $blocked ) ) ) );
				?></summary>
				<?php foreach ( $blocked as $post ) : ?>
					<form class="nm-mtr__row" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="nm_mazal_tov_review_nonce" value="<?php echo esc_attr( wp_create_nonce( 'nm_mazal_tov_review' ) ); ?>" />
						<input type="hidden" name="action" value="nm_mazal_tov_review" />
						<input type="hidden" name="nm_mt_id" value="<?php echo esc_attr( $post->ID ); ?>" />
						<?php ner_michoel_panel_form_field(); ?>
						<span class="nm-mtr__row-text"><?php echo esc_html( $post->post_title ); ?></span>
						<span class="nm-mtr__row-from"><?php echo esc_html( (string) get_post_meta( $post->ID, '_nm_mt_from_email', true ) ); ?></span>
						<button type="submit" name="nm_mt_do" value="restore" class="button"><?php esc_html_e( 'Bring back', 'ner-michoel-core' ); ?></button>
						<button type="submit" name="nm_mt_do" value="delete" class="button"><?php esc_html_e( 'Delete for good', 'ner-michoel-core' ); ?></button>
					</form>
				<?php endforeach; ?>
			</details>
		<?php endif; ?>
	</div>
	<style>
		.nm-mtr__item{background:#fff;border:1px solid #d0d5dd;border-radius:10px;padding:16px 20px;margin:18px 0;max-width:760px}
		.nm-mtr__label{display:block;font-size:12px;letter-spacing:.04em;text-transform:uppercase;color:#667085;margin-bottom:6px}
		.nm-mtr__card{border:1px solid #e4d7a8;background:#fffaf0;border-radius:10px;padding:12px 16px}
		.nm-mtr__type{margin:0 0 2px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#a36a00}
		.nm-mtr__type:empty{display:none}
		.nm-mtr__text{margin:0;font-size:17px;line-height:1.45}
		.nm-mtr__from{color:#475467;margin:12px 0 0}
		.nm-mtr__buttons{display:flex;flex-wrap:wrap;gap:8px;margin:10px 0 0}
		.nm-mtr__item{position:relative}
		.nm-mtr__default{position:absolute;left:-9999px;top:0;width:1px;height:1px;overflow:hidden}
		.nm-mtr__blank{margin:6px 0 0;color:#b32d2e;font-weight:600}
		.nm-mtr__block{margin-left:auto !important}
		.nm-mtr__blocked{margin-top:26px;max-width:760px}
		.nm-mtr__blocked summary{cursor:pointer;font-weight:600}
		.nm-mtr__row{display:flex;flex-wrap:wrap;align-items:center;gap:8px;padding:8px 0;border-bottom:1px solid #e4e7ec}
		.nm-mtr__row-text{flex:1 1 280px}
		.nm-mtr__row-from{color:#667085;font-size:13px}
		.nm-mtr__empty{padding:14px 0;color:#475467}
		@media (max-width:600px){.nm-mtr__block{margin-left:0 !important}.nm-mtr input[type=text],.nm-mtr select{font-size:16px}}
	</style>
	<script>
		( function () {
			// Keeps each card's "how it will look" box in step with the boxes under it, the way the site puts it together.
			function field( form, key ) {
				var el = form.querySelector( '[data-nm-mtr-field="' + key + '"]' );
				return el ? el.value.replace( /\s+/g, ' ' ).trim() : '';
			}
			// Same rules as ner_michoel_mazal_tov_compose_title() / _parts(): {name} in the words is where the name goes.
			var NAME_RE = /\{\s*name\s*\}/i;
			var BLANK_RE = /_{2,}|\{(?!\s*name\s*\})[^{}]{0,60}\}/i;
			function update( form ) {
				var lead = ( field( form, 'relationship' ) + ' ' + field( form, 'name' ) ).trim();
				var years = field( form, 'years' );
				if ( lead && years ) {
					lead += ' (' + years + ')';
				}
				var what = field( form, 'what' );
				var before = '';
				var after = what;
				var hit = what.match( NAME_RE );
				if ( hit ) {
					before = what.slice( 0, hit.index ).trim();
					after = what.slice( hit.index + hit[ 0 ].length ).trim().replace( new RegExp( NAME_RE.source, 'gi' ), function () { return lead; } );
				}
				var select = form.querySelector( '[data-nm-mtr-field="type"]' );
				var type = select && select.value ? select.options[ select.selectedIndex ].text : '';
				form.querySelector( '[data-nm-mtr-before]' ).textContent = before;
				form.querySelector( '[data-nm-mtr-lead]' ).textContent = lead;
				form.querySelector( '[data-nm-mtr-after]' ).textContent = after;
				form.querySelector( '[data-nm-mtr-type]' ).textContent = type;
				// A blank left in it cannot be posted: say so, and keep "Approve and post" from being pressed.
				var blank = BLANK_RE.test( field( form, 'name' ) + ' ' + what );
				var note = form.querySelector( '[data-nm-mtr-blank]' );
				var approve = form.querySelector( 'button[value="approve"]' );
				if ( note ) {
					note.hidden = ! blank;
				}
				if ( approve ) {
					approve.disabled = blank;
				}
			}
			document.querySelectorAll( '[data-nm-mtr]' ).forEach( update );
			document.addEventListener( 'input', function ( e ) {
				var form = e.target.closest ? e.target.closest( '[data-nm-mtr]' ) : null;
				if ( form ) {
					update( form );
				}
			} );
			document.addEventListener( 'change', function ( e ) {
				var form = e.target.closest ? e.target.closest( '[data-nm-mtr]' ) : null;
				if ( form ) {
					update( form );
				}
			} );
		}() );
	</script>
	<?php
}

/**
 * The buttons on the review screen: approve, save, block (a waiting one), bring back, delete
 * for good (a blocked one). Always for a Mazal Tov that a visitor shared.
 */
function ner_michoel_handle_mazal_tov_review() {
	if ( ! current_user_can( 'edit_others_posts' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'ner-michoel-core' ) );
	}
	check_admin_referer( 'nm_mazal_tov_review', 'nm_mazal_tov_review_nonce' );

	$redirect = ner_michoel_panel_redirect_url( admin_url( 'admin.php?page=nm-mazal-tov-review' ), 'mazaltovreview' );
	$done     = function ( $result ) use ( $redirect ) {
		wp_safe_redirect( esc_url_raw( add_query_arg( 'nm_mt_review', $result, $redirect ) ) );
		exit;
	};

	$id   = isset( $_POST['nm_mt_id'] ) ? absint( $_POST['nm_mt_id'] ) : 0;
	$do   = isset( $_POST['nm_mt_do'] ) ? sanitize_key( wp_unslash( $_POST['nm_mt_do'] ) ) : '';
	$post = $id ? get_post( $id ) : null;
	if ( ! $post || 'mazal_tov' !== $post->post_type || ! get_post_meta( $id, '_nm_mt_submitted', true ) ) {
		$done( 'error' );
	}

	if ( in_array( $do, array( 'restore', 'delete' ), true ) ) {
		if ( 'trash' !== $post->post_status ) {
			$done( 'error' );
		}
		if ( 'delete' === $do ) {
			wp_delete_post( $id, true );
			$done( 'deleted' );
		}
		wp_untrash_post( $id );
		wp_update_post( array( 'ID' => $id, 'post_status' => 'pending' ) );
		delete_post_meta( $id, '_nm_mt_blocked' );
		$done( 'restored' );
	}

	if ( 'pending' !== $post->post_status || ! in_array( $do, array( 'approve', 'save', 'block' ), true ) ) {
		$done( 'error' );
	}

	if ( 'block' === $do ) {
		update_post_meta( $id, '_nm_mt_blocked', time() );
		wp_trash_post( $id );
		$done( 'blocked' );
	}

	$read  = function ( $key ) {
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
	};
	$parts = array(
		'name'         => $read( 'nm_mt_name' ),
		'years'        => $read( 'nm_mt_years' ),
		'what'         => $read( 'nm_mt_what' ),
		'relationship' => $read( 'nm_mt_relationship' ),
	);
	if ( '' === trim( $parts['name'] ) || '' === trim( $parts['what'] ) || ner_michoel_mazal_tov_has_blank( $parts['name'] . ' ' . $parts['what'] ) ) {
		$done( 'error' );
	}

	ner_michoel_mazal_tov_save_parts( $id, $parts );
	$type_id = isset( $_POST['nm_mt_type'] ) ? ner_michoel_mazal_tov_valid_type( wp_unslash( $_POST['nm_mt_type'] ) ) : 0;
	wp_set_object_terms( $id, $type_id ? array( $type_id ) : array(), 'mazal_tov_type' );

	if ( 'approve' === $do ) {
		// Posted now, so it is the newest on the site.
		wp_update_post(
			array(
				'ID'            => $id,
				'post_status'   => 'publish',
				'post_date'     => current_time( 'mysql' ),
				'post_date_gmt' => current_time( 'mysql', true ),
			)
		);
		update_post_meta( $id, '_nm_mt_approved', time() );
		$done( 'approved' );
	}
	$done( 'saved' );
}
add_action( 'admin_post_nm_mazal_tov_review', 'ner_michoel_handle_mazal_tov_review' );
