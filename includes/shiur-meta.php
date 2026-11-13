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
		<strong><?php esc_html_e( 'Duration:', 'ner-michoel-core' ); ?></strong>
		<?php echo $duration ? esc_html( $duration ) : esc_html__( '—', 'ner-michoel-core' ); ?>
	</p>
	<p class="description"><?php esc_html_e( 'Set automatically from the audio or video file.', 'ner-michoel-core' ); ?></p>
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

	// Duration is not typed in. It's set from the file when the media is attached
	// (shiur-duration.php), so there's nothing to save here.
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
	$attachment_id = get_post_meta( $post_id, '_shiur_audio_id', true );
	// A shiur with an audio file AND a Vimeo link is an audio shiur that also has a video
	// (ner_michoel_get_shiur_extra_vimeo_id()). Before 2026-11-11 the Vimeo link won and the
	// audio disappeared from the page (R' Ariel Katz, shiurim 37 and 38).
	if ( $attachment_id && ! wp_attachment_is( 'video', $attachment_id ) ) {
		return 'audio';
	}
	if ( get_post_meta( $post_id, '_shiur_vimeo_id', true ) ) {
		return 'video-embed';
	}
	if ( ! $attachment_id ) {
		return 'audio';
	}
	return wp_attachment_is( 'video', $attachment_id ) ? 'video' : 'audio';
}

/**
 * The Vimeo video of an audio shiur that has both (one post for the audio and its
 * video, like the Add Shiur form: an audio file and a Vimeo link). '' when the shiur
 * has no Vimeo link, or when the Vimeo video is the shiur itself (no audio file).
 */
function ner_michoel_get_shiur_extra_vimeo_id( $post_id ) {
	if ( 'audio' !== ner_michoel_get_shiur_media_type( $post_id ) ) {
		return '';
	}
	return ner_michoel_get_shiur_vimeo_id( $post_id );
}

/**
 * A Vimeo video's number (and its private-link key, for an unlisted video) from
 * whatever was pasted in the Vimeo box: the number itself, a vimeo.com link, a
 * player.vimeo.com link, or Vimeo's whole embed code. "1243759820", or
 * "1243759820/abc123def0" for an unlisted video. '' when nothing in it is a Vimeo video.
 */
function ner_michoel_parse_vimeo_id( $text ) {
	$text = trim( html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' ) );
	if ( '' === $text ) {
		return '';
	}
	if ( preg_match( '/^\d{5,12}(?:\/[0-9a-f]{6,20})?$/i', $text ) ) {
		return strtolower( $text );
	}
	if ( preg_match( '#player\.vimeo\.com/video/(\d{5,12})([^\s"\'<>]*)#i', $text, $m ) || preg_match( '#vimeo\.com/(?:[^\s"\'<>]*?/)?(\d{5,12})((?:/[0-9a-f]{6,20})?[^\s"\'<>]*)#i', $text, $m ) ) {
		$id   = $m[1];
		$rest = $m[2];
		if ( preg_match( '#^/([0-9a-f]{6,20})(?:[/?\#]|$)#i', $rest, $h ) || preg_match( '/[?&](?:amp;)?h=([0-9a-f]{6,20})/i', $rest, $h ) ) {
			return $id . '/' . strtolower( $h[1] );
		}
		return $id;
	}
	return '';
}

/**
 * The Vimeo box is cleaned whenever it is saved (the Control Panel's shiur form, the
 * importer, wp-admin): a pasted Vimeo link or embed code is kept as the video's number,
 * so the player address is always right. Anything else (a Zoom recording's link, say)
 * is kept as typed, and the page shows it as a "Watch the video" link
 * (ner_michoel_shiur_video_embed()).
 */
function ner_michoel_sanitize_vimeo_meta( $value ) {
	$parsed = ner_michoel_parse_vimeo_id( $value );
	if ( '' !== $parsed ) {
		return $parsed;
	}
	$value = trim( html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' ) );
	if ( preg_match( '#https?://[^\s"\'<>]+#i', $value, $m ) ) {
		return esc_url_raw( $m[0] );
	}
	return sanitize_text_field( $value );
}
add_filter( 'sanitize_post_meta__shiur_vimeo_id', 'ner_michoel_sanitize_vimeo_meta' );

/**
 * How to show a shiur's video from the Vimeo box: array( 'type' => 'vimeo', 'src' =>
 * player address ) for a Vimeo video, array( 'type' => 'link', 'url' => ... ) for another
 * video address (a Zoom recording), or null when the box is empty or holds neither.
 */
function ner_michoel_shiur_video_embed( $stored ) {
	$stored = trim( (string) $stored );
	if ( '' === $stored ) {
		return null;
	}
	$vimeo = ner_michoel_parse_vimeo_id( $stored );
	if ( '' !== $vimeo ) {
		return array( 'type' => 'vimeo', 'src' => ner_michoel_vimeo_player_src( $vimeo ) );
	}
	if ( preg_match( '#^https?://#i', $stored ) ) {
		return array( 'type' => 'link', 'url' => $stored );
	}
	return null;
}

/**
 * The address for Vimeo's player for a stored video ("123" or "123/key").
 */
function ner_michoel_vimeo_player_src( $vimeo_id ) {
	$vimeo_id = (string) $vimeo_id;
	if ( ! preg_match( '/^(\d+)(?:\/([0-9a-f]+))?$/i', $vimeo_id, $m ) ) {
		$m = array( '', rawurlencode( $vimeo_id ), '' );
	}
	$url = 'https://player.vimeo.com/video/' . $m[1] . '?title=0&byline=0&portrait=0';
	if ( ! empty( $m[2] ) ) {
		$url .= '&h=' . $m[2];
	}
	return $url;
}

/**
 * The shiur's Vimeo video ID, or '' if it has none (i.e. it's not a
 * 'video-embed' type shiur — see ner_michoel_get_shiur_media_type()).
 * Theme renders this as an <iframe src="https://player.vimeo.com/video/{id}">.
 */
function ner_michoel_get_shiur_vimeo_id( $post_id ) {
	$vimeo_id = get_post_meta( $post_id, '_shiur_vimeo_id', true );
	// Not sanitize_text_field(): it takes the %20s out of a pasted video link. Callers escape it.
	return $vimeo_id ? trim( wp_strip_all_tags( (string) $vimeo_id ) ) : '';
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
