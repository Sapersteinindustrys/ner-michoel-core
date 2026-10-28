<?php
/**
 * This week's parsha, worked out from the Hebrew calendar, and the shiurim for it.
 * Used by the homepage's "This week" section (the theme's inc/week.php).
 *
 * Three parts:
 *
 * 1. The calendar. hebrew-calendar.php gives the date; this turns it into the
 *    reading for any Shabbos, for the diaspora or for Israel. A year's reading is
 *    fixed by its type (leap or not, the weekday Rosh Hashanah falls on, and how
 *    long the year is): the table in ner_michoel_parsha_year_types() lists which of
 *    the seven pairs of parshiyos are read together in each of the 14 types. Shabbosim
 *    are then dealt out one by one from Shabbos Bereishis, skipping the ones that are
 *    Yom Tov. The table and the dealing were checked against Hebcal for every Shabbos
 *    from 5600 to 6100, both ways (parsha-tests/).
 *
 * 2. Names. The site and its imports spell the same parsha many ways (Re'eh, Reeh,
 *    Re'a, Reah; Vayeira, Vayera; Mattos-Massei, Matot Masei). ner_michoel_spelling_key()
 *    folds a spelling to a key that ignores vowels, apostrophes, spaces, doubled
 *    letters, ch / kh / h, tz / ts and a final t or s, so those all give one key; the
 *    few that still differ (Yisro / Yitro, Ki Sisa / Ki Tisa) are listed as aliases,
 *    and a spelling one letter off a known key still counts when it can only mean one
 *    parsha.
 *
 * 3. Shiurim. A topic or series counts for a parsha when one of its " / " parts is that
 *    parsha ("Sefer Shemos / Bo / Weekly Parsha" has the part "Bo"; "Sefer Shemos" is the
 *    Chumash, not a parsha, so it never counts). Written shiurim are also found by their
 *    titles ("Parshas Re'eh 5786").
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The 54 parshiyos by number (Bereishis 1 to Vezos Haberacha 54), spelled as the site
 * spells them.
 */
function ner_michoel_parsha_names() {
	return array(
		1  => 'Bereishis',
		2  => 'Noach',
		3  => 'Lech Lecha',
		4  => 'Vayeira',
		5  => 'Chayei Sarah',
		6  => 'Toldos',
		7  => 'Vayeitzei',
		8  => 'Vayishlach',
		9  => 'Vayeishev',
		10 => 'Miketz',
		11 => 'Vayigash',
		12 => 'Vayechi',
		13 => 'Shemos',
		14 => 'Vaeira',
		15 => 'Bo',
		16 => 'Beshalach',
		17 => 'Yisro',
		18 => 'Mishpatim',
		19 => 'Terumah',
		20 => 'Tetzaveh',
		21 => 'Ki Sisa',
		22 => 'Vayakhel',
		23 => 'Pekudei',
		24 => 'Vayikra',
		25 => 'Tzav',
		26 => 'Shemini',
		27 => 'Tazria',
		28 => 'Metzora',
		29 => 'Acharei Mos',
		30 => 'Kedoshim',
		31 => 'Emor',
		32 => 'Behar',
		33 => 'Bechukosai',
		34 => 'Bamidbar',
		35 => 'Naso',
		36 => 'Behaaloscha',
		37 => 'Shlach',
		38 => 'Korach',
		39 => 'Chukas',
		40 => 'Balak',
		41 => 'Pinchas',
		42 => 'Mattos',
		43 => 'Massei',
		44 => 'Devarim',
		45 => 'Vaeschanan',
		46 => 'Eikev',
		47 => "Re'eh",
		48 => 'Shoftim',
		49 => 'Ki Seitzei',
		50 => 'Ki Savo',
		51 => 'Nitzavim',
		52 => 'Vayelech',
		53 => 'Haazinu',
		54 => 'Vezos Haberacha',
	);
}

/**
 * Spellings that ner_michoel_spelling_key() would not fold into the parsha's own key, by
 * parsha number.
 */
function ner_michoel_parsha_aliases() {
	return array(
		17 => array( 'Yitro', 'Jethro' ),
		21 => array( 'Ki Tisa', 'Ki Thisa' ),
		22 => array( 'Vayakel' ),
		29 => array( 'Acharei', 'Acharey Mot' ),
		33 => array( 'Bechukotai', 'Bechukotay', 'Bechukosay' ),
		36 => array( 'Behaalosecha', 'Behaalotecha', 'Behaalotcha', 'Behaaloscha', 'Behaalosecho', 'Baalosecha', 'Beha\'alos' ),
		37 => array( 'Shelach', 'Shelach Lecha', 'Shlach Lecha', 'Shlach Lecho', 'Shelakh' ),
		45 => array( 'Vaetchanan', 'Vaethanan', 'Vaeschanan', 'Vaetchonon', 'Vaeschonon', 'Va\'etchanan' ),
		49 => array( 'Ki Tetzei', 'Ki Teitzei', 'Ki Tetze', 'Ki Setzei', 'Ki Seitze', 'Ki Teitze' ),
		50 => array( 'Ki Tavo', 'Ki Tovo', 'Ki Sovo' ),
		53 => array( 'Azinu', 'Haazinu' ),
		54 => array( 'Vezot Haberakha', 'Zos Habracha', 'Vezos Habrocho' ),
	);
}

/**
 * Names that fold to a parsha's key but are something else on this site: the order of
 * Mishnayos Kodshim (Kedoshim) and the masechta Berochos (Bereishis).
 */
function ner_michoel_parsha_not_names() {
	return array( 'kodshim', 'kodashim', 'kodoshim', 'berochos', 'berachos', 'berachot', 'brachos', 'brochos', 'berakhot', 'bechoros', 'bechorot' );
}

/* ------------------------------------------------------------------
 * Names.
 * ------------------------------------------------------------------ */

/**
 * The letters of a text as lowercase words: accents removed, apostrophes dropped (so
 * "Re'eh" is one word), digits and punctuation taken for word breaks.
 */
function ner_michoel_name_words( $text ) {
	$s = html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' );
	if ( function_exists( 'remove_accents' ) ) {
		$s = remove_accents( $s );
	}
	$s = strtolower( $s );
	$s = preg_replace( "/['`\x{2018}\x{2019}\x{02BC}\x{02BB}\x{00B4}]/u", '', $s );
	return preg_split( '/[^a-z]+/', $s, -1, PREG_SPLIT_NO_EMPTY );
}

/**
 * The key of one word: sh, tz / ts, ch / kh / h, th, ph, ck, q / c and w are folded to
 * one letter each; a y that doesn't start a syllable is a vowel; an h at the end of a
 * word after a vowel is silent (Noach, Reeh, Sarah, Lech); then the vowels go, a final t
 * becomes s (Toldot / Toldos) and doubled letters become one.
 */
function ner_michoel_word_key( $word ) {
	$w = preg_replace(
		array( '/sch|sh/', '/tz|ts/', '/ch|kh/', '/th/', '/ph/', '/ck/', '/q/', '/c/', '/w/', '/j/' ),
		array( 'S', 'z', 'h', 't', 'p', 'k', 'k', 'k', 'v', 'y' ),
		(string) $word
	);
	$w = preg_replace( '/y(?![aeiou])/', 'i', $w );
	$w = preg_replace( '/(?<=[aeiou])h$/', '', $w );
	$w = preg_replace( '/[aeiou]/', '', $w );
	$w = preg_replace( '/t$/', 's', $w );
	return preg_replace( '/(.)\1+/', '$1', $w );
}

/**
 * The key of a name: its words' keys joined, so "Lech Lecha", "Lech-Lecha" and
 * "Lekh Lekha" are one.
 */
function ner_michoel_spelling_key( $text ) {
	static $cache = array();
	$text = (string) $text;
	if ( isset( $cache[ $text ] ) ) {
		return $cache[ $text ];
	}
	$key = '';
	foreach ( ner_michoel_name_words( $text ) as $word ) {
		$key .= ner_michoel_word_key( $word );
	}
	$cache[ $text ] = $key;
	return $key;
}

/**
 * A name's letters alone, lowercase, in one piece ("Re'eh" is "reeh").
 */
function ner_michoel_name_raw( $text ) {
	return implode( '', ner_michoel_name_words( $text ) );
}

/**
 * Key => parsha number, for the names and aliases (and for a two-word name written as one
 * word, "LechLecha"). A key two parshiyos share is left out (a test keeps that from
 * happening).
 */
function ner_michoel_parsha_key_map() {
	static $map = null;
	if ( null !== $map ) {
		return $map;
	}
	$map     = array();
	$clashed = array();
	$add     = function ( $name, $number ) use ( &$map, &$clashed ) {
		$keys = array( ner_michoel_spelling_key( $name ), ner_michoel_word_key( ner_michoel_name_raw( $name ) ) );
		foreach ( $keys as $key ) {
			if ( '' === $key ) {
				continue;
			}
			if ( isset( $map[ $key ] ) && $map[ $key ] !== $number ) {
				$clashed[ $key ] = true;
			}
			$map[ $key ] = $number;
		}
	};
	foreach ( ner_michoel_parsha_names() as $number => $name ) {
		$add( $name, $number );
	}
	foreach ( ner_michoel_parsha_aliases() as $number => $names ) {
		foreach ( $names as $name ) {
			$add( $name, $number );
		}
	}
	foreach ( array_keys( $clashed ) as $key ) {
		unset( $map[ $key ] );
	}
	return $map;
}

/**
 * The letters of every known spelling => parsha number, for telling a slip from a different
 * word.
 */
function ner_michoel_parsha_raw_map() {
	static $raw = null;
	if ( null === $raw ) {
		$raw = array();
		foreach ( ner_michoel_parsha_names() as $number => $name ) {
			$raw[ ner_michoel_name_raw( $name ) ] = $number;
		}
		foreach ( ner_michoel_parsha_aliases() as $number => $names ) {
			foreach ( $names as $name ) {
				$raw[ ner_michoel_name_raw( $name ) ] = $number;
			}
		}
	}
	return $raw;
}

/**
 * The parsha number for a key: an exact key, or else, for names of six letters or more, the
 * only parsha whose spelling is a slip away (one letter, two for a long name) and whose key
 * is at most one letter off. 0 for none. $raw is the name's letters.
 */
function ner_michoel_parsha_number_for_key( $key, $raw = '' ) {
	static $cache = array();
	$id = $key . '|' . $raw;
	if ( isset( $cache[ $id ] ) ) {
		return $cache[ $id ];
	}
	$map    = ner_michoel_parsha_key_map();
	$number = 0;
	if ( isset( $map[ $key ] ) ) {
		$number = $map[ $key ];
	} elseif ( strlen( $raw ) >= 6 ) {
		$limit = strlen( $raw ) >= 9 ? 2 : 1;
		$best  = $limit + 1;
		$near  = array();
		foreach ( ner_michoel_parsha_raw_map() as $known => $n ) {
			$distance = levenshtein( $known, $raw );
			if ( $distance > $limit || $distance > $best || levenshtein( ner_michoel_spelling_key( $known ), $key ) > 1 ) {
				continue;
			}
			if ( $distance < $best ) {
				$best = $distance;
				$near = array();
			}
			$near[ $n ] = true;
		}
		if ( 1 === count( $near ) ) {
			$number = (int) key( $near );
		}
	}
	$cache[ $id ] = $number;
	return $number;
}

/**
 * Words that may stand in front of a parsha's name ("Parshas Re'eh", "Weekly Parsha:
 * Re'eh", "Shabbos Noach"), and "and" between two.
 */
function ner_michoel_parsha_filler_words() {
	return array( 'parshas', 'parshat', 'parsha', 'parshah', 'parasha', 'parashah', 'parashat', 'parashas', 'sedra', 'sidra', 'weekly', 'shabbos', 'shabbat', 'shabbath', 'and', 'the' );
}

/**
 * The parshiyos a whole text names, as numbers in order, or an empty array. Every word
 * has to belong to a parsha name (one to three words each), after the filler words at the
 * front are dropped: "Parshas Re'eh 5786" is 47, "Mattos-Massei" is 42 and 43,
 * "Sefer Bereishis" and "Weekly Parsha" are nothing.
 *
 * @param string $text A topic or series part, or a title.
 * @return int[]
 */
function ner_michoel_parsha_match_name( $text ) {
	// A note in brackets at the end ("Vayechi (Chanukah)") is not part of the name.
	$words = ner_michoel_name_words( preg_replace( '/\s*\([^()]*\)\s*$/u', '', html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' ) ) );
	$fill  = ner_michoel_parsha_filler_words();
	while ( $words && in_array( $words[0], $fill, true ) ) {
		array_shift( $words );
	}
	$words = array_values( array_diff( $words, array( 'and' ) ) );
	if ( ! $words || count( $words ) > 8 || ( 1 === count( $words ) && in_array( $words[0], ner_michoel_parsha_not_names(), true ) ) ) {
		return array();
	}

	$keys = array();
	foreach ( $words as $i => $word ) {
		$keys[ $i ] = ner_michoel_word_key( $word );
	}
	$count = count( $words );

	// Cut the words into parsha names, the longest first; the first way that uses them all wins.
	$cut = function ( $from ) use ( &$cut, $keys, $words, $count ) {
		if ( $from >= $count ) {
			return array();
		}
		for ( $len = min( 3, $count - $from ); $len >= 1; $len-- ) {
			$number = ner_michoel_parsha_number_for_key( implode( '', array_slice( $keys, $from, $len ) ), implode( '', array_slice( $words, $from, $len ) ) );
			if ( ! $number ) {
				continue;
			}
			$rest = $cut( $from + $len );
			if ( null !== $rest ) {
				return array_merge( array( $number ), $rest );
			}
		}
		return null;
	};

	$found = $cut( 0 );
	if ( ! $found ) {
		return array();
	}
	$found = array_values( array_unique( $found ) );
	sort( $found );
	return $found;
}

/**
 * The parshiyos a written shiur's title names: "Parshas Haazinu 5787", "Bizayon Av
 * v'Eim (Ki Savo 5786)", "Haazinu 5787". An empty array when the title names none.
 */
function ner_michoel_parsha_in_title( $title ) {
	$title = trim( html_entity_decode( (string) $title, ENT_QUOTES, 'UTF-8' ) );
	if ( '' === $title ) {
		return array();
	}
	$tries = array();
	if ( preg_match( '/\(([^()]+)\)\s*$/u', $title, $m ) ) {
		$tries[] = $m[1];
	}
	if ( preg_match( '/\bpar[a]?sh(?:as|at|ah|a|os)\b[\s:\-\x{2013}\x{2014}]*(.+)$/iu', $title, $m ) ) {
		$tries[] = preg_split( '/\s+[\-\x{2013}\x{2014}]\s+|[:(,;]/u', $m[1] )[0];
	}
	$tries[] = $title;
	foreach ( $tries as $try ) {
		$found = ner_michoel_parsha_match_name( $try );
		if ( $found ) {
			return $found;
		}
	}
	return array();
}

/* ------------------------------------------------------------------
 * The calendar.
 * ------------------------------------------------------------------ */

/**
 * The first number of each pair of parshiyos read together, by year type, as
 * array( diaspora, Israel ). The type is "L" or "C" (leap or common), the weekday Rosh
 * Hashanah falls on (0 Sunday to 6 Shabbos), a dash and the length of the year in days.
 * 22 is Vayakhel-Pekudei, 27 Tazria-Metzora, 29 Acharei Mos-Kedoshim, 32 Behar-Bechukosai,
 * 39 Chukas-Balak, 42 Mattos-Massei, 51 Nitzavim-Vayelech.
 */
function ner_michoel_parsha_year_types() {
	return array(
		'C1-353' => array( array( 22, 27, 29, 32, 42, 51 ), array( 22, 27, 29, 32, 42, 51 ) ),
		'C1-355' => array( array( 22, 27, 29, 32, 39, 42, 51 ), array( 22, 27, 29, 32, 42, 51 ) ),
		'C2-354' => array( array( 22, 27, 29, 32, 39, 42, 51 ), array( 22, 27, 29, 32, 42, 51 ) ),
		'C4-354' => array( array( 22, 27, 29, 32, 42 ), array( 22, 27, 29, 42 ) ),
		'C4-355' => array( array( 27, 29, 32, 42 ), array( 27, 29, 32, 42 ) ),
		'C6-353' => array( array( 22, 27, 29, 32, 42 ), array( 22, 27, 29, 32, 42 ) ),
		'C6-355' => array( array( 22, 27, 29, 32, 42, 51 ), array( 22, 27, 29, 32, 42, 51 ) ),
		'L1-383' => array( array( 39, 42, 51 ), array( 42, 51 ) ),
		'L1-385' => array( array( 42 ), array() ),
		'L2-384' => array( array( 42 ), array() ),
		'L4-383' => array( array(), array() ),
		'L4-385' => array( array( 51 ), array( 51 ) ),
		'L6-383' => array( array( 42, 51 ), array( 42, 51 ) ),
		'L6-385' => array( array( 39, 42, 51 ), array( 42, 51 ) ),
	);
}

/**
 * The weekday of a fixed day: 0 Sunday to 6 Shabbos.
 */
function ner_michoel_parsha_weekday( $fixed ) {
	return (int) ( $fixed % 7 );
}

/**
 * The readings of the Torah year that starts after Simchas Torah of Hebrew year $year,
 * in order: a list of one or two parsha numbers each. The last one or two (Vayelech,
 * Haazinu) are read in the Tishrei after, before Sukkos.
 *
 * @return int[][]
 */
function ner_michoel_parsha_units( $year, $israel ) {
	static $cache = array();
	$id = $year . ( $israel ? 'i' : 'd' );
	if ( isset( $cache[ $id ] ) ) {
		return $cache[ $id ];
	}

	$types = ner_michoel_parsha_year_types();
	$type  = ( ner_michoel_hebrew_leap_year( $year ) ? 'L' : 'C' )
		. ner_michoel_parsha_weekday( ner_michoel_hebrew_to_fixed( 7, 1, $year ) )
		. '-' . ner_michoel_hebrew_days_in_year( $year );
	if ( ! isset( $types[ $type ] ) ) {
		return array();
	}
	$pairs = $types[ $type ][ $israel ? 1 : 0 ];

	$units = array();
	for ( $n = 1; $n <= 53; $n++ ) {
		if ( in_array( $n, $pairs, true ) ) {
			$units[] = array( $n, $n + 1 );
			++$n;
		} else {
			$units[] = array( $n );
		}
	}
	$cache[ $id ] = $units;
	return $units;
}

/**
 * Whether a Shabbos is also Yom Tov or Chol Hamoed, so it has a reading of its own and
 * no parsha. Israel keeps one day less of Pesach, Shemini Atzeres and Shavuos.
 */
function ner_michoel_parsha_is_yomtov( $fixed, $israel ) {
	list( , $month, $day ) = ner_michoel_hebrew_from_fixed( $fixed );
	if ( 7 === $month ) {
		return in_array( $day, array( 1, 2, 10 ), true ) || ( $day >= 15 && $day <= ( $israel ? 22 : 23 ) );
	}
	if ( 1 === $month ) {
		return $day >= 15 && $day <= ( $israel ? 21 : 22 );
	}
	if ( 3 === $month ) {
		return 6 === $day || ( ! $israel && 7 === $day );
	}
	return false;
}

/**
 * The first Shabbos on or after a fixed day.
 */
function ner_michoel_parsha_shabbos_on_or_after( $fixed ) {
	return $fixed + ( 6 - ner_michoel_parsha_weekday( $fixed ) );
}

/**
 * The parshiyos read on a Shabbos, as numbers (one, or two when read together), or an
 * empty array when the day isn't a Shabbos or its reading is for the Yom Tov.
 *
 * @param int  $fixed  Fixed day number (hebrew-calendar.php).
 * @param bool $israel Israel's reading instead of the diaspora's.
 * @return int[]
 */
function ner_michoel_parsha_of_shabbos( $fixed, $israel = false ) {
	$fixed = (int) $fixed;
	if ( 6 !== ner_michoel_parsha_weekday( $fixed ) || ner_michoel_parsha_is_yomtov( $fixed, $israel ) ) {
		return array();
	}

	list( $year )  = ner_michoel_hebrew_from_fixed( $fixed );
	$simchas_torah = ner_michoel_hebrew_to_fixed( 7, $israel ? 22 : 23, $year );

	if ( $fixed <= $simchas_torah ) {
		// Between Rosh Hashanah and Sukkos: what is left of last year's readings.
		$slots = array();
		for ( $s = ner_michoel_parsha_shabbos_on_or_after( ner_michoel_hebrew_to_fixed( 7, 1, $year ) ); $s < $simchas_torah; $s += 7 ) {
			if ( ! ner_michoel_parsha_is_yomtov( $s, $israel ) ) {
				$slots[] = $s;
			}
		}
		$units = ner_michoel_parsha_units( $year - 1, $israel );
		$left  = array_slice( $units, -count( $slots ) );
		$at    = array_search( $fixed, $slots, true );
		return ( false !== $at && isset( $left[ $at ] ) ) ? $left[ $at ] : array();
	}

	$units = ner_michoel_parsha_units( $year, $israel );
	$index = 0;
	for ( $s = ner_michoel_parsha_shabbos_on_or_after( $simchas_torah + 1 ); $s < $fixed; $s += 7 ) {
		if ( ! ner_michoel_parsha_is_yomtov( $s, $israel ) ) {
			++$index;
		}
	}
	return isset( $units[ $index ] ) ? $units[ $index ] : array();
}

/**
 * A fixed day's Hebrew date as text: "19 Cheshvan 5787".
 */
function ner_michoel_hebrew_date_label( $fixed ) {
	list( $year, $month, $day ) = ner_michoel_hebrew_from_fixed( (int) $fixed );
	$names = array(
		1  => 'Nisan',
		2  => 'Iyar',
		3  => 'Sivan',
		4  => 'Tammuz',
		5  => 'Av',
		6  => 'Elul',
		7  => 'Tishrei',
		8  => 'Cheshvan',
		9  => 'Kislev',
		10 => 'Teves',
		11 => 'Shevat',
		12 => ner_michoel_hebrew_leap_year( $year ) ? 'Adar I' : 'Adar',
		13 => 'Adar II',
	);
	return sprintf( '%d %s %d', $day, $names[ $month ], $year );
}

/**
 * A fixed day as a date in the site's language, "Oct 31".
 */
function ner_michoel_fixed_date_label( $fixed ) {
	$date = ( new DateTimeImmutable( '0001-01-01 12:00:00', new DateTimeZone( 'UTC' ) ) )->modify( '+' . ( (int) $fixed - 1 ) . ' days' );
	return function_exists( 'wp_date' ) ? wp_date( 'M j', $date->getTimestamp(), new DateTimeZone( 'UTC' ) ) : $date->format( 'M j' );
}

/* ------------------------------------------------------------------
 * The shiurim for a parsha.
 * ------------------------------------------------------------------ */

/**
 * Term ID => the parshiyos its name stands for, for the terms of a taxonomy ('topic' or
 * 'series') that have shiurim. A " / " name counts for each part that is a parsha.
 *
 * @return array<int,int[]>
 */
function ner_michoel_parsha_term_map( $taxonomy ) {
	static $maps = array();
	if ( isset( $maps[ $taxonomy ] ) ) {
		return $maps[ $taxonomy ];
	}
	$map = array();
	if ( taxonomy_exists( $taxonomy ) ) {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => true,
			)
		);
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$found = array();
				foreach ( preg_split( '#\s+/\s+#u', html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ) ) as $part ) {
					$found = array_merge( $found, ner_michoel_parsha_match_name( $part ) );
				}
				if ( $found ) {
					$map[ (int) $term->term_id ] = array_values( array_unique( $found ) );
				}
			}
		}
	}
	$maps[ $taxonomy ] = $map;
	return $map;
}

/**
 * Written shiur ID => the parshiyos its title names, for the ones that name any. Kept for
 * twelve hours, and dropped when any post changes.
 *
 * @return array<int,int[]>
 */
function ner_michoel_parsha_written_titles() {
	if ( ! post_type_exists( 'written_shiur' ) ) {
		return array();
	}
	$salt   = function_exists( 'wp_cache_get_last_changed' ) ? wp_cache_get_last_changed( 'posts' ) : (string) wp_count_posts( 'written_shiur' )->publish;
	$name   = 'nm_parsha_titles_' . md5( (string) $salt );
	$stored = get_transient( $name );
	if ( is_array( $stored ) ) {
		return $stored;
	}

	global $wpdb;
	$rows = $wpdb->get_results( "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'written_shiur' AND post_status = 'publish'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one small read, cached below.
	$map  = array();
	foreach ( (array) $rows as $row ) {
		$found = ner_michoel_parsha_in_title( $row->post_title );
		if ( $found ) {
			$map[ (int) $row->ID ] = $found;
		}
	}
	set_transient( $name, $map, 12 * HOUR_IN_SECONDS );
	return $map;
}

/**
 * The shiurim for some parshiyos: topics and series that name them, plus written shiurim
 * whose titles do. Newest first.
 *
 * @param int[] $numbers  Parsha numbers.
 * @param int   $per_page How many to return.
 * @return array {posts: WP_Post[], count: int, topics: int[] topic IDs found}
 */
function ner_michoel_parsha_shiurim( array $numbers, $per_page = 6 ) {
	$topic_ids  = array();
	$series_ids = array();
	foreach ( ner_michoel_parsha_term_map( 'topic' ) as $id => $has ) {
		if ( array_intersect( $has, $numbers ) ) {
			$topic_ids[] = $id;
		}
	}
	foreach ( ner_michoel_parsha_term_map( 'series' ) as $id => $has ) {
		if ( array_intersect( $has, $numbers ) ) {
			$series_ids[] = $id;
		}
	}
	$title_ids = array();
	foreach ( ner_michoel_parsha_written_titles() as $id => $has ) {
		if ( array_intersect( $has, $numbers ) ) {
			$title_ids[] = $id;
		}
	}

	$by_term = array( 'relation' => 'OR' );
	if ( $topic_ids ) {
		$by_term[] = array(
			'taxonomy' => 'topic',
			'field'    => 'term_id',
			'terms'    => $topic_ids,
		);
	}
	if ( $series_ids ) {
		$by_term[] = array(
			'taxonomy' => 'series',
			'field'    => 'term_id',
			'terms'    => $series_ids,
		);
	}

	$base  = array(
		'post_status'         => 'publish',
		'orderby'             => 'date',
		'order'               => 'DESC',
		'ignore_sticky_posts' => true,
		'posts_per_page'      => (int) $per_page,
	);
	$posts = array();
	$count = 0;

	if ( count( $by_term ) > 1 ) {
		$query = new WP_Query(
			array_merge(
				$base,
				array(
					'post_type' => array( 'shiur', 'written_shiur' ),
					'tax_query' => $by_term, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				)
			)
		);
		$posts = $query->posts;
		$count = (int) $query->found_posts;
	}

	if ( $title_ids ) {
		// Written shiurim named by their titles, the ones the terms didn't already give.
		$extra_args = array_merge(
			$base,
			array(
				'post_type' => 'written_shiur',
				'post__in'  => $title_ids,
			)
		);
		if ( count( $by_term ) > 1 ) {
			$extra_args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				'relation' => 'AND',
			);
			foreach ( array_slice( $by_term, 1 ) as $clause ) {
				$clause['operator']        = 'NOT IN';
				$extra_args['tax_query'][] = $clause;
			}
		}
		$extra = new WP_Query( $extra_args );
		$count += (int) $extra->found_posts;
		$posts  = array_merge( $posts, $extra->posts );
		usort(
			$posts,
			function ( $a, $b ) {
				return strcmp( $b->post_date_gmt . $b->ID, $a->post_date_gmt . $a->ID );
			}
		);
		$posts = array_slice( $posts, 0, (int) $per_page );
	}

	return array(
		'posts'  => $posts,
		'count'  => $count,
		'topics' => $topic_ids,
	);
}

/**
 * This week's parsha for the homepage, or null when this Shabbos has no parsha of its own
 * (Yom Tov or Chol Hamoed), or when an editor has hidden the box (week-settings.php). The
 * week runs Sunday to Shabbos; an editor can preview another day with
 * ?nm_season_date=YYYY-MM-DD (topics.php). An editor can also choose a parsha by hand, for a
 * few days or until they change it back; then the box is that parsha's.
 *
 * Whose reading: Chutz La'aretz's, or Eretz Yisroel's when $israel is true. Left out (null),
 * it is the one the Homepage & Look > This Week screen says (the filter
 * 'ner_michoel_parsha_israel' can still decide: add_filter( 'ner_michoel_parsha_israel',
 * '__return_true' )). In the weeks they read different parshiyos, ner_michoel_get_week_regions()
 * gives both.
 *
 * Returns: numbers (parsha numbers), name ("Re'eh", "Mattos-Massei"), title ("Parshas
 * Re'eh"), shabbos (fixed day), date ("Oct 31"), hebrew ("19 Cheshvan 5787"), posts
 * (WP_Post[]), count (all its shiurim), link (the topic page with the most, or ''), pinned
 * (true when an editor chose it).
 *
 * @param int       $per_page How many shiurim to list.
 * @param bool|null $israel   Eretz Yisroel's reading instead of Chutz La'aretz's.
 * @return array|null
 */
function ner_michoel_get_week_parsha( $per_page = 6, $israel = null ) {
	if ( null === $israel ) {
		$israel = ner_michoel_week_default_israel();
	}
	$pin = ner_michoel_week_parsha_pin();
	if ( 'hide' === $pin ) {
		return null;
	}

	$today   = ner_michoel_season_today();
	$shabbos = ner_michoel_parsha_shabbos_on_or_after( $today );
	if ( is_array( $pin ) ) {
		return ner_michoel_week_parsha_box( $pin, $shabbos, $per_page, true );
	}

	$numbers = ner_michoel_parsha_of_shabbos( $shabbos, (bool) $israel );
	if ( ! $numbers ) {
		return null;
	}
	return ner_michoel_week_parsha_box( $numbers, $shabbos, $per_page, false );
}

/**
 * The box for some parshiyos: see ner_michoel_get_week_parsha(). A parsha an editor chose
 * has no Shabbos of its own to date, so its date and Hebrew date are empty.
 *
 * @param int[] $numbers  Parsha numbers.
 * @param int   $shabbos  Fixed day of this week's Shabbos.
 * @param int   $per_page How many shiurim to list.
 * @param bool  $pinned   Chosen by an editor.
 * @return array
 */
function ner_michoel_week_parsha_box( array $numbers, $shabbos, $per_page, $pinned ) {
	$all  = ner_michoel_parsha_names();
	$name = implode( '-', array_map( function ( $n ) use ( $all ) {
		return $all[ $n ];
	}, $numbers ) );

	$found = ner_michoel_parsha_shiurim( $numbers, $per_page );

	// The topic page to link to: the one named for just this parsha (or these) with the most shiurim.
	$link = '';
	$best = -1;
	foreach ( $found['topics'] as $term_id ) {
		$term = get_term( $term_id, 'topic' );
		if ( ! $term || is_wp_error( $term ) || $term->count <= $best ) {
			continue;
		}
		$whole = ner_michoel_parsha_match_name( html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ) );
		if ( $whole && ! array_diff( $whole, $numbers ) ) {
			$url = get_term_link( $term );
			if ( ! is_wp_error( $url ) ) {
				$link = $url;
				$best = (int) $term->count;
			}
		}
	}

	return array(
		'numbers' => $numbers,
		'name'    => $name,
		/* translators: %s: the parsha's name, such as Re'eh */
		'title'   => sprintf( __( 'Parshas %s', 'ner-michoel-core' ), $name ),
		'shabbos' => $shabbos,
		'date'    => $pinned ? '' : ner_michoel_fixed_date_label( $shabbos ),
		'hebrew'  => $pinned ? '' : ner_michoel_hebrew_date_label( $shabbos ),
		'posts'   => $found['posts'],
		// With a topic page to go to, the count is that page's, so "All 62 shiurim" matches what it lists.
		'count'   => ( '' !== $link && $best > 0 ) ? $best : $found['count'],
		'link'    => $link,
		'pinned'  => $pinned,
	);
}

/**
 * Both readings of this week, for the homepage's buttons between Chutz La'aretz and Eretz
 * Yisroel. They differ for the weeks after a Yom Tov that fell on Shabbos in Chutz La'aretz
 * only (a few weeks of most years), until Chutz La'aretz catches up.
 *
 * Returns: default ('diaspora' or 'israel': the one shown first), toggle (true when the two
 * differ, the buttons are on, and nobody chose the parsha by hand), diaspora and israel (each
 * as ner_michoel_get_week_parsha() returns it; when toggle is false they are the same).
 *
 * @param int $per_page How many shiurim to list.
 * @return array
 */
function ner_michoel_get_week_regions( $per_page = 6 ) {
	$israel  = ner_michoel_week_default_israel();
	$default = $israel ? 'israel' : 'diaspora';

	$shabbos = ner_michoel_parsha_shabbos_on_or_after( ner_michoel_season_today() );
	$differs = null === ner_michoel_week_parsha_pin()
		&& ner_michoel_parsha_of_shabbos( $shabbos, false ) !== ner_michoel_parsha_of_shabbos( $shabbos, true );

	if ( ! $differs || ! ner_michoel_week_toggle_on() ) {
		$week = ner_michoel_get_week_parsha( $per_page, $israel );
		return array(
			'default'  => $default,
			'toggle'   => false,
			'diaspora' => $week,
			'israel'   => $week,
		);
	}

	return array(
		'default'  => $default,
		'toggle'   => true,
		'diaspora' => ner_michoel_get_week_parsha( $per_page, false ),
		'israel'   => ner_michoel_get_week_parsha( $per_page, true ),
	);
}
