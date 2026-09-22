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
		__( 'Audio', 'ner-michoel-core' ),
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
		<button type="button" class="button" id="ner_michoel_audio_select"><?php esc_html_e( 'Choose Audio File', 'ner-michoel-core' ); ?></button>
		<button type="button" class="button-link-delete" id="ner_michoel_audio_remove" style="<?php echo $attachment_id ? '' : 'display:none;'; ?> margin-left:8px;"><?php esc_html_e( 'Remove', 'ner-michoel-core' ); ?></button>
	</p>
	<p id="ner_michoel_audio_filename" style="word-break:break-all;">
		<?php echo $file_url ? esc_html( basename( $file_url ) ) : esc_html__( 'No file selected.', 'ner-michoel-core' ); ?>
	</p>
	<input type="hidden" name="ner_michoel_shiur_audio_id" id="ner_michoel_audio_id" value="<?php echo esc_attr( $attachment_id ); ?>" />

	<p>
		<label for="ner_michoel_shiur_duration"><?php esc_html_e( 'Duration (mm:ss, optional)', 'ner-michoel-core' ); ?></label><br />
		<input type="text" id="ner_michoel_shiur_duration" name="ner_michoel_shiur_duration" value="<?php echo esc_attr( $duration ); ?>" placeholder="45:30" style="width:100%;" />
	</p>
	<p class="description"><?php esc_html_e( 'Ordering within a series uses the Order field below (Page Attributes).', 'ner-michoel-core' ); ?></p>
	<script>
	( function( $ ) {
		var frame;
		$( '#ner_michoel_audio_select' ).on( 'click', function( e ) {
			e.preventDefault();
			if ( frame ) { frame.open(); return; }
			frame = wp.media( {
				title: <?php echo wp_json_encode( __( 'Select or upload an audio file', 'ner-michoel-core' ) ); ?>,
				library: { type: 'audio' },
				multiple: false
			} );
			frame.on( 'select', function() {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				$( '#ner_michoel_audio_id' ).val( attachment.id );
				$( '#ner_michoel_audio_filename' ).text( attachment.filename );
				$( '#ner_michoel_audio_remove' ).show();
			} );
			frame.open();
		} );
		$( '#ner_michoel_audio_remove' ).on( 'click', function( e ) {
			e.preventDefault();
			$( '#ner_michoel_audio_id' ).val( '' );
			$( '#ner_michoel_audio_filename' ).text( <?php echo wp_json_encode( __( 'No file selected.', 'ner-michoel-core' ) ); ?> );
			$( this ).hide();
		} );
	} )( jQuery );
	</script>
	<?php
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
	wp_enqueue_media();
}
add_action( 'admin_enqueue_scripts', 'ner_michoel_shiur_meta_box_assets' );

/**
 * Front-end accessors.
 */

function ner_michoel_get_shiur_audio_url( $post_id ) {
	$attachment_id = get_post_meta( $post_id, '_shiur_audio_id', true );
	if ( ! $attachment_id ) {
		return '';
	}
	$url = wp_get_attachment_url( $attachment_id );
	return $url ? $url : '';
}

function ner_michoel_get_shiur_duration( $post_id ) {
	$duration = get_post_meta( $post_id, '_shiur_duration', true );
	return $duration ? sanitize_text_field( $duration ) : '';
}
