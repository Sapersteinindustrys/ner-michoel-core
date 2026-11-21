/* Experiments > Shiur audio cleanup. Talks to /ner-michoel/v1/audio-cleanup/*. */
( function () {
	'use strict';
	var root = document.getElementById( 'nm-ac' );
	if ( ! root || ! window.wp || ! wp.apiFetch ) {
		return;
	}
	var cfg = window.nmAudioCleanup || {};
	var api = function ( path, opts ) {
		opts = opts || {};
		opts.path = '/ner-michoel/v1/audio-cleanup' + path;
		return wp.apiFetch( opts );
	};
	var $ = function ( id ) {
		return document.getElementById( id );
	};
	var el = function ( tag, attrs, kids ) {
		var n = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			if ( k === 'text' ) {
				n.textContent = attrs[ k ];
			} else if ( k === 'class' ) {
				n.className = attrs[ k ];
			} else if ( k.indexOf( 'on' ) === 0 ) {
				n.addEventListener( k.slice( 2 ), attrs[ k ] );
			} else if ( attrs[ k ] !== false && attrs[ k ] !== null && attrs[ k ] !== undefined ) {
				n.setAttribute( k, attrs[ k ] === true ? '' : attrs[ k ] );
			}
		} );
		( kids || [] ).forEach( function ( c ) {
			if ( c ) {
				n.appendChild( typeof c === 'string' ? document.createTextNode( c ) : c );
			}
		} );
		return n;
	};
	var errText = function ( e ) {
		return ( e && e.message ) || 'Something went wrong.';
	};

	/* ---------- 1. pick a recording ---------- */
	var state = { src: 'upload', files: [], shiur: null };

	[ 'nm-ac-strength', 'nm-ac-auto-strength' ].forEach( function ( id ) {
		Object.keys( cfg.strengths || {} ).forEach( function ( k ) {
			$( id ).appendChild( el( 'option', { value: k, text: cfg.strengths[ k ] } ) );
		} );
	} );
	$( 'nm-ac-strength' ).value = 'normal';
	$( 'nm-ac-helper-dl' ).href = cfg.helperUrl || '#';

	function canGo() {
		$( 'nm-ac-go' ).disabled = state.src === 'upload' ? ! state.files.length : ! state.shiur;
	}

	root.querySelectorAll( 'input[name="nm-ac-src"]' ).forEach( function ( r ) {
		r.addEventListener( 'change', function () {
			state.src = r.value;
			root.querySelectorAll( '.nm-ac__panel' ).forEach( function ( p ) {
				p.hidden = p.getAttribute( 'data-for' ) !== state.src;
			} );
			if ( state.src === 'shiur' && ! $( 'nm-ac-shiurim' ).children.length ) {
				searchShiurim( '' );
			}
			canGo();
		} );
	} );

	function showFiles() {
		var ul = $( 'nm-ac-files' );
		ul.innerHTML = '';
		state.files.forEach( function ( f, i ) {
			ul.appendChild(
				el( 'li', {}, [
					el( 'span', { text: f.title || f.filename } ),
					f.filesizeHumanReadable ? el( 'small', { text: ' ' + f.filesizeHumanReadable } ) : null,
					el( 'button', {
						type: 'button',
						class: 'button-link',
						text: 'Remove',
						onclick: function () {
							state.files.splice( i, 1 );
							showFiles();
						},
					} ),
				] )
			);
		} );
		if ( state.files.length === 2 ) {
			ul.appendChild( el( 'li', { class: 'nm-ac__hint', text: 'Two files: treated as two tracks of one recording. The better one is used.' } ) );
		}
		canGo();
	}

	var frame;
	$( 'nm-ac-upload' ).addEventListener( 'click', function () {
		if ( ! frame ) {
			frame = wp.media( {
				title: 'Recording to clean (one file, or the two tracks of one recording)',
				button: { text: 'Use these' },
				library: { type: 'audio' },
				multiple: true,
			} );
			frame.on( 'select', function () {
				state.files = frame.state().get( 'selection' ).toJSON().slice( 0, 2 );
				showFiles();
			} );
		}
		frame.open();
	} );

	var searchTimer;
	function searchShiurim( q ) {
		var ul = $( 'nm-ac-shiurim' );
		ul.innerHTML = '<li class="nm-ac__hint">Searching…</li>';
		api( '/shiurim?search=' + encodeURIComponent( q ) )
			.then( function ( r ) {
				ul.innerHTML = '';
				if ( ! r.shiurim.length ) {
					ul.appendChild( el( 'li', { class: 'nm-ac__hint', text: 'No shiurim with audio found.' } ) );
				}
				r.shiurim.forEach( function ( s ) {
					var li = el(
						'li',
						{
							role: 'option',
							tabindex: '0',
							'aria-selected': state.shiur === s.id ? 'true' : 'false',
						},
						[
							el( 'strong', { text: s.title } ),
							el( 'span', { text: [ s.speaker, s.date, s.duration ].filter( Boolean ).join( ' · ' ) } ),
						]
					);
					var pick = function () {
						state.shiur = s.id;
						ul.querySelectorAll( 'li' ).forEach( function ( x ) {
							x.setAttribute( 'aria-selected', 'false' );
						} );
						li.setAttribute( 'aria-selected', 'true' );
						canGo();
					};
					li.addEventListener( 'click', pick );
					li.addEventListener( 'keydown', function ( e ) {
						if ( e.key === 'Enter' || e.key === ' ' ) {
							e.preventDefault();
							pick();
						}
					} );
					ul.appendChild( li );
				} );
			} )
			.catch( function ( e ) {
				ul.innerHTML = '';
				ul.appendChild( el( 'li', { class: 'nm-ac__hint', text: errText( e ) } ) );
			} );
	}
	$( 'nm-ac-search' ).addEventListener( 'input', function () {
		clearTimeout( searchTimer );
		var q = this.value;
		searchTimer = setTimeout( function () {
			searchShiurim( q );
		}, 300 );
	} );

	$( 'nm-ac-go' ).addEventListener( 'click', function () {
		var btn = this;
		var data = {
			strength: $( 'nm-ac-strength' ).value,
			limit_seconds: parseInt( $( 'nm-ac-limit' ).value, 10 ),
			normalize: $( 'nm-ac-normalize' ).checked,
			shorten_silence: $( 'nm-ac-silence' ).checked,
		};
		if ( state.src === 'upload' ) {
			data.attachment_ids = state.files.map( function ( f ) {
				return f.id;
			} );
		} else {
			data.shiur_id = state.shiur;
		}
		btn.disabled = true;
		$( 'nm-ac-go-note' ).textContent = 'Sending…';
		api( '/jobs', { method: 'POST', data: data } )
			.then( function () {
				$( 'nm-ac-go-note' ).textContent = 'Sent. It shows up below; it takes a few minutes.';
				state.files = [];
				showFiles();
				load();
			} )
			.catch( function ( e ) {
				$( 'nm-ac-go-note' ).textContent = errText( e );
				canGo();
			} );
	} );

	/* ---------- 2. results ---------- */
	var STATUS = {
		queued: 'Waiting to start',
		working: 'Cleaning…',
		done: 'Ready',
		failed: 'Did not work',
	};

	function mb( bytes ) {
		return bytes ? ( bytes / 1048576 ).toFixed( 1 ) + ' MB' : '';
	}
	function mins( s ) {
		s = Math.round( s || 0 );
		return Math.floor( s / 60 ) + ':' + ( '0' + ( s % 60 ) ).slice( -2 );
	}

	function reportLines( r, job ) {
		var out = [];
		if ( ! r ) {
			return out;
		}
		if ( r.tracks && r.tracks.length > 1 ) {
			if ( r.same_tracks ) {
				out.push( 'The two tracks are the same recording; used one of them.' );
			} else {
				var others = r.tracks.filter( function ( t ) {
					return t.label !== r.picked;
				} );
				var best = r.tracks.filter( function ( t ) {
					return t.label === r.picked;
				} )[ 0 ];
				var gap = best && others.length ? Math.round( best.score - Math.max.apply( null, others.map( function ( t ) { return t.score; } ) ) ) : 0;
				var name = r.picked;
				if ( job && job.before.length > 1 && job.before[ r.picked_source ] ) {
					name += ' (' + job.before[ r.picked_source ].title + ')';
				}
				out.push( 'Used the ' + name + ( gap > 0 ? ': clearer than the other by ' + gap + ' dB.' : '.' ) );
			}
		}
		if ( r.before && r.after ) {
			var drop = Math.round( r.before.noise_db - r.after.noise_db - ( r.before.voice_db - r.after.voice_db ) );
			out.push( 'Background noise compared to the voice: ' + Math.round( r.before.snr_db ) + ' dB before, ' + Math.round( r.after.snr_db ) + ' dB after' + ( drop > 0 ? ' (' + drop + ' dB quieter).' : '.' ) );
		}
		if ( r.bytes_in && r.bytes_out ) {
			out.push( 'Length ' + mins( r.seconds_out ) + '. Size ' + mb( r.bytes_in ) + ' → ' + mb( r.bytes_out ) + ' (' + r.bitrate_kbps + ' kbps mono MP3).' );
		}
		if ( r.engine ) {
			out.push( 'Cleaned with ' + r.engine + ', strength ' + r.strength + ', in ' + Math.round( r.work_seconds ) + ' seconds.' );
		}
		return out;
	}

	/* One player with a Before / After switch that keeps the place. */
	function compare( beforeUrl, afterUrl ) {
		var a = el( 'audio', { preload: 'metadata', src: beforeUrl } );
		var b = el( 'audio', { preload: 'metadata', src: afterUrl } );
		var which = 'after';
		var cur = function () {
			return which === 'after' ? b : a;
		};
		var play = el( 'button', { type: 'button', class: 'button nm-ac__play', text: '▶ Play' } );
		var bBefore = el( 'button', { type: 'button', class: 'button', 'aria-pressed': 'false', text: 'Before' } );
		var bAfter = el( 'button', { type: 'button', class: 'button button-primary', 'aria-pressed': 'true', text: 'After' } );
		var seek = el( 'input', { type: 'range', min: '0', max: '1000', value: '0', 'aria-label': 'Position' } );
		var time = el( 'span', { class: 'nm-ac__time', text: '0:00' } );
		function sync() {
			var p = cur();
			play.textContent = p.paused ? '▶ Play' : '❚❚ Pause';
			if ( p.duration ) {
				seek.value = String( Math.round( ( p.currentTime / p.duration ) * 1000 ) );
			}
			time.textContent = mins( p.currentTime ) + ( p.duration ? ' / ' + mins( p.duration ) : '' );
		}
		[ a, b ].forEach( function ( p ) {
			[ 'timeupdate', 'play', 'pause', 'loadedmetadata', 'ended' ].forEach( function ( ev ) {
				p.addEventListener( ev, sync );
			} );
		} );
		play.addEventListener( 'click', function () {
			var p = cur();
			if ( p.paused ) {
				p.play();
			} else {
				p.pause();
			}
		} );
		function to( w ) {
			if ( w === which ) {
				return;
			}
			var from = cur();
			var wasPlaying = ! from.paused;
			which = w;
			var next = cur();
			var at = from.currentTime;
			from.pause();
			var go = function () {
				try {
					next.currentTime = Math.min( at, next.duration || at );
				} catch ( e ) {}
				if ( wasPlaying ) {
					next.play();
				}
			};
			if ( next.readyState >= 1 ) {
				go();
			} else {
				next.addEventListener( 'loadedmetadata', go, { once: true } );
				next.load();
			}
			bBefore.className = 'button' + ( w === 'before' ? ' button-primary' : '' );
			bAfter.className = 'button' + ( w === 'after' ? ' button-primary' : '' );
			bBefore.setAttribute( 'aria-pressed', w === 'before' ? 'true' : 'false' );
			bAfter.setAttribute( 'aria-pressed', w === 'after' ? 'true' : 'false' );
			sync();
		}
		bBefore.addEventListener( 'click', function () {
			to( 'before' );
		} );
		bAfter.addEventListener( 'click', function () {
			to( 'after' );
		} );
		seek.addEventListener( 'input', function () {
			var p = cur();
			if ( p.duration ) {
				p.currentTime = ( seek.value / 1000 ) * p.duration;
			}
		} );
		return el( 'div', { class: 'nm-ac__compare' }, [
			el( 'div', { class: 'nm-ac__ab' }, [ play, el( 'span', { class: 'nm-ac__switch', role: 'group', 'aria-label': 'Listen to' }, [ bBefore, bAfter ] ), seek, time ] ),
			el( 'p', { class: 'nm-ac__hint', text: 'Press Before / After while it plays: it switches at the same place.' } ),
			el( 'div', { class: 'nm-ac__two' }, [
				el( 'div', {}, [ el( 'strong', { text: 'Before' } ), el( 'audio', { controls: true, preload: 'none', src: beforeUrl } ) ] ),
				el( 'div', {}, [ el( 'strong', { text: 'After' } ), el( 'audio', { controls: true, preload: 'none', src: afterUrl } ) ] ),
			] ),
			a,
			b,
		] );
	}

	function action( job, what, label ) {
		return el( 'button', {
			type: 'button',
			class: 'button',
			text: label,
			onclick: function () {
				if ( what === 'delete' && ! window.confirm( 'Delete this result and its cleaned file?' ) ) {
					return;
				}
				var req = what === 'delete' ? api( '/jobs/' + job.id, { method: 'DELETE' } ) : api( '/jobs/' + job.id + '/' + what, { method: 'POST' } );
				req.then( load ).catch( function ( e ) {
					window.alert( errText( e ) );
				} );
			},
		} );
	}

	var shown = {};
	function card( job ) {
		var opts = job.options || {};
		var head = el( 'div', { class: 'nm-ac__head' }, [
			el( 'span', { class: 'nm-ac__pill is-' + job.status, text: STATUS[ job.status ] || job.status } ),
			el( 'strong', { text: job.title || 'Recording' } ),
			el( 'span', {
				class: 'nm-ac__meta',
				text: [ job.kind === 'auto' ? 'New shiur, automatic' : 'Tried here', opts.limit_seconds ? 'first ' + Math.round( opts.limit_seconds / 60 ) + ' min' : 'whole recording', job.created_at ].join( ' · ' ),
			} ),
		] );
		var kids = [ head ];
		if ( job.status === 'done' && job.after && job.before.length ) {
			var r = job.report || {};
			var before = job.before[ 0 ].url;
			if ( job.before.length > 1 && job.before[ r.picked_source ] ) {
				before = job.before[ r.picked_source ].url; // two files: compare against the one that was used
			}
			kids.push( compare( before, job.after ) );
			var lines = reportLines( r, job );
			if ( lines.length ) {
				kids.push( el( 'ul', { class: 'nm-ac__report' }, lines.map( function ( l ) { return el( 'li', { text: l } ); } ) ) );
			}
		}
		if ( job.status === 'failed' && job.message ) {
			kids.push( el( 'p', { class: 'nm-ac__error', text: job.message } ) );
		}
		var acts = [];
		if ( job.status === 'done' ) {
			acts.push( el( 'a', { class: 'button', href: job.after, download: true, text: 'Download cleaned' } ) );
			if ( job.shiur_id && ! opts.limit_seconds ) {
				acts.push( job.applied ? action( job, 'use-original', 'Use the original again' ) : action( job, 'use-cleaned', 'Let listeners hear the cleaned version' ) );
			}
		}
		if ( job.applied ) {
			kids.push( el( 'p', { class: 'nm-ac__live', text: 'Listeners hear the cleaned version of this shiur.' } ) );
		}
		if ( ( job.status === 'done' || job.status === 'failed' ) && ! job.applied ) {
			acts.push( action( job, 'again', 'Run again' ) );
		}
		if ( job.shiur_url ) {
			acts.push( el( 'a', { class: 'button-link', href: job.shiur_url, target: '_blank', rel: 'noopener', text: 'Open the shiur' } ) );
		}
		acts.push( action( job, 'delete', 'Delete' ) );
		kids.push( el( 'p', { class: 'nm-ac__actions' }, acts ) );
		return el( 'article', { class: 'nm-ac__job', 'data-id': job.id }, kids );
	}

	function runnerText( st ) {
		var h = st.helper;
		var k = st.kaggle;
		if ( ! h.waiting ) {
			return '';
		}
		var t = h.waiting + ( h.waiting === 1 ? ' recording is' : ' recordings are' ) + ' waiting or being cleaned. ';
		if ( k.enabled && k.has_token ) {
			t += 'Kaggle: ' + ( k.last_status || 'not started yet' ).toLowerCase().replace( /_/g, ' ' ) + ( k.last_push ? ' (started ' + k.last_push + ' ago)' : '' ) + '. Starting on Kaggle takes 2 to 4 minutes. ';
		} else if ( ! h.seen_own ) {
			t += 'Nothing is set up to do the cleaning yet: see “Where the cleaning runs” below. ';
		}
		if ( h.seen ) {
			t += 'The helper last asked for work ' + h.seen + ' ago.';
		}
		return t;
	}

	var timer;
	var settingsShown = false;
	function load() {
		clearTimeout( timer );
		return api( '/jobs' )
			.then( function ( r ) {
				var box = $( 'nm-ac-results' );
				var anyPlaying = Array.prototype.some.call( box.querySelectorAll( 'audio' ), function ( x ) {
					return ! x.paused;
				} );
				// Don't rebuild while someone is listening: only refresh when something changed.
				var sig = JSON.stringify( r.jobs.map( function ( j ) { return [ j.id, j.status, j.applied ]; } ) );
				if ( ! anyPlaying && sig !== shown.sig ) {
					box.innerHTML = '';
					if ( ! r.jobs.length ) {
						box.appendChild( el( 'p', { class: 'nm-ac__empty', text: 'Nothing yet. Upload or pick a recording above.' } ) );
					}
					r.jobs.forEach( function ( j ) {
						box.appendChild( card( j ) );
					} );
					shown.sig = sig;
				}
				$( 'nm-ac-runner' ).textContent = runnerText( r.status );
				showStatus( r.status );
				if ( r.status.helper.waiting ) {
					timer = setTimeout( load, 15000 );
				}
			} )
			.catch( function ( e ) {
				$( 'nm-ac-results' ).textContent = errText( e );
			} );
	}

	/* ---------- 3 + 4. settings ---------- */
	function showStatus( st ) {
		if ( ! settingsShown ) {
			var s = st.settings;
			var r = root.querySelector( 'input[name="nm-ac-auto"][value="' + s.auto + '"]' );
			if ( r ) {
				r.checked = true;
			}
			$( 'nm-ac-auto-strength' ).value = s.strength;
			$( 'nm-ac-bitrate' ).value = String( s.bitrate );
			$( 'nm-ac-kg-on' ).checked = !! st.kaggle.enabled;
			$( 'nm-ac-kg-user' ).value = st.kaggle.username || '';
			$( 'nm-ac-kg-token' ).placeholder = st.kaggle.has_token ? 'Saved (type a new one to change it)' : '';
			settingsShown = true;
		}
		var k = st.kaggle;
		var t = '';
		if ( k.last_status ) {
			t = 'Last run: ' + k.last_status.toLowerCase().replace( /_/g, ' ' ) + ( k.last_push ? ', started ' + k.last_push + ' ago' : '' ) + '.';
		}
		if ( k.message ) {
			t += ' ' + k.message;
		}
		var note = $( 'nm-ac-kg-note' );
		note.textContent = t + ' ';
		if ( k.url && k.last_push ) {
			note.appendChild( el( 'a', { href: k.url, target: '_blank', rel: 'noopener', text: 'See it on Kaggle' } ) );
		}
		$( 'nm-ac-key-note' ).textContent = st.settings.has_key ? 'A key exists. Making a new one stops the old one.' : '';
	}

	$( 'nm-ac-auto-save' ).addEventListener( 'click', function () {
		var picked = root.querySelector( 'input[name="nm-ac-auto"]:checked' );
		$( 'nm-ac-auto-note' ).textContent = 'Saving…';
		api( '/settings', {
			method: 'POST',
			data: {
				auto: picked ? picked.value : 'off',
				strength: $( 'nm-ac-auto-strength' ).value,
				bitrate: parseInt( $( 'nm-ac-bitrate' ).value, 10 ),
			},
		} )
			.then( function ( st ) {
				$( 'nm-ac-auto-note' ).textContent = st.settings.auto === 'off' ? 'Saved. Automatic cleanup is off.' : 'Saved. New shiurim will be cleaned.';
			} )
			.catch( function ( e ) {
				$( 'nm-ac-auto-note' ).textContent = errText( e );
			} );
	} );

	function kaggle( data, msg ) {
		$( 'nm-ac-kg-note' ).textContent = msg;
		return api( '/kaggle', { method: 'POST', data: data } )
			.then( function ( r ) {
				$( 'nm-ac-kg-token' ).value = '';
				settingsShown = false;
				showStatus( r.status );
				load();
				return r;
			} )
			.catch( function ( e ) {
				$( 'nm-ac-kg-note' ).textContent = errText( e );
			} );
	}
	$( 'nm-ac-kg-save' ).addEventListener( 'click', function () {
		kaggle( { enabled: $( 'nm-ac-kg-on' ).checked, username: $( 'nm-ac-kg-user' ).value, token: $( 'nm-ac-kg-token' ).value }, 'Saving…' );
	} );
	$( 'nm-ac-kg-start' ).addEventListener( 'click', function () {
		kaggle( { start: true }, 'Starting…' ).then( function ( r ) {
			if ( r && ! r.started ) {
				$( 'nm-ac-kg-note' ).textContent = 'Nothing to start: no recordings are waiting, or Kaggle is not switched on and saved.';
			}
		} );
	} );
	$( 'nm-ac-kg-check' ).addEventListener( 'click', function () {
		kaggle( { check: true }, 'Checking…' );
	} );

	$( 'nm-ac-key' ).addEventListener( 'click', function () {
		if ( ! window.confirm( 'Make a new helper key? A computer using the old key stops working until it gets the new one.' ) ) {
			return;
		}
		api( '/helper-key', { method: 'POST' } )
			.then( function ( r ) {
				var pre = $( 'nm-ac-cmd' );
				pre.hidden = false;
				pre.textContent =
					'Copy this now; the key is not shown again.\n\n' +
					'python audio-cleanup-helper.py --site ' + cfg.siteUrl + ' --key ' + r.key + '\n\n' +
					'Needs Python 3.11, ffmpeg, and: pip install "numpy<2" torch torchaudio deepfilternet==0.5.6';
				$( 'nm-ac-key-note' ).textContent = 'New key made.';
			} )
			.catch( function ( e ) {
				$( 'nm-ac-key-note' ).textContent = errText( e );
			} );
	} );

	load();
} )();
