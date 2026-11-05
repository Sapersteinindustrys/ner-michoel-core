/**
 * Control Panel > Shiurim > Series Order (includes/series-order.php): drag a row, or use
 * Top / up / down; "Series inside" opens a row's own list, ordered the same way. A row moves
 * only among its sisters. The order of the rows is what is saved.
 */
( function () {
	'use strict';

	function ownItem( el, list ) {
		var item = el.closest( 'li' );
		while ( item && item.parentElement !== list ) {
			item = item.parentElement.closest( 'li' );
		}
		return item;
	}

	function bind( list ) {
		if ( list.dataset.ready ) {
			return;
		}
		list.dataset.ready = '1';
		var dragging = null;

		list.addEventListener( 'click', function ( e ) {
			var open = e.target.closest( '[data-so-open]' );
			if ( open && ownItem( open, list ) ) {
				var inner = ownItem( open, list ).querySelector( '[data-so-list]' );
				if ( inner ) {
					inner.hidden = ! inner.hidden;
					open.setAttribute( 'aria-expanded', inner.hidden ? 'false' : 'true' );
				}
				e.stopPropagation();
				return;
			}
			var button = e.target.closest( '[data-move]' );
			if ( ! button ) {
				return;
			}
			var item = ownItem( button, list );
			if ( ! item ) {
				return;
			}
			e.stopPropagation();
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
			var item = ownItem( e.target, list );
			if ( ! item ) {
				return;
			}
			e.stopPropagation();
			dragging = item;
			dragging.classList.add( 'is-dragging' );
			e.dataTransfer.effectAllowed = 'move';
			e.dataTransfer.setData( 'text/plain', '' );
		} );
		list.addEventListener( 'dragover', function ( e ) {
			if ( ! dragging ) {
				return;
			}
			var over = ownItem( e.target, list );
			if ( ! over ) {
				return;
			}
			e.preventDefault();
			e.stopPropagation();
			if ( over === dragging ) {
				return;
			}
			var box = over.getBoundingClientRect();
			list.insertBefore( dragging, e.clientY > box.top + Math.min( box.height, 44 ) / 2 ? over.nextElementSibling : over );
		} );
		list.addEventListener( 'dragend', function () {
			if ( dragging ) {
				dragging.classList.remove( 'is-dragging' );
			}
			dragging = null;
		} );
	}

	function init() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-so-list]' ), bind );
	}

	init();
	document.addEventListener( 'nm:content-swapped', init );
} )();
