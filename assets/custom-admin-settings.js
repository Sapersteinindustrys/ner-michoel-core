/**
 * Generic single-form UI for the custom /admin's Settings screens
 * (Appearance, Layout Toggle, Hero Slider, Admin Login Shortcut,
 * Storage) — same "schema drives the renderer" idea as
 * custom-admin-cms.js, but for one singleton form instead of a list +
 * edit modal, since these are option groups, not repeatable records.
 * Talks to WordPress only through each screen's own REST route (see
 * includes/custom-admin-settings-api.php and, for Appearance/Storage,
 * the routes already in appearance-settings.php/bunny-storage.php).
 */
( function ( $ ) {
	'use strict';

	var cfg = window.nmSettingsConfig || {};

	function esc( str ) {
		return $( '<div>' ).text( str === null || str === undefined ? '' : String( str ) ).html();
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
						throw new Error( ( body && body.message ) ? body.message : 'Request failed.' );
					}
					return body;
				},
				function () {
					throw new Error( 'Unexpected server response (' + res.status + ').' );
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
		switch ( field.type ) {
			case 'text':
				return '<input type="text" class="nm-cms-input" data-key="' + esc( key ) + '" value="' + esc( value || '' ) + '" placeholder="' + esc( field.placeholder || '' ) + '">';
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
				return '<textarea class="nm-cms-input" data-key="' + esc( key ) + '" rows="' + ( field.rows || 3 ) + '">' + esc( value || '' ) + '</textarea>';
			case 'checkbox':
				return '<label class="nm-settings-checkbox"><input type="checkbox" data-key="' + esc( key ) + '"' + ( value ? ' checked' : '' ) + '> ' + esc( field.label ) + '</label>';
			case 'select':
				var opts = '';
				$.each( field.options || {}, function ( optKey, optLabel ) {
					opts += '<option value="' + esc( optKey ) + '"' + ( optKey === value ? ' selected' : '' ) + '>' + esc( optLabel ) + '</option>';
				} );
				return '<select class="nm-cms-input" data-key="' + esc( key ) + '">' + opts + '</select>';
			case 'color':
				var hex = /^#[0-9a-fA-F]{6}$/.test( value ) ? value : '#000000';
				return '<div class="nm-settings-color">' +
					'<input type="color" class="nm-settings-color-native" value="' + esc( hex ) + '">' +
					'<input type="text" class="nm-cms-input nm-settings-color-text" data-key="' + esc( key ) + '" value="' + esc( value || '' ) + '" placeholder="#rrggbb">' +
					'</div>';
			case 'image':
				return renderImageField( value, '' );
			default:
				return '<input type="text" class="nm-cms-input" data-key="' + esc( key ) + '" value="' + esc( value || '' ) + '">';
		}
	}

	function renderImageField( id, url ) {
		var hasImage = id && url;
		return '<div class="nm-cms-media-field" data-media-kind="image">' +
			'<input type="hidden" class="nm-cms-media-id" value="' + ( id || '' ) + '">' +
			'<div class="nm-cms-media-preview-wrap">' + ( hasImage ? '<img class="nm-cms-media-preview" src="' + esc( url ) + '">' : '<span class="nm-cms-muted">No image selected.</span>' ) + '</div>' +
			'<button type="button" class="nm-cms-btn nm-cms-media-choose">Choose Image</button> ' +
			'<button type="button" class="nm-cms-btn nm-cms-btn--link nm-cms-media-remove"' + ( hasImage ? '' : ' style="display:none;"' ) + '>Remove</button>' +
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

	function bindImagePicker( $field ) {
		var frame;
		$field.find( '.nm-cms-media-choose' ).on( 'click', function () {
			frame = wp.media( { title: 'Choose Image', library: { type: 'image' }, multiple: false } );
			frame.on( 'select', function () {
				var att = frame.state().get( 'selection' ).first().toJSON();
				var previewUrl = att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url;
				$field.find( '.nm-cms-media-id' ).val( att.id );
				$field.find( '.nm-cms-media-preview-wrap' ).html( '<img class="nm-cms-media-preview" src="' + previewUrl + '">' );
				$field.find( '.nm-cms-media-remove' ).show();
			} );
			frame.open();
		} );
		$field.find( '.nm-cms-media-remove' ).on( 'click', function () {
			$field.find( '.nm-cms-media-id' ).val( '' );
			$field.find( '.nm-cms-media-preview-wrap' ).html( '<span class="nm-cms-muted">No image selected.</span>' );
			$( this ).hide();
		} );
	}

	/**
	 * Search-as-you-type page results for a repeater item's link_url
	 * field, scoped to that one item so multiple slides' pickers don't
	 * collide. No-ops if the item has no .nm-settings-link-picker (only
	 * present for a 'link_url' sub-field — see renderRepeaterItem()).
	 */
	function bindLinkPicker( $item ) {
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
						$results.html( '<div class="nm-settings-link-result nm-cms-muted">No matches.</div>' ).removeAttr( 'hidden' );
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
		} );
	}

	/* ---------------- App instance ---------------- */

	function SettingsApp( $root ) {
		this.$root = $root;
		this.key = $root.data( 'settings-type' );
		this.schema = null;
		this.init();
	}

	SettingsApp.prototype.init = function () {
		var self = this;
		this.$root.html( '<div class="nm-cms-loading">Loading…</div>' );
		apiFetch( 'settings/' + this.key )
			.then( function ( schema ) {
				self.schema = schema;
				self.render();
			} )
			.catch( function ( err ) {
				self.$root.html( '<div class="nm-cms-error">' + esc( err.message ) + '</div>' );
			} );
	};

	SettingsApp.prototype.render = function () {
		var schema = this.schema;
		var html = '<div class="nm-settings">';

		if ( this.key === 'appearance' && schema.extra && schema.extra.palettes ) {
			html += '<div class="nm-settings-palettes">';
			$.each( schema.extra.palettes, function ( i, palette ) {
				html += '<button type="button" class="nm-settings-palette" data-bg="' + esc( palette.bg ) + '" data-surface="' + esc( palette.surface ) + '" data-text="' + esc( palette.text ) + '" data-accent="' + esc( palette.accent ) + '">' +
					'<span class="nm-settings-palette__swatch" style="background:' + esc( palette.bg ) + ';"><span style="background:' + esc( palette.surface ) + ';"></span><i style="background:' + esc( palette.accent ) + ';"></i></span>' +
					'<span class="nm-settings-palette__label">' + esc( palette.label ) + '</span>' +
					'</button>';
			} );
			html += '</div>';
			html += '<div class="nm-settings-preview" id="nm-settings-preview">' +
				'<div class="nm-settings-preview__card" id="nm-settings-preview-card">' +
				'<div class="nm-settings-preview__title" id="nm-settings-preview-title">Sample Shiur Title</div>' +
				'<div class="nm-settings-preview__text" id="nm-settings-preview-text">Rabbi Example — Series Name</div>' +
				'<button type="button" class="nm-settings-preview__button" id="nm-settings-preview-button">▶ Play</button>' +
				'</div></div>';
		}

		if ( this.key === 'admin_login' && schema.extra ) {
			var statusText = schema.extra.has_password
				? 'A shortcut password is set for ' + schema.extra.admin_url + '.'
				: schema.extra.admin_url + ' currently falls back to the normal WordPress login.';
			html += '<p class="nm-settings-status">' + esc( statusText ) + '</p>';
		}

		html += '<div class="nm-settings-form">';
		$.each( schema.fields, function ( key, field ) {
			if ( field.type === 'repeater' ) {
				return;
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

		var self = this;
		$.each( schema.fields, function ( key, field ) {
			if ( field.type !== 'repeater' ) {
				return;
			}
			html += self.renderRepeater( key, field, schema.values[ key ] || [] );
		} );

		html += '<p class="nm-settings-message" style="display:none;"></p>';
		html += '<button type="button" class="nm-cms-btn nm-cms-btn--primary nm-settings-save">Save</button>';
		html += '</div>';

		this.$root.html( html );
		this.bindWidgets();
	};

	SettingsApp.prototype.renderRepeaterItem = function ( field, item, index ) {
		item = item || {};
		var html = '<div class="nm-settings-repeater-item" data-index="' + index + '">';
		html += '<div class="nm-settings-repeater-item__drag" title="Drag to reorder">&#9776;</div>';
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
					'<input type="text" class="nm-settings-link-search" placeholder="Search pages…" autocomplete="off">' +
					'<div class="nm-settings-link-results" hidden></div>' +
					'</div>';
			}
			html += '</div>';
		} );
		html += '</div>';
		html += '<button type="button" class="nm-cms-btn nm-cms-btn--link nm-settings-repeater-remove">Remove</button>';
		html += '</div>';
		return html;
	};

	SettingsApp.prototype.renderRepeater = function ( key, field, items ) {
		var self = this;
		var html = '<div class="nm-settings-repeater" data-key="' + esc( key ) + '">';
		html += '<h3>' + esc( field.label ) + '</h3>';
		html += '<div class="nm-settings-repeater-list">';
		$.each( items, function ( i, item ) {
			html += self.renderRepeaterItem( field, item, i );
		} );
		html += '</div>';
		html += '<button type="button" class="nm-cms-btn nm-settings-repeater-add">+ Add ' + esc( field.item_label || 'Item' ) + '</button>';

		if ( field.item_fields.link_url && field.item_fields.link_text ) {
			html += '<div class="nm-settings-bulk-link">' +
				'<strong>Set one button for every ' + esc( ( field.item_label || 'item' ).toLowerCase() ) + '</strong>' +
				'<p class="nm-cms-hint">Fills in the same button link + text on every ' + esc( ( field.item_label || 'item' ).toLowerCase() ) + ' below, including new ones you add afterward.</p>' +
				'<div class="nm-settings-bulk-link__row">' +
				'<input type="text" class="nm-cms-input nm-settings-bulk-link-url" placeholder="https://">' +
				'<input type="text" class="nm-cms-input nm-settings-bulk-link-text" placeholder="Learn More">' +
				'<button type="button" class="nm-cms-btn nm-settings-bulk-link-apply">Apply to All</button>' +
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
		$root.find( '#nm-settings-preview-card' ).css( 'background', surface );
		$root.find( '#nm-settings-preview-title' ).css( 'color', text );
		$root.find( '#nm-settings-preview-text' ).css( { color: text, opacity: 0.65 } );
		$root.find( '#nm-settings-preview-button' ).css( { background: accent, color: '#fff' } );
	};

	SettingsApp.prototype.bindWidgets = function () {
		var self = this;
		var $root = this.$root;

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
				self.updatePreview();
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

		$root.find( '.nm-cms-media-field' ).each( function () {
			bindImagePicker( $( this ) );
		} );

		$root.find( '.nm-settings-repeater' ).each( function () {
			var $rep = $( this );
			var repKey = $rep.data( 'key' );
			var field = self.schema.fields[ repKey ];
			var $list = $rep.find( '.nm-settings-repeater-list' );
			if ( $list.sortable ) {
				$list.sortable( { handle: '.nm-settings-repeater-item__drag' } );
			}
			$list.find( '.nm-settings-repeater-item' ).each( function () {
				bindLinkPicker( $( this ) );
			} );
			$rep.find( '.nm-settings-repeater-add' ).on( 'click', function () {
				var $item = $( self.renderRepeaterItem( field, {}, $list.children().length ) );
				$list.append( $item );
				bindImagePicker( $item.find( '.nm-cms-media-field' ) );
				bindLinkPicker( $item );
			} );
			$list.on( 'click', '.nm-settings-repeater-remove', function () {
				$( this ).closest( '.nm-settings-repeater-item' ).remove();
			} );

			$rep.find( '.nm-settings-bulk-link-apply' ).on( 'click', function () {
				var url = $rep.find( '.nm-settings-bulk-link-url' ).val();
				var text = $rep.find( '.nm-settings-bulk-link-text' ).val();
				$list.find( '.nm-settings-repeater-item' ).each( function () {
					var $item = $( this );
					$item.find( '.nm-settings-field[data-subkey="link_url"] .nm-cms-input' ).val( url );
					$item.find( '.nm-settings-field[data-subkey="link_text"] .nm-cms-input' ).val( text );
				} );
			} );
		} );

		$root.find( '.nm-settings-save' ).on( 'click', function () {
			self.save( $( this ) );
		} );
	};

	SettingsApp.prototype.collect = function () {
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
		$btn.prop( 'disabled', true ).text( 'Saving…' );
		var payload = this.collect();

		apiFetch( this.schema.rest_path, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( payload )
		} )
			.then( function () {
				$btn.prop( 'disabled', false ).text( 'Save' );
				$msg.removeClass( 'nm-settings-message--error' ).addClass( 'nm-settings-message--success' ).text( 'Saved.' ).show();
				if ( 'admin_login' === self.key ) {
					self.init(); // Re-fetch so the status line reflects the new state.
				}
			} )
			.catch( function ( err ) {
				$btn.prop( 'disabled', false ).text( 'Save' );
				$msg.removeClass( 'nm-settings-message--success' ).addClass( 'nm-settings-message--error' ).text( err.message ).show();
			} );
	};

	function mountAll() {
		$( '.nm-settings-root[data-settings-type]' ).each( function () {
			new SettingsApp( $( this ) ); // eslint-disable-line no-new
		} );
	}

	$( mountAll );
} )( jQuery );
