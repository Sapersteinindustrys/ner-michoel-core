<?php
/**
 * "Post News" shortcut — News is native WP Posts filed under the
 * "news" category (see ner_michoel_create_default_news_category() in
 * ner-michoel-core.php, and page-templates/news-events.php in the
 * theme, which queries category_name => 'news'). That's an
 * implementation detail a non-technical admin could easily get wrong —
 * forget to check the category box and the post just never shows up on
 * the News & Events page, with no error telling them why.
 *
 * This creates the draft with the category already applied — hidden
 * entirely rather than merely pre-selected — then hands off to the
 * normal post editor, so there's no category step to forget at all.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ner_michoel_render_post_news_page() {
	if ( isset( $_GET['_wpnonce'] ) && wp_verify_nonce( wp_unslash( $_GET['_wpnonce'] ), 'nm_post_news' ) ) {
		ner_michoel_create_and_redirect_to_news_post();
		return; // The function above always exits (redirect or wp_die).
	}
	?>
	<div class="wrap nm-dashboard">
		<h1><?php esc_html_e( 'Post News', 'ner-michoel-core' ); ?></h1>
		<p><?php esc_html_e( 'Starts a new post already filed under "News", then takes you straight to the normal editor to write it — no need to remember to check the category box yourself.', 'ner-michoel-core' ); ?></p>
		<p>
			<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=nm-post-news' ), 'nm_post_news' ) ); ?>"><?php esc_html_e( 'Start a New News Post', 'ner-michoel-core' ); ?></a>
		</p>
	</div>
	<?php
}

function ner_michoel_create_and_redirect_to_news_post() {
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'ner-michoel-core' ) );
	}

	$news_category = get_category_by_slug( 'news' );
	$category_id   = $news_category ? $news_category->term_id : 0;

	$post_id = wp_insert_post(
		array(
			'post_type'     => 'post',
			'post_status'   => 'draft',
			'post_category' => $category_id ? array( $category_id ) : array(),
		),
		true
	);

	if ( is_wp_error( $post_id ) ) {
		wp_die( esc_html__( 'Could not create the post.', 'ner-michoel-core' ) );
	}

	wp_safe_redirect( admin_url( 'post.php?post=' . $post_id . '&action=edit' ) );
	exit;
}
