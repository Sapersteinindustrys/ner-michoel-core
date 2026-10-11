/**
 * The Control Panel's Colors & Fonts screen (Homepage & Look > Colors & Fonts).
 *
 * Two columns: on the left a font picker for headings and one for text (41
 * fonts, each name shown in its own face), the five colors with color pickers,
 * and 30 ready-made palettes of five colored balls; on the right the real
 * homepage in an iframe (includes/site-look.php serves it at ?nm_look_preview=1),
 * which takes every unsaved choice as it is made. Both fonts and colors are for
 * the whole site: the preview is the homepage, the choices reach every page.
 *
 * SettingsApp (custom-admin-settings.js) loads the screen's data and saves it;
 * this file draws the screen (NMSiteLook.render), reads it back (collect) and
 * tells SettingsApp when something changed (app.markDirty()).
 *
 * The preview's CSS is built here from the same pieces the PHP prints on the
 * site (extra.css), so what the preview shows is what the site will print.
 */
( function ( $ ) {
	'use strict';

	var DEVICE_KEY = 'nmLookDevice';
	var messageHandler = null;

	// Safe in text and in a quoted attribute: font stacks hold double quotes, names may hold an apostrophe.
	function esc( str ) {
		return $( '<div>' ).text( str === null || str === undefined ? '' : String( str ) ).html().replace( /"/g, '&quot;' ).replace( /'/g, '&#39;' );
	}

	function icon( name ) {
		return '<svg class="nm-ico" aria-hidden="true" focusable="false"><use href="#nm-i-' + name + '"></use></svg>';
	}

	/* ---------------- colour maths (readability hints only) ---------------- */

	function hexToRgb( hex ) {
		var m = /^#([0-9a-f]{6})$/i.exec( hex || '' );
		if ( ! m ) {
			return null;
		}
		var n = parseInt( m[ 1 ], 16 );
		return [ ( n >> 16 ) & 255, ( n >> 8 ) & 255, n & 255 ];
	}

	function luminance( rgb ) {
		var c = rgb.map( function ( v ) {
			v /= 255;
			return v <= 0.03928 ? v / 12.92 : Math.pow( ( v + 0.055 ) / 1.055, 2.4 );
		} );
		return 0.2126 * c[ 0 ] + 0.7152 * c[ 1 ] + 0.0722 * c[ 2 ];
	}

	function contrast( a, b ) {
		var ra = hexToRgb( a );
		var rb = hexToRgb( b );
		if ( ! ra || ! rb ) {
			return 0;
		}
		var la = luminance( ra );
		var lb = luminance( rb );
		return ( Math.max( la, lb ) + 0.05 ) / ( Math.min( la, lb ) + 0.05 );
	}

	function mixWith( hex, pct, withHex ) {
		var a = hexToRgb( hex );
		var b = hexToRgb( withHex );
		if ( ! a || ! b ) {
			return hex;
		}
		return '#' + a.map( function ( v, i ) {
			var out = Math.round( v * pct + b[ i ] * ( 1 - pct ) );
			return ( out < 16 ? '0' : '' ) + out.toString( 16 );
		} ).join( '' );
	}

	/**
	 * Whether each of the five colors will read well where the site uses it.
	 * Returns { key: { ok: bool, text: string } } for the ones with something to say.
	 */
	function readability( colors ) {
		var out = {};
		var c = contrast( '#ffffff', colors.brand );
		out.brand = c >= 4.5 ? { ok: true, text: 'White text on it is easy to read.' } : { ok: false, text: 'White text on this is hard to read. Pick a darker color.' };
		var f = contrast( '#ffffff', colors.fresh );
		out.fresh = f >= 3.5 ? { ok: true, text: 'Easy to see on white.' } : { ok: false, text: 'Small labels in this color are hard to see on white. Pick a darker one.' };
		var a = contrast( '#ffffff', mixWith( colors.accent, 0.62, '#000000' ) );
		out.accent = a >= 3.2 ? { ok: true, text: 'Reads well as a label and a button.' } : { ok: false, text: 'Labels in this color are hard to see on white. Pick a deeper one.' };
		var lum = hexToRgb( colors.soft ) ? luminance( hexToRgb( colors.soft ) ) : 1;
		out.soft = lum >= 0.8 ? { ok: true, text: 'Light, so the pages stay easy to read.' } : { ok: false, text: 'This is quite dark for a page background. Pick a lighter color.' };
		var t = contrast( colors.ink, colors.soft );
		out.ink = t >= 7 ? { ok: true, text: 'Text on the background is easy to read.' } : { ok: false, text: 'Text on the background is hard to read. Pick a darker color.' };
		return out;
	}

	/* ---------------- the look as CSS (same pieces the PHP prints) ---------------- */

	function fontBySlug( cfg, slug ) {
		for ( var i = 0; i < cfg.fonts.length; i++ ) {
			if ( cfg.fonts[ i ].slug === slug ) {
				return cfg.fonts[ i ];
			}
		}
		return null;
	}

	function colorsCustomized( cfg, colors ) {
		return cfg.roles.some( function ( r ) {
			return String( colors[ r.key ] ).toLowerCase() !== String( cfg.defaults.colors[ r.key ] ).toLowerCase();
		} );
	}

	function buildCss( cfg, state ) {
		var parts = cfg.css;
		var css = '';
		var vars = '';
		var body = fontBySlug( cfg, state.body );
		var heading = fontBySlug( cfg, state.heading );
		if ( body ) {
			vars += '--nm-look-font-body:' + body.stack + ';';
		}
		if ( heading ) {
			vars += '--nm-look-font-heading:' + heading.stack + ';';
		}
		if ( vars ) {
			css += ':root{' + vars + '}';
		}
		if ( body ) {
			css += parts.font_body;
		}
		if ( heading ) {
			css += parts.font_heading;
		}
		if ( colorsCustomized( cfg, state.colors ) ) {
			var roles = '';
			cfg.roles.forEach( function ( r ) {
				roles += '--nm-look-' + r.key + ':' + state.colors[ r.key ] + ';';
			} );
			css += parts.supports + '{:root{' + roles + parts.colors + '}' + parts.dark + '}';
		}
		return css;
	}

	function buildFontsUrl( cfg, state ) {
		var specs = {};
		[ state.heading, state.body ].forEach( function ( slug ) {
			var font = fontBySlug( cfg, slug );
			if ( font ) {
				specs[ font.spec ] = true;
				if ( font.partner ) {
					specs[ font.partner ] = true;
				}
			}
		} );
		var list = Object.keys( specs ).sort();
		return list.length ? cfg.google + '?family=' + list.join( '&family=' ) + '&display=swap' : '';
	}

	/* ---------------- the screen ---------------- */

	function Screen( app ) {
		this.app = app;
		this.cfg = app.schema.extra;
		this.saved = this.normalize( app.schema.values );
		this.state = this.clone( this.saved );
		this.device = 'desktop';
		try {
			this.device = window.localStorage.getItem( DEVICE_KEY ) === 'phone' ? 'phone' : 'desktop';
		} catch ( e ) {}
		this.previewReady = false;
		this.pickerOpen = null;
		this.pickerFilter = { heading: { q: '', cat: 'all', hebrew: false }, body: { q: '', cat: 'all', hebrew: false } };
	}

	Screen.prototype.clone = function ( s ) {
		return { heading: s.heading, body: s.body, colors: $.extend( {}, s.colors ), palette: s.palette };
	};

	Screen.prototype.normalize = function ( values ) {
		var cfg = this.cfg;
		var colors = {};
		cfg.roles.forEach( function ( r ) {
			var v = values && values.colors && values.colors[ r.key ];
			colors[ r.key ] = /^#[0-9a-f]{6}$/i.test( v || '' ) ? v.toLowerCase() : cfg.defaults.colors[ r.key ];
		} );
		return { heading: ( values && values.heading ) || '', body: ( values && values.body ) || '', colors: colors, palette: ( values && values.palette ) || 'custom' };
	};

	/** Which palette (if any) the current five colors are exactly. */
	Screen.prototype.paletteOf = function ( colors ) {
		var cfg = this.cfg;
		for ( var i = 0; i < cfg.palettes.length; i++ ) {
			var p = cfg.palettes[ i ];
			var same = cfg.roles.every( function ( r ) {
				return String( p.colors[ r.key ] ).toLowerCase() === String( colors[ r.key ] ).toLowerCase();
			} );
			if ( same ) {
				return p.id;
			}
		}
		return 'custom';
	};

	/* ----- markup ----- */

	Screen.prototype.pickerHtml = function ( part, label, hint ) {
		return '<div class="nm-look-field" data-part="' + part + '">' +
			'<label class="nm-look-field__label" id="nm-look-label-' + part + '">' + esc( label ) + '</label>' +
			'<div class="nm-look-picker" data-picker="' + part + '">' +
			'<button type="button" class="nm-look-picker__btn" aria-haspopup="listbox" aria-expanded="false" aria-labelledby="nm-look-label-' + part + '" aria-controls="nm-look-list-' + part + '"></button>' +
			'<div class="nm-look-picker__pop" id="nm-look-pop-' + part + '" hidden>' +
			'<div class="nm-look-picker__tools">' +
			'<input type="search" class="nm-cms-input nm-look-picker__search" placeholder="Search fonts…" aria-label="Search fonts" autocomplete="off">' +
			'<div class="nm-look-chips" role="group" aria-label="Kind of font"></div>' +
			'</div>' +
			'<ul class="nm-look-picker__list" id="nm-look-list-' + part + '" role="listbox" aria-labelledby="nm-look-label-' + part + '" tabindex="-1"></ul>' +
			'<p class="nm-look-picker__empty" hidden>No font matches that.</p>' +
			'</div>' +
			'</div>' +
			'<p class="nm-cms-hint">' + esc( hint ) + '</p>' +
			'</div>';
	};

	Screen.prototype.render = function () {
		var cfg = this.cfg;
		var html = '<div class="nm-settings nm-settings--look">';
		html += '<div class="nm-look">';

		/* controls */
		html += '<div class="nm-look__controls">';

		html += '<section class="nm-settings-card"><div class="nm-settings-card__body">';
		html += '<h3 class="nm-settings-card__title">Fonts</h3>';
		html += '<p class="nm-settings-card__sub">Applies to every page of the site. Fonts marked <span class="nm-look-heb" title="Has Hebrew letters">עב</span> include Hebrew letters; for the others, Hebrew words use a matching Hebrew font.</p>';
		html += this.pickerHtml( 'heading', 'Headings', 'Page titles and section headings.' );
		html += this.pickerHtml( 'body', 'Text', 'Everything else: paragraphs, menus, buttons.' );
		html += '</div></section>';

		html += '<section class="nm-settings-card"><div class="nm-settings-card__body">';
		html += '<div class="nm-look-head"><div><h3 class="nm-settings-card__title">Colors</h3>';
		html += '<p class="nm-settings-card__sub">Five colors do the whole site. Click a ball to pick it, or type a code.</p></div>';
		html += '<button type="button" class="nm-btn nm-btn--sm nm-look-reset" title="The site’s own fonts and colors, as the design first had them">' + icon( 'clock' ) + '<span>Back to the original look</span></button></div>';
		html += '<div class="nm-look-colors" role="group" aria-label="Your five colors">';
		cfg.roles.forEach( function ( r ) {
			html += '<div class="nm-look-color" data-role="' + esc( r.key ) + '">' +
				'<label class="nm-look-color__ball" title="Pick the ' + esc( r.label.toLowerCase() ) + '">' +
				'<input type="color" class="nm-look-color__native" aria-label="' + esc( r.label ) + ' color picker">' +
				'<span class="nm-look-color__swatch"></span>' +
				'</label>' +
				'<div class="nm-look-color__info">' +
				'<span class="nm-look-color__name">' + esc( r.label ) + '</span>' +
				'<input type="text" class="nm-cms-input nm-look-color__hex" maxlength="7" spellcheck="false" aria-label="' + esc( r.label ) + ' code" placeholder="#rrggbb">' +
				'<span class="nm-look-color__hint">' + esc( r.hint ) + '</span>' +
				'<span class="nm-look-color__check" aria-live="polite"></span>' +
				'</div></div>';
		} );
		html += '</div>';
		html += '</div></section>';

		html += '<section class="nm-settings-card"><div class="nm-settings-card__body">';
		var vibrant = cfg.palettes.filter( function ( p ) {
			return 'vibrant' === p.group;
		} ).length;
		html += '<h3 class="nm-settings-card__title">Color sets</h3>';
		html += '<p class="nm-settings-card__sub">' + cfg.palettes.length + ' ready-made sets: ' + vibrant + ' vibrant and ' + ( cfg.palettes.length - vibrant ) + ' calmer ones. Click one to try it; you can still change any color after.</p>';
		html += '<div class="nm-look-palettes" role="radiogroup" aria-label="Color sets">';
		var lastGroup = '';
		cfg.palettes.forEach( function ( p ) {
			if ( p.group !== lastGroup ) {
				lastGroup = p.group;
				var count = cfg.palettes.filter( function ( q ) {
					return q.group === p.group;
				} ).length;
				html += '<h4 class="nm-look-palettes__group">' + esc( cfg.groups[ p.group ] || p.group ) + ' <span>' + count + '</span></h4>';
			}
			html += '<button type="button" class="nm-look-palette" role="radio" aria-checked="false" data-id="' + esc( p.id ) + '" tabindex="-1">' +
				'<span class="nm-look-palette__name">' + esc( p.name ) + '</span>' +
				'<span class="nm-look-palette__balls" aria-hidden="true">';
			cfg.roles.forEach( function ( r ) {
				html += '<i style="background:' + esc( p.colors[ r.key ] ) + '"></i>';
			} );
			html += '</span><span class="nm-look-palette__tick">' + icon( 'check' ) + '</span></button>';
		} );
		html += '</div>';
		html += '</div></section>';

		html += '<p class="nm-look-note">' + icon( 'info' ) + '<span>Visitors see the new look as soon as you save. If your host keeps its own page cache, a page or two can take a little while to refresh.</span></p>';

		html += '<div class="nm-settings-card nm-look-barcard"><div class="nm-settings-bar">' +
			'<span class="nm-settings-bar__state"></span>' +
			'<p class="nm-settings-message" hidden></p>' +
			'<button type="button" class="nm-btn nm-look-discard" hidden>Discard changes</button>' +
			'<button type="button" class="nm-btn nm-btn--primary nm-settings-save">' + icon( 'check' ) + '<span>Save</span></button>' +
			'</div></div>';
		html += '</div>'; // controls

		/* preview */
		html += '<div class="nm-look__preview"><section class="nm-look-preview" aria-label="Sample homepage">';
		html += '<div class="nm-look-preview__bar">' +
			'<div><strong>Sample homepage</strong><span>Your changes show here before you save.</span></div>' +
			'<div class="nm-look-device" role="group" aria-label="Preview size">' +
			'<button type="button" data-device="desktop" aria-pressed="true">Computer</button>' +
			'<button type="button" data-device="phone" aria-pressed="false">Phone</button>' +
			'</div></div>';
		html += '<div class="nm-look-preview__stage" data-device="' + this.device + '">' +
			'<div class="nm-look-preview__loading"><span class="nm-spinner" aria-hidden="true"></span>Loading the homepage…</div>' +
			'<div class="nm-look-preview__frame"><iframe class="nm-look-preview__iframe" title="Sample homepage" src="' + esc( cfg.preview_url ) + '"></iframe></div>' +
			'<p class="nm-look-preview__fail" hidden>The homepage could not be shown here. Your changes will still save.</p>' +
			'</div>';
		html += '</section></div>';

		html += '</div></div>'; // .nm-look, .nm-settings

		this.app.$root.html( html );
		this.$root = this.app.$root;
		this.bind();
		this.paint();
		this.app.dirty = false;
		this.app.setState( 'clean' );
		var s = this;
		this.app.onSaved = function () {
			s.saved = s.clone( s.state );
			s.saved.palette = s.paletteOf( s.saved.colors );
			s.paint();
		};
	};

	/* ----- painting from state ----- */

	Screen.prototype.paint = function () {
		var self = this;
		var cfg = this.cfg;
		var state = this.state;

		state.palette = this.paletteOf( state.colors );

		// Font buttons.
		[ 'heading', 'body' ].forEach( function ( part ) {
			var f = fontBySlug( cfg, state[ part ] );
			var $btn = self.$root.find( '.nm-look-picker[data-picker="' + part + '"] .nm-look-picker__btn' );
			$btn.html(
				'<span class="nm-look-picker__current"' + ( f ? ' style="font-family:' + esc( f.stack ) + '"' : '' ) + '>' + esc( f ? f.name : 'The site’s own font' ) + '</span>' +
				( f && f.hebrew ? '<span class="nm-look-heb" title="Has Hebrew letters">עב</span>' : '' ) +
				icon( 'chevron' )
			);
		} );

		// Color balls and codes.
		var check = readability( state.colors );
		cfg.roles.forEach( function ( r ) {
			var $c = self.$root.find( '.nm-look-color[data-role="' + r.key + '"]' );
			var v = state.colors[ r.key ];
			$c.find( '.nm-look-color__swatch' ).css( 'background', v );
			$c.find( '.nm-look-color__native' ).val( v );
			var $hex = $c.find( '.nm-look-color__hex' );
			if ( document.activeElement !== $hex[ 0 ] ) {
				$hex.val( v );
			}
			var info = check[ r.key ];
			$c.find( '.nm-look-color__check' )
				.toggleClass( 'is-ok', !! ( info && info.ok ) )
				.toggleClass( 'is-warn', !! ( info && ! info.ok ) )
				.text( info ? info.text : '' );
		} );

		// Palette rows.
		var $rows = this.$root.find( '.nm-look-palette' );
		var found = false;
		$rows.each( function () {
			var on = $( this ).data( 'id' ) === state.palette;
			found = found || on;
			$( this ).toggleClass( 'is-on', on ).attr( 'aria-checked', on ? 'true' : 'false' ).attr( 'tabindex', on ? '0' : '-1' );
		} );
		if ( ! found ) {
			$rows.first().attr( 'tabindex', '0' );
		}

		this.$root.find( '.nm-look-reset' ).prop( 'disabled', this.isOriginal() );
		this.$root.find( '.nm-look-discard' ).prop( 'hidden', ! this.isDirty() );
		this.sendPreview();
	};

	/** The site's own fonts and colors, nothing chosen. */
	Screen.prototype.isOriginal = function () {
		return ! this.state.heading && ! this.state.body && ! colorsCustomized( this.cfg, this.state.colors );
	};

	/** Anything different from what is saved. */
	Screen.prototype.isDirty = function () {
		var a = this.state;
		var b = this.saved;
		return a.heading !== b.heading || a.body !== b.body || this.cfg.roles.some( function ( r ) {
			return String( a.colors[ r.key ] ).toLowerCase() !== String( b.colors[ r.key ] ).toLowerCase();
		} );
	};

	Screen.prototype.touched = function () {
		if ( this.isDirty() ) {
			this.app.markDirty();
		} else {
			this.app.dirty = false;
			this.app.setState( 'clean' );
		}
		this.paint();
	};

	/* ----- preview ----- */

	Screen.prototype.sendPreview = function () {
		var frame = this.$root.find( '.nm-look-preview__iframe' )[ 0 ];
		if ( ! frame || ! frame.contentWindow || ! this.previewReady ) {
			return;
		}
		frame.contentWindow.postMessage( { type: 'nm-look', css: buildCss( this.cfg, this.state ), fonts: buildFontsUrl( this.cfg, this.state ) }, window.location.origin );
	};

	Screen.prototype.fitPreview = function () {
		var $stage = this.$root.find( '.nm-look-preview__stage' );
		var $frame = this.$root.find( '.nm-look-preview__frame' );
		var stageW = $stage.innerWidth();
		if ( ! stageW ) {
			return;
		}
		var phone = 'phone' === this.device;
		var virtualW = phone ? 390 : 1200;
		var scale = Math.min( 1, stageW / virtualW );
		var viewH = phone ? 700 : Math.round( Math.min( 760, Math.max( 520, window.innerHeight - 250 ) ) );
		var h = Math.round( viewH / scale );
		var left = phone ? Math.max( 0, Math.round( ( stageW - virtualW * scale ) / 2 ) ) : 0;
		$frame.css( { width: virtualW + 'px', height: h + 'px', left: left + 'px', transform: 'scale(' + scale + ')' } );
		$stage.css( 'height', viewH + 'px' );
		$stage.attr( 'data-device', this.device );
	};

	Screen.prototype.bindPreview = function () {
		var self = this;
		var $root = this.$root;
		var frame = $root.find( '.nm-look-preview__iframe' )[ 0 ];
		var $stage = $root.find( '.nm-look-preview__stage' );
		var fail = function () {
			if ( ! self.previewReady ) {
				$stage.addClass( 'is-failed' );
				$root.find( '.nm-look-preview__fail' ).prop( 'hidden', false );
				$root.find( '.nm-look-preview__loading' ).prop( 'hidden', true );
			}
		};
		// The homepage answers as soon as its head is read, long before it has finished loading. If it has loaded and
		// said nothing (a host that refuses frames, a login page), or a minute has gone by, say so.
		var timer = setTimeout( fail, 60000 );
		$( frame ).on( 'load', function () {
			setTimeout( fail, 6000 );
		} );

		// A native listener: jQuery's event object does not carry a message's origin, source or data.
		if ( messageHandler ) {
			window.removeEventListener( 'message', messageHandler );
		}
		messageHandler = function ( e ) {
			if ( e.origin !== window.location.origin || ! frame || e.source !== frame.contentWindow || ! e.data || e.data.type !== 'nm-look-ready' ) {
				return;
			}
			clearTimeout( timer );
			self.previewReady = true;
			$stage.addClass( 'is-ready' ).removeClass( 'is-failed' );
			$root.find( '.nm-look-preview__loading' ).prop( 'hidden', true );
			$root.find( '.nm-look-preview__fail' ).prop( 'hidden', true );
			self.sendPreview();
		};
		window.addEventListener( 'message', messageHandler );

		$root.find( '.nm-look-device button' ).on( 'click', function () {
			self.device = $( this ).data( 'device' ) === 'phone' ? 'phone' : 'desktop';
			try {
				window.localStorage.setItem( DEVICE_KEY, self.device );
			} catch ( err ) {}
			self.syncDevice();
			self.fitPreview();
		} );
		this.syncDevice();

		$( window ).off( 'resize.nmlook' ).on( 'resize.nmlook', function () {
			self.fitPreview();
		} );
		this.fitPreview();
		if ( window.ResizeObserver ) {
			var ro = new window.ResizeObserver( function () {
				self.fitPreview();
			} );
			ro.observe( $stage[ 0 ] );
		}
	};

	Screen.prototype.syncDevice = function () {
		var d = this.device;
		this.$root.find( '.nm-look-device button' ).each( function () {
			$( this ).attr( 'aria-pressed', $( this ).data( 'device' ) === d ? 'true' : 'false' );
		} );
	};

	/* ----- font pickers ----- */

	Screen.prototype.fontsFor = function ( part ) {
		// Display and handwriting faces are for headings; text stays readable.
		return this.cfg.fonts.filter( function ( f ) {
			return 'heading' === part || 'sans' === f.cat || 'serif' === f.cat;
		} );
	};

	Screen.prototype.drawList = function ( part ) {
		var cfg = this.cfg;
		var flt = this.pickerFilter[ part ];
		var q = flt.q.toLowerCase();
		var current = this.state[ part ];
		var $pick = this.$root.find( '.nm-look-picker[data-picker="' + part + '"]' );
		var html = '<li role="option" class="nm-look-font nm-look-font--default' + ( ! current ? ' is-on' : '' ) + '" data-slug="" aria-selected="' + ( ! current ? 'true' : 'false' ) + '" tabindex="-1"><span class="nm-look-font__name">The site’s own font</span><span class="nm-look-font__meta">as it is now</span></li>';
		var shown = 0;
		this.fontsFor( part ).forEach( function ( f ) {
			if ( flt.cat !== 'all' && f.cat !== flt.cat ) {
				return;
			}
			if ( flt.hebrew && ! f.hebrew ) {
				return;
			}
			if ( q && f.name.toLowerCase().indexOf( q ) === -1 ) {
				return;
			}
			shown++;
			var on = f.slug === current;
			html += '<li role="option" class="nm-look-font' + ( on ? ' is-on' : '' ) + '" data-slug="' + esc( f.slug ) + '" aria-selected="' + ( on ? 'true' : 'false' ) + '" tabindex="-1">' +
				'<span class="nm-look-font__name" style="font-family:' + esc( f.stack ) + '">' + esc( f.name ) + '</span>' +
				'<span class="nm-look-font__meta">' + ( f.hebrew ? '<span class="nm-look-heb" title="Has Hebrew letters">עב</span>' : '' ) + esc( cfg.categories[ f.cat ] || f.cat ) + '</span>' +
				'</li>';
		} );
		$pick.find( '.nm-look-picker__list' ).html( html );
		$pick.find( '.nm-look-picker__empty' ).prop( 'hidden', shown > 0 || ! ( q || flt.cat !== 'all' || flt.hebrew ) );
	};

	Screen.prototype.drawChips = function ( part ) {
		var cfg = this.cfg;
		var flt = this.pickerFilter[ part ];
		var cats = { all: 'All' };
		var present = {};
		this.fontsFor( part ).forEach( function ( f ) {
			present[ f.cat ] = true;
		} );
		$.each( cfg.categories, function ( k, label ) {
			if ( present[ k ] ) {
				cats[ k ] = label;
			}
		} );
		var html = '';
		$.each( cats, function ( k, label ) {
			html += '<button type="button" class="nm-look-chip' + ( flt.cat === k ? ' is-on' : '' ) + '" data-cat="' + esc( k ) + '" aria-pressed="' + ( flt.cat === k ? 'true' : 'false' ) + '">' + esc( label ) + '</button>';
		} );
		html += '<button type="button" class="nm-look-chip nm-look-chip--heb' + ( flt.hebrew ? ' is-on' : '' ) + '" data-hebrew="1" aria-pressed="' + ( flt.hebrew ? 'true' : 'false' ) + '">עב Hebrew</button>';
		this.$root.find( '.nm-look-picker[data-picker="' + part + '"] .nm-look-chips' ).html( html );
	};

	/** Shows which filters are on without redrawing the chips (the one being clicked must stay put, with its focus). */
	Screen.prototype.syncChips = function ( part ) {
		var flt = this.pickerFilter[ part ];
		this.$root.find( '.nm-look-picker[data-picker="' + part + '"] .nm-look-chip' ).each( function () {
			var $c = $( this );
			var on = $c.data( 'hebrew' ) ? flt.hebrew : $c.data( 'cat' ) === flt.cat;
			$c.toggleClass( 'is-on', on ).attr( 'aria-pressed', on ? 'true' : 'false' );
		} );
	};

	Screen.prototype.openPicker = function ( part ) {
		var self = this;
		this.closePickers( part );
		var $pick = this.$root.find( '.nm-look-picker[data-picker="' + part + '"]' );
		this.loadPickerFonts();
		this.drawChips( part );
		this.drawList( part );
		$pick.addClass( 'is-open' ).find( '.nm-look-picker__btn' ).attr( 'aria-expanded', 'true' );
		$pick.find( '.nm-look-picker__pop' ).prop( 'hidden', false );
		this.pickerOpen = part;
		var $on = $pick.find( '.nm-look-font.is-on' ).first();
		if ( $on.length && $on[ 0 ].scrollIntoView ) {
			var list = $pick.find( '.nm-look-picker__list' )[ 0 ];
			list.scrollTop = Math.max( 0, $on[ 0 ].offsetTop - list.clientHeight / 2 + $on.outerHeight() / 2 );
		}
		setTimeout( function () {
			$pick.find( '.nm-look-picker__search' ).trigger( 'focus' );
		}, 0 );
		return self;
	};

	Screen.prototype.closePickers = function ( except ) {
		var self = this;
		this.$root.find( '.nm-look-picker.is-open' ).each( function () {
			var $p = $( this );
			if ( except && $p.data( 'picker' ) === except ) {
				return;
			}
			$p.removeClass( 'is-open' ).find( '.nm-look-picker__btn' ).attr( 'aria-expanded', 'false' );
			$p.find( '.nm-look-picker__pop' ).prop( 'hidden', true );
		} );
		if ( ! except ) {
			this.pickerOpen = null;
		}
		return self;
	};

	/** One request for all the names in their own faces; only fetched when a picker first opens. */
	Screen.prototype.loadPickerFonts = function () {
		if ( this.pickerFontsLoaded || ! this.cfg.picker_url ) {
			return;
		}
		this.pickerFontsLoaded = true;
		var link = document.createElement( 'link' );
		link.rel = 'stylesheet';
		link.id = 'nm-look-picker-fonts';
		link.href = this.cfg.picker_url;
		document.head.appendChild( link );
	};

	Screen.prototype.pick = function ( part, slug ) {
		this.state[ part ] = slug;
		this.closePickers();
		this.$root.find( '.nm-look-picker[data-picker="' + part + '"] .nm-look-picker__btn' ).trigger( 'focus' );
		this.touched();
	};

	/* ----- events ----- */

	Screen.prototype.bind = function () {
		var self = this;
		var $root = this.$root;

		$root.off( '.nmlook' );

		// Pickers.
		$root.on( 'click.nmlook', '.nm-look-picker__btn', function () {
			var part = $( this ).closest( '.nm-look-picker' ).data( 'picker' );
			if ( self.pickerOpen === part ) {
				self.closePickers();
			} else {
				self.openPicker( part );
			}
		} );
		$root.on( 'input.nmlook', '.nm-look-picker__search', function () {
			var part = $( this ).closest( '.nm-look-picker' ).data( 'picker' );
			self.pickerFilter[ part ].q = $( this ).val();
			self.drawList( part );
		} );
		$root.on( 'click.nmlook', '.nm-look-chip', function () {
			var part = $( this ).closest( '.nm-look-picker' ).data( 'picker' );
			if ( $( this ).data( 'hebrew' ) ) {
				self.pickerFilter[ part ].hebrew = ! self.pickerFilter[ part ].hebrew;
			} else {
				self.pickerFilter[ part ].cat = $( this ).data( 'cat' );
			}
			self.syncChips( part );
			self.drawList( part );
		} );
		$root.on( 'click.nmlook', '.nm-look-font', function () {
			var part = $( this ).closest( '.nm-look-picker' ).data( 'picker' );
			self.pick( part, String( $( this ).data( 'slug' ) || '' ) );
		} );
		$root.on( 'keydown.nmlook', '.nm-look-picker', function ( e ) {
			var $p = $( this );
			var part = $p.data( 'picker' );
			if ( 'Escape' === e.key && $p.hasClass( 'is-open' ) ) {
				e.preventDefault();
				self.closePickers();
				$p.find( '.nm-look-picker__btn' ).trigger( 'focus' );
				return;
			}
			if ( ( 'ArrowDown' === e.key || 'ArrowUp' === e.key ) && $p.hasClass( 'is-open' ) ) {
				e.preventDefault();
				var $items = $p.find( '.nm-look-font' );
				var $cur = $p.find( '.nm-look-font.is-active' );
				var idx = $items.index( $cur );
				idx = 'ArrowDown' === e.key ? Math.min( $items.length - 1, idx + 1 ) : Math.max( 0, idx < 0 ? 0 : idx - 1 );
				$items.removeClass( 'is-active' );
				var $next = $items.eq( idx ).addClass( 'is-active' );
				if ( $next.length ) {
					var list = $p.find( '.nm-look-picker__list' )[ 0 ];
					var top = $next[ 0 ].offsetTop;
					if ( top < list.scrollTop ) {
						list.scrollTop = top;
					} else if ( top + $next.outerHeight() > list.scrollTop + list.clientHeight ) {
						list.scrollTop = top + $next.outerHeight() - list.clientHeight;
					}
				}
				return;
			}
			if ( 'Enter' === e.key && $p.hasClass( 'is-open' ) && $( e.target ).is( '.nm-look-picker__search' ) ) {
				e.preventDefault();
				var $act = $p.find( '.nm-look-font.is-active' );
				if ( ! $act.length ) {
					$act = $p.find( '.nm-look-font' ).not( '.nm-look-font--default' ).first();
				}
				if ( $act.length ) {
					self.pick( part, String( $act.data( 'slug' ) || '' ) );
				}
			}
		} );
		$( document ).off( 'click.nmlookdoc' ).on( 'click.nmlookdoc', function ( e ) {
			// A target that is no longer on the page was redrawn by this click (a list item): that was inside the picker.
			if ( self.pickerOpen && document.body.contains( e.target ) && ! $( e.target ).closest( '.nm-look-picker' ).length ) {
				self.closePickers();
			}
		} );

		// Colors: the ball's native picker, the code box.
		$root.on( 'input.nmlook change.nmlook', '.nm-look-color__native', function () {
			var role = $( this ).closest( '.nm-look-color' ).data( 'role' );
			self.state.colors[ role ] = String( $( this ).val() ).toLowerCase();
			self.touched();
		} );
		$root.on( 'input.nmlook', '.nm-look-color__hex', function () {
			var role = $( this ).closest( '.nm-look-color' ).data( 'role' );
			var v = String( $( this ).val() ).trim();
			if ( v && v.charAt( 0 ) !== '#' ) {
				v = '#' + v;
			}
			$( this ).toggleClass( 'has-error', v.length > 1 && ! /^#[0-9a-f]{6}$/i.test( v ) );
			if ( /^#[0-9a-f]{6}$/i.test( v ) ) {
				self.state.colors[ role ] = v.toLowerCase();
				self.touched();
			}
		} );
		$root.on( 'blur.nmlook', '.nm-look-color__hex', function () {
			var role = $( this ).closest( '.nm-look-color' ).data( 'role' );
			$( this ).removeClass( 'has-error' ).val( self.state.colors[ role ] );
		} );

		// Palettes.
		$root.on( 'click.nmlook', '.nm-look-palette', function () {
			var id = $( this ).data( 'id' );
			var palette = self.cfg.palettes.filter( function ( p ) {
				return p.id === id;
			} )[ 0 ];
			if ( palette ) {
				self.state.colors = $.extend( {}, palette.colors );
				self.touched();
			}
		} );
		$root.on( 'keydown.nmlook', '.nm-look-palette', function ( e ) {
			var keys = { ArrowDown: 1, ArrowRight: 1, ArrowUp: -1, ArrowLeft: -1 };
			if ( keys[ e.key ] ) {
				e.preventDefault();
				var $all = $root.find( '.nm-look-palette' );
				var i = $all.index( this ) + keys[ e.key ];
				if ( i >= 0 && i < $all.length ) {
					$all.attr( 'tabindex', '-1' );
					$all.eq( i ).attr( 'tabindex', '0' ).trigger( 'focus' );
				}
			}
		} );

		// Back to the original look: the site's own fonts and colors.
		$root.on( 'click.nmlook', '.nm-look-reset', function () {
			self.state.heading = '';
			self.state.body = '';
			self.state.colors = $.extend( {}, self.cfg.defaults.colors );
			self.touched();
		} );

		// Discard changes: back to what is saved.
		$root.on( 'click.nmlook', '.nm-look-discard', function () {
			self.state = self.clone( self.saved );
			self.touched();
		} );

		$root.on( 'click.nmlook', '.nm-settings-save', function () {
			self.app.save( $( this ) );
		} );

		this.bindPreview();
	};

	/* ----- what SettingsApp saves ----- */

	Screen.prototype.collect = function () {
		return { heading: this.state.heading, body: this.state.body, colors: $.extend( {}, this.state.colors ) };
	};

	window.NMSiteLook = {
		render: function ( app ) {
			app.look = new Screen( app );
			app.look.render();
		},
		collect: function ( app ) {
			return app.look.collect();
		},
		// Exposed for the browser tests: the CSS and fonts address the screen builds for a given look.
		buildCss: buildCss,
		buildFontsUrl: buildFontsUrl,
		readability: readability
	};
} )( jQuery );
