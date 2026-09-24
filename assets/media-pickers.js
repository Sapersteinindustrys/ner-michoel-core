/**
 * Shared wp.media() pickers for the plugin's various "Choose
 * Image/File(s)" admin buttons — one properly-enqueued file using
 * event delegation (bound to `document`, not the specific button)
 * instead of each meta box printing its own inline <script>.
 *
 * Why: an inline <script> tag inside a classic meta box isn't
 * guaranteed to execute when the Block Editor loads that meta box's
 * markup — injecting HTML via innerHTML/DOM-move doesn't execute
 * embedded <script> tags the way a synchronously-parsed page does,
 * which was silently breaking these buttons on the post types that
 * use the block editor (shiur, gallery — both show_in_rest => true).
 * Event delegation sidesteps the question of *when* a button's HTML
 * actually lands in the DOM; a MutationObserver fallback below
 * handles the one case that isn't a click (jQuery UI Sortable, which
 * has to be initialized on the actual element once it exists).
 *
 * Localized strings come from `nmMediaPickers` (see
 * ner_michoel_enqueue_media_pickers_script() in admin-dashboard.php).
 */
( function ( $ ) {
	'use strict';

	function whenElementExists( selector, callback ) {
		var $existing = $( selector );
		if ( $existing.length ) {
			callback( $existing );
			return;
		}
		if ( typeof MutationObserver === 'undefined' ) {
			return;
		}
		var observer = new MutationObserver( function () {
			var $found = $( selector );
			if ( $found.length ) {
				observer.disconnect();
				callback( $found );
			}
		} );
		observer.observe( document.body, { childList: true, subtree: true } );
	}

	var strings = window.nmMediaPickers || {};

	/* ---- Gallery: Add Images (multi, sortable, removable) ---- */

	whenElementExists( '#ner_michoel_gallery_images', function ( $list ) {
		$list.sortable( { update: syncGalleryInput } );
	} );

	function syncGalleryInput() {
		var ids = [];
		$( '#ner_michoel_gallery_images .nm-gallery-item' ).each( function () {
			ids.push( $( this ).data( 'id' ) );
		} );
		$( '#ner_michoel_gallery_image_ids' ).val( ids.join( ',' ) );
	}

	var galleryFrame;
	$( document ).on( 'click', '#ner_michoel_gallery_add', function ( e ) {
		e.preventDefault();
		galleryFrame = wp.media( {
			title: strings.galleryTitle,
			library: { type: 'image' },
			multiple: true
		} );
		galleryFrame.on( 'select', function () {
			var selection = galleryFrame.state().get( 'selection' );
			var $list = $( '#ner_michoel_gallery_images' );
			selection.each( function ( attachment ) {
				var data = attachment.toJSON();
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
			syncGalleryInput();
		} );
		galleryFrame.open();
	} );

	$( document ).on( 'click', '.nm-gallery-remove', function () {
		$( this ).closest( '.nm-gallery-item' ).remove();
		syncGalleryInput();
	} );

	/* ---- Shiur: Choose Audio or Video File ---- */

	var shiurAudioFrame;
	$( document ).on( 'click', '#ner_michoel_audio_select', function ( e ) {
		e.preventDefault();
		if ( shiurAudioFrame ) {
			shiurAudioFrame.open();
			return;
		}
		shiurAudioFrame = wp.media( {
			title: strings.audioTitle,
			library: { type: [ 'audio', 'video' ] },
			multiple: false
		} );
		shiurAudioFrame.on( 'select', function () {
			var attachment = shiurAudioFrame.state().get( 'selection' ).first().toJSON();
			$( '#ner_michoel_audio_id' ).val( attachment.id );
			$( '#ner_michoel_audio_filename' ).text( attachment.filename );
			$( '#ner_michoel_audio_remove' ).show();
		} );
		shiurAudioFrame.open();
	} );

	$( document ).on( 'click', '#ner_michoel_audio_remove', function ( e ) {
		e.preventDefault();
		$( '#ner_michoel_audio_id' ).val( '' );
		$( '#ner_michoel_audio_filename' ).text( strings.noFileSelected );
		$( this ).hide();
	} );

	/* ---- Speaker/Series term: Choose Cover Image ---- */

	var termImageFrame;
	$( document ).on( 'click', '#ner_michoel_term_image_select', function ( e ) {
		e.preventDefault();
		if ( termImageFrame ) {
			termImageFrame.open();
			return;
		}
		termImageFrame = wp.media( {
			title: strings.coverImageTitle,
			library: { type: 'image' },
			multiple: false
		} );
		termImageFrame.on( 'select', function () {
			var attachment = termImageFrame.state().get( 'selection' ).first().toJSON();
			var url = attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url;
			$( '#ner_michoel_image_id' ).val( attachment.id );
			$( '#ner_michoel_term_image_preview' ).html( '<img src="' + url + '" style="max-width:150px;height:auto;display:block;" />' );
			$( '#ner_michoel_term_image_remove' ).show();
		} );
		termImageFrame.open();
	} );

	$( document ).on( 'click', '#ner_michoel_term_image_remove', function ( e ) {
		e.preventDefault();
		$( '#ner_michoel_image_id' ).val( '' );
		$( '#ner_michoel_term_image_preview' ).empty();
		$( this ).hide();
	} );

	/* ---- Bulk Upload Shiurim: Choose Files (multi) ---- */

	function guessTitle( filename ) {
		var name = filename.replace( /\.[^/.]+$/, '' );
		name = name.replace( /[_-]+/g, ' ' ).replace( /\s+/g, ' ' ).trim();
		return name.replace( /\w\S*/g, function ( word ) {
			return word.charAt( 0 ).toUpperCase() + word.substr( 1 );
		} );
	}

	function addBulkUploadRow( id, filename, title ) {
		var $row = $( '<tr></tr>' );
		$row.append(
			$( '<td></td>' ).text( filename ).append(
				$( '<input type="hidden" name="nm_bulk_attachment_id[]">' ).val( id )
			)
		);
		$row.append(
			$( '<td></td>' ).append(
				$( '<input type="text" name="nm_bulk_title[]" class="widefat">' ).val( title )
			)
		);
		$( '#nm_bulk_upload_table tbody' ).append( $row );
	}

	var bulkUploadFrame;
	$( document ).on( 'click', '#nm_bulk_upload_select', function ( e ) {
		e.preventDefault();
		if ( bulkUploadFrame ) {
			bulkUploadFrame.open();
			return;
		}
		bulkUploadFrame = wp.media( {
			title: strings.bulkUploadTitle,
			library: { type: [ 'audio', 'video' ] },
			multiple: true
		} );
		bulkUploadFrame.on( 'select', function () {
			var selection = bulkUploadFrame.state().get( 'selection' );
			$( '#nm_bulk_upload_table tbody' ).empty();
			selection.each( function ( attachment ) {
				var data = attachment.toJSON();
				addBulkUploadRow( data.id, data.filename, guessTitle( data.filename ) );
			} );
			$( '#nm_bulk_upload_table' ).show();
			$( '#nm_bulk_upload_submit' ).show();
		} );
		bulkUploadFrame.open();
	} );
} )( jQuery );
