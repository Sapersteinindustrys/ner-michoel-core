/**
 * The backup route, in the visitor's browser.
 *
 * Audio, video, PDFs and big pictures come from Bunny's address (or from the
 * site's own media address in front of it). A visitor whose filter approves this
 * site but not that address gets pages with nothing to play. This script notices
 * and sends those requests to media.php on this site instead, which fetches the
 * file from Bunny and passes it on (includes/media-relay.php).
 *
 * For a visitor who can reach Bunny it does nothing but wait:
 *
 *  - once in a while (every 6 hours) it loads Bunny's one-pixel test picture; if
 *    that fails, or comes back as another picture (some filters swap in their own),
 *    this browser is marked "blocked" for 30 minutes and everything below happens
 *    straight away, before the first click;
 *  - a player, picture or video that fails to load from Bunny is switched to the
 *    backup route on the spot, and picks up where it was;
 *  - a player that hasn't received a byte after 12 seconds is switched too;
 *  - "Play" links to a file and the download links go through the site while the
 *    browser is marked blocked.
 *
 * Config, printed before this file by includes/media-delivery.php:
 *   window.nmMedia = { hosts: [ addresses files are served from ],
 *                      relay: 'https://site/.../media.php',
 *                      probe: 'https://.../nm-probe.png' or '' }
 */
( function () {
	'use strict';

	var cfg = window.nmMedia;
	if ( ! cfg || ! cfg.relay || ! cfg.hosts || ! cfg.hosts.length || ! window.URL ) {
		return;
	}

	var KEY = 'nmMediaVia';
	var BLOCKED_MS = 30 * 60 * 1000;
	var REACHABLE_MS = 6 * 60 * 60 * 1000;
	var STALL_MS = 12000;
	var hosts = {};
	var i;
	for ( i = 0; i < cfg.hosts.length; i++ ) {
		hosts[ String( cfg.hosts[ i ] ).toLowerCase() ] = true;
	}
	var remembered = null; // The verdict, when the browser won't keep one.

	function parse( address ) {
		try {
			return new URL( address, window.location.href );
		} catch ( e ) {
			return null;
		}
	}

	function isCdn( address ) {
		var u = parse( address );
		return !! u && !! hosts[ u.host.toLowerCase() ];
	}

	function isRelay( address ) {
		return String( address || '' ).indexOf( cfg.relay ) === 0;
	}

	/** The same file through this site. */
	function toSite( address ) {
		var u = parse( address );
		if ( ! u || ! hosts[ u.host.toLowerCase() ] ) {
			return address;
		}
		var path = u.pathname;
		try {
			path = decodeURIComponent( path );
		} catch ( e ) {}
		path = path.replace( /^\/+/, '' );
		return cfg.relay + ( cfg.relay.indexOf( '?' ) < 0 ? '?' : '&' ) + 'p=' + encodeURIComponent( path ) + ( u.hash || '' );
	}

	/* ---- What this browser can reach ---- */

	function readVerdict() {
		try {
			var raw = window.localStorage.getItem( KEY );
			if ( raw ) {
				var v = JSON.parse( raw );
				if ( v && v.t ) {
					return v;
				}
			}
		} catch ( e ) {}
		return remembered;
	}

	function writeVerdict( blocked ) {
		var v = { t: Date.now(), v: blocked ? 'site' : 'cdn' };
		remembered = v;
		try {
			window.localStorage.setItem( KEY, JSON.stringify( v ) );
		} catch ( e ) {}
	}

	function fresh( v ) {
		return !! v && Date.now() - v.t < ( 'site' === v.v ? BLOCKED_MS : REACHABLE_MS );
	}

	/** Is this browser marked as unable to reach Bunny right now? */
	function viaSite() {
		var v = readVerdict();
		return fresh( v ) && 'site' === v.v;
	}

	/* ---- Addresses set from scripts: the player, the PDF reader, covers ---- */

	function patch( proto, prop ) {
		var d = proto && Object.getOwnPropertyDescriptor( proto, prop );
		if ( ! d || ! d.get || ! d.set || ! d.configurable ) {
			return;
		}
		Object.defineProperty( proto, prop, {
			configurable: true,
			enumerable: d.enumerable,
			get: d.get,
			set: function ( value ) {
				d.set.call( this, 'string' === typeof value && viaSite() ? toSite( value ) : value );
			}
		} );
	}
	patch( window.HTMLMediaElement && HTMLMediaElement.prototype, 'src' );
	patch( window.HTMLSourceElement && HTMLSourceElement.prototype, 'src' );
	patch( window.HTMLIFrameElement && HTMLIFrameElement.prototype, 'src' );
	patch( window.HTMLImageElement && HTMLImageElement.prototype, 'src' );

	/* ---- A player or video that can't load from Bunny ---- */

	// Whether someone asked this element to play. A failed load pauses the element itself (it fires
	// 'pause' with an error set), so the paused flag can't say what was wanted.
	document.addEventListener( 'play', function ( e ) {
		if ( e.target ) {
			e.target.__nmWant = true;
		}
	}, true );
	document.addEventListener( 'pause', function ( e ) {
		if ( e.target && ! e.target.error ) {
			e.target.__nmWant = false;
		}
	}, true );
	document.addEventListener( 'ended', function ( e ) {
		if ( e.target ) {
			e.target.__nmWant = false;
		}
	}, true );

	function ask( el, address, resumeAt, play ) {
		var onMeta = function () {
			el.removeEventListener( 'loadedmetadata', onMeta );
			if ( resumeAt > 0 ) {
				try {
					el.currentTime = resumeAt;
				} catch ( e ) {}
			}
		};
		var onPlayable = function () {
			el.removeEventListener( 'canplay', onPlayable );
			if ( isRelay( el.currentSrc ) ) {
				writeVerdict( true ); // It plays this way: that is the way to go for a while.
				sweepAll();
			}
		};
		el.addEventListener( 'loadedmetadata', onMeta );
		el.addEventListener( 'canplay', onPlayable );
		el.src = address; // Loading anew pauses the element; 'play' below asks again.
		if ( play ) {
			var promise = el.play();
			if ( promise && promise.catch ) {
				promise.catch( function () {} );
			}
		}
	}

	function switchMedia( el, address ) {
		var wasPlaying = ! el.paused || !! el.__nmWant;
		clearTimeout( el.__nmStall );
		ask( el, toSite( address ), el.currentTime || 0, wasPlaying );
	}

	/** The backup route itself answered with an error (busy, or Bunny is down): try again a couple of times. */
	function retryRelay( el, address ) {
		var n = el.__nmRetries || 0;
		if ( n >= 2 ) {
			return;
		}
		el.__nmRetries = n + 1;
		setTimeout( function () {
			if ( ( el.currentSrc || el.src ) !== address ) {
				return; // Another file was chosen meanwhile.
			}
			ask( el, address, el.currentTime || 0, ! el.paused || !! el.__nmWant );
		}, 0 === n ? 2500 : 6000 );
	}

	function onMediaError( el ) {
		var current = el.currentSrc || el.src || '';
		if ( isCdn( current ) ) {
			switchMedia( el, current );
		} else if ( isRelay( current ) ) {
			retryRelay( el, current );
		}
	}

	function loadAgain( media ) {
		var wasPlaying = ! media.paused || !! media.__nmWant || media.autoplay;
		media.load();
		if ( wasPlaying ) {
			var promise = media.play();
			if ( promise && promise.catch ) {
				promise.catch( function () {} );
			}
		}
	}

	function onSourceError( source, media ) {
		var current = source.src || '';
		if ( isCdn( current ) ) {
			source.src = toSite( current );
			loadAgain( media );
		} else if ( isRelay( current ) ) {
			// The route said "busy" (or Bunny hiccuped): a video built with a source tries again a couple of times too.
			var n = media.__nmRetries || 0;
			if ( n < 2 ) {
				media.__nmRetries = n + 1;
				setTimeout( function () {
					loadAgain( media );
				}, 0 === n ? 2500 : 6000 );
			}
		}
	}

	function onImageError( img ) {
		var current = img.currentSrc || img.src || '';
		if ( img.__nmImage || ! isCdn( current ) ) {
			return;
		}
		img.__nmImage = true;
		img.removeAttribute( 'srcset' );
		img.src = toSite( current );
	}

	document.addEventListener( 'error', function ( e ) {
		var el = e.target;
		if ( ! el || ! el.tagName ) {
			return;
		}
		var tag = el.tagName;
		if ( 'AUDIO' === tag || 'VIDEO' === tag ) {
			onMediaError( el );
		} else if ( 'SOURCE' === tag && el.parentNode && ( 'AUDIO' === el.parentNode.tagName || 'VIDEO' === el.parentNode.tagName ) ) {
			onSourceError( el, el.parentNode );
		} else if ( 'IMG' === tag ) {
			onImageError( el );
		}
	}, true );

	// A connection that is silently dropped never says "error": nothing has arrived after a while.
	document.addEventListener( 'loadstart', function ( e ) {
		var el = e.target;
		if ( ! el || ! ( 'AUDIO' === el.tagName || 'VIDEO' === el.tagName ) || ! isCdn( el.currentSrc || el.src ) ) {
			return;
		}
		clearTimeout( el.__nmStall );
		el.__nmStall = setTimeout( function () {
			var current = el.currentSrc || el.src;
			if ( 0 === el.readyState && 2 === el.networkState && isCdn( current ) ) {
				switchMedia( el, current );
			}
		}, STALL_MS );
	}, true );
	[ 'loadedmetadata', 'canplay', 'error', 'emptied', 'abort' ].forEach( function ( name ) {
		document.addEventListener( name, function ( e ) {
			if ( e.target && e.target.__nmStall ) {
				clearTimeout( e.target.__nmStall );
			}
		}, true );
	} );

	// Pictures that failed before this script was listening.
	function sweepImages() {
		var images = document.images || [];
		for ( var n = 0; n < images.length; n++ ) {
			if ( images[ n ].complete && 0 === images[ n ].naturalWidth ) {
				onImageError( images[ n ] );
			}
		}
	}
	// A PDF already opened in a frame can't say that it failed; once this browser is known to
	// be blocked, point such frames at the backup route.
	function sweepFrames() {
		var frames = document.getElementsByTagName( 'iframe' );
		for ( var n = 0; n < frames.length; n++ ) {
			var current = frames[ n ].getAttribute( 'src' ) || '';
			if ( current && isCdn( current ) ) {
				frames[ n ].src = toSite( parse( current ).href );
			}
		}
	}

	// Once this browser is known to be blocked, everything already on the page that points at Bunny
	// goes to the backup route straight away, instead of waiting to fail one by one.
	function sweepAll() {
		if ( ! viaSite() ) {
			return;
		}
		var n;
		var images = document.images || [];
		for ( n = 0; n < images.length; n++ ) {
			if ( isCdn( images[ n ].currentSrc || images[ n ].src ) ) {
				onImageError( images[ n ] );
			}
		}
		sweepFrames();
		var players = document.querySelectorAll( 'audio[src], video[src]' );
		for ( n = 0; n < players.length; n++ ) {
			if ( isCdn( players[ n ].getAttribute( 'src' ) ) ) {
				players[ n ].src = toSite( players[ n ].src );
			}
		}
		var sources = document.querySelectorAll( 'audio > source[src], video > source[src]' );
		for ( n = 0; n < sources.length; n++ ) {
			if ( isCdn( sources[ n ].getAttribute( 'src' ) ) ) {
				sources[ n ].src = toSite( sources[ n ].src );
				if ( sources[ n ].parentNode.load ) {
					sources[ n ].parentNode.load();
				}
			}
		}
	}
	function onReady() {
		sweepImages();
		sweepAll();
	}
	document.addEventListener( 'DOMContentLoaded', onReady );
	window.addEventListener( 'load', onReady );

	/* ---- Links: "Play" opens the file, downloads ---- */

	document.addEventListener( 'click', function ( e ) {
		if ( ! viaSite() || ! e.target || ! e.target.closest ) {
			return;
		}
		var a = e.target.closest( 'a[href]' );
		if ( ! a ) {
			return;
		}
		var href = a.getAttribute( 'href' ) || '';
		if ( isCdn( a.href ) ) {
			a.setAttribute( 'href', toSite( a.href ) );
		} else if ( /[?&]nm_download=/.test( href ) && href.indexOf( 'nm_via=' ) < 0 ) {
			a.setAttribute( 'href', href + ( href.indexOf( '?' ) < 0 ? '?' : '&' ) + 'nm_via=site' );
		}
	}, true );

	/* ---- Can this browser reach Bunny at all? ---- */

	function probe() {
		if ( ! cfg.probe || fresh( readVerdict() ) || false === window.navigator.onLine ) {
			return;
		}
		var img = new Image();
		var done = false;
		var finish = function ( reachable ) {
			if ( ! done ) {
				done = true;
				writeVerdict( ! reachable );
				if ( ! reachable ) {
					onReady();
				}
			}
		};
		img.onload = function () {
			finish( 1 === img.naturalWidth && 1 === img.naturalHeight );
		};
		img.onerror = function () {
			finish( false );
		};
		setTimeout( function () {
			finish( false );
		}, 10000 );
		img.src = cfg.probe + ( cfg.probe.indexOf( '?' ) < 0 ? '?' : '&' ) + 'v=' + Math.floor( Date.now() / 3600000 );
	}
	if ( 'complete' === document.readyState ) {
		setTimeout( probe, 1500 );
	} else {
		window.addEventListener( 'load', function () {
			setTimeout( probe, 1500 );
		} );
	}

	// For scripts that build addresses themselves.
	cfg.url = function ( address ) {
		return viaSite() ? toSite( address ) : address;
	};
}() );
