<?php
/**
 * Photo Gallery post type — an ordered set of images (e.g. an event's
 * photos), stored as its own post so it gets a URL/listing like
 * Shiurim rather than living inside an unrelated page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ner_michoel_register_gallery_post_type() {
	register_post_type(
		'gallery',
		array(
			'labels'        => array(
				'name'               => __( 'Galleries', 'ner-michoel-core' ),
				'singular_name'      => __( 'Gallery', 'ner-michoel-core' ),
				'add_new_item'       => __( 'Add New Gallery', 'ner-michoel-core' ),
				'edit_item'          => __( 'Edit Gallery', 'ner-michoel-core' ),
				'view_item'          => __( 'View Gallery', 'ner-michoel-core' ),
				'search_items'       => __( 'Search Galleries', 'ner-michoel-core' ),
				'not_found'          => __( 'No galleries found', 'ner-michoel-core' ),
				'not_found_in_trash' => __( 'No galleries found in Trash', 'ner-michoel-core' ),
				'all_items'          => __( 'All Galleries', 'ner-michoel-core' ),
				'menu_name'          => __( 'Galleries', 'ner-michoel-core' ),
			),
			'public'        => true,
			'has_archive'   => 'galleries',
			'rewrite'       => array( 'slug' => 'gallery', 'with_front' => false ),
			'menu_icon'     => 'dashicons-format-gallery',
			'supports'      => array( 'title', 'editor', 'thumbnail' ),
			'show_in_rest'  => true,
			'menu_position' => 21,
		)
	);
}
add_action( 'init', 'ner_michoel_register_gallery_post_type' );

/**
 * Which section a gallery belongs to — Photo, Video, or Shiurim
 * Video. Rewrite slug is 'galleries' (matching the CPT archive) so
 * term URLs land at /galleries/photo/, /galleries/video/,
 * /galleries/videoshiurim/ rather than the default taxonomy prefix.
 */
function ner_michoel_register_gallery_type_taxonomy() {
	register_taxonomy(
		'gallery_type',
		'gallery',
		array(
			'labels'            => array(
				'name'          => __( 'Gallery Types', 'ner-michoel-core' ),
				'singular_name' => __( 'Gallery Type', 'ner-michoel-core' ),
				'all_items'     => __( 'All Types', 'ner-michoel-core' ),
			),
			'hierarchical'      => false,
			'public'            => true,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'rewrite'           => array( 'slug' => 'galleries', 'with_front' => false ),
		)
	);
}
add_action( 'init', 'ner_michoel_register_gallery_type_taxonomy' );

/**
 * Seeds the three sections the real site's nav expects (Photo, Video,
 * Shiurim Video), with slugs chosen to match /galleries/photo,
 * /galleries/video, /galleries/videoshiurim exactly. Safe to call
 * repeatedly — term_exists() short-circuits once they're created.
 */
function ner_michoel_create_default_gallery_types() {
	$defaults = array(
		'photo'        => __( 'Photo', 'ner-michoel-core' ),
		'video'        => __( 'Video', 'ner-michoel-core' ),
		'videoshiurim' => __( 'Shiurim Video', 'ner-michoel-core' ),
	);
	foreach ( $defaults as $slug => $name ) {
		if ( ! term_exists( $slug, 'gallery_type' ) ) {
			wp_insert_term( $name, 'gallery_type', array( 'slug' => $slug ) );
		}
	}
}

/**
 * Two sizes cover the whole UI:
 * - nm_gallery_thumb: hard-cropped square, for grid thumbnails.
 * - nm_gallery_slide: capped bounding box, NOT cropped — a slider
 *   fits each image into its frame via CSS (object-fit) without
 *   stretching, whatever the original's aspect ratio was.
 * Both are generated at upload time, so the front end never ships a
 * full-resolution original just to shrink it in the browser.
 */
function ner_michoel_register_gallery_image_sizes() {
	add_image_size( 'nm_gallery_thumb', 400, 400, true );
	add_image_size( 'nm_gallery_slide', 1600, 1000, false );
}
add_action( 'after_setup_theme', 'ner_michoel_register_gallery_image_sizes' );

/**
 * Admin UI: multi-image picker + drag-to-reorder. Stored as an
 * ordered array of attachment IDs — the order set here is what the
 * grid/slider render in.
 */
function ner_michoel_add_gallery_meta_box() {
	add_meta_box(
		'ner_michoel_gallery_images',
		__( 'Gallery Images', 'ner-michoel-core' ),
		'ner_michoel_render_gallery_meta_box',
		'gallery',
		'normal',
		'high'
	);
	add_meta_box(
		'ner_michoel_gallery_videos',
		__( 'Video URLs', 'ner-michoel-core' ),
		'ner_michoel_render_gallery_videos_meta_box',
		'gallery',
		'normal',
		'default'
	);
}
add_action( 'add_meta_boxes', 'ner_michoel_add_gallery_meta_box' );

/**
 * For a Video / Shiurim Video gallery: one oEmbed-friendly URL
 * (YouTube, Vimeo) per line, in display order. Kept as a plain
 * textarea rather than a repeater/uploader — these are external
 * embeds, not media library attachments, and reordering lines is
 * enough for how often a gallery's video list changes.
 */
function ner_michoel_render_gallery_videos_meta_box( $post ) {
	wp_nonce_field( 'ner_michoel_save_gallery_videos', 'ner_michoel_gallery_videos_nonce' );
	$urls = get_post_meta( $post->ID, '_nm_gallery_video_urls', true );
	$urls = is_array( $urls ) ? $urls : array();
	?>
	<p class="description"><?php esc_html_e( 'Only used when this gallery\'s Gallery Type is Video or Shiurim Video. One YouTube or Vimeo URL per line, in display order.', 'ner-michoel-core' ); ?></p>
	<textarea name="ner_michoel_gallery_video_urls" rows="8" style="width:100%;font-family:monospace;"><?php echo esc_textarea( implode( "\n", $urls ) ); ?></textarea>
	<?php
}

function ner_michoel_save_gallery_videos( $post_id ) {
	if ( ! isset( $_POST['ner_michoel_gallery_videos_nonce'] ) ||
		! wp_verify_nonce( $_POST['ner_michoel_gallery_videos_nonce'], 'ner_michoel_save_gallery_videos' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	if ( isset( $_POST['ner_michoel_gallery_video_urls'] ) ) {
		$lines = preg_split( '/\r\n|\r|\n/', wp_unslash( $_POST['ner_michoel_gallery_video_urls'] ) );
		$urls  = array_values( array_filter( array_map( 'esc_url_raw', array_map( 'trim', $lines ) ) ) );
		update_post_meta( $post_id, '_nm_gallery_video_urls', $urls );
	}
}
add_action( 'save_post_gallery', 'ner_michoel_save_gallery_videos' );

function ner_michoel_render_gallery_meta_box( $post ) {
	wp_nonce_field( 'ner_michoel_save_gallery_meta', 'ner_michoel_gallery_meta_nonce' );

	$ids = get_post_meta( $post->ID, '_nm_gallery_image_ids', true );
	$ids = is_array( $ids ) ? array_map( 'absint', $ids ) : array();
	?>
	<div id="ner_michoel_gallery_images" style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:12px;">
		<?php foreach ( $ids as $id ) :
			$thumb = wp_get_attachment_image_url( $id, 'thumbnail' );
			if ( ! $thumb ) {
				continue;
			}
			?>
			<div class="nm-gallery-item" data-id="<?php echo esc_attr( $id ); ?>" style="position:relative;cursor:move;">
				<img src="<?php echo esc_url( $thumb ); ?>" style="width:100px;height:100px;object-fit:cover;display:block;border:1px solid #ccc;" />
				<button type="button" class="nm-gallery-remove button-link-delete" style="position:absolute;top:2px;right:2px;background:#fff;border-radius:50%;line-height:1;padding:2px 6px;">&times;</button>
			</div>
		<?php endforeach; ?>
	</div>
	<input type="hidden" name="ner_michoel_gallery_image_ids" id="ner_michoel_gallery_image_ids" value="<?php echo esc_attr( implode( ',', $ids ) ); ?>" />
	<button type="button" class="button" id="ner_michoel_gallery_add"><?php esc_html_e( 'Add Images', 'ner-michoel-core' ); ?></button>
	<p class="description"><?php esc_html_e( 'Drag thumbnails to reorder. This order is used for the grid and slider.', 'ner-michoel-core' ); ?></p>
	<script>
	( function( $ ) {
		var frame;
		var $list  = $( '#ner_michoel_gallery_images' );
		var $input = $( '#ner_michoel_gallery_image_ids' );

		function syncInput() {
			var ids = [];
			$list.find( '.nm-gallery-item' ).each( function () {
				ids.push( $( this ).data( 'id' ) );
			} );
			$input.val( ids.join( ',' ) );
		}

		$list.sortable( { update: syncInput } );

		$( '#ner_michoel_gallery_add' ).on( 'click', function ( e ) {
			e.preventDefault();
			frame = wp.media( {
				title: <?php echo wp_json_encode( __( 'Select gallery images', 'ner-michoel-core' ) ); ?>,
				library: { type: 'image' },
				multiple: true
			} );
			frame.on( 'select', function () {
				var selection = frame.state().get( 'selection' );
				selection.each( function ( attachment ) {
					var data     = attachment.toJSON();
					var thumbUrl = ( data.sizes && data.sizes.thumbnail ) ? data.sizes.thumbnail.url : data.url;
					var $item = $( '<div class="nm-gallery-item" style="position:relative;cursor:move;"></div>' )
						.attr( 'data-id', data.id )
						.append(
							$( '<img>' ).attr( 'src', thumbUrl ).css( {
								width: 100,
								height: 100,
								objectFit: 'cover',
								display: 'block',
								border: '1px solid #ccc'
							} )
						)
						.append(
							$( '<button type="button" class="nm-gallery-remove button-link-delete">&times;</button>' ).css( {
								position: 'absolute',
								top: 2,
								right: 2,
								background: '#fff',
								borderRadius: '50%',
								lineHeight: 1,
								padding: '2px 6px'
							} )
						);
					$list.append( $item );
				} );
				syncInput();
			} );
			frame.open();
		} );

		$list.on( 'click', '.nm-gallery-remove', function () {
			$( this ).closest( '.nm-gallery-item' ).remove();
			syncInput();
		} );
	} )( jQuery );
	</script>
	<?php
}

function ner_michoel_save_gallery_meta( $post_id ) {
	if ( ! isset( $_POST['ner_michoel_gallery_meta_nonce'] ) ||
		! wp_verify_nonce( $_POST['ner_michoel_gallery_meta_nonce'], 'ner_michoel_save_gallery_meta' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	if ( isset( $_POST['ner_michoel_gallery_image_ids'] ) ) {
		$raw = sanitize_text_field( wp_unslash( $_POST['ner_michoel_gallery_image_ids'] ) );
		$ids = '' !== $raw ? array_map( 'absint', explode( ',', $raw ) ) : array();
		$ids = array_values( array_filter( $ids ) );
		update_post_meta( $post_id, '_nm_gallery_image_ids', $ids );
	}
}
add_action( 'save_post_gallery', 'ner_michoel_save_gallery_meta' );

function ner_michoel_gallery_meta_box_assets( $hook ) {
	global $post_type;
	if ( 'gallery' !== $post_type || ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
		return;
	}
	wp_enqueue_media();
	wp_enqueue_script( 'jquery-ui-sortable' );
}
add_action( 'admin_enqueue_scripts', 'ner_michoel_gallery_meta_box_assets' );

/**
 * Front-end accessors.
 */

/**
 * Ordered image data for a gallery, in the shape a grid/slider needs:
 * a cropped thumb for grid cells, an uncropped bounded "slide" URL +
 * srcset for a slider (fit via CSS object-fit, not stretched), and
 * native width/height so the browser can reserve space up front
 * instead of shifting layout while images load.
 */
function ner_michoel_get_gallery_images( $post_id ) {
	$ids = get_post_meta( $post_id, '_nm_gallery_image_ids', true );
	$ids = is_array( $ids ) ? array_map( 'absint', $ids ) : array();

	$images = array();
	foreach ( $ids as $id ) {
		$slide = wp_get_attachment_image_src( $id, 'nm_gallery_slide' );
		if ( ! $slide ) {
			continue;
		}
		$thumb = wp_get_attachment_image_src( $id, 'nm_gallery_thumb' );

		$images[] = array(
			'id'      => $id,
			'url'     => $slide[0],
			'width'   => $slide[1],
			'height'  => $slide[2],
			'thumb'   => $thumb ? $thumb[0] : $slide[0],
			'srcset'  => (string) wp_get_attachment_image_srcset( $id, 'nm_gallery_slide' ),
			'alt'     => get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'caption' => wp_get_attachment_caption( $id ),
		);
	}
	return $images;
}

function ner_michoel_get_gallery_image_count( $post_id ) {
	$ids = get_post_meta( $post_id, '_nm_gallery_image_ids', true );
	return is_array( $ids ) ? count( $ids ) : 0;
}

/**
 * Cover image for a gallery card: the featured image if one's set,
 * else the first gallery image, else empty (caller falls back to a
 * placeholder — see ner_michoel_placeholder_art() in the theme).
 */
function ner_michoel_get_gallery_cover_url( $post_id, $size = 'nm_gallery_thumb' ) {
	if ( has_post_thumbnail( $post_id ) ) {
		$src = wp_get_attachment_image_src( get_post_thumbnail_id( $post_id ), $size );
		if ( $src ) {
			return $src[0];
		}
	}
	$images = ner_michoel_get_gallery_images( $post_id );
	return $images ? $images[0]['thumb'] : '';
}

/**
 * Ordered list of raw video URLs (YouTube/Vimeo) for a Video/Shiurim
 * Video gallery. Rendering (oEmbed/[embed]) is the theme's job — this
 * just hands back the URLs in the order they were entered.
 */
function ner_michoel_get_gallery_videos( $post_id ) {
	$urls = get_post_meta( $post_id, '_nm_gallery_video_urls', true );
	return is_array( $urls ) ? $urls : array();
}

/**
 * The gallery's section — 'photo', 'video', 'videoshiurim', or ''
 * if no Gallery Type term is assigned yet. Templates use this to
 * decide whether to render the image grid/slider or the video list.
 */
function ner_michoel_get_gallery_type( $post_id ) {
	$terms = get_the_terms( $post_id, 'gallery_type' );
	if ( ! $terms || is_wp_error( $terms ) ) {
		return '';
	}
	return $terms[0]->slug;
}
