/**
 * Control Panel > Shiurim > Series Order (includes/series-order.php): every series as a
 * folder. Drag a line, or use Top / up / down, to order it among its sisters. "Open" shows
 * the series inside one. "Move into…" puts a series (with everything inside it) into
 * another series, or back at the top: the line moves there at once, and Save order keeps it.
 * A series can't be moved into itself or into a series inside it, so those are not offered.
 */
( function () {
	'use strict';

	var words = window.nmSeriesOrder || {};

	function t( key, fallback ) {
		return words[ key ] || fallback;
	}

	function ownItem( el, list ) {
		var item = el.closest( 'li' );
		while ( item && item.parentElement !== list ) {
			item = item.parentElement.closest( 'li' );
		}
		return item;
	}

	function innerList( item ) {
		for ( var i = 0; i < item.children.length; i++ ) {
			if ( item.children[ i ].matches( '[data-so-list]' ) ) {
				return item.children[ i ];
			}
		}
		return null;
	}

	function itemsOf( list ) {
		return Array.prototype.filter.call( list.children, function ( el ) {
			return el.matches( 'li[data-so-id]' );
		} );
	}

	function setOpen( item, open ) {
		var inner  = innerList( item );
		var button = item.querySelector( ':scope > .nm-so__line [data-so-open]' );
		if ( ! inner ) {
			return;
		}
		inner.hidden = ! open;
		if ( button ) {
			button.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		}
	}

	// Shiurim in a series and in every series inside it.
	function total( item ) {
		var sum   = parseInt( item.getAttribute( 'data-so-own' ), 10 ) || 0;
		var inner = innerList( item );
		if ( inner ) {
			itemsOf( inner ).forEach( function ( kid ) {
				sum += total( kid );
			} );
		}
		return sum;
	}

	// After a move: each line's hidden input names its parent, Open shows only on a series
	// with something inside, and the counts follow.
	function refresh( root ) {
		Array.prototype.forEach.call( root.querySelectorAll( 'li[data-so-id]' ), function ( item ) {
			var list   = item.parentElement;
			var parent = list.getAttribute( 'data-so-parent' ) || '0';
			var input  = item.querySelector( ':scope > .nm-so__line [data-so-input]' );
			if ( input ) {
				input.name = 'kids[' + parent + '][]';
			}
			var inner  = innerList( item );
			var count  = inner ? itemsOf( inner ).length : 0;
			var button = item.querySelector( ':scope > .nm-so__line [data-so-open]' );
			if ( button ) {
				button.hidden = ! count;
			}
			var countEl = item.querySelector( ':scope > .nm-so__line [data-so-count]' );
			if ( countEl ) {
				var n = total( item );
				countEl.textContent = ( count ? t( 'inside', '%s series inside' ).replace( '%s', count.toLocaleString() ) + ' · ' : '' ) +
					t( 1 === n ? 'shiur' : 'shiurim', 1 === n ? '%s shiur' : '%s shiurim' ).replace( '%s', n.toLocaleString() );
			}
		} );
	}

	// The series this one could go into: every series but itself and the ones inside it, as paths.
	function targets( root, item ) {
		var out = [ { id: '0', path: t( 'top', 'The top' ) } ];
		function walk( list, prefix ) {
			itemsOf( list ).forEach( function ( li ) {
				if ( li === item ) {
					return;
				}
				var path = prefix ? prefix + ' / ' + li.getAttribute( 'data-so-name' ) : li.getAttribute( 'data-so-name' );
				out.push( { id: li.getAttribute( 'data-so-id' ), path: path } );
				var inner = innerList( li );
				if ( inner ) {
					walk( inner, path );
				}
			} );
		}
		walk( root, '' );
		return out;
	}

	function closePicker( item ) {
		var pick = item.querySelector( ':scope > .nm-so__line .nm-so__pick' );
		if ( pick ) {
			pick.parentNode.removeChild( pick );
		}
	}

	function openPicker( root, item, status ) {
		var line = item.querySelector( ':scope > .nm-so__line' );
		if ( line.querySelector( '.nm-so__pick' ) ) {
			closePicker( item );
			return;
		}
		var wrap   = document.createElement( 'span' );
		wrap.className = 'nm-so__pick';
		var select = document.createElement( 'select' );
		select.setAttribute( 'aria-label', t( 'pick', 'Move it into…' ) );
		var here   = item.parentElement.getAttribute( 'data-so-parent' ) || '0';
		var first  = document.createElement( 'option' );
		first.value = '';
		first.textContent = t( 'pick', 'Move it into…' );
		select.appendChild( first );
		targets( root, item ).forEach( function ( o ) {
			var opt = document.createElement( 'option' );
			opt.value = o.id;
			opt.textContent = o.path;
			opt.disabled = o.id === here;
			select.appendChild( opt );
		} );
		var cancel = document.createElement( 'button' );
		cancel.type = 'button';
		cancel.className = 'button button-small';
		cancel.textContent = t( 'cancel', 'Cancel' );
		cancel.addEventListener( 'click', function () {
			closePicker( item );
		} );
		select.addEventListener( 'change', function () {
			if ( '' === select.value ) {
				return;
			}
			var dest;
			if ( '0' === select.value ) {
				dest = root;
			} else {
				var target = root.querySelector( 'li[data-so-id="' + select.value + '"]' );
				dest = target ? innerList( target ) : null;
				if ( target ) {
					// Open the way down to it, so the moved line can be seen.
					var up = target;
					while ( up && up.matches( 'li[data-so-id]' ) ) {
						setOpen( up, true );
						up = up.parentElement.closest( 'li[data-so-id]' );
					}
				}
			}
			if ( ! dest ) {
				return;
			}
			closePicker( item );
			dest.appendChild( item );
			item.classList.add( 'is-moved' );
			refresh( root );
			if ( status ) {
				status.textContent = t( 'moved', 'Moved. Press Save order to keep it.' );
			}
			markDirty();
			item.scrollIntoView( { block: 'center', behavior: 'smooth' } );
			var focus = item.querySelector( '[data-so-move-into]' );
			if ( focus ) {
				focus.focus( { preventScroll: true } );
			}
		} );
		wrap.appendChild( select );
		wrap.appendChild( cancel );
		line.appendChild( wrap );
		select.focus();
	}

	var dirty = false;
	function markDirty() {
		dirty = true;
	}

	function bind( list, root, status ) {
		if ( list.dataset.ready ) {
			return;
		}
		list.dataset.ready = '1';
		var dragging = null;

		list.addEventListener( 'click', function ( e ) {
			var item = ownItem( e.target, list );
			if ( ! item ) {
				return;
			}
			var open = e.target.closest( '[data-so-open]' );
			if ( open ) {
				var inner = innerList( item );
				setOpen( item, inner && inner.hidden );
				e.stopPropagation();
				return;
			}
			if ( e.target.closest( '[data-so-move-into]' ) ) {
				e.stopPropagation();
				openPicker( root, item, status );
				return;
			}
			var button = e.target.closest( '[data-move]' );
			if ( ! button ) {
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
			markDirty();
			button.focus();
		} );

		list.addEventListener( 'dragstart', function ( e ) {
			var item = ownItem( e.target, list );
			if ( ! item || e.target.closest( 'select, button' ) ) {
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
			markDirty();
		} );
		list.addEventListener( 'dragend', function () {
			if ( dragging ) {
				dragging.classList.remove( 'is-dragging' );
			}
			dragging = null;
		} );
	}

	function init() {
		var form = document.querySelector( '[data-so-form]' );
		if ( ! form || form.dataset.ready ) {
			return;
		}
		form.dataset.ready = '1';
		var root   = form.querySelector( '[data-so-list][data-so-parent="0"]' );
		var status = form.querySelector( '[data-so-status]' );
		Array.prototype.forEach.call( form.querySelectorAll( '[data-so-list]' ), function ( list ) {
			bind( list, root, status );
		} );
		Array.prototype.forEach.call( form.querySelectorAll( '[data-so-all]' ), function ( button ) {
			button.addEventListener( 'click', function () {
				var open = 'open' === button.getAttribute( 'data-so-all' );
				Array.prototype.forEach.call( root.querySelectorAll( 'li[data-so-id]' ), function ( item ) {
					setOpen( item, open );
				} );
			} );
		} );
		form.addEventListener( 'submit', function () {
			dirty = false;
		} );
		window.addEventListener( 'beforeunload', function ( e ) {
			if ( dirty ) {
				e.preventDefault();
				e.returnValue = t( 'unsaved', 'You have changes that are not saved yet.' );
			}
		} );
		refresh( root );
	}

	init();
	document.addEventListener( 'nm:content-swapped', init );
} )();
