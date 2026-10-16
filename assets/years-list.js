/**
 * Years list: fills a Mazal Tov's "Years" box from the spreadsheet of the boys
 * and the years they were in yeshiva (includes/years-list.php) when the name that
 * was typed is in it.
 *
 * Watches the name box (the Honoree on Quick Mazal Tov, the Headline on Add New;
 * a preset's finished sentence works too, since the server also finds a boy's name
 * inside a sentence) and asks the site what the list has. Then:
 *
 *   one boy found          the Years box is filled in, and a line says where it came from
 *   several with that name "More than one ... Which one?" and a button for each
 *   no exact match         "Did you mean ..." with a button for each
 *   not in the list        a quiet line saying so
 *
 * It only ever writes into a Years box that is empty or still holds what it put
 * there itself. Years you typed are never overwritten (the line offers them as a
 * button instead), and years it put there are taken out again when the name is
 * changed to one that is not in the list, so a stale year can't be posted.
 *
 * No jQuery: the same file runs on wp-admin's Quick Mazal Tov screen, in the
 * panel's Add New form (assets/custom-admin-cms.js) and on the Years List screen
 * ("Try a name": no Years box, just the answer).
 */
( function () {
	'use strict';

	var DELAY = 350;
	var MIN_LENGTH = 3;

	function clean( value ) {
		return String( value === null || value === undefined ? '' : value ).replace( /\s+/g, ' ' ).trim();
	}

	/**
	 * What to do with the site's answer. res: { count, strong: [{name, years}], maybe: [...] };
	 * current: what is in the Years box; auto: what this script last put there.
	 * Returns { kind, ... } with kind one of nolist, found, choose, maybe, none, and for
	 * found: item, write (true when the box may be filled, false when it holds the person's own text).
	 * clear is true when the box holds only a year this script put there and it no longer fits.
	 */
	function decide( res, current, auto ) {
		current = clean( current );
		auto = clean( auto );
		var mine = current === '' || current === auto;
		var stale = current !== '' && current === auto;
		if ( ! res || ! res.count ) {
			return { kind: 'nolist', clear: stale };
		}
		var strong = res.strong || [];
		var maybe = res.maybe || [];
		if ( strong.length ) {
			var seen = {};
			var distinct = [];
			strong.forEach( function ( item ) {
				if ( ! seen[ item.years ] ) {
					seen[ item.years ] = true;
					distinct.push( item );
				}
			} );
			if ( distinct.length === 1 ) {
				return { kind: 'found', item: distinct[ 0 ], names: strong, write: mine, same: current === distinct[ 0 ].years, clear: false };
			}
			return { kind: 'choose', items: strong, clear: stale };
		}
		if ( maybe.length ) {
			return { kind: 'maybe', items: maybe, clear: stale };
		}
		return { kind: 'none', clear: stale };
	}

	function el( tag, props, kids ) {
		var node = document.createElement( tag );
		Object.keys( props || {} ).forEach( function ( key ) {
			var val = props[ key ];
			if ( val === null || val === undefined || val === false ) {
				return;
			}
			if ( key === 'text' ) {
				node.textContent = val;
			} else {
				node.setAttribute( key, val === true ? '' : String( val ) );
			}
		} );
		( kids || [] ).forEach( function ( kid ) {
			if ( kid ) {
				node.appendChild( typeof kid === 'string' ? document.createTextNode( kid ) : kid );
			}
		} );
		return node;
	}

	function fire( node, type ) {
		node.dispatchEvent( new Event( type, { bubbles: true } ) );
	}

	/**
	 * o.source   the name <input> to watch
	 * o.target   the Years <input> to fill (omit for "just show the answer")
	 * o.note     the element the line is drawn in
	 * o.url, o.nonce   the lookup route and its REST nonce
	 * o.manageUrl, o.canManage   where the list is uploaded, and whether this person may
	 * Returns { lookup(), destroy() }.
	 */
	function attach( o ) {
		var source = o.source;
		var target = o.target || null;
		var note = o.note;
		var state = { auto: '', timer: null, seq: 0, last: null };
		note.classList.add( 'nm-yl-note' );
		note.setAttribute( 'role', 'status' );
		note.setAttribute( 'aria-live', 'polite' );
		note.hidden = true;

		function show( parts, tone ) {
			note.textContent = '';
			note.className = 'nm-yl-note' + ( tone ? ' nm-yl-note--' + tone : '' );
			parts.forEach( function ( part ) {
				if ( part ) {
					note.appendChild( typeof part === 'string' ? document.createTextNode( part ) : part );
				}
			} );
			note.hidden = ! parts.length;
		}

		function hide() {
			note.textContent = '';
			note.hidden = true;
		}

		function write( years ) {
			state.auto = clean( years );
			target.value = years;
			fire( target, 'input' );
		}

		function clear() {
			state.auto = '';
			target.value = '';
			fire( target, 'input' );
		}

		function who( item ) {
			return el( 'strong', { 'class': 'nm-yl-name', dir: 'auto', text: item.name } );
		}

		function chips( items ) {
			var box = el( 'span', { 'class': 'nm-yl-chips' } );
			items.forEach( function ( item ) {
				if ( target ) {
					var chip = el( 'button', { type: 'button', 'class': 'nm-yl-chip' }, [
						el( 'span', { 'class': 'nm-yl-chip__name', dir: 'auto', text: item.name } ),
						' ',
						el( 'span', { 'class': 'nm-yl-chip__years', dir: 'auto', text: item.years } )
					] );
					chip.addEventListener( 'click', function () {
						write( item.years );
						show( [ 'Years filled in from the list: ', who( item ), ' — ', el( 'span', { dir: 'auto', text: item.years } ), clearButton() ], 'ok' );
					} );
					box.appendChild( chip );
				} else {
					box.appendChild( el( 'span', { 'class': 'nm-yl-chip nm-yl-chip--static' }, [
						el( 'span', { 'class': 'nm-yl-chip__name', dir: 'auto', text: item.name } ),
						' ',
						el( 'span', { 'class': 'nm-yl-chip__years', dir: 'auto', text: item.years } )
					] ) );
				}
			} );
			return box;
		}

		function clearButton() {
			var button = el( 'button', { type: 'button', 'class': 'nm-yl-link', text: 'Not right? Clear' } );
			button.addEventListener( 'click', function () {
				clear();
				hide();
			} );
			return button;
		}

		function useButton( item ) {
			var button = el( 'button', { type: 'button', 'class': 'nm-yl-link', text: 'Use these years' } );
			button.addEventListener( 'click', function () {
				write( item.years );
				show( [ 'Years filled in from the list: ', who( item ), ' — ', el( 'span', { dir: 'auto', text: item.years } ), clearButton() ], 'ok' );
			} );
			return button;
		}

		function apply( res ) {
			var d = decide( res, target ? target.value : '', state.auto );
			if ( target && d.clear ) {
				clear();
			}
			switch ( d.kind ) {
				case 'nolist':
					if ( o.canManage && o.manageUrl ) {
						show( [ 'There is no Years List yet. ', el( 'a', { href: o.manageUrl, target: '_blank', rel: 'noopener', text: 'Upload the spreadsheet' } ), ' and the years will fill in from the name.' ], 'muted' );
					} else {
						hide();
					}
					break;
				case 'found':
					if ( ! target ) {
						show( [ 'Found: ', who( d.item ), ' — ', el( 'span', { dir: 'auto', text: d.item.years } ) ], 'ok' );
					} else if ( d.write ) {
						write( d.item.years );
						show( [ 'Years filled in from the list: ', who( d.item ), ' — ', el( 'span', { dir: 'auto', text: d.item.years } ), clearButton() ], 'ok' );
					} else if ( d.same ) {
						show( [ 'The list agrees: ', who( d.item ), ' — ', el( 'span', { dir: 'auto', text: d.item.years } ) ], 'ok' );
					} else {
						show( [ 'The list has ', who( d.item ), ': ', el( 'span', { dir: 'auto', text: d.item.years } ), '. ', useButton( d.item ) ], 'info' );
					}
					break;
				case 'choose':
					show( [ 'More than one match in the list' + ( target ? '. Which one?' : ':' ), chips( d.items ) ], 'info' );
					break;
				case 'maybe':
					show( [ 'No exact match. Did you mean:', chips( d.items ) ], 'info' );
					break;
				default:
					show( [ 'Not in the Years List.' ], 'muted' );
			}
		}

		function lookup() {
			var text = clean( source.value );
			if ( text.length < MIN_LENGTH ) {
				state.seq++;
				state.last = null;
				if ( target && state.auto && clean( target.value ) === state.auto ) {
					clear();
				}
				hide();
				return;
			}
			if ( text === state.last ) {
				return;
			}
			state.last = text;
			var seq = ++state.seq;
			fetch( o.url, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': o.nonce },
				body: JSON.stringify( { names: [ text ] } )
			} ).then( function ( res ) {
				if ( ! res.ok ) {
					throw new Error( 'status ' + res.status );
				}
				return res.json();
			} ).then( function ( res ) {
				if ( seq === state.seq ) {
					apply( res );
				}
			} ).catch( function () {
				if ( seq === state.seq ) {
					state.last = null;
					show( [ 'The Years List could not be reached just now. You can type the years yourself.' ], 'muted' );
				}
			} );
		}

		function onInput() {
			window.clearTimeout( state.timer );
			state.timer = window.setTimeout( lookup, DELAY );
		}

		source.addEventListener( 'input', onInput );
		source.addEventListener( 'change', onInput );

		return {
			lookup: lookup,
			destroy: function () {
				window.clearTimeout( state.timer );
				source.removeEventListener( 'input', onInput );
				source.removeEventListener( 'change', onInput );
			}
		};
	}

	// Quick Mazal Tov and the Years List screen: <div data-nm-years-lookup data-source="#id" data-target="#id" ...>
	function autoMount() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-nm-years-lookup]' ), function ( host ) {
			var source = document.querySelector( host.getAttribute( 'data-source' ) || '' );
			var targetSel = host.getAttribute( 'data-target' ) || '';
			if ( ! source ) {
				return;
			}
			attach( {
				source: source,
				target: targetSel ? document.querySelector( targetSel ) : null,
				note: host,
				url: host.getAttribute( 'data-url' ) || '',
				nonce: host.getAttribute( 'data-nonce' ) || '',
				manageUrl: host.getAttribute( 'data-manage-url' ) || '',
				canManage: host.getAttribute( 'data-can-manage' ) === '1'
			} );
		} );
	}

	var api = { attach: attach, decide: decide, clean: clean };

	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = api;
	}
	if ( typeof window !== 'undefined' && typeof document !== 'undefined' ) {
		window.NMYearsList = api;
		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', autoMount );
		} else {
			autoMount();
		}
	}
}() );
