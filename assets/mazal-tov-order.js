/**
 * Control Panel > Announcements > Mazal Tov Order (includes/mazal-tov-order.php):
 * drag a row, or use Top / up / down. The order of the rows is what is saved.
 */
( function () {
	'use strict';

	function init() {
		var list = document.querySelector( '[data-mt-order]' );
		if ( ! list || list.dataset.ready ) {
			return;
		}
		list.dataset.ready = '1';
		var dragging = null;

		list.addEventListener( 'click', function ( e ) {
			var button = e.target.closest( '[data-move]' );
			if ( ! button ) {
				return;
			}
			var item = button.closest( 'li' );
			var move = button.getAttribute( 'data-move' );
			if ( 'top' === move ) {
				list.insertBefore( item, list.firstElementChild );
			} else if ( 'up' === move && item.previousElementSibling ) {
				list.insertBefore( item, item.previousElementSibling );
			} else if ( 'down' === move && item.nextElementSibling ) {
				list.insertBefore( item.nextElementSibling, item );
			}
			button.focus();
		} );

		list.addEventListener( 'dragstart', function ( e ) {
			dragging = e.target.closest( 'li' );
			if ( dragging ) {
				dragging.classList.add( 'is-dragging' );
				e.dataTransfer.effectAllowed = 'move';
				e.dataTransfer.setData( 'text/plain', '' );
			}
		} );
		list.addEventListener( 'dragover', function ( e ) {
			if ( ! dragging ) {
				return;
			}
			e.preventDefault();
			var over = e.target.closest( 'li' );
			if ( ! over || over === dragging ) {
				return;
			}
			var box = over.getBoundingClientRect();
			list.insertBefore( dragging, e.clientY > box.top + box.height / 2 ? over.nextElementSibling : over );
		} );
		list.addEventListener( 'dragend', function () {
			if ( dragging ) {
				dragging.classList.remove( 'is-dragging' );
			}
			dragging = null;
		} );
	}

	init();
	document.addEventListener( 'nm:content-swapped', init );
} )();
