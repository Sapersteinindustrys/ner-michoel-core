/**
 * Fires once per page load, after the page has finished loading, so
 * the reported load time is real. Uses sendBeacon where available so
 * this never delays or gets cancelled by navigation.
 */
( function () {
	if ( typeof nmSiteStats === 'undefined' ) {
		return;
	}

	function send() {
		var loadTime = null;
		if ( window.performance && window.performance.timing ) {
			var t = window.performance.timing;
			if ( t.loadEventEnd > 0 && t.navigationStart > 0 ) {
				loadTime = t.loadEventEnd - t.navigationStart;
			}
		}

		var payload = {
			url:       window.location.pathname + window.location.search,
			referrer:  document.referrer || '',
			load_time: loadTime
		};

		var body = JSON.stringify( payload );

		if ( navigator.sendBeacon ) {
			var blob = new Blob( [ body ], { type: 'application/json' } );
			navigator.sendBeacon( nmSiteStats.endpoint, blob );
			return;
		}

		fetch( nmSiteStats.endpoint, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: body,
			keepalive: true
		} ).catch( function () {} );
	}

	if ( document.readyState === 'complete' ) {
		send();
	} else {
		window.addEventListener( 'load', function () {
			// A tick after 'load' so loadEventEnd is actually populated.
			setTimeout( send, 0 );
		} );
	}
} )();
