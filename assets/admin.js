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

	$list.sortable( { handle: '.nm-slide-row__drag' } );

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
	}

	$list.find( '.nm-slide-row' ).each( function () {
		bindRow( $( this ) );
	} );

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
} );
