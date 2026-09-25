/**
 * Generic list + edit UI for the custom /admin's content screens —
 * one renderer driven entirely by the schema each content type's REST
 * endpoint reports (see includes/custom-admin-api.php), so adding a
 * field or a whole content type is a PHP config change, not new JS.
 *
 * Deliberately NOT wp-admin's own list tables/post editor rendered in
 * an iframe — this is our own markup/CSS talking to WordPress only
 * through the REST API underneath (cookie + nonce auth, same as the
 * block editor uses).
 */
( function ( $ ) {
	'use strict';

	var cfg = window.nmCmsConfig || {};
	var schemaCache = {};
	var termsCache = {};

	function esc( str ) {
		return $( '<div>' ).text( str === null || str === undefined ? '' : String( str ) ).html();
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

	function getSchema( type ) {
		if ( schemaCache[ type ] ) {
			return Promise.resolve( schemaCache[ type ] );
		}
		return apiFetch( 'cms/' + type + '/schema' ).then( function ( schema ) {
			schemaCache[ type ] = schema;
			return schema;
		} );
	}

	function getTerms( taxonomy ) {
		if ( termsCache[ taxonomy ] ) {
			return termsCache[ taxonomy ];
		}
		termsCache[ taxonomy ] = apiFetch( 'terms/' + taxonomy );
		return termsCache[ taxonomy ];
	}

	/* ---------------- Field rendering ---------------- */

	function renderFieldControl( field, value ) {
		switch ( field.type ) {
			case 'text':
				return '<input type="text" class="nm-cms-input" value="' + esc( value || '' ) + '">';
			case 'email':
				return '<input type="email" class="nm-cms-input" value="' + esc( value || '' ) + '">';
			case 'number':
				return '<input type="number" class="nm-cms-input" value="' + esc( value === undefined || value === null ? '' : value ) + '">';
			case 'textarea':
				return '<textarea class="nm-cms-input" rows="' + ( field.rows || 4 ) + '">' + esc( value || '' ) + '</textarea>';
			case 'lines':
				return '<textarea class="nm-cms-input nm-cms-mono" rows="6" placeholder="One per line">' + esc( value || '' ) + '</textarea>';
			case 'select':
				var opts = '';
				$.each( field.options || {}, function ( optKey, optLabel ) {
					opts += '<option value="' + esc( optKey ) + '"' + ( optKey === value ? ' selected' : '' ) + '>' + esc( optLabel ) + '</option>';
				} );
				return '<select class="nm-cms-input">' + opts + '</select>';
			case 'taxonomy':
				return '<select class="nm-cms-input nm-cms-taxonomy-select" data-taxonomy="' + esc( field.taxonomy ) + '" data-selected="' + esc( value || '' ) + '"><option value="">— None —</option></select>';
			case 'term_parent':
				return '<select class="nm-cms-input nm-cms-parent-select" data-selected="' + esc( value || '' ) + '"><option value="">— None (top level) —</option></select>';
			case 'image':
				return renderMediaField( 'image', value );
			case 'media':
				return renderMediaField( 'media', value );
			case 'media_multi':
				return renderMediaMultiField( value );
			case 'readonly':
				return '<div class="nm-cms-readonly-text">' + ( value ? esc( value ) : '<span class="nm-cms-muted">—</span>' ) + '</div>';
			case 'readonly_textarea':
				return '<div class="nm-cms-readonly-text nm-cms-readonly-textarea">' + ( value ? esc( value ).replace( /\n/g, '<br>' ) : '<span class="nm-cms-muted">—</span>' ) + '</div>';
			default:
				return '<input type="text" class="nm-cms-input" value="' + esc( value || '' ) + '">';
		}
	}

	function renderMediaField( kind, value ) {
		var hasFile = value && value.id;
		var preview;
		if ( hasFile && kind === 'image' ) {
			preview = '<img class="nm-cms-media-preview" src="' + esc( value.url ) + '">';
		} else if ( hasFile ) {
			preview = '<span class="nm-cms-media-filename">' + esc( value.filename || value.url ) + '</span>';
		} else {
			preview = '<span class="nm-cms-muted">No file selected.</span>';
		}
		return '<div class="nm-cms-media-field" data-media-kind="' + kind + '">' +
			'<input type="hidden" class="nm-cms-media-id" value="' + ( hasFile ? value.id : '' ) + '">' +
			'<div class="nm-cms-media-preview-wrap">' + preview + '</div>' +
			'<button type="button" class="nm-cms-btn nm-cms-media-choose">Choose ' + ( kind === 'image' ? 'Image' : 'File' ) + '</button> ' +
			'<button type="button" class="nm-cms-btn nm-cms-btn--link nm-cms-media-remove"' + ( hasFile ? '' : ' style="display:none;"' ) + '>Remove</button>' +
			'</div>';
	}

	function renderMediaMultiField( value ) {
		value = value || [];
		var items = $.map( value, function ( img ) {
			return '<div class="nm-cms-media-multi-item" data-id="' + img.id + '"><img src="' + esc( img.url ) + '"><button type="button" class="nm-cms-media-multi-remove">&times;</button></div>';
		} ).join( '' );
		return '<div class="nm-cms-media-multi-field">' +
			'<div class="nm-cms-media-multi-list">' + items + '</div>' +
			'<button type="button" class="nm-cms-btn nm-cms-media-multi-add">Add Images</button>' +
			'<p class="nm-cms-hint">Drag thumbnails to reorder.</p>' +
			'</div>';
	}

	/* ---------------- App instance (one per mounted tab) ---------------- */

	function CmsApp( $root ) {
		this.$root = $root;
		this.type = $root.data( 'cms-type' );
		this.args = $root.data( 'cms-args' ) || {};
		this.page = 1;
		this.search = '';
		this.filters = {};
		this.schema = null;
		this.init();
	}

	CmsApp.prototype.init = function () {
		var self = this;
		this.$root.html( '<div class="nm-cms-loading">Loading…</div>' );
		getSchema( this.type )
			.then( function ( schema ) {
				self.schema = schema;
				self.renderShell();
				self.loadList();
			} )
			.catch( function ( err ) {
				self.$root.html( '<div class="nm-cms-error">' + esc( err.message ) + '</div>' );
			} );
	};

	CmsApp.prototype.renderShell = function () {
		var self = this;
		var schema = this.schema;
		var html = '<div class="nm-cms">';
		html += '<div class="nm-cms-toolbar">';
		html += '<input type="search" class="nm-cms-search nm-cms-input" placeholder="Search…">';
		html += '<div class="nm-cms-filters"></div>';
		html += '<div class="nm-cms-toolbar-spacer"></div>';
		if ( ! schema.readonly ) {
			html += '<button type="button" class="nm-cms-btn nm-cms-btn--primary nm-cms-add">+ Add New ' + esc( schema.label ) + '</button>';
		}
		html += '</div>';
		html += '<div class="nm-cms-table-wrap"><table class="nm-cms-table"><thead><tr>';
		$.each( schema.list_columns, function ( i, col ) {
			html += '<th>' + esc( col.label ) + '</th>';
		} );
		html += '<th class="nm-cms-col-actions">Actions</th>';
		html += '</tr></thead><tbody class="nm-cms-tbody"></tbody></table></div>';
		html += '<div class="nm-cms-pagination"></div>';
		html += '<div class="nm-cms-modal-root"></div>';
		html += '</div>';
		this.$root.html( html );

		this.$root.find( '.nm-cms-search' ).on(
			'input',
			debounce( function () {
				self.search = $( this ).val();
				self.page = 1;
				self.loadList();
			}, 350 )
		);

		this.$root.find( '.nm-cms-add' ).on( 'click', function () {
			self.openForm( null );
		} );

		this.renderFilters();
	};

	CmsApp.prototype.renderFilters = function () {
		var self = this;
		var taxonomies = this.schema.taxonomies || [];
		if ( ! taxonomies.length ) {
			return;
		}
		var $wrap = this.$root.find( '.nm-cms-filters' );
		$.each( taxonomies, function ( i, taxonomy ) {
			var $select = $( '<select class="nm-cms-input nm-cms-filter"><option value="">All</option></select>' );
			$wrap.append( $select );
			getTerms( taxonomy ).then( function ( terms ) {
				$.each( terms, function ( j, term ) {
					$select.append( $( '<option></option>' ).val( term.id ).text( term.name ) );
				} );
			} );
			$select.on( 'change', function () {
				var val = $( this ).val();
				if ( val ) {
					self.filters[ taxonomy ] = val;
				} else {
					delete self.filters[ taxonomy ];
				}
				self.page = 1;
				self.loadList();
			} );
		} );
	};

	CmsApp.prototype.loadList = function () {
		var self = this;
		var params = $.extend( {}, this.args, this.filters, {
			page: this.page,
			per_page: 20,
			search: this.search
		} );
		var qs = $.param( params );

		this.$root.find( '.nm-cms-tbody' ).html( '<tr><td colspan="99" class="nm-cms-loading-row">Loading…</td></tr>' );

		apiFetch( 'cms/' + this.type + '?' + qs )
			.then( function ( result ) {
				self.renderRows( result );
			} )
			.catch( function ( err ) {
				self.$root.find( '.nm-cms-tbody' ).html( '<tr><td colspan="99" class="nm-cms-error">' + esc( err.message ) + '</td></tr>' );
			} );
	};

	CmsApp.prototype.renderCell = function ( item, col ) {
		switch ( col.render ) {
			case 'title':
				return '<strong>' + esc( item.title || item.name ) + '</strong>' + ( item.thumbnail ? '<br><img class="nm-cms-thumb" src="' + esc( item.thumbnail ) + '">' : '' );
			case 'taxonomy':
				return item[ col.key ] && item[ col.key ].length ? esc( item[ col.key ].join( ', ' ) ) : '<span class="nm-cms-muted">—</span>';
			case 'number':
				return esc( item[ col.key ] || 0 );
			case 'status':
				return '<span class="nm-cms-status nm-cms-status--' + esc( item.status ) + '">' + esc( item.status ) + '</span>';
			case 'date':
				return esc( item.date );
			case 'meta':
				return item[ col.key ] ? esc( item[ col.key ] ) : '<span class="nm-cms-muted">—</span>';
			case 'meta_trim':
				return item[ col.key ] ? esc( item[ col.key ] ) : '<span class="nm-cms-muted">—</span>';
			case 'term_count':
				return esc( item.count || 0 );
			case 'term_meta':
				return item[ col.key ] ? esc( item[ col.key ] ) : '<span class="nm-cms-muted">—</span>';
			default:
				return esc( item[ col.key ] );
		}
	};

	CmsApp.prototype.renderRows = function ( result ) {
		var self = this;
		var schema = this.schema;
		var $tbody = this.$root.find( '.nm-cms-tbody' );

		if ( ! result.items.length ) {
			$tbody.html( '<tr><td colspan="99" class="nm-cms-empty">Nothing here yet.</td></tr>' );
		} else {
			var rows = $.map( result.items, function ( item ) {
				var cells = $.map( schema.list_columns, function ( col ) {
					return '<td>' + self.renderCell( item, col ) + '</td>';
				} ).join( '' );
				var actions = '<td class="nm-cms-col-actions">';
				actions += '<button type="button" class="nm-cms-row-btn nm-cms-edit" data-id="' + item.id + '">' + ( schema.readonly ? 'View' : 'Edit' ) + '</button>';
				if ( item.edit_link ) {
					actions += '<a class="nm-cms-row-btn" href="' + esc( item.edit_link ) + '" target="_blank" rel="noopener">View on Site</a>';
				}
				actions += '<button type="button" class="nm-cms-row-btn nm-cms-row-btn--danger nm-cms-delete" data-id="' + item.id + '">Delete</button>';
				actions += '</td>';
				return '<tr>' + cells + actions + '</tr>';
			} ).join( '' );
			$tbody.html( rows );
		}

		this.renderPagination( result );

		$tbody.find( '.nm-cms-edit' ).on( 'click', function () {
			self.openForm( $( this ).data( 'id' ) );
		} );
		$tbody.find( '.nm-cms-delete' ).on( 'click', function () {
			var id = $( this ).data( 'id' );
			if ( ! window.confirm( 'Delete this ' + schema.label.toLowerCase() + '? This can\'t be undone from here.' ) ) {
				return;
			}
			apiFetch( 'cms/' + self.type + '/' + id, { method: 'DELETE' } )
				.then( function () {
					self.loadList();
				} )
				.catch( function ( err ) {
					window.alert( err.message );
				} );
		} );
	};

	CmsApp.prototype.renderPagination = function ( result ) {
		var self = this;
		var $p = this.$root.find( '.nm-cms-pagination' );
		if ( result.total_pages <= 1 ) {
			$p.empty();
			return;
		}
		var html = '<button type="button" class="nm-cms-btn nm-cms-prev"' + ( result.page <= 1 ? ' disabled' : '' ) + '>← Prev</button>';
		html += '<span class="nm-cms-page-indicator">Page ' + result.page + ' of ' + result.total_pages + ' (' + result.total + ' total)</span>';
		html += '<button type="button" class="nm-cms-btn nm-cms-next"' + ( result.page >= result.total_pages ? ' disabled' : '' ) + '>Next →</button>';
		$p.html( html );
		$p.find( '.nm-cms-prev' ).on( 'click', function () {
			self.page = Math.max( 1, self.page - 1 );
			self.loadList();
		} );
		$p.find( '.nm-cms-next' ).on( 'click', function () {
			self.page = self.page + 1;
			self.loadList();
		} );
	};

	/* ---------------- Edit/Add modal ---------------- */

	CmsApp.prototype.openForm = function ( id ) {
		var self = this;
		var schema = this.schema;
		var isNew = ! id;

		var $overlay = $( '<div class="nm-cms-overlay"></div>' );
		var $modal = $( '<div class="nm-cms-modal"></div>' );
		$modal.append(
			'<div class="nm-cms-modal-header"><h2>' + ( isNew ? 'Add New ' : ( schema.readonly ? 'View ' : 'Edit ' ) ) + esc( schema.label ) + '</h2>' +
			'<button type="button" class="nm-cms-modal-close">&times;</button></div>'
		);
		var $body = $( '<div class="nm-cms-modal-body">Loading…</div>' );
		$modal.append( $body );
		var $footer = $( '<div class="nm-cms-modal-footer"></div>' );
		if ( ! schema.readonly ) {
			$footer.append( '<button type="button" class="nm-cms-btn nm-cms-btn--primary nm-cms-save">Save</button>' );
		}
		$footer.append( '<button type="button" class="nm-cms-btn nm-cms-cancel">Close</button>' );
		$modal.append( $footer );
		$overlay.append( $modal );
		this.$root.find( '.nm-cms-modal-root' ).html( '' ).append( $overlay );

		function close() {
			$overlay.remove();
		}
		$overlay.on( 'click', function ( e ) {
			if ( e.target === $overlay[ 0 ] ) {
				close();
			}
		} );
		$modal.find( '.nm-cms-modal-close, .nm-cms-cancel' ).on( 'click', close );

		var dataPromise = isNew ? Promise.resolve( {} ) : apiFetch( 'cms/' + this.type + '/' + id );

		dataPromise
			.then( function ( item ) {
				$body.html( self.renderFormFields( item ) );
				self.bindFieldWidgets( $body, item );

				$modal.find( '.nm-cms-save' ).on( 'click', function () {
					var $btn = $( this ).prop( 'disabled', true ).text( 'Saving…' );
					var payload = self.collectFormData( $body );
					var path = isNew ? 'cms/' + self.type : 'cms/' + self.type + '/' + id;
					apiFetch( path, {
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify( payload )
					} )
						.then( function () {
							close();
							self.loadList();
						} )
						.catch( function ( err ) {
							$btn.prop( 'disabled', false ).text( 'Save' );
							window.alert( err.message );
						} );
				} );
			} )
			.catch( function ( err ) {
				$body.html( '<div class="nm-cms-error">' + esc( err.message ) + '</div>' );
			} );
	};

	CmsApp.prototype.renderFormFields = function ( item ) {
		var schema = this.schema;
		var html = '<div class="nm-cms-form">';
		$.each( schema.fields, function ( key, field ) {
			var value = item[ key ];
			html += '<div class="nm-cms-field" data-field="' + key + '" data-type="' + field.type + '">';
			html += '<label>' + esc( field.label ) + ( field.required ? ' *' : '' ) + '</label>';
			html += renderFieldControl( field, value );
			html += '</div>';
		} );
		html += '</div>';
		return html;
	};

	CmsApp.prototype.bindFieldWidgets = function ( $body, item ) {
		var self = this;
		var schema = this.schema;

		$body.find( '.nm-cms-taxonomy-select' ).each( function () {
			var $select = $( this );
			var taxonomy = $select.data( 'taxonomy' );
			var selected = String( $select.data( 'selected' ) || '' );
			getTerms( taxonomy ).then( function ( terms ) {
				$.each( terms, function ( i, term ) {
					var $opt = $( '<option></option>' ).val( term.id ).text( term.name );
					if ( String( term.id ) === selected ) {
						$opt.prop( 'selected', true );
					}
					$select.append( $opt );
				} );
			} );
		} );

		var $parentSelect = $body.find( '.nm-cms-parent-select' );
		if ( $parentSelect.length && schema.kind === 'taxonomy' ) {
			var selectedParent = String( $parentSelect.data( 'selected' ) || '' );
			getTerms( self.type ).then( function ( terms ) {
				$.each( terms, function ( i, term ) {
					if ( item.id && term.id === item.id ) {
						return;
					}
					var $opt = $( '<option></option>' ).val( term.id ).text( term.name );
					if ( String( term.id ) === selectedParent ) {
						$opt.prop( 'selected', true );
					}
					$parentSelect.append( $opt );
				} );
			} );
		}

		$body.find( '.nm-cms-media-field' ).each( function () {
			var $field = $( this );
			var kind = $field.data( 'media-kind' );
			var frame;
			$field.find( '.nm-cms-media-choose' ).on( 'click', function () {
				frame = wp.media( {
					title: kind === 'image' ? 'Choose Image' : 'Choose File',
					library: kind === 'image' ? { type: 'image' } : { type: [ 'audio', 'video' ] },
					multiple: false
				} );
				frame.on( 'select', function () {
					var att = frame.state().get( 'selection' ).first().toJSON();
					$field.find( '.nm-cms-media-id' ).val( att.id );
					if ( kind === 'image' ) {
						var previewUrl = att.sizes && att.sizes.medium ? att.sizes.medium.url : att.url;
						$field.find( '.nm-cms-media-preview-wrap' ).html( '<img class="nm-cms-media-preview" src="' + previewUrl + '">' );
					} else {
						$field.find( '.nm-cms-media-preview-wrap' ).html( '<span class="nm-cms-media-filename">' + esc( att.filename ) + '</span>' );
					}
					$field.find( '.nm-cms-media-remove' ).show();
				} );
				frame.open();
			} );
			$field.find( '.nm-cms-media-remove' ).on( 'click', function () {
				$field.find( '.nm-cms-media-id' ).val( '' );
				$field.find( '.nm-cms-media-preview-wrap' ).html( '<span class="nm-cms-muted">No file selected.</span>' );
				$( this ).hide();
			} );
		} );

		$body.find( '.nm-cms-media-multi-field' ).each( function () {
			var $field = $( this );
			var $list = $field.find( '.nm-cms-media-multi-list' );
			if ( $list.sortable ) {
				$list.sortable();
			}
			var frame;
			$field.find( '.nm-cms-media-multi-add' ).on( 'click', function () {
				frame = wp.media( { title: 'Add Images', library: { type: 'image' }, multiple: true } );
				frame.on( 'select', function () {
					var selection = frame.state().get( 'selection' );
					selection.each( function ( attachment ) {
						var data = attachment.toJSON();
						var thumbUrl = data.sizes && data.sizes.thumbnail ? data.sizes.thumbnail.url : data.url;
						$list.append( '<div class="nm-cms-media-multi-item" data-id="' + data.id + '"><img src="' + thumbUrl + '"><button type="button" class="nm-cms-media-multi-remove">&times;</button></div>' );
					} );
				} );
				frame.open();
			} );
			$list.on( 'click', '.nm-cms-media-multi-remove', function () {
				$( this ).closest( '.nm-cms-media-multi-item' ).remove();
			} );
		} );
	};

	CmsApp.prototype.collectFormData = function ( $body ) {
		var data = {};
		$body.find( '.nm-cms-field' ).each( function () {
			var $field = $( this );
			var key = $field.data( 'field' );
			var type = $field.data( 'type' );
			switch ( type ) {
				case 'taxonomy':
				case 'term_parent':
					data[ key ] = $field.find( 'select' ).val() || '';
					break;
				case 'select':
					data[ key ] = $field.find( 'select' ).val();
					break;
				case 'image':
				case 'media':
					data[ key ] = $field.find( '.nm-cms-media-id' ).val() || '';
					break;
				case 'media_multi':
					var ids = [];
					$field.find( '.nm-cms-media-multi-item' ).each( function () {
						ids.push( $( this ).data( 'id' ) );
					} );
					data[ key ] = ids;
					break;
				case 'readonly':
				case 'readonly_textarea':
					break;
				default:
					data[ key ] = $field.find( '.nm-cms-input' ).val();
			}
		} );
		return data;
	};

	function mountAll() {
		$( '.nm-cms-root[data-cms-type]' ).each( function () {
			new CmsApp( $( this ) ); // eslint-disable-line no-new
		} );
	}

	$( mountAll );
} )( jQuery );
