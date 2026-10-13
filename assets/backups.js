/**
 * Advanced > Backups (includes/backups-screen.php): draws how the backups stand, the three copies and the
 * last runs from the status data, follows a running backup every 3 seconds, and sends Back up now, Stop and
 * the settings to the REST routes in includes/backups.php.
 */
( function () {
	'use strict';

	var root = document.querySelector( '.nmb' );
	if ( ! root ) {
		return;
	}
	var cfg = window.nmBackupsConfig || {};
	var T = cfg.text || {};
	var A = window.NMAdmin || {};
	var data = {};
	try {
		data = JSON.parse( root.getAttribute( 'data-nmb-initial' ) || '{}' );
	} catch ( e ) {}
	var remote = null;
	var timer = null;
	var form = root.querySelector( '[data-nmb="form"]' );

	function q( sel ) {
		return root.querySelector( sel );
	}
	function esc( s ) {
		return String( s === undefined || s === null ? '' : s ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}
	function icon( name ) {
		return A.icon ? A.icon( name ) : '';
	}
	function toast( msg, type ) {
		if ( A.toast ) {
			A.toast( msg, type );
		}
	}
	/* "%1$s … %2$s" or "%s" filled in order. */
	function fmt( template ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var i = 0;
		return String( template ).replace( /%(\d+)\$s|%s/g, function ( m, n ) {
			return n ? args[ n - 1 ] : args[ i++ ];
		} );
	}
	/* "Today at 3:00 AM" in the middle of a sentence: "today at 3:00 AM". */
	function mid( s ) {
		return /^(Today|Tomorrow|Yesterday)\b/.test( s ) ? s.charAt( 0 ).toLowerCase() + s.slice( 1 ) : s;
	}
	function size( bytes ) {
		bytes = Number( bytes ) || 0;
		var units = [ 'bytes', 'KB', 'MB', 'GB', 'TB' ];
		var u = 0;
		while ( bytes >= 1024 && u < units.length - 1 ) {
			bytes /= 1024;
			u++;
		}
		return ( u ? bytes.toFixed( bytes < 10 ? 1 : 0 ) : bytes ) + ' ' + units[ u ];
	}
	function took( sec ) {
		sec = Number( sec ) || 0;
		return sec < 90 ? sec + ' ' + T.seconds : Math.round( sec / 60 ) + ' ' + T.minutes;
	}
	function slotList( slots ) {
		var words = ( slots || [] ).map( function ( s ) {
			return T[ s ] || s;
		} );
		if ( words.length < 2 ) {
			return words.join( '' );
		}
		return words.slice( 0, -1 ).join( ', ' ) + ' ' + T.and + ' ' + words[ words.length - 1 ];
	}

	function api( method, path, body ) {
		return fetch( cfg.restUrl + path, {
			method: method,
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json', Accept: 'application/json' },
			body: body ? JSON.stringify( body ) : undefined,
		} ).then( function ( r ) {
			return r.json().catch( function () {
				return {};
			} ).then( function ( j ) {
				if ( ! r.ok ) {
					throw new Error( j && j.message ? j.message : 'HTTP ' + r.status );
				}
				return j;
			} );
		} );
	}

	/* ---------------- How the backups stand ---------------- */

	function head( iconName, title, tone ) {
		return '<div class="nmb-head nmb-head--' + tone + '"><span class="nmb-head__icon">' + icon( iconName ) + '</span><h2 class="nmb-head__title">' + esc( title ) + '</h2></div>';
	}

	function lastOkSize() {
		var log = data.log || [];
		for ( var i = 0; i < log.length; i++ ) {
			if ( 'ok' === log[ i ].status ) {
				return log[ i ].size;
			}
		}
		return 0;
	}

	function renderStatus() {
		var el = q( '[data-nmb="status"]' );
		var h = '';
		var job = data.job;
		if ( ! data.configured ) {
			el.className = 'nm-card nmb-status is-warn';
			h = head( 'cloud', T.notSetTitle, 'warn' ) +
				'<p>' + esc( T.notSet ) + '</p>' +
				'<div class="nmb-actions"><a class="nm-btn nm-btn--primary" href="' + esc( cfg.storageUrl ) + '">' + esc( T.openStorage ) + '</a></div>';
		} else if ( job ) {
			el.className = 'nm-card nmb-status is-run';
			var doing = job.doing.charAt( 0 ).toUpperCase() + job.doing.slice( 1 );
			h = head( 'cloud', T.runningTitle, 'run' ) +
				'<div class="nmb-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' + job.percent + '" aria-label="' + esc( T.runningTitle ) + '"><span style="width:' + Math.max( 2, job.percent ) + '%"></span></div>' +
				'<p class="nmb-doing"><strong>' + esc( doing ) + '</strong>' + ( job.detail ? ' <span class="nmb-sep">·</span> ' + esc( job.detail ) : '' ) + ' <span class="nmb-pct">' + job.percent + '%</span></p>' +
				'<p class="nmb-muted">' + esc( fmt( T.runningSub, mid( job.startedText ), slotList( job.due ) ) ) + '</p>' +
				( job.retry ? '<p class="nmb-note nmb-note--warn">' + esc( T.retrying + job.retry ) + '</p>' : '' ) +
				'<div class="nmb-actions"><button type="button" class="nm-btn" data-nmb-act="stop">' + icon( 'x' ) + esc( T.stop ) + '</button></div>';
		} else {
			var last = ( data.log || [] )[ 0 ];
			var tone, title, iconName;
			var lines = [];
			if ( last && 'failed' === last.status ) {
				tone = 'bad';
				iconName = 'alert';
				title = T.failedTitle;
				lines.push( '<p class="nmb-error">' + esc( last.error ) + '</p>' );
				lines.push( '<p>' + esc( T.failedKeep ) + '</p>' );
			} else if ( data.overdue ) {
				tone = 'warn';
				iconName = 'warning';
				title = T.overdueTitle;
				lines.push( '<p>' + esc( fmt( T.overdue, data.lastOkText ? mid( data.lastOkText ) : '—' ) ) + '</p>' );
			} else if ( data.lastOk ) {
				tone = 'ok';
				iconName = 'check-circle';
				title = T.okTitle;
				lines.push( '<p>' + esc( fmt( T.lastOk, mid( data.lastOkText ), size( lastOkSize() ) ) ) + '</p>' );
			} else {
				tone = 'none';
				iconName = 'cloud';
				title = T.noneTitle;
				lines.push( '<p>' + esc( data.nextText ? fmt( T.noneNext, mid( data.nextText ) ) : T.none ) + '</p>' );
			}
			if ( ! data.settings.enabled ) {
				lines.push( '<p class="nmb-muted">' + esc( T.nightlyOff ) + '</p>' );
			} else if ( data.nextText && 'none' !== tone ) {
				lines.push( '<p class="nmb-muted">' + esc( fmt( T.next, mid( data.nextText ) ) ) + '</p>' );
			}
			el.className = 'nm-card nmb-status is-' + tone;
			h = head( iconName, title, tone ) + lines.join( '' ) +
				'<div class="nmb-actions"><button type="button" class="nm-btn nm-btn--primary" data-nmb-act="run">' + icon( 'cloud' ) + esc( T.backUpNow ) + '</button></div>';
		}
		el.innerHTML = h;
	}

	/* ---------------- The three copies ---------------- */

	function renderSlots() {
		var defs = [
			[ 'daily', T.slotDaily, T.slotDailySub ],
			[ 'monthly', T.slotMonthly, T.slotMonthSub ],
			[ 'bimonthly', T.slotBimonthly, T.slotBiSub ],
		];
		var slots = data.slots || {};
		var h = defs.map( function ( d ) {
			var info = slots[ d[ 0 ] ];
			var check = remote && remote[ d[ 0 ] ];
			var body;
			if ( info ) {
				var files = info.files || [];
				body = '<p class="nmb-slot__when">' + esc( info.madeText ) + '</p>' +
					'<p class="nmb-muted">' + esc( size( info.size ) ) + '</p>' +
					'<div class="nmb-slot__dl">' + files.map( function ( f, i ) {
						var label = files.length > 1 ? fmt( T.piece, i + 1, files.length ) : T.download;
						return '<a class="nm-btn nm-btn--soft nm-btn--sm" href="' + esc( f.url ) + '" download="' + esc( f.name ) + '" title="' + esc( f.name ) + '">' + icon( 'download' ) + esc( label ) + '</a>';
					} ).join( '' ) + '</div>';
			} else {
				body = '<p class="nmb-muted">' + esc( check && check.forgotten ? T.slotGone : T.slotEmpty ) + '</p>';
			}
			if ( check && check.error ) {
				body += '<p class="nmb-note nmb-note--warn">' + esc( T.slotCheckFail + check.error ) + '</p>';
			}
			return '<article class="nm-card nmb-slot' + ( info ? '' : ' is-empty' ) + '" data-slot="' + d[ 0 ] + '">' +
				'<h3 class="nmb-slot__title">' + icon( 'database' ) + esc( d[ 1 ] ) + '</h3>' +
				'<p class="nmb-slot__sub">' + esc( d[ 2 ] ) + '</p>' + body + '</article>';
		} ).join( '' );
		// Only when something changed: a Download link swapped for an identical one while the browser
		// is starting its download cancels the download.
		var el = q( '[data-nmb="slots"]' );
		if ( el.__nmbHtml !== h ) {
			el.innerHTML = h;
			el.__nmbHtml = h;
		}
	}

	/* ---------------- Recent runs ---------------- */

	function renderLog() {
		var log = data.log || [];
		var el = q( '[data-nmb="log"]' );
		if ( ! log.length ) {
			el.innerHTML = '<p class="nmb-muted">' + esc( T.noRuns ) + '</p>';
			return;
		}
		el.innerHTML = '<table class="nmb-table"><thead><tr>' +
			'<th scope="col">' + esc( T.colWhen ) + '</th><th scope="col">' + esc( T.colHow ) + '</th><th scope="col">' + esc( T.colResult ) + '</th>' +
			'<th scope="col" class="num">' + esc( T.colSize ) + '</th><th scope="col" class="num">' + esc( T.colTook ) + '</th></tr></thead><tbody>' +
			log.map( function ( e ) {
				var result;
				if ( 'ok' === e.status ) {
					result = '<span class="nmb-tag nmb-tag--ok">' + icon( 'check' ) + esc( fmt( T.resultOk, slotList( e.slots ) ) ) + '</span>';
				} else if ( 'failed' === e.status ) {
					result = '<span class="nmb-tag nmb-tag--bad">' + icon( 'alert' ) + esc( T.resultFailed ) + '</span> <span class="nmb-small">' + esc( e.error ) + '</span>';
				} else {
					result = '<span class="nmb-tag">' + esc( e.error ? e.error : T.resultStopped ) + '</span>';
				}
				return '<tr><td>' + esc( e.startedText ) + '</td><td>' + esc( 'manual' === e.trigger ? T.howManual : T.howSchedule ) + '</td><td>' + result + '</td>' +
					'<td class="num">' + ( 'ok' === e.status ? esc( size( e.size ) ) : '—' ) + '</td><td class="num">' + esc( took( e.took ) ) + '</td></tr>';
			} ).join( '' ) + '</tbody></table>';
	}

	function renderWhere() {
		var w = data.where || {};
		var line = q( '[data-nmb="where"]' );
		var hint = q( '[data-nmb="where-hint"]' );
		if ( ! data.configured ) {
			line.textContent = T.whereNone;
			hint.textContent = '';
			return;
		}
		line.innerHTML = esc( fmt( w.own ? T.whereOwn : T.whereMedia, w.zone, '\u0000' ) ).replace( '\u0000', '<code>' + esc( w.folder ) + '</code>' );
		hint.textContent = w.own ? T.hintOwn : T.hintMedia;
	}

	function render() {
		renderStatus();
		renderSlots();
		renderLog();
		renderWhere();
	}

	/* ---------------- Keeping up ---------------- */

	function schedule() {
		clearTimeout( timer );
		if ( data.job ) {
			timer = setTimeout( refresh, 3000 );
		}
	}

	function refresh() {
		api( 'GET', '/status' ).then( function ( d ) {
			var was = data.job;
			data = d;
			render();
			if ( was && ! d.job ) {
				var last = ( d.log || [] )[ 0 ];
				if ( last && 'ok' === last.status ) {
					toast( T.done, 'success' );
				} else if ( last && 'failed' === last.status ) {
					toast( T.failedToast, 'error' );
				}
				loadRemote();
			}
			schedule();
		} ).catch( function () {
			schedule();
		} );
	}

	function loadRemote() {
		if ( ! data.configured ) {
			return;
		}
		api( 'GET', '/remote' ).then( function ( r ) {
			remote = r;
			var forgot = Object.keys( r ).some( function ( k ) {
				return r[ k ] && r[ k ].forgotten;
			} );
			if ( forgot ) {
				return api( 'GET', '/status' ).then( function ( d ) {
					data = d;
					render();
				} );
			}
			renderSlots();
		} ).catch( function () {} );
	}

	/* ---------------- Buttons and the form ---------------- */

	root.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '[data-nmb-act]' );
		if ( ! btn ) {
			return;
		}
		var act = btn.getAttribute( 'data-nmb-act' );
		if ( 'run' === act ) {
			btn.disabled = true;
			btn.textContent = T.starting;
			api( 'POST', '/run' ).then( function ( d ) {
				data = d;
				render();
				toast( T.started, 'info' );
				schedule();
			} ).catch( function ( err ) {
				toast( T.error + err.message, 'error' );
				render();
			} );
		} else if ( 'stop' === act ) {
			var ask = A.confirm ? A.confirm( { title: T.stopTitle, message: T.stopText, confirmText: T.stopYes, cancelText: T.keepGoing, danger: true, icon: 'x' } ) : Promise.resolve( true );
			ask.then( function ( yes ) {
				if ( ! yes ) {
					return;
				}
				btn.disabled = true;
				api( 'POST', '/cancel' ).then( function ( d ) {
					data = d;
					render();
					if ( ! d.job ) {
						toast( T.stopped, 'info' );
					}
					schedule();
				} ).catch( function ( err ) {
					toast( T.error + err.message, 'error' );
					btn.disabled = false;
				} );
			} );
		}
	} );

	if ( form ) {
		form.addEventListener( 'change', function ( e ) {
			if ( 'checkbox' === e.target.type ) {
				var label = e.target.closest( '.nm-settings-checkbox' );
				if ( label ) {
					label.classList.toggle( 'is-on', e.target.checked );
				}
			}
		} );

		var statusEl = form.querySelector( '[data-nmb="form-status"]' );
		var submit = form.querySelector( 'button[type="submit"]' );

		var save = function ( body, okText ) {
			statusEl.textContent = body.zone_name || body.api_key ? T.savingZone : T.saving;
			submit.disabled = true;
			return api( 'POST', '/settings', body ).then( function ( d ) {
				data = d;
				render();
				// Emptied once saved, unless something new was typed in it meanwhile.
				if ( form.elements.api_key.value.trim() === ( body.api_key || '' ) ) {
					form.elements.api_key.value = '';
				}
				form.elements.api_key.placeholder = d.settings.has_key ? T.keySaved : '';
				statusEl.textContent = '';
				toast( okText || T.saved, 'success' );
				remote = null;
				loadRemote();
			} ).catch( function ( err ) {
				statusEl.textContent = T.error + err.message;
				toast( T.error + err.message, 'error' );
			} ).then( function () {
				submit.disabled = false;
			} );
		};

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			save( {
				enabled: form.elements.enabled.checked,
				hour: Number( form.elements.hour.value ),
				email: form.elements.email.checked,
				zone_name: form.elements.zone_name.value.trim(),
				region: form.elements.region.value.trim(),
				api_key: form.elements.api_key.value.trim(),
			} );
		} );

		var removeBtn = form.querySelector( '[data-nmb="remove-zone"]' );
		if ( removeBtn ) {
			removeBtn.addEventListener( 'click', function () {
				var ask = A.confirm ? A.confirm( { title: T.removeZoneQ, message: T.removeZoneT, confirmText: T.removeZoneYes, cancelText: T.cancel, icon: 'cloud' } ) : Promise.resolve( true );
				ask.then( function ( yes ) {
					if ( ! yes ) {
						return;
					}
					save( { remove_zone: true } ).then( function () {
						if ( ! data.settings.zone_name ) {
							form.elements.zone_name.value = '';
							form.elements.region.value = '';
							removeBtn.hidden = true;
						}
					} );
				} );
			} );
		}
	}

	render();
	loadRemote();
	schedule();
} )();
