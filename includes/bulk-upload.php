<?php
/**
 * Bulk shiur uploader — pick multiple audio/video files at once (the
 * same wp.media picker the per-shiur meta box uses, with multi-select
 * turned on), auto-create one draft `shiur` post per file with a title
 * guessed from the filename, then a single follow-up screen assigns
 * one speaker + one series to the whole batch and publishes it.
 *
 * Posts stay in `draft` until that follow-up step completes — an
 * admin who navigates away mid-batch just leaves drafts sitting in
 * the Shiurim list, not half-finished posts live on the site.
 *
 * The in-progress batch (an array of post IDs) is held in a
 * per-user transient rather than passed through the URL, since a
 * large batch could easily exceed a comfortable query-string length.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NER_MICHOEL_BULK_UPLOAD_TRANSIENT_PREFIX', 'nm_bulk_upload_' );

function ner_michoel_bulk_upload_transient_key() {
	return NER_MICHOEL_BULK_UPLOAD_TRANSIENT_PREFIX . get_current_user_id();
}

function ner_michoel_render_bulk_upload_page() {
	?>
	<div class="wrap nm-dashboard">
		<h1><?php esc_html_e( 'Bulk Upload Shiurim', 'ner-michoel-core' ); ?></h1>
		<p><?php esc_html_e( 'Pick multiple audio or video files at once. Each becomes its own Shiur (titled from the filename) — you\'ll assign a speaker and series to the whole batch in one step next.', 'ner-michoel-core' ); ?></p>

		<p>
			<button type="button" class="button button-primary" id="nm_bulk_upload_select"><?php esc_html_e( 'Choose Files', 'ner-michoel-core' ); ?></button>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="nm_bulk_upload_form">
			<?php wp_nonce_field( 'nm_bulk_create_shiurim', 'nm_bulk_upload_nonce' ); ?>
			<input type="hidden" name="action" value="nm_bulk_create_shiurim" />
			<table class="widefat striped" id="nm_bulk_upload_table" style="display:none;max-width:720px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'File', 'ner-michoel-core' ); ?></th>
						<th><?php esc_html_e( 'Shiur Title', 'ner-michoel-core' ); ?></th>
					</tr>
				</thead>
				<tbody></tbody>
			</table>
			<p>
				<button type="submit" class="button button-primary" id="nm_bulk_upload_submit" style="display:none;"><?php esc_html_e( 'Create Shiurim', 'ner-michoel-core' ); ?></button>
			</p>
		</form>
	</div>
	<script>
	( function( $ ) {
		var frame;

		var guessTitle = function( filename ) {
			var name = filename.replace( /\.[^/.]+$/, '' );
			name = name.replace( /[_-]+/g, ' ' ).replace( /\s+/g, ' ' ).trim();
			return name.replace( /\w\S*/g, function ( word ) {
				return word.charAt( 0 ).toUpperCase() + word.substr( 1 );
			} );
		};

		var addRow = function( id, filename, title ) {
			var $row = $( '<tr></tr>' );
			$row.append( $( '<td></td>' ).text( filename ).append(
				$( '<input type="hidden" name="nm_bulk_attachment_id[]">' ).val( id )
			) );
			$row.append( $( '<td></td>' ).append(
				$( '<input type="text" name="nm_bulk_title[]" class="widefat">' ).val( title )
			) );
			$( '#nm_bulk_upload_table tbody' ).append( $row );
		};

		$( '#nm_bulk_upload_select' ).on( 'click', function( e ) {
			e.preventDefault();
			if ( frame ) {
				frame.open();
				return;
			}
			frame = wp.media( {
				title: <?php echo wp_json_encode( __( 'Select or upload audio/video files', 'ner-michoel-core' ) ); ?>,
				library: { type: [ 'audio', 'video' ] },
				multiple: true
			} );
			frame.on( 'select', function() {
				var selection = frame.state().get( 'selection' );
				$( '#nm_bulk_upload_table tbody' ).empty();
				selection.each( function( attachment ) {
					var data = attachment.toJSON();
					addRow( data.id, data.filename, guessTitle( data.filename ) );
				} );
				$( '#nm_bulk_upload_table' ).show();
				$( '#nm_bulk_upload_submit' ).show();
			} );
			frame.open();
		} );
	} )( jQuery );
	</script>
	<?php
}

function ner_michoel_handle_bulk_create_shiurim() {
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'ner-michoel-core' ) );
	}
	check_admin_referer( 'nm_bulk_create_shiurim', 'nm_bulk_upload_nonce' );

	$attachment_ids = isset( $_POST['nm_bulk_attachment_id'] ) ? array_map( 'absint', (array) $_POST['nm_bulk_attachment_id'] ) : array();
	$titles         = isset( $_POST['nm_bulk_title'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['nm_bulk_title'] ) ) : array();

	$created = array();

	foreach ( $attachment_ids as $i => $attachment_id ) {
		if ( ! $attachment_id ) {
			continue;
		}

		$title = ( isset( $titles[ $i ] ) && '' !== $titles[ $i ] ) ? $titles[ $i ] : __( 'Untitled Shiur', 'ner-michoel-core' );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'shiur',
				'post_title'  => $title,
				'post_status' => 'draft',
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			continue;
		}

		update_post_meta( $post_id, '_shiur_audio_id', $attachment_id );
		$created[] = $post_id;
	}

	if ( empty( $created ) ) {
		wp_safe_redirect( admin_url( 'admin.php?page=nm-bulk-upload&nm_bulk=empty' ) );
		exit;
	}

	set_transient( ner_michoel_bulk_upload_transient_key(), $created, HOUR_IN_SECONDS );

	wp_safe_redirect( admin_url( 'admin.php?page=nm-bulk-assign' ) );
	exit;
}
add_action( 'admin_post_nm_bulk_create_shiurim', 'ner_michoel_handle_bulk_create_shiurim' );

function ner_michoel_render_bulk_assign_page() {
	$post_ids = get_transient( ner_michoel_bulk_upload_transient_key() );

	if ( ! $post_ids ) {
		?>
		<div class="wrap nm-dashboard">
			<h1><?php esc_html_e( 'Assign Batch', 'ner-michoel-core' ); ?></h1>
			<p><?php esc_html_e( 'No pending batch found — it may have expired (batches last an hour), or already been assigned.', 'ner-michoel-core' ); ?></p>
			<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=nm-bulk-upload' ) ); ?>"><?php esc_html_e( 'Start a new bulk upload', 'ner-michoel-core' ); ?></a></p>
		</div>
		<?php
		return;
	}

	$speakers = get_terms( array( 'taxonomy' => 'speaker', 'hide_empty' => false ) );
	$series   = get_terms( array( 'taxonomy' => 'series', 'hide_empty' => false ) );
	?>
	<div class="wrap nm-dashboard">
		<h1><?php esc_html_e( 'Assign Batch', 'ner-michoel-core' ); ?></h1>
		<p>
			<?php
			printf(
				/* translators: %d: number of shiurim in the batch */
				esc_html(
					_n(
						'%d shiur was just created as a draft. Assign a speaker and series to the whole batch, then publish.',
						'%d shiurim were just created as drafts. Assign a speaker and series to the whole batch, then publish.',
						count( $post_ids ),
						'ner-michoel-core'
					)
				),
				count( $post_ids )
			);
			?>
		</p>
		<ul>
			<?php foreach ( $post_ids as $post_id ) : ?>
				<li>
					<?php echo esc_html( get_the_title( $post_id ) ); ?>
					&mdash;
					<a href="<?php echo esc_url( get_edit_post_link( $post_id ) ); ?>"><?php esc_html_e( 'edit', 'ner-michoel-core' ); ?></a>
				</li>
			<?php endforeach; ?>
		</ul>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'nm_bulk_assign_shiurim', 'nm_bulk_assign_nonce' ); ?>
			<input type="hidden" name="action" value="nm_bulk_assign_shiurim" />
			<table class="form-table">
				<tr>
					<th scope="row"><label for="nm_bulk_speaker"><?php esc_html_e( 'Speaker', 'ner-michoel-core' ); ?></label></th>
					<td>
						<select name="nm_bulk_speaker" id="nm_bulk_speaker">
							<option value=""><?php esc_html_e( '— None —', 'ner-michoel-core' ); ?></option>
							<?php foreach ( $speakers as $term ) : ?>
								<option value="<?php echo esc_attr( $term->term_id ); ?>"><?php echo esc_html( $term->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="nm_bulk_series"><?php esc_html_e( 'Series', 'ner-michoel-core' ); ?></label></th>
					<td>
						<select name="nm_bulk_series" id="nm_bulk_series">
							<option value=""><?php esc_html_e( '— None —', 'ner-michoel-core' ); ?></option>
							<?php foreach ( $series as $term ) : ?>
								<option value="<?php echo esc_attr( $term->term_id ); ?>"><?php echo esc_html( $term->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
			</table>
			<p>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Assign & Publish', 'ner-michoel-core' ); ?></button>
			</p>
		</form>
	</div>
	<?php
}

function ner_michoel_handle_bulk_assign_shiurim() {
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'ner-michoel-core' ) );
	}
	check_admin_referer( 'nm_bulk_assign_shiurim', 'nm_bulk_assign_nonce' );

	$post_ids = get_transient( ner_michoel_bulk_upload_transient_key() );
	if ( ! $post_ids ) {
		wp_safe_redirect( admin_url( 'admin.php?page=nm-bulk-upload' ) );
		exit;
	}

	$speaker_id = isset( $_POST['nm_bulk_speaker'] ) ? absint( $_POST['nm_bulk_speaker'] ) : 0;
	$series_id  = isset( $_POST['nm_bulk_series'] ) ? absint( $_POST['nm_bulk_series'] ) : 0;

	foreach ( $post_ids as $post_id ) {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			continue;
		}
		if ( $speaker_id ) {
			wp_set_object_terms( $post_id, array( $speaker_id ), 'speaker' );
		}
		if ( $series_id ) {
			wp_set_object_terms( $post_id, array( $series_id ), 'series' );
		}
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);
	}

	delete_transient( ner_michoel_bulk_upload_transient_key() );

	wp_safe_redirect( admin_url( 'edit.php?post_type=shiur&nm_bulk=done' ) );
	exit;
}
add_action( 'admin_post_nm_bulk_assign_shiurim', 'ner_michoel_handle_bulk_assign_shiurim' );
