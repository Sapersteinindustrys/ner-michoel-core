<?php
/**
 * Years list: a spreadsheet (Excel or CSV) of the boys and the years they were
 * in yeshiva. When a Mazal Tov is written for a name that is in the list, the
 * announcement's "Years" box is filled in from it.
 *
 * Three parts, in this order:
 *
 *  1. Plain functions with no WordPress in them (reading an .xlsx / .csv file,
 *     finding the Name and Years columns, matching a typed name against the
 *     list). They are unit-tested in plain PHP, so everything that touches a
 *     WordPress function is kept below them.
 *  2. Storing the list (one option, not loaded on ordinary page views), the
 *     lookup route the Mazal Tov forms call, and the handlers behind the
 *     screen's buttons.
 *  3. The screen: Announcements > Years List (and the same page in wp-admin).
 *
 * Nothing here changes what an announcement stores: "Years" stays a free-text
 * box (_mazal_tov_years) that the person can overwrite, so the theme needs no
 * change and an announcement never changes when the list does.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'NER_MICHOEL_YEARS_OPTION' ) ) {
	define( 'NER_MICHOEL_YEARS_OPTION', 'nm_years_list' );
}
// Limits that keep a bad or hostile file from hurting the server.
if ( ! defined( 'NER_MICHOEL_YEARS_MAX_FILE' ) ) {
	define( 'NER_MICHOEL_YEARS_MAX_FILE', 12 * 1024 * 1024 );     // the uploaded file
}
if ( ! defined( 'NER_MICHOEL_YEARS_MAX_XML' ) ) {
	define( 'NER_MICHOEL_YEARS_MAX_XML', 48 * 1024 * 1024 );      // one unpacked part of an .xlsx
}
if ( ! defined( 'NER_MICHOEL_YEARS_MAX_ROWS' ) ) {
	define( 'NER_MICHOEL_YEARS_MAX_ROWS', 50000 );                // rows read from one sheet
}
if ( ! defined( 'NER_MICHOEL_YEARS_MAX_COLS' ) ) {
	define( 'NER_MICHOEL_YEARS_MAX_COLS', 60 );
}
if ( ! defined( 'NER_MICHOEL_YEARS_MAX_LIST' ) ) {
	define( 'NER_MICHOEL_YEARS_MAX_LIST', 30000 );                // names kept in the list
}

/* ================================================================== */
/* 1. Plain functions (no WordPress)                                    */
/* ================================================================== */

/* ---------------- Names ---------------- */

/**
 * Words that are not part of a name: titles and endings ("Rabbi", "Jr.",
 * "shlita"). Compared after the name has been lower-cased and its quotes removed.
 */
function ner_michoel_years_noise_words() {
	return array(
		'rabbi', 'rav', 'harav', 'reb', 'rebbe', 'mr', 'mrs', 'ms', 'dr', 'hagaon', 'hagon', 'reverend',
		'jr', 'sr', 'shlita', 'shlitah', 'ztl', 'zl', 'zt', 'obm', 'esq',
		"\xD7\x94\xD7\xA8\xD7\x91", "\xD7\xA8", "\xD7\x9E\xD7\xA8", "\xD7\x94\xD7\x92\xD7\x90\xD7\x95\xD7\x9F", // הרב ר מר הגאון
	);
}

/**
 * A text as a list of lower-case words: accents, Hebrew vowel points, quotes
 * and punctuation gone ("Ya'akov" and "Yaakov" agree), titles dropped.
 */
function ner_michoel_years_tokens( $text ) {
	$text = trim( (string) $text );
	if ( '' === $text ) {
		return array();
	}
	$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	if ( class_exists( 'Normalizer' ) ) {
		$decomposed = Normalizer::normalize( $text, Normalizer::FORM_D );
		if ( is_string( $decomposed ) ) {
			$text = $decomposed;
		}
	}
	$text = @preg_replace( '/\p{Mn}+/u', '', $text );
	if ( ! is_string( $text ) ) {
		return array();
	}
	// "R' Moshe": the R' is a title. Checked before quotes are removed, so a name that merely ends in r is safe.
	$text = preg_replace( '/(?<![\p{L}\p{N}])r[\'\x{2019}\x{2018}](?=\s)/u', ' ', $text );
	$text  = preg_replace( '/[\'"`\x{00B4}\x{2018}\x{2019}\x{201B}\x{02BC}\x{201C}\x{201D}\x{05F3}\x{05F4}]+/u', '', $text );
	$text  = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text );
	$parts = preg_split( '/\s+/', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
	if ( ! $parts ) {
		return array();
	}
	return array_values( array_diff( $parts, ner_michoel_years_noise_words() ) );
}

/**
 * The words of a name in reading order. "Cohen, Moshe" (one comma, a word on
 * each side) reads as "Moshe Cohen".
 */
function ner_michoel_years_name_tokens( $name ) {
	$name = (string) $name;
	if ( 1 === substr_count( $name, ',' ) ) {
		$sides = explode( ',', $name );
		$left  = ner_michoel_years_tokens( $sides[0] );
		$right = ner_michoel_years_tokens( $sides[1] );
		if ( $left && $right ) {
			return array_merge( $right, $left );
		}
	}
	return ner_michoel_years_tokens( $name );
}

/** Spaces tidied, control characters gone, no longer than $max characters. */
function ner_michoel_years_clean( $text, $max = 120 ) {
	$text = (string) $text;
	if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $text, 'UTF-8' ) ) {
		$text = function_exists( 'mb_convert_encoding' ) ? mb_convert_encoding( $text, 'UTF-8', 'Windows-1252' ) : '';
	}
	$text = preg_replace( '/[\x00-\x08\x0B-\x1F\x7F]+/', ' ', $text );
	$text = preg_replace( '/\s+/u', ' ', (string) $text );
	$text = trim( (string) $text );
	if ( function_exists( 'mb_substr' ) && mb_strlen( $text, 'UTF-8' ) > $max ) {
		$text = trim( mb_substr( $text, 0, $max, 'UTF-8' ) );
	}
	return $text;
}

/**
 * What goes in the Years box for a cell: as typed in the sheet, except that a
 * date (a real Excel date shows up as 2005-09-01) becomes its year.
 */
function ner_michoel_years_clean_years( $text ) {
	$text = ner_michoel_years_clean( $text, 80 );
	if ( preg_match( '/^(\d{4})-\d{2}-\d{2}(?:[ T].*)?$/', $text, $m ) ) {
		return $m[1];
	}
	return $text;
}

/**
 * One stored row for a name and its years, or null when either is empty.
 * n = name as written, y = years as written, o = the name's words in order,
 * k = the same words sorted (so "Cohen Moshe" and "Moshe Cohen" agree), c = how many.
 */
function ner_michoel_years_make_row( $name, $years ) {
	$name   = ner_michoel_years_clean( $name, 120 );
	$years  = ner_michoel_years_clean_years( $years );
	$tokens = ner_michoel_years_name_tokens( $name );
	if ( '' === $years || ! $tokens ) {
		return null;
	}
	$sorted = $tokens;
	sort( $sorted, SORT_STRING );
	return array(
		'n' => $name,
		'y' => $years,
		'o' => implode( ' ', $tokens ),
		'k' => implode( ' ', $sorted ),
		'c' => count( $tokens ),
	);
}

/* ---------------- Matching ---------------- */

/**
 * Looks the typed texts up in the list. $queries are what was typed: a name, or
 * a whole headline such as "Mazel Tov to Moshe Cohen on his engagement".
 *
 * 'strong' = the name is the whole text (in either order), or a boy's whole name
 * (two words or more) appears word for word inside it.
 * 'maybe'  = the text is part of a longer name ("Moshe Cohen" for "Moshe Yitzchok
 * Cohen") or one letter off. Only looked for when nothing is strong.
 * Each result is array( name, years ), best first.
 */
function ner_michoel_years_find( array $rows, array $queries ) {
	$strong  = array();
	$parsed  = array();
	foreach ( $queries as $query ) {
		$tokens = ner_michoel_years_name_tokens( (string) $query );
		$count  = count( $tokens );
		if ( ! $count || $count > 80 ) {
			continue;
		}
		$sorted = $tokens;
		sort( $sorted, SORT_STRING );
		$parsed[] = array(
			'tokens' => $tokens,
			'count'  => $count,
			'key'    => implode( ' ', $sorted ),
			'padded' => ' ' . implode( ' ', $tokens ) . ' ',
		);
	}
	if ( ! $parsed ) {
		return array( 'strong' => array(), 'maybe' => array() );
	}

	foreach ( $parsed as $q ) {
		foreach ( $rows as $i => $row ) {
			if ( isset( $strong[ $i ] ) && 0 === $strong[ $i ] ) {
				continue;
			}
			if ( $row['k'] === $q['key'] ) {
				$strong[ $i ] = 0;
				continue;
			}
			$row_count = (int) $row['c'];
			if ( $row_count >= 2 && $row_count < $q['count'] && ! isset( $strong[ $i ] ) ) {
				if ( false !== strpos( $q['padded'], ' ' . $row['o'] . ' ' ) ) {
					$strong[ $i ] = 1;
				} elseif ( false !== strpos( $q['padded'], ' ' . implode( ' ', array_reverse( explode( ' ', $row['o'] ) ) ) . ' ' ) ) {
					$strong[ $i ] = 2;
				}
			}
		}
	}

	$maybe = array();
	if ( ! $strong ) {
		foreach ( $parsed as $q ) {
			if ( $q['count'] < 2 ) {
				continue;
			}
			$key_len = strlen( $q['key'] );
			foreach ( $rows as $i => $row ) {
				$row_count = (int) $row['c'];
				if ( $row_count > $q['count'] && ! array_diff( $q['tokens'], explode( ' ', $row['o'] ) ) ) {
					$score = 1 + $row_count - $q['count'];
				} elseif ( $key_len >= 6 && $key_len <= 60 && abs( strlen( $row['k'] ) - $key_len ) <= 2 ) {
					$distance = levenshtein( $q['key'], $row['k'] );
					if ( $distance < 1 || $distance > ( $key_len >= 14 ? 2 : 1 ) ) {
						continue;
					}
					$score = 10 + $distance;
				} else {
					continue;
				}
				if ( ! isset( $maybe[ $i ] ) || $score < $maybe[ $i ] ) {
					$maybe[ $i ] = $score;
				}
			}
		}
	}

	$pick = function ( array $ranked, $limit ) use ( $rows ) {
		asort( $ranked );
		$out = array();
		foreach ( array_keys( $ranked ) as $i ) {
			$out[] = array( 'name' => $rows[ $i ]['n'], 'years' => $rows[ $i ]['y'], 'rank' => $ranked[ $i ] );
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	};
	return array( 'strong' => $pick( $strong, 12 ), 'maybe' => $pick( $maybe, 8 ) );
}

/* ---------------- Reading a file: .xlsx without ZipArchive ---------------- */

/**
 * The parts of a zip file, from its central directory. null when it is not a
 * (plain, non-zip64) zip. Each entry: method, crc, csize, size, loff, flags.
 */
function ner_michoel_years_zip_entries( $fh, $size ) {
	if ( $size < 22 ) {
		return null;
	}
	$tail = min( $size, 65557 );
	fseek( $fh, $size - $tail );
	$buf = (string) fread( $fh, $tail );
	$pos = strrpos( $buf, "PK\x05\x06" );
	if ( false === $pos || strlen( $buf ) < $pos + 22 ) {
		return null;
	}
	$end = unpack( 'vdisk/vcdisk/ventriesdisk/ventries/Vcdsize/Vcdoffset/vcomment', substr( $buf, $pos + 4, 18 ) );
	if ( 0xFFFFFFFF === $end['cdoffset'] || 0xFFFF === $end['entries'] || $end['cdsize'] > 8 * 1024 * 1024 || $end['cdoffset'] + $end['cdsize'] > $size ) {
		return null;
	}
	fseek( $fh, $end['cdoffset'] );
	$cd      = (string) fread( $fh, $end['cdsize'] );
	$entries = array();
	$p       = 0;
	$len     = strlen( $cd );
	for ( $n = 0; $n < $end['entries'] && $p + 46 <= $len; $n++ ) {
		if ( "PK\x01\x02" !== substr( $cd, $p, 4 ) ) {
			return null;
		}
		$h    = unpack( 'vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vsize/vnlen/velen/vclen/vdisk/viattr/Veattr/Vloff', substr( $cd, $p + 4, 42 ) );
		$name = str_replace( '\\', '/', substr( $cd, $p + 46, $h['nlen'] ) );
		$entries[ $name ] = array(
			'method' => $h['method'],
			'crc'    => $h['crc'],
			'csize'  => $h['csize'],
			'size'   => $h['size'],
			'loff'   => $h['loff'],
			'flags'  => $h['flags'],
		);
		$p += 46 + $h['nlen'] + $h['elen'] + $h['clen'];
	}
	return $entries;
}

/** One part of a zip, unpacked and checked, or array( 'error' => ... ). */
function ner_michoel_years_zip_read( $fh, array $entry, $limit ) {
	if ( $entry['flags'] & 1 ) {
		return array( 'error' => 'The file is password-protected. Save a copy without the password and upload that.' );
	}
	if ( $entry['size'] > $limit ) {
		return array( 'error' => 'The file is too big to read.' );
	}
	fseek( $fh, $entry['loff'] );
	$head = (string) fread( $fh, 30 );
	if ( 30 !== strlen( $head ) || "PK\x03\x04" !== substr( $head, 0, 4 ) ) {
		return array( 'error' => 'The file is damaged (a part could not be found).' );
	}
	$lens = unpack( 'vnlen/velen', substr( $head, 26, 4 ) );
	fseek( $fh, $entry['loff'] + 30 + $lens['nlen'] + $lens['elen'] );
	$data = '';
	$left = $entry['csize'];
	while ( $left > 0 ) {
		$piece = fread( $fh, min( 1048576, $left ) );
		if ( false === $piece || '' === $piece ) {
			break;
		}
		$data .= $piece;
		$left -= strlen( $piece );
	}
	if ( strlen( $data ) !== $entry['csize'] ) {
		return array( 'error' => 'The file is damaged (it ends too soon).' );
	}
	if ( 0 === $entry['method'] ) {
		$out = $data;
	} elseif ( 8 === $entry['method'] ) {
		$out = @gzinflate( $data, $limit + 1 );
	} else {
		return array( 'error' => 'The file uses a kind of packing this site cannot read.' );
	}
	if ( ! is_string( $out ) || strlen( $out ) !== $entry['size'] || ( crc32( $out ) & 0xFFFFFFFF ) !== $entry['crc'] ) {
		return array( 'error' => 'The file is damaged (a part did not unpack correctly).' );
	}
	return array( 'data' => $out );
}

/** An XMLReader over a string, or null (no XML support, or the text asks for entities). */
function ner_michoel_years_xml_reader( $xml ) {
	if ( ! class_exists( 'XMLReader' ) || false !== stripos( $xml, '<!ENTITY' ) || false !== stripos( $xml, '<!DOCTYPE' ) ) {
		return null;
	}
	$reader = new XMLReader();
	if ( ! @$reader->XML( $xml, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT ) ) {
		return null;
	}
	return $reader;
}

/** A1 -> 0, B7 -> 1, AA3 -> 26. */
function ner_michoel_years_col_index( $ref ) {
	if ( ! preg_match( '/^([A-Za-z]+)/', (string) $ref, $m ) ) {
		return -1;
	}
	$n = 0;
	foreach ( str_split( strtoupper( $m[1] ) ) as $ch ) {
		$n = $n * 26 + ( ord( $ch ) - 64 );
	}
	return $n - 1;
}

/** The sheet's shared strings, in order (phonetic guides left out). */
function ner_michoel_years_xlsx_shared_strings( $xml ) {
	$out    = array();
	$reader = ner_michoel_years_xml_reader( $xml );
	if ( ! $reader ) {
		return $out;
	}
	$current = null;
	$in_ph   = false;
	while ( @$reader->read() ) {
		if ( XMLReader::ELEMENT === $reader->nodeType ) {
			$name = $reader->localName;
			if ( 'si' === $name ) {
				if ( $reader->isEmptyElement ) {
					$out[] = '';
				} else {
					$current = '';
				}
			} elseif ( 'rPh' === $name && ! $reader->isEmptyElement ) {
				$in_ph = true;
			} elseif ( 't' === $name && null !== $current && ! $in_ph ) {
				$current .= $reader->readString();
			}
		} elseif ( XMLReader::END_ELEMENT === $reader->nodeType ) {
			if ( 'si' === $reader->localName && null !== $current ) {
				$out[]   = $current;
				$current = null;
			} elseif ( 'rPh' === $reader->localName ) {
				$in_ph = false;
			}
		}
	}
	$reader->close();
	return $out;
}

/** Excel's built-in date number formats, and a custom one that has y, m, d, h or s in it. */
function ner_michoel_years_is_date_format( $id, array $custom ) {
	$id = (int) $id;
	if ( ( $id >= 14 && $id <= 22 ) || ( $id >= 27 && $id <= 36 ) || ( $id >= 45 && $id <= 47 ) || ( $id >= 50 && $id <= 58 ) ) {
		return true;
	}
	if ( isset( $custom[ $id ] ) ) {
		$code = preg_replace( '/"[^"]*"|\\\\.|\[[^\]]*\]/', '', $custom[ $id ] );
		return (bool) preg_match( '/[ymdhs]/i', (string) $code );
	}
	return false;
}

/** Which cell styles (by position) show a date. */
function ner_michoel_years_xlsx_date_styles( $xml ) {
	$styles = array();
	$reader = ner_michoel_years_xml_reader( $xml );
	if ( ! $reader ) {
		return $styles;
	}
	$custom  = array();
	$in_xfs  = false;
	$formats = array();
	while ( @$reader->read() ) {
		if ( XMLReader::ELEMENT === $reader->nodeType ) {
			$name = $reader->localName;
			if ( 'numFmt' === $name ) {
				$custom[ (int) $reader->getAttribute( 'numFmtId' ) ] = (string) $reader->getAttribute( 'formatCode' );
			} elseif ( 'cellXfs' === $name && ! $reader->isEmptyElement ) {
				$in_xfs = true;
			} elseif ( 'xf' === $name && $in_xfs ) {
				$formats[] = (int) $reader->getAttribute( 'numFmtId' );
			}
		} elseif ( XMLReader::END_ELEMENT === $reader->nodeType && 'cellXfs' === $reader->localName ) {
			$in_xfs = false;
		}
	}
	$reader->close();
	foreach ( $formats as $index => $id ) {
		$styles[ $index ] = ner_michoel_years_is_date_format( $id, $custom );
	}
	return $styles;
}

/** An Excel date number as 2005-09-01. */
function ner_michoel_years_serial_to_date( $serial, $is1904 ) {
	$days = (float) $serial - ( $is1904 ? 24107 : 25569 );
	return gmdate( 'Y-m-d', (int) round( $days * 86400 ) );
}

/** A number from a cell as text: 2005, not 2005.0 or 2.005E3. */
function ner_michoel_years_number_text( $value ) {
	$value = trim( (string) $value );
	if ( '' === $value || ! is_numeric( $value ) ) {
		return $value;
	}
	$float = (float) $value;
	if ( floor( $float ) === $float && abs( $float ) < 1e15 ) {
		return (string) (int) $float;
	}
	return rtrim( rtrim( sprintf( '%.10F', $float ), '0' ), '.' );
}

/**
 * The rows of one sheet as lists of strings, empty cells as ''. Stops at the
 * row limit (and says so).
 */
function ner_michoel_years_xlsx_sheet_rows( $xml, array $shared, array $date_styles, $is1904 ) {
	$out = array( 'rows' => array(), 'truncated' => false );
	$reader = ner_michoel_years_xml_reader( $xml );
	if ( ! $reader ) {
		return $out;
	}
	$row    = null;
	$cell   = null;
	$seq    = 0;
	$max_c  = NER_MICHOEL_YEARS_MAX_COLS;
	while ( @$reader->read() ) {
		$type = $reader->nodeType;
		$name = $reader->localName;
		if ( XMLReader::ELEMENT === $type ) {
			if ( 'row' === $name ) {
				$row = array();
				$seq = 0;
				if ( $reader->isEmptyElement ) {
					$row = null;
				}
			} elseif ( 'c' === $name && null !== $row ) {
				$ref = (string) $reader->getAttribute( 'r' );
				$col = '' !== $ref ? ner_michoel_years_col_index( $ref ) : $seq;
				if ( $col < 0 ) {
					$col = $seq;
				}
				$seq  = $col + 1;
				$cell = array( 'col' => $col, 't' => (string) $reader->getAttribute( 't' ), 's' => (int) $reader->getAttribute( 's' ), 'v' => null );
				if ( $reader->isEmptyElement ) {
					$cell = null;
				}
			} elseif ( 'v' === $name && null !== $cell ) {
				$cell['v'] = $reader->readString();
			} elseif ( 'is' === $name && null !== $cell && ! $reader->isEmptyElement ) {
				$inner = preg_replace( '#<(\w+:)?rPh\b.*?</(\w+:)?rPh>#s', '', $reader->readInnerXml() );
				$cell['v'] = html_entity_decode( strip_tags( (string) $inner ), ENT_QUOTES | ENT_XML1, 'UTF-8' );
			}
		} elseif ( XMLReader::END_ELEMENT === $type ) {
			if ( 'c' === $name && null !== $cell && null !== $row ) {
				$value = (string) $cell['v'];
				switch ( $cell['t'] ) {
					case 's':
						$value = isset( $shared[ (int) $value ] ) ? $shared[ (int) $value ] : '';
						break;
					case 'str':
					case 'inlineStr':
					case 'd':
						break;
					case 'b':
						$value = '1' === $value ? 'TRUE' : 'FALSE';
						break;
					case 'e':
						$value = '';
						break;
					default:
						if ( '' !== $value && is_numeric( $value ) && ! empty( $date_styles[ $cell['s'] ] ) ) {
							$value = ner_michoel_years_serial_to_date( $value, $is1904 );
						} else {
							$value = ner_michoel_years_number_text( $value );
						}
				}
				if ( $cell['col'] < $max_c && '' !== trim( $value ) ) {
					$row[ $cell['col'] ] = $value;
				}
				$cell = null;
			} elseif ( 'row' === $name && null !== $row ) {
				if ( $row ) {
					$width = max( array_keys( $row ) ) + 1;
					$dense = array_fill( 0, $width, '' );
					foreach ( $row as $col => $value ) {
						$dense[ $col ] = $value;
					}
					$out['rows'][] = $dense;
					if ( count( $out['rows'] ) >= NER_MICHOEL_YEARS_MAX_ROWS ) {
						$out['truncated'] = true;
						break;
					}
				}
				$row = null;
			}
		}
	}
	$reader->close();
	return $out;
}

/** "xl/worksheets/../x.xml"-style paths made plain. */
function ner_michoel_years_zip_path( $base, $target ) {
	$target = str_replace( '\\', '/', (string) $target );
	$full   = ( 0 === strpos( $target, '/' ) ) ? ltrim( $target, '/' ) : $base . $target;
	$parts  = array();
	foreach ( explode( '/', $full ) as $part ) {
		if ( '..' === $part ) {
			array_pop( $parts );
		} elseif ( '' !== $part && '.' !== $part ) {
			$parts[] = $part;
		}
	}
	return implode( '/', $parts );
}

/**
 * An .xlsx file as array( 'sheets' => [ [name, rows, truncated] ] ), or
 * array( 'error' => message ).
 */
function ner_michoel_years_read_xlsx( $path ) {
	if ( ! class_exists( 'XMLReader' ) ) {
		return array( 'error' => 'This site cannot read Excel files (its XML support is switched off). Save the sheet as a CSV file from Excel and upload that instead.' );
	}
	$size = (int) @filesize( $path );
	$fh   = @fopen( $path, 'rb' );
	if ( ! $fh ) {
		return array( 'error' => 'The file could not be opened.' );
	}
	$result = ner_michoel_years_read_xlsx_handle( $fh, $size );
	fclose( $fh );
	return $result;
}

function ner_michoel_years_read_xlsx_handle( $fh, $size ) {
	$entries = ner_michoel_years_zip_entries( $fh, $size );
	if ( null === $entries || ! isset( $entries['xl/workbook.xml'] ) ) {
		return array( 'error' => 'This does not look like an Excel (.xlsx) file. Open it in Excel and use Save As > Excel Workbook (.xlsx), or save it as CSV.' );
	}
	$limit = NER_MICHOEL_YEARS_MAX_XML;
	$read  = function ( $name ) use ( $fh, $entries, $limit ) {
		if ( ! isset( $entries[ $name ] ) ) {
			return array( 'data' => '' );
		}
		return ner_michoel_years_zip_read( $fh, $entries[ $name ], $limit );
	};

	$book = $read( 'xl/workbook.xml' );
	if ( isset( $book['error'] ) ) {
		return $book;
	}
	$is1904 = false;
	$sheets = array();
	$reader = ner_michoel_years_xml_reader( $book['data'] );
	if ( ! $reader ) {
		return array( 'error' => 'The Excel file could not be read (its list of sheets is damaged).' );
	}
	$rel_ns = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
	while ( @$reader->read() ) {
		if ( XMLReader::ELEMENT !== $reader->nodeType ) {
			continue;
		}
		if ( 'workbookPr' === $reader->localName ) {
			$flag   = strtolower( (string) $reader->getAttribute( 'date1904' ) );
			$is1904 = ( '1' === $flag || 'true' === $flag );
		} elseif ( 'sheet' === $reader->localName ) {
			$rid = $reader->getAttributeNs( 'id', $rel_ns );
			if ( null === $rid || '' === $rid ) {
				$rid = (string) $reader->getAttribute( 'r:id' );
			}
			$state    = (string) $reader->getAttribute( 'state' );
			$sheets[] = array(
				'name'   => (string) $reader->getAttribute( 'name' ),
				'rid'    => (string) $rid,
				'hidden' => ( 'hidden' === $state || 'veryHidden' === $state ),
			);
		}
	}
	$reader->close();

	$targets = array();
	$rels    = $read( 'xl/_rels/workbook.xml.rels' );
	if ( isset( $rels['data'] ) && '' !== $rels['data'] ) {
		$reader = ner_michoel_years_xml_reader( $rels['data'] );
		while ( $reader && @$reader->read() ) {
			if ( XMLReader::ELEMENT === $reader->nodeType && 'Relationship' === $reader->localName ) {
				$targets[ (string) $reader->getAttribute( 'Id' ) ] = ner_michoel_years_zip_path( 'xl/', (string) $reader->getAttribute( 'Target' ) );
			}
		}
		if ( $reader ) {
			$reader->close();
		}
	}

	$strings = $read( 'xl/sharedStrings.xml' );
	$shared  = ( isset( $strings['data'] ) && '' !== $strings['data'] ) ? ner_michoel_years_xlsx_shared_strings( $strings['data'] ) : array();
	$style_x = $read( 'xl/styles.xml' );
	$dates   = ( isset( $style_x['data'] ) && '' !== $style_x['data'] ) ? ner_michoel_years_xlsx_date_styles( $style_x['data'] ) : array();

	$out = array( 'sheets' => array() );
	foreach ( $sheets as $n => $sheet ) {
		if ( $sheet['hidden'] ) {
			continue;
		}
		$part = isset( $targets[ $sheet['rid'] ] ) ? $targets[ $sheet['rid'] ] : 'xl/worksheets/sheet' . ( $n + 1 ) . '.xml';
		if ( ! isset( $entries[ $part ] ) ) {
			continue;
		}
		$xml = $read( $part );
		if ( isset( $xml['error'] ) ) {
			return $xml;
		}
		$parsed = ner_michoel_years_xlsx_sheet_rows( $xml['data'], $shared, $dates, $is1904 );
		if ( $parsed['rows'] ) {
			$out['sheets'][] = array( 'name' => $sheet['name'], 'rows' => $parsed['rows'], 'truncated' => $parsed['truncated'] );
		}
	}
	if ( ! $out['sheets'] ) {
		return array( 'error' => 'There is nothing in this Excel file: no sheet has any rows with text in them.' );
	}
	return $out;
}

/* ---------------- Reading a file: CSV ---------------- */

/** Text from a CSV file as UTF-8: handles a byte-order mark, UTF-16 and Windows-1252 / 1255 (Excel's plain "CSV"). */
function ner_michoel_years_csv_text( $raw ) {
	if ( 0 === strpos( $raw, "\xEF\xBB\xBF" ) ) {
		return substr( $raw, 3 );
	}
	if ( 0 === strpos( $raw, "\xFF\xFE" ) || 0 === strpos( $raw, "\xFE\xFF" ) ) {
		$text = function_exists( 'mb_convert_encoding' ) ? mb_convert_encoding( $raw, 'UTF-8', 'UTF-16' ) : '';
		return ( 0 === strpos( $text, "\xEF\xBB\xBF" ) ) ? substr( $text, 3 ) : $text;
	}
	if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $raw, 'UTF-8' ) ) {
		// Excel's plain "CSV" is in the computer's old code page: Hebrew Windows writes Windows-1255, others Windows-1252.
		// Hebrew words are runs of letters in the E0-FA range; accented Latin letters stand alone.
		if ( function_exists( 'iconv' ) && preg_match_all( '/[\xE0-\xFA]{3,}/', $raw ) >= 3 ) {
			$hebrew = @iconv( 'CP1255', 'UTF-8//IGNORE', $raw );
			if ( is_string( $hebrew ) && '' !== $hebrew ) {
				return $hebrew;
			}
		}
		if ( function_exists( 'mb_convert_encoding' ) ) {
			return mb_convert_encoding( $raw, 'UTF-8', 'Windows-1252' );
		}
	}
	return $raw;
}

/** A CSV / tab-separated text file as array( 'sheets' => [ [name, rows, truncated] ] ). */
function ner_michoel_years_read_csv( $path ) {
	$raw = @file_get_contents( $path, false, null, 0, NER_MICHOEL_YEARS_MAX_FILE + 1 );
	if ( false === $raw || '' === trim( $raw ) ) {
		return array( 'error' => 'The file is empty.' );
	}
	$text = ner_michoel_years_csv_text( $raw );
	// The separator: whichever of comma, semicolon and tab is most common in the first lines.
	$head  = implode( "\n", array_slice( preg_split( '/\r\n|\r|\n/', $text ), 0, 8 ) );
	$best  = ',';
	$most  = -1;
	foreach ( array( ',', ';', "\t" ) as $sep ) {
		$n = substr_count( $head, $sep );
		if ( $n > $most ) {
			$most = $n;
			$best = $sep;
		}
	}
	$h = fopen( 'php://temp', 'r+' );
	fwrite( $h, $text );
	rewind( $h );
	$rows      = array();
	$truncated = false;
	while ( false !== ( $line = fgetcsv( $h, 0, $best, '"', '' ) ) ) {
		if ( null === $line ) {
			continue;
		}
		$line = array_slice( array_map( 'strval', $line ), 0, NER_MICHOEL_YEARS_MAX_COLS );
		while ( $line && '' === trim( end( $line ) ) ) {
			array_pop( $line );
		}
		if ( $line ) {
			$rows[] = $line;
		}
		if ( count( $rows ) >= NER_MICHOEL_YEARS_MAX_ROWS ) {
			$truncated = true;
			break;
		}
	}
	fclose( $h );
	if ( ! $rows ) {
		return array( 'error' => 'The file is empty.' );
	}
	return array( 'sheets' => array( array( 'name' => '', 'rows' => $rows, 'truncated' => $truncated ) ) );
}

/** Any supported file, told apart by what is inside it, not by its name. */
function ner_michoel_years_read_file( $path ) {
	$size = (int) @filesize( $path );
	if ( $size <= 0 ) {
		return array( 'error' => 'The file is empty.' );
	}
	if ( $size > NER_MICHOEL_YEARS_MAX_FILE ) {
		return array( 'error' => 'The file is too big (over 12 MB). A list of names should be far smaller: remove other sheets or pictures and try again.' );
	}
	$fh   = @fopen( $path, 'rb' );
	$head = $fh ? (string) fread( $fh, 8 ) : '';
	if ( $fh ) {
		fclose( $fh );
	}
	if ( "\xD0\xCF\x11\xE0" === substr( $head, 0, 4 ) ) {
		return array( 'error' => 'This is an old Excel file (.xls) and this site can only read the newer .xlsx kind. In Excel choose File > Save As > Excel Workbook (.xlsx) (or CSV), then upload that.' );
	}
	if ( 'PK' === substr( $head, 0, 2 ) ) {
		return ner_michoel_years_read_xlsx( $path );
	}
	if ( '%PDF' === substr( $head, 0, 4 ) || "\x89PNG" === substr( $head, 0, 4 ) || "\xFF\xD8" === substr( $head, 0, 2 ) ) {
		return array( 'error' => 'That is not a spreadsheet. Upload an Excel (.xlsx) or CSV file.' );
	}
	return ner_michoel_years_read_csv( $path );
}

/* ---------------- Finding the columns ---------------- */

/** What a column heading says it holds: name, first, last, years, from, until, or '' (not ours). */
function ner_michoel_years_header_kind( $text ) {
	$t = function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( (string) $text ), 'UTF-8' ) : strtolower( trim( (string) $text ) );
	$t = trim( (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $t ) );
	if ( '' === $t ) {
		return '';
	}
	if ( preg_match( '/father|mother|parent|wife|husband|spouse|phone|e mail|email|address|city|state|zip|birth|dob|born|rebbi|rebbe|contact|cell|mobile|\bab\b|\bem\b|אבא|אמא|טלפון|כתובת|לידה/u', $t ) ) {
		return '';
	}
	if ( preg_match( '/\b(last|final) (year|yr)|\b(until|till|through|thru|ended|end|ending|left|leaving|graduat\w*|finish\w*|departed|departure|to)\b|^עד|סיום/u', $t ) ) {
		return 'until';
	}
	if ( preg_match( '/\b(from|start\w*|began|begin\w*|entered|entry|joined|arrival|arrived|first year)\b|התחלה/u', $t ) ) {
		return 'from';
	}
	if ( preg_match( '/\b(years?|yrs?|class|attended|attendance|dates?|period|time|grade|shana|shanim)\b|שנה|שנים|שנת|מחזור|תקופה|כיתה/u', $t ) ) {
		return 'years';
	}
	if ( preg_match( '/\b(last|family|surname|lastname)\b|משפחה/u', $t ) ) {
		return 'last';
	}
	if ( preg_match( '/\b(first|given|private|firstname)\b|פרטי/u', $t ) ) {
		return 'first';
	}
	if ( preg_match( '/\b(names?|students?|talmid|talmidim|bochur|bochurim|boys?|alumnus|alumni|fullname)\b|^שם$|שם|תלמיד/u', $t ) ) {
		return 'name';
	}
	return '';
}

/** Does a cell look like a year or a run of years? ("2005", "2005-2008", "'05, '08", 5765) */
function ner_michoel_years_looks_like_years( $text ) {
	return (bool) preg_match( '/(?<!\d)(?:19|20)\d{2}(?!\d)|[\'\x{2019}\x{2018}]\d{2}(?!\d)|(?<!\d)5[7-9]\d{2}(?!\d)/u', (string) $text );
}

/** Does a cell look like a person's name (letters, no long numbers)? */
function ner_michoel_years_looks_like_name( $text ) {
	return (bool) preg_match( '/\p{L}{2}/u', (string) $text ) && ! preg_match( '/\d{3}/', (string) $text );
}

/**
 * Which columns hold what. Looks for a heading row among the first rows; where a
 * heading is missing or unclear it looks at what the columns contain.
 * Returns header (row number from 0, -1 for none), name, last, years, until
 * (column numbers from 0, -1 for none) and width (number of columns).
 */
function ner_michoel_years_detect( array $rows ) {
	$map   = array( 'header' => -1, 'name' => -1, 'last' => -1, 'years' => -1, 'until' => -1, 'width' => 0 );
	$width = 0;
	foreach ( array_slice( $rows, 0, 60 ) as $row ) {
		$width = max( $width, count( $row ) );
	}
	$map['width'] = $width;

	// The heading row: the first of the top rows with two different kinds of heading in it; failing that, one with a single kind.
	$kinds = array();
	for ( $i = 0, $max = min( count( $rows ), 12 ); $i < $max; $i++ ) {
		$found    = array();
		$nonempty = 0;
		foreach ( $rows[ $i ] as $c => $cell ) {
			if ( '' === trim( (string) $cell ) ) {
				continue;
			}
			$nonempty++;
			$kind = ner_michoel_years_header_kind( $cell );
			if ( '' !== $kind ) {
				$found[ $c ] = $kind;
			}
		}
		if ( $nonempty < 2 || ! $found ) {
			continue;
		}
		$distinct = count( array_unique( $found ) );
		if ( $distinct >= 2 ) {
			$map['header'] = $i;
			$kinds         = $found;
			break;
		}
		if ( $map['header'] < 0 ) {
			$map['header'] = $i;
			$kinds         = $found;
		}
	}

	if ( $kinds ) {
		$by = array();
		foreach ( $kinds as $c => $kind ) {
			if ( ! isset( $by[ $kind ] ) ) {
				$by[ $kind ] = $c;
			}
		}
		if ( isset( $by['first'], $by['last'] ) ) {
			$map['name'] = $by['first'];
			$map['last'] = $by['last'];
		} elseif ( isset( $by['name'] ) ) {
			$map['name'] = $by['name'];
		} elseif ( isset( $by['first'] ) ) {
			$map['name'] = $by['first'];
		} elseif ( isset( $by['last'] ) ) {
			$map['name'] = $by['last'];
		}
		if ( isset( $by['years'] ) ) {
			$map['years'] = $by['years'];
		} elseif ( isset( $by['from'] ) ) {
			$map['years'] = $by['from'];
			if ( isset( $by['until'] ) ) {
				$map['until'] = $by['until'];
			}
		} elseif ( isset( $by['until'] ) ) {
			$map['years'] = $by['until'];
		}
	}

	if ( $map['name'] < 0 || $map['years'] < 0 ) {
		$guess = ner_michoel_years_guess_columns( $rows, $map['header'] + 1, array_filter( array( $map['name'], $map['last'], $map['years'], $map['until'] ), function ( $c ) {
			return $c >= 0;
		} ) );
		if ( $map['name'] < 0 && $guess['name'] >= 0 ) {
			$map['name'] = $guess['name'];
			if ( $map['last'] < 0 ) {
				$map['last'] = $guess['last'];
			}
		}
		if ( $map['years'] < 0 && $guess['years'] >= 0 ) {
			$map['years'] = $guess['years'];
		}
	}
	return $map;
}

/** Column guesses from what is in the columns (no heading to go by). */
function ner_michoel_years_guess_columns( array $rows, $start, array $taken ) {
	$guess  = array( 'name' => -1, 'last' => -1, 'years' => -1 );
	$sample = array_slice( $rows, max( 0, $start ), 60 );
	$stats  = array();
	foreach ( $sample as $row ) {
		foreach ( $row as $c => $cell ) {
			$cell = trim( (string) $cell );
			if ( '' === $cell ) {
				continue;
			}
			if ( ! isset( $stats[ $c ] ) ) {
				$stats[ $c ] = array( 'n' => 0, 'name' => 0, 'years' => 0, 'oneword' => 0 );
			}
			$stats[ $c ]['n']++;
			if ( ner_michoel_years_looks_like_name( $cell ) ) {
				$stats[ $c ]['name']++;
				if ( false === strpos( $cell, ' ' ) ) {
					$stats[ $c ]['oneword']++;
				}
			}
			if ( ner_michoel_years_looks_like_years( $cell ) ) {
				$stats[ $c ]['years']++;
			}
		}
	}
	ksort( $stats );
	foreach ( $stats as $c => $s ) {
		if ( in_array( $c, $taken, true ) ) {
			continue;
		}
		if ( $guess['name'] < 0 && $s['n'] >= 1 && $s['name'] / $s['n'] >= 0.6 && $s['years'] / $s['n'] < 0.5 ) {
			$guess['name'] = $c;
		}
	}
	foreach ( $stats as $c => $s ) {
		if ( in_array( $c, $taken, true ) || $c === $guess['name'] ) {
			continue;
		}
		if ( $guess['years'] < 0 && $s['n'] >= 1 && $s['years'] / $s['n'] >= 0.5 ) {
			$guess['years'] = $c;
		}
	}
	// A first-name column followed by a last-name column.
	if ( $guess['name'] >= 0 && isset( $stats[ $guess['name'] ], $stats[ $guess['name'] + 1 ] ) ) {
		$a = $stats[ $guess['name'] ];
		$b = $stats[ $guess['name'] + 1 ];
		$next = $guess['name'] + 1;
		if ( $next !== $guess['years'] && ! in_array( $next, $taken, true )
			&& $a['name'] && $a['oneword'] / $a['name'] >= 0.8
			&& $b['name'] && $b['oneword'] / $b['name'] >= 0.8 && $b['name'] / $b['n'] >= 0.6 && $b['years'] / $b['n'] < 0.5 ) {
			$guess['last'] = $next;
		}
	}
	return $guess;
}

/**
 * The (name, years) pairs a sheet gives with the chosen columns. Returns rows
 * (made by make_row, repeats of the same name and years dropped) and counts of
 * what was left out.
 */
function ner_michoel_years_extract( array $rows, array $map ) {
	$out = array( 'rows' => array(), 'no_name' => 0, 'no_years' => 0, 'repeats' => 0, 'ambiguous' => 0 );
	if ( $map['name'] < 0 || $map['years'] < 0 ) {
		return $out;
	}
	$start  = ( isset( $map['header'] ) && $map['header'] >= 0 ) ? $map['header'] + 1 : 0;
	$header = ( $start > 0 && isset( $rows[ $start - 1 ] ) ) ? $rows[ $start - 1 ] : array();
	$title  = isset( $header[ $map['name'] ] ) ? trim( (string) $header[ $map['name'] ] ) : '';
	$cell   = function ( array $row, $c ) {
		return ( $c >= 0 && isset( $row[ $c ] ) ) ? trim( (string) $row[ $c ] ) : '';
	};
	$seen    = array();
	$by_name = array();
	for ( $i = $start, $n = count( $rows ); $i < $n; $i++ ) {
		$row  = $rows[ $i ];
		$name = $cell( $row, $map['name'] );
		if ( $map['last'] >= 0 ) {
			$name = trim( $name . ' ' . $cell( $row, $map['last'] ) );
		}
		if ( '' === $name || ! preg_match( '/\p{L}/u', $name ) || ( '' !== $title && $name === $title ) ) {
			if ( '' !== implode( '', array_map( 'trim', $row ) ) ) {
				$out['no_name']++;
			}
			continue;
		}
		$years = ner_michoel_years_clean_years( $cell( $row, $map['years'] ) );
		if ( $map['until'] >= 0 ) {
			$until = ner_michoel_years_clean_years( $cell( $row, $map['until'] ) );
			if ( '' !== $until && '' !== $years && $until !== $years ) {
				$years = $years . "\xE2\x80\x93" . $until; // an en dash
			} elseif ( '' === $years ) {
				$years = $until;
			}
		}
		$made = ner_michoel_years_make_row( $name, $years );
		if ( ! $made ) {
			$out['no_years']++;
			continue;
		}
		$id = $made['k'] . '|' . $made['y'];
		if ( isset( $seen[ $id ] ) ) {
			$out['repeats']++;
			continue;
		}
		$seen[ $id ]                          = true;
		$by_name[ $made['k'] ][ $made['y'] ] = true;
		$out['rows'][]                        = $made;
	}
	foreach ( $by_name as $years ) {
		if ( count( $years ) > 1 ) {
			$out['ambiguous']++;
		}
	}
	return $out;
}

/** The new list when a file is added to the one already stored: a name that is in both takes the file's years. */
function ner_michoel_years_merge( array $existing, array $incoming ) {
	$replaced = array();
	foreach ( $incoming as $row ) {
		$replaced[ $row['k'] ] = true;
	}
	$kept = array();
	foreach ( $existing as $row ) {
		if ( ! isset( $replaced[ $row['k'] ] ) ) {
			$kept[] = $row;
		}
	}
	return array_merge( $kept, $incoming );
}

/** Rows as a CSV text (UTF-8 with a byte-order mark, so Excel shows Hebrew and accents right). */
function ner_michoel_years_to_csv( array $rows ) {
	usort(
		$rows,
		function ( $a, $b ) {
			return strcasecmp( $a['n'], $b['n'] );
		}
	);
	$h = fopen( 'php://temp', 'r+' );
	$guard = function ( $value ) {
		return preg_match( '/^[=+@\t\r]/', $value ) ? "'" . $value : $value;
	};
	fputcsv( $h, array( 'Name', 'Years' ), ',', '"', '' );
	foreach ( $rows as $row ) {
		fputcsv( $h, array( $guard( $row['n'] ), $guard( $row['y'] ) ), ',', '"', '' );
	}
	rewind( $h );
	$text = stream_get_contents( $h );
	fclose( $h );
	return "\xEF\xBB\xBF" . $text;
}

/* ================================================================== */
/* 2. Storing the list, the lookup route, the buttons' handlers         */
/* ================================================================== */

/** The stored list: rows, count, file, at, by. Empty when there is none. */
function ner_michoel_years_get_list() {
	$stored = get_option( NER_MICHOEL_YEARS_OPTION, array() );
	if ( ! is_array( $stored ) || empty( $stored['rows'] ) || ! is_array( $stored['rows'] ) ) {
		return array( 'rows' => array(), 'count' => 0, 'file' => '', 'at' => 0, 'by' => 0 );
	}
	$stored['count'] = count( $stored['rows'] );
	return $stored;
}

/** How many names are in the list (without unpacking more than needed twice per request). */
function ner_michoel_years_count() {
	$list = ner_michoel_years_get_list();
	return (int) $list['count'];
}

/**
 * Stores a list. Not loaded on ordinary page views (autoload off). Returns true
 * or an error message.
 */
function ner_michoel_years_save_list( array $rows, $file ) {
	global $wpdb;
	$rows = array_values( $rows );
	if ( count( $rows ) > NER_MICHOEL_YEARS_MAX_LIST ) {
		return sprintf( 'The list would have %s names. The most this site keeps is %s.', number_format_i18n( count( $rows ) ), number_format_i18n( NER_MICHOEL_YEARS_MAX_LIST ) );
	}
	$value = array(
		'rows'  => $rows,
		'count' => count( $rows ),
		'file'  => ner_michoel_years_clean( $file, 120 ),
		'at'    => time(),
		'by'    => get_current_user_id(),
	);
	$wpdb->last_error = '';
	update_option( NER_MICHOEL_YEARS_OPTION, $value, false );
	$stored = (int) $wpdb->get_var( $wpdb->prepare( "SELECT LENGTH(option_value) FROM {$wpdb->options} WHERE option_name = %s", NER_MICHOEL_YEARS_OPTION ) );
	if ( '' !== $wpdb->last_error || $stored < 20 ) {
		delete_option( NER_MICHOEL_YEARS_OPTION );
		return 'The list could not be saved (the database refused it). Nothing was changed.';
	}
	return true;
}

/**
 * Looks names up. Returns count (how many names are in the list), strong and
 * maybe (see ner_michoel_years_find), without the internal rank.
 */
function ner_michoel_years_lookup( array $names ) {
	$list = ner_michoel_years_get_list();
	if ( ! $list['count'] ) {
		return array( 'count' => 0, 'strong' => array(), 'maybe' => array() );
	}
	$found = ner_michoel_years_find( $list['rows'], $names );
	$strip = function ( array $items ) {
		return array_map(
			function ( $item ) {
				return array( 'name' => $item['name'], 'years' => $item['years'] );
			},
			$items
		);
	};
	return array(
		'count'  => (int) $list['count'],
		'strong' => $strip( $found['strong'] ),
		'maybe'  => $strip( $found['maybe'] ),
	);
}

function ner_michoel_register_years_list_route() {
	register_rest_route(
		'ner-michoel/v1',
		'/years-list/lookup',
		array(
			'methods'             => 'POST',
			'callback'            => 'ner_michoel_years_list_route',
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
		)
	);
}
add_action( 'rest_api_init', 'ner_michoel_register_years_list_route' );

function ner_michoel_years_list_route( WP_REST_Request $request ) {
	$names = $request->get_param( 'names' );
	if ( ! is_array( $names ) ) {
		$names = array( (string) $request->get_param( 'name' ) );
	}
	$clean = array();
	foreach ( array_slice( $names, 0, 8 ) as $name ) {
		if ( is_string( $name ) ) {
			$name = trim( wp_strip_all_tags( $name ) );
			if ( '' !== $name ) {
				$clean[] = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 400, 'UTF-8' ) : substr( $name, 0, 400 );
			}
		}
	}
	return new WP_REST_Response( ner_michoel_years_lookup( $clean ), 200 );
}

/** Who may upload, replace, download or remove the list. */
function ner_michoel_years_can_manage() {
	return current_user_can( 'edit_others_posts' );
}

/**
 * What the lookup helper (assets/years-list.js) needs on a page: where to ask, the
 * nonce for the REST route, where the list is managed, and whether this person
 * may manage it.
 */
function ner_michoel_years_script_config() {
	return array(
		'url'       => esc_url_raw( rest_url( 'ner-michoel/v1/years-list/lookup' ) ),
		'nonce'     => wp_create_nonce( 'wp_rest' ),
		'manageUrl' => esc_url_raw( ner_michoel_years_screen_url() ),
		'canManage' => ner_michoel_years_can_manage(),
	);
}

/** The helper's script and style (the panel loads them on every page; wp-admin's Quick Mazal Tov asks for them). */
function ner_michoel_enqueue_years_list_assets() {
	wp_enqueue_style( 'ner-michoel-years-list', NER_MICHOEL_CORE_URL . 'assets/years-list.css', array(), NER_MICHOEL_CORE_VERSION );
	wp_enqueue_script( 'ner-michoel-years-list', NER_MICHOEL_CORE_URL . 'assets/years-list.js', array(), NER_MICHOEL_CORE_VERSION, true );
}

/** The Years List screen: the panel's page, or wp-admin's copy. */
function ner_michoel_years_screen_url( $args = array() ) {
	if ( function_exists( 'ner_michoel_is_custom_admin_render' ) && ner_michoel_is_custom_admin_render() ) {
		return ner_michoel_custom_admin_url( 'mazaltovyears', $args );
	}
	$url = admin_url( 'admin.php?page=nm-mazal-tov-years' );
	return $args ? add_query_arg( $args, $url ) : $url;
}

/** Where a handler sends the person next (the panel's page when the form came from it). */
function ner_michoel_years_back( $args = array() ) {
	$url = ner_michoel_panel_redirect_url( admin_url( 'admin.php?page=nm-mazal-tov-years' ), 'mazaltovyears', $args );
	wp_safe_redirect( esc_url_raw( $url ) );
	exit;
}

/* A message that survives the redirect (and is shown once). */
function ner_michoel_years_flash( $type, $message ) {
	set_transient( 'nm_years_flash_' . get_current_user_id(), array( 'type' => $type, 'message' => $message ), 300 );
}

function ner_michoel_years_take_flash() {
	$key   = 'nm_years_flash_' . get_current_user_id();
	$flash = get_transient( $key );
	if ( $flash ) {
		delete_transient( $key );
	}
	return is_array( $flash ) ? $flash : null;
}

function ner_michoel_years_guard() {
	if ( ! ner_michoel_years_can_manage() ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'ner-michoel-core' ), '', array( 'response' => 403 ) );
	}
}

/* A file waiting to be checked: kept for two hours, only for the person who uploaded it. */
function ner_michoel_years_pending_get( $token ) {
	$token = preg_replace( '/[^a-f0-9]/', '', (string) $token );
	if ( 32 !== strlen( $token ) ) {
		return null;
	}
	$data = get_transient( 'nm_years_pending_' . $token );
	if ( ! is_array( $data ) || (int) $data['user'] !== get_current_user_id() ) {
		return null;
	}
	return $data;
}

function ner_michoel_years_pending_delete( $token ) {
	delete_transient( 'nm_years_pending_' . preg_replace( '/[^a-f0-9]/', '', (string) $token ) );
}

/** Step 1: the file arrives, is read, and is kept to be checked. */
function ner_michoel_handle_years_upload() {
	ner_michoel_years_guard();
	check_admin_referer( 'nm_years_upload', 'nm_years_upload_nonce' );

	$file = isset( $_FILES['nm_years_file'] ) ? $_FILES['nm_years_file'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	if ( ! $file || ! isset( $file['error'] ) || UPLOAD_ERR_NO_FILE === $file['error'] ) {
		ner_michoel_years_flash( 'error', __( 'Choose a spreadsheet file first.', 'ner-michoel-core' ) );
		ner_michoel_years_back();
	}
	if ( UPLOAD_ERR_OK !== $file['error'] || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
		$too_big = in_array( $file['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true );
		ner_michoel_years_flash( 'error', $too_big ? __( 'That file is too big for this server to accept.', 'ner-michoel-core' ) : __( 'The file did not upload. Please try again.', 'ner-michoel-core' ) );
		ner_michoel_years_back();
	}

	$read = ner_michoel_years_read_file( $file['tmp_name'] );
	if ( isset( $read['error'] ) ) {
		ner_michoel_years_flash( 'error', $read['error'] );
		ner_michoel_years_back();
	}

	$sheets = array();
	foreach ( $read['sheets'] as $sheet ) {
		$rows = array();
		foreach ( $sheet['rows'] as $row ) {
			$rows[] = array_map(
				function ( $cell ) {
					return ner_michoel_years_clean( $cell, 200 );
				},
				$row
			);
		}
		$sheets[] = array( 'name' => ner_michoel_years_clean( $sheet['name'], 60 ), 'rows' => $rows, 'truncated' => ! empty( $sheet['truncated'] ) );
	}

	$token  = bin2hex( random_bytes( 16 ) );
	$kept   = set_transient(
		'nm_years_pending_' . $token,
		array(
			'user'   => get_current_user_id(),
			'file'   => ner_michoel_years_clean( sanitize_file_name( wp_unslash( $file['name'] ) ), 120 ),
			'sheets' => $sheets,
		),
		2 * HOUR_IN_SECONDS
	);
	if ( ! $kept ) {
		ner_michoel_years_flash( 'error', __( 'The file was read, but this site could not keep it while you check it (the database refused it). Try a smaller file, with only the sheet that has the names.', 'ner-michoel-core' ) );
		ner_michoel_years_back();
	}
	ner_michoel_years_back( array( 'nm_years_review' => $token ) );
}
add_action( 'admin_post_nm_years_upload', 'ner_michoel_handle_years_upload' );

/**
 * What the review step reads from the chosen options: which sheet(s), which
 * columns, replace or add. $source is $_GET or $_POST.
 */
function ner_michoel_years_review_choices( array $pending, array $source ) {
	$sheets = $pending['sheets'];
	$choice = isset( $source['sheet'] ) ? (string) $source['sheet'] : '';
	$mode   = ( isset( $source['mode'] ) && 'add' === $source['mode'] ) ? 'add' : 'replace';

	// The first sheet that gives a Name and a Years column, unless several do (then all of them).
	$detected = array();
	$usable   = array();
	foreach ( $sheets as $i => $sheet ) {
		$detected[ $i ] = ner_michoel_years_detect( $sheet['rows'] );
		if ( $detected[ $i ]['name'] >= 0 && $detected[ $i ]['years'] >= 0 ) {
			$usable[] = $i;
		}
	}
	if ( 'all' !== $choice && ( ! ctype_digit( $choice ) || ! isset( $sheets[ (int) $choice ] ) ) ) {
		if ( count( $usable ) > 1 ) {
			$choice = 'all';
		} else {
			$choice = (string) ( $usable ? $usable[0] : 0 );
		}
	}

	$map = null;
	if ( 'all' !== $choice ) {
		$map  = $detected[ (int) $choice ];
		$pick = function ( $key, $fallback ) use ( $source ) {
			if ( ! isset( $source[ $key ] ) || '' === $source[ $key ] ) {
				return $fallback;
			}
			if ( ! preg_match( '/^-?\d{1,3}$/', (string) $source[ $key ] ) ) {
				return $fallback;
			}
			$n = (int) $source[ $key ];
			return ( $n >= -1 && $n < NER_MICHOEL_YEARS_MAX_COLS ) ? $n : $fallback;
		};
		$map['header'] = $pick( 'hdr', $map['header'] );
		$map['name']   = $pick( 'c_name', $map['name'] );
		$map['last']   = $pick( 'c_last', $map['last'] );
		$map['years']  = $pick( 'c_years', $map['years'] );
		$map['until']  = $pick( 'c_until', $map['until'] );
	}
	return array( 'sheet' => $choice, 'mode' => $mode, 'map' => $map, 'detected' => $detected );
}

/** The rows a set of choices gives, and what to tell the person about them. */
function ner_michoel_years_review_extract( array $pending, array $choices ) {
	$result = array( 'rows' => array(), 'no_name' => 0, 'no_years' => 0, 'repeats' => 0, 'ambiguous' => 0, 'skipped_sheets' => array(), 'truncated' => false );
	$take   = function ( array $sheet, array $map ) use ( &$result ) {
		$got = ner_michoel_years_extract( $sheet['rows'], $map );
		foreach ( array( 'no_name', 'no_years', 'repeats' ) as $key ) {
			$result[ $key ] += $got[ $key ];
		}
		$result['rows']      = array_merge( $result['rows'], $got['rows'] );
		$result['truncated'] = $result['truncated'] || ! empty( $sheet['truncated'] );
	};
	if ( 'all' === $choices['sheet'] ) {
		foreach ( $pending['sheets'] as $i => $sheet ) {
			$map = $choices['detected'][ $i ];
			if ( $map['name'] < 0 || $map['years'] < 0 ) {
				$result['skipped_sheets'][] = '' !== $sheet['name'] ? $sheet['name'] : (string) ( $i + 1 );
				continue;
			}
			$take( $sheet, $map );
		}
	} else {
		$take( $pending['sheets'][ (int) $choices['sheet'] ], $choices['map'] );
	}
	// The same name and years twice across tabs counts once.
	$seen = array();
	$rows = array();
	foreach ( $result['rows'] as $row ) {
		$id = $row['k'] . '|' . $row['y'];
		if ( isset( $seen[ $id ] ) ) {
			$result['repeats']++;
			continue;
		}
		$seen[ $id ] = true;
		$rows[]      = $row;
	}
	$result['rows'] = $rows;
	$by_name        = array();
	foreach ( $rows as $row ) {
		$by_name[ $row['k'] ][ $row['y'] ] = true;
	}
	foreach ( $by_name as $years ) {
		if ( count( $years ) > 1 ) {
			$result['ambiguous']++;
		}
	}
	return $result;
}

/** Step 2: "Update the preview", "Use this list" or "Cancel". */
function ner_michoel_handle_years_apply() {
	ner_michoel_years_guard();
	check_admin_referer( 'nm_years_apply', 'nm_years_apply_nonce' );

	$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
	$do    = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';

	if ( 'cancel' === $do ) {
		ner_michoel_years_pending_delete( $token );
		ner_michoel_years_flash( 'success', __( 'Nothing was changed. The file you uploaded was thrown away.', 'ner-michoel-core' ) );
		ner_michoel_years_back();
	}

	$pending = ner_michoel_years_pending_get( $token );
	if ( ! $pending ) {
		ner_michoel_years_flash( 'error', __( 'That upload is no longer waiting (it is kept for two hours). Please upload the file again.', 'ner-michoel-core' ) );
		ner_michoel_years_back();
	}

	$posted = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	if ( 'apply' !== $do ) {
		$args = array( 'nm_years_review' => $token );
		foreach ( array( 'sheet', 'hdr', 'c_name', 'c_last', 'c_years', 'c_until', 'mode' ) as $key ) {
			if ( isset( $posted[ $key ] ) ) {
				$args[ $key ] = sanitize_text_field( $posted[ $key ] );
			}
		}
		ner_michoel_years_back( $args );
	}

	$choices = ner_michoel_years_review_choices( $pending, $posted );
	$got     = ner_michoel_years_review_extract( $pending, $choices );
	if ( ! $got['rows'] ) {
		ner_michoel_years_flash( 'error', __( 'No names with years were found with those columns, so nothing was changed. Choose the Name and Years columns and look at the preview first.', 'ner-michoel-core' ) );
		ner_michoel_years_back( array( 'nm_years_review' => $token ) );
	}

	$rows = $got['rows'];
	if ( 'add' === $choices['mode'] ) {
		$current = ner_michoel_years_get_list();
		$rows    = ner_michoel_years_merge( $current['rows'], $rows );
	}
	$saved = ner_michoel_years_save_list( $rows, $pending['file'] );
	if ( true !== $saved ) {
		ner_michoel_years_flash( 'error', $saved );
		ner_michoel_years_back( array( 'nm_years_review' => $token ) );
	}
	ner_michoel_years_pending_delete( $token );
	ner_michoel_years_flash(
		'success',
		sprintf(
			/* translators: 1: number of names in the list now, 2: file name */
			_n( 'Done. The list now has %1$s name (from %2$s).', 'Done. The list now has %1$s names (from %2$s).', count( $rows ), 'ner-michoel-core' ),
			number_format_i18n( count( $rows ) ),
			$pending['file']
		)
	);
	ner_michoel_years_back();
}
add_action( 'admin_post_nm_years_apply', 'ner_michoel_handle_years_apply' );

/** Removes the whole list. */
function ner_michoel_handle_years_clear() {
	ner_michoel_years_guard();
	check_admin_referer( 'nm_years_clear', 'nm_years_clear_nonce' );
	delete_option( NER_MICHOEL_YEARS_OPTION );
	ner_michoel_years_flash( 'success', __( 'The list was removed. Years boxes will no longer fill in by themselves until you upload a list again.', 'ner-michoel-core' ) );
	ner_michoel_years_back();
}
add_action( 'admin_post_nm_years_clear', 'ner_michoel_handle_years_clear' );

/** Sends the list back as a CSV file that opens in Excel. */
function ner_michoel_handle_years_download() {
	ner_michoel_years_guard();
	check_admin_referer( 'nm_years_download' );
	$list = ner_michoel_years_get_list();
	if ( ! $list['count'] ) {
		ner_michoel_years_flash( 'error', __( 'There is no list to download yet.', 'ner-michoel-core' ) );
		ner_michoel_years_back();
	}
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="years-list.csv"' );
	header( 'X-Content-Type-Options: nosniff' );
	echo ner_michoel_years_to_csv( $list['rows'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a CSV file, not a page.
	exit;
}
add_action( 'admin_post_nm_years_download', 'ner_michoel_handle_years_download' );

/* ================================================================== */
/* 3. The screen                                                        */
/* ================================================================== */

/** "A", "B", ... "AA" for a column number from 0. */
function ner_michoel_years_col_letter( $n ) {
	$s = '';
	$n = (int) $n;
	do {
		$s = chr( 65 + ( $n % 26 ) ) . $s;
		$n = intdiv( $n, 26 ) - 1;
	} while ( $n >= 0 );
	return $s;
}

/** A menu of the file's columns, each named by its heading when it has one. */
function ner_michoel_years_column_select( $name, $selected, array $sheet, $header, $width, $none_label ) {
	$html  = '<select name="' . esc_attr( $name ) . '" id="nm_years_' . esc_attr( $name ) . '">';
	$html .= '<option value="-1"' . selected( -1, (int) $selected, false ) . '>' . esc_html( $none_label ) . '</option>';
	for ( $c = 0; $c < $width; $c++ ) {
		$title = ( $header >= 0 && isset( $sheet['rows'][ $header ][ $c ] ) ) ? trim( (string) $sheet['rows'][ $header ][ $c ] ) : '';
		$label = sprintf( /* translators: %s: column letter */ __( 'Column %s', 'ner-michoel-core' ), ner_michoel_years_col_letter( $c ) );
		if ( '' !== $title ) {
			$label .= ' — ' . ( function_exists( 'mb_substr' ) ? mb_substr( $title, 0, 40, 'UTF-8' ) : substr( $title, 0, 40 ) );
		}
		$html .= '<option value="' . (int) $c . '"' . selected( $c, (int) $selected, false ) . '>' . esc_html( $label ) . '</option>';
	}
	return $html . '</select>';
}

function ner_michoel_render_years_list_page() {
	if ( ! ner_michoel_years_can_manage() ) {
		echo '<div class="wrap"><p>' . esc_html__( 'You are not allowed to see this page.', 'ner-michoel-core' ) . '</p></div>';
		return;
	}
	$list    = ner_michoel_years_get_list();
	$flash   = ner_michoel_years_take_flash();
	$token   = isset( $_GET['nm_years_review'] ) ? sanitize_text_field( wp_unslash( $_GET['nm_years_review'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$pending = $token ? ner_michoel_years_pending_get( $token ) : null;
	$panel   = function_exists( 'ner_michoel_is_custom_admin_render' ) && ner_michoel_is_custom_admin_render();
	$post_to = admin_url( 'admin-post.php' );
	?>
	<div class="wrap nm-dashboard nm-years">
		<h1><?php esc_html_e( 'Years List', 'ner-michoel-core' ); ?></h1>
		<?php if ( ! $panel ) : // The panel's own page heading already says this. ?>
			<p class="nm-years__lead"><?php esc_html_e( 'A spreadsheet of the boys and the years they were in yeshiva. When you post a Mazal Tov and type a name that is in it, the Years box fills in by itself.', 'ner-michoel-core' ); ?></p>
		<?php endif; ?>

		<?php if ( $flash ) : ?>
			<div class="notice notice-<?php echo 'success' === $flash['type'] ? 'success' : 'error'; ?>"><p><?php echo esc_html( $flash['message'] ); ?></p></div>
		<?php endif; ?>
		<?php if ( $token && ! $pending && ! $flash ) : ?>
			<div class="notice notice-error"><p><?php esc_html_e( 'That upload is no longer waiting (it is kept for two hours). Please upload the file again.', 'ner-michoel-core' ); ?></p></div>
		<?php endif; ?>

		<?php if ( $pending ) : ?>
			<?php ner_michoel_render_years_review( $pending, $token, $list, $post_to ); ?>
		<?php else : ?>

		<h2 class="nm-years__h"><?php esc_html_e( 'The list now', 'ner-michoel-core' ); ?></h2>
		<?php if ( $list['count'] ) : ?>
			<table class="widefat striped nm-years__status">
				<tbody>
					<tr><th><?php esc_html_e( 'Names in the list', 'ner-michoel-core' ); ?></th><td data-nm-years-count><?php echo esc_html( number_format_i18n( $list['count'] ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'From the file', 'ner-michoel-core' ); ?></th><td><?php echo esc_html( $list['file'] ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Loaded', 'ner-michoel-core' ); ?></th><td>
						<?php
						$by = $list['by'] ? get_userdata( (int) $list['by'] ) : null;
						echo esc_html( wp_date( 'j M Y, g:i a', (int) $list['at'] ) . ( $by ? ' — ' . $by->display_name : '' ) );
						?>
					</td></tr>
				</tbody>
			</table>
			<p class="nm-years__buttons">
				<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'nm_years_download' ), $post_to ), 'nm_years_download' ) ); ?>"><?php esc_html_e( 'Download the list as a spreadsheet', 'ner-michoel-core' ); ?></a>
			</p>
			<details class="nm-years__remove">
				<summary><?php esc_html_e( 'Remove the whole list', 'ner-michoel-core' ); ?></summary>
				<form method="post" action="<?php echo esc_url( $post_to ); ?>">
					<?php wp_nonce_field( 'nm_years_clear', 'nm_years_clear_nonce' ); ?>
					<input type="hidden" name="action" value="nm_years_clear" />
					<?php ner_michoel_panel_form_field(); ?>
					<p><?php esc_html_e( 'This deletes every name and year from the site. Mazal Tovs you already posted keep their years. You can upload a list again any time.', 'ner-michoel-core' ); ?></p>
					<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Yes, remove the list', 'ner-michoel-core' ); ?></button>
				</form>
			</details>
		<?php else : ?>
			<p class="nm-years__empty"><?php esc_html_e( 'There is no list yet. Upload your spreadsheet below.', 'ner-michoel-core' ); ?></p>
		<?php endif; ?>

		<h2 class="nm-years__h"><?php echo $list['count'] ? esc_html__( 'Update the list', 'ner-michoel-core' ) : esc_html__( 'Upload the spreadsheet', 'ner-michoel-core' ); ?></h2>
		<form method="post" action="<?php echo esc_url( $post_to ); ?>" enctype="multipart/form-data" class="nm-years__upload">
			<?php wp_nonce_field( 'nm_years_upload', 'nm_years_upload_nonce' ); ?>
			<input type="hidden" name="action" value="nm_years_upload" />
			<?php ner_michoel_panel_form_field(); ?>
			<p>
				<label for="nm_years_file"><?php esc_html_e( 'Excel file (.xlsx) or CSV', 'ner-michoel-core' ); ?></label><br />
				<input type="file" id="nm_years_file" name="nm_years_file" accept=".xlsx,.xlsm,.csv,.tsv,.txt,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" required />
			</p>
			<p class="description"><?php esc_html_e( 'One row per boy, with a heading row on top such as Name and Years (or First name, Last name and Years). Years can be written the way you want them to appear, for example 2005-2008 or \'05, \'08. Nothing changes yet: you will see how the file was read and choose whether to replace the list or add to it.', 'ner-michoel-core' ); ?></p>
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Upload and check', 'ner-michoel-core' ); ?></button></p>
		</form>

		<h2 class="nm-years__h"><?php esc_html_e( 'Try a name', 'ner-michoel-core' ); ?></h2>
		<div class="nm-years__try">
			<label for="nm_years_try"><?php esc_html_e( 'Type a name to see what the list has for it', 'ner-michoel-core' ); ?></label><br />
			<input type="text" id="nm_years_try" class="regular-text" autocomplete="off" />
			<div data-nm-years-lookup
				data-source="#nm_years_try"
				data-target=""
				data-url="<?php echo esc_url( rest_url( 'ner-michoel/v1/years-list/lookup' ) ); ?>"
				data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
				data-manage-url=""
				data-can-manage="0"></div>
		</div>
		<?php endif; ?>
	</div>
	<?php
}

/** The "how I read your file" step, with the columns to change and a preview. */
function ner_michoel_render_years_review( array $pending, $token, array $list, $post_to ) {
	$choices = ner_michoel_years_review_choices( $pending, wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks what to show; nothing is saved here.
	$got     = ner_michoel_years_review_extract( $pending, $choices );
	$sheets  = $pending['sheets'];
	$all     = ( 'all' === $choices['sheet'] );
	$sheet   = $all ? null : $sheets[ (int) $choices['sheet'] ];
	$map     = $choices['map'];
	$width   = $all ? 0 : max( 1, $choices['detected'][ (int) $choices['sheet'] ]['width'] );
	?>
	<h2 class="nm-years__h"><?php esc_html_e( 'How your file was read', 'ner-michoel-core' ); ?></h2>
	<p><?php echo esc_html( sprintf( /* translators: %s: file name */ __( 'File: %s. Nothing has been changed yet.', 'ner-michoel-core' ), $pending['file'] ) ); ?></p>

	<form method="post" action="<?php echo esc_url( $post_to ); ?>" class="nm-years__review">
		<?php wp_nonce_field( 'nm_years_apply', 'nm_years_apply_nonce' ); ?>
		<input type="hidden" name="action" value="nm_years_apply" />
		<input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>" />
		<?php ner_michoel_panel_form_field(); ?>
		<table class="form-table">
			<?php if ( count( $sheets ) > 1 ) : ?>
			<tr>
				<th scope="row"><label for="nm_years_sheet"><?php esc_html_e( 'Which tab', 'ner-michoel-core' ); ?></label></th>
				<td>
					<select name="sheet" id="nm_years_sheet">
						<option value="all"<?php selected( $all ); ?>><?php esc_html_e( 'All tabs together', 'ner-michoel-core' ); ?></option>
						<?php foreach ( $sheets as $i => $s ) : ?>
							<option value="<?php echo (int) $i; ?>"<?php selected( ! $all && (int) $choices['sheet'] === $i ); ?>><?php echo esc_html( ( '' !== $s['name'] ? $s['name'] : sprintf( __( 'Tab %d', 'ner-michoel-core' ), $i + 1 ) ) . ' (' . number_format_i18n( count( $s['rows'] ) ) . ' ' . __( 'rows', 'ner-michoel-core' ) . ')' ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'With “All tabs together”, each tab is read on its own and the names are put in one list.', 'ner-michoel-core' ); ?></p>
				</td>
			</tr>
			<?php else : ?>
				<input type="hidden" name="sheet" value="<?php echo esc_attr( $all ? 'all' : (string) (int) $choices['sheet'] ); ?>" />
			<?php endif; ?>
			<?php if ( ! $all ) : ?>
			<tr>
				<th scope="row"><label for="nm_years_c_name"><?php esc_html_e( 'The boy’s name is in', 'ner-michoel-core' ); ?></label></th>
				<td><?php echo ner_michoel_years_column_select( 'c_name', $map['name'], $sheet, $map['header'], $width, __( '— not chosen —', 'ner-michoel-core' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
			</tr>
			<tr>
				<th scope="row"><label for="nm_years_c_last"><?php esc_html_e( 'and his last name is in', 'ner-michoel-core' ); ?></label></th>
				<td><?php echo ner_michoel_years_column_select( 'c_last', $map['last'], $sheet, $map['header'], $width, __( '— no separate last name column —', 'ner-michoel-core' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
			</tr>
			<tr>
				<th scope="row"><label for="nm_years_c_years"><?php esc_html_e( 'The years are in', 'ner-michoel-core' ); ?></label></th>
				<td><?php echo ner_michoel_years_column_select( 'c_years', $map['years'], $sheet, $map['header'], $width, __( '— not chosen —', 'ner-michoel-core' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
			</tr>
			<tr>
				<th scope="row"><label for="nm_years_c_until"><?php esc_html_e( 'and the last year is in', 'ner-michoel-core' ); ?></label></th>
				<td>
					<?php echo ner_michoel_years_column_select( 'c_until', $map['until'], $sheet, $map['header'], $width, __( '— no separate last year column —', 'ner-michoel-core' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<p class="description"><?php esc_html_e( 'Only if the first year and the last year are in two columns: they are written together, like 2005–2008.', 'ner-michoel-core' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="nm_years_hdr"><?php esc_html_e( 'The names start after', 'ner-michoel-core' ); ?></label></th>
				<td>
					<select name="hdr" id="nm_years_hdr">
						<option value="-1"<?php selected( -1, (int) $map['header'] ); ?>><?php esc_html_e( 'No heading row (the first row is a name)', 'ner-michoel-core' ); ?></option>
						<?php for ( $r = 0; $r < min( 10, count( $sheet['rows'] ) ); $r++ ) : ?>
							<?php
							$first = '';
							foreach ( $sheet['rows'][ $r ] as $cell ) {
								if ( '' !== trim( (string) $cell ) ) {
									$first = $cell;
									break;
								}
							}
							?>
							<option value="<?php echo (int) $r; ?>"<?php selected( $r, (int) $map['header'] ); ?>><?php echo esc_html( sprintf( /* translators: 1: row number, 2: its first cell */ __( 'Row %1$d (%2$s)', 'ner-michoel-core' ), $r + 1, function_exists( 'mb_substr' ) ? mb_substr( $first, 0, 30, 'UTF-8' ) : substr( $first, 0, 30 ) ) ); ?></option>
						<?php endfor; ?>
					</select>
				</td>
			</tr>
			<?php endif; ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'What to do with it', 'ner-michoel-core' ); ?></th>
				<td>
					<label><input type="radio" name="mode" value="replace"<?php checked( 'replace', $choices['mode'] ); ?> /> <?php esc_html_e( 'Replace the whole list with this file', 'ner-michoel-core' ); ?></label><br />
					<label><input type="radio" name="mode" value="add"<?php checked( 'add', $choices['mode'] ); ?> /> <?php esc_html_e( 'Add to the list: new names are added, and a name already there takes the years in this file', 'ner-michoel-core' ); ?></label>
					<?php if ( $list['count'] ) : ?>
						<p class="description"><?php echo esc_html( sprintf( /* translators: %s: number */ __( 'The list has %s names now.', 'ner-michoel-core' ), number_format_i18n( $list['count'] ) ) ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
		</table>

		<h3 class="nm-years__h3"><?php esc_html_e( 'Preview', 'ner-michoel-core' ); ?></h3>
		<?php if ( $got['rows'] ) : ?>
			<p class="nm-years__found"><strong><?php echo esc_html( sprintf( /* translators: %s: number */ _n( '%s name with years found.', '%s names with years found.', count( $got['rows'] ), 'ner-michoel-core' ), number_format_i18n( count( $got['rows'] ) ) ) ); ?></strong></p>
			<table class="widefat striped nm-years__preview">
				<thead><tr><th><?php esc_html_e( 'Name', 'ner-michoel-core' ); ?></th><th><?php esc_html_e( 'Years', 'ner-michoel-core' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( array_slice( $got['rows'], 0, 8 ) as $row ) : ?>
						<tr><td><span dir="auto"><?php echo esc_html( $row['n'] ); ?></span></td><td><span dir="auto"><?php echo esc_html( $row['y'] ); ?></span></td></tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( count( $got['rows'] ) > 8 ) : ?>
				<p class="description"><?php echo esc_html( sprintf( /* translators: %s: number */ __( '…and %s more.', 'ner-michoel-core' ), number_format_i18n( count( $got['rows'] ) - 8 ) ) ); ?></p>
			<?php endif; ?>
		<?php else : ?>
			<div class="notice notice-warning"><p><?php esc_html_e( 'No names with years were found with these choices. Pick the column with the names and the column with the years above, then press “Update the preview”.', 'ner-michoel-core' ); ?></p></div>
		<?php endif; ?>
		<ul class="nm-years__notes">
			<?php if ( $got['no_name'] ) : ?><li><?php echo esc_html( sprintf( /* translators: %s: number */ _n( '%s row had no name and was left out.', '%s rows had no name and were left out.', $got['no_name'], 'ner-michoel-core' ), number_format_i18n( $got['no_name'] ) ) ); ?></li><?php endif; ?>
			<?php if ( $got['no_years'] ) : ?><li><?php echo esc_html( sprintf( /* translators: %s: number */ _n( '%s name had no years and was left out.', '%s names had no years and were left out.', $got['no_years'], 'ner-michoel-core' ), number_format_i18n( $got['no_years'] ) ) ); ?></li><?php endif; ?>
			<?php if ( $got['repeats'] ) : ?><li><?php echo esc_html( sprintf( /* translators: %s: number */ _n( '%s repeated row (same name and years) was counted once.', '%s repeated rows (same name and years) were counted once.', $got['repeats'], 'ner-michoel-core' ), number_format_i18n( $got['repeats'] ) ) ); ?></li><?php endif; ?>
			<?php if ( $got['ambiguous'] ) : ?><li><?php echo esc_html( sprintf( /* translators: %s: number */ _n( '%s name is in the file with different years (two boys with the same name?). When you post for it you will be asked to pick.', '%s names are in the file with different years (two boys with the same name?). When you post for them you will be asked to pick.', $got['ambiguous'], 'ner-michoel-core' ), number_format_i18n( $got['ambiguous'] ) ) ); ?></li><?php endif; ?>
			<?php if ( $got['skipped_sheets'] ) : ?><li><?php echo esc_html( sprintf( /* translators: %s: tab names */ __( 'These tabs had no Name and Years columns and were skipped: %s.', 'ner-michoel-core' ), implode( ', ', $got['skipped_sheets'] ) ) ); ?></li><?php endif; ?>
			<?php if ( $got['truncated'] ) : ?><li><?php echo esc_html( sprintf( /* translators: %s: number */ __( 'Only the first %s rows of a sheet are read.', 'ner-michoel-core' ), number_format_i18n( NER_MICHOEL_YEARS_MAX_ROWS ) ) ); ?></li><?php endif; ?>
		</ul>
		<p class="nm-years__buttons">
			<button type="submit" name="do" value="apply" class="button button-primary"<?php disabled( ! $got['rows'] ); ?>><?php esc_html_e( 'Use this list', 'ner-michoel-core' ); ?></button>
			<button type="submit" name="do" value="preview" class="button"><?php esc_html_e( 'Update the preview', 'ner-michoel-core' ); ?></button>
			<button type="submit" name="do" value="cancel" class="button button-link-delete" formnovalidate><?php esc_html_e( 'Cancel', 'ner-michoel-core' ); ?></button>
		</p>
	</form>
	<?php
}
