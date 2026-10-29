/*
 * Series picker: turns a <select data-series-picker> into a series chooser that knows series sit inside
 * series (Gemara > Bava Kamma > Perek 3).
 *
 * What the person sees:
 *  - a "Chosen:" line with the whole name of the series chosen (or that none is yet);
 *  - a search box that looks through every series at every level. Choosing a result sets the whole chain,
 *    so nobody has to pick Gemara, then Bava Kamma, then Perek 3: picking Perek 3 is enough;
 *  - the three most recently used series ("Recent"), when nothing is typed;
 *  - one drop-down per level: the top-level series first; once one is chosen, a drop-down with the series
 *    inside it appears, and so on down to the last. Each drop-down ends with "Add a new series" (or "Add a
 *    sub-series inside ..."), which asks for a name right there and adds it.
 *
 * The <select> stays in the page, hidden, and holds the value (the term ID of the deepest series chosen),
 * so the form's own saving code doesn't change. Each <option> says what it is: its text is the series' whole
 * name, data-name its own name, data-parent the ID of the series it sits inside, data-last when a shiur last
 * used it. To add a series the select needs data-add-url (the route) and data-nonce.
 *
 * Enter in the search box never submits the form the picker sits in (the "Finish Your Upload" screen): it
 * picks the first series that matches what was typed.
 */
( function ( window, document ) {
	'use strict';

	var RECENT_COUNT = 3;
	var MAX_RESULTS  = 60;
	var ADD          = '__add__';
	var ARROW        = '›';

	function fold( text ) {
		return String( text || '' ).toLowerCase().replace( /\s+/g, ' ' ).trim();
	}

	function natural( a, b ) {
		return a.name.localeCompare( b.name, undefined, { numeric: true, sensitivity: 'base' } ) || ( Number( a.id ) - Number( b.id ) );
	}

	function el( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( text !== undefined ) {
			node.textContent = text;
		}
		return node;
	}

	// Text with the typed words marked, built from text nodes so a name can never become markup.
	function highlight( into, text, tokens ) {
		var lower  = text.toLowerCase();
		var marked = new Array( text.length + 1 ).join( '0' ).split( '' );
		tokens.forEach( function ( token ) {
			if ( ! token ) {
				return;
			}
			var at = lower.indexOf( token );
			while ( at !== -1 ) {
				for ( var i = at; i < at + token.length; i++ ) {
					marked[ i ] = '1';
				}
				at = lower.indexOf( token, at + token.length );
			}
		} );
		var start = 0;
		while ( start < text.length ) {
			var on  = marked[ start ] === '1';
			var end = start;
			while ( end < text.length && ( marked[ end ] === '1' ) === on ) {
				end++;
			}
			var piece = text.slice( start, end );
			if ( on ) {
				into.appendChild( el( 'mark', 'nm-series-picker__mark', piece ) );
			} else {
				into.appendChild( document.createTextNode( piece ) );
			}
			start = end;
		}
	}

	function enhance( select ) {
		if ( ! select || select.getAttribute( 'data-picker-ready' ) === '1' ) {
			return;
		}
		select.setAttribute( 'data-picker-ready', '1' );

		var addUrl = select.getAttribute( 'data-add-url' ) || '';
		var nonce  = select.getAttribute( 'data-nonce' ) || '';

		var nodes = {};
		var roots = [];

		// Reads the select's options into the tree. Run again after a series was added.
		function load() {
			nodes = {};
			roots = [];
			var order = [];
			Array.prototype.forEach.call( select.options, function ( option ) {
				if ( option.value === '' ) {
					return;
				}
				var id = String( option.value );
				nodes[ id ] = {
					id: id,
					label: option.textContent,
					name: option.getAttribute( 'data-name' ) || option.textContent,
					parent: String( option.getAttribute( 'data-parent' ) || '' ),
					last: option.getAttribute( 'data-last' ) || '',
					children: []
				};
				order.push( id );
			} );
			order.forEach( function ( id ) {
				var node = nodes[ id ];
				if ( node.parent && node.parent !== '0' && node.parent !== id && nodes[ node.parent ] ) {
					nodes[ node.parent ].children.push( node );
				} else {
					node.parent = '';
					roots.push( node );
				}
			} );
			roots.sort( natural );
			order.forEach( function ( id ) {
				nodes[ id ].children.sort( natural );
			} );
		}
		load();

		// The series from the top down to this one: [ root, ..., node ].
		function chainOf( id ) {
			var chain = [];
			var seen  = {};
			var node  = nodes[ id ];
			while ( node && ! seen[ node.id ] ) {
				seen[ node.id ] = true;
				chain.unshift( node );
				node = node.parent ? nodes[ node.parent ] : null;
			}
			return chain;
		}

		function recentNodes() {
			return Object.keys( nodes )
				.map( function ( id ) {
					return nodes[ id ];
				} )
				.filter( function ( node ) {
					return node.last;
				} )
				.sort( function ( a, b ) {
					return b.last.localeCompare( a.last );
				} )
				.slice( 0, RECENT_COUNT );
		}

		var wrap = el( 'div', 'nm-series-picker' );

		var current = el( 'div', 'nm-series-picker__current' );
		current.setAttribute( 'role', 'status' );
		wrap.appendChild( current );

		var search = el( 'input', 'nm-series-picker__search' );
		search.type        = 'search';
		search.placeholder = select.getAttribute( 'data-placeholder' ) || 'Search…';
		search.setAttribute( 'aria-label', search.placeholder );
		wrap.appendChild( search );

		// What a search finds: every series at every level, by its whole name.
		var list = el( 'div', 'nm-series-picker__list' );
		list.setAttribute( 'role', 'listbox' );
		list.hidden = true;
		wrap.appendChild( list );

		var recentBox = el( 'div', 'nm-series-picker__section nm-series-picker__recent' );
		wrap.appendChild( recentBox );

		var levelsBox = el( 'div', 'nm-series-picker__levels' );
		wrap.appendChild( levelsBox );

		var addBox = el( 'div', 'nm-series-picker__add' );
		addBox.hidden = true;
		wrap.appendChild( addBox );

		var note = el( 'p', 'nm-series-picker__note' );
		note.setAttribute( 'role', 'status' );
		note.hidden = true;
		wrap.appendChild( note );

		function say( text, isError ) {
			note.textContent = text || '';
			note.hidden      = ! text;
			note.classList.toggle( 'is-error', !! isError );
		}

		function choose( id ) {
			select.value = id;
			select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}

		function makeItem( node, tokens ) {
			var button = el( 'button', 'nm-series-picker__item' );
			button.type = 'button';
			button.setAttribute( 'data-value', node.id );
			button.setAttribute( 'data-label', node.label );
			button.setAttribute( 'role', 'option' );
			var chain = chainOf( node.id );
			var up    = chain.slice( 0, -1 ).map( function ( part ) {
				return part.name;
			} ).join( ' / ' );
			if ( up ) {
				var crumb = el( 'span', 'nm-series-picker__crumb' );
				highlight( crumb, up + ' / ', tokens );
				button.appendChild( crumb );
			}
			var own = el( 'span', 'nm-series-picker__own' );
			highlight( own, node.name, tokens );
			button.appendChild( own );
			button.addEventListener( 'click', function () {
				search.value = '';
				applyFilter();
				choose( node.id );
			} );
			return button;
		}

		function applyFilter() {
			var query  = fold( search.value );
			var tokens = query ? query.split( ' ' ) : [];

			list.textContent = '';
			if ( ! tokens.length ) {
				list.hidden      = true;
				recentBox.hidden = ! recentBox.firstChild;
				levelsBox.hidden = false;
				addBox.hidden    = addBox.getAttribute( 'data-open' ) !== '1';
				return;
			}

			var found = [];
			Object.keys( nodes ).forEach( function ( id ) {
				var node = nodes[ id ];
				var hay  = fold( node.label );
				for ( var i = 0; i < tokens.length; i++ ) {
					if ( hay.indexOf( tokens[ i ] ) === -1 ) {
						return;
					}
				}
				var own   = fold( node.name );
				var score = hay === query ? 0 : own === query ? 1 : own.indexOf( query ) === 0 ? 2 : own.indexOf( query ) !== -1 ? 3 : hay.indexOf( query ) !== -1 ? 4 : 5;
				found.push( { node: node, score: score } );
			} );
			found.sort( function ( a, b ) {
				return a.score - b.score || a.node.label.localeCompare( b.node.label, undefined, { numeric: true, sensitivity: 'base' } );
			} );

			found.slice( 0, MAX_RESULTS ).forEach( function ( hit ) {
				var button = makeItem( hit.node, tokens );
				var on     = hit.node.id === String( select.value );
				button.classList.toggle( 'is-selected', on );
				button.setAttribute( 'aria-selected', on ? 'true' : 'false' );
				list.appendChild( button );
			} );
			if ( ! found.length ) {
				list.appendChild( el( 'div', 'nm-series-picker__empty', select.getAttribute( 'data-empty-label' ) || 'No series match that.' ) );
			} else if ( found.length > MAX_RESULTS ) {
				list.appendChild( el( 'div', 'nm-series-picker__more', 'Showing the first ' + MAX_RESULTS + ' of ' + found.length + '. Type more to narrow it down.' ) );
			}
			list.hidden      = false;
			recentBox.hidden = true;
			levelsBox.hidden = true;
			addBox.hidden    = true;
		}

		function renderRecent() {
			recentBox.textContent = '';
			var recent = recentNodes();
			if ( ! recent.length ) {
				recentBox.hidden = true;
				return;
			}
			recentBox.appendChild( el( 'div', 'nm-series-picker__heading', select.getAttribute( 'data-recent-label' ) || 'Recent' ) );
			recent.forEach( function ( node ) {
				var button = makeItem( node, [] );
				var on     = node.id === String( select.value );
				button.classList.toggle( 'is-selected', on );
				button.setAttribute( 'aria-selected', on ? 'true' : 'false' );
				recentBox.appendChild( button );
			} );
			recentBox.hidden = ! fold( search.value ) ? false : true;
		}

		// One drop-down per level of the chain, and one more for what could go inside the last.
		function renderLevels() {
			levelsBox.textContent = '';
			var chain = chainOf( String( select.value ) );

			for ( var level = 0; level <= chain.length; level++ ) {
				var parent   = level === 0 ? null : chain[ level - 1 ];
				var children = parent ? parent.children : roots;
				if ( level > 0 && ! children.length && ! addUrl ) {
					break;
				}
				levelsBox.appendChild( makeLevel( level, parent, children, chain[ level ] || null, chain ) );
			}
		}

		function makeLevel( level, parent, children, chosen, chain ) {
			var row   = el( 'label', 'nm-series-picker__level' );
			var title = level === 0
				? ( select.getAttribute( 'data-level-top' ) || 'Series' )
				: ( select.getAttribute( 'data-level-sub' ) || 'Inside' ) + ' “' + parent.name + '”';
			row.appendChild( el( 'span', 'nm-series-picker__level-label', title ) );

			var drop = el( 'select', 'nm-series-picker__level-select' );
			drop.setAttribute( 'data-level', String( level ) );

			var first = document.createElement( 'option' );
			first.value       = '';
			first.textContent = level === 0
				? ( select.getAttribute( 'data-none-option' ) || '— None —' )
				: ( children.length ? ( select.getAttribute( 'data-sub-option' ) || '— Choose one (optional) —' ) : ( select.getAttribute( 'data-no-sub-option' ) || '— None inside yet —' ) );
			drop.appendChild( first );

			children.forEach( function ( child ) {
				var option = document.createElement( 'option' );
				option.value       = child.id;
				option.textContent = child.name + ( child.children.length ? '  ' + ARROW : '' );
				drop.appendChild( option );
			} );

			if ( addUrl ) {
				var add = document.createElement( 'option' );
				add.value       = ADD;
				add.textContent = level === 0
					? '＋ ' + ( select.getAttribute( 'data-add-top' ) || 'Add a new series…' )
					: '＋ ' + ( select.getAttribute( 'data-add-sub' ) || 'Add a sub-series inside' ) + ' “' + parent.name + '”…';
				drop.appendChild( add );
			}

			drop.value = chosen ? chosen.id : '';
			drop.addEventListener( 'change', function () {
				var value = drop.value;
				if ( value === ADD ) {
					drop.value = chosen ? chosen.id : '';
					openAdd( parent );
					return;
				}
				closeAdd();
				say( '' );
				if ( value !== '' ) {
					choose( value );
				} else {
					// Back to the level above: the series chosen is the one this drop-down sits in.
					choose( parent ? parent.id : '' );
				}
			} );
			row.appendChild( drop );
			return row;
		}

		// ---- Adding a series ----

		function closeAdd() {
			addBox.hidden = true;
			addBox.setAttribute( 'data-open', '0' );
			addBox.textContent = '';
		}

		function openAdd( parent ) {
			addBox.textContent = '';
			addBox.setAttribute( 'data-open', '1' );
			addBox.hidden = false;
			say( '' );

			addBox.appendChild( el( 'div', 'nm-series-picker__add-title', parent
				? ( select.getAttribute( 'data-add-sub-title' ) || 'New sub-series inside' ) + ' “' + chainOf( parent.id ).map( function ( part ) {
					return part.name;
				} ).join( ' / ' ) + '”'
				: ( select.getAttribute( 'data-add-top-title' ) || 'New series' ) ) );

			var row   = el( 'div', 'nm-series-picker__add-row' );
			var input = el( 'input', 'nm-series-picker__add-input' );
			input.type        = 'text';
			input.maxLength   = 200;
			input.placeholder = select.getAttribute( 'data-add-placeholder' ) || 'Name';
			input.setAttribute( 'aria-label', input.placeholder );
			var go = el( 'button', 'nm-series-picker__add-go', select.getAttribute( 'data-add-go' ) || 'Add' );
			go.type = 'button';
			var cancel = el( 'button', 'nm-series-picker__add-cancel', select.getAttribute( 'data-add-cancel' ) || 'Cancel' );
			cancel.type = 'button';
			row.appendChild( input );
			row.appendChild( go );
			row.appendChild( cancel );
			addBox.appendChild( row );

			function submit() {
				var name = input.value.replace( /\s+/g, ' ' ).trim();
				if ( ! name ) {
					say( select.getAttribute( 'data-add-need-name' ) || 'Type a name first.', true );
					input.focus();
					return;
				}
				go.disabled = true;
				input.disabled = true;
				say( '' );
				addSeries( name, parent ? parent.id : 0 ).then( function ( made ) {
					closeAdd();
					var inside = made.parent && nodes[ String( made.parent ) ] ? ' inside “' + nodes[ String( made.parent ) ].label + '”' : '';
					say( made.added ? 'Added “' + made.name + '”' + inside + ' and chosen it.' : '“' + made.path + '” was already there, so it is chosen.', false );
				} ).catch( function ( err ) {
					go.disabled = false;
					input.disabled = false;
					say( err && err.message ? err.message : 'The series could not be added. Please try again.', true );
					input.focus();
				} );
			}

			go.addEventListener( 'click', submit );
			cancel.addEventListener( 'click', function () {
				closeAdd();
				say( '' );
			} );
			input.addEventListener( 'keydown', function ( event ) {
				if ( event.key === 'Enter' || event.keyCode === 13 ) {
					// Never submits the form the picker sits in.
					event.preventDefault();
					event.stopPropagation();
					if ( ! event.isComposing ) {
						submit();
					}
				} else if ( event.key === 'Escape' ) {
					// Closes the name box only; the form around the picker keeps its own Escape (close / discard).
					event.preventDefault();
					event.stopPropagation();
					closeAdd();
					say( '' );
				}
			} );
			input.focus();
		}

		function addSeries( name, parentId ) {
			return fetch( addUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
				body: JSON.stringify( { name: name, parent: parentId || 0 } )
			} ).then( function ( res ) {
				return res.json().then( function ( body ) {
					if ( ! res.ok || ! body || ! body.id ) {
						throw new Error( body && body.message ? body.message : 'The series could not be added (' + res.status + ').' );
					}
					return body;
				}, function () {
					throw new Error( 'The server sent an unexpected reply (' + res.status + '). Please try again.' );
				} );
			} ).then( function ( made ) {
				var id = String( made.id );
				if ( ! nodes[ id ] ) {
					var option = document.createElement( 'option' );
					option.value       = id;
					option.textContent = made.path;
					option.setAttribute( 'data-name', made.name );
					option.setAttribute( 'data-parent', String( made.parent || 0 ) );
					select.appendChild( option );
					load();
				}
				document.dispatchEvent( new CustomEvent( 'nm-series-added', { detail: made } ) );
				choose( id );
				return made;
			} );
		}

		// ---- Keeping the parts in step with the select ----

		function refresh() {
			var value  = String( select.value );
			var chosen = value !== '' && select.selectedIndex >= 0 ? select.options[ select.selectedIndex ] : null;
			current.textContent = chosen
				? ( select.getAttribute( 'data-chosen-label' ) || 'Chosen:' ) + ' ' + chosen.textContent
				: ( select.getAttribute( 'data-none-label' ) || 'No series chosen yet.' );
			current.classList.toggle( 'has-value', !! chosen );
			renderLevels();
			renderRecent();
			applyFilter();
		}

		search.addEventListener( 'input', applyFilter );

		// The form can set the series from code (a file's album tag): keep everything in step.
		if ( select._nmPickerRefresh ) {
			select.removeEventListener( 'change', select._nmPickerRefresh );
		}
		select._nmPickerRefresh = refresh;
		select.addEventListener( 'change', refresh );

		// Enter must not submit the form around the picker (that would publish a batch with no series).
		// With something typed it chooses the first series shown; with nothing typed it does nothing.
		search.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Escape' && search.value ) {
				// Clears the search only. Without this the form's own Escape (close the form, or "discard changes?")
				// would run as well. With nothing typed, Escape still reaches the form as it always did.
				event.preventDefault();
				event.stopPropagation();
				search.value = '';
				applyFilter();
				return;
			}
			if ( ( event.key !== 'Enter' && event.keyCode !== 13 ) || event.isComposing ) {
				return;
			}
			event.preventDefault();
			if ( ! fold( search.value ) ) {
				return;
			}
			var first = list.querySelector( '.nm-series-picker__item' );
			if ( first ) {
				first.click();
			}
		} );

		// After the select, not before it: the picker has drop-downs of its own, and the form's code that looks for "the
		// select in this field" must still find the one that holds the value first.
		select.parentNode.insertBefore( wrap, select.nextSibling );
		select.hidden = true;

		refresh();
	}

	// Builds the picker again from the select's options, after the form added a series to it.
	function rebuild( select ) {
		if ( ! select ) {
			return;
		}
		var old = select.nextElementSibling;
		if ( old && old.classList.contains( 'nm-series-picker' ) ) {
			old.parentNode.removeChild( old );
		}
		select.hidden = false;
		select.removeAttribute( 'data-picker-ready' );
		enhance( select );
	}

	function initAll() {
		document.querySelectorAll( 'select[data-series-picker]' ).forEach( enhance );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initAll );
	} else {
		initAll();
	}

	window.NMSeriesPicker = { enhance: enhance, rebuild: rebuild };
} )( window, document );
