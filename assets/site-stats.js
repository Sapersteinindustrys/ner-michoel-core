/**
 * Visitor counting for the Control Panel's Site Visitors and Speed & Capacity
 * screens. Sends:
 *   view   once the page has loaded, with the real load time;
 *   nav    when the theme's page router changes page without a reload;
 *   beat   every 50 seconds (the server may ask for less often) while the tab
 *          is in view or something is playing, so the panel can say how many
 *          people are on the site and listening right now;
 *   leave  when the tab closes.
 *
 * Goes to the light counter (beacon.php, which doesn't start all of WordPress)
 * and, if that can't be reached, to the REST route for the rest of the visit.
 * Nothing is sent for signed-in editors: the plugin doesn't load this for them.
 */
( function () {
	if ( typeof nmSiteStats === 'undefined' || ! window.JSON ) {
		return;
	}

	var lightUrl = nmSiteStats.light || '';
	var restUrl  = nmSiteStats.endpoint || '';
	var useRest  = ! lightUrl || store( 'nmStatsRest' ) === '1';
	var tabId    = store( 'nmStatsTab' );
	var nextIn   = 50;
	var timer    = null;
	var lastSent = 0;
	var stopped  = false;

	if ( ! tabId ) {
		tabId = randomId();
		store( 'nmStatsTab', tabId );
	}

	// Per-tab storage (sessionStorage); quietly does nothing where it's blocked.
	function store( key, value ) {
		try {
			if ( value === undefined ) {
				return window.sessionStorage.getItem( key );
			}
			window.sessionStorage.setItem( key, value );
		} catch ( e ) {}
		return null;
	}

	function randomId() {
		var out = '';
		var bytes;
		if ( window.crypto && window.crypto.getRandomValues ) {
			bytes = new Uint8Array( 12 );
			window.crypto.getRandomValues( bytes );
			for ( var i = 0; i < bytes.length; i++ ) {
				out += ( '0' + bytes[ i ].toString( 16 ) ).slice( -2 );
			}
			return out;
		}
		return String( Math.random() ).slice( 2 ) + String( Date.now() );
	}

	function isPlaying() {
		var media = document.querySelectorAll( 'audio, video' );
		for ( var i = 0; i < media.length; i++ ) {
			if ( ! media[ i ].paused && ! media[ i ].ended ) {
				return true;
			}
		}
		return false;
	}

	function signedIn() {
		return document.body && document.body.classList && document.body.classList.contains( 'logged-in' ) ? 1 : 0;
	}

	function loadTime() {
		var p = window.performance;
		if ( ! p ) {
			return null;
		}
		if ( p.getEntriesByType ) {
			var nav = p.getEntriesByType( 'navigation' )[ 0 ];
			if ( nav && nav.loadEventEnd > 0 ) {
				return Math.round( nav.loadEventEnd );
			}
		}
		if ( p.timing && p.timing.loadEventEnd > 0 && p.timing.navigationStart > 0 ) {
			return p.timing.loadEventEnd - p.timing.navigationStart;
		}
		return null;
	}

	function payload( type ) {
		var data = {
			t: type,
			sid: tabId,
			url: window.location.pathname + window.location.search,
			playing: isPlaying() ? 1 : 0,
			in: signedIn()
		};
		if ( type === 'view' ) {
			data.ref  = document.referrer || '';
			data.load = loadTime();
		}
		return data;
	}

	function post( url, body ) {
		return fetch( url, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: body,
			credentials: 'same-origin',
			keepalive: true
		} ).then( function ( res ) {
			if ( ! res.ok ) {
				throw new Error( 'HTTP ' + res.status );
			}
			return res.json();
		} );
	}

	function send( type ) {
		if ( stopped || ! window.fetch ) {
			return;
		}
		var body = JSON.stringify( payload( type ) );
		lastSent = Date.now();

		var first = useRest ? post( restUrl, body ) : post( lightUrl, body ).catch( function () {
			// The light counter can't be reached here: the REST route from now on.
			useRest = true;
			store( 'nmStatsRest', '1' );
			return post( restUrl, body );
		} );

		first.then( function ( answer ) {
			var next = answer && typeof answer.next === 'number' ? answer.next : 50;
			if ( next <= 0 ) {
				stopped = true; // The server doesn't count this browser (a bot, say).
				clearTimeout( timer );
				return;
			}
			nextIn = Math.max( 30, Math.min( 900, next ) );
			schedule();
		} ).catch( function () {
			schedule();
		} );
	}

	// The next "still here", only while the tab is in view or playing.
	function schedule() {
		clearTimeout( timer );
		if ( stopped ) {
			return;
		}
		timer = setTimeout( function () {
			if ( document.visibilityState === 'hidden' && ! isPlaying() ) {
				return; // Picked up again when the tab comes back or something plays.
			}
			send( 'beat' );
		}, nextIn * 1000 );
	}

	function resume() {
		if ( Date.now() - lastSent > 10000 ) {
			send( 'beat' );
		} else {
			schedule();
		}
	}

	function start() {
		send( 'view' );

		document.addEventListener( 'nm:content-swapped', function () {
			send( 'nav' );
		} );

		document.addEventListener( 'visibilitychange', function () {
			if ( document.visibilityState === 'visible' ) {
				resume();
			}
		} );

		// Starting or stopping playback is news for the "listening now" count.
		[ 'play', 'pause', 'ended' ].forEach( function ( name ) {
			document.addEventListener( name, resume, true );
		} );

		window.addEventListener( 'pagehide', function () {
			if ( stopped || ! navigator.sendBeacon ) {
				return;
			}
			navigator.sendBeacon( useRest ? restUrl : lightUrl, new Blob( [ JSON.stringify( { t: 'leave', sid: tabId } ) ], { type: 'text/plain' } ) );
		} );

		// Back to a page kept in the browser's back/forward cache: it said "leave" on the way out.
		window.addEventListener( 'pageshow', function ( event ) {
			if ( event.persisted ) {
				send( 'beat' );
			}
		} );
	}

	if ( document.readyState === 'complete' ) {
		start();
	} else {
		window.addEventListener( 'load', function () {
			// A tick after 'load' so loadEventEnd is actually populated.
			setTimeout( start, 0 );
		} );
	}
} )();
