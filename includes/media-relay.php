<?php
/**
 * The backup route for media files.
 *
 * Shiur audio, PDFs and big pictures live on Bunny and visitors' browsers fetch
 * them from Bunny's address directly. A filter that approves this site but not
 * Bunny's address (a school, a workplace, a community filter) blocks that, and
 * the pages then load with nothing to play. The page script
 * (assets/media-fallback.js) notices, and asks for the file at media.php on this
 * site instead; this file is what media.php runs. This server asks Bunny for the
 * file and passes it on, so the visitor's browser only ever talks to this site.
 *
 * It is a backup, not the way files are normally delivered: every byte sent this
 * way uses one of the host's few PHP processes for as long as the transfer takes,
 * which is the cost Bunny exists to avoid. So:
 *
 *  - only visitors whose browser could not reach Bunny come here;
 *  - at most a handful of files are sent at once (a lock file per place); when all
 *    are busy the answer is 503 and the page script tries again in a moment, so a
 *    rush can never use up the processes the rest of the site needs;
 *  - a request for "everything from byte N on" gets a few megabytes, not the rest of
 *    the file: players ask for the next piece when they need it, and no process is
 *    held for the length of a listening session;
 *  - only files on this site's own Bunny host, only audio, video, picture and PDF
 *    types, never a redirect followed, so it can't be turned into a general proxy.
 *
 * This file is loaded by media.php with only WordPress's database layer started
 * (SHORTINIT), so nothing here may use the HTTP API, the plugin's other files or
 * anything that isn't in that minimal core. It uses cURL directly.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The file types the backup route will pass on, and the type to send when Bunny
 * gives a vague one.
 */
function ner_michoel_relay_types() {
	return array(
		'mp3'  => 'audio/mpeg',
		'm4a'  => 'audio/mp4',
		'aac'  => 'audio/aac',
		'wav'  => 'audio/wav',
		'ogg'  => 'audio/ogg',
		'oga'  => 'audio/ogg',
		'opus' => 'audio/ogg',
		'flac' => 'audio/flac',
		'mp4'  => 'video/mp4',
		'm4v'  => 'video/mp4',
		'mov'  => 'video/quicktime',
		'webm' => 'video/webm',
		'pdf'  => 'application/pdf',
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'gif'  => 'image/gif',
		'webp' => 'image/webp',
		'avif' => 'image/avif',
	);
}

/**
 * How much of a file one request gets when the player asks for "from here on".
 */
function ner_michoel_relay_chunk() {
	return 4 * 1024 * 1024;
}

/**
 * What the route needs from the options: whether it is on, Bunny's own address
 * (the pull zone's, which always works from this server) and how many files may
 * be sent at once. Reads the options directly so it works without the plugin.
 */
function ner_michoel_relay_settings() {
	$bunny = get_option( 'nm_bunny_storage', array() );
	$media = get_option( 'nm_media_delivery', array() );
	$bunny = is_array( $bunny ) ? $bunny : array();
	$media = is_array( $media ) ? $media : array();

	$host = isset( $bunny['pull_zone_hostname'] ) ? trim( (string) $bunny['pull_zone_hostname'], '/ ' ) : '';
	$on   = '' !== $host && ( ! array_key_exists( 'relay', $media ) || ! empty( $media['relay'] ) );
	$max  = isset( $media['relay_max'] ) ? (int) $media['relay_max'] : 6;

	return array(
		'on'   => $on,
		'host' => $host,
		'max'  => max( 1, min( 20, $max ) ),
	);
}

/**
 * https, always, except on a development copy of the site that says so in
 * wp-config.php (the tests talk to a fake Bunny on a plain port).
 */
function ner_michoel_relay_scheme() {
	if ( defined( 'NM_BUNNY_TEST_SCHEME' ) && in_array( NM_BUNNY_TEST_SCHEME, array( 'http', 'https' ), true )
		&& function_exists( 'wp_get_environment_type' ) && in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
		return NM_BUNNY_TEST_SCHEME;
	}
	return 'https';
}

/**
 * The file's path inside the Bunny zone, if it is one this route may fetch;
 * '' if not. $raw is the path as the browser's address had it, already decoded
 * once ("2026/05/shiur one.mp3"). Letters of any language, digits and a few
 * file-name marks; no dots-only parts, no query or fragment characters, and only
 * the media types above.
 */
function ner_michoel_relay_clean_path( $raw ) {
	if ( ! is_string( $raw ) || '' === $raw || strlen( $raw ) > 600 || ! preg_match( '//u', $raw ) ) {
		return '';
	}
	if ( preg_match( '/[\x00-\x1F\x7F\\\\?#"\'<>|*:%]/', $raw ) ) {
		return '';
	}
	$segments = explode( '/', ltrim( $raw, '/' ) );
	foreach ( $segments as $segment ) {
		if ( '' === $segment || '.' === $segment || '..' === $segment || ! preg_match( '/^[\p{L}\p{M}\p{N} ._\-()\[\]+,@=~!&]+$/u', $segment ) ) {
			return '';
		}
	}
	$path = implode( '/', $segments );
	$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
	$all  = ner_michoel_relay_types();
	if ( '' === $ext || ! isset( $all[ $ext ] ) ) {
		return '';
	}
	return $path;
}

/**
 * The address of that path on Bunny's own host.
 */
function ner_michoel_relay_upstream_url( $host, $path ) {
	return ner_michoel_relay_scheme() . '://' . $host . '/' . implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) );
}

/**
 * The part of the file to ask Bunny for, from the browser's Range header: the
 * same range, but never more than one chunk. '' means no range (send it all).
 * One range only; anything else is treated as no range.
 */
function ner_michoel_relay_range( $header, $chunk = 0 ) {
	$chunk  = $chunk > 0 ? (int) $chunk : ner_michoel_relay_chunk();
	$header = trim( (string) $header );
	if ( '' === $header || ! preg_match( '/^bytes=(\d{0,15})-(\d{0,15})$/', $header, $m ) ) {
		return '';
	}
	if ( '' === $m[1] && '' === $m[2] ) {
		return '';
	}
	if ( '' === $m[1] ) { // The last N bytes.
		$n = min( (int) $m[2], $chunk );
		return $n > 0 ? 'bytes=-' . $n : '';
	}
	$start = (int) $m[1];
	$end   = '' === $m[2] ? $start + $chunk - 1 : min( (int) $m[2], $start + $chunk - 1 );
	if ( $end < $start ) {
		return '';
	}
	return 'bytes=' . $start . '-' . $end;
}

/**
 * Takes one of the $max places for sending a file. The place is a lock file that
 * the operating system releases when this process ends, however it ends, so a
 * crashed transfer can't leave a place stuck. Returns the place's number, or -1
 * when all are taken. If no lock file can be made at all it lets the request
 * through (better than refusing everyone).
 */
function ner_michoel_relay_take_slot( $max ) {
	$dir = rtrim( sys_get_temp_dir(), '/\\' );
	$tag = substr( md5( ABSPATH ), 0, 8 );
	$any = false;
	for ( $i = 0; $i < $max; $i++ ) {
		$fp = @fopen( $dir . '/nm-relay-' . $tag . '-' . $i . '.lock', 'c' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fp ) {
			continue;
		}
		$any = true;
		if ( flock( $fp, LOCK_EX | LOCK_NB ) ) {
			$GLOBALS['nm_relay_slot'] = $fp; // Kept open until the process ends.
			return $i;
		}
		fclose( $fp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}
	return $any ? -1 : 0;
}

/**
 * A short plain-text answer for a request that can't be served.
 */
function ner_michoel_relay_fail( $status, $message, $extra_header = '' ) {
	if ( ! headers_sent() ) {
		http_response_code( $status );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Cache-Control: no-store, max-age=0' );
		header( 'X-Robots-Tag: noindex' );
		if ( '' !== $extra_header ) {
			header( $extra_header );
		}
	}
	echo $message; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	return array(
		'status' => $status,
		'bytes'  => 0,
		'busy'   => 503 === $status,
	);
}

/**
 * Sends one file from Bunny to the browser. $raw_path is the file's path in the
 * zone as given by the page; $opts['filename'] makes it a download with that name.
 * Sends everything itself (status, headers, body) and returns
 * array( 'status' => int, 'bytes' => int, 'busy' => bool ); the caller ends the request.
 */
function ner_michoel_relay_serve( $raw_path, $opts = array() ) {
	$cfg = ner_michoel_relay_settings();
	if ( ! $cfg['on'] ) {
		return ner_michoel_relay_fail( 404, 'This is not switched on.' );
	}
	if ( ! function_exists( 'curl_init' ) ) {
		return ner_michoel_relay_fail( 501, 'This server cannot fetch files for you.' );
	}
	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
	if ( 'GET' !== $method && 'HEAD' !== $method ) {
		return ner_michoel_relay_fail( 405, 'GET or HEAD only.', 'Allow: GET, HEAD' );
	}
	$path = ner_michoel_relay_clean_path( $raw_path );
	if ( '' === $path ) {
		return ner_michoel_relay_fail( 400, 'That is not a file this site can send.' );
	}
	if ( ner_michoel_relay_take_slot( $cfg['max'] ) < 0 ) {
		return ner_michoel_relay_fail( 503, 'Busy right now. Please try again in a moment.', 'Retry-After: 3' );
	}

	// Nothing may be buffered or squeezed on the way: the body is passed on as it arrives.
	while ( ob_get_level() > 0 ) {
		@ob_end_clean(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
	@ini_set( 'zlib.output_compression', '0' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.Risky
	if ( function_exists( 'apache_setenv' ) ) {
		@apache_setenv( 'no-gzip', '1' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
	@set_time_limit( 900 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

	$headers = array( 'Accept-Encoding: identity', 'User-Agent: NerMichoelMediaRoute/1' );
	$range   = ner_michoel_relay_range( isset( $_SERVER['HTTP_RANGE'] ) ? (string) $_SERVER['HTTP_RANGE'] : '' );
	if ( '' !== $range ) {
		$headers[] = 'Range: ' . $range;
	}
	foreach ( array(
		'HTTP_IF_RANGE'          => 'If-Range',
		'HTTP_IF_NONE_MATCH'     => 'If-None-Match',
		'HTTP_IF_MODIFIED_SINCE' => 'If-Modified-Since',
	) as $key => $name ) {
		if ( ! empty( $_SERVER[ $key ] ) && is_string( $_SERVER[ $key ] ) ) {
			$headers[] = $name . ': ' . substr( preg_replace( '/[\r\n]+/', '', $_SERVER[ $key ] ), 0, 200 );
		}
	}

	$types = ner_michoel_relay_types();
	$ext   = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
	$resp  = array(
		'status'  => 0,
		'headers' => array(),
		'sent'    => false,
	);
	$bytes = 0;

	// Passes Bunny's status and the headers that matter on to the browser. False when
	// what Bunny sent is not a file this route should pass on.
	$send_headers = function () use ( &$resp, $opts, $types, $ext ) {
		$status = $resp['status'];
		$h      = $resp['headers'];
		if ( 200 !== $status && 206 !== $status ) {
			return false;
		}
		// What Bunny calls the file is used only to refuse an answer that is not media at all (an
		// HTML error page). The type sent on is always the one for the file's own extension, so a
		// file can never reach the browser as something it would run (an SVG, HTML).
		$upstream = isset( $h['content-type'] ) ? strtolower( trim( explode( ';', $h['content-type'] )[0] ) ) : '';
		if ( '' !== $upstream && 'application/octet-stream' !== $upstream && 'binary/octet-stream' !== $upstream
			&& ! preg_match( '#^(audio|video|image)/[a-z0-9.+-]+$#', $upstream ) && 'application/pdf' !== $upstream ) {
			return false;
		}
		$type = $types[ $ext ];
		http_response_code( $status );
		header( 'Content-Type: ' . $type );
		foreach ( array(
			'content-length' => 'Content-Length',
			'content-range'  => 'Content-Range',
			'accept-ranges'  => 'Accept-Ranges',
			'etag'           => 'ETag',
			'last-modified'  => 'Last-Modified',
		) as $key => $name ) {
			if ( isset( $h[ $key ] ) && ! preg_match( '/[\r\n]/', $h[ $key ] ) ) {
				header( $name . ': ' . $h[ $key ] );
			}
		}
		if ( ! isset( $h['accept-ranges'] ) ) {
			header( 'Accept-Ranges: bytes' );
		}
		header( 'Cache-Control: public, max-age=86400' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex' );
		if ( ! empty( $opts['filename'] ) ) {
			$name     = (string) $opts['filename'];
			$fallback = trim( preg_replace( '/[^A-Za-z0-9._ -]+/', '_', $name ) );
			header( 'Content-Disposition: attachment; filename="' . ( '' === $fallback ? 'download' : $fallback ) . '"; filename*=UTF-8\'\'' . rawurlencode( $name ) );
		}
		$resp['sent'] = true;
		return true;
	};

	$ch = curl_init( ner_michoel_relay_upstream_url( $cfg['host'], $path ) );
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_TIMEOUT        => 900,
			CURLOPT_BUFFERSIZE     => 65536,
			CURLOPT_NOBODY         => 'HEAD' === $method,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_HEADERFUNCTION => function ( $c, $line ) use ( &$resp ) {
				if ( preg_match( '#^HTTP/\S+\s+(\d{3})#', $line, $m ) ) {
					$resp['status']  = (int) $m[1];
					$resp['headers'] = array();
				} elseif ( false !== strpos( $line, ':' ) ) {
					list( $name, $value ) = explode( ':', $line, 2 );

					$resp['headers'][ strtolower( trim( $name ) ) ] = trim( $value );
				}
				return strlen( $line );
			},
			CURLOPT_WRITEFUNCTION  => function ( $c, $data ) use ( &$resp, &$bytes, $send_headers ) {
				if ( ! $resp['sent'] && ! $send_headers() ) {
					return 0; // Not a file we pass on: stop; the answer is made below.
				}
				echo $data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$bytes += strlen( $data );
				flush();
				return connection_aborted() ? 0 : strlen( $data );
			},
		)
	);
	curl_exec( $ch );
	curl_close( $ch );

	if ( $resp['sent'] ) {
		return array(
			'status' => $resp['status'],
			'bytes'  => $bytes,
			'busy'   => false,
		);
	}
	// Nothing was sent yet: a HEAD answer, "not changed", "no such range", or a failure.
	if ( ( 200 === $resp['status'] || 206 === $resp['status'] ) && 'HEAD' === $method && $send_headers() ) {
		return array(
			'status' => $resp['status'],
			'bytes'  => 0,
			'busy'   => false,
		);
	}
	if ( 304 === $resp['status'] ) {
		http_response_code( 304 );
		foreach ( array( 'etag' => 'ETag', 'last-modified' => 'Last-Modified' ) as $key => $name ) {
			if ( isset( $resp['headers'][ $key ] ) && ! preg_match( '/[\r\n]/', $resp['headers'][ $key ] ) ) {
				header( $name . ': ' . $resp['headers'][ $key ] );
			}
		}
		return array(
			'status' => 304,
			'bytes'  => 0,
			'busy'   => false,
		);
	}
	if ( 416 === $resp['status'] ) {
		http_response_code( 416 );
		if ( isset( $resp['headers']['content-range'] ) && ! preg_match( '/[\r\n]/', $resp['headers']['content-range'] ) ) {
			header( 'Content-Range: ' . $resp['headers']['content-range'] );
		}
		return array(
			'status' => 416,
			'bytes'  => 0,
			'busy'   => false,
		);
	}
	if ( 404 === $resp['status'] || 403 === $resp['status'] || 410 === $resp['status'] ) {
		return ner_michoel_relay_fail( 404, 'No such file.' );
	}
	return ner_michoel_relay_fail( 502, 'The file could not be fetched just now.' );
}
