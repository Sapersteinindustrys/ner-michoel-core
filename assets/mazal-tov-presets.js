/**
 * Mazal Tov presets: the "fill in the blanks" picker used when posting a Mazal
 * Tov, and the little preview the preset form shows while a wording is typed.
 * (Presets themselves are stored by includes/mazal-tov-presets.php.)
 *
 * A wording such as "Mazel Tov to the ___ family on the birth of a baby ___" has
 * blanks: a run of two or more underscores, or a label in curly brackets, like
 * {Family name}. The picker puts a box in each blank, in the middle of the
 * sentence, and writes the finished sentence into the headline field as the
 * boxes are filled. The headline field is locked while a preset is in use (so
 * what you see is what is posted) and unlocked again when you go back to
 * writing it yourself.
 *
 * Used by the Quick Mazal Tov screen (it mounts itself on [data-nm-mt-fill]) and
 * by the panel's Add New Mazal Tov and preset forms (assets/custom-admin-cms.js).
 * No jQuery: the same code runs on the wp-admin version of the Quick screen.
 */
( function () {
	'use strict';

	var BLANK_RE = /\{([^{}]{0,60})\}|_{2,}/g;
	var UNFILLED = '___';
	var GENERIC_PLACEHOLDER = 'fill in';
	var MAX_FILL = 120;

	/* ---------------- A wording, as parts (no page needed) ---------------- */

	function clean( value ) {
		return String( value === null || value === undefined ? '' : value ).replace( /\s+/g, ' ' ).trim();
	}

	// "Mazel Tov to the ___ family" -> text, a blank, text. A blank has the
	// label that was written in curly brackets ('' when it is only underscores)
	// and its place in the sentence, n (from 0).
	function parse( wording ) {
		var text = String( wording === null || wording === undefined ? '' : wording );
		var parts = [];
		var last = 0;
		var count = 0;
		var match;
		BLANK_RE.lastIndex = 0;
		while ( ( match = BLANK_RE.exec( text ) ) !== null ) {
			if ( match.index > last ) {
				parts.push( { text: text.slice( last, match.index ) } );
			}
			parts.push( { blank: true, label: match[ 1 ] === undefined ? '' : clean( match[ 1 ] ), n: count } );
			count++;
			last = match.index + match[ 0 ].length;
		}
		if ( last < text.length ) {
			parts.push( { text: text.slice( last ) } );
		}
		return { parts: parts, count: count };
	}

	// The sentence with the blanks filled from values[n]; a blank with nothing
	// in it stays as ___, which the headline check then refuses to post.
	function compose( parsed, values ) {
		var out = '';
		values = values || [];
		parsed.parts.forEach( function ( part ) {
			if ( part.blank ) {
				var value = clean( values[ part.n ] );
				out += value ? value : UNFILLED;
			} else {
				out += part.text;
			}
		} );
		return clean( out );
	}

	function isComplete( parsed, values ) {
		values = values || [];
		for ( var n = 0; n < parsed.count; n++ ) {
			if ( ! clean( values[ n ] ) ) {
				return false;
			}
		}
		return true;
	}

	/* ---------------- Small DOM helpers ---------------- */

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

	// A box wide enough for what is in it (or its label), never wider than the line.
	function sizeInput( input ) {
		var chars = Math.max( input.value.length, input.placeholder.length, 8 );
		input.style.width = ( chars + 2 ) + 'ch';
	}

	// The sentence, with a box in each blank. preview: the same look, not typeable.
	function renderSentence( container, parsed, o ) {
		o = o || {};
		var inputs = [];
		container.textContent = '';
		parsed.parts.forEach( function ( part ) {
			if ( ! part.blank ) {
				container.appendChild( document.createTextNode( part.text ) );
				return;
			}
			var input = el( 'input', {
				type: 'text',
				'class': 'nm-mt-blank',
				placeholder: part.label || GENERIC_PLACEHOLDER,
				'aria-label': part.label || 'Blank ' + ( part.n + 1 ) + ' of ' + parsed.count,
				maxlength: MAX_FILL,
				autocomplete: 'off',
				spellcheck: 'false',
				dir: 'auto',
				disabled: !! o.preview
			} );
			if ( o.values && o.values[ part.n ] ) {
				input.value = o.values[ part.n ];
			}
			sizeInput( input );
			if ( ! o.preview ) {
				input.addEventListener( 'input', function () {
					sizeInput( input );
					if ( o.onInput ) {
						o.onInput( part.n, input.value, input );
					}
				} );
			}
			inputs[ part.n ] = input;
			container.appendChild( input );
		} );
		return inputs;
	}

	/* ---------------- The picker ---------------- */

	/**
	 * host      the element to build the picker in
	 * opts      presets: [{id, name, wording, type_id}], headline: the headline
	 *           <input>, type: the Type <select> (or a function that returns it),
	 *           typesReady: a promise for when the Type menu is filled in,
	 *           manageUrl: where presets are made, selectId: id for the menu
	 * returns   { isActive, isComplete, showError, reset }
	 */
	function mountPicker( host, opts ) {
		opts = opts || {};
		var headline = opts.headline || null;
		var presets = ( opts.presets || [] ).filter( function ( p ) {
			return p && clean( p.wording );
		} );
		var state = { preset: null, parsed: null, values: [], inputs: [], before: null };
		var note = null;

		var api = {
			element: host,
			isActive: function () {
				return !! state.preset;
			},
			isComplete: function () {
				return ! state.preset || isComplete( state.parsed, state.values );
			},
			showError: function () {},
			reset: function () {}
		};

		host.textContent = '';
		host.classList.add( 'nm-mt-pick' );

		if ( ! presets.length ) {
			var none = el( 'p', { 'class': 'nm-mt-none' }, [ 'No presets yet. ' ] );
			if ( opts.manageUrl ) {
				none.appendChild( el( 'a', { href: opts.manageUrl, target: '_blank', rel: 'noopener', text: 'Make one' } ) );
				none.appendChild( document.createTextNode( ' to save time: a sentence with blanks, like “Mazel Tov to the ___ family on the birth of a baby ___”.' ) );
			}
			host.appendChild( none );
			return api;
		}

		var select = el( 'select', { 'class': 'nm-mt-select' + ( opts.selectClass ? ' ' + opts.selectClass : '' ), id: opts.selectId || null, 'aria-label': 'Preset' } );
		select.appendChild( el( 'option', { value: '', text: 'None — I’ll write it myself' } ) );
		presets.forEach( function ( p ) {
			select.appendChild( el( 'option', { value: String( p.id ), text: p.name } ) );
		} );

		var sentence = el( 'div', { 'class': 'nm-mt-sentence', dir: 'auto', role: 'group', 'aria-label': 'Fill in the blanks' } );
		var error = el( 'p', { 'class': 'nm-mt-error', role: 'alert', hidden: true } );
		var box = el( 'div', { 'class': 'nm-mt-box', hidden: true }, [
			el( 'p', { 'class': 'nm-mt-caption', text: 'Fill in the blanks' } ),
			sentence,
			error
		] );
		host.appendChild( select );
		host.appendChild( box );
		if ( opts.manageUrl ) {
			host.appendChild( el( 'p', { 'class': 'nm-mt-manage' }, [ el( 'a', { href: opts.manageUrl, target: '_blank', rel: 'noopener', text: 'Add or edit presets' } ) ] ) );
		}

		function typeSelect() {
			return typeof opts.type === 'function' ? opts.type() : ( opts.type || null );
		}

		function lock() {
			if ( ! headline ) {
				return;
			}
			if ( state.before === null ) {
				state.before = headline.value;
			}
			headline.readOnly = true;
			headline.setAttribute( 'dir', 'auto' );
			headline.classList.add( 'nm-mt-made' );
			if ( ! note ) {
				note = el( 'p', { 'class': 'nm-mt-made-note', text: 'Written for you from the blanks. To type it yourself, choose “None” in Preset.' } );
				headline.parentNode.insertBefore( note, headline.nextSibling );
			}
		}

		function unlock() {
			if ( ! headline ) {
				return;
			}
			headline.readOnly = false;
			headline.classList.remove( 'nm-mt-made' );
			if ( note && note.parentNode ) {
				note.parentNode.removeChild( note );
			}
			note = null;
		}

		function writeHeadline() {
			if ( ! headline ) {
				return;
			}
			headline.value = compose( state.parsed, state.values );
			fire( headline, 'input' );
		}

		function clearError() {
			error.hidden = true;
			error.textContent = '';
			box.classList.remove( 'has-error' );
			state.inputs.forEach( function ( input ) {
				if ( input && clean( input.value ) ) {
					input.removeAttribute( 'aria-invalid' );
				}
			} );
		}

		function applyType( preset ) {
			var menu = typeSelect();
			if ( ! menu || ! preset.type_id ) {
				return;
			}
			var wanted = String( preset.type_id );
			function apply() {
				var has = Array.prototype.some.call( menu.options, function ( option ) {
					return option.value === wanted;
				} );
				if ( has ) {
					menu.value = wanted;
					fire( menu, 'change' );
				}
				return has;
			}
			if ( ! apply() && opts.typesReady && opts.typesReady.then ) {
				opts.typesReady.then( apply );
			}
		}

		function choose( id, fromUser ) {
			var preset = null;
			presets.forEach( function ( p ) {
				if ( String( p.id ) === String( id ) ) {
					preset = p;
				}
			} );
			var wasComplete = api.isComplete();
			state.preset = preset;
			clearError();

			if ( ! preset ) {
				// Back to typing it: keep a finished sentence to edit, but don't leave a half-filled one.
				if ( headline && state.before !== null && ! wasComplete ) {
					headline.value = state.before;
					fire( headline, 'input' );
				}
				state.before = null;
				state.parsed = null;
				state.values = [];
				state.inputs = [];
				box.hidden = true;
				sentence.textContent = '';
				unlock();
				return;
			}

			state.parsed = parse( preset.wording );
			state.values = [];
			state.inputs = renderSentence( sentence, state.parsed, {
				values: state.values,
				onInput: function ( n, value ) {
					state.values[ n ] = value;
					writeHeadline();
					clearError();
				}
			} );
			box.hidden = false;
			lock();
			writeHeadline();
			applyType( preset );
			if ( fromUser && state.inputs[ 0 ] ) {
				state.inputs[ 0 ].focus();
			}
		}

		select.addEventListener( 'change', function () {
			choose( select.value, true );
		} );

		api.showError = function () {
			var first = null;
			state.inputs.forEach( function ( input ) {
				if ( input && ! clean( input.value ) ) {
					input.setAttribute( 'aria-invalid', 'true' );
					first = first || input;
				}
			} );
			error.textContent = 'Please fill in every blank first.';
			error.hidden = false;
			box.classList.add( 'has-error' );
			if ( first ) {
				first.focus();
			}
		};
		api.reset = function () {
			select.value = '';
			choose( '', false );
		};
		return api;
	}

	/* ---------------- The preset form's wording box ---------------- */

	// "Add a blank" puts ___ where the cursor is (in place of any selected text),
	// with a space on a side that needs one.
	function insertBlank( textarea ) {
		var value = textarea.value;
		var start = typeof textarea.selectionStart === 'number' ? textarea.selectionStart : value.length;
		var end = typeof textarea.selectionEnd === 'number' ? textarea.selectionEnd : start;
		var before = value.slice( 0, start );
		var after = value.slice( end );
		var lead = before && ! /[\s(\[“"'‘—-]$/.test( before ) ? ' ' : '';
		var trail = after && ! /^[\s.,;:!?)\]”"'’]/.test( after ) ? ' ' : '';
		textarea.value = before + lead + UNFILLED + trail + after;
		var caret = before.length + lead.length + UNFILLED.length;
		textarea.focus();
		if ( textarea.setSelectionRange ) {
			textarea.setSelectionRange( caret, caret );
		}
		fire( textarea, 'input' );
	}

	// root: .nm-mt-wording, holding the textarea, the "Add a blank" button and the
	// preview's body. Shows the sentence the way the picker will, boxes not typeable.
	function mountWordingEditor( root ) {
		var textarea = root.querySelector( 'textarea' );
		var preview = root.querySelector( '.nm-mt-wording__preview-body' );
		var add = root.querySelector( '.nm-mt-add-blank' );
		if ( ! textarea || ! preview ) {
			return;
		}

		function refresh() {
			var parsed = parse( textarea.value );
			preview.textContent = '';
			if ( ! clean( textarea.value ) ) {
				preview.appendChild( el( 'p', { 'class': 'nm-mt-muted', text: 'Type the wording above and it shows here.' } ) );
				return;
			}
			var sentence = el( 'div', { 'class': 'nm-mt-sentence nm-mt-sentence--preview', dir: 'auto', 'aria-hidden': 'true' } );
			renderSentence( sentence, parsed, { preview: true } );
			preview.appendChild( sentence );
			preview.appendChild( el( 'p', {
				'class': 'nm-mt-muted',
				text: parsed.count
					? parsed.count + ( parsed.count === 1 ? ' blank' : ' blanks' ) + ' to fill in when you post.'
					: 'No blanks yet, so this will be posted exactly as written. Add ___ where a name goes.'
			} ) );
		}

		textarea.addEventListener( 'input', refresh );
		if ( add ) {
			add.addEventListener( 'click', function () {
				insertBlank( textarea );
			} );
		}
		refresh();
	}

	/* ---------------- Screens that are not drawn by the panel's script ---------------- */

	// Quick Mazal Tov: <div data-nm-mt-fill data-presets='[...]' data-headline="#id" data-type="#id">
	function autoMount() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-nm-mt-fill]' ), function ( host ) {
			var presets = [];
			try {
				presets = JSON.parse( host.getAttribute( 'data-presets' ) || '[]' );
			} catch ( e ) {
				presets = [];
			}
			var headlineSel = host.getAttribute( 'data-headline' );
			var typeSel = host.getAttribute( 'data-type' );
			var controller = mountPicker( host, {
				presets: presets,
				headline: headlineSel ? document.querySelector( headlineSel ) : null,
				type: typeSel ? document.querySelector( typeSel ) : null,
				manageUrl: host.getAttribute( 'data-manage-url' ) || '',
				selectId: host.getAttribute( 'data-select-id' ) || ''
			} );
			var form = host.closest( 'form' );
			if ( form ) {
				form.addEventListener( 'submit', function ( event ) {
					if ( controller.isActive() && ! controller.isComplete() ) {
						event.preventDefault();
						controller.showError();
					}
				} );
			}
		} );
	}

	var api = {
		UNFILLED: UNFILLED,
		parse: parse,
		compose: compose,
		isComplete: isComplete,
		mountPicker: mountPicker,
		mountWordingEditor: mountWordingEditor
	};

	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = api;
	}
	if ( typeof window !== 'undefined' && typeof document !== 'undefined' ) {
		window.NMMazalTov = api;
		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', autoMount );
		} else {
			autoMount();
		}
	}
}() );
