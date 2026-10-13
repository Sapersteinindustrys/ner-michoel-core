/**
 * A clean title from a file name, in the browser: "bava-kama.mp3" becomes "Bava Kama".
 *
 * This is the same function as ner_michoel_title_from_filename() in includes/file-names.php (read that file's
 * header for the rules). The two are tested on the same names, so what Upload Many and the shiur form suggest is
 * what the server would make. Used by:
 *   - assets/media-pickers.js: the titles Upload Many suggests for the chosen files.
 *   - assets/custom-admin-cms.js: the Title of a new shiur or written shiur, filled from the file you pick.
 *
 * No jQuery, so it also runs in plain Node for the tests.
 */
( function ( root ) {
	'use strict';

	// A closed list, so "bava.kama" (no ending) is not read as a file called "bava" ending in ".kama".
	var EXTENSIONS = 'mp3|m4a|m4b|mp4|m4v|mov|wav|wma|wax|wmv|wm|wmx|aac|ogg|oga|ogv|opus|flac|mka|mkv|webm|avi|divx|flv|asf|asx|3gp|3gpp|3g2|3gp2|amr|ra|ram|mid|midi|mp2|mpg|mpeg|mpe|mpga|aif|aiff|pdf';
	// White space, written out so PHP and the browser agree on exactly the same characters.
	var WHITE = '\\u0009-\\u000d\\u0020\\u0085\\u00a0\\u1680\\u2000-\\u200a\\u2028\\u2029\\u202f\\u205f\\u3000\\ufeff';
	// What separates the words: white space, _ . + ~ | and every kind of dash.
	var SEPARATORS = WHITE + '_.+~|\\u2010-\\u2015\\u2212\\-';

	var SPLIT = new RegExp( '([' + SEPARATORS + ']+)' );
	var WHITE_RUN = new RegExp( '[' + WHITE + ']+', 'g' );
	var ENDING = new RegExp( '\\.(?:' + EXTENSIONS + ')$', 'i' );
	var DASHES = [ '-', '\u2010', '\u2011', '\u2012', '\u2013' ];

	// Anything that is not a letter, number or mark. Older browsers without \p{...} treat every non-ASCII character as a letter.
	var NOT_LETTER_AT_START;
	var NOT_LETTER_RUN;
	try {
		NOT_LETTER_AT_START = new RegExp( '^[^\\p{L}\\p{N}]*', 'u' );
		NOT_LETTER_RUN = new RegExp( '[^\\p{L}\\p{N}\\p{M}]+', 'gu' );
	} catch ( e ) {
		NOT_LETTER_AT_START = /^[^A-Za-z0-9\u00c0-\uffff]*/;
		NOT_LETTER_RUN = /[^A-Za-z0-9\u00c0-\uffff]+/g;
	}

	// A dash (any look-alike, with spaces around it) that may stand for "to" between two page numbers.
	function isDash( separator ) {
		return DASHES.indexOf( String( separator ).replace( WHITE_RUN, '' ) ) !== -1;
	}

	// A page of a Gemara: one to three digits, no leading zero (that is a counter, "025"), an optional a or b.
	function isPage( token ) {
		return /^[1-9][0-9]{0,2}[ab]?$/i.test( token );
	}

	// Digits with an optional a or b: a page, a counter or a year.
	function isNumber( token ) {
		return /^[0-9]+[ab]?$/i.test( token );
	}

	// Where a page falls in the book: 27 and 27a are the front of daf 27, 27b its back. Only used to order two pages.
	function pageValue( token ) {
		return 2 * parseInt( token, 10 ) + ( token.slice( -1 ).toLowerCase() === 'b' ? 1 : 0 );
	}

	// Capital for a plain lowercase a-z first letter, after any opening bracket or quote ("(night)" -> "(Night)").
	function capitalise( token ) {
		var lead = NOT_LETTER_AT_START.exec( token );
		lead = lead ? lead[ 0 ] : '';
		var first = token.charAt( lead.length );
		if ( first.length === 1 && first >= 'a' && first <= 'z' ) {
			return lead + first.toUpperCase() + token.slice( lead.length + 1 );
		}
		return token;
	}

	function titleFromFilename( name ) {
		var s = String( name === null || name === undefined ? '' : name ).trim();

		// A path leaves only its last part.
		s = s.replace( /^[\s\S]*[\\/]/, '' );

		// "bava%20kama": read back into letters only when every % is a real escape and the result is valid text.
		if ( /%[0-9A-Fa-f]{2}/.test( s ) && ! /%(?![0-9A-Fa-f]{2})/.test( s ) ) {
			try {
				s = decodeURIComponent( s );
			} catch ( e ) {
				// Not valid text: keep it as it was.
			}
		}

		// The file ending (twice over for "name.m4a.mp3").
		for ( var i = 0; i < 3; i++ ) {
			var trimmed = s.replace( ENDING, '' );
			if ( trimmed === s ) {
				break;
			}
			s = trimmed;
		}

		// The upload stamp the old site put in front of its file names ("1787166936_").
		s = s.replace( /^[0-9]{9,13}[_-]/, '' );

		// Words and the separators between them: word, separator, word, separator, ...
		var parts = s.split( SPLIT );
		var tokens = [];
		var seps = [];
		for ( var p = 0; p < parts.length; p += 2 ) {
			if ( parts[ p ] === '' ) {
				continue; // Nothing before the first separator, or after the last one.
			}
			tokens.push( parts[ p ] );
			seps.push( p + 1 < parts.length ? parts[ p + 1 ] : '' );
		}

		var count = tokens.length;
		var out = '';
		for ( var k = 0; k < count; k++ ) {
			out += capitalise( tokens[ k ] );
			if ( k + 1 >= count ) {
				break;
			}
			var range = isDash( seps[ k ] ) &&
				isPage( tokens[ k ] ) &&
				isPage( tokens[ k + 1 ] ) &&
				pageValue( tokens[ k + 1 ] ) > pageValue( tokens[ k ] ) &&
				! ( k > 0 && isDash( seps[ k - 1 ] ) && isNumber( tokens[ k - 1 ] ) ) &&
				! ( k + 2 < count && isDash( seps[ k + 1 ] ) && isNumber( tokens[ k + 2 ] ) );
			out += range ? '-' : ' ';
		}
		return out;
	}

	// What two names must share to count as the same name (the same as ner_michoel_name_key()).
	function nameKey( name ) {
		var title = titleFromFilename( name );
		if ( ! title ) {
			return '';
		}
		return title.toLowerCase().replace( NOT_LETTER_RUN, '-' ).replace( /^-+|-+$/g, '' );
	}

	// Is the title just the name of the file: the same words, or the same words with the number WordPress adds to a
	// stored file whose name was taken ("bava-kama" and bava-kama-1.mp3)? That number belongs to the file, not the title.
	function nameMatchesFile( title, file ) {
		var t = nameKey( title );
		var f = nameKey( file );
		if ( ! t || ! f ) {
			return false;
		}
		return f === t || ( f.indexOf( t + '-' ) === 0 && /^[0-9]{1,3}$/.test( f.slice( t.length + 1 ) ) );
	}

	// The title for a Media Library item as wp.media gives it ({ title, filename }): its own title when that is only
	// its file name (the server has already cleaned it), otherwise the cleaned file name.
	function titleFromAttachment( att ) {
		att = att || {};
		var file = att.filename || '';
		var title = att.title || '';
		if ( title && file && nameMatchesFile( title, file ) ) {
			return titleFromFilename( title );
		}
		return titleFromFilename( file || title );
	}

	var api = {
		titleFromFilename: titleFromFilename,
		nameKey: nameKey,
		nameMatchesFile: nameMatchesFile,
		titleFromAttachment: titleFromAttachment
	};

	root.NMFileNames = api;
	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = api;
	}
}( typeof window !== 'undefined' ? window : this ) );
