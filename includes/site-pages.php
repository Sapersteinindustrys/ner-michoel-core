<?php
/**
 * The About page, editable from the Control Panel (Homepage & Look > About Page).
 *
 * Before this, the theme's About template (page-templates/about.php) printed
 * its own fixed text and ignored the page's content, so nothing typed into the
 * page showed on the site. The live page's content was also the host's sample
 * text ("Schedule a consultation"), never meant to show.
 *
 * Now:
 * - The panel tab has one button, "Edit the About page". The first time, it
 *   puts the site's current About text into the page (as headings and
 *   paragraphs; the old content is kept as a revision), marks the page as
 *   edited here (_nm_about_custom), and opens WordPress's full editor, where
 *   text, headings, pictures and links can be changed. Every time after, it
 *   just opens the editor.
 * - The theme shows the page's own content once it is marked, and its built-in
 *   text until then, so the live page looks the same until someone edits it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NER_MICHOEL_ABOUT_MARK', '_nm_about_custom' );

/**
 * The About page's ID: the page using the About template, else the page called
 * "about". 0 when there is none.
 */
function ner_michoel_about_page_id() {
	$pages = get_posts(
		array(
			'post_type'   => 'page',
			'post_status' => array( 'publish', 'draft', 'private', 'pending' ),
			'numberposts' => 1,
			'fields'      => 'ids',
			'orderby'     => 'ID',
			'order'       => 'ASC',
			'meta_key'    => '_wp_page_template', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'  => 'page-templates/about.php', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		)
	);
	if ( $pages ) {
		return (int) $pages[0];
	}
	$page = get_page_by_path( 'about' );
	return $page ? (int) $page->ID : 0;
}

/**
 * True when the About page's own content is what the site shows (it was opened
 * from the panel at least once). The theme asks this.
 */
function ner_michoel_about_uses_page_content( $post_id ) {
	return (bool) get_post_meta( (int) $post_id, NER_MICHOEL_ABOUT_MARK, true ) && '' !== trim( (string) get_post_field( 'post_content', (int) $post_id ) );
}

/**
 * The site's About text as editor blocks: the same words the theme shows.
 */
function ner_michoel_about_default_content() {
	$parts = array(
		array( 'h', __( 'Yeshivas Toras Moshe', 'ner-michoel-core' ) ),
		array( 'p', __( 'Yeshivas Toras Moshe was founded in Jerusalem in 1982 by Rav Moshe Meiselman shlita, as a memorial to Rav Moshe Soloveitchik zt"l. The Yeshiva has experienced tremendous growth. With 130 bochurim and 50 avreichim in the kollel, enrollment is at an all-time high.', 'ner-michoel-core' ) ),
		array( 'h', __( 'The Ner Michoel Alumni Association', 'ner-michoel-core' ) ),
		array( 'p', __( 'The Ner Michoel Alumni Association of Yeshivas Toras Moshe was founded in September 2012 to maintain the connections between the Yeshiva and its alumni around the world. Since then, it has produced educational materials and hosted events internationally.', 'ner-michoel-core' ) ),
		array( 'p', __( 'The Association is named for Rabbi Michoel Weiner zt"l, a contemporary and close friend of Rav Meiselman — the two grew up in Boston together and maintained a mutual feeling of respect and appreciation throughout their lives.', 'ner-michoel-core' ) ),
		array( 'h', __( 'Rabbi Michoel Weiner zt"l', 'ner-michoel-core' ) ),
		array( 'p', __( 'Reb Michoel studied under some of the most prominent Torah scholars of his generation and was known for his meticulous scholarship. His diligence in learning was legendary; he would sit for long hours, toiling in the understanding of Torah with complete focus. His commentaries on Talmudic texts are noted for their halakhic precision.', 'ner-michoel-core' ) ),
		array( 'p', __( 'Alongside his intensity in learning, Reb Michoel possessed an extraordinary sense of humor, and maintained a joyful home centered on spiritual service.', 'ner-michoel-core' ) ),
	);

	$blocks = array();
	foreach ( $parts as $part ) {
		$text = esc_html( $part[1] );
		if ( 'h' === $part[0] ) {
			$blocks[] = "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">{$text}</h2>\n<!-- /wp:heading -->";
		} else {
			$blocks[] = "<!-- wp:paragraph -->\n<p>{$text}</p>\n<!-- /wp:paragraph -->";
		}
	}
	return implode( "\n\n", $blocks );
}

/**
 * "Edit the About page": makes the page if there is none, puts the site's
 * text in it the first time, and opens WordPress's editor on it.
 */
function ner_michoel_handle_edit_about_page() {
	if ( ! current_user_can( 'edit_pages' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'ner-michoel-core' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'nm_edit_about_page' );

	$page_id = ner_michoel_about_page_id();
	if ( ! $page_id ) {
		$page_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => __( 'About Us', 'ner-michoel-core' ),
				'post_name'    => 'about',
				'post_content' => '',
			),
			true
		);
		if ( is_wp_error( $page_id ) ) {
			wp_die( esc_html__( 'Could not create the About page.', 'ner-michoel-core' ) );
		}
		update_post_meta( $page_id, '_wp_page_template', 'page-templates/about.php' );
	}

	if ( ! current_user_can( 'edit_post', $page_id ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'ner-michoel-core' ), '', array( 'response' => 403 ) );
	}

	if ( ! get_post_meta( $page_id, NER_MICHOEL_ABOUT_MARK, true ) ) {
		// The old content stays as a revision (wp_update_post saves one).
		wp_update_post(
			array(
				'ID'           => $page_id,
				'post_content' => ner_michoel_about_default_content(),
			)
		);
		update_post_meta( $page_id, NER_MICHOEL_ABOUT_MARK, 1 );
	}

	wp_safe_redirect( admin_url( 'post.php?post=' . (int) $page_id . '&action=edit' ) );
	exit;
}
add_action( 'admin_post_nm_edit_about_page', 'ner_michoel_handle_edit_about_page' );

/**
 * The panel tab (Homepage & Look > About Page).
 */
function ner_michoel_render_about_page_editor() {
	$page_id = ner_michoel_about_page_id();
	$edit    = wp_nonce_url( admin_url( 'admin-post.php?action=nm_edit_about_page' ), 'nm_edit_about_page' );
	$custom  = $page_id && ner_michoel_about_uses_page_content( $page_id );
	?>
	<div class="wrap nm-dashboard">
		<h1><?php esc_html_e( 'About Page', 'ner-michoel-core' ); ?></h1>
		<p><?php esc_html_e( 'Change the words, headings and pictures on the About page. The button opens the page in the full editor; press Update there to put your changes on the site.', 'ner-michoel-core' ); ?></p>
		<?php if ( ! $custom ) : ?>
			<p class="description"><?php esc_html_e( 'The first time, the page is filled with the text the site shows now, so you start from that.', 'ner-michoel-core' ); ?></p>
		<?php endif; ?>
		<p>
			<a class="button button-primary" href="<?php echo esc_url( $edit ); ?>"><?php esc_html_e( 'Edit the About page', 'ner-michoel-core' ); ?></a>
			<?php if ( $page_id && 'publish' === get_post_status( $page_id ) ) : ?>
				<a class="button" href="<?php echo esc_url( get_permalink( $page_id ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View the page', 'ner-michoel-core' ); ?></a>
			<?php endif; ?>
		</p>
	</div>
	<?php
}
