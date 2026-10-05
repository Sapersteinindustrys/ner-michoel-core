<?php
/**
 * Written shiurim — PDF lectures, with their own post type.
 *
 * Kept separate from `shiur` on purpose: the shiur type is built around
 * audio/video playback (play queue, media-type detection, duration), and
 * a written shiur is just a title, speaker, series, description and one
 * PDF. Forcing a PDF into `shiur` would be silently misread as an audio
 * shiur (see ner_michoel_get_shiur_media_type()).
 *
 * Shares the `speaker` and `series` taxonomies with `shiur` (registered
 * in post-types.php), so a speaker's or series' terms cover both kinds.
 *
 * The full-library importer (library-import.php) creates these from the
 * origin site's PDF shiurim.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NER_MICHOEL_WRITTEN_REWRITE_VERSION', '1' );

function ner_michoel_register_written_shiur_post_type() {
	register_post_type(
		'written_shiur',
		array(
			'labels'        => array(
				'name'               => __( 'Written Shiurim', 'ner-michoel-core' ),
				'singular_name'      => __( 'Written Shiur', 'ner-michoel-core' ),
				'add_new_item'       => __( 'Add New Written Shiur', 'ner-michoel-core' ),
				'edit_item'          => __( 'Edit Written Shiur', 'ner-michoel-core' ),
				'new_item'           => __( 'New Written Shiur', 'ner-michoel-core' ),
				'view_item'          => __( 'View Written Shiur', 'ner-michoel-core' ),
				'search_items'       => __( 'Search Written Shiurim', 'ner-michoel-core' ),
				'not_found'          => __( 'No written shiurim found', 'ner-michoel-core' ),
				'not_found_in_trash' => __( 'No written shiurim found in Trash', 'ner-michoel-core' ),
				'all_items'          => __( 'All Written Shiurim', 'ner-michoel-core' ),
				'menu_name'          => __( 'Written Shiurim', 'ner-michoel-core' ),
			),
			'public'        => true,
			'has_archive'   => 'written-shiurim',
			'rewrite'       => array( 'slug' => 'written-shiurim', 'with_front' => false ),
			'menu_icon'     => 'dashicons-media-document',
			'supports'      => array( 'title', 'editor', 'excerpt', 'thumbnail' ),
			'show_in_rest'  => true,
			'menu_position' => 21,
		)
	);
}
add_action( 'init', 'ner_michoel_register_written_shiur_post_type' );

/**
 * The activation hook flushes rewrite rules, but an install that's
 * already running never fires it again. Flush once for this version,
 * so /written-shiurim/ resolves without a manual Settings > Permalinks
 * visit. Runs on init (not admin_init) so a front-end request right
 * after deploy works too.
 */
function ner_michoel_maybe_flush_written_rewrite() {
	if ( get_option( 'nm_written_rewrite_version' ) !== NER_MICHOEL_WRITTEN_REWRITE_VERSION ) {
		flush_rewrite_rules( false );
		update_option( 'nm_written_rewrite_version', NER_MICHOEL_WRITTEN_REWRITE_VERSION );
	}
}
add_action( 'init', 'ner_michoel_maybe_flush_written_rewrite', 20 );

/**
 * wp-admin meta box for the PDF. The Site Control Panel's Written
 * Shiurim screen (custom-admin-api.php) edits the same meta key, so
 * either place works.
 */
function ner_michoel_add_written_shiur_meta_box() {
	add_meta_box(
		'ner_michoel_written_pdf',
		__( 'PDF File', 'ner-michoel-core' ),
		'ner_michoel_render_written_shiur_meta_box',
		'written_shiur',
		'side',
		'high'
	);
}
add_action( 'add_meta_boxes', 'ner_michoel_add_written_shiur_meta_box' );

function ner_michoel_render_written_shiur_meta_box( $post ) {
	wp_nonce_field( 'ner_michoel_save_written_shiur_meta', 'ner_michoel_written_shiur_meta_nonce' );

	$pdf_id  = ner_michoel_get_written_shiur_pdf_id( $post->ID );
	$pdf_url = $pdf_id ? wp_get_attachment_url( $pdf_id ) : '';
	?>
	<p>
		<button type="button" class="button" id="ner_michoel_pdf_select"><?php esc_html_e( 'Choose PDF', 'ner-michoel-core' ); ?></button>
		<button type="button" class="button-link-delete" id="ner_michoel_pdf_remove" style="<?php echo $pdf_id ? '' : 'display:none;'; ?> margin-left:8px;"><?php esc_html_e( 'Remove', 'ner-michoel-core' ); ?></button>
	</p>
	<p id="ner_michoel_pdf_filename" style="word-break:break-all;">
		<?php echo $pdf_url ? esc_html( basename( $pdf_url ) ) : esc_html__( 'No PDF selected.', 'ner-michoel-core' ); ?>
	</p>
	<input type="hidden" name="ner_michoel_written_pdf_id" id="ner_michoel_pdf_id" value="<?php echo esc_attr( $pdf_id ); ?>" />
	<?php
	// JS: assets/media-pickers.js (enqueued in ner_michoel_written_shiur_assets()).
}

function ner_michoel_save_written_shiur_meta( $post_id ) {
	if ( ! isset( $_POST['ner_michoel_written_shiur_meta_nonce'] ) ||
		! wp_verify_nonce( $_POST['ner_michoel_written_shiur_meta_nonce'], 'ner_michoel_save_written_shiur_meta' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	if ( isset( $_POST['ner_michoel_written_pdf_id'] ) ) {
		$pdf_id = absint( $_POST['ner_michoel_written_pdf_id'] );
		if ( $pdf_id && 'application/pdf' === get_post_mime_type( $pdf_id ) ) {
			update_post_meta( $post_id, '_written_pdf_id', $pdf_id );
		} else {
			delete_post_meta( $post_id, '_written_pdf_id' );
		}
	}
}
add_action( 'save_post_written_shiur', 'ner_michoel_save_written_shiur_meta' );

function ner_michoel_written_shiur_assets( $hook ) {
	global $post_type;
	if ( 'written_shiur' !== $post_type || ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
		return;
	}
	ner_michoel_enqueue_media_pickers_script();
}
add_action( 'admin_enqueue_scripts', 'ner_michoel_written_shiur_assets' );

/**
 * Front-end accessors.
 */

/**
 * The attached PDF's attachment ID, or 0. Checks the MIME type as well
 * as the meta, so a non-PDF stored by a bad CMS write is never served.
 */
function ner_michoel_get_written_shiur_pdf_id( $post_id ) {
	$attachment_id = (int) get_post_meta( $post_id, '_written_pdf_id', true );
	return ( $attachment_id && 'application/pdf' === get_post_mime_type( $attachment_id ) ) ? $attachment_id : 0;
}

/**
 * The PDF's URL, or '' if none is attached.
 */
function ner_michoel_get_written_shiur_pdf_url( $post_id ) {
	$attachment_id = ner_michoel_get_written_shiur_pdf_id( $post_id );
	$url           = $attachment_id ? wp_get_attachment_url( $attachment_id ) : '';
	return $url ? $url : '';
}

/**
 * Download URL that streams the PDF with a "{Speaker} - {Title}.pdf"
 * filename (downloads.php), or '' if there's no PDF.
 */
function ner_michoel_get_written_shiur_download_url( $post_id ) {
	if ( ! ner_michoel_get_written_shiur_pdf_id( $post_id ) ) {
		return '';
	}
	return add_query_arg( 'nm_download', '1', get_permalink( $post_id ) );
}
