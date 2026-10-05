/*
 * First line of a PDF, read in the browser (used by the written shiur form in the
 * Site Control Panel). The Summary field is filled from it, and the homepage card
 * shows that Summary.
 *
 * Reading uses Mozilla's pdf.js, loaded from cdnjs at a fixed version the first
 * time it's needed. It isn't bundled with the plugin. Nothing is loaded anywhere
 * else. The text comes from the PDF's own font data, so Hebrew and other
 * non-Latin text works.
 */
( function ( window, document ) {
	'use strict';

	var PDFJS_VERSION = '3.11.174';
	var BASE          = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/' + PDFJS_VERSION + '/';
	var loading       = null;

	function loadScript( src ) {
		return new Promise( function ( resolve, reject ) {
			var script   = document.createElement( 'script' );
			script.src   = src;
			script.async = true;
			script.onload  = function () {
				resolve();
			};
			script.onerror = function () {
				reject( new Error( 'Could not load the PDF reader from ' + src ) );
			};
			document.head.appendChild( script );
		} );
	}

	function ensurePdfJs() {
		if ( window.pdfjsLib ) {
			return Promise.resolve( window.pdfjsLib );
		}
		if ( ! loading ) {
			loading = loadScript( BASE + 'pdf.min.js' ).then( function () {
				window.pdfjsLib.GlobalWorkerOptions.workerSrc = BASE + 'pdf.worker.min.js';
				return window.pdfjsLib;
			} );
		}
		return loading;
	}

	var RTL = /[֐-ࣿיִ-﷿ﹰ-﻿]/; // Hebrew, Arabic, and related scripts

	/*
	 * Groups the page's text items into lines by their vertical position, top line
	 * first. Within a line, items go left to right, or right to left for Hebrew,
	 * Yiddish and Arabic script.
	 */
	function firstLineOf( items ) {
		var lines = [];
		items.forEach( function ( item ) {
			var text = String( item.str || '' );
			if ( ! text.trim() ) {
				return;
			}
			var y = item.transform ? item.transform[ 5 ] : 0;
			var x = item.transform ? item.transform[ 4 ] : 0;
			var line = null;
			for ( var i = 0; i < lines.length; i++ ) {
				if ( Math.abs( lines[ i ].y - y ) < 3 ) {
					line = lines[ i ];
					break;
				}
			}
			if ( ! line ) {
				line = { y: y, parts: [] };
				lines.push( line );
			}
			line.parts.push( { text: text, x: x } );
		} );

		if ( ! lines.length ) {
			return '';
		}

		lines.sort( function ( a, b ) {
			return b.y - a.y; // Higher on the page first.
		} );

		var top  = lines[ 0 ];
		var rtl  = top.parts.some( function ( p ) {
			return RTL.test( p.text );
		} );
		top.parts.sort( function ( a, b ) {
			return rtl ? b.x - a.x : a.x - b.x;
		} );

		return top.parts.map( function ( p ) {
			return p.text;
		} ).join( ' ' ).replace( /\s+/g, ' ' ).trim();
	}

	/**
	 * Resolves to the first line of page one of the PDF at url. Rejects when the
	 * file can't be fetched or read (for example, a CDN that doesn't allow the
	 * browser to read it).
	 */
	function read( url ) {
		return ensurePdfJs().then( function ( pdfjsLib ) {
			return pdfjsLib.getDocument( { url: url, withCredentials: false } ).promise;
		} ).then( function ( doc ) {
			return doc.getPage( 1 ).then( function ( page ) {
				return page.getTextContent();
			} ).then( function ( content ) {
				return firstLineOf( content.items );
			} );
		} );
	}

	window.NMPdfFirstLine = { read: read, firstLineOf: firstLineOf };
} )( window, document );
