/**
 * Homepage Slider admin page — drag-to-reorder, add/remove slides,
 * and the image picker for each row. See includes/homepage-slider.php
 * for the PHP side (field names, save handling, the JS row template).
 */

jQuery( function ( $ ) {
	'use strict';

	var $list = $( '#nm-slider-list' );
	if ( ! $list.length ) {
		return;
	}

	// Builds one new (unbound, unattached) row from the JS template —
	// shared by both "+ Add Slide" and the multi-photo picker below,
	// rather than duplicating the template-cloning logic in each.
	function createRow() {
		var template = document.getElementById( 'nm-slide-row-template' );
		if ( ! template ) {
			return null;
		}
		var index = $list.find( '.nm-slide-row' ).length;
		var html  = template.innerHTML.replace( /__INDEX__/g, index );
		return $( html );
	}

	// Copies the "one button for every slide" fields into a single
	// row — used both by "Apply to All Slides" (every existing row)
	// and automatically on every newly created row, so a slide added
	// after that button is set doesn't fall out of sync with it.
	function applyBulkButton( $row ) {
		var url  = $( '#nm-bulk-link-url' ).val();
		var text = $( '#nm-bulk-link-text' ).val();
		if ( ! url && ! text ) {
			return;
		}
		$row.find( '.nm-slide-link-url' ).val( url );
		$row.find( '.nm-slide-link-text' ).val( text );
	}

	// "+ Add Slide" is bound first, before anything below that could
	// throw (jQuery UI Sortable failing to init, a media-library
	// hiccup) — those would otherwise abort this whole handler and
	// silently leave the button doing nothing, with no visible error.
	$( '#nm-slider-add' ).on( 'click', function () {
		var $row = createRow();
		if ( ! $row ) {
			return;
		}
		$list.append( $row );
		bindRow( $row );
		applyBulkButton( $row );
	} );

	// One media frame, multiple images at once (drag-and-drop onto the
	// frame's own uploader works here too, not just picking from the
	// library) — each selected image becomes its own new slide row.
	$( '#nm-slider-add-multiple' ).on( 'click', function () {
		var frame = wp.media( {
			title: 'Select images — one slide per image',
			library: { type: 'image' },
			multiple: true
		} );
		frame.on( 'select', function () {
			frame.state().get( 'selection' ).each( function ( attachment ) {
				var data = attachment.toJSON();
				var url  = ( data.sizes && data.sizes.medium ) ? data.sizes.medium.url : data.url;
				var $row = createRow();
				if ( ! $row ) {
					return;
				}
				$row.find( '.nm-slide-image-id' ).val( data.id );
				$row.find( '.nm-slide-row__preview' ).html( '<img src="' + url + '" alt="" />' );
				$list.append( $row );
				bindRow( $row );
				applyBulkButton( $row );
			} );
		} );
		frame.open();
	} );

	$( '#nm-bulk-link-apply' ).on( 'click', function () {
		$list.find( '.nm-slide-row' ).each( function () {
			applyBulkButton( $( this ) );
		} );
	} );

	try {
		$list.sortable( { handle: '.nm-slide-row__drag' } );
	} catch ( e ) {
		window.console && window.console.error( 'Ner Michoel: drag-to-reorder unavailable', e );
	}

	function bindRow( $row ) {
		var frame;

		$row.find( '.nm-slide-choose-image' ).on( 'click', function ( e ) {
			e.preventDefault();
			frame = wp.media( {
				title: 'Select slide image(s)',
				library: { type: 'image' },
				multiple: true
			} );
			frame.on( 'select', function () {
				// Picking several here fills this row with the first and
				// inserts one new row per additional image right after it,
				// in order — the same "one photo, one slide" behavior as
				// "+ Add Slides from Photos…", but from the button people
				// reach for first without needing to find the other one.
				var attachments = frame.state().get( 'selection' ).toArray();
				var $insertAfter = $row;
				attachments.forEach( function ( attachment, i ) {
					var data = attachment.toJSON();
					var url  = ( data.sizes && data.sizes.medium ) ? data.sizes.medium.url : data.url;

					if ( 0 === i ) {
						$row.find( '.nm-slide-image-id' ).val( data.id );
						$row.find( '.nm-slide-row__preview' ).html( '<img src="' + url + '" alt="" />' );
						return;
					}

					var $newRow = createRow();
					if ( ! $newRow ) {
						return;
					}
					$newRow.find( '.nm-slide-image-id' ).val( data.id );
					$newRow.find( '.nm-slide-row__preview' ).html( '<img src="' + url + '" alt="" />' );
					$insertAfter.after( $newRow );
					bindRow( $newRow );
					applyBulkButton( $newRow );
					$insertAfter = $newRow;
				} );
			} );
			frame.open();
		} );

		$row.find( '.nm-slide-remove' ).on( 'click', function () {
			$row.remove();
		} );

		// Page search — scoped to this row via the $row/$searchInput/etc.
		// closures, same as the image picker above, so the same picker UI
		// works independently no matter how many slide rows exist,
		// including ones added later by "+ Add Slide".
		var $searchInput = $row.find( '.nm-slide-link-search' );
		var $results     = $row.find( '.nm-slide-link-results' );
		var $urlInput    = $row.find( '.nm-slide-link-url' );
		var searchTimer  = null;

		function renderResults( items ) {
			$results.empty();
			if ( ! items || ! items.length ) {
				$results.attr( 'hidden', true );
				return;
			}
			items.forEach( function ( item ) {
				var $btn = $( '<button type="button" class="nm-slide-link-result"></button>' ).text( item.title || item.url );
				$btn.on( 'click', function () {
					$urlInput.val( item.url );
					$searchInput.val( item.title );
					$results.empty().attr( 'hidden', true );
				} );
				$results.append( $btn );
			} );
			$results.attr( 'hidden', false );
		}

		$searchInput.on( 'input', function () {
			var term = $searchInput.val().trim();
			window.clearTimeout( searchTimer );
			if ( term.length < 2 ) {
				$results.empty().attr( 'hidden', true );
				return;
			}
			if ( ! window.nmHomepageSlider ) {
				return;
			}
			searchTimer = window.setTimeout( function () {
				$.post( nmHomepageSlider.ajaxUrl, {
					action: 'nm_search_pages',
					search: term,
					nonce: nmHomepageSlider.nonce
				} ).done( function ( response ) {
					renderResults( response && response.success ? response.data : [] );
				} );
			}, 300 );
		} );
	}

	$list.find( '.nm-slide-row' ).each( function () {
		bindRow( $( this ) );
	} );

	// Bound once, delegated on the document, rather than per row —
	// closing every open results dropdown on an outside click doesn't
	// need row-specific state, and binding it inside bindRow() would
	// stack up one redundant document-level handler per slide added.
	$( document ).on( 'click', function ( e ) {
		if ( ! $( e.target ).closest( '.nm-slide-link-picker' ).length ) {
			$( '.nm-slide-link-results' ).attr( 'hidden', true );
		}
	} );
} );
