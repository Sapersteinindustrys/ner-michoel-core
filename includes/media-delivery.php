<?php
/**
 * Where visitors get the media files from, and what happens when they can't.
 *
 * Two things, both for files that live on Bunny (bunny-storage.php):
 *
 * 1. The media address. Files are offered at Bunny's own address
 *    (tomo-website.b-cdn.net). A filter that approves this site by name may not
 *    approve that one, and the address says nothing about whose files they are. So
 *    the site prefers an address under its own name, media.<this site's address>,
 *    which Bunny can serve too (a CNAME record in the domain's DNS, plus the name
 *    added to the Pull Zone in bunny.net). The name is worked out from the site's
 *    current address every time, so it follows when the address changes; or an
 *    administrator types one. It is only used once a check has shown that it
 *    really works (it answers from Bunny, over https, with a valid certificate);
 *    until then, and whenever it stops working, Bunny's own address is used, so a
 *    new or moved domain can never leave the files unreachable. The check runs in
 *    the background every few minutes and when the screen is saved.
 *
 * 2. The backup route (media.php, media-relay.php, assets/media-fallback.js). For a
 *    visitor whose filter blocks Bunny whatever it is called, the page asks this
 *    site to fetch the file and pass it on.
 *
 * Settings: option nm_media_delivery (name, relay on/off, relay_max). The result of
 * the last check: option nm_media_delivery_state.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The small picture the checks and the visitors' browsers use to see whether the media address works. */
function ner_michoel_media_probe_path() {
	return 'nm-probe.png';
}

/** A 1 x 1 picture. A filter that swaps in its own "blocked" picture shows up as the wrong size. */
function ner_michoel_media_probe_bytes() {
	return base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
}

function ner_michoel_media_settings() {
	$s = wp_parse_args(
		get_option( 'nm_media_delivery', array() ),
		array(
			'name'      => '',
			'relay'     => true,
			'relay_max' => 6,
		)
	);
	return array(
		'name'      => (string) $s['name'],
		'relay'     => (bool) $s['relay'],
		'relay_max' => max( 1, min( 20, (int) $s['relay_max'] ) ),
	);
}

/**
 * A typed media address, cleaned. Returns array( name, error ): the host name in
 * lower case with no http://, path or trailing dot ('' for none), and a message
 * when it can't be an address.
 */
function ner_michoel_media_clean_name( $raw ) {
	$name = strtolower( trim( (string) $raw ) );
	if ( '' === $name ) {
		return array( '', '' );
	}
	$name = preg_replace( '~^[a-z][a-z0-9+.-]*://~', '', $name );
	$name = preg_replace( '~[/?#].*$~', '', $name );
	$name = rtrim( $name, '.' );
	if ( preg_match( '/[^\x20-\x7e]/', $name ) && function_exists( 'idn_to_ascii' ) ) {
		$ascii = idn_to_ascii( $name, 0, defined( 'INTL_IDNA_VARIANT_UTS46' ) ? INTL_IDNA_VARIANT_UTS46 : 0 );
		if ( is_string( $ascii ) && '' !== $ascii ) {
			$name = $ascii;
		}
	}
	if ( false !== strpos( $name, ':' ) || false !== strpos( $name, '@' ) ) {
		return array( '', __( 'Type just the address, like media.example.org: no http://, port or path.', 'ner-michoel-core' ) );
	}
	if ( ! preg_match( '/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{1,62}$/', $name ) ) {
		return array( '', __( 'That doesn’t look like a web address. It should look like media.example.org.', 'ner-michoel-core' ) );
	}
	return array( $name, '' );
}

/**
 * The media address that goes with a site address: "media." and the address
 * without a leading www. '' when the site has no real domain (an IP address,
 * localhost, a single word).
 */
function ner_michoel_media_name_for_host( $host ) {
	$host = rtrim( strtolower( trim( (string) $host ) ), '.' );
	$host = preg_replace( '/:\d+$/', '', $host );
	if ( '' === $host || false !== strpos( $host, ':' ) || false !== strpos( $host, '[' ) || preg_match( '/^\d{1,3}(\.\d{1,3}){3}$/', $host ) ) {
		return '';
	}
	if ( 0 === strpos( $host, 'www.' ) ) {
		$host = substr( $host, 4 );
	}
	if ( false === strpos( $host, '.' ) ) {
		return '';
	}
	list( $name, $error ) = ner_michoel_media_clean_name( 'media.' . $host );
	return '' === $error ? $name : '';
}

/**
 * The address this site is on right now, as visitors see it.
 */
function ner_michoel_media_site_host() {
	$host = wp_parse_url( home_url(), PHP_URL_HOST );
	return is_string( $host ) ? $host : '';
}

/**
 * The media address the site wants to use: the one an administrator typed, or
 * media.<the site's address> worked out now. '' when there is none to use.
 */
function ner_michoel_media_candidate() {
	$s = ner_michoel_media_settings();
	if ( '' !== $s['name'] ) {
		list( $name, $error ) = ner_michoel_media_clean_name( $s['name'] );
		return '' === $error ? $name : '';
	}
	return ner_michoel_media_name_for_host( ner_michoel_media_site_host() );
}

/**
 * What the last check found.
 */
function ner_michoel_media_state() {
	$state = wp_parse_args(
		get_option( 'nm_media_delivery_state', array() ),
		array(
			'candidate' => '',
			'bunny'     => '',
			'ok'        => false,
			'reason'    => '',
			'checked'   => 0,
			'probe'     => false,
		)
	);
	return $state;
}

/**
 * The address files are offered at right now: the media address when the last
 * check showed it works for the address the site has now, else Bunny's own.
 */
function ner_michoel_media_host( $reset = false ) {
	static $host = null;
	if ( $reset ) {
		$host = null;
		return '';
	}
	if ( null !== $host ) {
		return $host;
	}
	$settings = ner_michoel_bunny_storage_settings();
	$bunny    = trim( (string) $settings['pull_zone_hostname'], '/' );
	$host     = $bunny;
	if ( '' === $bunny ) {
		return $host;
	}
	$candidate = ner_michoel_media_candidate();
	if ( '' !== $candidate ) {
		$state = ner_michoel_media_state();
		if ( $state['candidate'] === $candidate && $state['bunny'] === $bunny && $state['ok'] ) {
			$host = $candidate;
		} elseif ( $state['candidate'] !== $candidate || $state['bunny'] !== $bunny ) {
			// The site's address (or Bunny's) changed since the last check: look again soon.
			ner_michoel_media_schedule_check( 15 );
		}
	}
	return $host;
}

/* ------------------------------------------------------------------ */
/* Checking the media address                                          */
/* ------------------------------------------------------------------ */

/**
 * One HEAD request, with what went wrong in a form the screen can explain.
 * Returns array( code, headers, error, message ): error is '' when the server
 * answered, else dns / cert / timeout / connect / other.
 */
function ner_michoel_media_head( $url ) {
	$r = wp_remote_head(
		$url,
		array(
			'timeout'     => 8,
			'redirection' => 0,
			'sslverify'   => true,
			'user-agent'  => 'NerMichoelMediaCheck/1',
		)
	);
	if ( is_wp_error( $r ) ) {
		$msg  = $r->get_error_message();
		$kind = 'other';
		if ( preg_match( '/cURL error (\d+)/', $msg, $m ) ) {
			$n = (int) $m[1];
			if ( 6 === $n ) {
				$kind = 'dns';
			} elseif ( in_array( $n, array( 35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91 ), true ) ) {
				$kind = 'cert';
			} elseif ( 28 === $n ) {
				$kind = 'timeout';
			} elseif ( 7 === $n ) {
				$kind = 'connect';
			}
		} elseif ( false !== stripos( $msg, 'resolve host' ) || false !== stripos( $msg, 'getaddrinfo' ) ) {
			$kind = 'dns';
		} elseif ( false !== stripos( $msg, 'certificate' ) || false !== stripos( $msg, 'ssl' ) ) {
			$kind = 'cert';
		}
		return array(
			'code'    => 0,
			'headers' => array(),
			'error'   => $kind,
			'message' => $msg,
		);
	}
	$headers = array();
	foreach ( array( 'server', 'content-type', 'content-length', 'cdn-pullzone' ) as $name ) {
		$value            = wp_remote_retrieve_header( $r, $name );
		$headers[ $name ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
	}
	return array(
		'code'    => (int) wp_remote_retrieve_response_code( $r ),
		'headers' => $headers,
		'error'   => '',
		'message' => '',
	);
}

/**
 * Does the answer come from Bunny? Bunny names itself in the Server header and
 * sends CDN-PullZone; the picture's size is the last resort.
 */
function ner_michoel_media_is_bunny( $answer ) {
	$h = $answer['headers'];
	return ( ! empty( $h['server'] ) && false !== stripos( $h['server'], 'bunny' ) )
		|| ! empty( $h['cdn-pullzone'] )
		|| ( ! empty( $h['content-length'] ) && (int) $h['content-length'] === strlen( ner_michoel_media_probe_bytes() ) );
}

/**
 * Is the small test picture on Bunny's own address? Puts it there if it isn't
 * and the storage key is set.
 */
function ner_michoel_media_probe_ready() {
	$settings = ner_michoel_bunny_storage_settings();
	$url      = ner_michoel_bunny_scheme() . '://' . trim( (string) $settings['pull_zone_hostname'], '/' ) . '/' . ner_michoel_media_probe_path();

	$answer = ner_michoel_media_head( $url );
	if ( 200 === $answer['code'] ) {
		return true;
	}
	if ( ! ner_michoel_bunny_storage_is_configured() ) {
		return false;
	}
	$tmp = tempnam( get_temp_dir(), 'nmprobe' );
	if ( ! $tmp ) {
		return false;
	}
	file_put_contents( $tmp, ner_michoel_media_probe_bytes() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	$sent = ner_michoel_bunny_upload_file( $tmp, ner_michoel_media_probe_path() );
	wp_delete_file( $tmp );
	if ( ! $sent ) {
		return false;
	}
	$answer = ner_michoel_media_head( $url . '?t=' . time() );
	return 200 === $answer['code'];
}

/**
 * Looks at the media address and records what it found. Returns the new state.
 * Sends at most two small requests (one to Bunny's own address for the test
 * picture, one to the media address).
 */
function ner_michoel_media_check() {
	ner_michoel_media_host( true );
	$before = ner_michoel_media_host();

	$settings  = ner_michoel_bunny_storage_settings();
	$bunny     = trim( (string) $settings['pull_zone_hostname'], '/' );
	$candidate = ner_michoel_media_candidate();
	$state     = array(
		'candidate' => $candidate,
		'bunny'     => $bunny,
		'ok'        => false,
		'reason'    => '',
		'checked'   => time(),
		'probe'     => false,
	);

	if ( '' === $bunny ) {
		$state['reason'] = 'no-bunny';
	} else {
		$state['probe'] = ner_michoel_media_probe_ready();
		if ( '' === $candidate ) {
			$state['reason'] = 'no-name';
		} elseif ( ! $state['probe'] ) {
			$state['reason'] = 'no-probe';
		} else {
			$answer = ner_michoel_media_head( ner_michoel_bunny_scheme() . '://' . $candidate . '/' . ner_michoel_media_probe_path() );
			if ( '' !== $answer['error'] ) {
				$state['reason'] = in_array( $answer['error'], array( 'dns', 'cert' ), true ) ? $answer['error'] : 'unreachable';
			} elseif ( 200 === $answer['code'] && ner_michoel_media_is_bunny( $answer ) ) {
				$state['ok']     = true;
				$state['reason'] = 'ok';
			} elseif ( 200 === $answer['code'] ) {
				$state['reason'] = 'not-bunny';
			} elseif ( ner_michoel_media_is_bunny( $answer ) ) {
				$state['reason'] = 'not-added';
			} else {
				$state['reason'] = 'not-bunny';
			}
		}
	}

	update_option( 'nm_media_delivery_state', $state );
	ner_michoel_media_host( true );
	$after = ner_michoel_media_host();

	// Addresses in the library's cached lists changed with the host.
	if ( $before !== $after && function_exists( 'ner_michoel_library_changed' ) ) {
		ner_michoel_library_changed();
	}

	// The next look: soon if the name isn't working yet, later if it is. Replaces any
	// look already waiting (the one asked for when the address changed, say).
	wp_clear_scheduled_hook( 'nm_media_host_check' );
	if ( '' !== $candidate && '' !== $bunny ) {
		ner_michoel_media_schedule_check( $state['ok'] ? 1800 : 600 );
	}
	return $state;
}

function ner_michoel_media_schedule_check( $delay ) {
	if ( ! wp_next_scheduled( 'nm_media_host_check' ) ) {
		wp_schedule_single_event( time() + max( 5, (int) $delay ), 'nm_media_host_check' );
	}
}

function ner_michoel_media_check_cron() {
	ner_michoel_media_check();
}
add_action( 'nm_media_host_check', 'ner_michoel_media_check_cron' );

/**
 * Keeps a check scheduled while there is a media address to watch, so one that
 * stops working (a certificate that lapses, a record that's removed) is noticed.
 */
function ner_michoel_media_keep_scheduled() {
	$settings = ner_michoel_bunny_storage_settings();
	if ( '' === trim( (string) $settings['pull_zone_hostname'], '/' ) || '' === ner_michoel_media_candidate() ) {
		return;
	}
	ner_michoel_media_schedule_check( 120 );
}
add_action( 'init', 'ner_michoel_media_keep_scheduled', 20 );

/* ------------------------------------------------------------------ */
/* What the screen says                                                */
/* ------------------------------------------------------------------ */

function ner_michoel_media_reason_text( $reason, $bunny ) {
	switch ( $reason ) {
		case 'dns':
			return __( 'the name isn’t in the domain’s DNS yet (no record found for it).', 'ner-michoel-core' );
		case 'cert':
			return __( 'the name reaches Bunny but has no valid security certificate yet. In bunny.net, add the name to the Pull Zone’s hostnames and press “Get free certificate”.', 'ner-michoel-core' );
		case 'not-added':
			return __( 'the name reaches Bunny but isn’t added to the Pull Zone yet. In bunny.net: Pull Zone → Hostnames → Add hostname.', 'ner-michoel-core' );
		case 'not-bunny':
			/* translators: %s: Bunny's own address */
			return sprintf( __( 'the name answers, but not from Bunny. Check that its DNS record points to %s.', 'ner-michoel-core' ), $bunny );
		case 'unreachable':
			return __( 'the name didn’t answer in time.', 'ner-michoel-core' );
		case 'no-probe':
			return __( 'Bunny’s small test picture isn’t there yet. Check the storage details above.', 'ner-michoel-core' );
		default:
			return '';
	}
}

/**
 * The relay's last 24 hours, from the request timing table: array( sent, turned_away ), or null.
 */
function ner_michoel_media_relay_numbers() {
	global $wpdb;
	if ( ! function_exists( 'ner_michoel_visitor_tables' ) ) {
		return null;
	}
	$t        = ner_michoel_visitor_tables();
	$suppress = $wpdb->suppress_errors( true );
	$rows     = $wpdb->get_results( $wpdb->prepare( "SELECT kind, SUM(hits) AS hits FROM {$t['perf']} WHERE bucket >= %d AND kind IN ('relay', 'relay-busy') GROUP BY kind", (int) floor( time() / 60 ) - 1440 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->suppress_errors( $suppress );
	$out = array(
		'sent'         => 0,
		'turned_away'  => 0,
	);
	foreach ( (array) $rows as $row ) {
		$out['relay' === $row->kind ? 'sent' : 'turned_away'] = (int) $row->hits;
	}
	return $out;
}

/**
 * The lines at the top of the Storage screen: where files come from now, why not
 * the media address yet, what to do about it, and the backup route's numbers.
 */
function ner_michoel_media_status_lines() {
	$settings = ner_michoel_bunny_storage_settings();
	$bunny    = trim( (string) $settings['pull_zone_hostname'], '/' );
	$media    = ner_michoel_media_settings();
	$lines    = array();

	if ( '' === $bunny ) {
		$lines[] = __( 'Files are not on Bunny yet, so visitors get them from this site.', 'ner-michoel-core' );
		return $lines;
	}

	$candidate = ner_michoel_media_candidate();
	$state     = ner_michoel_media_state();
	ner_michoel_media_host( true );
	$host = ner_michoel_media_host();

	if ( $host === $candidate ) {
		/* translators: %s: the media address */
		$lines[] = sprintf( __( 'Visitors get shiur audio, PDFs and big pictures from %s, an address under this site’s own name.', 'ner-michoel-core' ), $host );
	} else {
		/* translators: %s: Bunny's own address */
		$lines[] = sprintf( __( 'Visitors get shiur audio, PDFs and big pictures from %s (Bunny’s own address).', 'ner-michoel-core' ), $host );
	}

	if ( '' === $candidate ) {
		$lines[] = sprintf(
			/* translators: %s: the site's address */
			__( 'This site’s address (%s) isn’t a real domain yet, so it can’t have its own media name. Once the site is on its real domain the name is worked out by itself, or type one below.', 'ner-michoel-core' ),
			ner_michoel_media_site_host()
		);
	} elseif ( $host !== $candidate ) {
		$why = ner_michoel_media_reason_text( $state['reason'], $bunny );
		if ( $state['candidate'] !== $candidate || ! $state['checked'] ) {
			/* translators: %s: the media address */
			$lines[] = sprintf( __( 'The name %s hasn’t been checked yet. The check runs by itself in a few minutes, or press “Check again”.', 'ner-michoel-core' ), $candidate );
		} else {
			/* translators: 1: the media address, 2: the reason */
			$lines[] = sprintf( __( 'The name %1$s isn’t ready: %2$s Until it is, everything keeps working through Bunny’s own address.', 'ner-michoel-core' ), $candidate, $why );
		}
		/* translators: 1: the media address, 2: Bunny's own address */
		$lines[] = sprintf( __( 'To switch it on: (1) in your domain’s DNS add a CNAME record named “%1$s” that points to %2$s; (2) in bunny.net open the Pull Zone, go to Hostnames, add %1$s and press “Get free certificate”; (3) press “Check again” here.', 'ner-michoel-core' ), $candidate, $bunny );
	} else {
		$lines[] = __( 'It is checked every half hour. If it ever stops working, Bunny’s own address takes over by itself.', 'ner-michoel-core' );
	}

	if ( $state['checked'] ) {
		/* translators: %s: how long ago, like "5 minutes" */
		$lines[] = sprintf( __( 'Last checked %s ago.', 'ner-michoel-core' ), human_time_diff( $state['checked'], time() ) );
	}

	if ( ! $media['relay'] ) {
		$lines[] = __( 'Backup route: off. A visitor whose filter blocks the file address can’t play or open files.', 'ner-michoel-core' );
	} else {
		$line   = sprintf(
			/* translators: %d: how many files may be sent at once */
			_n( 'Backup route: on, at most %d file at a time.', 'Backup route: on, at most %d files at a time.', $media['relay_max'], 'ner-michoel-core' ),
			$media['relay_max']
		);
		$counts = ner_michoel_media_relay_numbers();
		if ( $counts ) {
			$line .= ' ' . sprintf(
				/* translators: 1: requests sent, 2: requests turned away */
				__( 'Last 24 hours: %1$s requests were sent through this site, %2$s turned away because every place was busy.', 'ner-michoel-core' ),
				number_format_i18n( $counts['sent'] ),
				number_format_i18n( $counts['turned_away'] )
			);
		}
		$lines[] = $line;
	}
	return $lines;
}

/* ------------------------------------------------------------------ */
/* Saving                                                              */
/* ------------------------------------------------------------------ */

/**
 * The settings in a save request, checked. Returns the settings to store or a
 * WP_Error (nothing is stored until the whole request is good).
 */
function ner_michoel_media_validate_request( WP_REST_Request $request ) {
	$out  = ner_michoel_media_settings();
	$name = $request->get_param( 'media_name' );
	if ( null !== $name ) {
		list( $clean, $error ) = ner_michoel_media_clean_name( (string) $name );
		if ( '' !== $error ) {
			return new WP_Error( 'nm_media_name', $error, array( 'status' => 400 ) );
		}
		$out['name'] = $clean;
	}
	$relay = $request->get_param( 'relay' );
	if ( null !== $relay ) {
		$out['relay'] = (bool) $relay;
	}
	$max = $request->get_param( 'relay_max' );
	if ( null !== $max && '' !== $max ) {
		$out['relay_max'] = max( 1, min( 20, (int) $max ) );
	}
	return $out;
}

function ner_michoel_media_save( $settings ) {
	update_option( 'nm_media_delivery', $settings );
	ner_michoel_media_host( true );
}

/** The values the Storage screen shows for its media fields. */
function ner_michoel_media_values() {
	$s = ner_michoel_media_settings();
	return array(
		'media_name' => $s['name'],
		'relay'      => $s['relay'],
		'relay_max'  => $s['relay_max'],
	);
}

function ner_michoel_register_media_check_route() {
	register_rest_route(
		'ner-michoel/v1',
		'/media-check',
		array(
			'methods'             => 'POST',
			'callback'            => function () {
				$state = ner_michoel_media_check();
				return new WP_REST_Response(
					array(
						'ok'     => (bool) $state['ok'],
						'reason' => $state['reason'],
						'lines'  => ner_michoel_media_status_lines(),
					),
					200
				);
			},
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		)
	);
}
add_action( 'rest_api_init', 'ner_michoel_register_media_check_route' );

/* ------------------------------------------------------------------ */
/* The page script                                                     */
/* ------------------------------------------------------------------ */

/**
 * Puts the backup route's script in the head of every public page when files are
 * on Bunny and the route is on. The script does nothing until a file can't be
 * loaded from Bunny (or Bunny's test picture can't), so for most visitors it is a
 * few hundred bytes of waiting.
 */
function ner_michoel_media_enqueue() {
	$settings = ner_michoel_bunny_storage_settings();
	$bunny    = trim( (string) $settings['pull_zone_hostname'], '/' );
	$media    = ner_michoel_media_settings();
	if ( '' === $bunny || ! $media['relay'] ) {
		return;
	}

	$state = ner_michoel_media_state();
	$host  = ner_michoel_media_host();
	$hosts = array( $host, $bunny, ner_michoel_media_candidate(), $state['candidate'] );
	$hosts = array_values( array_unique( array_filter( array_map( 'strtolower', array_map( 'strval', $hosts ) ) ) ) );

	$config = array(
		'hosts' => $hosts,
		'relay' => esc_url_raw( NER_MICHOEL_CORE_URL . 'media.php' ),
		'probe' => $state['probe'] ? ner_michoel_bunny_scheme() . '://' . $host . '/' . ner_michoel_media_probe_path() : '',
	);

	wp_register_script( 'nm-media-fallback', NER_MICHOEL_CORE_URL . 'assets/media-fallback.js', array(), NER_MICHOEL_CORE_VERSION, false );
	wp_add_inline_script( 'nm-media-fallback', 'window.nmMedia=' . wp_json_encode( $config ) . ';', 'before' );
	wp_enqueue_script( 'nm-media-fallback' );
}
add_action( 'wp_enqueue_scripts', 'ner_michoel_media_enqueue', 1 );
