/*
 * Series picker: turns a <select data-series-picker> into a searchable list.
 *
 * The three series with the most recent shiurim (their data-last date, set by
 * the server) sit at the top under "Recent". The rest follow under "All". A
 * search box above filters both groups as you type. Choosing an item sets the
 * select's value and fires its change event, so the form's own saving code
 * doesn't change. The select stays in the page, hidden, and holds the value.
 */
( function ( window, document ) {
	'use strict';

	var RECENT_COUNT = 3;

	function fold( text ) {
		return String( text || '' ).toLowerCase().replace( /\s+/g, ' ' ).trim();
	}

	function enhance( select ) {
		if ( ! select || select.getAttribute( 'data-picker-ready' ) === '1' ) {
			return;
		}
		select.setAttribute( 'data-picker-ready', '1' );

		var options = Array.prototype.slice.call( select.options );
		var none    = null;
		var real    = [];
		options.forEach( function ( option ) {
			if ( option.value === '' ) {
				if ( ! none ) {
					none = option;
				}
			} else {
				real.push( option );
			}
		} );

		var recent = real
			.filter( function ( option ) {
				return option.getAttribute( 'data-last' );
			} )
			.sort( function ( a, b ) {
				return b.getAttribute( 'data-last' ).localeCompare( a.getAttribute( 'data-last' ) );
			} )
			.slice( 0, RECENT_COUNT );

		var rest = real.filter( function ( option ) {
			return recent.indexOf( option ) === -1;
		} );

		var wrap = document.createElement( 'div' );
		wrap.className = 'nm-series-picker';

		var search = document.createElement( 'input' );
		search.type        = 'search';
		search.className   = 'nm-series-picker__search';
		search.placeholder = select.getAttribute( 'data-placeholder' ) || 'Search…';
		search.setAttribute( 'aria-label', search.placeholder );
		wrap.appendChild( search );

		var list = document.createElement( 'div' );
		list.className = 'nm-series-picker__list';
		list.setAttribute( 'role', 'listbox' );
		wrap.appendChild( list );

		var items    = [];
		var sections = [];
		var noneButton = null;

		function makeButton( option ) {
			var button = document.createElement( 'button' );
			button.type        = 'button';
			button.className   = 'nm-series-picker__item';
			button.textContent = option.textContent;
			button.setAttribute( 'data-value', option.value );
			button.addEventListener( 'click', function () {
				select.value = option.value;
				refresh();
				select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			} );
			return button;
		}

		function addSection( title, group ) {
			if ( ! group.length ) {
				return;
			}
			var section = document.createElement( 'div' );
			section.className = 'nm-series-picker__section';

			var heading = document.createElement( 'div' );
			heading.className   = 'nm-series-picker__heading';
			heading.textContent = title;
			section.appendChild( heading );

			var entry = { el: section, items: [] };
			group.forEach( function ( option ) {
				var button = makeButton( option );
				section.appendChild( button );
				var item = { option: option, button: button };
				entry.items.push( item );
				items.push( item );
			} );

			sections.push( entry );
			list.appendChild( section );
		}

		if ( none ) {
			noneButton = makeButton( none );
			noneButton.classList.add( 'nm-series-picker__none' );
			list.appendChild( noneButton );
			items.push( { option: none, button: noneButton } );
		}

		addSection( select.getAttribute( 'data-recent-label' ) || 'Recent', recent );
		addSection( select.getAttribute( 'data-all-label' ) || 'All', rest );

		function refresh() {
			var current = select.value;
			items.forEach( function ( item ) {
				var on = item.option.value === current;
				item.button.classList.toggle( 'is-selected', on );
				item.button.setAttribute( 'aria-selected', on ? 'true' : 'false' );
			} );
		}

		function applyFilter() {
			var query = fold( search.value );
			items.forEach( function ( item ) {
				var show = ! query || fold( item.option.textContent ).indexOf( query ) !== -1;
				item.button.hidden = ! show;
			} );
			sections.forEach( function ( section ) {
				section.el.hidden = ! section.items.some( function ( item ) {
					return ! item.button.hidden;
				} );
			} );
			if ( noneButton ) {
				noneButton.hidden = !! query;
			}
		}

		search.addEventListener( 'input', applyFilter );

		select.parentNode.insertBefore( wrap, select );
		select.hidden = true;

		refresh();
		applyFilter();
	}

	function initAll() {
		document.querySelectorAll( 'select[data-series-picker]' ).forEach( enhance );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initAll );
	} else {
		initAll();
	}

	window.NMSeriesPicker = { enhance: enhance };
} )( window, document );
