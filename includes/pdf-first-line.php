<?php
/**
 * First line of a PDF, read on the server. The written-summary batch
 * (written-summary-batch.php) uses this to fill the Summary of written
 * shiurim, so it runs with no browser open. assets/pdf-first-line.js (pdf.js,
 * in the Site Control Panel) is still the way to read a PDF that this can't.
 *
 * Written by hand, with no library, so nothing is vendored. It reads only what
 * page 1's text needs: the page tree, the page's content stream and the fonts
 * that stream uses. Text is decoded through each font's ToUnicode map (how Word
 * and most PDF writers embed Hebrew and other non-Latin text), and glyph widths
 * from /Widths or /W place each run on the page, so lines can be put in order.
 *
 * Returns '' for anything it can't read with confidence: encrypted files, pages
 * with no text layer (scans), and fonts with neither a ToUnicode map nor a plain
 * Latin encoding. The batch marks those as checked, so they aren't retried.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NER_MICHOEL_PDF_RTL', '/[\x{0590}-\x{08FF}\x{FB1D}-\x{FDFF}\x{FE70}-\x{FEFF}]/u' );

/**
 * Top line of page 1 as plain text, or '' when the PDF can't be read.
 */
function ner_michoel_pdf_first_line( $pdf ) {
	if ( ! is_string( $pdf ) || '' === $pdf || false === strpos( substr( $pdf, 0, 1024 ), '%PDF' ) ) {
		return '';
	}

	try {
		$doc  = ner_michoel_pdf_open( $pdf );
		$page = $doc ? ner_michoel_pdf_first_page( $doc ) : null;
		if ( ! $page ) {
			return '';
		}
		return ner_michoel_pdf_top_line( ner_michoel_pdf_page_items( $doc, $page ) );
	} catch ( Throwable $e ) {
		return '';
	}
}

/* ------------------------------------------------------------------
 * File structure: objects, object streams, trailer.
 * ------------------------------------------------------------------ */

/**
 * Reads every "N G obj ... endobj" in the file, in file order, so a later
 * incremental update overrides an earlier version of the same object. Object
 * streams (PDF 1.5+) are expanded after, into objects not already present.
 */
function ner_michoel_pdf_open( $pdf ) {
	$doc = array(
		'objects' => array(),
		'cache'   => array(),
		'root'    => null,
	);

	if ( ! preg_match_all( '/(?<![0-9])(\d+)\s+(\d+)\s+obj\b/', $pdf, $heads, PREG_OFFSET_CAPTURE ) ) {
		return null;
	}

	$count = count( $heads[0] );
	for ( $i = 0; $i < $count; $i++ ) {
		$num   = (int) $heads[1][ $i ][0];
		$start = $heads[0][ $i ][1] + strlen( $heads[0][ $i ][0] );
		$stop  = ( $i + 1 < $count ) ? $heads[0][ $i + 1 ][1] : strlen( $pdf );

		$body = substr( $pdf, $start, $stop - $start );
		$end  = strrpos( $body, 'endobj' );
		if ( false !== $end ) {
			$body = substr( $body, 0, $end );
		}

		$doc['objects'][ $num ] = ner_michoel_pdf_split_stream( $body );
	}

	ner_michoel_pdf_expand_object_streams( $doc );

	$doc['root'] = ner_michoel_pdf_find_root( $doc, $pdf );
	return null === $doc['root'] ? null : $doc;
}

/**
 * Separates an object's dictionary from its stream data. The stream keyword
 * comes straight after the dictionary's closing >>, so that's where to split.
 * Binary stream data can contain the word "endstream" only by accident, and
 * the first one after the data start is the right one.
 */
function ner_michoel_pdf_split_stream( $body ) {
	if ( ! preg_match( '/>>\s*stream(\r\n|\n|\r)/', $body, $m, PREG_OFFSET_CAPTURE ) ) {
		return array(
			'body' => $body,
			'data' => null,
		);
	}

	$dict_end   = $m[0][1] + 2;
	$data_start = $m[0][1] + strlen( $m[0][0] );
	$data_end   = strpos( $body, 'endstream', $data_start );
	if ( false === $data_end ) {
		$data_end = strlen( $body );
	}

	$data = substr( $body, $data_start, $data_end - $data_start );
	if ( "\r\n" === substr( $data, -2 ) ) {
		$data = substr( $data, 0, -2 );
	} elseif ( "\n" === substr( $data, -1 ) || "\r" === substr( $data, -1 ) ) {
		$data = substr( $data, 0, -1 );
	}

	return array(
		'body' => substr( $body, 0, $dict_end ),
		'data' => $data,
	);
}

/**
 * Object streams hold several objects in one compressed stream, with a header
 * of "objnum offset" pairs. Font and page dictionaries often live there.
 */
function ner_michoel_pdf_expand_object_streams( &$doc ) {
	foreach ( array_keys( $doc['objects'] ) as $num ) {
		$obj = $doc['objects'][ $num ];
		if ( null === $obj['data'] ) {
			continue;
		}

		$items = ner_michoel_pdf_body_items( $obj['body'] );
		if ( 'ObjStm' !== ner_michoel_pdf_name_of( isset( $items['Type'] ) ? $items['Type'] : null ) ) {
			continue;
		}

		$data = ner_michoel_pdf_decode_stream( $obj['data'], $items, $doc );
		if ( null === $data ) {
			continue;
		}

		$count = (int) ner_michoel_pdf_num_or( isset( $items['N'] ) ? $items['N'] : null, 0 );
		$first = (int) ner_michoel_pdf_num_or( isset( $items['First'] ) ? $items['First'] : null, 0 );

		if ( ! preg_match_all( '/\d+/', substr( $data, 0, $first ), $nums ) ) {
			continue;
		}

		$pairs   = array_map( 'intval', $nums[0] );
		$entries = array();
		for ( $k = 0; $k + 1 < count( $pairs ) && count( $entries ) < $count; $k += 2 ) {
			$entries[] = array( $pairs[ $k ], $pairs[ $k + 1 ] );
		}

		foreach ( $entries as $e => $entry ) {
			$from = $first + $entry[1];
			$to   = isset( $entries[ $e + 1 ] ) ? $first + $entries[ $e + 1 ][1] : strlen( $data );
			if ( ! isset( $doc['objects'][ $entry[0] ] ) ) {
				$doc['objects'][ $entry[0] ] = array(
					'body' => substr( $data, $from, max( 0, $to - $from ) ),
					'data' => null,
				);
			}
		}
	}
}

/**
 * Applies a stream's filters. Only FlateDecode (zlib) is needed for text; any
 * other filter (images, LZW, and so on) returns null and the stream is skipped.
 */
function ner_michoel_pdf_decode_stream( $data, $items, &$doc ) {
	$filters = ner_michoel_pdf_resolve( $doc, isset( $items['Filter'] ) ? $items['Filter'] : null );
	$names   = array();

	if ( is_array( $filters ) ) {
		if ( 'name' === $filters['type'] ) {
			$names[] = $filters['value'];
		} elseif ( 'array' === $filters['type'] ) {
			foreach ( $filters['value'] as $node ) {
				$names[] = ner_michoel_pdf_name_of( ner_michoel_pdf_resolve( $doc, $node ) );
			}
		}
	}

	foreach ( $names as $name ) {
		if ( 'FlateDecode' !== $name && 'Fl' !== $name ) {
			return null;
		}

		// Some writers include the two-byte zlib header, some don't, and some
		// have a bad checksum at the end. Try each form before giving up.
		$out = @gzuncompress( $data ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $out ) {
			$out = @gzinflate( substr( $data, 2 ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		if ( false === $out ) {
			$out = @gzinflate( $data ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		if ( false === $out ) {
			return null;
		}
		$data = $out;
	}

	return $data;
}

/**
 * Decoded bytes of the stream object $num, or null when it isn't a stream we
 * can decode.
 */
function ner_michoel_pdf_stream_bytes( &$doc, $num ) {
	if ( ! isset( $doc['objects'][ $num ] ) || null === $doc['objects'][ $num ]['data'] ) {
		return null;
	}

	$obj = $doc['objects'][ $num ];
	return ner_michoel_pdf_decode_stream( $obj['data'], ner_michoel_pdf_body_items( $obj['body'] ), $doc );
}

/**
 * Decoded bytes for a reference node that points at a stream, else null.
 */
function ner_michoel_pdf_stream_of( &$doc, $node ) {
	if ( ! is_array( $node ) || 'ref' !== $node['type'] ) {
		return null;
	}
	return ner_michoel_pdf_stream_bytes( $doc, (int) $node['value'] );
}

/**
 * Object number of the document catalog. Taken from the last trailer when
 * there is one. Files with a cross-reference stream (PDF 1.5+) have no trailer
 * keyword, so for those the catalog is found by its type.
 */
function ner_michoel_pdf_find_root( &$doc, $pdf ) {
	if ( preg_match_all( '/trailer\s*<</', $pdf, $trailers, PREG_OFFSET_CAPTURE ) ) {
		$last = end( $trailers[0] );
		$pos  = $last[1] + strlen( 'trailer' );
		$node = ner_michoel_pdf_parse( $pdf, $pos );
		if ( is_array( $node ) && 'dict' === $node['type'] && isset( $node['value']['Root'] ) && 'ref' === $node['value']['Root']['type'] ) {
			return (int) $node['value']['Root']['value'];
		}
	}

	$found = null;
	foreach ( $doc['objects'] as $num => $obj ) {
		if ( false !== strpos( $obj['body'], '/Catalog' ) ) {
			$found = (int) $num;
		}
	}
	return $found;
}

/* ------------------------------------------------------------------
 * Objects and values.
 * ------------------------------------------------------------------ */

/**
 * Parsed value of object $num, cached. Null when the object isn't in the file.
 */
function ner_michoel_pdf_object( &$doc, $num ) {
	if ( array_key_exists( $num, $doc['cache'] ) ) {
		return $doc['cache'][ $num ];
	}

	$value = null;
	if ( isset( $doc['objects'][ $num ] ) ) {
		$pos   = 0;
		$value = ner_michoel_pdf_parse( $doc['objects'][ $num ]['body'], $pos );
	}

	$doc['cache'][ $num ] = $value;
	return $value;
}

/**
 * Follows references until it reaches a value. Capped, so a reference loop
 * can't run forever.
 */
function ner_michoel_pdf_resolve( &$doc, $node ) {
	for ( $i = 0; $i < 8 && is_array( $node ) && 'ref' === $node['type']; $i++ ) {
		$node = ner_michoel_pdf_object( $doc, (int) $node['value'] );
	}
	return $node;
}

/**
 * Entry $key of a dictionary's items, with any reference resolved.
 */
function ner_michoel_pdf_get( &$doc, $items, $key ) {
	return isset( $items[ $key ] ) ? ner_michoel_pdf_resolve( $doc, $items[ $key ] ) : null;
}

/**
 * Items of the dictionary at the start of an object body, or an empty array.
 */
function ner_michoel_pdf_body_items( $body ) {
	$pos  = 0;
	$node = ner_michoel_pdf_parse( $body, $pos );
	return ( is_array( $node ) && 'dict' === $node['type'] ) ? $node['value'] : array();
}

function ner_michoel_pdf_name_of( $node ) {
	return ( is_array( $node ) && 'name' === $node['type'] ) ? $node['value'] : '';
}

function ner_michoel_pdf_num_or( $node, $default ) {
	return ( is_array( $node ) && 'num' === $node['type'] ) ? (float) $node['value'] : $default;
}

/**
 * Skips whitespace and % comments. Returns the new position.
 */
function ner_michoel_pdf_skip( $s, $pos ) {
	$len = strlen( $s );
	while ( $pos < $len ) {
		$c = $s[ $pos ];
		if ( false !== strpos( "\0\t\n\f\r ", $c ) ) {
			$pos++;
		} elseif ( '%' === $c ) {
			$pos += strcspn( $s, "\r\n", $pos );
		} else {
			break;
		}
	}
	return $pos;
}

/**
 * Reads one PDF value at $pos and moves $pos past it. Returns null at the end of
 * the input, or for a byte that doesn't start a value (which is skipped). Every
 * call either moves $pos or returns null at the end, so loops over it finish.
 *
 * Values are arrays with 'type' and 'value':
 *   num, str (bytes), name, kw (keyword or operator), ref (object number),
 *   array (list of values), dict (name => value).
 */
function ner_michoel_pdf_parse( $s, &$pos ) {
	$pos = ner_michoel_pdf_skip( $s, $pos );
	$len = strlen( $s );
	if ( $pos >= $len ) {
		return null;
	}

	$c = $s[ $pos ];

	if ( '<' === $c && $pos + 1 < $len && '<' === $s[ $pos + 1 ] ) {
		$pos += 2;
		return array(
			'type'  => 'dict',
			'value' => ner_michoel_pdf_parse_items( $s, $pos, '>' ),
		);
	}

	if ( '[' === $c ) {
		$pos++;
		return array(
			'type'  => 'array',
			'value' => ner_michoel_pdf_parse_items( $s, $pos, ']' ),
		);
	}

	if ( '<' === $c ) {
		$end = strpos( $s, '>', $pos + 1 );
		$end = ( false === $end ) ? $len : $end;
		$hex = preg_replace( '/[^0-9A-Fa-f]/', '', substr( $s, $pos + 1, $end - $pos - 1 ) );
		$pos = $end + 1;
		if ( 1 === strlen( $hex ) % 2 ) {
			$hex .= '0';
		}
		return array(
			'type'  => 'str',
			'value' => (string) hex2bin( $hex ),
		);
	}

	if ( '(' === $c ) {
		return array(
			'type'  => 'str',
			'value' => ner_michoel_pdf_literal( $s, $pos ),
		);
	}

	if ( '/' === $c ) {
		preg_match( '/\G\/([^\s\/\[\]<>(){}%]*)/', $s, $m, 0, $pos );
		$pos += strlen( $m[0] );
		return array(
			'type'  => 'name',
			'value' => preg_replace_callback(
				'/#([0-9A-Fa-f]{2})/',
				function ( $h ) {
					return chr( hexdec( $h[1] ) );
				},
				$m[1]
			),
		);
	}

	if ( preg_match( '/\G[+-]?(?:\d+\.?\d*|\.\d+)/', $s, $m, 0, $pos ) ) {
		$pos += strlen( $m[0] );
		if ( preg_match( '/^\d+$/', $m[0] ) && preg_match( '/\G\s+\d+\s+R(?![^\s\/\[\]<>(){}%])/', $s, $r, 0, $pos ) ) {
			$pos += strlen( $r[0] );
			return array(
				'type'  => 'ref',
				'value' => (int) $m[0],
			);
		}
		return array(
			'type'  => 'num',
			'value' => (float) $m[0],
		);
	}

	if ( preg_match( '/\G[^\s\/\[\]<>(){}%]+/', $s, $m, 0, $pos ) ) {
		$pos += strlen( $m[0] );
		return array(
			'type'  => 'kw',
			'value' => $m[0],
		);
	}

	$pos++;
	return null;
}

/**
 * Items up to the closing ] (arrays) or >> (dictionaries). $pos is moved past it.
 */
function ner_michoel_pdf_parse_items( $s, &$pos, $close ) {
	$len     = strlen( $s );
	$is_dict = '>' === $close;
	$items   = array();

	while ( true ) {
		$pos = ner_michoel_pdf_skip( $s, $pos );
		if ( $pos >= $len ) {
			break;
		}

		if ( $is_dict ) {
			if ( '>' === $s[ $pos ] && $pos + 1 < $len && '>' === $s[ $pos + 1 ] ) {
				$pos += 2;
				break;
			}
			$key = ner_michoel_pdf_parse( $s, $pos );
			if ( ! is_array( $key ) || 'name' !== $key['type'] ) {
				continue;
			}
			$value = ner_michoel_pdf_parse( $s, $pos );
			if ( null !== $value ) {
				$items[ $key['value'] ] = $value;
			}
		} else {
			if ( ']' === $s[ $pos ] ) {
				$pos++;
				break;
			}
			$value = ner_michoel_pdf_parse( $s, $pos );
			if ( null !== $value ) {
				$items[] = $value;
			}
		}
	}

	return $items;
}

/**
 * A literal string "(...)", with nested parentheses and backslash escapes.
 * Moves $pos past the closing parenthesis.
 */
function ner_michoel_pdf_literal( $s, &$pos ) {
	$len   = strlen( $s );
	$depth = 0;
	$out   = '';
	$pos++;

	while ( $pos < $len ) {
		$c = $s[ $pos ];

		if ( '\\' === $c ) {
			$n    = isset( $s[ $pos + 1 ] ) ? $s[ $pos + 1 ] : '';
			$pos += 2;

			if ( 'n' === $n ) {
				$out .= "\n";
			} elseif ( 'r' === $n ) {
				$out .= "\r";
			} elseif ( 't' === $n ) {
				$out .= "\t";
			} elseif ( 'b' === $n ) {
				$out .= "\x08";
			} elseif ( 'f' === $n ) {
				$out .= "\x0c";
			} elseif ( "\r" === $n ) {
				// Line continuation: a backslash before a line break adds nothing.
				if ( isset( $s[ $pos ] ) && "\n" === $s[ $pos ] ) {
					$pos++;
				}
			} elseif ( "\n" === $n ) {
				// Line continuation, as above.
			} elseif ( '' !== $n && false !== strpos( '01234567', $n ) ) {
				$oct = $n;
				for ( $k = 0; $k < 2 && $pos < $len && false !== strpos( '01234567', $s[ $pos ] ); $k++ ) {
					$oct .= $s[ $pos ];
					$pos++;
				}
				$out .= chr( octdec( $oct ) & 0xFF );
			} else {
				// \( \) \\ and any unknown escape stand for the character itself.
				$out .= $n;
			}
			continue;
		}

		if ( '(' === $c ) {
			$depth++;
			$out .= $c;
		} elseif ( ')' === $c ) {
			if ( 0 === $depth ) {
				$pos++;
				break;
			}
			$depth--;
			$out .= $c;
		} else {
			$out .= $c;
		}
		$pos++;
	}

	return $out;
}

/* ------------------------------------------------------------------
 * Page 1, its content and its fonts.
 * ------------------------------------------------------------------ */

/**
 * The first page: walks the page tree down its first kids. Resources can be
 * inherited from a parent node, so they're carried down the walk.
 */
function ner_michoel_pdf_first_page( &$doc ) {
	$catalog = ner_michoel_pdf_object( $doc, $doc['root'] );
	if ( ! is_array( $catalog ) || 'dict' !== $catalog['type'] ) {
		return null;
	}

	return ner_michoel_pdf_find_page( $doc, ner_michoel_pdf_get( $doc, $catalog['value'], 'Pages' ), null, 0 );
}

function ner_michoel_pdf_find_page( &$doc, $node, $inherited, $depth ) {
	$node = ner_michoel_pdf_resolve( $doc, $node );
	if ( ! is_array( $node ) || 'dict' !== $node['type'] || $depth > 32 ) {
		return null;
	}

	$items     = $node['value'];
	$resources = isset( $items['Resources'] ) ? $items['Resources'] : $inherited;
	$type      = ner_michoel_pdf_name_of( ner_michoel_pdf_get( $doc, $items, 'Type' ) );

	if ( 'Page' === $type || ( '' === $type && ! isset( $items['Kids'] ) ) ) {
		return array(
			'dict'      => $items,
			'resources' => $resources,
		);
	}

	$kids = ner_michoel_pdf_get( $doc, $items, 'Kids' );
	if ( ! is_array( $kids ) || 'array' !== $kids['type'] ) {
		return null;
	}

	foreach ( $kids['value'] as $kid ) {
		$found = ner_michoel_pdf_find_page( $doc, $kid, $resources, $depth + 1 );
		if ( $found ) {
			return $found;
		}
	}

	return null;
}

/**
 * Concatenated bytes of a page's content streams. /Contents is a single stream,
 * an array of streams, or a reference to either.
 */
function ner_michoel_pdf_page_content( &$doc, $items ) {
	if ( ! isset( $items['Contents'] ) || ! is_array( $items['Contents'] ) ) {
		return '';
	}

	$raw   = $items['Contents'];
	$nodes = array();

	if ( 'array' === $raw['type'] ) {
		$nodes = $raw['value'];
	} elseif ( null === ner_michoel_pdf_stream_of( $doc, $raw ) ) {
		$resolved = ner_michoel_pdf_resolve( $doc, $raw );
		if ( is_array( $resolved ) && 'array' === $resolved['type'] ) {
			$nodes = $resolved['value'];
		}
	} else {
		$nodes = array( $raw );
	}

	$out = '';
	foreach ( $nodes as $node ) {
		$bytes = ner_michoel_pdf_stream_of( $doc, $node );
		if ( null !== $bytes ) {
			$out .= $bytes . "\n";
		}
	}
	return $out;
}

/**
 * Name => font decoder for the page's /Font resources. A font that can't be
 * decoded is null, and its text is left out.
 */
function ner_michoel_pdf_page_fonts( &$doc, $resources ) {
	$res = ner_michoel_pdf_resolve( $doc, $resources );
	if ( ! is_array( $res ) || 'dict' !== $res['type'] ) {
		return array();
	}

	$font_dict = ner_michoel_pdf_get( $doc, $res['value'], 'Font' );
	if ( ! is_array( $font_dict ) || 'dict' !== $font_dict['type'] ) {
		return array();
	}

	$fonts = array();
	foreach ( $font_dict['value'] as $name => $ref ) {
		$font           = ner_michoel_pdf_resolve( $doc, $ref );
		$fonts[ $name ] = ( is_array( $font ) && 'dict' === $font['type'] ) ? ner_michoel_pdf_font_decoder( $doc, $font['value'] ) : null;
	}
	return $fonts;
}

/**
 * How a font's codes become text and widths. Returns null when there's no way
 * to read the font's text, so it's left out rather than guessed.
 *
 * - width: bytes per glyph code (2 for composite Identity-H fonts, else 1).
 * - map: code (hex) => text, from the ToUnicode CMap. Used when present.
 * - latin: plain Latin fallback for a simple font with a standard encoding and
 *   no ToUnicode map.
 * - widths / default: glyph advance in 1/1000 text space.
 */
function ner_michoel_pdf_font_decoder( &$doc, $items ) {
	$is_cid = 'Type0' === ner_michoel_pdf_name_of( ner_michoel_pdf_get( $doc, $items, 'Subtype' ) );

	$font = array(
		'width'   => $is_cid ? 2 : 1,
		'map'     => null,
		'latin'   => false,
		'widths'  => array(),
		'default' => 500,
	);

	if ( isset( $items['ToUnicode'] ) ) {
		$cmap_bytes = ner_michoel_pdf_stream_of( $doc, $items['ToUnicode'] );
		if ( null !== $cmap_bytes ) {
			$cmap = ner_michoel_pdf_parse_cmap( $cmap_bytes );
			if ( $cmap['map'] ) {
				$font['map']   = $cmap['map'];
				$font['width'] = $is_cid ? ( $cmap['width'] ?: 2 ) : 1;
			}
		}
	}

	if ( null === $font['map'] ) {
		if ( $is_cid ) {
			return null;
		}
		$encoding = ner_michoel_pdf_get( $doc, $items, 'Encoding' );
		if ( is_array( $encoding ) && 'dict' === $encoding['type'] ) {
			// A Differences table names glyphs; mapping those names isn't done here.
			return null;
		}
		$font['latin'] = true;
	}

	if ( $is_cid ) {
		$desc_list = ner_michoel_pdf_get( $doc, $items, 'DescendantFonts' );
		$desc      = null;
		if ( is_array( $desc_list ) && 'array' === $desc_list['type'] && $desc_list['value'] ) {
			$desc = ner_michoel_pdf_resolve( $doc, $desc_list['value'][0] );
		}
		$desc_items      = ( is_array( $desc ) && 'dict' === $desc['type'] ) ? $desc['value'] : array();
		$font['default'] = ner_michoel_pdf_num_or( ner_michoel_pdf_get( $doc, $desc_items, 'DW' ), 1000 );

		$w = ner_michoel_pdf_get( $doc, $desc_items, 'W' );
		if ( is_array( $w ) && 'array' === $w['type'] ) {
			$font['widths'] = ner_michoel_pdf_cid_widths( $doc, $w['value'] );
		}
	} else {
		$first = (int) ner_michoel_pdf_num_or( ner_michoel_pdf_get( $doc, $items, 'FirstChar' ), 0 );
		$list  = ner_michoel_pdf_get( $doc, $items, 'Widths' );
		if ( is_array( $list ) && 'array' === $list['type'] ) {
			foreach ( $list['value'] as $i => $node ) {
				$font['widths'][ $first + $i ] = ner_michoel_pdf_num_or( ner_michoel_pdf_resolve( $doc, $node ), 0 );
			}
		}
	}

	return $font;
}

/**
 * Glyph widths of a composite font's /W array. Two forms are used:
 * "c [w1 w2 ...]" and "cfirst clast w".
 */
function ner_michoel_pdf_cid_widths( &$doc, $list ) {
	$out   = array();
	$list  = array_values( $list );
	$count = count( $list );
	$i     = 0;

	while ( $i < $count ) {
		$first_node = ner_michoel_pdf_resolve( $doc, $list[ $i ] );
		if ( ! is_array( $first_node ) || 'num' !== $first_node['type'] ) {
			$i++;
			continue;
		}

		$first = (int) $first_node['value'];
		$next  = isset( $list[ $i + 1 ] ) ? ner_michoel_pdf_resolve( $doc, $list[ $i + 1 ] ) : null;

		if ( is_array( $next ) && 'array' === $next['type'] ) {
			foreach ( $next['value'] as $k => $node ) {
				$out[ $first + $k ] = ner_michoel_pdf_num_or( ner_michoel_pdf_resolve( $doc, $node ), 0 );
			}
			$i += 2;
		} elseif ( is_array( $next ) && 'num' === $next['type'] && isset( $list[ $i + 2 ] ) ) {
			$last = (int) $next['value'];
			$w    = ner_michoel_pdf_num_or( ner_michoel_pdf_resolve( $doc, $list[ $i + 2 ] ), 0 );
			if ( $last >= $first && $last - $first <= 65535 ) {
				for ( $c = $first; $c <= $last; $c++ ) {
					$out[ $c ] = $w;
				}
			}
			$i += 3;
		} else {
			$i++;
		}
	}

	return $out;
}

/**
 * Reads a ToUnicode CMap: the code width (from codespacerange), then the
 * bfchar and bfrange mappings. Keys are upper-case hex, padded to the code
 * width, so they match bin2hex() of the bytes in a string.
 */
function ner_michoel_pdf_parse_cmap( $cmap ) {
	$width = 0;
	if ( preg_match( '/begincodespacerange(.*?)endcodespacerange/s', $cmap, $space ) && preg_match( '/<([0-9A-Fa-f\s]+)>/', $space[1], $hex ) ) {
		$width = (int) ceil( strlen( preg_replace( '/\s+/', '', $hex[1] ) ) / 2 );
	}

	$map = array();

	if ( preg_match_all( '/beginbfchar(.*?)endbfchar/s', $cmap, $sections ) ) {
		foreach ( $sections[1] as $section ) {
			if ( preg_match_all( '/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]*)>/', $section, $pairs, PREG_SET_ORDER ) ) {
				foreach ( $pairs as $pair ) {
					$map[ $pair[1] ] = ner_michoel_pdf_utf16_to_utf8( $pair[2] );
				}
			}
		}
	}

	if ( preg_match_all( '/beginbfrange(.*?)endbfrange/s', $cmap, $sections ) ) {
		foreach ( $sections[1] as $section ) {
			preg_match_all( '/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*(\[[^\]]*\]|<[0-9A-Fa-f]*>)/s', $section, $ranges, PREG_SET_ORDER );
			foreach ( $ranges as $range ) {
				$lo  = hexdec( $range[1] );
				$hi  = hexdec( $range[2] );
				$src = strlen( $range[1] );
				if ( $hi < $lo || $hi - $lo > 65535 ) {
					continue;
				}

				if ( '[' === $range[3][0] ) {
					// One text value per code, in order.
					preg_match_all( '/<([0-9A-Fa-f]*)>/', $range[3], $dst );
					foreach ( $dst[1] as $offset => $dst_hex ) {
						if ( $lo + $offset <= $hi ) {
							$map[ str_pad( dechex( $lo + $offset ), $src, '0', STR_PAD_LEFT ) ] = ner_michoel_pdf_utf16_to_utf8( $dst_hex );
						}
					}
				} else {
					// A start value that counts up by one for each code in the range.
					$dst_hex = substr( $range[3], 1, -1 );
					$dst_len = strlen( $dst_hex );
					if ( 0 === $dst_len ) {
						continue;
					}
					$base = hexdec( $dst_hex );
					for ( $code = $lo; $code <= $hi; $code++ ) {
						$value = str_pad( dechex( $base + $code - $lo ), $dst_len, '0', STR_PAD_LEFT );
						$map[ str_pad( dechex( $code ), $src, '0', STR_PAD_LEFT ) ] = ner_michoel_pdf_utf16_to_utf8( $value );
					}
				}
			}
		}
	}

	$width  = $width ?: 2;
	$normal = array();
	foreach ( $map as $key => $value ) {
		$normal[ strtoupper( str_pad( (string) $key, $width * 2, '0', STR_PAD_LEFT ) ) ] = $value;
	}

	return array(
		'width' => $width,
		'map'   => $normal,
	);
}

/**
 * UTF-16BE hex to UTF-8, with surrogate pairs. Done by hand so it doesn't need
 * the mbstring extension.
 */
function ner_michoel_pdf_utf16_to_utf8( $hex ) {
	if ( '' === $hex ) {
		return '';
	}
	if ( 1 === strlen( $hex ) % 2 ) {
		$hex .= '0';
	}

	$bytes = (string) hex2bin( $hex );
	$len   = strlen( $bytes );
	$out   = '';

	for ( $i = 0; $i + 1 < $len; $i += 2 ) {
		$unit = ( ord( $bytes[ $i ] ) << 8 ) | ord( $bytes[ $i + 1 ] );
		if ( $unit >= 0xD800 && $unit <= 0xDBFF && $i + 3 < $len ) {
			$low = ( ord( $bytes[ $i + 2 ] ) << 8 ) | ord( $bytes[ $i + 3 ] );
			if ( $low >= 0xDC00 && $low <= 0xDFFF ) {
				$unit = 0x10000 + ( ( $unit - 0xD800 ) << 10 ) + ( $low - 0xDC00 );
				$i   += 2;
			}
		}
		$out .= ner_michoel_pdf_utf8_char( $unit );
	}

	return $out;
}

function ner_michoel_pdf_utf8_char( $cp ) {
	if ( $cp < 0x80 ) {
		return chr( $cp );
	}
	if ( $cp < 0x800 ) {
		return chr( 0xC0 | ( $cp >> 6 ) ) . chr( 0x80 | ( $cp & 0x3F ) );
	}
	if ( $cp < 0x10000 ) {
		return chr( 0xE0 | ( $cp >> 12 ) ) . chr( 0x80 | ( ( $cp >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $cp & 0x3F ) );
	}
	return chr( 0xF0 | ( $cp >> 18 ) ) . chr( 0x80 | ( ( $cp >> 12 ) & 0x3F ) ) . chr( 0x80 | ( ( $cp >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $cp & 0x3F ) );
}

/**
 * Text for one glyph code. Empty when the code has no mapping.
 */
function ner_michoel_pdf_glyph_text( $font, $code ) {
	if ( null !== $font['map'] ) {
		$key = strtoupper( bin2hex( $code ) );
		return isset( $font['map'][ $key ] ) ? $font['map'][ $key ] : '';
	}
	if ( $font['latin'] ) {
		$o = ord( $code );
		return ( $o >= 0x20 && $o <= 0x7E ) ? chr( $o ) : '';
	}
	return '';
}

/* ------------------------------------------------------------------
 * Content stream: operators and text positions.
 * ------------------------------------------------------------------ */

/**
 * Splits a content stream into [operator, operands] pairs. Operands are the
 * values read since the previous operator.
 */
function ner_michoel_pdf_content_ops( $s ) {
	$ops  = array();
	$args = array();
	$pos  = 0;
	$len  = strlen( $s );

	while ( $pos < $len ) {
		$node = ner_michoel_pdf_parse( $s, $pos );
		if ( null === $node ) {
			continue;
		}

		if ( 'kw' !== $node['type'] || in_array( $node['value'], array( 'true', 'false', 'null' ), true ) ) {
			$args[] = $node;
			continue;
		}

		if ( 'ID' === $node['value'] ) {
			// Inline image data: skip to EI, so binary bytes aren't read as operators.
			$end  = strpos( $s, 'EI', $pos );
			$pos  = ( false === $end ) ? $len : $end + 2;
			$args = array();
			continue;
		}

		$ops[] = array( $node['value'], $args );
		$args  = array();
	}

	return $ops;
}

function ner_michoel_pdf_arg( $args, $i ) {
	return isset( $args[ $i ] ) ? $args[ $i ] : null;
}

function ner_michoel_pdf_arg_num( $args, $i ) {
	$node = ner_michoel_pdf_arg( $args, $i );
	return ( is_array( $node ) && 'num' === $node['type'] ) ? (float) $node['value'] : 0.0;
}

function ner_michoel_pdf_arg_str( $args, $i ) {
	$node = ner_michoel_pdf_arg( $args, $i );
	return ( is_array( $node ) && 'str' === $node['type'] ) ? $node['value'] : '';
}

function ner_michoel_pdf_arg_name( $args, $i ) {
	$node = ner_michoel_pdf_arg( $args, $i );
	return ( is_array( $node ) && 'name' === $node['type'] ) ? $node['value'] : '';
}

/**
 * Six numbers as a matrix [a b c d e f].
 */
function ner_michoel_pdf_matrix( $args ) {
	$m = array();
	for ( $i = 0; $i < 6; $i++ ) {
		$m[] = ner_michoel_pdf_arg_num( $args, $i );
	}
	return $m;
}

/**
 * Matrix product A x B in PDF's row-vector convention. Applying A and then B.
 */
function ner_michoel_pdf_mul( $a, $b ) {
	return array(
		$a[0] * $b[0] + $a[1] * $b[2],
		$a[0] * $b[1] + $a[1] * $b[3],
		$a[2] * $b[0] + $a[3] * $b[2],
		$a[2] * $b[1] + $a[3] * $b[3],
		$a[4] * $b[0] + $a[5] * $b[2] + $b[4],
		$a[4] * $b[1] + $a[5] * $b[3] + $b[5],
	);
}

/**
 * Records one shown string as a run of text at its start position on the page,
 * then moves the text position past it by the glyphs' widths.
 */
function ner_michoel_pdf_show( &$tm, $ctm, $font, $size, $bytes, &$items ) {
	$step    = $font ? $font['width'] : 1;
	$text    = '';
	$advance = 0.0;

	for ( $i = 0; $i + $step <= strlen( $bytes ); $i += $step ) {
		$code = substr( $bytes, $i, $step );
		if ( $font ) {
			$text    .= ner_michoel_pdf_glyph_text( $font, $code );
			$glyph    = hexdec( bin2hex( $code ) );
			$advance += isset( $font['widths'][ $glyph ] ) ? $font['widths'][ $glyph ] : $font['default'];
		} else {
			$advance += 500;
		}
	}

	$start = ner_michoel_pdf_mul( $tm, $ctm );
	$tm    = ner_michoel_pdf_mul( array( 1, 0, 0, 1, $advance / 1000 * $size, 0 ), $tm );
	$end   = ner_michoel_pdf_mul( $tm, $ctm );

	if ( '' === $text ) {
		return;
	}

	$items[] = array(
		'text' => $text,
		'x'    => $start[4],
		'xend' => $end[4],
		'y'    => $start[5],
		'size' => max( 1.0, abs( $size ) * hypot( $start[0], $start[1] ) ),
	);
}

/**
 * Runs the content stream and returns every run of text, with its position.
 */
function ner_michoel_pdf_run_content( $content, $fonts ) {
	$ident = array( 1, 0, 0, 1, 0, 0 );
	$ctm   = $ident;
	$tm    = $ident;
	$tlm   = $ident;
	$stack = array();

	$font    = null;
	$size    = 0.0;
	$leading = 0.0;
	$items   = array();

	foreach ( ner_michoel_pdf_content_ops( $content ) as $entry ) {
		$op   = $entry[0];
		$args = $entry[1];

		switch ( $op ) {
			case 'BT':
				$tm  = $ident;
				$tlm = $ident;
				break;

			case 'q':
				$stack[] = $ctm;
				break;

			case 'Q':
				if ( $stack ) {
					$ctm = array_pop( $stack );
				}
				break;

			case 'cm':
				$ctm = ner_michoel_pdf_mul( ner_michoel_pdf_matrix( $args ), $ctm );
				break;

			case 'Tf':
				$name = ner_michoel_pdf_arg_name( $args, 0 );
				$font = ( '' !== $name && isset( $fonts[ $name ] ) ) ? $fonts[ $name ] : null;
				$size = ner_michoel_pdf_arg_num( $args, 1 );
				break;

			case 'TL':
				$leading = ner_michoel_pdf_arg_num( $args, 0 );
				break;

			case 'Tm':
				$tlm = ner_michoel_pdf_matrix( $args );
				$tm  = $tlm;
				break;

			case 'Td':
				$tlm = ner_michoel_pdf_mul( array( 1, 0, 0, 1, ner_michoel_pdf_arg_num( $args, 0 ), ner_michoel_pdf_arg_num( $args, 1 ) ), $tlm );
				$tm  = $tlm;
				break;

			case 'TD':
				$leading = -ner_michoel_pdf_arg_num( $args, 1 );
				$tlm     = ner_michoel_pdf_mul( array( 1, 0, 0, 1, ner_michoel_pdf_arg_num( $args, 0 ), ner_michoel_pdf_arg_num( $args, 1 ) ), $tlm );
				$tm      = $tlm;
				break;

			case 'T*':
				$tlm = ner_michoel_pdf_mul( array( 1, 0, 0, 1, 0, -$leading ), $tlm );
				$tm  = $tlm;
				break;

			case 'Tj':
				ner_michoel_pdf_show( $tm, $ctm, $font, $size, ner_michoel_pdf_arg_str( $args, 0 ), $items );
				break;

			case "'":
				$tlm = ner_michoel_pdf_mul( array( 1, 0, 0, 1, 0, -$leading ), $tlm );
				$tm  = $tlm;
				ner_michoel_pdf_show( $tm, $ctm, $font, $size, ner_michoel_pdf_arg_str( $args, 0 ), $items );
				break;

			case '"':
				$tlm = ner_michoel_pdf_mul( array( 1, 0, 0, 1, 0, -$leading ), $tlm );
				$tm  = $tlm;
				ner_michoel_pdf_show( $tm, $ctm, $font, $size, ner_michoel_pdf_arg_str( $args, 2 ), $items );
				break;

			case 'TJ':
				$list = ner_michoel_pdf_arg( $args, 0 );
				if ( ! is_array( $list ) || 'array' !== $list['type'] ) {
					break;
				}
				foreach ( $list['value'] as $part ) {
					if ( ! is_array( $part ) ) {
						continue;
					}
					if ( 'str' === $part['type'] ) {
						ner_michoel_pdf_show( $tm, $ctm, $font, $size, $part['value'], $items );
					} elseif ( 'num' === $part['type'] ) {
						// A number in TJ moves the position: negative is a gap, usually a word space.
						$tm = ner_michoel_pdf_mul( array( 1, 0, 0, 1, -$part['value'] / 1000 * $size, 0 ), $tm );
					}
				}
				break;
		}
	}

	return $items;
}

/**
 * Items of page 1, from its content and fonts.
 */
function ner_michoel_pdf_page_items( &$doc, $page ) {
	$content = ner_michoel_pdf_page_content( $doc, $page['dict'] );
	if ( '' === $content ) {
		return array();
	}

	return ner_michoel_pdf_run_content( $content, ner_michoel_pdf_page_fonts( $doc, $page['resources'] ) );
}

/**
 * The top line of the page, as text. Runs at the same height (within 2 units)
 * are one line. Within a line, runs go left to right, or right to left when the
 * line has Hebrew or Arabic script, as in the browser reader. A gap wider than a
 * fifth of the font size becomes a space.
 */
function ner_michoel_pdf_top_line( $items ) {
	if ( ! $items ) {
		return '';
	}

	usort(
		$items,
		function ( $a, $b ) {
			return $b['y'] <=> $a['y'];
		}
	);

	$top  = $items[0]['y'];
	$line = array();
	foreach ( $items as $item ) {
		if ( abs( $item['y'] - $top ) > 2 ) {
			break;
		}
		$line[] = $item;
	}

	$all_text = '';
	foreach ( $line as $item ) {
		$all_text .= $item['text'];
	}
	$rtl = (bool) preg_match( NER_MICHOEL_PDF_RTL, $all_text );

	usort(
		$line,
		function ( $a, $b ) use ( $rtl ) {
			if ( $a['x'] === $b['x'] ) {
				return 0;
			}
			return ( $rtl ? $a['x'] > $b['x'] : $a['x'] < $b['x'] ) ? -1 : 1;
		}
	);

	$text = '';
	$prev = null;
	foreach ( $line as $item ) {
		if ( null !== $prev ) {
			$prev_left  = min( $prev['x'], $prev['xend'] );
			$prev_right = max( $prev['x'], $prev['xend'] );
			$left       = min( $item['x'], $item['xend'] );
			$right      = max( $item['x'], $item['xend'] );
			$gap        = $rtl ? ( $prev_left - $right ) : ( $left - $prev_right );

			if ( $gap > 0.2 * $item['size'] && ! preg_match( '/\s$/u', $text ) && ! preg_match( '/^\s/u', $item['text'] ) ) {
				$text .= ' ';
			}
		}
		$text .= $item['text'];
		$prev  = $item;
	}

	$text = preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $text );
	$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );

	// A line of one or two letters is a stray mark, not a summary.
	if ( preg_match_all( '/\p{L}/u', $text ) < 2 ) {
		return '';
	}

	return $text;
}
