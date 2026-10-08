/**
 * Site Control Panel shell (includes/custom-admin.php): the sidebar, the
 * Help drawer, Ctrl+K search, the first-visit guided tour, the Home
 * "Getting started" list, and the toasts and confirm dialogs the content
 * and settings screens use (window.NMAdmin).
 *
 * Per-person state (tour seen, checklist hidden, search tried) lives in
 * localStorage, keyed by user ID. Nothing here talks to the server.
 */
( function ( $ ) {
	'use strict';

	var cfg = window.nmAdminShell || {};
	var isMac = /Mac|iPhone|iPad/.test( navigator.platform || navigator.userAgent || '' );

	/* ---------------- Small helpers ---------------- */

	function esc( str ) {
		return String( str === null || str === undefined ? '' : str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#39;' );
	}

	function icon( name, cls ) {
		return '<svg class="nm-ico' + ( cls ? ' ' + cls : '' ) + '" aria-hidden="true" focusable="false"><use href="#nm-i-' + name + '"></use></svg>';
	}

	var store = {
		key: function ( k ) {
			return 'nmAdmin:' + ( cfg.userId || 0 ) + ':' + k;
		},
		get: function ( k ) {
			try {
				return window.localStorage.getItem( this.key( k ) );
			} catch ( e ) {
				return null;
			}
		},
		set: function ( k, v ) {
			try {
				window.localStorage.setItem( this.key( k ), v );
			} catch ( e ) {}
		},
		remove: function ( k ) {
			try {
				window.localStorage.removeItem( this.key( k ) );
			} catch ( e ) {}
		}
	};

	// Keeps Tab inside an open overlay; returns a function that releases it.
	function trapFocus( el ) {
		function onKey( e ) {
			if ( e.key !== 'Tab' ) {
				return;
			}
			var items = $( el ).find( 'a[href], button:not([disabled]), input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])' ).filter( ':visible' );
			if ( ! items.length ) {
				return;
			}
			var first = items[ 0 ];
			var last = items[ items.length - 1 ];
			if ( e.shiftKey && document.activeElement === first ) {
				e.preventDefault();
				last.focus();
			} else if ( ! e.shiftKey && document.activeElement === last ) {
				e.preventDefault();
				first.focus();
			}
		}
		el.addEventListener( 'keydown', onKey );
		return function () {
			el.removeEventListener( 'keydown', onKey );
		};
	}

	/* ---------------- Toasts ---------------- */

	var $toasts = null;

	function toast( message, type, ms ) {
		type = type || 'success';
		if ( ! $toasts ) {
			$toasts = $( '<div class="nm-toasts" role="status" aria-live="polite"></div>' ).appendTo( document.body );
		}
		var iconName = type === 'error' ? 'x' : ( type === 'info' ? 'info' : 'check' );
		var $t = $(
			'<div class="nm-toast nm-toast--' + esc( type ) + '">' +
				'<span class="nm-toast__icon">' + icon( iconName ) + '</span>' +
				'<span class="nm-toast__text"></span>' +
				'<button type="button" class="nm-toast__close" aria-label="Dismiss">' + icon( 'x' ) + '</button>' +
			'</div>'
		);
		$t.find( '.nm-toast__text' ).text( message );
		$toasts.append( $t );

		var timer;
		function dismiss() {
			clearTimeout( timer );
			$t.addClass( 'is-leaving' );
			setTimeout( function () {
				$t.remove();
			}, 260 );
		}
		$t.find( '.nm-toast__close' ).on( 'click', dismiss );
		timer = setTimeout( dismiss, ms || ( type === 'error' ? 7000 : 3800 ) );
		return dismiss;
	}

	/* ---------------- Confirm dialog ---------------- */

	/**
	 * opts: title, message, confirmText, cancelText, danger, icon.
	 * Resolves true (confirmed) or false.
	 */
	function confirmDialog( opts ) {
		opts = opts || {};
		return new Promise( function ( resolve ) {
			var previous = document.activeElement;
			var $wrap = $(
				'<div class="nm-dialog-wrap">' +
					'<div class="nm-dialog' + ( opts.danger ? ' nm-dialog--danger' : '' ) + '" role="alertdialog" aria-modal="true" aria-labelledby="nm-dialog-title" aria-describedby="nm-dialog-msg">' +
						'<div class="nm-dialog__icon">' + icon( opts.icon || ( opts.danger ? 'trash' : 'help' ) ) + '</div>' +
						'<h3 id="nm-dialog-title"></h3>' +
						'<p id="nm-dialog-msg"></p>' +
						'<div class="nm-dialog__foot">' +
							'<button type="button" class="nm-btn" data-no></button>' +
							'<button type="button" class="nm-btn ' + ( opts.danger ? 'nm-btn--danger' : 'nm-btn--primary' ) + '" data-yes></button>' +
						'</div>' +
					'</div>' +
				'</div>'
			);
			$wrap.find( 'h3' ).text( opts.title || 'Are you sure?' );
			$wrap.find( 'p' ).text( opts.message || '' );
			$wrap.find( '[data-no]' ).text( opts.cancelText || 'Cancel' );
			$wrap.find( '[data-yes]' ).text( opts.confirmText || 'Yes' );
			$wrap.appendTo( document.body );
			var release = trapFocus( $wrap[ 0 ] );

			function done( result ) {
				release();
				$( document ).off( 'keydown.nmdialog' );
				$wrap.remove();
				if ( previous && previous.focus ) {
					previous.focus();
				}
				resolve( result );
			}
			$wrap.find( '[data-yes]' ).on( 'click', function () {
				done( true );
			} );
			$wrap.find( '[data-no]' ).on( 'click', function () {
				done( false );
			} );
			$wrap.on( 'mousedown', function ( e ) {
				if ( e.target === $wrap[ 0 ] ) {
					done( false );
				}
			} );
			$( document ).on( 'keydown.nmdialog', function ( e ) {
				if ( e.key === 'Escape' ) {
					e.stopImmediatePropagation();
					done( false );
				}
			} );
			// The safe choice gets focus on a destructive dialog.
			$wrap.find( opts.danger ? '[data-no]' : '[data-yes]' ).trigger( 'focus' );
		} );
	}

	/* ---------------- Sidebar ---------------- */

	var $app = $( '#nm-app' );
	var sidebarOpenedByTour = false;

	function openSidebar() {
		$app.addClass( 'is-nav-open' );
		$( '.nm-scrim' ).prop( 'hidden', false );
	}

	function closeSidebar() {
		$app.removeClass( 'is-nav-open' );
		$( '.nm-scrim' ).prop( 'hidden', true );
	}

	function isMobileNav() {
		return window.matchMedia && window.matchMedia( '(max-width: 960px)' ).matches;
	}

	function bindSidebar() {
		$( document ).on( 'click', '[data-sidebar-open]', openSidebar );
		$( document ).on( 'click', '[data-sidebar-close]', closeSidebar );

		$( '.nm-nav__group-btn' ).on( 'click', function () {
			var $btn = $( this );
			var $group = $btn.closest( '.nm-nav__group' );
			var open = $btn.attr( 'aria-expanded' ) !== 'true';
			$btn.attr( 'aria-expanded', open ? 'true' : 'false' );
			$group.toggleClass( 'is-open', open ).find( '.nm-nav__items' ).prop( 'hidden', ! open );
		} );
	}

	/* ---------------- Top bar ---------------- */

	function bindTopbar() {
		var $bar = $( '.nm-topbar' );
		function onScroll() {
			$bar.toggleClass( 'is-scrolled', window.scrollY > 4 );
		}
		$( window ).on( 'scroll', onScroll );
		onScroll();

		$( '[data-kbd-hint]' ).text( isMac ? '⌘ K' : 'Ctrl K' );

		var $menuBtn = $( '[data-user-menu]' );
		var $menu = $( '#nm-user-menu' );
		$menuBtn.on( 'click', function ( e ) {
			e.stopPropagation();
			var open = $menu.prop( 'hidden' );
			$menu.prop( 'hidden', ! open );
			$menuBtn.attr( 'aria-expanded', open ? 'true' : 'false' );
		} );
		$( document ).on( 'click', function ( e ) {
			if ( ! $menu.prop( 'hidden' ) && ! $( e.target ).closest( '.nm-user' ).length ) {
				$menu.prop( 'hidden', true );
				$menuBtn.attr( 'aria-expanded', 'false' );
			}
		} );
		$menu.on( 'click', 'button', function () {
			$menu.prop( 'hidden', true );
			$menuBtn.attr( 'aria-expanded', 'false' );
		} );
	}

	/* ---------------- Help drawer ---------------- */

	var helpRelease = null;
	var helpReturn = null;

	function openHelp() {
		var $help = $( '#nm-help' );
		if ( ! $help.length ) {
			return;
		}
		helpReturn = document.activeElement;
		$help.prop( 'hidden', false );
		$( '.nm-help-backdrop' ).prop( 'hidden', false );
		helpRelease = trapFocus( $help[ 0 ] );
		$help.find( '[data-help-close]' ).first().trigger( 'focus' );
	}

	function closeHelp() {
		var $help = $( '#nm-help' );
		if ( $help.prop( 'hidden' ) ) {
			return;
		}
		$help.prop( 'hidden', true );
		$( '.nm-help-backdrop' ).prop( 'hidden', true );
		if ( helpRelease ) {
			helpRelease();
			helpRelease = null;
		}
		if ( helpReturn && helpReturn.focus ) {
			helpReturn.focus();
		}
	}

	function bindHelp() {
		$( document ).on( 'click', '[data-help-open]', function ( e ) {
			e.preventDefault();
			openHelp();
		} );
		$( document ).on( 'click', '[data-help-close]', closeHelp );
		// Buttons inside the drawer that start something else close it first.
		$( '#nm-help' ).on( 'click', '[data-tour-start], [data-palette-open]', closeHelp );
	}

	/* ---------------- Ctrl+K search ---------------- */

	var palette = null;

	function fold( s ) {
		return String( s || '' ).toLowerCase().replace( /[’'"“”]/g, '' ).replace( /\s+/g, ' ' ).trim();
	}

	function searchItems( query ) {
		var items = cfg.search || [];
		var q = fold( query );
		if ( ! q ) {
			// Nothing typed: quick actions first, then every page.
			return items.filter( function ( it ) {
				return it.action;
			} ).concat( items.filter( function ( it ) {
				return ! it.action;
			} ) );
		}
		var words = q.split( ' ' );
		var scored = [];
		items.forEach( function ( it, i ) {
			var label = fold( it.label );
			var hay = label + ' ' + fold( it.group ) + ' ' + fold( it.keywords ) + ' ' + fold( it.desc );
			for ( var w = 0; w < words.length; w++ ) {
				if ( hay.indexOf( words[ w ] ) === -1 ) {
					return;
				}
			}
			var score = 0;
			if ( label.indexOf( q ) === 0 ) {
				score += 30;
			} else if ( label.indexOf( q ) !== -1 ) {
				score += 20;
			} else if ( words.every( function ( word ) {
				return label.indexOf( word ) !== -1;
			} ) ) {
				score += 12;
			}
			if ( fold( it.keywords ).indexOf( q ) !== -1 ) {
				score += 6;
			}
			if ( ! it.action ) {
				score += 1;
			}
			scored.push( { it: it, score: score, i: i } );
		} );
		scored.sort( function ( a, b ) {
			return b.score - a.score || a.i - b.i;
		} );
		return scored.map( function ( s ) {
			return s.it;
		} );
	}

	function openPalette() {
		if ( palette ) {
			return;
		}
		markLocalDone( 'search' );
		var previous = document.activeElement;
		var $wrap = $(
			'<div class="nm-palette-wrap">' +
				'<div class="nm-palette" role="dialog" aria-modal="true" aria-label="Search the control panel">' +
					'<div class="nm-palette__input-row">' + icon( 'search' ) +
						'<input type="text" class="nm-palette__input" placeholder="What do you want to do? Try “zoom” or “add shiur”" autocomplete="off" spellcheck="false" role="combobox" aria-expanded="true" aria-controls="nm-palette-list" aria-autocomplete="list">' +
						'<kbd class="nm-kbd">Esc</kbd>' +
					'</div>' +
					'<div class="nm-palette__results" id="nm-palette-list" role="listbox"></div>' +
					'<div class="nm-palette__foot">' +
						'<span><kbd class="nm-kbd">↑</kbd><kbd class="nm-kbd">↓</kbd> to move</span>' +
						'<span><kbd class="nm-kbd">Enter</kbd> to open</span>' +
						'<span><kbd class="nm-kbd">Esc</kbd> to close</span>' +
					'</div>' +
				'</div>' +
			'</div>'
		).appendTo( document.body );

		var $input = $wrap.find( '.nm-palette__input' );
		var $list = $wrap.find( '.nm-palette__results' );
		var results = [];
		var active = 0;
		var release = trapFocus( $wrap[ 0 ] );

		function render() {
			results = searchItems( $input.val() );
			active = 0;
			if ( ! results.length ) {
				$list.html( '<div class="nm-palette__empty"><strong>No matches</strong>Try a different word — for example “photos”, “speaker”, or “colors”.</div>' );
				return;
			}
			var html = '';
			var lastSection = null;
			results.forEach( function ( it, i ) {
				var section = it.action ? 'Quick actions' : 'Pages';
				if ( section !== lastSection ) {
					html += '<div class="nm-palette__heading">' + section + '</div>';
					lastSection = section;
				}
				var sub = it.action ? '' : [ it.group, it.desc ].filter( Boolean ).join( ' · ' );
				html += '<button type="button" class="nm-palette__item' + ( i === 0 ? ' is-active' : '' ) + '" role="option" data-index="' + i + '" aria-selected="' + ( i === 0 ? 'true' : 'false' ) + '">' +
					'<span class="nm-palette__icon">' + icon( it.icon || 'arrow-r' ) + '</span>' +
					'<span class="nm-palette__text"><strong>' + esc( it.label ) + '</strong>' + ( sub ? '<span>' + esc( sub ) + '</span>' : '' ) + '</span>' +
					'<span class="nm-palette__go">' + icon( 'arrow-r' ) + '</span>' +
				'</button>';
			} );
			$list.html( html );
		}

		function setActive( i ) {
			if ( ! results.length ) {
				return;
			}
			active = ( i + results.length ) % results.length;
			var $items = $list.find( '.nm-palette__item' );
			$items.removeClass( 'is-active' ).attr( 'aria-selected', 'false' );
			var $cur = $items.eq( active ).addClass( 'is-active' ).attr( 'aria-selected', 'true' );
			if ( $cur[ 0 ] && $cur[ 0 ].scrollIntoView ) {
				$cur[ 0 ].scrollIntoView( { block: 'nearest' } );
			}
		}

		function go( i ) {
			var it = results[ i ];
			if ( it && it.url ) {
				window.location.href = it.url;
			}
		}

		function close() {
			release();
			$wrap.remove();
			palette = null;
			if ( previous && previous.focus ) {
				previous.focus();
			}
		}

		$input.on( 'input', render );
		$input.on( 'keydown', function ( e ) {
			if ( e.key === 'ArrowDown' ) {
				e.preventDefault();
				setActive( active + 1 );
			} else if ( e.key === 'ArrowUp' ) {
				e.preventDefault();
				setActive( active - 1 );
			} else if ( e.key === 'Enter' ) {
				e.preventDefault();
				go( active );
			}
		} );
		$list.on( 'mousemove', '.nm-palette__item', function () {
			var i = parseInt( $( this ).attr( 'data-index' ), 10 );
			if ( i !== active ) {
				setActive( i );
			}
		} );
		$list.on( 'click', '.nm-palette__item', function () {
			go( parseInt( $( this ).attr( 'data-index' ), 10 ) );
		} );
		$wrap.on( 'mousedown', function ( e ) {
			if ( e.target === $wrap[ 0 ] ) {
				close();
			}
		} );

		palette = { close: close };
		render();
		$input.trigger( 'focus' );
	}

	function bindPalette() {
		$( document ).on( 'click', '[data-palette-open]', function ( e ) {
			e.preventDefault();
			closeSidebar();
			openPalette();
		} );
		$( document ).on( 'keydown', function ( e ) {
			var key = ( e.key || '' ).toLowerCase();
			if ( ( e.ctrlKey || e.metaKey ) && key === 'k' ) {
				e.preventDefault();
				if ( palette ) {
					palette.close();
				} else {
					openPalette();
				}
				return;
			}
			if ( e.key === 'Escape' ) {
				if ( palette ) {
					palette.close();
				} else if ( ! $( '#nm-help' ).prop( 'hidden' ) ) {
					closeHelp();
				} else if ( $app.hasClass( 'is-nav-open' ) ) {
					closeSidebar();
				}
			}
		} );
	}

	/* ---------------- Getting started (Home) ---------------- */

	function markLocalDone( key ) {
		store.set( 'done:' + key, '1' );
		updateChecklist();
	}

	function updateChecklist() {
		var $card = $( '[data-checklist]' );
		if ( ! $card.length ) {
			return;
		}
		if ( store.get( 'checklistHidden' ) === '1' ) {
			$card.prop( 'hidden', true );
			return;
		}
		$card.prop( 'hidden', false );
		var $items = $card.find( '.nm-checklist__item' );
		$items.filter( '[data-check-local]' ).each( function () {
			var $it = $( this );
			if ( store.get( 'done:' + $it.attr( 'data-check' ) ) === '1' && ! $it.hasClass( 'is-done' ) ) {
				$it.addClass( 'is-done' ).find( '.nm-checklist__state' ).text( 'Done' );
			}
		} );
		var total = $items.length;
		var done = $items.filter( '.is-done' ).length;
		$card.find( '[data-checklist-bar]' ).css( 'width', total ? Math.round( ( done / total ) * 100 ) + '%' : '0%' );
		$card.find( '[data-checklist-summary]' ).text(
			done === total ? 'All ' + total + ' done — nicely done!' : done + ' of ' + total + ' done. Click a step to do it.'
		);
		$card.find( '[data-checklist-complete]' ).prop( 'hidden', done !== total );
	}

	function bindChecklist() {
		$( document ).on( 'click', '[data-checklist-hide]', function () {
			store.set( 'checklistHidden', '1' );
			updateChecklist();
			toast( 'Hidden. You can bring it back from your account menu (top right).', 'info' );
		} );
		$( document ).on( 'click', '[data-checklist-show]', function () {
			store.remove( 'checklistHidden' );
			if ( cfg.isHome ) {
				updateChecklist();
				var el = $( '[data-checklist]' )[ 0 ];
				if ( el && el.scrollIntoView ) {
					el.scrollIntoView( { behavior: 'smooth', block: 'center' } );
				}
			} else if ( cfg.homeUrl ) {
				window.location.href = cfg.homeUrl;
			}
		} );
		updateChecklist();
	}

	/* ---------------- Greeting (Home) ---------------- */

	function greet() {
		var $title = $( '[data-nm-greeting]' );
		if ( ! $title.length ) {
			return;
		}
		var now = new Date();
		var h = now.getHours();
		var part = h < 12 ? 'Good morning' : ( h < 18 ? 'Good afternoon' : 'Good evening' );
		var name = $title.attr( 'data-name' );
		$title.text( part + ( name ? ', ' + name : '' ) );
		try {
			$( '[data-nm-date]' ).text( now.toLocaleDateString( undefined, { weekday: 'long', month: 'long', day: 'numeric' } ) );
		} catch ( e ) {}
	}

	/* ---------------- Guided tour ---------------- */

	var TOUR = [
		{
			emoji: '👋',
			title: 'Welcome to your Control Panel',
			text: 'This is where you look after the website — shiurim, announcements, the homepage, and more. Want a quick look around? It takes about a minute.',
			welcome: true
		},
		{
			target: '[data-tour="nav"]',
			title: 'Everything lives in this menu',
			text: 'Pages are grouped by what you want to do: Shiurim, Announcements, Messages, and so on. Click a group’s name to open it. Things you’ll rarely need are tucked under “Advanced”.',
			mobileNav: true
		},
		{
			target: '[data-tour="quick"]',
			title: 'Shortcuts for the common jobs',
			text: 'Adding a shiur, posting a Mazal Tov, changing the homepage banner — they’re all one click away from Home.',
			homeOnly: true
		},
		{
			target: '[data-tour="search"]',
			title: 'Can’t find something? Search for it',
			text: 'Click here (or press ' + ( isMac ? '⌘ + K' : 'Ctrl + K' ) + ') and type what you’re looking for — like “zoom” or “photos” — to jump straight there.'
		},
		{
			target: '[data-tour="help"]',
			title: 'Help is on every page',
			text: 'Not sure what a page does? Press Help. You’ll get a short, step-by-step explanation of that page.'
		},
		{
			target: '[data-tour="site"]',
			title: 'See your changes',
			text: 'Opens the website in a new tab, so you can see exactly what visitors see.'
		},
		{
			emoji: '🎉',
			title: 'You’re ready!',
			text: 'Nothing changes on the site until you press Save, and deleting always asks first — so feel free to explore. You can replay this tour any time from Help.',
			last: true
		}
	];

	var tour = null;

	function visibleRect( el ) {
		if ( ! el ) {
			return null;
		}
		var r = el.getBoundingClientRect();
		if ( r.width < 2 || r.height < 2 ) {
			return null;
		}
		if ( r.bottom < 0 || r.right < 0 || r.top > window.innerHeight || r.left > window.innerWidth ) {
			return null;
		}
		return r;
	}

	function startTour() {
		if ( tour ) {
			return;
		}
		closeHelp();
		if ( palette ) {
			palette.close();
		}
		var steps = TOUR.filter( function ( s ) {
			return ! ( s.homeOnly && ! cfg.isHome ) && ! ( s.target && ! document.querySelector( s.target ) );
		} );
		var $shade = $( '<div class="nm-tour-shade"></div>' ).appendTo( document.body );
		var $spot = $( '<div class="nm-tour-spot is-center"></div>' ).appendTo( document.body );
		var $pop = $( '<div class="nm-tour-pop" role="dialog" aria-modal="true" aria-live="polite"></div>' ).appendTo( document.body );
		var index = 0;
		var release = trapFocus( $pop[ 0 ] );

		function place() {
			var step = steps[ index ];
			var el = step.target ? document.querySelector( step.target ) : null;
			var r = visibleRect( el );
			var popW = $pop.outerWidth();
			var popH = $pop.outerHeight();
			var vw = window.innerWidth;
			var vh = window.innerHeight;
			var gap = 16;
			var pad = 6;

			if ( ! r ) {
				$spot.addClass( 'is-center' ).css( { top: vh / 2, left: vw / 2 } );
				$pop.css( { top: Math.max( 16, ( vh - popH ) / 2 ), left: Math.max( 16, ( vw - popW ) / 2 ) } );
				return;
			}

			// Long targets (the whole menu) get a spotlight trimmed to the screen.
			var top = Math.max( 8, r.top - pad );
			var bottom = Math.min( vh - 8, r.bottom + pad );
			$spot.removeClass( 'is-center' ).css( {
				top: top,
				left: Math.max( 8, r.left - pad ),
				width: Math.min( vw - 16, r.width + pad * 2 ),
				height: bottom - top
			} );

			var left;
			var popTop;
			if ( r.right + gap + popW <= vw - 16 && r.width < vw * 0.5 ) {
				left = r.right + gap;
				popTop = Math.min( Math.max( 16, r.top ), vh - popH - 16 );
			} else if ( r.bottom + gap + popH <= vh - 16 ) {
				popTop = r.bottom + gap;
				left = Math.min( Math.max( 16, r.left + r.width / 2 - popW / 2 ), vw - popW - 16 );
			} else if ( r.top - gap - popH >= 16 ) {
				popTop = r.top - gap - popH;
				left = Math.min( Math.max( 16, r.left + r.width / 2 - popW / 2 ), vw - popW - 16 );
			} else {
				popTop = Math.max( 16, ( vh - popH ) / 2 );
				left = Math.max( 16, ( vw - popW ) / 2 );
			}
			$pop.css( { top: popTop, left: left } );
		}

		function show() {
			var step = steps[ index ];

			if ( step.mobileNav && isMobileNav() && ! $app.hasClass( 'is-nav-open' ) ) {
				openSidebar();
				sidebarOpenedByTour = true;
			} else if ( ! step.mobileNav && sidebarOpenedByTour ) {
				closeSidebar();
				sidebarOpenedByTour = false;
			}

			if ( step.target ) {
				var el = document.querySelector( step.target );
				if ( el && el.scrollIntoView && ! visibleRect( el ) ) {
					el.scrollIntoView( { block: 'center' } );
				}
			}

			var dots = '';
			for ( var i = 0; i < steps.length; i++ ) {
				dots += '<i class="' + ( i === index ? 'is-on' : '' ) + '"></i>';
			}
			var countable = steps.length - 2;
			var label = step.welcome || step.last ? '' : 'Step ' + index + ' of ' + countable;

			var buttons;
			if ( step.welcome ) {
				buttons = '<button type="button" class="nm-tour-pop__skip" data-tour-skip>Maybe later</button>' +
					'<button type="button" class="nm-btn nm-btn--primary nm-btn--sm" data-tour-next>Show me around</button>';
			} else if ( step.last ) {
				buttons = '<button type="button" class="nm-btn nm-btn--sm" data-tour-back>Back</button>' +
					'<button type="button" class="nm-btn nm-btn--primary nm-btn--sm" data-tour-next>Start using it</button>';
			} else {
				buttons = '<button type="button" class="nm-tour-pop__skip" data-tour-skip>Skip tour</button>' +
					'<button type="button" class="nm-btn nm-btn--sm" data-tour-back>Back</button>' +
					'<button type="button" class="nm-btn nm-btn--primary nm-btn--sm" data-tour-next>Next</button>';
			}

			$pop.html(
				( step.emoji ? '<div class="nm-tour-pop__emoji" aria-hidden="true">' + step.emoji + '</div>' : '' ) +
				( label ? '<p class="nm-tour-pop__step">' + label + '</p>' : '' ) +
				'<h3>' + esc( step.title ) + '</h3>' +
				'<p>' + esc( step.text ) + '</p>' +
				'<div class="nm-tour-pop__foot"><div class="nm-tour-pop__dots" aria-hidden="true">' + dots + '</div>' + buttons + '</div>'
			);
			place();
			if ( step.mobileNav && sidebarOpenedByTour ) {
				setTimeout( place, 340 ); // After the menu has slid in.
			}
			$pop.find( '[data-tour-next]' ).trigger( 'focus' );
		}

		function end( completed ) {
			release();
			$shade.remove();
			$spot.remove();
			$pop.remove();
			$( window ).off( '.nmtour' );
			$( document ).off( '.nmtour' );
			if ( sidebarOpenedByTour ) {
				closeSidebar();
				sidebarOpenedByTour = false;
			}
			store.set( 'tourSeen', '1' );
			tour = null;
			if ( completed ) {
				markLocalDone( 'tour' );
				toast( 'Tour finished. You can replay it any time from Help.', 'success' );
			}
		}

		$pop.on( 'click', '[data-tour-next]', function () {
			if ( index >= steps.length - 1 ) {
				end( true );
				return;
			}
			index++;
			show();
		} );
		$pop.on( 'click', '[data-tour-back]', function () {
			if ( index > 0 ) {
				index--;
				show();
			}
		} );
		$pop.on( 'click', '[data-tour-skip]', function () {
			end( steps[ index ].welcome ? false : index >= steps.length - 2 );
		} );
		$( window ).on( 'resize.nmtour scroll.nmtour', function () {
			place();
		} );
		$( document ).on( 'keydown.nmtour', function ( e ) {
			if ( e.key === 'Escape' ) {
				e.stopImmediatePropagation();
				end( false );
			} else if ( e.key === 'ArrowRight' ) {
				$pop.find( '[data-tour-next]' ).trigger( 'click' );
			} else if ( e.key === 'ArrowLeft' ) {
				$pop.find( '[data-tour-back]' ).trigger( 'click' );
			}
		} );

		tour = { end: end };
		show();
	}

	function bindTour() {
		$( document ).on( 'click', '[data-tour-start]', function ( e ) {
			e.preventDefault();
			$( '#nm-user-menu' ).prop( 'hidden', true );
			startTour();
		} );
		// First visit: offer the tour once, on Home.
		if ( cfg.isHome && store.get( 'tourSeen' ) !== '1' ) {
			setTimeout( startTour, 500 );
		}
	}

	/* ---------------- Messages left in the URL ---------------- */

	function flash() {
		if ( cfg.flash && cfg.flash.message ) {
			toast( cfg.flash.message, cfg.flash.type || 'success' );
		}
		// One-time notices shouldn't come back on reload.
		if ( window.history && window.history.replaceState && window.URL ) {
			try {
				var url = new URL( window.location.href );
				var changed = false;
				[ 'nm_bulk', 'nm_mazal_tov', 'nm_import', 'created', 'skipped', 'audio_failed' ].forEach( function ( k ) {
					if ( url.searchParams.has( k ) ) {
						url.searchParams.delete( k );
						changed = true;
					}
				} );
				if ( changed ) {
					window.history.replaceState( null, '', url.toString() );
				}
			} catch ( e ) {}
		}
	}

	/* ---------------- Public API ---------------- */

	window.NMAdmin = {
		toast: toast,
		confirm: confirmDialog,
		icon: icon,
		esc: esc,
		markDone: markLocalDone,
		trapFocus: trapFocus
	};

	$( function () {
		$( document.body ).removeClass( 'no-js' ).addClass( 'js' );
		bindSidebar();
		bindTopbar();
		bindHelp();
		bindPalette();
		bindChecklist();
		greet();
		flash();
		bindTour();
	} );
} )( jQuery );
