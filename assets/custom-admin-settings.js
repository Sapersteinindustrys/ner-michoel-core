/**
 * Generic single-form UI for the custom /admin's Settings screens
 * (Appearance, Layout Toggle, Hero Slider, Admin Login Shortcut,
 * Storage, ...) — same "schema drives the renderer" idea as
 * custom-admin-cms.js, but for one singleton form instead of a list +
 * edit panel, since these are option groups, not repeatable records.
 * Talks to WordPress only through each screen's own REST route (see
 * includes/custom-admin-settings-api.php and, for Appearance/Storage,
 * the routes already in appearance-settings.php/bunny-storage.php).
 *
 * The form sits in a card with a save bar along the bottom that says
 * whether there are unsaved changes; leaving the page with unsaved
 * changes asks first, and Ctrl+S saves.
 */
( function ( $ ) {
	'use strict';

	var cfg = window.nmSettingsConfig || {};

	function esc( str ) {
		return $( '<div>' ).text( str === null || str === undefined ? '' : String( str ) ).html();
	}

	function icon( name ) {
		return '<svg class="nm-ico" aria-hidden="true" focusable="false"><use href="#nm-i-' + name + '"></use></svg>';
	}

	function toast( message, type ) {
		if ( window.NMAdmin ) {
			window.NMAdmin.toast( message, type );
		}
	}

	function apiUrl( path ) {
		return cfg.restUrl.replace( /\/$/, '' ) + '/' + path.replace( /^\//, '' );
	}

	function apiFetch( path, options ) {
		options = options || {};
		options.headers = $.extend( { 'X-WP-Nonce': cfg.nonce }, options.headers || {} );
		options.credentials = 'same-origin';
		return fetch( apiUrl( path ), options ).then( function ( res ) {
			return res.json().then(
				function ( body ) {
					if ( ! res.ok ) {
						throw new Error( ( body && body.message ) ? body.message : 'Something went wrong. Please try again.' );
					}
					return body;
				},
				function () {
					throw new Error( 'The server sent an unexpected reply (' + res.status + '). Please try again.' );
				}
			);
		} );
	}

	function debounce( fn, wait ) {
		var timer;
		return function () {
			var args = arguments;
			var ctx = this;
			clearTimeout( timer );
			timer = setTimeout( function () {
				fn.apply( ctx, args );
			}, wait );
		};
	}

	/**
	 * The Hero Slider's per-slide link field reuses the existing
	 * wp_ajax_nm_search_pages action (homepage-slider.php) — a small,
	 * page-title-only search, not the REST API — so this hits
	 * admin-ajax.php directly instead of going through apiFetch().
	 */
	function searchPages( search ) {
		return $.post( cfg.ajaxUrl, {
			action: 'nm_search_pages',
			nonce: cfg.searchPagesNonce,
			search: search
		} ).then( function ( response ) {
			return response && response.success ? response.data : [];
		} );
	}

	/* ---------------- Field rendering ---------------- */

	function renderField( key, field, value ) {
		var maxlength = field.maxlength ? ' maxlength="' + parseInt( field.maxlength, 10 ) + '"' : '';
		switch ( field.type ) {
			case 'text':
				return '<input type="text" class="nm-cms-input" data-key="' + esc( key ) + '" value="' + esc( value || '' ) + '" placeholder="' + esc( field.placeholder || '' ) + '"' + maxlength + '>';
			case 'password':
				return '<input type="password" class="nm-cms-input" data-key="' + esc( key ) + '" value="' + esc( value || '' ) + '" autocomplete="new-password">';
			case 'number':
				var attrs = '';
				if ( field.min !== undefined ) {
					attrs += ' min="' + field.min + '"';
				}
				if ( field.max !== undefined ) {
					attrs += ' max="' + field.max + '"';
				}
				return '<input type="number" class="nm-cms-input" data-key="' + esc( key ) + '" value="' + esc( value === undefined || value === null ? '' : value ) + '"' + attrs + '>';
			case 'textarea':
				return '<textarea class="nm-cms-input" data-key="' + esc( key ) + '" rows="' + ( field.rows || 3 ) + '" placeholder="' + esc( field.placeholder || '' ) + '"' + maxlength + '>' + esc( value || '' ) + '</textarea>';
			case 'checkbox':
				return '<label class="nm-settings-checkbox' + ( value ? ' is-on' : '' ) + '"><input type="checkbox" data-key="' + esc( key ) + '"' + ( value ? ' checked' : '' ) + '> <span>' + esc( field.label ) + '</span></label>';
			case 'select':
				var opts = '';
				$.each( field.options || {}, function ( optKey, optLabel ) {
					opts += '<option value="' + esc( optKey ) + '"' + ( optKey === value ? ' selected' : '' ) + '>' + esc( optLabel ) + '</option>';
				} );
				return '<select class="nm-cms-input" data-key="' + esc( key ) + '">' + opts + '</select>';
			case 'color':
				var hex = /^#[0-9a-fA-F]{6}$/.test( value ) ? value : '#000000';
				return '<div class="nm-settings-color">' +
					'<input type="color" class="nm-settings-color-native" value="' + esc( hex ) + '" aria-label="Pick a color">' +
					'<input type="text" class="nm-cms-input nm-settings-color-text" data-key="' + esc( key ) + '" value="' + esc( value || '' ) + '" placeholder="#rrggbb">' +
					'</div>';
			case 'image':
				return renderImageField( value, '' );
			default:
				return '<input type="text" class="nm-cms-input" data-key="' + esc( key ) + '" value="' + esc( value || '' ) + '">';
		}
	}

	function imagePreview( url ) {
		return url
			? '<img class="nm-cms-media-preview" src="' + esc( url ) + '" alt="">'
			: '<span class="nm-cms-media-empty">' + icon( 'image' ) + '<span>No picture yet</span></span>';
	}

	function renderImageField( id, url ) {
		var hasImage = id && url;
		return '<div class="nm-cms-media-field" data-media-kind="image">' +
			'<input type="hidden" class="nm-cms-media-id" value="' + esc( id || '' ) + '">' +
			'<div class="nm-cms-media-preview-wrap">' + imagePreview( hasImage ? url : '' ) + '</div>' +
			'<div class="nm-cms-media-actions">' +
			'<button type="button" class="nm-btn nm-btn--sm nm-cms-media-choose">' + icon( 'upload' ) + '<span>' + ( hasImage ? 'Change picture' : 'Choose picture' ) + '</span></button>' +
			'<button type="button" class="nm-btn nm-btn--sm nm-cms-btn--link nm-cms-media-remove"' + ( hasImage ? '' : ' hidden' ) + '>Remove</button>' +
			'</div>' +
			'</div>';
	}

	function readFieldValue( $f, type ) {
		switch ( type ) {
			case 'checkbox':
				return $f.find( 'input[type=checkbox]' ).prop( 'checked' );
			case 'number':
				return Number( $f.find( '.nm-cms-input' ).val() );
			case 'color':
				return $f.find( '.nm-settings-color-text' ).val();
			case 'image':
				return $f.find( '.nm-cms-media-id' ).val() || '';
			case 'select':
				return $f.find( 'select' ).val();
			default:
				return $f.find( '.nm-cms-input' ).val();
		}
	}

	function bindImagePicker( $field, onChange ) {
		var frame;
		$field.find( '.nm-cms-media-choose' ).on( 'click', function () {
			frame = wp.media( { title: 'Choose a picture', button: { text: 'Use this picture' }, library: { type: 'image' }, multiple: false } );
			frame.on( 'select', function () {
				var att = frame.state().get( 'selection' ).first().toJSON();
				var previewUrl = att.sizes && att.sizes.medium ? att.sizes.medium.url : att.url;
				$field.find( '.nm-cms-media-id' ).val( att.id );
				$field.find( '.nm-cms-media-preview-wrap' ).html( imagePreview( previewUrl ) );
				$field.find( '.nm-cms-media-choose span' ).text( 'Change picture' );
				$field.find( '.nm-cms-media-remove' ).prop( 'hidden', false );
				onChange();
			} );
			frame.open();
		} );
		$field.find( '.nm-cms-media-remove' ).on( 'click', function () {
			$field.find( '.nm-cms-media-id' ).val( '' );
			$field.find( '.nm-cms-media-preview-wrap' ).html( imagePreview( '' ) );
			$field.find( '.nm-cms-media-choose span' ).text( 'Choose picture' );
			$( this ).prop( 'hidden', true );
			onChange();
		} );
	}

	/**
	 * Search-as-you-type page results for a repeater item's link_url
	 * field, scoped to that one item so multiple slides' pickers don't
	 * collide. No-ops if the item has no .nm-settings-link-picker (only
	 * present for a 'link_url' sub-field — see renderRepeaterItem()).
	 */
	function bindLinkPicker( $item, onChange ) {
		var $picker = $item.find( '.nm-settings-link-picker' );
		if ( ! $picker.length ) {
			return;
		}
		var $input = $picker.find( '.nm-settings-link-search' );
		var $results = $picker.find( '.nm-settings-link-results' );
		var $urlField = $item.find( '.nm-settings-field[data-subkey="link_url"] .nm-cms-input' );

		$input.on(
			'input',
			debounce( function () {
				var term = $input.val();
				if ( '' === term ) {
					$results.empty().attr( 'hidden', true );
					return;
				}
				searchPages( term ).then( function ( pages ) {
					if ( ! pages.length ) {
						$results.html( '<div class="nm-settings-link-result nm-cms-muted">No pages match that.</div>' ).removeAttr( 'hidden' );
						return;
					}
					var html = $.map( pages, function ( page ) {
						return '<div class="nm-settings-link-result" data-url="' + esc( page.url ) + '">' + esc( page.title ) + '</div>';
					} ).join( '' );
					$results.html( html ).removeAttr( 'hidden' );
				} );
			}, 300 )
		);

		$results.on( 'click', '.nm-settings-link-result[data-url]', function () {
			$urlField.val( $( this ).data( 'url' ) );
			$results.empty().attr( 'hidden', true );
			$input.val( '' );
			onChange();
		} );
	}

	/* ---------------- App instance ---------------- */

	function SettingsApp( $root ) {
		this.$root = $root;
		this.key = $root.data( 'settings-type' );
		this.schema = null;
		this.dirty = false;
		this.saving = false;
		this.bindPageGuards();
		this.init();
	}

	/**
	 * Loads and draws the screen. `flash`: a success message to show once it's
	 * drawn (after a save that reloads the screen to refresh its status lines).
	 */
	SettingsApp.prototype.init = function ( flash ) {
		var self = this;
		this.dirty = false;
		this.$root.html( '<div class="nm-cms-loading"><span class="nm-spinner" aria-hidden="true"></span>Loading…</div>' );
		apiFetch( 'settings/' + this.key )
			.then( function ( schema ) {
				self.schema = schema;
				self.render();
				if ( flash ) {
					self.setState( 'saved' );
				}
			} )
			.catch( function ( err ) {
				self.$root.html( '<div class="nm-cms-error">' + icon( 'warning' ) + '<div><strong>That didn’t load</strong><span>' + esc( err.message ) + '</span></div></div>' );
			} );
	};

	SettingsApp.prototype.bindPageGuards = function () {
		var self = this;
		$( window ).on( 'beforeunload', function ( e ) {
			if ( self.dirty && ! self.saving ) {
				e.preventDefault();
				e.returnValue = '';
				return '';
			}
		} );
		$( document ).on( 'keydown', function ( e ) {
			if ( ( e.ctrlKey || e.metaKey ) && String( e.key ).toLowerCase() === 's' && self.schema && ! $( '.media-modal:visible, .nm-dialog-wrap, .nm-drawer-wrap' ).length ) {
				e.preventDefault();
				self.$root.find( '.nm-settings-save:not(:disabled)' ).trigger( 'click' );
			}
		} );
	};

	SettingsApp.prototype.setState = function ( state ) {
		var $bar = this.$root.find( '.nm-settings-bar' );
		var $state = $bar.find( '.nm-settings-bar__state' );
		$bar.toggleClass( 'is-dirty', state === 'dirty' );
		if ( state === 'dirty' ) {
			$state.html( '<span class="nm-dot" aria-hidden="true"></span>You have unsaved changes' );
		} else if ( state === 'saved' ) {
			$state.html( icon( 'check-circle' ) + 'Saved — your changes are live' );
		} else {
			$state.html( icon( 'check-circle' ) + 'Everything is saved' );
		}
	};

	SettingsApp.prototype.markDirty = function () {
		if ( ! this.dirty ) {
			this.dirty = true;
			this.setState( 'dirty' );
		}
	};

	SettingsApp.prototype.renderFields = function () {
		var schema = this.schema;
		var html = '<div class="nm-settings-form">';
		$.each( schema.fields, function ( key, field ) {
			if ( field.type === 'repeater' ) {
				return;
			}
			// A field can start a named group (the Homepage screen: Welcome, Photos, Banner at the bottom).
			if ( field.section ) {
				html += '<h4 class="nm-settings-section">' + esc( field.section ) + '</h4>';
			}
			html += '<div class="nm-settings-field" data-key="' + esc( key ) + '" data-type="' + esc( field.type ) + '">';
			if ( field.type !== 'checkbox' ) {
				html += '<label>' + esc( field.label ) + '</label>';
			}
			html += renderField( key, field, schema.values[ key ] );
			if ( field.desc ) {
				html += '<p class="nm-cms-hint">' + esc( field.desc ) + '</p>';
			}
			html += '</div>';
		} );
		html += '</div>';
		return html;
	};

	SettingsApp.prototype.render = function () {
		var self = this;
		var schema = this.schema;

		// Colors & Fonts draws itself (assets/custom-admin-look.js): it needs two columns and a live preview.
		if ( 'site_look' === this.key && window.NMSiteLook ) {
			window.NMSiteLook.render( this );
			return;
		}

		var html = '<div class="nm-settings">';

		if ( this.key === 'appearance' && schema.extra && schema.extra.palettes ) {
			html += '<div class="nm-settings-card"><div class="nm-settings-card__body">';
			html += '<h3 class="nm-settings-card__title">Ready-made color sets</h3>';
			html += '<p class="nm-settings-card__sub">Click one to try it. The preview below changes straight away; the site changes when you press Save.</p>';
			html += '<div class="nm-settings-palettes">';
			$.each( schema.extra.palettes, function ( i, palette ) {
				html += '<button type="button" class="nm-settings-palette" data-bg="' + esc( palette.bg ) + '" data-surface="' + esc( palette.surface ) + '" data-text="' + esc( palette.text ) + '" data-accent="' + esc( palette.accent ) + '">' +
					'<span class="nm-settings-palette__swatch" style="background:' + esc( palette.bg ) + ';"><span style="background:' + esc( palette.surface ) + ';"></span><i style="background:' + esc( palette.accent ) + ';"></i></span>' +
					'<span class="nm-settings-palette__label">' + esc( palette.label ) + '</span>' +
					'</button>';
			} );
			html += '</div>';
			html += '<div class="nm-settings-preview" id="nm-settings-preview">' +
				'<p class="nm-settings-preview__label" id="nm-settings-preview-label">Preview</p>' +
				'<div class="nm-settings-preview__card" id="nm-settings-preview-card">' +
				'<div class="nm-settings-preview__title" id="nm-settings-preview-title">Sample Shiur Title</div>' +
				'<div class="nm-settings-preview__text" id="nm-settings-preview-text">Rabbi Example — Series Name</div>' +
				'<button type="button" class="nm-settings-preview__button" id="nm-settings-preview-button" tabindex="-1">▶ Play</button>' +
				'</div></div>';
			html += '</div></div>';
		}

		html += '<div class="nm-settings-card"><div class="nm-settings-card__body">';

		if ( this.key === 'admin_login' && schema.extra ) {
			var statusText = schema.extra.has_password
				? 'A shortcut password is set. People can open ' + schema.extra.admin_url + ' with it.'
				: 'No shortcut password yet. ' + schema.extra.admin_url + ' uses the normal WordPress login.';
			html += '<div class="nm-settings-status"><div>' + esc( statusText ) + '</div></div>';
		}

		// What a screen reports about itself (Security: what's on, what's been turned away).
		if ( schema.extra && schema.extra.status_lines && schema.extra.status_lines.length ) {
			html += '<div class="nm-settings-status">' + $.map( schema.extra.status_lines, function ( line ) {
				return '<div>' + esc( line ) + '</div>';
			} ).join( '' ) + '</div>';
		}

		var hasRepeater = false;
		$.each( schema.fields, function ( key, field ) {
			if ( field.type === 'repeater' ) {
				hasRepeater = true;
				html += self.renderRepeater( key, field, schema.values[ key ] || [] );
			}
		} );

		if ( hasRepeater ) {
			html += '</div></div><div class="nm-settings-card"><div class="nm-settings-card__body">';
			html += '<h3 class="nm-settings-card__title">' + esc( schema.options_title || 'Options' ) + '</h3>';
			html += '<p class="nm-settings-card__sub">' + esc( schema.options_sub || 'How the banner behaves. These apply to every slide.' ) + '</p>';
		}
		html += this.renderFields();

		html += '</div>';
		html += '<div class="nm-settings-bar">' +
			'<span class="nm-settings-bar__state"></span>' +
			'<p class="nm-settings-message" hidden></p>' +
			'<button type="button" class="nm-btn nm-btn--primary nm-settings-save">' + icon( 'check' ) + '<span>Save</span></button>' +
			'</div>';
		html += '</div></div>';

		this.$root.html( html );
		this.setState( 'clean' );
		this.bindWidgets();
	};

	SettingsApp.prototype.renderRepeaterItem = function ( field, item, index ) {
		item = item || {};
		var html = '<div class="nm-settings-repeater-item" data-index="' + index + '">';
		html += '<div class="nm-settings-repeater-item__drag" title="Drag to change the order"><span class="nm-settings-repeater-item__num">' + ( index + 1 ) + '</span>' + icon( 'grip' ) + '</div>';
		html += '<div class="nm-settings-repeater-item__body">';
		$.each( field.item_fields, function ( subKey, subField ) {
			html += '<div class="nm-settings-field" data-subkey="' + esc( subKey ) + '" data-type="' + esc( subField.type ) + '">';
			html += '<label>' + esc( subField.label ) + '</label>';
			if ( 'image' === subField.type ) {
				html += renderImageField( item[ subKey ], item.image_url );
			} else {
				html += renderField( subKey, subField, item[ subKey ] );
			}
			if ( 'link_url' === subKey ) {
				html += '<div class="nm-settings-link-picker">' +
					'<input type="text" class="nm-settings-link-search" placeholder="…or search for a page by name" autocomplete="off">' +
					'<div class="nm-settings-link-results" hidden></div>' +
					'</div>';
			}
			html += '</div>';
		} );
		html += '</div>';
		html += '<button type="button" class="nm-btn nm-btn--sm nm-cms-btn--link nm-settings-repeater-remove" title="Remove this ' + esc( ( field.item_label || 'item' ).toLowerCase() ) + '">' + icon( 'trash' ) + '<span>Remove</span></button>';
		html += '</div>';
		return html;
	};

	SettingsApp.prototype.renderRepeater = function ( key, field, items ) {
		var self = this;
		var noun = ( field.item_label || 'item' ).toLowerCase();
		var html = '<div class="nm-settings-repeater" data-key="' + esc( key ) + '">';
		html += '<div class="nm-settings-repeater__head"><h3>' + esc( field.label ) + '<span class="nm-settings-repeater__count"></span></h3>' +
			'<button type="button" class="nm-btn nm-btn--soft nm-btn--sm nm-settings-repeater-add">' + icon( 'plus' ) + '<span>Add ' + esc( field.item_label || 'Item' ) + '</span></button></div>';
		html += '<p class="nm-settings-card__sub">' + esc( this.schema.repeater_sub || 'They show in this order. Drag a ' + noun + ' by its number to move it.' ) + '</p>';
		html += '<div class="nm-settings-repeater-list" data-empty="' + esc( 'No ' + noun + 's yet. Press “Add ' + ( field.item_label || 'Item' ) + '” to make the first one.' ) + '">';
		$.each( items, function ( i, item ) {
			html += self.renderRepeaterItem( field, item, i );
		} );
		html += '</div>';

		if ( field.item_fields.link_url && field.item_fields.link_text ) {
			html += '<div class="nm-settings-bulk-link">' +
				'<strong>Same button on every ' + esc( noun ) + '?</strong>' +
				'<p class="nm-cms-hint">Fills in this button link and text on every ' + esc( noun ) + ' above. You can still change any one afterwards.</p>' +
				'<div class="nm-settings-bulk-link__row">' +
				'<input type="text" class="nm-cms-input nm-settings-bulk-link-url" placeholder="Link, e.g. https://…">' +
				'<input type="text" class="nm-cms-input nm-settings-bulk-link-text" placeholder="Button text, e.g. Learn More">' +
				'<button type="button" class="nm-btn nm-settings-bulk-link-apply">Apply to all</button>' +
				'</div></div>';
		}

		html += '</div>';
		return html;
	};

	SettingsApp.prototype.updatePreview = function () {
		if ( 'appearance' !== this.key ) {
			return;
		}
		var $root = this.$root;
		function val( key, fallback ) {
			var v = $root.find( '.nm-settings-field[data-key="' + key + '"] .nm-settings-color-text' ).val();
			return v || fallback;
		}
		var bg = val( 'nm_color_bg', '#121212' );
		var surface = val( 'nm_color_surface', '#181818' );
		var text = val( 'nm_color_text', '#ffffff' );
		var accent = val( 'nm_color_accent', '#2f8f5b' );
		$root.find( '#nm-settings-preview' ).css( 'background', bg );
		$root.find( '#nm-settings-preview-label' ).css( 'color', text );
		$root.find( '#nm-settings-preview-card' ).css( 'background', surface );
		$root.find( '#nm-settings-preview-title' ).css( 'color', text );
		$root.find( '#nm-settings-preview-text' ).css( { color: text, opacity: 0.65 } );
		$root.find( '#nm-settings-preview-button' ).css( { background: accent, color: '#fff' } );
	};

	SettingsApp.prototype.renumber = function ( $rep ) {
		var $items = $rep.find( '.nm-settings-repeater-item' );
		$items.each( function ( i ) {
			$( this ).find( '.nm-settings-repeater-item__num' ).text( i + 1 );
		} );
		$rep.find( '.nm-settings-repeater__count' ).text( $items.length ? '(' + $items.length + ')' : '' );
	};

	SettingsApp.prototype.bindWidgets = function () {
		var self = this;
		var $root = this.$root;
		var dirty = function () {
			self.markDirty();
		};

		// Re-drawn after some saves, so delegated handlers are reset first.
		$root.off( '.nmset' );
		$( document ).off( 'click.nmset' );

		// Any edit marks the form unsaved — except typing in a page search box.
		$root.on( 'input.nmset change.nmset', '.nm-settings-card', function ( e ) {
			if ( $( e.target ).is( '.nm-settings-link-search, .nm-settings-bulk-link-url, .nm-settings-bulk-link-text' ) ) {
				return;
			}
			dirty();
		} );

		$root.on( 'change.nmset', '.nm-settings-checkbox input', function () {
			$( this ).closest( '.nm-settings-checkbox' ).toggleClass( 'is-on', $( this ).prop( 'checked' ) );
		} );

		$root.find( '.nm-settings-color' ).each( function () {
			var $wrap = $( this );
			var $native = $wrap.find( '.nm-settings-color-native' );
			var $text = $wrap.find( '.nm-settings-color-text' );
			$native.on( 'input', function () {
				$text.val( $( this ).val() );
				self.updatePreview();
			} );
			$text.on( 'input', function () {
				var v = $( this ).val();
				if ( /^#[0-9a-fA-F]{6}$/.test( v ) ) {
					$native.val( v );
				}
				self.updatePreview();
			} );
		} );

		if ( 'appearance' === this.key ) {
			$root.find( '.nm-settings-palette' ).on( 'click', function () {
				var $btn = $( this );
				var map = { nm_color_bg: 'bg', nm_color_surface: 'surface', nm_color_text: 'text', nm_color_accent: 'accent' };
				$.each( map, function ( fieldKey, dataAttr ) {
					var color = $btn.data( dataAttr );
					var $field = $root.find( '.nm-settings-field[data-key="' + fieldKey + '"]' );
					$field.find( '.nm-settings-color-native' ).val( color );
					$field.find( '.nm-settings-color-text' ).val( color );
				} );
				$root.find( '.nm-settings-palette' ).removeClass( 'is-picked' );
				$btn.addClass( 'is-picked' );
				self.updatePreview();
				dirty();
			} );
			this.updatePreview();
		}

		if ( 'admin_login' === this.key && this.schema.extra ) {
			var $select = $root.find( '.nm-settings-field[data-key="login_as"] select' );
			var currentId = this.schema.values.login_as;
			$select.append( '<option value="0">— First available admin —</option>' );
			$.each( this.schema.extra.admins, function ( i, admin ) {
				var $opt = $( '<option></option>' ).val( admin.id ).text( admin.name );
				if ( admin.id === currentId ) {
					$opt.prop( 'selected', true );
				}
				$select.append( $opt );
			} );
		}

		$root.find( '.nm-settings-form .nm-cms-media-field' ).each( function () {
			bindImagePicker( $( this ), dirty );
		} );

		$root.find( '.nm-settings-repeater' ).each( function () {
			var $rep = $( this );
			var repKey = $rep.data( 'key' );
			var field = self.schema.fields[ repKey ];
			var $list = $rep.find( '.nm-settings-repeater-list' );
			if ( $list.sortable ) {
				$list.sortable( {
					handle: '.nm-settings-repeater-item__drag',
					update: function () {
						self.renumber( $rep );
						dirty();
					}
				} );
			}
			$list.find( '.nm-settings-repeater-item' ).each( function () {
				bindImagePicker( $( this ).find( '.nm-cms-media-field' ), dirty );
				bindLinkPicker( $( this ), dirty );
			} );
			self.renumber( $rep );

			$rep.find( '.nm-settings-repeater-add' ).on( 'click', function () {
				var $item = $( self.renderRepeaterItem( field, {}, $list.children().length ) );
				$list.append( $item );
				bindImagePicker( $item.find( '.nm-cms-media-field' ), dirty );
				bindLinkPicker( $item, dirty );
				self.renumber( $rep );
				dirty();
				if ( $item[ 0 ].scrollIntoView ) {
					$item[ 0 ].scrollIntoView( { behavior: 'smooth', block: 'center' } );
				}
			} );
			$list.on( 'click', '.nm-settings-repeater-remove', function () {
				var $item = $( this ).closest( '.nm-settings-repeater-item' );
				var remove = function () {
					$item.remove();
					self.renumber( $rep );
					dirty();
				};
				if ( window.NMAdmin ) {
					window.NMAdmin.confirm( {
						title: 'Remove this ' + ( field.item_label || 'item' ).toLowerCase() + '?',
						message: 'It comes off the list now, and off the site when you press Save.',
						confirmText: 'Remove',
						cancelText: 'Keep it',
						danger: true
					} ).then( function ( ok ) {
						if ( ok ) {
							remove();
						}
					} );
				} else {
					remove();
				}
			} );

			$rep.find( '.nm-settings-bulk-link-apply' ).on( 'click', function () {
				var url = $rep.find( '.nm-settings-bulk-link-url' ).val();
				var text = $rep.find( '.nm-settings-bulk-link-text' ).val();
				var count = 0;
				$list.find( '.nm-settings-repeater-item' ).each( function () {
					var $item = $( this );
					$item.find( '.nm-settings-field[data-subkey="link_url"] .nm-cms-input' ).val( url );
					$item.find( '.nm-settings-field[data-subkey="link_text"] .nm-cms-input' ).val( text );
					count++;
				} );
				if ( count ) {
					dirty();
					toast( 'Button added to all ' + count + '. Press Save to keep it.', 'info' );
				}
			} );
		} );

		$( document ).on( 'click.nmset', function ( e ) {
			if ( ! $( e.target ).closest( '.nm-settings-link-picker' ).length ) {
				$root.find( '.nm-settings-link-results' ).attr( 'hidden', true );
			}
		} );

		$root.find( '.nm-settings-save' ).on( 'click', function () {
			self.save( $( this ) );
		} );
	};

	SettingsApp.prototype.collect = function () {
		if ( 'site_look' === this.key && window.NMSiteLook ) {
			return window.NMSiteLook.collect( this );
		}
		var schema = this.schema;
		var $root = this.$root;
		var data = {};

		$.each( schema.fields, function ( key, field ) {
			if ( 'repeater' === field.type ) {
				return;
			}
			var $f = $root.find( '.nm-settings-form > .nm-settings-field[data-key="' + key + '"]' );
			data[ key ] = readFieldValue( $f, field.type );
		} );

		$root.find( '.nm-settings-repeater' ).each( function () {
			var $rep = $( this );
			var repKey = $rep.data( 'key' );
			var repField = schema.fields[ repKey ];
			var items = [];
			$rep.find( '.nm-settings-repeater-item' ).each( function () {
				var $item = $( this );
				var obj = {};
				$.each( repField.item_fields, function ( subKey, subField ) {
					var $f = $item.find( '.nm-settings-field[data-subkey="' + subKey + '"]' );
					obj[ subKey ] = readFieldValue( $f, subField.type );
				} );
				items.push( obj );
			} );
			data[ repKey ] = items;
		} );

		return data;
	};

	SettingsApp.prototype.save = function ( $btn ) {
		var self = this;
		var $msg = this.$root.find( '.nm-settings-message' );
		$btn.prop( 'disabled', true ).find( 'span' ).text( 'Saving…' );
		$msg.prop( 'hidden', true );
		this.saving = true;
		var payload = this.collect();

		apiFetch( this.schema.rest_path, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( payload )
		} )
			.then( function () {
				self.saving = false;
				self.dirty = false;
				$btn.prop( 'disabled', false ).find( 'span' ).text( 'Save' );
				if ( self.onSaved ) {
					self.onSaved();
				}
				toast( 'Saved. Your changes are live on the site.', 'success' );
				if ( 'admin_login' === self.key || 'security' === self.key ) {
					self.init( 'Saved.' ); // Re-fetch so the status line reflects the new state.
				} else {
					self.setState( 'saved' );
				}
			} )
			.catch( function ( err ) {
				self.saving = false;
				$btn.prop( 'disabled', false ).find( 'span' ).text( 'Save' );
				$msg.removeClass( 'nm-settings-message--success' ).addClass( 'nm-settings-message--error' ).text( err.message ).prop( 'hidden', false );
				toast( err.message, 'error' );
			} );
	};

	function mountAll() {
		$( '.nm-settings-root[data-settings-type]' ).each( function () {
			new SettingsApp( $( this ) ); // eslint-disable-line no-new
		} );
	}

	$( mountAll );
} )( jQuery );
