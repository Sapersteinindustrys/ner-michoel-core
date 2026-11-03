/**
 * Control Panel > Alumni > Alumni Directory (includes/alumni-directory.php).
 *
 * The whole directory is loaded once (a few thousand rows is small) and
 * searched, filtered, sorted and paged here. A row opens in a side panel to
 * edit, tie to an account, or delete. "Download" gives the rows shown as a CSV
 * that Excel opens. "Columns" renames, re-kinds, re-orders, hides or removes
 * columns.
 */
( function () {
	'use strict';

	var cfg = window.nmAlumniDir || {};
	var app = document.getElementById( 'nm-adir-app' );
	if ( ! app || ! cfg.root ) {
		return;
	}

	var HIDDEN_KEY = 'nmAlumniDirHidden';
	var state = {
		columns: [],
		kinds: {},
		rows: [],
		q: '',
		account: '',
		optin: '',
		self: '',
		sort: { key: '', dir: 1 },
		page: 0,
		per: 50,
		hidden: readHidden(),
		selected: {}
	};

	function readHidden() {
		try {
			return JSON.parse( window.localStorage.getItem( HIDDEN_KEY ) || '{}' ) || {};
		} catch ( e ) {
			return {};
		}
	}
	function saveHidden() {
		try {
			window.localStorage.setItem( HIDDEN_KEY, JSON.stringify( state.hidden ) );
		} catch ( e ) {}
	}

	function api( path, method, body ) {
		return fetch( cfg.root + '/' + path, {
			method: method || 'GET',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: body ? JSON.stringify( body ) : undefined
		} ).then( function ( res ) {
			return res.json().then( function ( data ) {
				if ( ! res.ok ) {
					throw new Error( ( data && data.message ) || 'Something went wrong.' );
				}
				return data;
			} );
		} );
	}

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			var v = attrs[ k ];
			if ( null === v || undefined === v || false === v ) {
				return;
			}
			if ( 'text' === k ) {
				node.textContent = v;
			} else if ( 'class' === k ) {
				node.className = v;
			} else if ( 0 === k.indexOf( 'on' ) ) {
				node.addEventListener( k.slice( 2 ), v );
			} else if ( true === v ) {
				node.setAttribute( k, '' );
			} else {
				node.setAttribute( k, v );
			}
		} );
		( children || [] ).forEach( function ( c ) {
			if ( null !== c && undefined !== c ) {
				node.appendChild( 'string' === typeof c ? document.createTextNode( c ) : c );
			}
		} );
		return node;
	}

	function fold( text ) {
		return String( text || '' ).toLowerCase().normalize( 'NFD' ).replace( /[̀-֑ͯ-ׇ]/g, '' );
	}

	function cell( row, key ) {
		return row.d && row.d[ key ] ? String( row.d[ key ] ) : '';
	}

	function shownColumns() {
		return state.columns.filter( function ( c ) {
			return ! state.hidden[ c.key ];
		} );
	}

	/* ---------------- Filtering ---------------- */

	function matches( row ) {
		if ( 'linked' === state.account && ! row.account ) {
			return false;
		}
		if ( 'unlinked' === state.account && row.account ) {
			return false;
		}
		if ( 'yes' === state.optin && ! row.optin ) {
			return false;
		}
		if ( 'no' === state.optin && row.optin ) {
			return false;
		}
		if ( 'yes' === state.self && ! row.selfAt ) {
			return false;
		}
		if ( state.q ) {
			var hay = row._hay;
			if ( undefined === hay ) {
				hay = row._hay = fold( Object.keys( row.d || {} ).map( function ( k ) {
					return row.d[ k ];
				} ).join( ' ' ) + ' ' + ( row.account ? row.account.name + ' ' + row.account.email : '' ) );
			}
			var words = fold( state.q ).split( /\s+/ ).filter( Boolean );
			for ( var i = 0; i < words.length; i++ ) {
				if ( -1 === hay.indexOf( words[ i ] ) ) {
					return false;
				}
			}
		}
		return true;
	}

	function visibleRows() {
		var rows = state.rows.filter( matches );
		var s = state.sort;
		if ( s.key ) {
			rows.sort( function ( a, b ) {
				var x, y;
				if ( '_account' === s.key ) {
					x = a.account ? a.account.name : '';
					y = b.account ? b.account.name : '';
				} else if ( '_optin' === s.key ) {
					x = a.optin ? 1 : 0;
					y = b.optin ? 1 : 0;
					return ( x - y ) * s.dir;
				} else if ( '_updated' === s.key ) {
					x = a.selfAt || '';
					y = b.selfAt || '';
				} else {
					x = cell( a, s.key );
					y = cell( b, s.key );
				}
				if ( '' === x && '' !== y ) {
					return 1;
				}
				if ( '' !== x && '' === y ) {
					return -1;
				}
				return x.localeCompare( y, undefined, { numeric: true, sensitivity: 'base' } ) * s.dir;
			} );
		}
		return rows;
	}

	/* ---------------- Drawing ---------------- */

	var refs = {};

	function drawShell() {
		app.innerHTML = '';
		refs.search = el( 'input', {
			type: 'search',
			class: 'nm-adir__search',
			placeholder: 'Search names, emails, cities, anything…',
			'aria-label': 'Search the directory',
			oninput: function () {
				state.q = refs.search.value;
				state.page = 0;
				drawTable();
			}
		} );
		function filter( label, key, options ) {
			var sel = el( 'select', {
				'aria-label': label,
				onchange: function () {
					state[ key ] = sel.value;
					state.page = 0;
					drawTable();
				}
			}, options.map( function ( o ) {
				return el( 'option', { value: o[ 0 ], text: o[ 1 ] } );
			} ) );
			return sel;
		}
		var bar = el( 'div', { class: 'nm-adir__bar' }, [
			refs.search,
			filter( 'Account', 'account', [ [ '', 'All rows' ], [ 'linked', 'Has an account' ], [ 'unlinked', 'No account yet' ] ] ),
			filter( 'Emails', 'optin', [ [ '', 'Emails: any' ], [ 'yes', 'Wants emails' ], [ 'no', 'Doesn’t want emails' ] ] ),
			filter( 'Updated', 'self', [ [ '', 'Updated by alumnus: any' ], [ 'yes', 'Updated by alumnus' ] ] ),
			el( 'span', { class: 'nm-adir__spacer' } ),
			el( 'button', { type: 'button', class: 'button button-primary', text: 'Add alumnus', onclick: function () {
				openRow( null );
			} } ),
			el( 'button', { type: 'button', class: 'button', text: 'Columns', onclick: openColumns } ),
			el( 'button', { type: 'button', class: 'button', text: 'Download shown (CSV)', onclick: download } ),
			el( 'a', { class: 'button', href: cfg.uploadUrl, text: 'Upload a sheet' } )
		] );
		refs.count = el( 'p', { class: 'nm-adir__count' } );
		refs.bulk = el( 'div', { class: 'nm-adir__bulk', hidden: true } );
		refs.table = el( 'div', { class: 'nm-adir__scroll' } );
		refs.pager = el( 'div', { class: 'nm-adir__pager' } );
		refs.panel = el( 'div', { class: 'nm-adir__panel', hidden: true, role: 'dialog', 'aria-modal': 'true' } );
		refs.shade = el( 'div', { class: 'nm-adir__shade', hidden: true, onclick: closePanel } );
		[ bar, refs.count, refs.bulk, refs.table, refs.pager, refs.shade, refs.panel ].forEach( function ( n ) {
			app.appendChild( n );
		} );
		document.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key && ! refs.panel.hidden ) {
				closePanel();
			}
		} );
	}

	function sortHeader( key, label ) {
		var on = state.sort.key === key;
		return el( 'th', { scope: 'col', 'aria-sort': on ? ( 1 === state.sort.dir ? 'ascending' : 'descending' ) : null }, [
			el( 'button', { type: 'button', class: 'nm-adir__sort' + ( on ? ' is-on' : '' ), onclick: function () {
				if ( state.sort.key === key ) {
					state.sort.dir = -state.sort.dir;
				} else {
					state.sort = { key: key, dir: 1 };
				}
				drawTable();
			} }, [ label, on ? ( 1 === state.sort.dir ? ' ▲' : ' ▼' ) : '' ] )
		] );
	}

	function drawTable() {
		var rows = visibleRows();
		var cols = shownColumns();
		var pages = Math.max( 1, Math.ceil( rows.length / state.per ) );
		state.page = Math.min( state.page, pages - 1 );
		var slice = rows.slice( state.page * state.per, ( state.page + 1 ) * state.per );

		refs.count.textContent = rows.length === state.rows.length
			? state.rows.length.toLocaleString() + ' alumni'
			: rows.length.toLocaleString() + ' of ' + state.rows.length.toLocaleString() + ' alumni';

		if ( ! state.rows.length ) {
			refs.table.innerHTML = '';
			refs.table.appendChild( el( 'p', { class: 'nm-adir__empty' }, [ 'The directory is empty. ', el( 'a', { href: cfg.uploadUrl, text: 'Upload the alumni sheet' } ), ' to fill it.' ] ) );
			refs.pager.innerHTML = '';
			drawBulk();
			return;
		}

		var allBox = el( 'input', { type: 'checkbox', 'aria-label': 'Select all shown', onchange: function () {
			slice.forEach( function ( r ) {
				if ( allBox.checked ) {
					state.selected[ r.id ] = true;
				} else {
					delete state.selected[ r.id ];
				}
			} );
			drawTable();
		} } );
		allBox.checked = slice.length > 0 && slice.every( function ( r ) {
			return state.selected[ r.id ];
		} );

		var head = el( 'tr', {}, [ el( 'th', { class: 'nm-adir__check' }, [ allBox ] ) ]
			.concat( cols.map( function ( c ) {
				return sortHeader( c.key, c.label );
			} ) )
			.concat( [ sortHeader( '_account', 'Account' ), sortHeader( '_optin', 'Emails' ), sortHeader( '_updated', 'Updated by alumnus' ) ] ) );

		var body = el( 'tbody', {}, slice.map( function ( r ) {
			var box = el( 'input', { type: 'checkbox', 'aria-label': 'Select row', onclick: function ( e ) {
				e.stopPropagation();
			}, onchange: function () {
				if ( box.checked ) {
					state.selected[ r.id ] = true;
				} else {
					delete state.selected[ r.id ];
				}
				drawBulk();
			} } );
			box.checked = !! state.selected[ r.id ];
			var tr = el( 'tr', { class: 'nm-adir__row', tabindex: '0', onclick: function () {
				openRow( r );
			}, onkeydown: function ( e ) {
				if ( 'Enter' === e.key ) {
					openRow( r );
				}
			} }, [ el( 'td', { class: 'nm-adir__check' }, [ box ] ) ]
				.concat( cols.map( function ( c ) {
					return el( 'td', { dir: 'auto', class: 'address' === c.kind ? 'nm-adir__addr' : null, text: cell( r, c.key ) } );
				} ) )
				.concat( [
					el( 'td', {}, r.account ? [ r.account.name, el( 'br' ), el( 'small', { text: r.account.email + ( r.account.unverified ? ' (not confirmed yet)' : '' ) } ) ] : [ el( 'span', { class: 'nm-adir__muted', text: '—' } ) ] ),
					el( 'td', { text: r.optin ? 'Yes' : 'No' } ),
					el( 'td', { text: r.selfAt ? new Date( r.selfAt ).toLocaleDateString() : '' } )
				] ) );
			return tr;
		} ) );

		var table = el( 'table', { class: 'widefat striped nm-adir__table' }, [ el( 'thead', {}, [ head ] ), body ] );
		refs.table.innerHTML = '';
		refs.table.appendChild( table );

		refs.pager.innerHTML = '';
		if ( pages > 1 ) {
			refs.pager.appendChild( el( 'button', { type: 'button', class: 'button', text: '‹ Previous', disabled: 0 === state.page, onclick: function () {
				state.page--;
				drawTable();
			} } ) );
			refs.pager.appendChild( el( 'span', { text: ' Page ' + ( state.page + 1 ) + ' of ' + pages + ' ' } ) );
			refs.pager.appendChild( el( 'button', { type: 'button', class: 'button', text: 'Next ›', disabled: state.page >= pages - 1, onclick: function () {
				state.page++;
				drawTable();
			} } ) );
		}
		var per = el( 'select', { 'aria-label': 'Rows per page', onchange: function () {
			state.per = parseInt( per.value, 10 );
			state.page = 0;
			drawTable();
		} }, [ 25, 50, 100, 250 ].map( function ( n ) {
			var o = el( 'option', { value: String( n ), text: n + ' per page' } );
			o.selected = n === state.per;
			return o;
		} ) );
		refs.pager.appendChild( per );
		drawBulk();
	}

	function drawBulk() {
		var ids = Object.keys( state.selected ).map( Number );
		refs.bulk.innerHTML = '';
		refs.bulk.hidden = ! ids.length;
		if ( ! ids.length ) {
			return;
		}
		refs.bulk.appendChild( el( 'span', { text: ids.length + ' selected ' } ) );
		refs.bulk.appendChild( el( 'button', { type: 'button', class: 'button', text: 'Download selected (CSV)', onclick: function () {
			download( state.rows.filter( function ( r ) {
				return state.selected[ r.id ];
			} ) );
		} } ) );
		refs.bulk.appendChild( el( 'button', { type: 'button', class: 'button button-link-delete', text: 'Delete selected', onclick: function () {
			if ( ! window.confirm( 'Delete ' + ids.length + ' rows from the directory? Accounts are not deleted.' ) ) {
				return;
			}
			api( 'row-delete', 'POST', { ids: ids } ).then( function ( res ) {
				var gone = {};
				res.deleted.forEach( function ( id ) {
					gone[ id ] = true;
				} );
				state.rows = state.rows.filter( function ( r ) {
					return ! gone[ r.id ];
				} );
				state.selected = {};
				drawTable();
			} ).catch( alertError );
		} } ) );
		refs.bulk.appendChild( el( 'button', { type: 'button', class: 'button-link', text: 'Clear', onclick: function () {
			state.selected = {};
			drawTable();
		} } ) );
	}

	function alertError( err ) {
		window.alert( err && err.message ? err.message : 'Something went wrong.' );
	}

	/* ---------------- Side panel ---------------- */

	function openPanel( title, children ) {
		refs.panel.innerHTML = '';
		refs.panel.appendChild( el( 'div', { class: 'nm-adir__panel-head' }, [
			el( 'h2', { text: title } ),
			el( 'button', { type: 'button', class: 'button-link nm-adir__close', 'aria-label': 'Close', text: '×', onclick: closePanel } )
		] ) );
		children.forEach( function ( c ) {
			refs.panel.appendChild( c );
		} );
		refs.panel.hidden = false;
		refs.shade.hidden = false;
		var first = refs.panel.querySelector( 'input, textarea, select, button' );
		if ( first ) {
			first.focus();
		}
	}

	function closePanel() {
		refs.panel.hidden = true;
		refs.shade.hidden = true;
	}

	function replaceRow( row ) {
		var found = false;
		state.rows = state.rows.map( function ( r ) {
			if ( r.id === row.id ) {
				found = true;
				return row;
			}
			return r;
		} );
		if ( ! found ) {
			state.rows.push( row );
		}
	}

	function openRow( row ) {
		var inputs = {};
		var form = el( 'form', { class: 'nm-adir__form', onsubmit: function ( e ) {
			e.preventDefault();
			var data = {};
			Object.keys( inputs ).forEach( function ( k ) {
				data[ k ] = inputs[ k ].value;
			} );
			save.disabled = true;
			api( 'row', 'POST', { id: row ? row.id : 0, data: data, optin: optin.checked } ).then( function ( res ) {
				replaceRow( res.row );
				closePanel();
				drawTable();
			} ).catch( function ( err ) {
				save.disabled = false;
				alertError( err );
			} );
		} } );
		state.columns.forEach( function ( c ) {
			var input = 'address' === c.kind || 'other' === c.kind && cell( row || {}, c.key ).length > 60
				? el( 'textarea', { rows: 3 } )
				: el( 'input', { type: 'email' === c.kind ? 'text' : 'text' } );
			input.value = row ? cell( row, c.key ) : '';
			input.setAttribute( 'dir', 'auto' );
			inputs[ c.key ] = input;
			var edited = row && row.self && -1 !== row.self.indexOf( c.key );
			form.appendChild( el( 'label', { class: 'nm-adir__field' }, [
				el( 'span', {}, [ c.label, ' ', el( 'small', { text: '(' + ( state.kinds[ c.kind ] || c.kind ) + ( edited ? ', changed by the alumnus' : '' ) + ')' } ) ] ),
				input
			] ) );
		} );
		if ( ! state.columns.length ) {
			form.appendChild( el( 'p', { text: 'There are no columns yet. Upload a sheet first, or add columns with “Columns”.' } ) );
		}
		var optin = el( 'input', { type: 'checkbox' } );
		optin.checked = !! ( row && row.optin );
		form.appendChild( el( 'label', { class: 'nm-adir__check-field' }, [ optin, ' Wants emails (opted in)' ] ) );
		var save = el( 'button', { type: 'submit', class: 'button button-primary', text: row ? 'Save' : 'Add' } );
		form.appendChild( el( 'p', {}, [ save, ' ', el( 'button', { type: 'button', class: 'button', text: 'Cancel', onclick: closePanel } ) ] ) );

		var extra = [];
		if ( row ) {
			var acct = el( 'div', { class: 'nm-adir__acct' } );
			if ( row.account ) {
				var how = { email: 'matched by email at sign-up', name: 'matched by name at sign-up', office: 'tied by the office' }[ row.by ] || '';
				acct.appendChild( el( 'h3', { text: 'Account' } ) );
				acct.appendChild( el( 'p', {}, [
					el( 'a', { href: cfg.userUrl + row.account.id, target: '_blank', rel: 'noopener', text: row.account.name } ),
					' — ' + row.account.email + ( how ? ' (' + how + ')' : '' )
				] ) );
				acct.appendChild( el( 'button', { type: 'button', class: 'button', text: 'Untie the account', onclick: function () {
					if ( ! window.confirm( 'Untie this account from the row? The account stays; it can find itself again from its Account page.' ) ) {
						return;
					}
					api( 'row-unlink', 'POST', { id: row.id } ).then( function ( res ) {
						replaceRow( res.row );
						closePanel();
						drawTable();
					} ).catch( alertError );
				} } ) );
			} else {
				var email = el( 'input', { type: 'email', placeholder: 'Their account email' } );
				acct.appendChild( el( 'h3', { text: 'Account' } ) );
				acct.appendChild( el( 'p', { class: 'description', text: 'No account is tied to this row. Alumni tie themselves when they sign up. To tie an existing account by hand, type its email:' } ) );
				acct.appendChild( el( 'p', {}, [ email, ' ', el( 'button', { type: 'button', class: 'button', text: 'Tie account', onclick: function () {
					api( 'row-link', 'POST', { id: row.id, email: email.value } ).then( function ( res ) {
						replaceRow( res.row );
						closePanel();
						drawTable();
					} ).catch( alertError );
				} } ) ] ) );
			}
			extra.push( acct );
			extra.push( el( 'p', { class: 'nm-adir__danger' }, [ el( 'button', { type: 'button', class: 'button button-link-delete', text: 'Delete this row', onclick: function () {
				if ( ! window.confirm( 'Delete this row from the directory? A tied account is not deleted.' ) ) {
					return;
				}
				api( 'row-delete', 'POST', { ids: [ row.id ] } ).then( function () {
					state.rows = state.rows.filter( function ( r ) {
						return r.id !== row.id;
					} );
					delete state.selected[ row.id ];
					closePanel();
					drawTable();
				} ).catch( alertError );
			} } ) ] ) );
		}
		openPanel( row ? ( nameOf( row ) || 'Alumnus' ) : 'Add alumnus', [ form ].concat( extra ) );
	}

	function nameOf( row ) {
		var first = '', last = '', full = '';
		state.columns.forEach( function ( c ) {
			var v = cell( row, c.key );
			if ( 'first' === c.kind && ! first ) {
				first = v;
			} else if ( 'last' === c.kind && ! last ) {
				last = v;
			} else if ( 'name' === c.kind && ! full ) {
				full = v;
			}
		} );
		return ( first + ' ' + last ).trim() || full;
	}

	/* ---------------- Columns ---------------- */

	function openColumns() {
		var list = el( 'ol', { class: 'nm-adir__cols' } );
		var items = state.columns.map( function ( c ) {
			return { key: c.key, label: c.label, kind: c.kind };
		} );
		function draw() {
			list.innerHTML = '';
			items.forEach( function ( c, i ) {
				var label = el( 'input', { type: 'text', value: c.label, 'aria-label': 'Column name', oninput: function () {
					c.label = label.value;
				} } );
				var kind = el( 'select', { 'aria-label': 'What this column is', onchange: function () {
					c.kind = kind.value;
				} }, Object.keys( state.kinds ).map( function ( k ) {
					var o = el( 'option', { value: k, text: state.kinds[ k ] } );
					o.selected = k === c.kind;
					return o;
				} ) );
				var show = el( 'input', { type: 'checkbox', onchange: function () {
					if ( show.checked ) {
						delete state.hidden[ c.key ];
					} else {
						state.hidden[ c.key ] = true;
					}
				} } );
				show.checked = ! c.key || ! state.hidden[ c.key ];
				list.appendChild( el( 'li', {}, [
					label, kind,
					el( 'label', { class: 'nm-adir__show' }, [ show, ' Show in table' ] ),
					el( 'button', { type: 'button', class: 'button-link', 'aria-label': 'Move up', text: '↑', disabled: 0 === i, onclick: function () {
						items.splice( i - 1, 0, items.splice( i, 1 )[ 0 ] );
						draw();
					} } ),
					el( 'button', { type: 'button', class: 'button-link', 'aria-label': 'Move down', text: '↓', disabled: i === items.length - 1, onclick: function () {
						items.splice( i + 1, 0, items.splice( i, 1 )[ 0 ] );
						draw();
					} } ),
					el( 'button', { type: 'button', class: 'button-link button-link-delete', text: 'Remove', onclick: function () {
						if ( c.key && ! window.confirm( 'Remove the column “' + c.label + '” and what it holds in every row?' ) ) {
							return;
						}
						items.splice( i, 1 );
						draw();
					} } )
				] ) );
			} );
		}
		draw();
		var saveBtn = el( 'button', { type: 'button', class: 'button button-primary', text: 'Save columns', onclick: function () {
			saveBtn.disabled = true;
			api( 'columns', 'POST', { columns: items } ).then( function () {
				saveHidden();
				return load();
			} ).then( closePanel ).catch( function ( err ) {
				saveBtn.disabled = false;
				alertError( err );
			} );
		} } );
		openPanel( 'Columns', [
			el( 'p', { class: 'description', text: 'Rename a column, say what it is (names and emails are what alumni are found by), change the order, hide it from this table, or remove it. Alumni see every column on their Account page, and can change all but Years.' } ),
			list,
			el( 'p', {}, [ el( 'button', { type: 'button', class: 'button', text: 'Add a column', onclick: function () {
				items.push( { key: '', label: 'New column', kind: 'other' } );
				draw();
			} } ) ] ),
			el( 'p', {}, [ saveBtn, ' ', el( 'button', { type: 'button', class: 'button', text: 'Cancel', onclick: closePanel } ) ] )
		] );
	}

	/* ---------------- Download ---------------- */

	function csvCell( v ) {
		v = String( null === v || undefined === v ? '' : v );
		if ( /^[=+\-@\t\r]/.test( v ) ) {
			v = '\'' + v; // never a formula in Excel
		}
		return /[",\n\r]/.test( v ) ? '"' + v.replace( /"/g, '""' ) + '"' : v;
	}

	function download( rows ) {
		rows = Array.isArray( rows ) ? rows : visibleRows();
		var head = state.columns.map( function ( c ) {
			return c.label;
		} ).concat( [ 'Wants emails', 'Account email', 'Updated by alumnus' ] );
		var lines = [ head.map( csvCell ).join( ',' ) ];
		rows.forEach( function ( r ) {
			lines.push( state.columns.map( function ( c ) {
				return cell( r, c.key );
			} ).concat( [ r.optin ? 'Yes' : 'No', r.account ? r.account.email : '', r.selfAt ? r.selfAt.slice( 0, 10 ) : '' ] ).map( csvCell ).join( ',' ) );
		} );
		var blob = new Blob( [ '﻿' + lines.join( '\r\n' ) ], { type: 'text/csv;charset=utf-8' } );
		var a = el( 'a', { href: URL.createObjectURL( blob ), download: 'alumni-directory-' + new Date().toISOString().slice( 0, 10 ) + '.csv' } );
		document.body.appendChild( a );
		a.click();
		setTimeout( function () {
			URL.revokeObjectURL( a.href );
			a.remove();
		}, 1000 );
	}

	/* ---------------- Start ---------------- */

	function load() {
		return api( 'rows' ).then( function ( data ) {
			state.columns = data.columns || [];
			state.kinds = data.kinds || {};
			state.rows = data.rows || [];
			drawTable();
		} );
	}

	drawShell();
	load().catch( function ( err ) {
		app.innerHTML = '';
		app.appendChild( el( 'p', { class: 'notice notice-error', text: 'The directory could not be loaded: ' + err.message } ) );
	} );
} )();
