/**
 * Beta testing mode (includes/beta-feedback.php): the banner, its hide
 * button, and the "Report a problem" pop-up.
 *
 * The form is sent with fetch as a plain form post. Before it goes, the
 * site's browser check (form-guard.js) holds the send until its answer is
 * ready, then lets it through to the handler here. Each report adds what the
 * page knows on its own: the address and title (read when sending, so it's
 * right after the site's router swaps pages), the device and the last few
 * script errors.
 */
( function () {
	'use strict';

	var cfg = window.nmBetaSettings || {};
	var HIDE_KEY = 'nmBetaHidden';

	function store( key, value ) {
		try {
			if ( undefined === value ) {
				return window.sessionStorage.getItem( key );
			}
			window.sessionStorage.setItem( key, value );
		} catch ( e ) {
			// Private mode or storage blocked: the banner just stays.
		}
		return null;
	}

	function init() {
		var bar   = document.getElementById( 'nm-beta-bar' );
		var modal = document.getElementById( 'nm-beta-modal' );
		var tab   = document.querySelector( '.nm-beta-tab' );
		if ( ! modal ) {
			return;
		}
		var form   = document.getElementById( 'nm-beta-form' );
		var done   = modal.querySelector( '.nm-beta-done' );
		var status = form.querySelector( '.nm-beta-form__status' );
		var pageEl = form.querySelector( '[data-nm-beta-page]' );
		var lastFocus = null;

		// A theme without wp_body_open: the banner came with the footer, so
		// move it to the top of the page.
		if ( bar && bar.parentNode === document.body ) {
			var content = document.getElementById( 'page' ) || document.getElementById( 'content' );
			if ( content && ( content.compareDocumentPosition( bar ) & Node.DOCUMENT_POSITION_FOLLOWING ) ) {
				document.body.insertBefore( bar, document.body.firstChild );
			}
		}

		function showBar( show ) {
			if ( bar ) {
				bar.hidden = ! show;
			}
			if ( tab ) {
				tab.hidden = show;
			}
			document.documentElement.classList.toggle( 'nm-beta-on', !! show );
		}
		showBar( '1' !== store( HIDE_KEY ) );

		function say( text, isError ) {
			status.textContent = text || '';
			status.classList.toggle( 'is-error', !! isError );
		}

		function open() {
			lastFocus = document.activeElement;
			pageEl.textContent = document.title || window.location.pathname;
			form.hidden = false;
			done.hidden = true;
			say( '' );
			modal.hidden = false;
			document.documentElement.classList.add( 'nm-beta-modal-open' );
			var name = form.elements.name;
			( name && ! name.value ? name : form.elements.message ).focus();
		}

		function close() {
			modal.hidden = true;
			document.documentElement.classList.remove( 'nm-beta-modal-open' );
			if ( lastFocus && lastFocus.focus ) {
				lastFocus.focus();
			}
		}

		document.addEventListener( 'click', function ( e ) {
			var t = e.target;
			if ( ! t || ! t.closest ) {
				return;
			}
			if ( t.closest( '[data-nm-beta-open]' ) ) {
				e.preventDefault();
				open();
			} else if ( t.closest( '[data-nm-beta-close]' ) ) {
				e.preventDefault();
				close();
			} else if ( t.closest( '[data-nm-beta-hide]' ) ) {
				e.preventDefault();
				store( HIDE_KEY, '1' );
				showBar( false );
			} else if ( t.closest( '[data-nm-beta-again]' ) ) {
				e.preventDefault();
				open();
			}
		} );

		document.addEventListener( 'keydown', function ( e ) {
			if ( modal.hidden ) {
				return;
			}
			if ( 'Escape' === e.key ) {
				close();
				return;
			}
			if ( 'Tab' === e.key ) {
				// Keep the keyboard inside the pop-up.
				var items = Array.prototype.filter.call(
					modal.querySelectorAll( 'button, input, select, textarea, a[href]' ),
					function ( el ) {
						return ! el.disabled && el.offsetParent !== null && '-1' !== el.getAttribute( 'tabindex' );
					}
				);
				if ( ! items.length ) {
					return;
				}
				var first = items[ 0 ];
				var last  = items[ items.length - 1 ];
				if ( e.shiftKey && document.activeElement === first ) {
					e.preventDefault();
					last.focus();
				} else if ( ! e.shiftKey && document.activeElement === last ) {
					e.preventDefault();
					first.focus();
				}
			}
		} );

		function context() {
			var nav = window.navigator || {};
			var scr = window.screen || {};
			var tz  = '';
			try {
				tz = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
			} catch ( e ) {}
			var platform = ( nav.userAgentData && nav.userAgentData.platform ) || nav.platform || '';
			if ( nav.userAgentData && nav.userAgentData.mobile ) {
				platform += ' (mobile)';
			}
			return {
				page_url: window.location.href,
				page_title: document.title,
				referrer: document.referrer,
				user_agent: nav.userAgent || '',
				platform: platform,
				screen: ( scr.width || '?' ) + 'x' + ( scr.height || '?' ) + ( window.devicePixelRatio && 1 !== window.devicePixelRatio ? ' @' + Math.round( window.devicePixelRatio * 100 ) / 100 + 'x' : '' ),
				viewport: window.innerWidth + 'x' + window.innerHeight,
				touch: ( nav.maxTouchPoints || 0 ) > 0 ? 'yes (' + nav.maxTouchPoints + ' points)' : 'no',
				language: nav.language || '',
				timezone: tz,
				local_time: new Date().toString().replace( / \(.*\)$/, '' ),
				errors: ( window.nmBetaErrors || [] ).join( '\n' )
			};
		}

		// An old "please type your name" shouldn't stay up while they type.
		form.addEventListener( 'input', function () {
			if ( status.classList.contains( 'is-error' ) ) {
				say( '' );
			}
		} );

		/**
		 * The host's bot screen (Bluehost) can answer a first post with a tiny
		 * page that only sets a "humans_..." cookie and reloads. A fetch never
		 * runs that page, so set the cookie here and send once more. The host
		 * stops it before WordPress, so the browser check isn't used up yet.
		 */
		function post( data, tries ) {
			return fetch( cfg.url, { method: 'POST', body: data, credentials: 'same-origin' } )
				.then( function ( res ) {
					return res.text().then( function ( text ) {
						var cookie = /document\.cookie\s*=\s*["']([A-Za-z0-9_]+=[^"';]*)/.exec( text );
						if ( cookie && tries < 2 ) {
							document.cookie = cookie[ 1 ] + '; path=/';
							return post( data, tries + 1 );
						}
						try {
							return JSON.parse( text );
						} catch ( e ) {
							return { success: false };
						}
					} );
				} );
		}

		var sending = false;
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			if ( sending ) {
				return;
			}
			if ( ! form.elements.name.value.trim() ) {
				say( cfg.needName, true );
				form.elements.name.focus();
				return;
			}
			if ( ! form.elements.message.value.trim() ) {
				say( cfg.needText, true );
				form.elements.message.focus();
				return;
			}

			var data = new FormData( form );
			var ctx  = context();
			Object.keys( ctx ).forEach( function ( k ) {
				data.append( k, ctx[ k ] );
			} );
			data.append( 'action', 'nm_beta_report' );

			var send = form.querySelector( '.nm-beta-form__send' );
			sending = true;
			send.disabled = true;
			say( cfg.sending );

			post( data, 0 )
				.then( function ( json ) {
					if ( json && json.success ) {
						form.elements.message.value = '';
						say( '' );
						form.hidden = true;
						done.hidden = false;
						var btn = done.querySelector( 'button' );
						if ( btn ) {
							btn.focus();
						}
					} else {
						say( ( json && json.data && json.data.message ) || cfg.failed, true );
					}
				} )
				.catch( function () {
					say( cfg.failed, true );
				} )
				.then( function () {
					sending = false;
					send.disabled = false;
					// Every browser check works once: get a fresh one.
					if ( window.nmFormGuard && window.nmFormGuard.reset ) {
						window.nmFormGuard.reset( form );
					}
				} );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
