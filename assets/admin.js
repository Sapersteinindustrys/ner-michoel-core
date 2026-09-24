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

	// "+ Add Slide" is bound first, before anything below that could
	// throw (jQuery UI Sortable failing to init, a media-library
	// hiccup) — those would otherwise abort this whole handler and
	// silently leave the button doing nothing, with no visible error.
	$( '#nm-slider-add' ).on( 'click', function () {
		var template = document.getElementById( 'nm-slide-row-template' );
		if ( ! template ) {
			return;
		}
		var index = $list.find( '.nm-slide-row' ).length;
		var html  = template.innerHTML.replace( /__INDEX__/g, index );
		var $row  = $( html );
		$list.append( $row );
		bindRow( $row );
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
				title: 'Select slide image',
				library: { type: 'image' },
				multiple: false
			} );
			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				var url = ( attachment.sizes && attachment.sizes.medium ) ? attachment.sizes.medium.url : attachment.url;
				$row.find( '.nm-slide-image-id' ).val( attachment.id );
				$row.find( '.nm-slide-row__preview' ).html( '<img src="' + url + '" alt="" />' );
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
