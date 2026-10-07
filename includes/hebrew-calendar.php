<?php
/**
 * Hebrew calendar dates, in plain PHP (no calendar or intl extension needed).
 * Used by the seasonal homepage boxes (topics.php): a topic can show every year
 * between two Hebrew dates, such as 11 Tishrei to 23 Tishrei for Sukkos.
 *
 * The arithmetic is Reingold and Dershowitz's ("Calendrical Calculations"): the
 * molad of Tishrei with the four postponements of Rosh Hashanah, and day numbers
 * counted from 1 January of year 1 (the "fixed" day). Checked against PHP's own
 * calendar extension for every day from 1900 to 2100.
 *
 * Months are numbered as in that book: Nisan 1, Iyar 2, Sivan 3, Tammuz 4, Av 5,
 * Elul 6, Tishrei 7, Cheshvan 8, Kislev 9, Teves 10, Shevat 11, Adar 12 (Adar I in
 * a leap year), Adar II 13. A Hebrew year starts at Tishrei, so in one year the
 * months run 7 to 12 (or 13), then 1 to 6.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ner_michoel_hebrew_leap_year( $year ) {
	return ( ( 7 * $year + 1 ) % 19 ) < 7;
}

function ner_michoel_hebrew_last_month( $year ) {
	return ner_michoel_hebrew_leap_year( $year ) ? 13 : 12;
}

/**
 * Days from the start of the calendar to Rosh Hashanah of $year: the molad of
 * Tishrei, then the postponements (molad at or after midday; Tuesday 9h 204p in
 * a common year; Monday 15h 589p after a leap year; never Sunday, Wednesday or
 * Friday).
 */
function ner_michoel_hebrew_elapsed_days( $year ) {
	static $cache = array();
	if ( isset( $cache[ $year ] ) ) {
		return $cache[ $year ];
	}

	$cycle_year     = ( $year - 1 ) % 19;
	$months_elapsed = 235 * intdiv( $year - 1, 19 ) + 12 * $cycle_year + intdiv( 7 * $cycle_year + 1, 19 );
	$parts_elapsed  = 204 + 793 * ( $months_elapsed % 1080 );
	$hours_elapsed  = 5 + 12 * $months_elapsed + 793 * intdiv( $months_elapsed, 1080 ) + intdiv( $parts_elapsed, 1080 );
	$day            = 1 + 29 * $months_elapsed + intdiv( $hours_elapsed, 24 );
	$parts          = 1080 * ( $hours_elapsed % 24 ) + $parts_elapsed % 1080;

	if ( $parts >= 19440
		|| ( 2 === $day % 7 && $parts >= 9924 && ! ner_michoel_hebrew_leap_year( $year ) )
		|| ( 1 === $day % 7 && $parts >= 16789 && ner_michoel_hebrew_leap_year( $year - 1 ) ) ) {
		++$day;
	}
	if ( in_array( $day % 7, array( 0, 3, 5 ), true ) ) {
		++$day;
	}

	$cache[ $year ] = $day;
	return $day;
}

function ner_michoel_hebrew_days_in_year( $year ) {
	return ner_michoel_hebrew_elapsed_days( $year + 1 ) - ner_michoel_hebrew_elapsed_days( $year );
}

function ner_michoel_hebrew_month_length( $month, $year ) {
	$days = ner_michoel_hebrew_days_in_year( $year );

	if ( in_array( $month, array( 2, 4, 6, 10, 13 ), true )
		|| ( 8 === $month && 5 !== $days % 10 )   // Cheshvan has 30 days only in a full year.
		|| ( 9 === $month && 3 === $days % 10 )   // Kislev has 29 days in a short year.
		|| ( 12 === $month && ! ner_michoel_hebrew_leap_year( $year ) ) ) {
		return 29;
	}
	return 30;
}

/**
 * The fixed day number of a Hebrew date.
 */
function ner_michoel_hebrew_to_fixed( $month, $day, $year ) {
	$day_in_year = $day;
	if ( $month < 7 ) {
		$last = ner_michoel_hebrew_last_month( $year );
		for ( $m = 7; $m <= $last; $m++ ) {
			$day_in_year += ner_michoel_hebrew_month_length( $m, $year );
		}
		for ( $m = 1; $m < $month; $m++ ) {
			$day_in_year += ner_michoel_hebrew_month_length( $m, $year );
		}
	} else {
		for ( $m = 7; $m < $month; $m++ ) {
			$day_in_year += ner_michoel_hebrew_month_length( $m, $year );
		}
	}
	return $day_in_year + ner_michoel_hebrew_elapsed_days( $year ) - 1373429;
}

/**
 * The Hebrew date of a fixed day number: array( year, month, day ).
 */
function ner_michoel_hebrew_from_fixed( $fixed ) {
	$year = intdiv( $fixed + 1373429, 366 );
	while ( $fixed >= ner_michoel_hebrew_to_fixed( 7, 1, $year + 1 ) ) {
		++$year;
	}

	$month = ( $fixed < ner_michoel_hebrew_to_fixed( 1, 1, $year ) ) ? 7 : 1;
	while ( $fixed > ner_michoel_hebrew_to_fixed( $month, ner_michoel_hebrew_month_length( $month, $year ), $year ) ) {
		++$month;
	}

	return array( $year, $month, $fixed - ner_michoel_hebrew_to_fixed( $month, 1, $year ) + 1 );
}

/**
 * The fixed day number of a Gregorian date.
 */
function ner_michoel_fixed_from_gregorian( $year, $month, $day ) {
	$lengths = array( 31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31 );
	if ( ( 0 === $year % 4 && 0 !== $year % 100 ) || 0 === $year % 400 ) {
		$lengths[1] = 29;
	}

	$fixed = $day;
	for ( $m = 1; $m < $month; $m++ ) {
		$fixed += $lengths[ $m - 1 ];
	}
	$prior = $year - 1;
	return $fixed + 365 * $prior + intdiv( $prior, 4 ) - intdiv( $prior, 100 ) + intdiv( $prior, 400 );
}

/**
 * Month slugs, in the order of a Hebrew year (Tishrei first), with the names
 * shown in the admin and on the site. 'adar' is the Adar of Purim: Adar II in a
 * leap year.
 */
function ner_michoel_hebrew_months() {
	return array(
		'tishrei'  => __( 'Tishrei', 'ner-michoel-core' ),
		'cheshvan' => __( 'Cheshvan', 'ner-michoel-core' ),
		'kislev'   => __( 'Kislev', 'ner-michoel-core' ),
		'teves'    => __( 'Teves', 'ner-michoel-core' ),
		'shevat'   => __( 'Shevat', 'ner-michoel-core' ),
		'adar'     => __( 'Adar', 'ner-michoel-core' ),
		'nisan'    => __( 'Nisan', 'ner-michoel-core' ),
		'iyar'     => __( 'Iyar', 'ner-michoel-core' ),
		'sivan'    => __( 'Sivan', 'ner-michoel-core' ),
		'tammuz'   => __( 'Tammuz', 'ner-michoel-core' ),
		'av'       => __( 'Av', 'ner-michoel-core' ),
		'elul'     => __( 'Elul', 'ner-michoel-core' ),
	);
}

/**
 * The book's month number for a slug in a given year, or 0 for an unknown slug.
 */
function ner_michoel_hebrew_month_number( $slug, $year ) {
	$numbers = array(
		'tishrei'  => 7,
		'cheshvan' => 8,
		'kislev'   => 9,
		'teves'    => 10,
		'shevat'   => 11,
		'adar'     => 12,
		'nisan'    => 1,
		'iyar'     => 2,
		'sivan'    => 3,
		'tammuz'   => 4,
		'av'       => 5,
		'elul'     => 6,
	);
	if ( ! isset( $numbers[ $slug ] ) ) {
		return 0;
	}
	if ( 'adar' === $slug && ner_michoel_hebrew_leap_year( $year ) ) {
		return 13;
	}
	return $numbers[ $slug ];
}

/**
 * The fixed day of a month slug and day in a Hebrew year. A day past the end of
 * a short month (30 Cheshvan in a year where it has 29) counts as its last day.
 * Returns 0 for an unknown slug.
 */
function ner_michoel_hebrew_slug_to_fixed( $slug, $day, $year ) {
	$month = ner_michoel_hebrew_month_number( $slug, $year );
	if ( ! $month ) {
		return 0;
	}
	$day = max( 1, min( (int) $day, ner_michoel_hebrew_month_length( $month, $year ) ) );
	return ner_michoel_hebrew_to_fixed( $month, $day, $year );
}

/**
 * Whether a window that repeats every year (from one Hebrew month and day to
 * another) contains the fixed day $fixed. A window can run over the end of the
 * year, such as 1 Elul to 10 Tishrei.
 */
function ner_michoel_hebrew_window_contains( $from_slug, $from_day, $to_slug, $to_day, $fixed ) {
	$found = ner_michoel_hebrew_window_start( $from_slug, $from_day, $to_slug, $to_day, $fixed );
	return null !== $found;
}

/**
 * The fixed day on which the current run of a yearly window started, or null if
 * $fixed isn't inside one. Used to put the season that started most recently first.
 */
function ner_michoel_hebrew_window_start( $from_slug, $from_day, $to_slug, $to_day, $fixed ) {
	list( $year ) = ner_michoel_hebrew_from_fixed( $fixed );

	foreach ( array( $year - 1, $year ) as $start_year ) {
		$start = ner_michoel_hebrew_slug_to_fixed( $from_slug, $from_day, $start_year );
		$end   = ner_michoel_hebrew_slug_to_fixed( $to_slug, $to_day, $start_year );
		if ( ! $start || ! $end ) {
			return null;
		}
		if ( $end < $start ) {
			// Runs over the end of the Hebrew year: it ends in the next one.
			$end = ner_michoel_hebrew_slug_to_fixed( $to_slug, $to_day, $start_year + 1 );
		}
		if ( $fixed >= $start && $fixed <= $end ) {
			return $start;
		}
	}
	return null;
}
