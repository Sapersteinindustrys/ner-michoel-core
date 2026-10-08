/**
 * The browser check for the public forms (includes/form-guard.php).
 *
 * Each form with a [data-nm-guard] block gets a signed challenge from the
 * server and solves it here: it finds the number whose SHA-256, with the
 * challenge, starts with enough zero bits. That starts as soon as the form is
 * on screen (or first touched, for a form in a hidden tab) and runs on a
 * background thread, so typing never stutters. Sending waits for it, and for Cloudflare
 * Turnstile when that's on, with a "Checking…" line under the form meanwhile.
 *
 * Forms sent by a script (the Account page's account.js) add
 * window.nmFormGuard.fields( form ) to what they send, and call
 * window.nmFormGuard.reset( form ) after each try: every check works once.
 */
( function () {
	'use strict';

	var cfg = window.nmFormGuardSettings || {};

	/* ---- SHA-256 ---- */

	/**
	 * The hashing, as one self-contained function. The page calls it, and its
	 * source also becomes the background worker below, so the two can't drift
	 * apart. Returns { hasher, zeroBits, search }.
	 */
	function sha256Kit() {
		var K = new Uint32Array( [
			0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
			0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
			0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
			0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
			0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
			0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
			0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
			0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2
		] );
		var IV = [ 0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a, 0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19 ];
		var W = new Uint32Array( 64 );

		/** One 64-byte block of `bytes`, from `offset`, into the state `H`. */
		function compress( H, bytes, offset ) {
			var i, t1, t2, x, y;
			for ( i = 0; i < 16; i++ ) {
				x = offset + i * 4;
				W[ i ] = ( bytes[ x ] << 24 ) | ( bytes[ x + 1 ] << 16 ) | ( bytes[ x + 2 ] << 8 ) | bytes[ x + 3 ];
			}
			for ( i = 16; i < 64; i++ ) {
				x = W[ i - 15 ];
				y = W[ i - 2 ];
				W[ i ] = W[ i - 16 ] + ( ( ( x >>> 7 ) | ( x << 25 ) ) ^ ( ( x >>> 18 ) | ( x << 14 ) ) ^ ( x >>> 3 ) ) +
					W[ i - 7 ] + ( ( ( y >>> 17 ) | ( y << 15 ) ) ^ ( ( y >>> 19 ) | ( y << 13 ) ) ^ ( y >>> 10 ) );
			}
			var a = H[ 0 ], b = H[ 1 ], c = H[ 2 ], d = H[ 3 ], e = H[ 4 ], f = H[ 5 ], g = H[ 6 ], h = H[ 7 ];
			for ( i = 0; i < 64; i++ ) {
				t1 = ( h + ( ( ( e >>> 6 ) | ( e << 26 ) ) ^ ( ( e >>> 11 ) | ( e << 21 ) ) ^ ( ( e >>> 25 ) | ( e << 7 ) ) ) +
					( ( e & f ) ^ ( ~e & g ) ) + K[ i ] + W[ i ] ) | 0;
				t2 = ( ( ( ( a >>> 2 ) | ( a << 30 ) ) ^ ( ( a >>> 13 ) | ( a << 19 ) ) ^ ( ( a >>> 22 ) | ( a << 10 ) ) ) +
					( ( a & b ) ^ ( a & c ) ^ ( b & c ) ) ) | 0;
				h = g;
				g = f;
				f = e;
				e = ( d + t1 ) | 0;
				d = c;
				c = b;
				b = a;
				a = ( t1 + t2 ) | 0;
			}
			H[ 0 ] += a;
			H[ 1 ] += b;
			H[ 2 ] += c;
			H[ 3 ] += d;
			H[ 4 ] += e;
			H[ 5 ] += f;
			H[ 6 ] += g;
			H[ 7 ] += h;
		}

		function ascii( text ) {
			var out = new Uint8Array( text.length );
			for ( var i = 0; i < text.length; i++ ) {
				out[ i ] = text.charCodeAt( i ) & 0xff;
			}
			return out;
		}

		/**
		 * A hasher for `prefix` + a short ASCII suffix. The prefix's whole blocks
		 * are hashed once here, so each try only hashes the last block or two.
		 */
		function hasher( prefix ) {
			var bytes = ascii( prefix );
			var whole = bytes.length - ( bytes.length % 64 );
			var mid = new Uint32Array( IV );
			for ( var o = 0; o < whole; o += 64 ) {
				compress( mid, bytes, o );
			}
			var rest = bytes.subarray( whole );
			var buf = new Uint8Array( 128 );
			var H = new Uint32Array( 8 );

			return function ( suffix ) {
				var tail = rest.length + suffix.length;
				var size = tail + 9 <= 64 ? 64 : 128;
				var bits = ( bytes.length + suffix.length ) * 8;
				buf.fill( 0, 0, size );
				buf.set( rest, 0 );
				for ( var i = 0; i < suffix.length; i++ ) {
					buf[ rest.length + i ] = suffix.charCodeAt( i ) & 0xff;
				}
				buf[ tail ] = 0x80;
				buf[ size - 4 ] = ( bits >>> 24 ) & 0xff;
				buf[ size - 3 ] = ( bits >>> 16 ) & 0xff;
				buf[ size - 2 ] = ( bits >>> 8 ) & 0xff;
				buf[ size - 1 ] = bits & 0xff;
				H.set( mid );
				compress( H, buf, 0 );
				if ( 128 === size ) {
					compress( H, buf, 64 );
				}
				return H;
			};
		}

		function zeroBits( H ) {
			for ( var i = 0, n = 0; i < 8; i++ ) {
				if ( 0 !== H[ i ] ) {
					return n + Math.clz32( H[ i ] );
				}
				n += 32;
			}
			return n;
		}

		/** The first n from `from` (trying `count` of them) whose hash has `bits` zero bits, or -1. */
		function search( hash, bits, from, count ) {
			for ( var n = from, end = from + count; n < end; n++ ) {
				if ( zeroBits( hash( String( n ) ) ) >= bits ) {
					return n;
				}
			}
			return -1;
		}

		return { hasher: hasher, zeroBits: zeroBits, search: search };
	}

	var kit = sha256Kit();
	var workerUrl = null;

	/**
	 * Finds the answer, then calls done( answer ). On a background thread (a
	 * Web Worker made from sha256Kit), so it runs at full speed and never slows
	 * typing; on the page, in short slices, if a worker can't start.
	 */
	function solve( token, bits, done ) {
		var finished = false;
		var finish = function ( answer ) {
			if ( ! finished ) {
				finished = true;
				done( answer );
			}
		};
		var fallback = function () {
			solveHere( token, bits, finish );
		};
		try {
			if ( ! workerUrl ) {
				workerUrl = URL.createObjectURL( new Blob( [
					'var kit = (' + sha256Kit.toString() + ')();\n' +
					'onmessage = function ( e ) { postMessage( String( kit.search( kit.hasher( e.data.token + ":" ), e.data.bits, 0, Infinity ) ) ); };'
				], { type: 'text/javascript' } ) );
			}
			var worker = new Worker( workerUrl );
			worker.onmessage = function ( e ) {
				worker.terminate();
				finish( e.data );
			};
			worker.onerror = function () {
				worker.terminate();
				fallback();
			};
			worker.postMessage( { token: token, bits: bits } );
		} catch ( err ) {
			fallback();
		}
	}

	/** The same on the page: slices of about 12ms, yielding between them. */
	function solveHere( token, bits, done ) {
		var hash = kit.hasher( token + ':' );
		var n = 0;
		// A message channel yields without setTimeout's minimum delay.
		var channel = 'function' === typeof MessageChannel ? new MessageChannel() : null;
		if ( channel ) {
			channel.port1.onmessage = slice;
		}
		function slice() {
			var until = Date.now() + 12;
			while ( Date.now() < until ) {
				var found = kit.search( hash, bits, n, 512 );
				if ( -1 !== found ) {
					done( String( found ) );
					return;
				}
				n += 512;
			}
			if ( channel ) {
				channel.port2.postMessage( 0 );
			} else {
				setTimeout( slice, 0 );
			}
		}
		slice();
	}

	/* ---- The forms ---- */

	var waitingForTurnstile = [];

	function setup( box ) {
		var form = box.closest ? box.closest( 'form' ) : null;
		if ( ! form || form.nmGuard ) {
			return;
		}
		var state = {
			form: form,
			box: box,
			name: box.getAttribute( 'data-nm-guard' ),
			token: box.querySelector( 'input[name="nm_fg_token"]' ),
			answer: box.querySelector( 'input[name="nm_fg_answer"]' ),
			status: box.querySelector( '.nm-guard__status' ),
			slot: box.querySelector( '.nm-guard__turnstile' ),
			widget: null,
			pass: '',
			work: null,
			readyAt: 0,
			expiresAt: 0,
			busy: false,
			listeners: []
		};
		form.nmGuard = state;

		if ( isShown( form ) ) {
			start( state );
			return;
		}
		// A form in a hidden tab starts when it's first used.
		var first = function () {
			form.removeEventListener( 'focusin', first );
			form.removeEventListener( 'pointerdown', first );
			start( state );
		};
		form.addEventListener( 'focusin', first );
		form.addEventListener( 'pointerdown', first );
	}

	function isShown( el ) {
		return !! ( el.offsetWidth || el.offsetHeight || el.getClientRects().length );
	}

	function start( state ) {
		if ( ! state.work || Date.now() > state.expiresAt ) {
			state.work = challenge( state );
		}
		renderTurnstile( state );
		return state.work;
	}

	/** Resolves true once the hidden fields hold a solved challenge, false if it couldn't be fetched. */
	function challenge( state ) {
		state.token.value = '';
		state.answer.value = '';
		state.expiresAt = Date.now() + 60000;
		var url = cfg.challengeUrl + ( -1 === String( cfg.challengeUrl ).indexOf( '?' ) ? '?' : '&' ) + 'form=' + encodeURIComponent( state.name );

		return fetch( url, { credentials: 'omit', cache: 'no-store' } )
			.then( function ( res ) {
				if ( ! res.ok ) {
					throw new Error( 'challenge ' + res.status );
				}
				return res.json();
			} )
			.then( function ( data ) {
				// Timed from when it arrived, which is after the server issued it,
				// so waiting this long always satisfies the server's minimum.
				var received = Date.now();
				state.readyAt = received + data.wait * 1000 + 300;
				state.expiresAt = received + ( data.ttl - 300 ) * 1000;
				return new Promise( function ( resolve ) {
					solve( data.token, data.bits, function ( answer ) {
						state.token.value = data.token;
						state.answer.value = answer;
						resolve( true );
					} );
				} );
			} )
			.catch( function () {
				state.work = null;
				return false;
			} );
	}

	/* ---- Cloudflare Turnstile (when the site has keys) ---- */

	// Called by Turnstile's script once it loads (its URL names this function).
	window.nmFormGuardTurnstile = function () {
		waitingForTurnstile.splice( 0 ).forEach( renderTurnstile );
	};

	var turnstileRequested = false;

	// Cloudflare's script, added the first time a form needs the widget (so
	// pages without a form never fetch it, and a form swapped in by the page
	// router still gets it).
	function loadTurnstile() {
		if ( turnstileRequested || ! cfg.turnstileApi ) {
			return;
		}
		turnstileRequested = true;
		var script = document.createElement( 'script' );
		script.src = cfg.turnstileApi;
		script.async = true;
		script.defer = true;
		document.head.appendChild( script );
	}

	function renderTurnstile( state ) {
		if ( ! state.slot || null !== state.widget ) {
			return;
		}
		if ( ! window.turnstile ) {
			if ( -1 === waitingForTurnstile.indexOf( state ) ) {
				waitingForTurnstile.push( state );
			}
			loadTurnstile();
			return;
		}
		state.widget = window.turnstile.render( state.slot, {
			sitekey: state.slot.getAttribute( 'data-sitekey' ),
			action: state.name,
			appearance: 'interaction-only',
			size: 'flexible',
			callback: function ( token ) {
				state.pass = token;
				notify( state );
			},
			'expired-callback': function () {
				state.pass = '';
			},
			'error-callback': function () {
				state.pass = '';
				notify( state );
			}
		} ) || '';

		// While Cloudflare has nothing to show, the widget is 0px tall but would
		// still take a row (and a gap) in the form. Marked idle then (the theme
		// takes it out of the layout), and back in as soon as it grows.
		if ( 'function' === typeof ResizeObserver ) {
			new ResizeObserver( function () {
				state.slot.classList.toggle( 'is-idle', 0 === state.slot.offsetHeight );
			} ).observe( state.slot );
		}
	}

	function notify( state ) {
		state.listeners.splice( 0 ).forEach( function ( fn ) {
			fn();
		} );
	}

	/** Resolves true once Turnstile has passed (at once when it's off), false after 30s without. */
	function turnstilePassed( state ) {
		if ( ! state.slot || state.pass ) {
			return Promise.resolve( true );
		}
		renderTurnstile( state );
		return new Promise( function ( resolve ) {
			var timer = setTimeout( function () {
				resolve( !! state.pass );
			}, 30000 );
			state.listeners.push( function () {
				clearTimeout( timer );
				resolve( !! state.pass );
			} );
		} );
	}

	/**
	 * Turnstile fills its own hidden field a moment after it hands the token
	 * over, so a form sent straight away could go without it. Write it in
	 * before a normal (non-script) send.
	 */
	function fillTurnstileField( state ) {
		if ( ! state.slot || ! state.pass ) {
			return;
		}
		var field = state.form.querySelector( '[name="cf-turnstile-response"]' );
		if ( ! field ) {
			field = document.createElement( 'input' );
			field.type = 'hidden';
			field.name = 'cf-turnstile-response';
			state.box.appendChild( field );
		}
		field.value = state.pass;
	}

	/* ---- Sending ---- */

	function isReady( state ) {
		var now = Date.now();
		return '' !== state.answer.value && now >= state.readyAt && now < state.expiresAt && ( ! state.slot || '' !== state.pass );
	}

	/** 'ok', 'failed' (no challenge) or 'turnstile' (not passed). */
	function whenReady( state ) {
		return start( state ).then( function ( ok ) {
			if ( ! ok ) {
				return 'failed';
			}
			return new Promise( function ( resolve ) {
				setTimeout( resolve, Math.max( 0, state.readyAt - Date.now() ) );
			} ).then( function () {
				return turnstilePassed( state ).then( function ( passed ) {
					return passed ? 'ok' : 'turnstile';
				} );
			} );
		} );
	}

	function say( state, text, isError ) {
		if ( ! state.status ) {
			return;
		}
		state.status.textContent = text || '';
		state.status.hidden = ! text;
		state.status.classList.toggle( 'is-error', !! isError );
	}

	function setBusy( state, busy ) {
		state.busy = busy;
		state.form.setAttribute( 'aria-busy', busy ? 'true' : 'false' );
		state.form.querySelectorAll( 'button[type="submit"], input[type="submit"]' ).forEach( function ( button ) {
			button.disabled = busy;
		} );
	}

	function send( form, submitter ) {
		if ( 'function' === typeof form.requestSubmit ) {
			if ( submitter && submitter.form === form ) {
				form.requestSubmit( submitter );
			} else {
				form.requestSubmit();
			}
			return;
		}
		var event = document.createEvent( 'Event' );
		event.initEvent( 'submit', true, true );
		if ( form.dispatchEvent( event ) ) {
			form.submit();
		}
	}

	// Capture phase on the document, so this runs before the page's own submit
	// handler (account.js) and can hold the send until the check is done.
	document.addEventListener(
		'submit',
		function ( e ) {
			var state = e.target && e.target.nmGuard;
			if ( ! state ) {
				return;
			}
			if ( isReady( state ) ) {
				fillTurnstileField( state );
				return;
			}
			e.preventDefault();
			e.stopImmediatePropagation();
			if ( state.busy ) {
				return;
			}
			var submitter = e.submitter || null;
			setBusy( state, true );
			say( state, cfg.checking );
			whenReady( state ).then( function ( result ) {
				setBusy( state, false );
				if ( 'ok' !== result ) {
					say( state, 'turnstile' === result ? cfg.turnstile : cfg.failed, true );
					return;
				}
				say( state, '' );
				send( state.form, submitter );
			} );
		},
		true
	);

	function reset( form ) {
		var state = form && form.nmGuard;
		if ( ! state ) {
			return;
		}
		state.work = null;
		if ( state.slot && state.widget && window.turnstile ) {
			state.pass = '';
			try {
				window.turnstile.reset( state.widget );
			} catch ( err ) {
				// The widget was removed; the next render makes a new one.
				state.widget = null;
			}
		}
		start( state );
	}

	window.nmFormGuard = {
		/** What a script-sent form adds to its request. */
		fields: function ( form ) {
			var state = form && form.nmGuard;
			if ( ! state ) {
				return {};
			}
			var out = { nm_fg_token: state.token.value, nm_fg_answer: state.answer.value };
			if ( state.slot ) {
				out[ 'cf-turnstile-response' ] = state.pass;
			}
			return out;
		},
		/** A fresh check after a try (each one works once). */
		reset: reset,
		/** SHA-256 of an ASCII string, as hex. For tests. */
		sha256: function ( text ) {
			var H = kit.hasher( text )( '' );
			var hex = '';
			for ( var i = 0; i < 8; i++ ) {
				hex += ( '0000000' + H[ i ].toString( 16 ) ).slice( -8 );
			}
			return hex;
		}
	};

	function init() {
		document.querySelectorAll( '[data-nm-guard]' ).forEach( setup );
	}

	// Back to a page from the browser's cache: its check may already have been
	// used by the send that left it, so get new ones.
	window.addEventListener( 'pageshow', function ( e ) {
		if ( e.persisted ) {
			document.querySelectorAll( 'form' ).forEach( function ( form ) {
				if ( form.nmGuard ) {
					reset( form );
				}
			} );
		}
	} );

	// The theme's page router swaps in a page's content without running its
	// scripts: look for the new page's forms after each swap.
	document.addEventListener( 'nm:content-swapped', init );

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
