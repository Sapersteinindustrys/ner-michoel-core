<?php
/**
 * Admin meta box for the shiur audio file (attachment ID) and an
 * optional manual duration override, plus the front-end accessors
 * that read them back.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ner_michoel_add_shiur_meta_box() {
	add_meta_box(
		'ner_michoel_shiur_audio',
		__( 'Media (Audio or Video)', 'ner-michoel-core' ),
		'ner_michoel_render_shiur_meta_box',
		'shiur',
		'side',
		'high'
	);
}
add_action( 'add_meta_boxes', 'ner_michoel_add_shiur_meta_box' );

function ner_michoel_render_shiur_meta_box( $post ) {
	wp_nonce_field( 'ner_michoel_save_shiur_meta', 'ner_michoel_shiur_meta_nonce' );

	$attachment_id = get_post_meta( $post->ID, '_shiur_audio_id', true );
	$duration      = get_post_meta( $post->ID, '_shiur_duration', true );
	$file_url      = $attachment_id ? wp_get_attachment_url( $attachment_id ) : '';
	?>
	<p>
		<button type="button" class="button" id="ner_michoel_audio_select"><?php esc_html_e( 'Choose Audio or Video File', 'ner-michoel-core' ); ?></button>
		<button type="button" class="button-link-delete" id="ner_michoel_audio_remove" style="<?php echo $attachment_id ? '' : 'display:none;'; ?> margin-left:8px;"><?php esc_html_e( 'Remove', 'ner-michoel-core' ); ?></button>
	</p>
	<p class="description"><?php esc_html_e( 'Uploading a video file here makes this a video shiur automatically — no separate switch to set.', 'ner-michoel-core' ); ?></p>
	<p id="ner_michoel_audio_filename" style="word-break:break-all;">
		<?php echo $file_url ? esc_html( basename( $file_url ) ) : esc_html__( 'No file selected.', 'ner-michoel-core' ); ?>
	</p>
	<input type="hidden" name="ner_michoel_shiur_audio_id" id="ner_michoel_audio_id" value="<?php echo esc_attr( $attachment_id ); ?>" />

	<p>
		<label for="ner_michoel_shiur_duration"><?php esc_html_e( 'Duration (mm:ss, optional)', 'ner-michoel-core' ); ?></label><br />
		<input type="text" id="ner_michoel_shiur_duration" name="ner_michoel_shiur_duration" value="<?php echo esc_attr( $duration ); ?>" placeholder="45:30" style="width:100%;" />
	</p>
	<p class="description"><?php esc_html_e( 'Ordering within a series uses the Order field below (Page Attributes).', 'ner-michoel-core' ); ?></p>
	<?php
	// JS: assets/media-pickers.js (enqueued in ner_michoel_shiur_meta_box_assets()
	// below) — not an inline <script> here, since the block editor loads
	// this meta box in a way that doesn't reliably execute inline scripts.
}

function ner_michoel_save_shiur_meta( $post_id ) {
	if ( ! isset( $_POST['ner_michoel_shiur_meta_nonce'] ) ||
		! wp_verify_nonce( $_POST['ner_michoel_shiur_meta_nonce'], 'ner_michoel_save_shiur_meta' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	if ( isset( $_POST['ner_michoel_shiur_audio_id'] ) ) {
		$attachment_id = absint( $_POST['ner_michoel_shiur_audio_id'] );
		if ( $attachment_id ) {
			update_post_meta( $post_id, '_shiur_audio_id', $attachment_id );
		} else {
			delete_post_meta( $post_id, '_shiur_audio_id' );
		}
	}

	if ( isset( $_POST['ner_michoel_shiur_duration'] ) ) {
		update_post_meta( $post_id, '_shiur_duration', sanitize_text_field( $_POST['ner_michoel_shiur_duration'] ) );
	}
}
add_action( 'save_post_shiur', 'ner_michoel_save_shiur_meta' );

function ner_michoel_shiur_meta_box_assets( $hook ) {
	global $post_type;
	if ( 'shiur' !== $post_type || ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
		return;
	}
	ner_michoel_enqueue_media_pickers_script();
}
add_action( 'admin_enqueue_scripts', 'ner_michoel_shiur_meta_box_assets' );

/**
 * Front-end accessors.
 */

/**
 * 'audio' (default — every shiur created before video support existed
 * keeps working unchanged), 'video' (a self-hosted video file,
 * detected from the actual uploaded file's MIME type rather than a
 * separately-stored flag, so it can never drift out of sync with
 * what's actually attached), or 'video-embed' (no file at all —
 * externally hosted, e.g. Vimeo, via `_shiur_vimeo_id`; this exists
 * because the nermichoel.org library import found video shiurim that
 * were never self-hosted files to begin with — see dev-notes.md).
 */
function ner_michoel_get_shiur_media_type( $post_id ) {
	if ( get_post_meta( $post_id, '_shiur_vimeo_id', true ) ) {
		return 'video-embed';
	}
	$attachment_id = get_post_meta( $post_id, '_shiur_audio_id', true );
	if ( ! $attachment_id ) {
		return 'audio';
	}
	return wp_attachment_is( 'video', $attachment_id ) ? 'video' : 'audio';
}

/**
 * The shiur's Vimeo video ID, or '' if it has none (i.e. it's not a
 * 'video-embed' type shiur — see ner_michoel_get_shiur_media_type()).
 * Theme renders this as an <iframe src="https://player.vimeo.com/video/{id}">.
 */
function ner_michoel_get_shiur_vimeo_id( $post_id ) {
	$vimeo_id = get_post_meta( $post_id, '_shiur_vimeo_id', true );
	return $vimeo_id ? sanitize_text_field( $vimeo_id ) : '';
}

/**
 * The shiur's audio URL, or '' if it has no audio attached (including
 * when its attached file is actually a video — use
 * ner_michoel_get_shiur_video_url() for that).
 */
function ner_michoel_get_shiur_audio_url( $post_id ) {
	$attachment_id = get_post_meta( $post_id, '_shiur_audio_id', true );
	if ( ! $attachment_id || wp_attachment_is( 'video', $attachment_id ) ) {
		return '';
	}
	$url = wp_get_attachment_url( $attachment_id );
	return $url ? $url : '';
}

/**
 * Same contract as ner_michoel_get_shiur_audio_url(), for the video
 * case: URL string if this shiur's attached file is a video, '' otherwise.
 */
function ner_michoel_get_shiur_video_url( $post_id ) {
	$attachment_id = get_post_meta( $post_id, '_shiur_audio_id', true );
	if ( ! $attachment_id || ! wp_attachment_is( 'video', $attachment_id ) ) {
		return '';
	}
	$url = wp_get_attachment_url( $attachment_id );
	return $url ? $url : '';
}

function ner_michoel_get_shiur_duration( $post_id ) {
	$duration = get_post_meta( $post_id, '_shiur_duration', true );
	return $duration ? sanitize_text_field( $duration ) : '';
}

/**
 * Optional dedication ("l'zecher nishmas...", "in honor of...") — a
 * common pattern on Torah-content sites. Separate meta box from the
 * media box above since it's a distinct, unrelated concern.
 */
function ner_michoel_add_shiur_dedication_meta_box() {
	add_meta_box(
		'ner_michoel_shiur_dedication',
		__( 'Dedication (optional)', 'ner-michoel-core' ),
		'ner_michoel_render_shiur_dedication_meta_box',
		'shiur',
		'side',
		'default'
	);
}
add_action( 'add_meta_boxes', 'ner_michoel_add_shiur_dedication_meta_box' );

function ner_michoel_render_shiur_dedication_meta_box( $post ) {
	wp_nonce_field( 'ner_michoel_save_shiur_dedication', 'ner_michoel_shiur_dedication_nonce' );
	$dedication = get_post_meta( $post->ID, '_shiur_dedication', true );
	?>
	<label for="ner_michoel_shiur_dedication" class="screen-reader-text"><?php esc_html_e( 'Dedication', 'ner-michoel-core' ); ?></label>
	<textarea
		id="ner_michoel_shiur_dedication"
		name="ner_michoel_shiur_dedication"
		rows="3"
		style="width:100%;"
		placeholder="<?php esc_attr_e( "L'zecher nishmas...", 'ner-michoel-core' ); ?>"
	><?php echo esc_textarea( $dedication ); ?></textarea>
	<?php
}

function ner_michoel_save_shiur_dedication( $post_id ) {
	if ( ! isset( $_POST['ner_michoel_shiur_dedication_nonce'] ) ||
		! wp_verify_nonce( $_POST['ner_michoel_shiur_dedication_nonce'], 'ner_michoel_save_shiur_dedication' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	if ( isset( $_POST['ner_michoel_shiur_dedication'] ) ) {
		update_post_meta( $post_id, '_shiur_dedication', sanitize_textarea_field( wp_unslash( $_POST['ner_michoel_shiur_dedication'] ) ) );
	}
}
add_action( 'save_post_shiur', 'ner_michoel_save_shiur_dedication' );

/**
 * Front-end accessor: a shiur's dedication text, or '' if none is set.
 */
function ner_michoel_get_shiur_dedication( $post_id ) {
	$dedication = get_post_meta( $post_id, '_shiur_dedication', true );
	return $dedication ? sanitize_textarea_field( $dedication ) : '';
}
