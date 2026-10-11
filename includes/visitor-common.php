<?php
/**
 * The parts of visitor counting that both counters share: the light one
 * (beacon.php, which starts only WordPress's database layer, about a tenth of
 * the work of a full start) and the REST route ner-michoel/v1/pageview
 * (site-stats.php), which visitors fall back to when the light one can't be
 * reached.
 *
 * Plain PHP and $wpdb only, nothing that SHORTINIT leaves out (no options API,
 * no hooks, no user functions), so beacon.php can include this file.
 *
 * What a visit stores:
 * - a page view (nm_pageviews), as before: the address, the outside site that
 *   sent the visitor, a daily visitor hash, and the load time;
 * - "who is here now" (nm_presence): one row per open tab, refreshed every 50
 *   seconds while the tab is in view or playing, deleted when the tab closes;
 * - once a minute, a count of the rows above (nm_live_minutes): how many people
 *   were on the site and how many were listening. The Speed & Capacity screen
 *   draws its live chart and its capacity numbers from it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** How often an open tab says it's still there, in seconds. Under 60, so every minute gets a count. */
define( 'NER_MICHOEL_PING_SECONDS', 50 );

/** A tab not heard from for this long has left. Covers a ping or two lost on a phone. */
define( 'NER_MICHOEL_PRESENCE_WINDOW', 150 );

/** Most page views one visitor can add in an hour. A person never gets near it; a script would. */
define( 'NER_MICHOEL_PAGEVIEWS_PER_HOUR', 300 );

/**
 * The tables, by job.
 */
function ner_michoel_visitor_tables() {
	global $wpdb;
	return array(
		'pageviews' => $wpdb->prefix . 'nm_pageviews',
		'presence'  => $wpdb->prefix . 'nm_presence',
		'live'      => $wpdb->prefix . 'nm_live_minutes',
		'perf'      => $wpdb->prefix . 'nm_perf_minutes',
	);
}

/**
 * User-agent substrings for common crawlers, uptime monitors, and
 * link-preview fetchers — not exhaustive (no bot list ever is), just
 * enough to keep the obvious ones out of "visitor" counts.
 */
function ner_michoel_is_bot_user_agent( $user_agent ) {
	if ( '' === $user_agent ) {
		return true; // A real browser always sends one.
	}
	$needles = array(
		'bot', 'spider', 'crawl', 'slurp', 'facebookexternalhit', 'whatsapp',
		'telegrambot', 'pingdom', 'uptimerobot', 'bingpreview', 'ahrefs',
		'semrush', 'mj12bot', 'petalbot', 'yandex', 'headlesschrome',
		'phantomjs', 'python-requests', 'curl/', 'wget/',
	);
	$user_agent = strtolower( $user_agent );
	foreach ( $needles as $needle ) {
		if ( false !== strpos( $user_agent, $needle ) ) {
			return true;
		}
	}
	return false;
}

/**
 * The address a page view is stored under, minus anything that looks like a
 * secret. The page-view script sends the whole query string, and the list of top
 * pages in the Control Panel shows what is stored, so a password, a code, a name or an
 * email address that ever reached a web address (a form sent the wrong way, a
 * reset link) must not be kept. Whole parameters are dropped by name.
 */
function ner_michoel_pageview_clean_url( $url ) {
	$parts = explode( '?', $url, 2 );
	if ( 2 !== count( $parts ) ) {
		return $url;
	}

	$kept = array();
	foreach ( explode( '&', $parts[1] ) as $pair ) {
		if ( '' === $pair ) {
			continue;
		}
		$name = urldecode( current( explode( '=', $pair, 2 ) ) );
		if ( ! preg_match( '/pass|pwd|secret|token|code|otp|key|nonce|e-?mail|login|phone|^(first_|last_|user_|display_)?name$/i', $name ) ) {
			$kept[] = $pair;
		}
	}

	return $parts[0] . ( $kept ? '?' . implode( '&', $kept ) : '' );
}

/**
 * The secret mixed into the daily visitor hash: the same value wp_salt() gives
 * (AUTH_KEY and AUTH_SALT from wp-config.php, or the copies WordPress keeps in
 * the options table when those are missing), read without wp_salt() so the
 * light counter can use it too.
 */
function ner_michoel_visitor_salt() {
	static $salt = null;
	if ( null !== $salt ) {
		return $salt;
	}

	$default = 'put your unique phrase here';
	$key     = ( defined( 'AUTH_KEY' ) && AUTH_KEY && $default !== AUTH_KEY ) ? AUTH_KEY : '';
	$extra   = ( defined( 'AUTH_SALT' ) && AUTH_SALT && $default !== AUTH_SALT ) ? AUTH_SALT : '';

	if ( '' === $key || '' === $extra ) {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name IN ('auth_key', 'auth_salt')" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed names.
		foreach ( (array) $rows as $row ) {
			if ( 'auth_key' === $row->option_name && '' === $key ) {
				$key = (string) $row->option_value;
			} elseif ( 'auth_salt' === $row->option_name && '' === $extra ) {
				$extra = (string) $row->option_value;
			}
		}
	}

	$salt = $key . $extra;
	if ( '' === $salt ) {
		$salt = defined( 'DB_PASSWORD' ) ? md5( DB_NAME . DB_PASSWORD ) : 'nm';
	}
	return $salt;
}

/**
 * The browser's user-agent string as both counters store and hash it: no tags,
 * whitespace collapsed, at most 400 characters. (sanitize_text_field() reads an
 * option, which the light counter can't.)
 */
function ner_michoel_clean_user_agent( $raw ) {
	return substr( trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $raw ) ) ), 0, 400 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
}

/**
 * The daily visitor hash: the same person on the same day gives the same value,
 * and nothing in it can be turned back into an address or followed to the next day.
 */
function ner_michoel_visitor_hash( $ip, $user_agent ) {
	return md5( $ip . '|' . $user_agent . '|' . gmdate( 'Y-m-d' ) . '|' . ner_michoel_visitor_salt() );
}

/**
 * CPU time this PHP process has used so far, in milliseconds, or null where the
 * host doesn't say.
 */
function ner_michoel_cpu_ms() {
	if ( ! function_exists( 'getrusage' ) ) {
		return null;
	}
	$usage = getrusage();
	if ( ! is_array( $usage ) || ! isset( $usage['ru_utime.tv_sec'] ) ) {
		return null;
	}
	return ( $usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec'] ) * 1000 + ( $usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_usec'] ) / 1000;
}

/**
 * Adds one request to this minute's line for its kind in nm_perf_minutes: how
 * many, how long they took in total and at most, the CPU time, the database
 * queries, how many were by signed-in people, and how many took over a second.
 * One small write per request; the Speed & Capacity screen adds them up.
 */
function ner_michoel_perf_add( $kind, $wall_ms, $cpu_ms, $queries, $signed_in ) {
	global $wpdb;
	$tables = ner_michoel_visitor_tables();
	$wall   = max( 0, (int) round( $wall_ms ) );
	$cpu    = null === $cpu_ms ? $wall : max( 0, (int) round( $cpu_ms ) );

	$suppress = $wpdb->suppress_errors( true );
	$wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$tables['perf']} (bucket, kind, hits, signed_in, slow, wall_ms, wall_max, cpu_ms, queries)
			VALUES (%d, %s, 1, %d, %d, %d, %d, %d, %d)
			ON DUPLICATE KEY UPDATE hits = hits + 1, signed_in = signed_in + VALUES(signed_in), slow = slow + VALUES(slow),
				wall_ms = wall_ms + VALUES(wall_ms), wall_max = GREATEST(wall_max, VALUES(wall_max)), cpu_ms = cpu_ms + VALUES(cpu_ms), queries = queries + VALUES(queries)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			(int) floor( time() / 60 ),
			substr( (string) $kind, 0, 24 ),
			$signed_in ? 1 : 0,
			$wall > 1000 ? 1 : 0,
			$wall,
			$wall,
			$cpu,
			max( 0, (int) $queries )
		)
	);
	$wpdb->suppress_errors( $suppress );
}

/**
 * Handles one ping from a visitor's browser, from either counter. $data is the
 * JSON the script sent:
 *
 *   t        'view' (a page loaded), 'nav' (the theme's page router changed page),
 *            'beat' (still here), 'leave' (tab closed) or 'test' (the monitor's check).
 *   url      the page's path and query.        ref   the referring page (view only).
 *   load     the page's load time in ms (view). sid   the tab's random id.
 *   playing  1 while audio or video is playing. in    1 when signed in.
 *
 * $site_host is this site's host name, to leave out referrers from the site itself.
 * Returns array( 'ok' => false only when the database couldn't store it, 'next' =>
 * seconds until the next ping, 0 for "don't ping again" ).
 */
function ner_michoel_handle_visitor_ping( $data, $ip, $user_agent, $site_host ) {
	$type = isset( $data['t'] ) ? (string) $data['t'] : 'view';
	if ( ! in_array( $type, array( 'view', 'nav', 'beat', 'leave', 'test' ), true ) ) {
		return array( 'ok' => true, 'next' => 0 );
	}
	if ( 'test' === $type ) {
		return array( 'ok' => true, 'next' => NER_MICHOEL_PING_SECONDS );
	}
	if ( ner_michoel_is_bot_user_agent( $user_agent ) ) {
		return array( 'ok' => true, 'next' => 0 ); // Not counted, and no need to keep asking.
	}

	global $wpdb;
	$tables  = ner_michoel_visitor_tables();
	$now     = time();
	$visitor = ner_michoel_visitor_hash( $ip, $user_agent );
	$sid_raw = isset( $data['sid'] ) ? preg_replace( '/[^a-z0-9]/i', '', (string) $data['sid'] ) : '';
	$sid     = '' !== $sid_raw ? md5( $visitor . '|' . substr( $sid_raw, 0, 64 ) ) : '';

	$suppress = $wpdb->suppress_errors( true );

	if ( 'leave' === $type ) {
		if ( '' !== $sid ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$tables['presence']} WHERE sid = %s", $sid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$wpdb->suppress_errors( $suppress );
		return array( 'ok' => true, 'next' => 0 );
	}

	$url = isset( $data['url'] ) ? (string) $data['url'] : '';
	$url = substr( ner_michoel_pageview_clean_url( trim( strip_tags( $url ) ) ), 0, 255 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- wp_strip_all_tags() isn't loaded in the light counter.
	$ok  = true;

	if ( 'view' === $type || 'nav' === $type ) {
		$recent = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['pageviews']} WHERE visitor_hash = %s AND created_at >= %s", $visitor, gmdate( 'Y-m-d H:i:s', $now - HOUR_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( '' !== $url && $recent < NER_MICHOEL_PAGEVIEWS_PER_HOUR ) {
			$referrer_domain = null;
			$referrer        = 'view' === $type && isset( $data['ref'] ) ? (string) $data['ref'] : '';
			if ( '' !== $referrer ) {
				$referrer_host = parse_url( $referrer, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- wp_parse_url() isn't loaded in the light counter.
				if ( $referrer_host && strtolower( $referrer_host ) !== strtolower( $site_host ) ) {
					$referrer_domain = substr( preg_replace( '/^www\./', '', strtolower( $referrer_host ) ), 0, 255 );
				}
			}
			$load = isset( $data['load'] ) && is_numeric( $data['load'] ) && $data['load'] >= 0 && $data['load'] < 300000 && 'view' === $type ? (int) $data['load'] : null;

			$ok = false !== $wpdb->insert(
				$tables['pageviews'],
				array(
					'created_at'      => gmdate( 'Y-m-d H:i:s', $now ),
					'url'             => $url,
					'referrer_domain' => $referrer_domain,
					'visitor_hash'    => $visitor,
					'load_time_ms'    => $load,
				),
				array( '%s', '%s', '%s', '%s', '%d' )
			);
		}
	}

	if ( '' !== $sid ) {
		$playing = ! empty( $data['playing'] ) ? 1 : 0;
		$signed  = ! empty( $data['in'] ) ? 1 : 0;
		$path    = substr( '' !== $url ? $url : '/', 0, 191 );
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$tables['presence']} (sid, visitor_hash, first_seen, last_seen, playing, signed_in, path)
				VALUES (%s, %s, %d, %d, %d, %d, %s)
				ON DUPLICATE KEY UPDATE last_seen = VALUES(last_seen), playing = VALUES(playing), signed_in = VALUES(signed_in), path = VALUES(path)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$sid,
				$visitor,
				$now,
				$now,
				$playing,
				$signed,
				$path
			)
		);
		if ( $wpdb->last_error ) {
			$ok = false;
		}
	}

	ner_michoel_take_minute_count( $now );

	$wpdb->suppress_errors( $suppress );

	return array(
		'ok'   => $ok,
		'next' => ner_michoel_ping_interval( $now ),
	);
}

/**
 * Once a minute (the first ping of each minute does it): counts the open tabs
 * heard from in the last two and a half minutes, and how many of them are
 * playing, into nm_live_minutes, and forgets tabs gone for 15 minutes.
 */
function ner_michoel_take_minute_count( $now ) {
	global $wpdb;
	$tables = ner_michoel_visitor_tables();
	$bucket = (int) floor( $now / 60 );

	$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$tables['live']} (bucket, online, listening, signed_in) VALUES (%d, 0, 0, 0)", $bucket ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( 1 !== (int) $wpdb->rows_affected ) {
		return; // Another ping already counted this minute.
	}

	$row = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS online, COALESCE(SUM(playing), 0) AS listening, COALESCE(SUM(signed_in), 0) AS signed_in FROM {$tables['presence']} WHERE last_seen >= %d", $now - NER_MICHOEL_PRESENCE_WINDOW ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( $row ) {
		$wpdb->query( $wpdb->prepare( "UPDATE {$tables['live']} SET online = %d, listening = %d, signed_in = %d WHERE bucket = %d", (int) $row->online, (int) $row->listening, (int) $row->signed_in, $bucket ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$tables['presence']} WHERE last_seen < %d", $now - 15 * MINUTE_IN_SECONDS ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

/**
 * Seconds until a tab's next ping. Normally 50; with thousands of people on the
 * site the pings spread out, so counting them never becomes the load.
 */
function ner_michoel_ping_interval( $now ) {
	global $wpdb;
	$tables = ner_michoel_visitor_tables();
	$online = (int) $wpdb->get_var( $wpdb->prepare( "SELECT online FROM {$tables['live']} WHERE bucket = %d", (int) floor( $now / 60 ) - 1 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return NER_MICHOEL_PING_SECONDS * max( 1, min( 6, (int) ceil( $online / 3000 ) ) );
}
