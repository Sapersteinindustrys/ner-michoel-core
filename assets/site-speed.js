/**
 * Reports > Speed & Capacity: draws the charts (plain SVG, no library), keeps
 * "Right now" up to date every 10 seconds while the page is open and in view,
 * switches the history charts between the last day and week, runs "Check now"
 * and saves the host's limits. Data from GET speed/live and speed/history
 * (includes/site-speed-screen.php).
 */
( function () {
	var root = document.querySelector( '.nms' );
	if ( ! root || typeof nmSpeedConfig === 'undefined' ) {
		return;
	}

	var cfg     = nmSpeedConfig;
	var SVG     = 'http://www.w3.org/2000/svg';
	var state   = { live: null, history: null, range: 'day', timer: null, failures: 0 };
	var nf      = new Intl.NumberFormat();
	var nf1     = new Intl.NumberFormat( undefined, { maximumFractionDigits: 1 } );
	var tzOpt   = {};
	try {
		if ( cfg.tz ) {
			new Intl.DateTimeFormat( undefined, { timeZone: cfg.tz } );
			tzOpt = { timeZone: cfg.tz };
		}
	} catch ( e ) {
		tzOpt = {};
	}

	var SERIES = {
		online:    { label: 'On the site', color: 'var(--nms-s1)' },
		listening: { label: 'Listening', color: 'var(--nms-s2)' },
		page_ms:   { label: 'Pages built for visitors', color: 'var(--nms-s1)' },
		data_ms:   { label: 'Site data (queue, next up, plays)', color: 'var(--nms-s2)' },
		probe_ms:  { label: 'Test page, every 5 minutes', color: 'var(--nms-s3)' },
		use_visits:{ label: 'Visitors', color: 'var(--nms-s1)' },
		use_bots:  { label: 'Search engines and bots', color: 'var(--nms-s2)' },
		use_other: { label: 'Staff and background jobs', color: 'var(--nms-s3)' }
	};
	if ( cfg.labels ) {
		Object.keys( cfg.labels ).forEach( function ( k ) {
			if ( SERIES[ k ] ) {
				SERIES[ k ].label = cfg.labels[ k ];
			}
		} );
	}
	var T = cfg.text || {};
	function t( key, fallback ) {
		return T[ key ] || fallback;
	}

	/* ------------------------------------------------------------ */
	/* Helpers                                                       */
	/* ------------------------------------------------------------ */

	function q( name ) {
		return root.querySelector( '[data-nms="' + name + '"]' );
	}
	function setText( name, text ) {
		var el = q( name );
		if ( el ) {
			el.textContent = text;
		}
	}
	function el( tag, attrs, parent ) {
		var node = document.createElementNS( SVG, tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			node.setAttribute( k, attrs[ k ] );
		} );
		if ( parent ) {
			parent.appendChild( node );
		}
		return node;
	}
	function html( tag, cls, text ) {
		var node = document.createElement( tag );
		if ( cls ) {
			node.className = cls;
		}
		if ( text !== undefined && text !== null ) {
			node.textContent = text;
		}
		return node;
	}
	function fmtTime( sec, withDay ) {
		var o = withDay ? { weekday: 'short', hour: 'numeric', minute: '2-digit' } : { hour: 'numeric', minute: '2-digit' };
		Object.keys( tzOpt ).forEach( function ( k ) {
			o[ k ] = tzOpt[ k ];
		} );
		return new Intl.DateTimeFormat( undefined, o ).format( new Date( sec * 1000 ) );
	}
	function ago( sec ) {
		var d = Math.max( 0, Math.round( Date.now() / 1000 - sec ) );
		if ( d < 60 ) {
			return d + ' s';
		}
		if ( d < 3600 ) {
			return Math.round( d / 60 ) + ' min';
		}
		return nf1.format( d / 3600 ) + ' h';
	}
	function inTime( sec ) {
		var d = Math.round( sec - Date.now() / 1000 );
		return d <= 0 ? t( 'aMoment', 'a moment' ) : ago( Date.now() / 1000 - d );
	}
	/**
	 * A y-axis with round steps: about four of them, whole numbers when the values are
	 * counts of people. Returns { max, step }.
	 */
	function axis( dataMax, whole ) {
		var target = dataMax > 0 ? dataMax * 1.05 : 1;
		var raw = target / 4;
		var p = Math.pow( 10, Math.floor( Math.log10( raw ) ) );
		var mult = whole ? [ 1, 2, 5, 10 ] : [ 1, 2, 2.5, 5, 10 ];
		var step = 10 * p;
		for ( var i = 0; i < mult.length; i++ ) {
			if ( mult[ i ] * p >= raw ) {
				step = mult[ i ] * p;
				break;
			}
		}
		if ( whole ) {
			step = Math.max( 1, Math.round( step ) );
		}
		return { max: step * Math.ceil( target / step ), step: step };
	}
	function api( path, opts ) {
		opts = opts || {};
		return fetch( cfg.restUrl + path, {
			method: opts.method || 'GET',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' },
			body: opts.body ? JSON.stringify( opts.body ) : undefined
		} ).then( function ( res ) {
			return res.json().then( function ( data ) {
				if ( ! res.ok ) {
					throw new Error( ( data && data.message ) || ( 'HTTP ' + res.status ) );
				}
				return data;
			} );
		} );
	}
	function severity( pct ) {
		if ( pct >= 90 ) {
			return [ 'is-critical', t( 'overloaded', 'Overloaded' ) ];
		}
		if ( pct >= 70 ) {
			return [ 'is-serious', t( 'veryBusy', 'Very busy: people may wait' ) ];
		}
		if ( pct >= 40 ) {
			return [ 'is-warn', t( 'busy', 'Busy' ) ];
		}
		return [ '', t( 'calm', 'Calm' ) ];
	}
	function setMeter( name, pct ) {
		var m = q( name );
		if ( ! m ) {
			return '';
		}
		var sev = severity( pct );
		m.classList.remove( 'is-warn', 'is-serious', 'is-critical' );
		if ( sev[ 0 ] ) {
			m.classList.add( sev[ 0 ] );
		}
		m.firstElementChild.style.width = Math.max( pct > 0 ? 2 : 0, Math.min( 100, pct ) ) + '%';
		m.setAttribute( 'aria-label', nf.format( Math.round( pct ) ) + '% - ' + sev[ 1 ] );
		return sev[ 1 ];
	}

	/* ------------------------------------------------------------ */
	/* Legend, tooltip and table view                                */
	/* ------------------------------------------------------------ */

	function legend( container, keys, box ) {
		var ul = html( 'ul', 'nms-legend' );
		keys.forEach( function ( k ) {
			var li = html( 'li' );
			var key = html( 'i', box ? 'is-box' : '' );
			key.style.background = SERIES[ k ].color;
			li.appendChild( key );
			li.appendChild( document.createTextNode( SERIES[ k ].label ) );
			ul.appendChild( li );
		} );
		container.appendChild( ul );
	}

	function tooltip( container ) {
		var tip = html( 'div', 'nms-tip' );
		tip.hidden = true;
		container.appendChild( tip );
		return {
			show: function ( x, y, title, rows ) {
				tip.textContent = '';
				tip.appendChild( html( 'div', 'nms-tip__time', title ) );
				rows.forEach( function ( r ) {
					var row = html( 'div', 'nms-tip__row' );
					var key = html( 'i' );
					key.style.background = r.color;
					row.appendChild( key );
					row.appendChild( html( 'strong', '', r.value ) );
					row.appendChild( document.createTextNode( r.label ) );
					tip.appendChild( row );
				} );
				tip.hidden = false;
				var w = container.clientWidth;
				var left = x + 14;
				if ( left + tip.offsetWidth > w ) {
					left = Math.max( 0, x - tip.offsetWidth - 14 );
				}
				tip.style.left = left + 'px';
				tip.style.top = Math.max( 0, y - 10 ) + 'px';
			},
			hide: function () {
				tip.hidden = true;
			}
		};
	}

	function tableView( container, headers, rows ) {
		var details = html( 'details', 'nms-tablev' );
		details.appendChild( html( 'summary', '', t( 'showTable', 'Show as a table' ) ) );
		var wrap = html( 'div', 'nms-table-wrap' );
		var table = html( 'table', 'nms-table' );
		var thead = html( 'thead' );
		var tr = html( 'tr' );
		headers.forEach( function ( h, i ) {
			var th = html( 'th', i ? 'nms-num' : '', h );
			th.scope = 'col';
			tr.appendChild( th );
		} );
		thead.appendChild( tr );
		table.appendChild( thead );
		var tbody = html( 'tbody' );
		rows.forEach( function ( r ) {
			var row = html( 'tr' );
			r.forEach( function ( cell, i ) {
				var c = html( i ? 'td' : 'th', i ? 'nms-num' : '', cell );
				if ( ! i ) {
					c.scope = 'row';
				}
				row.appendChild( c );
			} );
			tbody.appendChild( row );
		} );
		table.appendChild( tbody );
		wrap.appendChild( table );
		details.appendChild( wrap );
		container.appendChild( details );
		return details;
	}

	/* ------------------------------------------------------------ */
	/* Line chart                                                    */
	/* ------------------------------------------------------------ */

	/**
	 * points: [{t, <key>: number|null}], keys: series keys, fmt: value -> text,
	 * withDay: show the weekday in times, unit: text after values in the table.
	 */
	function lineChart( container, points, keys, fmt, withDay, emptyText, whole ) {
		var keepTable = container.querySelector( '.nms-tablev' );
		var tableOpen = keepTable && keepTable.open;
		container.textContent = '';

		var has = points.some( function ( p ) {
			return keys.some( function ( k ) {
				return p[ k ] !== null && p[ k ] !== undefined && p[ k ] > 0;
			} );
		} );

		legend( container, keys, false );
		if ( ! points.length || ! has ) {
			container.appendChild( html( 'div', 'nms-empty', emptyText ) );
			return;
		}

		var W = Math.max( 280, container.clientWidth );
		var H = 220;
		var m = { l: 44, r: 16, t: 14, b: 26 };
		var pw = W - m.l - m.r;
		var ph = H - m.t - m.b;
		var max = 0;
		points.forEach( function ( p ) {
			keys.forEach( function ( k ) {
				if ( p[ k ] > max ) {
					max = p[ k ];
				}
			} );
		} );
		var ax = axis( max, whole );
		max = ax.max;
		var t0 = points[ 0 ].t;
		var t1 = points[ points.length - 1 ].t;
		var x = function ( tt ) {
			return m.l + ( t1 === t0 ? pw : ( ( tt - t0 ) / ( t1 - t0 ) ) * pw );
		};
		var y = function ( v ) {
			return m.t + ph - ( v / max ) * ph;
		};

		var svg = el( 'svg', { viewBox: '0 0 ' + W + ' ' + H, height: H, role: 'img', 'aria-label': container.getAttribute( 'aria-label' ) || '' } );

		// Grid and y-axis.
		for ( var gv = 0; gv <= max + ax.step / 1000; gv += ax.step ) {
			var gy = y( gv );
			el( 'line', { x1: m.l, x2: W - m.r, y1: gy, y2: gy, stroke: gv ? '#e8ecea' : '#cdd5d1', 'stroke-width': 1, 'shape-rendering': 'crispEdges' }, svg );
			var lab = el( 'text', { x: m.l - 8, y: gy + 4, 'text-anchor': 'end', 'class': 'nms-tick' }, svg );
			lab.textContent = fmt( gv, true );
		}
		// x-axis times.
		var ticks = Math.min( 6, Math.max( 2, Math.floor( pw / 110 ) ) );
		for ( var i = 0; i <= ticks; i++ ) {
			var tt = t0 + ( ( t1 - t0 ) * i ) / ticks;
			var xl = el( 'text', { x: x( tt ), y: H - 6, 'text-anchor': i === 0 ? 'start' : ( i === ticks ? 'end' : 'middle' ), 'class': 'nms-tick' }, svg );
			xl.textContent = fmtTime( tt, withDay );
		}

		// Lines (a gap where there's no value) and a dot at each line's last value.
		keys.forEach( function ( k ) {
			var d = '';
			var pen = false;
			var last = null;
			points.forEach( function ( p ) {
				var v = p[ k ];
				if ( v === null || v === undefined ) {
					pen = false;
					return;
				}
				d += ( pen ? 'L' : 'M' ) + x( p.t ).toFixed( 1 ) + ' ' + y( v ).toFixed( 1 );
				pen = true;
				last = p;
			} );
			var path = el( 'path', { d: d, fill: 'none', 'stroke-width': 2, 'stroke-linejoin': 'round', 'stroke-linecap': 'round' }, svg );
			path.style.stroke = SERIES[ k ].color;
			if ( last ) {
				var dot = el( 'circle', { cx: x( last.t ), cy: y( last[ k ] ), r: 4, 'stroke-width': 2, stroke: '#fff' }, svg );
				dot.style.fill = SERIES[ k ].color;
			}
		} );

		// Hover / keyboard: a crosshair that snaps to the nearest time, one tooltip for every series.
		var cross = el( 'line', { y1: m.t, y2: m.t + ph, stroke: '#75827c', 'stroke-width': 1, visibility: 'hidden' }, svg );
		var dots = keys.map( function ( k ) {
			var c = el( 'circle', { r: 4, 'stroke-width': 2, stroke: '#fff', visibility: 'hidden' }, svg );
			c.style.fill = SERIES[ k ].color;
			return c;
		} );
		var hit = el( 'rect', { x: m.l, y: m.t, width: pw, height: ph, 'class': 'nms-hit', tabindex: 0 }, svg );
		container.appendChild( svg );
		var tip = tooltip( container );
		var current = points.length - 1;

		function showAt( idx ) {
			current = Math.max( 0, Math.min( points.length - 1, idx ) );
			var p = points[ current ];
			var px = x( p.t );
			cross.setAttribute( 'x1', px );
			cross.setAttribute( 'x2', px );
			cross.setAttribute( 'visibility', 'visible' );
			var rows = [];
			var topY = m.t + ph;
			keys.forEach( function ( k, n ) {
				var v = p[ k ];
				if ( v === null || v === undefined ) {
					dots[ n ].setAttribute( 'visibility', 'hidden' );
				} else {
					dots[ n ].setAttribute( 'cx', px );
					dots[ n ].setAttribute( 'cy', y( v ) );
					dots[ n ].setAttribute( 'visibility', 'visible' );
					topY = Math.min( topY, y( v ) );
				}
				rows.push( { color: SERIES[ k ].color, value: v === null || v === undefined ? '—' : fmt( v ), label: SERIES[ k ].label } );
			} );
			tip.show( px, topY, fmtTime( p.t, withDay ), rows );
		}
		function hide() {
			cross.setAttribute( 'visibility', 'hidden' );
			dots.forEach( function ( d ) {
				d.setAttribute( 'visibility', 'hidden' );
			} );
			tip.hide();
		}
		function nearest( clientX ) {
			var rect = svg.getBoundingClientRect();
			var sx = ( ( clientX - rect.left ) / rect.width ) * W;
			var best = 0;
			var bestD = Infinity;
			points.forEach( function ( p, n ) {
				var dd = Math.abs( x( p.t ) - sx );
				if ( dd < bestD ) {
					bestD = dd;
					best = n;
				}
			} );
			return best;
		}
		hit.addEventListener( 'pointermove', function ( e ) {
			showAt( nearest( e.clientX ) );
		} );
		hit.addEventListener( 'pointerleave', hide );
		hit.addEventListener( 'focus', function () {
			showAt( current );
		} );
		hit.addEventListener( 'blur', hide );
		hit.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'ArrowLeft' || e.key === 'ArrowRight' ) {
				e.preventDefault();
				showAt( current + ( e.key === 'ArrowLeft' ? -1 : 1 ) );
			}
		} );

		var rows = points.slice().reverse().map( function ( p ) {
			return [ fmtTime( p.t, withDay ) ].concat( keys.map( function ( k ) {
				return p[ k ] === null || p[ k ] === undefined ? '—' : fmt( p[ k ] );
			} ) );
		} );
		var tv = tableView( container, [ t( 'time', 'Time' ) ].concat( keys.map( function ( k ) {
			return SERIES[ k ].label;
		} ) ), rows );
		if ( tableOpen ) {
			tv.open = true;
		}
	}

	/* ------------------------------------------------------------ */
	/* Stacked columns (server use)                                  */
	/* ------------------------------------------------------------ */

	function topRounded( x0, y0, w, h, r ) {
		r = Math.min( r, w / 2, h );
		return 'M' + x0 + ' ' + ( y0 + h ) + 'V' + ( y0 + r ) + 'Q' + x0 + ' ' + y0 + ' ' + ( x0 + r ) + ' ' + y0 + 'H' + ( x0 + w - r ) + 'Q' + ( x0 + w ) + ' ' + y0 + ' ' + ( x0 + w ) + ' ' + ( y0 + r ) + 'V' + ( y0 + h ) + 'Z';
	}

	function columnChart( container, cols, keys, withDay, emptyText ) {
		var keepTable = container.querySelector( '.nms-tablev' );
		var tableOpen = keepTable && keepTable.open;
		container.textContent = '';
		legend( container, keys, true );

		var total = function ( c ) {
			return keys.reduce( function ( s, k ) {
				return s + ( c[ k ] || 0 );
			}, 0 );
		};
		if ( ! cols.length || ! cols.some( function ( c ) {
			return total( c ) > 0;
		} ) ) {
			container.appendChild( html( 'div', 'nms-empty', emptyText ) );
			return;
		}

		var W = Math.max( 280, container.clientWidth );
		var H = 230;
		var m = { l: 44, r: 16, t: 14, b: 26 };
		var pw = W - m.l - m.r;
		var ph = H - m.t - m.b;
		var peak = 0;
		cols.forEach( function ( c ) {
			peak = Math.max( peak, total( c ) );
		} );
		// Up to 100% at least once the server gets busy; a quiet site is zoomed in (the 70% line is then off the chart).
		var ax = axis( peak < 25 ? Math.max( peak * 1.25, 1 ) : Math.max( 95.3, peak ), false );
		var max = ax.max;
		var y = function ( v ) {
			return m.t + ph - ( v / max ) * ph;
		};
		var band = pw / cols.length;
		var bw = Math.max( 2, Math.min( 24, band - 2 ) );

		var svg = el( 'svg', { viewBox: '0 0 ' + W + ' ' + H, height: H, role: 'img', 'aria-label': container.getAttribute( 'aria-label' ) || '' } );
		for ( var gv = 0; gv <= max + ax.step / 1000; gv += ax.step ) {
			el( 'line', { x1: m.l, x2: W - m.r, y1: y( gv ), y2: y( gv ), stroke: gv ? '#e8ecea' : '#cdd5d1', 'stroke-width': 1, 'shape-rendering': 'crispEdges' }, svg );
			var lab = el( 'text', { x: m.l - 8, y: y( gv ) + 4, 'text-anchor': 'end', 'class': 'nms-tick' }, svg );
			lab.textContent = nf1.format( gv ) + '%';
		}
		if ( max >= 70 ) {
			el( 'line', { x1: m.l, x2: W - m.r, y1: y( 70 ), y2: y( 70 ), stroke: '#75827c', 'stroke-width': 1, 'shape-rendering': 'crispEdges' }, svg );
			var ref = el( 'text', { x: W - m.r, y: y( 70 ) - 5, 'text-anchor': 'end', 'class': 'nms-ref' }, svg );
			ref.textContent = t( 'smoothLine', 'Smooth up to 70%' );
		}
		var ticks = Math.min( 6, Math.max( 2, Math.floor( pw / 110 ) ) );
		for ( var i = 0; i <= ticks; i++ ) {
			var ci = Math.min( cols.length - 1, Math.round( ( ( cols.length - 1 ) * i ) / ticks ) );
			var xl = el( 'text', { x: m.l + band * ci + band / 2, y: H - 6, 'text-anchor': i === 0 ? 'start' : ( i === ticks ? 'end' : 'middle' ), 'class': 'nms-tick' }, svg );
			xl.textContent = fmtTime( cols[ ci ].t, withDay );
		}

		var tip;
		cols.forEach( function ( c, n ) {
			var cx = m.l + band * n + ( band - bw ) / 2;
			var grp = el( 'g', { 'class': 'nms-col' }, svg );
			var base = 0;
			var visible = keys.filter( function ( k ) {
				return ( c[ k ] || 0 ) > 0;
			} );
			visible.forEach( function ( k, idx ) {
				var v = c[ k ];
				var y0 = y( base + v );
				var h = y( base ) - y0;
				var isTop = idx === visible.length - 1;
				var gap = idx > 0 ? 2 : 0; // a 2px gap between stacked parts
				var hh = Math.max( 0, h - gap );
				if ( hh <= 0 ) {
					base += v;
					return;
				}
				var shape = isTop ? el( 'path', { d: topRounded( cx, y0, bw, hh, 4 ) }, grp ) : el( 'rect', { x: cx, y: y0, width: bw, height: hh }, grp );
				shape.style.fill = SERIES[ k ].color;
				base += v;
			} );
			var hitR = el( 'rect', { x: m.l + band * n, y: m.t, width: band, height: ph, 'class': 'nms-col-hit', tabindex: n === cols.length - 1 ? 0 : -1 }, svg );
			var show = function () {
				grp.classList.add( 'is-hover' );
				var rows = keys.map( function ( k ) {
					return { color: SERIES[ k ].color, value: nf1.format( c[ k ] || 0 ) + '%', label: SERIES[ k ].label };
				} );
				rows.push( { color: 'transparent', value: nf1.format( total( c ) ) + '%', label: t( 'total', 'in all' ) } );
				tip.show( m.l + band * n + band / 2, y( total( c ) ), fmtTime( c.t, withDay ), rows );
			};
			var hideIt = function () {
				grp.classList.remove( 'is-hover' );
				tip.hide();
			};
			hitR.addEventListener( 'pointerenter', show );
			hitR.addEventListener( 'pointerleave', hideIt );
			hitR.addEventListener( 'focus', show );
			hitR.addEventListener( 'blur', hideIt );
			hitR.addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'ArrowLeft' || e.key === 'ArrowRight' ) {
					e.preventDefault();
					var all = svg.querySelectorAll( '.nms-col-hit' );
					var next = all[ n + ( e.key === 'ArrowLeft' ? -1 : 1 ) ];
					if ( next ) {
						next.focus();
					}
				}
			} );
		} );
		container.appendChild( svg );
		tip = tooltip( container );

		var rows = cols.slice().reverse().map( function ( c ) {
			return [ fmtTime( c.t, withDay ) ].concat( keys.map( function ( k ) {
				return nf1.format( c[ k ] || 0 ) + '%';
			} ) );
		} );
		var tv = tableView( container, [ t( 'time', 'Time' ) ].concat( keys.map( function ( k ) {
			return SERIES[ k ].label;
		} ) ), rows );
		if ( tableOpen ) {
			tv.open = true;
		}
	}

	/* ------------------------------------------------------------ */
	/* Right now                                                     */
	/* ------------------------------------------------------------ */

	function renderLive( data ) {
		state.live = data;
		var now = data.now || {};
		setText( 'online', nf.format( now.online || 0 ) );
		setText( 'listening', nf.format( now.listening || 0 ) );
		setText( 'signed', nf.format( now.signed_in || 0 ) + ' ' + t( 'signedIn', 'signed in' ) );
		setText( 'use', nf.format( Math.round( data.use_now || 0 ) ) + '%' );
		setText( 'use-label', setMeter( 'use-meter', data.use_now || 0 ) + ' · ' + t( 'last3', 'last 3 minutes' ) );

		var cap = data.capacity || {};
		setText( 'peak', nf.format( cap.peak_online || 0 ) );
		setText( 'peak-at', cap.peak_at ? fmtTime( cap.peak_at, true ) : t( 'notYet', 'not yet' ) );
		setText( 'visit-smooth', cap.visit_smooth ? nf.format( cap.visit_smooth ) : '—' );
		setText( 'listen-smooth', cap.listen_smooth ? nf.format( cap.listen_smooth ) : '—' );
		setText( 'visit-max', cap.visit_max ? nf.format( cap.visit_max ) : '—' );
		setText( 'limit', cap.limit === 'cpu' ? t( 'cpu', 'CPU' ) : t( 'processes', 'Server processes' ) );
		var share = cap.visit_smooth ? ( 100 * ( now.online || 0 ) ) / cap.visit_smooth : 0;
		setMeter( 'cap-meter', share );
		setText( 'cap-now', cap.visit_smooth ? t( 'capNow', 'Now: %1 of %2 (%3%)' ).replace( '%1', nf.format( now.online || 0 ) ).replace( '%2', nf.format( cap.visit_smooth ) ).replace( '%3', nf1.format( share ) ) : '' );
		setText( 'basis', basisText( cap ) );

		var pages = q( 'pages' );
		if ( pages ) {
			pages.textContent = '';
			if ( ! now.pages || ! now.pages.length ) {
				pages.appendChild( html( 'li', 'nms-pages__empty', t( 'noPages', 'Nobody on the site right now (editors and administrators aren’t counted).' ) ) );
			} else {
				now.pages.forEach( function ( p ) {
					var li = html( 'li' );
					var path = html( 'span', 'nms-pages__path', p.path );
					path.title = p.path;
					li.appendChild( path );
					var count = nf.format( p.people ) + ' ' + ( p.people === 1 ? t( 'person', 'person' ) : t( 'people', 'people' ) );
					if ( p.listening ) {
						count += ', ' + nf.format( p.listening ) + ' ' + t( 'listening', 'listening' );
					}
					li.appendChild( html( 'span', 'nms-pages__count', count ) );
					pages.appendChild( li );
				} );
			}
		}

		var mon = data.monitor || {};
		var line = mon.last_run ? t( 'lastCheck', 'Last background check %1 ago.' ).replace( '%1', ago( mon.last_run ) ) : t( 'noCheck', 'The first background check hasn’t run yet.' );
		if ( mon.skipped ) {
			line += ' ' + t( 'skipped', 'Its test requests were skipped because the site was busy.' );
		}
		if ( mon.next_run ) {
			line += ' ' + t( 'nextCheck', 'Next in about %1.' ).replace( '%1', inTime( mon.next_run ) );
		}
		setText( 'monitor-line', line );

		drawLiveChart();
	}

	// The live chart is redrawn on every refresh, but not under the reader's pointer or
	// keyboard focus: that would take the tooltip away. It catches up when they leave.
	var liveChart = root.querySelector( '[data-nms-chart="live"]' );
	var livePending = false;
	function liveBusy() {
		return liveChart && ( liveChart.matches( ':hover' ) || liveChart.contains( document.activeElement ) );
	}
	function drawLiveChart() {
		if ( ! liveChart || ! state.live ) {
			return;
		}
		if ( liveBusy() ) {
			livePending = true;
			return;
		}
		livePending = false;
		lineChart( liveChart, state.live.minutes || [], [ 'online', 'listening' ], function ( v ) {
			return nf.format( Math.round( v ) );
		}, false, t( 'liveEmpty', 'Nobody has been on the site in the last hour. The line fills in as visitors come.' ), true );
	}
	if ( liveChart ) {
		[ 'pointerleave', 'focusout' ].forEach( function ( name ) {
			liveChart.addEventListener( name, function () {
				setTimeout( function () {
					if ( livePending && ! liveBusy() ) {
						drawLiveChart();
					}
				}, 50 );
			} );
		} );
	}

	function basisText( cap ) {
		if ( ! cap || cap.basis === 'none' || ! cap.visit_smooth ) {
			return t( 'basisNone', 'Not enough measurements yet. The first background check runs within 5 minutes of the update; the numbers fill in after it.' );
		}
		if ( cap.basis === 'measured' ) {
			return t( 'basisMeasured', 'Measured from %1 visitor-minutes in the last 7 days: one person browsing costs the server about %2 ms of work and %3 ms of CPU a minute; one listening, about %4 ms.' )
				.replace( '%1', nf.format( cap.visitor_minutes ) ).replace( '%2', nf.format( Math.round( cap.cost_visit_ms ) ) ).replace( '%3', nf.format( Math.round( cap.cost_visit_cpu ) ) ).replace( '%4', nf.format( Math.round( cap.cost_listen_ms ) ) );
		}
		return t( 'basisEstimate', 'An estimate for now: fewer than 2 hours of visitor time measured so far, so it uses the time a page takes to build from scratch (%1 ms), the visitor counter (%2 ms) and typical use (a page a minute, as often from the host’s cache as measured). It becomes a measurement as people visit.' )
			.replace( '%1', nf.format( cap.page_ms ) ).replace( '%2', nf1.format( cap.ping_ms ) );
	}

	function poll() {
		clearTimeout( state.timer );
		if ( document.visibilityState === 'hidden' ) {
			return; // Picked up again when the tab is back in view.
		}
		api( '/live' ).then( function ( data ) {
			state.failures = 0;
			root.classList.remove( 'is-stale' );
			renderLive( data );
			setText( 'updated', t( 'updated', 'Updated %1. Updates every 10 seconds while this page is open.' ).replace( '%1', fmtTime( data.time, false ) ) );
		} ).catch( function () {
			state.failures++;
			root.classList.add( 'is-stale' );
			setText( 'updated', t( 'failed', 'Couldn’t update just now; trying again.' ) );
		} ).then( function () {
			state.timer = setTimeout( poll, state.failures > 3 ? 30000 : 10000 );
		} );
	}

	/* ------------------------------------------------------------ */
	/* History                                                       */
	/* ------------------------------------------------------------ */

	function rebin( buckets, size ) {
		var out = [];
		for ( var i = 0; i < buckets.length; i += size ) {
			var part = buckets.slice( i, i + size );
			var c = { t: part[ 0 ].t };
			[ 'use_visits', 'use_bots', 'use_other' ].forEach( function ( k ) {
				c[ k ] = part.reduce( function ( s, b ) {
					return s + ( b[ k ] || 0 );
				}, 0 ) / part.length;
			} );
			out.push( c );
		}
		return out;
	}

	function renderHistory( data ) {
		state.history = data;
		var week = data.range === 'week';
		var withDay = true; // 24 hours span two days too, so every time says which.
		var b = data.buckets || [];
		var ms = function ( v, axis ) {
			return axis && v >= 1000 ? nf1.format( v / 1000 ) + ' s' : nf.format( Math.round( v ) ) + ' ms';
		};

		lineChart( root.querySelector( '[data-nms-chart="people"]' ), b, [ 'online', 'listening' ], function ( v ) {
			return nf1.format( v );
		}, withDay, t( 'peopleEmpty', 'No visitors counted in this time yet.' ), true );

		lineChart( root.querySelector( '[data-nms-chart="speed"]' ), b, [ 'page_ms', 'data_ms', 'probe_ms' ], ms, withDay, t( 'speedEmpty', 'No requests timed in this time yet.' ) );

		columnChart( root.querySelector( '[data-nms-chart="use"]' ), rebin( b, week ? 3 : 6 ), [ 'use_visits', 'use_bots', 'use_other' ], withDay, t( 'useEmpty', 'The server hasn’t been measured doing any work in this time yet.' ) );

		var table = q( 'busiest' );
		if ( table ) {
			table.textContent = '';
			var busiest = data.busiest || [];
			var thead = html( 'thead' );
			var tr = html( 'tr' );
			[ t( 'when', 'When' ), t( 'peopleCol', 'People (most)' ), t( 'listeningCol', 'Listening' ), t( 'useCol', 'Server in use' ), t( 'pageCol', 'Page build' ) ].forEach( function ( h, i ) {
				var th = html( 'th', i ? 'nms-num' : '', h );
				th.scope = 'col';
				tr.appendChild( th );
			} );
			thead.appendChild( tr );
			table.appendChild( thead );
			var tbody = html( 'tbody' );
			if ( ! busiest.length ) {
				var empty = html( 'tr' );
				var td = html( 'td', '', t( 'busyEmpty', 'Nothing measured in this time yet.' ) );
				td.colSpan = 5;
				empty.appendChild( td );
				tbody.appendChild( empty );
			}
			busiest.forEach( function ( r ) {
				var row = html( 'tr' );
				var th = html( 'th', '', fmtTime( r.t, true ) );
				th.scope = 'row';
				row.appendChild( th );
				row.appendChild( html( 'td', 'nms-num', nf1.format( r.online ) + ' (' + nf.format( r.peak ) + ')' ) );
				row.appendChild( html( 'td', 'nms-num', nf1.format( r.listening ) ) );
				row.appendChild( html( 'td', 'nms-num', nf1.format( r.use_visits + r.use_bots + r.use_other ) + '%' ) );
				row.appendChild( html( 'td', 'nms-num', r.page_ms === null ? '—' : nf.format( r.page_ms ) + ' ms' ) );
				tbody.appendChild( row );
			} );
			table.appendChild( tbody );
		}
	}

	function loadHistory( range ) {
		state.range = range;
		var grid = root.querySelector( '[data-nms-history]' );
		if ( grid ) {
			grid.classList.add( 'is-loading' ); // keeps the old charts, dimmed, until the new ones arrive
		}
		api( '/history?range=' + encodeURIComponent( range ) ).then( function ( data ) {
			if ( data.range === state.range ) {
				renderHistory( data );
			}
		} ).catch( function () {} ).then( function () {
			if ( grid ) {
				grid.classList.remove( 'is-loading' );
			}
		} );
	}

	/* ------------------------------------------------------------ */
	/* Controls                                                      */
	/* ------------------------------------------------------------ */

	Array.prototype.forEach.call( root.querySelectorAll( '[data-nms-range]' ), function ( btn ) {
		btn.addEventListener( 'click', function () {
			Array.prototype.forEach.call( root.querySelectorAll( '[data-nms-range]' ), function ( b ) {
				var on = b === btn;
				b.classList.toggle( 'is-active', on );
				b.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
			} );
			loadHistory( btn.getAttribute( 'data-nms-range' ) );
		} );
	} );

	var check = root.querySelector( '[data-nms-check]' );
	if ( check ) {
		check.addEventListener( 'click', function () {
			check.disabled = true;
			var label = check.lastChild;
			var before = label.textContent;
			label.textContent = t( 'checking', 'Checking… (about 10 seconds)' );
			api( '/check', { method: 'POST' } ).then( function () {
				window.location.reload();
			} ).catch( function ( e ) {
				check.disabled = false;
				label.textContent = t( 'checkFailed', 'The check didn’t finish: ' ) + e.message;
				setTimeout( function () {
					label.textContent = before;
				}, 8000 );
			} );
		} );
	}

	var form = root.querySelector( '[data-nms-settings]' );
	if ( form ) {
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			setText( 'settings-msg', t( 'saving', 'Saving…' ) );
			api( '/settings', {
				method: 'POST',
				body: { processes: parseInt( form.processes.value, 10 ), cores: parseFloat( form.cores.value ) }
			} ).then( function ( data ) {
				setText( 'settings-msg', t( 'saved', 'Saved. The numbers above now use these limits.' ) );
				if ( state.live ) {
					state.live.capacity = data.capacity;
					renderLive( state.live );
				}
			} ).catch( function ( err ) {
				setText( 'settings-msg', err.message );
			} );
		} );
	}

	document.addEventListener( 'visibilitychange', function () {
		if ( document.visibilityState === 'visible' ) {
			poll();
		}
	} );

	var resizeTimer = null;
	window.addEventListener( 'resize', function () {
		clearTimeout( resizeTimer );
		resizeTimer = setTimeout( function () {
			if ( state.live ) {
				renderLive( state.live );
			}
			if ( state.history ) {
				renderHistory( state.history );
			}
		}, 200 );
	} );

	try {
		renderLive( JSON.parse( root.getAttribute( 'data-nms-initial' ) || '{}' ) );
	} catch ( e ) {}
	state.timer = setTimeout( poll, 10000 );
	loadHistory( 'day' );
} )();
