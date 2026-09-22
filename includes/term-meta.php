<?php
/**
 * Optional cover image for `speaker` and `series` terms (term meta),
 * so speaker/series cards have "artist photo" / "album art" the way a
 * streaming app would, instead of falling back to a placeholder.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ner_michoel_taxonomy_image_field( $taxonomy_object, $term = null ) {
	$image_id  = $term ? get_term_meta( $term->term_id, 'ner_michoel_image_id', true ) : '';
	$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'medium' ) : '';
	$is_edit   = (bool) $term;
	?>
	<?php if ( $is_edit ) : ?>
	<tr class="form-field">
		<th scope="row"><label for="ner_michoel_image_id"><?php esc_html_e( 'Cover Image', 'ner-michoel-core' ); ?></label></th>
		<td>
			<div id="ner_michoel_term_image_preview" style="margin-bottom:8px;">
				<?php if ( $image_url ) : ?>
					<img src="<?php echo esc_url( $image_url ); ?>" style="max-width:150px;height:auto;display:block;" />
				<?php endif; ?>
			</div>
			<input type="hidden" name="ner_michoel_image_id" id="ner_michoel_image_id" value="<?php echo esc_attr( $image_id ); ?>" />
			<button type="button" class="button" id="ner_michoel_term_image_select"><?php esc_html_e( 'Choose Image', 'ner-michoel-core' ); ?></button>
			<button type="button" class="button-link-delete" id="ner_michoel_term_image_remove" style="margin-left:8px;<?php echo $image_id ? '' : 'display:none;'; ?>"><?php esc_html_e( 'Remove', 'ner-michoel-core' ); ?></button>
		</td>
	</tr>
	<?php else : ?>
	<div class="form-field">
		<label for="ner_michoel_image_id"><?php esc_html_e( 'Cover Image', 'ner-michoel-core' ); ?></label>
		<div id="ner_michoel_term_image_preview" style="margin-bottom:8px;"></div>
		<input type="hidden" name="ner_michoel_image_id" id="ner_michoel_image_id" value="" />
		<button type="button" class="button" id="ner_michoel_term_image_select"><?php esc_html_e( 'Choose Image', 'ner-michoel-core' ); ?></button>
	</div>
	<?php endif; ?>
	<script>
	( function( $ ) {
		var frame;
		$( '#ner_michoel_term_image_select' ).on( 'click', function( e ) {
			e.preventDefault();
			if ( frame ) { frame.open(); return; }
			frame = wp.media( {
				title: <?php echo wp_json_encode( __( 'Select or upload a cover image', 'ner-michoel-core' ) ); ?>,
				library: { type: 'image' },
				multiple: false
			} );
			frame.on( 'select', function() {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				var url = attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url;
				$( '#ner_michoel_image_id' ).val( attachment.id );
				$( '#ner_michoel_term_image_preview' ).html( '<img src="' + url + '" style="max-width:150px;height:auto;display:block;" />' );
				$( '#ner_michoel_term_image_remove' ).show();
			} );
			frame.open();
		} );
		$( '#ner_michoel_term_image_remove' ).on( 'click', function( e ) {
			e.preventDefault();
			$( '#ner_michoel_image_id' ).val( '' );
			$( '#ner_michoel_term_image_preview' ).empty();
			$( this ).hide();
		} );
	} )( jQuery );
	</script>
	<?php
}

function ner_michoel_taxonomy_image_add_field( $taxonomy ) {
	ner_michoel_taxonomy_image_field( $taxonomy );
}
add_action( 'speaker_add_form_fields', 'ner_michoel_taxonomy_image_add_field' );
add_action( 'series_add_form_fields', 'ner_michoel_taxonomy_image_add_field' );

function ner_michoel_taxonomy_image_edit_field( $term, $taxonomy ) {
	ner_michoel_taxonomy_image_field( $taxonomy, $term );
}
add_action( 'speaker_edit_form_fields', 'ner_michoel_taxonomy_image_edit_field', 10, 2 );
add_action( 'series_edit_form_fields', 'ner_michoel_taxonomy_image_edit_field', 10, 2 );

function ner_michoel_save_taxonomy_image( $term_id ) {
	if ( ! current_user_can( 'manage_categories' ) ) {
		return;
	}
	if ( isset( $_POST['ner_michoel_image_id'] ) ) {
		$image_id = absint( $_POST['ner_michoel_image_id'] );
		if ( $image_id ) {
			update_term_meta( $term_id, 'ner_michoel_image_id', $image_id );
		} else {
			delete_term_meta( $term_id, 'ner_michoel_image_id' );
		}
	}
}
add_action( 'created_speaker', 'ner_michoel_save_taxonomy_image' );
add_action( 'edited_speaker', 'ner_michoel_save_taxonomy_image' );
add_action( 'created_series', 'ner_michoel_save_taxonomy_image' );
add_action( 'edited_series', 'ner_michoel_save_taxonomy_image' );

function ner_michoel_taxonomy_image_assets( $hook ) {
	if ( ! in_array( $hook, array( 'term.php', 'edit-tags.php' ), true ) ) {
		return;
	}
	$taxonomy = isset( $_GET['taxonomy'] ) ? sanitize_key( $_GET['taxonomy'] ) : '';
	if ( in_array( $taxonomy, array( 'speaker', 'series' ), true ) ) {
		wp_enqueue_media();
	}
}
add_action( 'admin_enqueue_scripts', 'ner_michoel_taxonomy_image_assets' );

/**
 * Purpose-specific crops so a speaker photo and a series cover don't
 * both fall back to WordPress' generic 'medium' size (whatever shape
 * the original happens to be). Both are square hard crops — a round
 * speaker photo is just this crop shown with border-radius in CSS.
 * Actual generation is deferred to a background job — see
 * includes/image-processing.php — so attaching a large original
 * doesn't make the term-edit screen wait on the resize.
 */
function ner_michoel_register_term_image_sizes() {
	add_image_size( 'nm_speaker_photo', 500, 500, true );
	add_image_size( 'nm_series_cover', 500, 500, true );
}
add_action( 'after_setup_theme', 'ner_michoel_register_term_image_sizes' );

/**
 * Front-end accessor: cover image URL for a speaker/series term, or
 * empty string if none is set (templates fall back to a placeholder).
 */
function ner_michoel_get_term_image_url( $term_id, $size = 'medium' ) {
	$image_id = get_term_meta( $term_id, 'ner_michoel_image_id', true );
	if ( ! $image_id ) {
		return '';
	}
	$url = wp_get_attachment_image_url( $image_id, $size );
	return $url ? $url : '';
}

/**
 * Speaker photo at its dedicated square crop.
 */
function ner_michoel_get_speaker_photo_url( $term_id ) {
	return ner_michoel_get_term_image_url( $term_id, 'nm_speaker_photo' );
}

/**
 * Series cover at its dedicated square crop.
 */
function ner_michoel_get_series_cover_url( $term_id ) {
	return ner_michoel_get_term_image_url( $term_id, 'nm_series_cover' );
}
