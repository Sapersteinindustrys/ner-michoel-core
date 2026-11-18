/**
 * Series & Topics Re-sort screen (includes/series-resort-screen.php): the plan as an editable
 * table. Every change is saved as it is made (REST ner-michoel/v1/resort/row).
 */
( function () {
	'use strict';
	var cfg = window.nmResort;
	var root = document.getElementById( 'nm-resort' );
	if ( ! cfg || ! root ) {
		return;
	}
	var $ = function ( sel ) { return root.querySelector( sel ); };
	var data = { rows: [], summary: {}, settings: {}, state: {} };
	var view = { kind: 'series', search: '', review: true, page: 0 };
	var PER = 100;
	var LABELS = {
		move: 'Move to', keep: 'Keep as is', by_title: 'By title', 'delete': 'Delete',
		rename: 'Rename to', merge: 'Merge into', drop: 'Drop', assign: 'Give speaker'
	};
	var COLS = {
		series: [ 'Series now', 'Shiurim', 'Choice', 'Goes to', 'Add topics', 'Sure?', 'Note', '' ],
		shiur: [ 'Shiur', 'Choice', 'Goes to', 'Add topics', 'Sure?', 'Note', '' ],
		topic: [ 'Topic', 'Shiurim', 'Choice', 'New name / merge into', 'Sure?', 'Note', '' ],
		speaker: [ 'Speaker', 'Shiurim', 'Choice', 'New name / merge into', 'Sure?', 'Note', '' ],
		byspeak: [ 'Shiur', 'Choice', 'Speaker', 'Sure?', 'Note', '' ]
	};

	function api( path, body, isForm ) {
		var opts = { method: body ? 'POST' : 'GET', credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce } };
		if ( body ) {
			if ( isForm ) {
				opts.body = body;
			} else {
				opts.headers[ 'Content-Type' ] = 'application/json';
				opts.body = JSON.stringify( body );
			}
		}
		return fetch( cfg.api + path, opts ).then( function ( r ) {
			return r.json().then( function ( j ) {
				if ( ! r.ok ) {
					throw new Error( ( j && j.message ) || ( 'Error ' + r.status ) );
				}
				return j;
			} );
		} );
	}
	function el( tag, attrs, text ) {
		var node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			if ( 'class' === k ) {
				node.className = attrs[ k ];
			} else {
				node.setAttribute( k, attrs[ k ] );
			}
		} );
		if ( null != text ) {
			node.textContent = text;
		}
		return node;
	}
	function hint( text, bad ) {
		var h = $( '[data-nm-hint]' );
		h.textContent = text || '';
		h.classList.toggle( 'is-bad', !! bad );
	}
	function locked() {
		return 'idle' !== data.state.phase;
	}

	/* ---------- loading ---------- */

	function load() {
		return api( 'rows' ).then( function ( j ) {
			data = j;
			fillLists();
			fillSettings();
			showState();
			render();
		} ).catch( function ( e ) {
			$( '[data-nm-body]' ).innerHTML = '';
			hint( e.message, true );
		} );
	}
	function fillLists() {
		var places = {};
		data.rows.forEach( function ( r ) {
			if ( ( 'series' === r.kind || 'shiur' === r.kind ) && r.target ) {
				var parts = r.target.split( ' / ' );
				for ( var i = 1; i <= parts.length; i++ ) {
					places[ parts.slice( 0, i ).join( ' / ' ) ] = true;
				}
			}
		} );
		var fill = function ( id, list ) {
			var dl = document.getElementById( id );
			dl.innerHTML = '';
			list.sort().forEach( function ( v ) {
				dl.appendChild( el( 'option', { value: v } ) );
			} );
		};
		fill( 'nm-resort-places', Object.keys( places ) );
		fill( 'nm-resort-topics', ( data.topics || [] ).map( decode ) );
		fill( 'nm-resort-speakers', ( data.speakers || [] ).map( decode ) );
	}
	function decode( s ) {
		var t = document.createElement( 'textarea' );
		t.innerHTML = s;
		return t.value;
	}

	/* ---------- the table ---------- */

	function shown() {
		var q = view.search.toLowerCase();
		return data.rows.filter( function ( r ) {
			if ( r.kind !== view.kind ) {
				return false;
			}
			if ( view.review && ! r.review ) {
				return false;
			}
			return ! q || ( r.current_label + ' ' + r.target + ' ' + r.add_topics + ' ' + r.note ).toLowerCase().indexOf( q ) !== -1;
		} );
	}
	function render() {
		root.querySelectorAll( '[data-nm-tabs] button' ).forEach( function ( b ) {
			var s = data.summary[ b.getAttribute( 'data-kind' ) ] || { rows: 0, review: 0 };
			b.querySelector( 'span' ).textContent = s.rows ? '(' + s.rows + ( s.review ? ', ' + s.review + ' to look at' : '' ) + ')' : '(0)';
			b.setAttribute( 'aria-selected', b.getAttribute( 'data-kind' ) === view.kind ? 'true' : 'false' );
		} );
		var head = $( '[data-nm-head]' );
		head.innerHTML = '';
		COLS[ view.kind ].forEach( function ( c ) {
			head.appendChild( el( 'th', {}, c ) );
		} );
		var rows = shown();
		var pages = Math.max( 1, Math.ceil( rows.length / PER ) );
		view.page = Math.min( view.page, pages - 1 );
		var body = $( '[data-nm-body]' );
		body.innerHTML = '';
		if ( ! rows.length ) {
			var td = el( 'td', { colspan: COLS[ view.kind ].length }, view.review ? 'Nothing here needs a look. Untick “Only rows that need a look” to see every row.' : 'No rows.' );
			body.appendChild( el( 'tr' ) ).appendChild( td );
		}
		rows.slice( view.page * PER, view.page * PER + PER ).forEach( function ( r ) {
			body.appendChild( rowNode( r ) );
		} );
		var pager = $( '[data-nm-pager]' );
		pager.innerHTML = '';
		if ( pages > 1 ) {
			for ( var p = 0; p < pages; p++ ) {
				var b = el( 'button', { type: 'button', 'class': 'button' + ( p === view.page ? ' button-primary' : '' ), 'data-page': p }, String( p + 1 ) );
				pager.appendChild( b );
			}
		}
		pager.appendChild( el( 'span', {}, ' ' + rows.length + ' rows' ) );
	}
	function rowNode( r ) {
		var tr = el( 'tr', { 'data-id': r.id, 'class': 'is-' + r.status + ( r.review ? ' needs-review' : '' ) } );
		var kind = r.kind;
		var name = el( 'td', { 'class': 'nm-resort-name' } );
		name.appendChild( el( 'span', {}, r.current_label ) );
		tr.appendChild( name );
		if ( 'series' === kind || 'topic' === kind || 'speaker' === kind ) {
			tr.appendChild( el( 'td', { 'class': 'num' }, String( r.shiurim ) ) );
		}
		var choice = el( 'select', { 'data-field': 'action', 'aria-label': 'Choice' } );
		cfg.actions[ kind ].forEach( function ( a ) {
			var o = el( 'option', { value: a }, LABELS[ a ] || a );
			if ( a === r.action ) {
				o.selected = true;
			}
			choice.appendChild( o );
		} );
		choice.disabled = locked();
		tr.appendChild( el( 'td' ) ).appendChild( choice );
		var list = ( 'topic' === kind ) ? 'nm-resort-topics' : ( 'speaker' === kind || 'byspeak' === kind ) ? 'nm-resort-speakers' : 'nm-resort-places';
		var target = el( 'input', { type: 'text', 'data-field': 'target', list: list, value: r.target, 'aria-label': 'Goes to' } );
		target.disabled = locked() || [ 'keep', 'drop', 'delete', 'by_title' ].indexOf( r.action ) !== -1;
		tr.appendChild( el( 'td', { 'class': 'nm-resort-target' } ) ).appendChild( target );
		if ( 'series' === kind || 'shiur' === kind ) {
			var add = el( 'input', { type: 'text', 'data-field': 'add_topics', value: r.add_topics, 'aria-label': 'Add topics', placeholder: 'none' } );
			add.disabled = locked();
			tr.appendChild( el( 'td' ) ).appendChild( add );
		}
		tr.appendChild( el( 'td' ) ).appendChild( el( 'span', { 'class': 'nm-resort-conf is-' + r.confidence }, r.confidence ) );
		tr.appendChild( el( 'td', { 'class': 'nm-resort-note' }, r.note ) );
		var ok = el( 'button', { type: 'button', 'class': 'button nm-resort-ok', 'data-approve': '1', title: 'approved' === r.status ? 'Approved' : 'Approve this row' }, 'approved' === r.status ? '✓ Approved' : '✓' );
		ok.disabled = locked();
		tr.appendChild( el( 'td' ) ).appendChild( ok );
		return tr;
	}
	function replaceRow( saved ) {
		for ( var i = 0; i < data.rows.length; i++ ) {
			if ( data.rows[ i ].id === saved.id ) {
				var was = data.rows[ i ];
				data.rows[ i ] = saved;
				recount( was, saved );
				break;
			}
		}
		var tr = root.querySelector( 'tr[data-id="' + saved.id + '"]' );
		if ( tr ) {
			tr.replaceWith( rowNode( saved ) );
		}
		render.tabsOnly();
	}
	function recount( was, now ) {
		var s = data.summary[ now.kind ];
		if ( ! s ) {
			return;
		}
		s.review += ( now.review ? 1 : 0 ) - ( was.review ? 1 : 0 );
		s.approved += ( 'approved' === now.status ? 1 : 0 ) - ( 'approved' === was.status ? 1 : 0 );
	}
	render.tabsOnly = function () {
		root.querySelectorAll( '[data-nm-tabs] button' ).forEach( function ( b ) {
			var s = data.summary[ b.getAttribute( 'data-kind' ) ] || { rows: 0, review: 0 };
			b.querySelector( 'span' ).textContent = s.rows ? '(' + s.rows + ( s.review ? ', ' + s.review + ' to look at' : '' ) + ')' : '(0)';
		} );
	};
	function save( id, change ) {
		change.id = id;
		return api( 'row', change ).then( function ( row ) {
			row.id = +row.id;
			replaceRow( row );
			hint( 'Saved.' );
		} ).catch( function ( e ) {
			hint( e.message, true );
		} );
	}

	root.addEventListener( 'change', function ( e ) {
		var field = e.target.getAttribute( 'data-field' );
		var tr = e.target.closest( 'tr[data-id]' );
		if ( field && tr ) {
			var change = {};
			change[ field ] = e.target.value;
			save( +tr.getAttribute( 'data-id' ), change );
		}
	} );
	root.addEventListener( 'click', function ( e ) {
		var t = e.target;
		if ( t.closest( '[data-nm-tabs] button' ) ) {
			view.kind = t.closest( 'button' ).getAttribute( 'data-kind' );
			view.page = 0;
			render();
		} else if ( t.hasAttribute( 'data-page' ) ) {
			view.page = +t.getAttribute( 'data-page' );
			render();
		} else if ( t.hasAttribute( 'data-approve' ) ) {
			var tr = t.closest( 'tr[data-id]' );
			var row = data.rows.filter( function ( r ) { return r.id === +tr.getAttribute( 'data-id' ); } )[ 0 ];
			save( row.id, { status: 'approved' === row.status ? 'proposed' : 'approved' } );
		}
	} );
	$( '[data-nm-search]' ).addEventListener( 'input', function ( e ) {
		view.search = e.target.value;
		view.page = 0;
		render();
	} );
	$( '[data-nm-review]' ).addEventListener( 'change', function ( e ) {
		view.review = e.target.checked;
		view.page = 0;
		render();
	} );
	$( '[data-nm-approve-shown]' ).addEventListener( 'click', function () {
		var ids = shown().slice( view.page * PER, view.page * PER + PER ).map( function ( r ) { return r.id; } );
		if ( ! ids.length || ! window.confirm( 'Approve the ' + ids.length + ' rows on this page as they are?' ) ) {
			return;
		}
		api( 'approve', { ids: ids } ).then( function ( j ) {
			hint( j.approved + ' approved' + ( j.skipped ? ', ' + j.skipped + ' need a place first' : '' ) + '.' );
			return load();
		} ).catch( function ( e ) { hint( e.message, true ); } );
	} );

	/* ---------- spreadsheet ---------- */

	$( '[data-nm-download]' ).setAttribute( 'href', cfg.csv );
	$( '[data-nm-upload]' ).addEventListener( 'change', function ( e ) {
		var file = e.target.files[ 0 ];
		if ( ! file ) {
			return;
		}
		var form = new FormData();
		form.append( 'file', file );
		hint( 'Reading the spreadsheet…' );
		api( 'upload', form, true ).then( function ( j ) {
			hint( j.changed + ' rows changed, ' + j.same + ' the same.' + ( j.problems.length ? ' Not read: ' + j.problems.join( '; ' ) : '' ), j.problems.length > 0 );
			e.target.value = '';
			return load();
		} ).catch( function ( err ) { hint( err.message, true ); e.target.value = ''; } );
	} );
	$( '[data-nm-regenerate]' ).addEventListener( 'click', function () {
		var fresh = window.confirm( 'Start the plan again from the computer’s suggestions?\n\nOK: every row goes back to the suggestion (your changes are lost).\nCancel: keep your changes and only add new series, topics and shiurim.' );
		hint( 'Making the plan…' );
		api( 'generate', { fresh: fresh } ).then( function () { return load(); } ).then( function () { hint( fresh ? 'The plan was made again.' : 'New rows were added; your changes were kept.' ); } ).catch( function ( e ) { hint( e.message, true ); } );
	} );

	/* ---------- settings ---------- */

	var sectionsFrom = [];
	function fillSettings() {
		root.querySelectorAll( '[data-nm-setting]' ).forEach( function ( input ) {
			var key = input.getAttribute( 'data-nm-setting' );
			if ( 'checkbox' === input.type ) {
				input.checked = !! data.settings[ key ];
			} else {
				input.value = data.settings[ key ];
			}
			input.disabled = locked();
		} );
		sectionsFrom = data.settings.sections.slice();
		drawSections( data.settings.sections.map( function ( s ) { return { from: s, to: s }; } ) );
	}
	function drawSections( list ) {
		var ol = $( '[data-nm-sections]' );
		ol.innerHTML = '';
		list.forEach( function ( s, i ) {
			var li = el( 'li', { 'data-from': s.from } );
			var input = el( 'input', { type: 'text', value: s.to, 'aria-label': 'Section name' } );
			input.disabled = locked();
			li.appendChild( input );
			[ [ '↑', -1 ], [ '↓', 1 ] ].forEach( function ( m ) {
				var b = el( 'button', { type: 'button', 'class': 'button-link', 'data-move': m[ 1 ], 'aria-label': m[ 1 ] < 0 ? 'Move up' : 'Move down' }, m[ 0 ] );
				b.disabled = locked() || ( m[ 1 ] < 0 ? 0 === i : i === list.length - 1 );
				li.appendChild( b );
			} );
			var x = el( 'button', { type: 'button', 'class': 'button-link', 'data-remove': '1', 'aria-label': 'Remove' }, '✕' );
			x.disabled = locked();
			li.appendChild( x );
			ol.appendChild( li );
		} );
	}
	function readSections() {
		return Array.prototype.map.call( root.querySelectorAll( '[data-nm-sections] li' ), function ( li ) {
			return { from: li.getAttribute( 'data-from' ) || '', to: li.querySelector( 'input' ).value.trim() };
		} );
	}
	$( '[data-nm-sections]' ).addEventListener( 'click', function ( e ) {
		var list = readSections();
		var li = e.target.closest( 'li' );
		if ( ! li ) {
			return;
		}
		var i = Array.prototype.indexOf.call( li.parentNode.children, li );
		if ( e.target.hasAttribute( 'data-move' ) ) {
			var j = i + +e.target.getAttribute( 'data-move' );
			list.splice( j, 0, list.splice( i, 1 )[ 0 ] );
			drawSections( list );
		} else if ( e.target.hasAttribute( 'data-remove' ) ) {
			list.splice( i, 1 );
			drawSections( list );
		}
	} );
	$( '[data-nm-add-section]' ).addEventListener( 'click', function () {
		var list = readSections();
		list.push( { from: '', to: '' } );
		drawSections( list );
		root.querySelector( '[data-nm-sections] li:last-child input' ).focus();
	} );
	$( '[data-nm-save-sections]' ).addEventListener( 'click', function () {
		api( 'settings', { sections: readSections().filter( function ( s ) { return s.to; } ) } ).then( function ( s ) {
			hint( 'Sections saved.' );
			return load();
		} ).catch( function ( e ) { hint( e.message, true ); } );
	} );
	root.querySelectorAll( '[data-nm-setting]' ).forEach( function ( input ) {
		input.addEventListener( 'change', function () {
			var body = {};
			body[ input.getAttribute( 'data-nm-setting' ) ] = 'checkbox' === input.type ? input.checked : +input.value;
			api( 'settings', body ).then( function ( s ) {
				data.settings = s;
				hint( 'Setting saved. Press Preview to see what it changes.' );
			} ).catch( function ( e ) { hint( e.message, true ); } );
		} );
	} );

	/* ---------- preview, apply, undo ---------- */

	function showState() {
		var st = data.state;
		var box = $( '[data-nm-state]' );
		var msg = '';
		if ( 'applied' === st.phase ) {
			msg = 'The plan is applied (' + new Date( st.applied_at * 1000 ).toLocaleString() + '). ' + ( st.message || '' ) + ' To change the plan, press Undo first.';
		} else if ( 'applying' === st.phase || 'undoing' === st.phase ) {
			msg = ( 'applying' === st.phase ? 'Applying' : 'Undoing' ) + '… ' + st.done + ' of ' + st.total + ' shiurim looked at.';
		} else if ( st.message ) {
			msg = st.message;
		}
		box.hidden = ! msg;
		box.textContent = msg;
		box.className = 'nm-resort-state is-' + st.phase;
		$( '[data-nm-apply]' ).disabled = 'idle' !== st.phase;
		$( '[data-nm-undo]' ).disabled = 'applied' !== st.phase;
		var bar = $( '[data-nm-progress]' );
		bar.hidden = ! ( 'applying' === st.phase || 'undoing' === st.phase );
		bar.firstChild.style.width = st.total ? Math.round( 100 * st.done / st.total ) + '%' : '5%';
	}
	function poll() {
		api( 'status', {} ).then( function ( st ) {
			data.state = st;
			showState();
			if ( 'applying' === st.phase || 'undoing' === st.phase ) {
				window.setTimeout( poll, 1500 );
			} else {
				load();
			}
		} ).catch( function () { window.setTimeout( poll, 5000 ); } );
	}
	$( '[data-nm-preview]' ).addEventListener( 'click', function () {
		var out = $( '[data-nm-preview-out]' );
		out.textContent = 'Counting…';
		api( 'preview', {} ).then( function ( p ) {
			out.innerHTML = '';
			var ul = el( 'ul' );
			[
				p.moved + ' of ' + p.shiurim + ' shiurim change series',
				p.topics_changed + ' shiurim change topics',
				p.speakers + ' shiurim get a speaker',
				p.series_new + ' new series are made',
				p.series_gone + ' old series go (nothing left in them)',
				'Topics: ' + p.topic_actions.rename + ' renamed, ' + p.topic_actions.merge + ' merged, ' + p.topic_actions.drop + ' dropped',
				p.no_series ? p.no_series + ' shiurim end up with no series (their series is set to Delete)' : ''
			].filter( Boolean ).forEach( function ( t ) { ul.appendChild( el( 'li', {}, t ) ); } );
			out.appendChild( ul );
			var sec = el( 'p' );
			sec.textContent = 'Shiurim per section: ' + Object.keys( p.sections ).map( function ( k ) { return k + ' ' + p.sections[ k ]; } ).join( ', ' );
			out.appendChild( sec );
			if ( p.series_new_list.length ) {
				var d = el( 'details' );
				d.appendChild( el( 'summary', {}, 'The new series' ) );
				var l = el( 'ul', { 'class': 'nm-resort-newlist' } );
				p.series_new_list.forEach( function ( s ) { l.appendChild( el( 'li', {}, s ) ); } );
				d.appendChild( l );
				out.appendChild( d );
			}
		} ).catch( function ( e ) { out.textContent = e.message; } );
	} );
	$( '[data-nm-apply]' ).addEventListener( 'click', function () {
		var left = Object.keys( data.summary ).reduce( function ( n, k ) { return n + data.summary[ k ].review; }, 0 );
		var ask = 'Apply the plan to the site now?' + ( left ? '\n\n' + left + ' rows were not looked at yet; they go as the computer suggested.' : '' ) + '\n\nA full copy is kept first, and Undo puts everything back.';
		if ( ! window.confirm( ask ) ) {
			return;
		}
		api( 'apply', { confirm: 'yes' } ).then( function ( st ) {
			data.state = st;
			showState();
			poll();
		} ).catch( function ( e ) { hint( e.message, true ); } );
	} );
	$( '[data-nm-undo]' ).addEventListener( 'click', function () {
		if ( ! window.confirm( 'Undo the re-sort and put every series, topic and speaker back as it was before Apply?' ) ) {
			return;
		}
		api( 'undo', { confirm: 'yes' } ).then( function ( st ) {
			data.state = st;
			showState();
			poll();
		} ).catch( function ( e ) { hint( e.message, true ); } );
	} );

	load().then( function () {
		if ( 'applying' === data.state.phase || 'undoing' === data.state.phase ) {
			poll();
		}
	} );
}() );
