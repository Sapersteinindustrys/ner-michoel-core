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
 *
 * The list is a searchable, filterable table (cards on a phone); a row
 * opens its form in a panel that slides in from the right. Confirmations
 * and "Saved" messages go through the shell's window.NMAdmin
 * (custom-admin-shell.js), with plain browser dialogs as a fallback.
 * Links can open a form straight away: ?new=1 or ?edit=<id>.
 */
( function ( $ ) {
	'use strict';

	var cfg = window.nmCmsConfig || {};
	var schemaCache = {};
	var termsCache = {};

	var STATUS_LABELS = {
		publish: 'Published',
		draft: 'Draft',
		pending: 'Waiting for review',
		future: 'Scheduled',
		private: 'Private'
	};

	/* ---------------- Shell helpers (with fallbacks) ---------------- */

	function ui() {
		return window.NMAdmin || null;
	}

	function toast( message, type ) {
		if ( ui() ) {
			ui().toast( message, type );
		} else if ( type === 'error' ) {
			window.alert( message ); // eslint-disable-line no-alert
		}
	}

	function confirmBox( opts ) {
		if ( ui() ) {
			return ui().confirm( opts );
		}
		return Promise.resolve( window.confirm( ( opts.title || '' ) + '\n\n' + ( opts.message || '' ) ) ); // eslint-disable-line no-alert
	}

	function icon( name ) {
		return '<svg class="nm-ico" aria-hidden="true" focusable="false"><use href="#nm-i-' + name + '"></use></svg>';
	}

	function esc( str ) {
		return $( '<div>' ).text( str === null || str === undefined ? '' : String( str ) ).html();
	}

	// Term names arrive HTML-encoded (&amp;, &#8217;). Decoded for showing as text; a
	// textarea's value never runs markup.
	function decodeName( str ) {
		var el = document.createElement( 'textarea' );
		el.innerHTML = String( str === null || str === undefined ? '' : str );
		return el.value;
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

	function formatDate( ymd ) {
		var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec( String( ymd || '' ) );
		if ( ! m ) {
			return ymd || '';
		}
		try {
			return new Date( +m[ 1 ], +m[ 2 ] - 1, +m[ 3 ] ).toLocaleDateString( undefined, { month: 'short', day: 'numeric', year: 'numeric' } );
		} catch ( e ) {
			return ymd;
		}
	}

	function formatNumber( n ) {
		var num = Number( n ) || 0;
		try {
			return num.toLocaleString();
		} catch ( e ) {
			return String( num );
		}
	}

	function mediaModalOpen() {
		return $( '.media-modal:visible' ).length > 0;
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

	function getSchema( type ) {
		if ( schemaCache[ type ] ) {
			return Promise.resolve( schemaCache[ type ] );
		}
		return apiFetch( 'cms/' + type + '/schema' ).then( function ( schema ) {
			schemaCache[ type ] = schema;
			return schema;
		} );
	}

	function getTerms( taxonomy, postType ) {
		// postType scopes "recent" (the series picker) to the form you're on —
		// a series recently used by a shiur isn't necessarily recent for a
		// written shiur, or vice versa. Cache key includes it so the two
		// forms don't share a stale result.
		var cacheKey = taxonomy + '|' + ( postType || '' );
		if ( termsCache[ cacheKey ] ) {
			return termsCache[ cacheKey ];
		}
		var path = 'terms/' + taxonomy + ( postType ? '?post_type=' + encodeURIComponent( postType ) : '' );
		termsCache[ cacheKey ] = apiFetch( path );
		return termsCache[ cacheKey ];
	}

	/* ---------------- Field rendering ---------------- */

	function renderFieldControl( field, value, id ) {
		var idAttr = id ? ' id="' + id + '"' : '';
		switch ( field.type ) {
			case 'text':
				return '<input type="text"' + idAttr + ' class="nm-cms-input" value="' + esc( value || '' ) + '">';
			case 'email':
				return '<input type="email"' + idAttr + ' class="nm-cms-input" value="' + esc( value || '' ) + '" placeholder="name@example.com">';
			case 'number':
				return '<input type="number"' + idAttr + ' class="nm-cms-input nm-cms-input--short" value="' + esc( value === undefined || value === null ? '' : value ) + '">';
			case 'textarea':
				return '<textarea' + idAttr + ' class="nm-cms-input" rows="' + ( field.rows || 4 ) + '">' + esc( value || '' ) + '</textarea>';
			case 'lines':
				return '<textarea' + idAttr + ' class="nm-cms-input nm-cms-mono" rows="6" placeholder="One link per line">' + esc( value || '' ) + '</textarea>';
			case 'select':
				var opts = '';
				$.each( field.options || {}, function ( optKey, optLabel ) {
					opts += '<option value="' + esc( optKey ) + '"' + ( optKey === value ? ' selected' : '' ) + '>' + esc( optLabel ) + '</option>';
				} );
				return '<select' + idAttr + ' class="nm-cms-input">' + opts + '</select>';
			case 'taxonomy':
				// field.picker === 'recent' makes this the searchable list with "Recent" on top
				// (series-picker.js, enhanced once the terms have loaded).
				return '<select' + idAttr + ' class="nm-cms-input nm-cms-taxonomy-select"' +
					( field.picker === 'recent' ? ' data-series-picker data-placeholder="Type to search series…" data-recent-label="Recently used" data-all-label="All series"' : '' ) +
					' data-taxonomy="' + esc( field.taxonomy ) + '" data-selected="' + esc( value || '' ) + '"><option value="">— None —</option></select>';
			case 'taxonomy_multi':
				// Several terms (topics): a filter box over a checkbox list, filled once the
				// terms have loaded (bindFieldWidgets).
				return '<div class="nm-cms-multi" data-taxonomy="' + esc( field.taxonomy ) + '" data-selected="' + esc( $.isArray( value ) ? value.join( ',' ) : '' ) + '">' +
					'<input type="search" class="nm-cms-input nm-cms-multi__filter" placeholder="Type to filter…" aria-label="Filter">' +
					'<div class="nm-cms-multi__list" role="group"><span class="nm-cms-muted">Loading…</span></div>' +
					'</div>';
			case 'term_parent':
				return '<select' + idAttr + ' class="nm-cms-input nm-cms-parent-select" data-selected="' + esc( value || '' ) + '"><option value="">— None (top level) —</option></select>';
			case 'image':
				return renderMediaField( 'image', value );
			case 'media':
				// A media field can declare kind 'pdf' (written shiurim) —
				// same field type on the PHP side, different picker here.
				return renderMediaField( field.kind === 'pdf' ? 'pdf' : 'media', value );
			case 'media_multi':
				return renderMediaMultiField( value );
			case 'readonly':
				return '<div class="nm-cms-readonly-text">' + ( value ? esc( value ) : '<span class="nm-cms-muted">—</span>' ) + '</div>';
			case 'readonly_textarea':
				return '<div class="nm-cms-readonly-text nm-cms-readonly-textarea">' + ( value ? esc( value ).replace( /\n/g, '<br>' ) : '<span class="nm-cms-muted">—</span>' ) + '</div>';
			default:
				return '<input type="text"' + idAttr + ' class="nm-cms-input" value="' + esc( value || '' ) + '">';
		}
	}

	function mediaPreview( kind, value ) {
		if ( value && value.id && kind === 'image' ) {
			return '<img class="nm-cms-media-preview" src="' + esc( value.url ) + '" alt="">';
		}
		if ( value && value.id ) {
			return '<span class="nm-cms-media-file">' + icon( kind === 'pdf' ? 'file-text' : 'headphones' ) + '<span class="nm-cms-media-filename">' + esc( value.filename || value.url ) + '</span></span>';
		}
		return '<span class="nm-cms-media-empty">' + icon( kind === 'image' ? 'image' : ( kind === 'pdf' ? 'file-text' : 'upload' ) ) + '<span>' + ( kind === 'image' ? 'No image yet' : 'No file yet' ) + '</span></span>';
	}

	function renderMediaField( kind, value ) {
		var hasFile = value && value.id;
		var noun = kind === 'image' ? 'Image' : kind === 'pdf' ? 'PDF' : 'File';
		return '<div class="nm-cms-media-field" data-media-kind="' + kind + '">' +
			'<input type="hidden" class="nm-cms-media-id" value="' + ( hasFile ? value.id : '' ) + '">' +
			'<div class="nm-cms-media-preview-wrap">' + mediaPreview( kind, value ) + '</div>' +
			'<div class="nm-cms-media-actions">' +
			'<button type="button" class="nm-btn nm-btn--sm nm-cms-media-choose">' + icon( 'upload' ) + '<span>' + ( hasFile ? 'Change ' : 'Choose ' ) + noun + '</span></button>' +
			'<button type="button" class="nm-btn nm-btn--sm nm-cms-btn--link nm-cms-media-remove"' + ( hasFile ? '' : ' hidden' ) + '>Remove</button>' +
			'</div>' +
			'</div>';
	}

	function mediaMultiItem( id, url ) {
		return '<div class="nm-cms-media-multi-item" data-id="' + esc( id ) + '"><img src="' + esc( url ) + '" alt=""><button type="button" class="nm-cms-media-multi-remove" aria-label="Remove image">&times;</button></div>';
	}

	function renderMediaMultiField( value ) {
		value = value || [];
		var items = $.map( value, function ( img ) {
			return mediaMultiItem( img.id, img.url );
		} ).join( '' );
		return '<div class="nm-cms-media-multi-field">' +
			'<div class="nm-cms-media-multi-list">' + items + '</div>' +
			'<button type="button" class="nm-btn nm-btn--sm nm-cms-media-multi-add">' + icon( 'plus' ) + '<span>Add Images</span></button>' +
			'<p class="nm-cms-hint">Tip: pick several at once. Drag the thumbnails to change their order.</p>' +
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
		this.rows = {};
		this.init();
	}

	CmsApp.prototype.init = function () {
		var self = this;
		this.$root.html( '<div class="nm-cms-loading"><span class="nm-spinner" aria-hidden="true"></span>Loading…</div>' );
		getSchema( this.type )
			.then( function ( schema ) {
				self.schema = schema;
				self.renderShell();
				self.loadList();
				self.openFromUrl();
			} )
			.catch( function ( err ) {
				self.$root.html( self.errorBox( err.message ) );
			} );
	};

	CmsApp.prototype.errorBox = function ( message ) {
		return '<div class="nm-cms-error">' + icon( 'warning' ) + '<div><strong>That didn’t work</strong><span>' + esc( message ) + '</span></div></div>';
	};

	// ?new=1 opens a blank form, ?edit=<id> opens that item. Taken out of the
	// address afterwards, so a reload doesn't reopen it.
	CmsApp.prototype.openFromUrl = function () {
		if ( ! window.URL ) {
			return;
		}
		var url;
		try {
			url = new URL( window.location.href );
		} catch ( e ) {
			return;
		}
		var editId = parseInt( url.searchParams.get( 'edit' ), 10 );
		var wantsNew = url.searchParams.get( 'new' ) === '1';
		if ( editId ) {
			this.openForm( editId );
		} else if ( wantsNew && ! this.schema.readonly ) {
			this.openForm( null );
		}
		if ( url.searchParams.has( 'edit' ) || url.searchParams.has( 'new' ) ) {
			url.searchParams.delete( 'edit' );
			url.searchParams.delete( 'new' );
			if ( window.history && window.history.replaceState ) {
				window.history.replaceState( null, '', url.toString() );
			}
		}
	};

	CmsApp.prototype.pluralLower = function () {
		return String( this.schema.label_plural || 'items' ).toLowerCase();
	};

	CmsApp.prototype.taxonomyLabel = function ( taxonomy ) {
		var label = '';
		$.each( this.schema.list_columns || [], function ( i, col ) {
			if ( col.taxonomy === taxonomy ) {
				label = col.label;
			}
		} );
		return label || taxonomy.charAt( 0 ).toUpperCase() + taxonomy.slice( 1 ).replace( /_/g, ' ' );
	};

	CmsApp.prototype.renderShell = function () {
		var self = this;
		var schema = this.schema;
		var html = '<div class="nm-cms">';
		html += '<div class="nm-cms-toolbar">';
		html += '<label class="nm-cms-searchbox">' + icon( 'search' ) +
			'<input type="search" class="nm-cms-search nm-cms-input" placeholder="Search ' + esc( this.pluralLower() ) + '…" aria-label="Search ' + esc( this.pluralLower() ) + '"></label>';
		html += '<div class="nm-cms-filters"></div>';
		html += '<div class="nm-cms-toolbar-spacer"></div>';
		if ( ! schema.readonly ) {
			html += '<button type="button" class="nm-btn nm-btn--primary nm-cms-add">' + icon( 'plus' ) + '<span>Add New ' + esc( schema.label ) + '</span></button>';
		}
		html += '</div>';
		html += '<div class="nm-cms-table-wrap"><table class="nm-cms-table"><thead><tr>';
		$.each( schema.list_columns, function ( i, col ) {
			html += '<th scope="col">' + esc( col.label ) + '</th>';
		} );
		html += '<th class="nm-cms-col-actions" scope="col"><span class="screen-reader-text">Actions</span></th>';
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
			var label = self.taxonomyLabel( taxonomy );
			var $select = $( '<select class="nm-cms-input nm-cms-filter"></select>' ).attr( 'aria-label', 'Filter by ' + label.toLowerCase() );
			$select.append( $( '<option value=""></option>' ).text( label + ': All' ) );
			$wrap.append( $select );
			getTerms( taxonomy ).then( function ( terms ) {
				$.each( terms, function ( j, term ) {
					$select.append( $( '<option></option>' ).val( term.id ).text( label + ': ' + decodeName( term.name ) ) );
				} );
			} );
			$select.on( 'change', function () {
				var val = $( this ).val();
				if ( val ) {
					self.filters[ taxonomy ] = val;
				} else {
					delete self.filters[ taxonomy ];
				}
				$( this ).toggleClass( 'is-set', !! val );
				self.page = 1;
				self.loadList();
			} );
		} );
	};

	CmsApp.prototype.isFiltered = function () {
		return !! this.search || ! $.isEmptyObject( this.filters );
	};

	CmsApp.prototype.loadList = function () {
		var self = this;
		var params = $.extend( {}, this.args, this.filters, {
			page: this.page,
			per_page: 20,
			search: this.search
		} );
		var qs = $.param( params );
		var cols = ( this.schema.list_columns || [] ).length + 1;

		var skeleton = '';
		for ( var r = 0; r < 4; r++ ) {
			skeleton += '<tr class="nm-cms-skeleton">';
			for ( var c = 0; c < cols; c++ ) {
				skeleton += '<td><span></span></td>';
			}
			skeleton += '</tr>';
		}
		this.$root.find( '.nm-cms-tbody' ).html( skeleton );
		this.requestId = ( this.requestId || 0 ) + 1;
		var requestId = this.requestId;

		apiFetch( 'cms/' + this.type + '?' + qs )
			.then( function ( result ) {
				if ( requestId === self.requestId ) {
					self.renderRows( result );
				}
			} )
			.catch( function ( err ) {
				if ( requestId === self.requestId ) {
					self.$root.find( '.nm-cms-tbody' ).html( '<tr><td colspan="99">' + self.errorBox( err.message ) + '</td></tr>' );
				}
			} );
	};

	CmsApp.prototype.renderCell = function ( item, col ) {
		var muted = '<span class="nm-cms-muted">—</span>';
		switch ( col.render ) {
			case 'title':
				return '<div class="nm-cms-titlecell">' +
					( item.thumbnail ? '<img class="nm-cms-thumb" src="' + esc( item.thumbnail ) + '" alt="">' : '' ) +
					'<strong>' + esc( decodeName( item.title || item.name ) ) + '</strong></div>';
			case 'taxonomy':
				return item[ col.key ] && item[ col.key ].length ? esc( decodeName( item[ col.key ].join( ', ' ) ) ) : muted;
			case 'number':
				return '<span class="nm-cms-num">' + esc( formatNumber( item[ col.key ] ) ) + '</span>';
			case 'status':
				return '<span class="nm-pill nm-pill--' + esc( item.status ) + '">' + esc( STATUS_LABELS[ item.status ] || item.status ) + '</span>';
			case 'date':
				return '<span class="nm-cms-date">' + esc( formatDate( item.date ) ) + '</span>';
			case 'meta':
			case 'meta_trim':
			case 'term_meta':
				return item[ col.key ] ? esc( item[ col.key ] ) : muted;
			case 'term_count':
				return '<span class="nm-cms-num">' + esc( formatNumber( item.count ) ) + '</span>';
			default:
				return esc( item[ col.key ] );
		}
	};

	CmsApp.prototype.renderEmpty = function () {
		var schema = this.schema;
		if ( this.isFiltered() ) {
			return '<div class="nm-cms-empty">' +
				'<span class="nm-cms-empty__icon">' + icon( 'search' ) + '</span>' +
				'<strong>No matches</strong>' +
				'<span>Nothing fits that search or filter. Try fewer words, or clear it.</span>' +
				'<button type="button" class="nm-btn nm-btn--sm nm-cms-clear">Clear search &amp; filters</button>' +
				'</div>';
		}
		if ( this.args && this.args.missing_audio ) {
			return '<div class="nm-cms-empty nm-cms-empty--good">' +
				'<span class="nm-cms-empty__icon">' + icon( 'check-circle' ) + '</span>' +
				'<strong>Every shiur has its audio</strong>' +
				'<span>Nothing to fix here. Nice work!</span>' +
				'</div>';
		}
		return '<div class="nm-cms-empty">' +
			'<span class="nm-cms-empty__icon">' + icon( schema.readonly ? 'inbox' : 'sparkles' ) + '</span>' +
			'<strong>No ' + esc( this.pluralLower() ) + ' yet</strong>' +
			'<span>' + ( schema.readonly ? 'When something arrives, it will show up here.' : 'Your first one is just a click away.' ) + '</span>' +
			( schema.readonly ? '' : '<button type="button" class="nm-btn nm-btn--primary nm-btn--sm nm-cms-empty-add">' + icon( 'plus' ) + '<span>Add your first ' + esc( schema.label.toLowerCase() ) + '</span></button>' ) +
			'</div>';
	};

	CmsApp.prototype.renderRows = function ( result ) {
		var self = this;
		var schema = this.schema;
		var $tbody = this.$root.find( '.nm-cms-tbody' );
		this.rows = {};

		if ( ! result.items.length ) {
			$tbody.html( '<tr class="nm-cms-empty-row"><td colspan="99">' + this.renderEmpty() + '</td></tr>' );
		} else {
			var rows = $.map( result.items, function ( item ) {
				self.rows[ item.id ] = item;
				var cells = $.map( schema.list_columns, function ( col ) {
					var cell = self.renderCell( item, col );
					var empty = cell.indexOf( 'nm-cms-muted' ) !== -1 ? ' nm-cms-cell--empty' : '';
					return '<td data-label="' + esc( col.label ) + '" class="nm-cms-col--' + esc( col.render ) + empty + '">' + cell + '</td>';
				} ).join( '' );
				var actions = '<td class="nm-cms-col-actions"><div class="nm-cms-actions">';
				actions += '<button type="button" class="nm-btn nm-btn--sm nm-cms-edit" data-id="' + item.id + '">' + icon( schema.readonly ? 'eye' : 'pencil' ) + '<span>' + ( schema.readonly ? 'Open' : 'Edit' ) + '</span></button>';
				if ( item.edit_link ) {
					actions += '<a class="nm-icon-btn nm-cms-row-icon" href="' + esc( item.edit_link ) + '" target="_blank" rel="noopener" title="See it on the site" aria-label="See it on the site">' + icon( 'external' ) + '</a>';
				}
				actions += '<button type="button" class="nm-icon-btn nm-cms-row-icon nm-cms-row-icon--danger nm-cms-delete" data-id="' + item.id + '" title="Delete" aria-label="Delete">' + icon( 'trash' ) + '</button>';
				actions += '</div></td>';
				return '<tr class="nm-cms-row" data-id="' + item.id + '" tabindex="0">' + cells + actions + '</tr>';
			} ).join( '' );
			$tbody.html( rows );
		}

		this.renderPagination( result );

		$tbody.find( '.nm-cms-clear' ).on( 'click', function () {
			self.search = '';
			self.filters = {};
			self.page = 1;
			self.$root.find( '.nm-cms-search' ).val( '' );
			self.$root.find( '.nm-cms-filter' ).val( '' ).removeClass( 'is-set' );
			self.loadList();
		} );
		$tbody.find( '.nm-cms-empty-add' ).on( 'click', function () {
			self.openForm( null );
		} );

		// The whole row opens the form; its own buttons and links do their own thing.
		$tbody.find( '.nm-cms-row' ).on( 'click', function ( e ) {
			if ( $( e.target ).closest( 'a, button' ).length ) {
				return;
			}
			self.openForm( $( this ).data( 'id' ) );
		} ).on( 'keydown', function ( e ) {
			if ( e.key === 'Enter' && e.target === this ) {
				self.openForm( $( this ).data( 'id' ) );
			}
		} );
		$tbody.find( '.nm-cms-edit' ).on( 'click', function () {
			self.openForm( $( this ).data( 'id' ) );
		} );
		$tbody.find( '.nm-cms-delete' ).on( 'click', function () {
			self.confirmDelete( $( this ).data( 'id' ) );
		} );
	};

	CmsApp.prototype.confirmDelete = function ( id, onDone ) {
		var self = this;
		var schema = this.schema;
		var item = this.rows[ id ] || {};
		var name = decodeName( item.title || item.name || '' );
		var noun = schema.label.toLowerCase();
		var message;
		if ( schema.kind === 'taxonomy' ) {
			message = ( name ? '“' + name + '” will be removed. ' : '' ) + 'Any shiurim in it are kept — they just won’t be listed under it any more.';
		} else if ( schema.readonly ) {
			message = 'This message will be deleted for good.';
		} else {
			message = ( name ? '“' + name + '” will be taken off the site.' : 'It will be taken off the site.' ) + ' Tip: set Status to Draft instead if you only want to hide it for now.';
		}
		return confirmBox( {
			title: 'Delete this ' + noun + '?',
			message: message,
			confirmText: 'Yes, delete',
			cancelText: 'Keep it',
			danger: true
		} ).then( function ( ok ) {
			if ( ! ok ) {
				return false;
			}
			return apiFetch( 'cms/' + self.type + '/' + id, { method: 'DELETE' } )
				.then( function () {
					toast( 'Deleted.', 'success' );
					if ( onDone ) {
						onDone();
					}
					self.loadList();
					return true;
				} )
				.catch( function ( err ) {
					toast( err.message, 'error' );
					return false;
				} );
		} );
	};

	CmsApp.prototype.renderPagination = function ( result ) {
		var self = this;
		var $p = this.$root.find( '.nm-cms-pagination' );
		if ( ! result.items.length ) {
			$p.empty();
			return;
		}
		var perPage = 20;
		var from = ( result.page - 1 ) * perPage + 1;
		var to = from + result.items.length - 1;
		var noun = this.pluralLower();
		var html = '<span class="nm-cms-page-indicator">' +
			( result.total_pages > 1 ? 'Showing ' + formatNumber( from ) + '–' + formatNumber( to ) + ' of ' + formatNumber( result.total ) + ' ' + esc( noun ) : formatNumber( result.total ) + ' ' + esc( result.total === 1 ? String( this.schema.label ).toLowerCase() : noun ) ) +
			'</span>';
		if ( result.total_pages > 1 ) {
			html += '<span class="nm-cms-pager">' +
				'<button type="button" class="nm-btn nm-btn--sm nm-cms-prev"' + ( result.page <= 1 ? ' disabled' : '' ) + '>' + icon( 'chevron-l' ) + '<span>Previous</span></button>' +
				'<span class="nm-cms-page-num">Page ' + result.page + ' of ' + result.total_pages + '</span>' +
				'<button type="button" class="nm-btn nm-btn--sm nm-cms-next"' + ( result.page >= result.total_pages ? ' disabled' : '' ) + '><span>Next</span>' + icon( 'chevron-r' ) + '</button>' +
				'</span>';
		}
		$p.html( html );
		$p.find( '.nm-cms-prev' ).on( 'click', function () {
			self.page = Math.max( 1, self.page - 1 );
			self.loadList();
			self.scrollToTop();
		} );
		$p.find( '.nm-cms-next' ).on( 'click', function () {
			self.page = self.page + 1;
			self.loadList();
			self.scrollToTop();
		} );
	};

	CmsApp.prototype.scrollToTop = function () {
		var top = this.$root.offset().top - 90;
		if ( window.scrollY > top ) {
			window.scrollTo( { top: Math.max( 0, top ), behavior: 'smooth' } );
		}
	};

	/* ---------------- Edit/Add panel ---------------- */

	CmsApp.prototype.openForm = function ( id ) {
		var self = this;
		var schema = this.schema;
		var isNew = ! id;
		var dirty = false;
		var saving = false;
		var previousFocus = document.activeElement;
		var title = isNew ? 'Add New ' + schema.label : ( schema.readonly ? schema.label : 'Edit ' + schema.label );

		var $wrap = $(
			'<div class="nm-drawer-wrap">' +
				'<div class="nm-drawer-backdrop"></div>' +
				'<section class="nm-drawer" role="dialog" aria-modal="true" aria-labelledby="nm-drawer-title">' +
					'<header class="nm-drawer__head">' +
						'<div class="nm-drawer__titles"><p class="nm-drawer__eyebrow"></p><h2 id="nm-drawer-title"></h2></div>' +
						'<button type="button" class="nm-icon-btn nm-drawer__close" aria-label="Close">' + icon( 'x' ) + '</button>' +
					'</header>' +
					'<div class="nm-drawer__body"><div class="nm-cms-loading"><span class="nm-spinner" aria-hidden="true"></span>Loading…</div></div>' +
					'<footer class="nm-drawer__foot">' +
						'<span class="nm-drawer__hint"></span>' +
						'<span class="nm-drawer__buttons"></span>' +
					'</footer>' +
				'</section>' +
			'</div>'
		);
		$wrap.find( '.nm-drawer__eyebrow' ).text( schema.label_plural );
		$wrap.find( 'h2' ).text( title );
		var $body = $wrap.find( '.nm-drawer__body' );
		var $buttons = $wrap.find( '.nm-drawer__buttons' );
		var $hint = $wrap.find( '.nm-drawer__hint' );

		if ( ! schema.readonly ) {
			$buttons.append( '<button type="button" class="nm-btn nm-cms-cancel">Cancel</button>' );
			$buttons.append( '<button type="button" class="nm-btn nm-btn--primary nm-cms-save" disabled>' + icon( 'check' ) + '<span>' + ( isNew ? 'Add ' + esc( schema.label ) : 'Save changes' ) + '</span></button>' );
			$hint.html( '<span>Changes are saved when you press <strong>' + ( isNew ? 'Add' : 'Save' ) + '</strong>.</span>' );
		} else {
			$buttons.append( '<button type="button" class="nm-btn nm-btn--primary nm-cms-cancel">Close</button>' );
		}

		this.$root.find( '.nm-cms-modal-root' ).html( '' ).append( $wrap );
		$( document.body ).addClass( 'nm-no-scroll' );
		var release = ui() && ui().trapFocus ? ui().trapFocus( $wrap.find( '.nm-drawer' )[ 0 ] ) : function () {};
		$wrap.find( '.nm-drawer__close' ).trigger( 'focus' );

		function markDirty() {
			if ( ! dirty && ! schema.readonly ) {
				dirty = true;
				$hint.html( '<span class="nm-dot" aria-hidden="true"></span><span>You have unsaved changes</span>' );
			}
		}

		function teardown() {
			release();
			$( document ).off( 'keydown.nmdrawer' );
			$wrap.addClass( 'is-closing' );
			$( document.body ).removeClass( 'nm-no-scroll' );
			setTimeout( function () {
				$wrap.remove();
			}, 220 );
			if ( previousFocus && previousFocus.focus ) {
				previousFocus.focus();
			}
		}

		function requestClose() {
			if ( saving ) {
				return;
			}
			if ( ! dirty ) {
				teardown();
				return;
			}
			confirmBox( {
				title: 'Leave without saving?',
				message: 'You’ve made changes that haven’t been saved yet. If you leave now, they’ll be lost.',
				confirmText: 'Leave without saving',
				cancelText: 'Keep editing',
				danger: true,
				icon: 'warning'
			} ).then( function ( ok ) {
				if ( ok ) {
					teardown();
				}
			} );
		}

		$wrap.find( '.nm-drawer-backdrop' ).on( 'click', requestClose );
		$wrap.find( '.nm-drawer__close, .nm-cms-cancel' ).on( 'click', requestClose );
		$( document ).on( 'keydown.nmdrawer', function ( e ) {
			if ( mediaModalOpen() || $( '.nm-dialog-wrap' ).length ) {
				return;
			}
			if ( e.key === 'Escape' ) {
				e.preventDefault();
				requestClose();
			} else if ( ( e.ctrlKey || e.metaKey ) && String( e.key ).toLowerCase() === 's' ) {
				e.preventDefault();
				$wrap.find( '.nm-cms-save:not(:disabled)' ).trigger( 'click' );
			}
		} );

		var dataPromise = isNew ? Promise.resolve( {} ) : apiFetch( 'cms/' + this.type + '/' + id );

		dataPromise
			.then( function ( item ) {
				$body.html( self.renderFormFields( item ) );
				self.bindFieldWidgets( $body, item, markDirty );

				// Typing in a search/filter box isn't a change to the item.
				$body.on( 'input change', function ( e ) {
					if ( $( e.target ).is( '.nm-cms-multi__filter, .nm-series-picker__search' ) ) {
						return;
					}
					markDirty();
				} );

				if ( schema.readonly && item.email ) {
					var $reply = $( '<a class="nm-btn nm-cms-reply">' + icon( 'inbox' ) + '<span>Reply by email</span></a>' ).attr( 'href', 'mailto:' + item.email );
					$buttons.prepend( $reply );
				}
				if ( ! isNew && ! schema.readonly ) {
					var $del = $( '<button type="button" class="nm-btn nm-btn--sm nm-cms-btn--link nm-drawer__delete">' + icon( 'trash' ) + '<span>Delete</span></button>' );
					$del.on( 'click', function () {
						self.rows[ id ] = self.rows[ id ] || { title: item.title || item.name };
						self.confirmDelete( id, function () {
							dirty = false;
							teardown();
						} );
					} );
					$wrap.find( '.nm-drawer__foot' ).prepend( $del );
				}

				var $first = $body.find( 'input.nm-cms-input:not([type=search]), textarea.nm-cms-input' ).first();
				if ( isNew && $first.length ) {
					$first.trigger( 'focus' );
				}

				$wrap.find( '.nm-cms-save' ).prop( 'disabled', false ).on( 'click', function () {
					var $btn = $( this );
					var payload = self.collectFormData( $body );
					var titleKey = schema.kind === 'taxonomy' ? 'name' : 'title';
					$body.find( '.nm-cms-field.has-error' ).removeClass( 'has-error' ).find( '.nm-cms-field-error' ).remove();
					if ( schema.fields[ titleKey ] && schema.fields[ titleKey ].required && ! $.trim( payload[ titleKey ] || '' ) ) {
						var $f = $body.find( '.nm-cms-field[data-field="' + titleKey + '"]' ).addClass( 'has-error' );
						$f.append( '<p class="nm-cms-field-error">Please fill this in — it’s the name people will see.</p>' );
						$f.find( '.nm-cms-input' ).trigger( 'focus' );
						return;
					}

					saving = true;
					$btn.prop( 'disabled', true ).find( 'span' ).text( 'Saving…' );
					var path = isNew ? 'cms/' + self.type : 'cms/' + self.type + '/' + id;
					apiFetch( path, {
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify( payload )
					} )
						.then( function () {
							saving = false;
							dirty = false;
							var savedName = $.trim( payload[ titleKey ] || '' );
							if ( payload.status === 'draft' ) {
								toast( 'Saved as a draft. It stays hidden from visitors until you set it to Published.', 'success' );
							} else if ( isNew ) {
								toast( 'Added' + ( savedName ? ': “' + savedName + '”' : '' ) + '.', 'success' );
							} else {
								toast( 'Saved. Your changes are live.', 'success' );
							}
							teardown();
							self.loadList();
						} )
						.catch( function ( err ) {
							saving = false;
							$btn.prop( 'disabled', false ).find( 'span' ).text( isNew ? 'Add ' + schema.label : 'Save changes' );
							toast( err.message, 'error' );
						} );
				} );
			} )
			.catch( function ( err ) {
				$body.html( self.errorBox( err.message ) );
			} );
	};

	CmsApp.prototype.renderFormFields = function ( item ) {
		var schema = this.schema;
		var html = '<div class="nm-cms-form">';
		var n = 0;
		$.each( schema.fields, function ( key, field ) {
			var value = item[ key ];
			var id = 'nm-f-' + key + '-' + ( n++ );
			var isGroup = field.type === 'taxonomy_multi' || field.type === 'media_multi' || field.type === 'image' || field.type === 'media';
			html += '<div class="nm-cms-field nm-cms-field--' + esc( field.type ) + '" data-field="' + esc( key ) + '" data-type="' + esc( field.type ) + '">';
			html += '<label class="nm-cms-label"' + ( isGroup ? '' : ' for="' + id + '"' ) + '>' + esc( field.label ) +
				( field.required ? ' <span class="nm-cms-required">Required</span>' : '' ) + '</label>';
			if ( field.hint ) {
				html += '<p class="nm-cms-hint nm-cms-hint--top">' + esc( field.hint ) + '</p>';
			}
			html += renderFieldControl( field, value, id );
			html += '</div>';
		} );
		html += '</div>';
		return html;
	};

	// Written shiurim: when a PDF is picked, its first line goes into the Summary
	// field (the homepage card's line), but only if that field is still empty.
	function fillSummaryFromPdf( $body, url ) {
		var $summary = $body.find( '.nm-cms-field[data-field="excerpt"] textarea' );
		if ( ! $summary.length || ! window.NMPdfFirstLine || $.trim( $summary.val() ) !== '' ) {
			return;
		}
		window.NMPdfFirstLine.read( url ).then( function ( line ) {
			if ( line && $.trim( $summary.val() ) === '' ) {
				$summary.val( line );
				toast( 'Filled in the Summary from the first line of the PDF. You can change it.', 'info' );
			}
		} ).catch( function () {
			$summary.attr( 'placeholder', 'Could not read the PDF here. Type the first line.' );
		} );
	}

	CmsApp.prototype.bindFieldWidgets = function ( $body, item, markDirty ) {
		var self = this;
		var schema = this.schema;
		markDirty = markDirty || function () {};

		$body.find( '.nm-cms-taxonomy-select' ).each( function () {
			var $select = $( this );
			var taxonomy = $select.data( 'taxonomy' );
			var selected = String( $select.data( 'selected' ) || '' );
			getTerms( taxonomy, self.type ).then( function ( terms ) {
				$.each( terms, function ( i, term ) {
					var $opt = $( '<option></option>' ).val( term.id ).text( decodeName( term.name ) );
					if ( term.last ) {
						$opt.attr( 'data-last', term.last );
					}
					if ( String( term.id ) === selected ) {
						$opt.prop( 'selected', true );
					}
					$select.append( $opt );
				} );
				// All options are in place and the selection is set, so the picker
				// can build its list from them.
				if ( $select.is( '[data-series-picker]' ) && window.NMSeriesPicker ) {
					window.NMSeriesPicker.enhance( $select[ 0 ] );
				}
			} );
		} );

		// Several-term fields (topics). Marked loaded only once the list is in, so a save
		// before then leaves the post's terms alone instead of clearing them.
		$body.find( '.nm-cms-multi' ).each( function () {
			var $wrap   = $( this );
			var $list   = $wrap.find( '.nm-cms-multi__list' );
			var $filter = $wrap.find( '.nm-cms-multi__filter' );
			var chosen  = {};
			$.each( String( $wrap.attr( 'data-selected' ) || '' ).split( ',' ), function ( i, termId ) {
				if ( termId ) {
					chosen[ termId ] = true;
				}
			} );
			getTerms( $wrap.data( 'taxonomy' ), self.type ).then( function ( terms ) {
				// The ones already chosen first, then the rest by name.
				var sorted = terms.slice().sort( function ( a, b ) {
					var ca = chosen[ String( a.id ) ] ? 0 : 1;
					var cb = chosen[ String( b.id ) ] ? 0 : 1;
					return ca - cb || decodeName( a.name ).localeCompare( decodeName( b.name ) );
				} );
				$list.empty();
				$.each( sorted, function ( i, term ) {
					var $box = $( '<input type="checkbox">' ).val( term.id ).prop( 'checked', !! chosen[ String( term.id ) ] );
					$list.append( $( '<label class="nm-cms-multi__item"></label>' ).append( $box ).append( $( '<span></span>' ).text( decodeName( term.name ) ) ) );
				} );
				if ( ! terms.length ) {
					$list.append( $( '<span class="nm-cms-muted"></span>' ).text( 'None yet. Add some under Shiurim → Topics.' ) );
				}
				$wrap.attr( 'data-loaded', '1' );
			} );
			$filter.on( 'input', function () {
				var q = String( $filter.val() || '' ).toLowerCase().trim();
				$list.find( '.nm-cms-multi__item' ).each( function () {
					var $it = $( this );
					$it.prop( 'hidden', !! q && $it.text().toLowerCase().indexOf( q ) === -1 );
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
					var $opt = $( '<option></option>' ).val( term.id ).text( decodeName( term.name ) );
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
			var noun = kind === 'image' ? 'Image' : kind === 'pdf' ? 'PDF' : 'File';
			var frame;
			$field.find( '.nm-cms-media-choose' ).on( 'click', function () {
				frame = wp.media( {
					title: kind === 'image' ? 'Choose an image' : kind === 'pdf' ? 'Choose a PDF' : 'Choose an audio or video file',
					button: { text: 'Use this ' + noun.toLowerCase() },
					library: kind === 'image' ? { type: 'image' } : kind === 'pdf' ? { type: 'application/pdf' } : { type: [ 'audio', 'video' ] },
					multiple: false
				} );
				frame.on( 'select', function () {
					var att = frame.state().get( 'selection' ).first().toJSON();
					$field.find( '.nm-cms-media-id' ).val( att.id );
					if ( kind === 'image' ) {
						var previewUrl = att.sizes && att.sizes.medium ? att.sizes.medium.url : att.url;
						$field.find( '.nm-cms-media-preview-wrap' ).html( mediaPreview( 'image', { id: att.id, url: previewUrl } ) );
					} else {
						$field.find( '.nm-cms-media-preview-wrap' ).html( mediaPreview( kind, { id: att.id, filename: att.filename, url: att.url } ) );
					}
					$field.find( '.nm-cms-media-choose span' ).text( 'Change ' + noun );
					$field.find( '.nm-cms-media-remove' ).prop( 'hidden', false );
					markDirty();
					if ( kind === 'pdf' ) {
						fillSummaryFromPdf( $body, att.url );
					}
				} );
				frame.open();
			} );
			$field.find( '.nm-cms-media-remove' ).on( 'click', function () {
				$field.find( '.nm-cms-media-id' ).val( '' );
				$field.find( '.nm-cms-media-preview-wrap' ).html( mediaPreview( kind, null ) );
				$field.find( '.nm-cms-media-choose span' ).text( 'Choose ' + noun );
				$( this ).prop( 'hidden', true );
				markDirty();
			} );
		} );

		$body.find( '.nm-cms-media-multi-field' ).each( function () {
			var $field = $( this );
			var $list = $field.find( '.nm-cms-media-multi-list' );
			if ( $list.sortable ) {
				$list.sortable( { update: markDirty } );
			}
			var frame;
			$field.find( '.nm-cms-media-multi-add' ).on( 'click', function () {
				frame = wp.media( { title: 'Add images', button: { text: 'Add to gallery' }, library: { type: 'image' }, multiple: true } );
				frame.on( 'select', function () {
					var selection = frame.state().get( 'selection' );
					selection.each( function ( attachment ) {
						var data = attachment.toJSON();
						var thumbUrl = data.sizes && data.sizes.thumbnail ? data.sizes.thumbnail.url : data.url;
						$list.append( mediaMultiItem( data.id, thumbUrl ) );
					} );
					markDirty();
				} );
				frame.open();
			} );
			$list.on( 'click', '.nm-cms-media-multi-remove', function () {
				$( this ).closest( '.nm-cms-media-multi-item' ).remove();
				markDirty();
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
				case 'taxonomy_multi':
					// Not sent until the list has loaded, so a quick save can't clear the terms.
					if ( $field.find( '.nm-cms-multi' ).attr( 'data-loaded' ) !== '1' ) {
						break;
					}
					var termIds = [];
					$field.find( '.nm-cms-multi__item input:checked' ).each( function () {
						termIds.push( parseInt( $( this ).val(), 10 ) );
					} );
					data[ key ] = termIds;
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
