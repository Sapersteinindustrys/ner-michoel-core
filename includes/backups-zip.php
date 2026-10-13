<?php
/**
 * A zip file written one file at a time, which can stop between any two files and carry on in a later
 * request. The backups (backups.php) are built across many short background runs, because a host stops a
 * PHP request after a minute or so and a whole site takes longer than that to pack.
 *
 * A plain zip, no ZIP64: backups.php keeps each piece of a backup near 1 GB and fewer than 65,000 files,
 * and leaves out (and lists) any single file of 3.5 GB or more, so every number fits in 32 bits. Names are
 * UTF-8 (flag bit 11). Pictures, audio, video, PDFs and other zips are stored as they are (squeezing them
 * again saves nothing and costs the host's CPU); everything else is deflated.
 *
 * Each file's header is written with blank sizes, then filled in once the file is through (no data
 * descriptors, which some unzip tools handle badly). The central directory is collected in a second file
 * beside the zip and copied onto the end by close_archive().
 *
 * To carry on: state() gives array( 'path', 'cd', 'size', 'cd_size', 'entries' ), and open() with that
 * state cuts both files back to those sizes first, so anything a stopped request wrote after its last save
 * is dropped and written again.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NM_Backup_Zip {

	/** Largest single file: below 4 GB with room for the headers. */
	const MAX_FILE = 3758096384; // 3.5 GB

	/** Endings that are already compressed: stored, not deflated. */
	const STORED = 'jpg jpeg jpe png gif webp avif heic heif ico mp3 m4a m4b aac ogg oga opus flac wma mp4 m4v mov webm mkv avi wmv flv 3gp zip gz tgz bz2 xz 7z rar zst br woff woff2 pdf docx xlsx pptx odt ods odp epub jar apk';

	private $path;
	private $cd_path;
	private $fh;
	private $cdh;
	private $size;
	private $cd_size;
	private $entries;

	/**
	 * Opens a new zip at $path, or carries on with one from its saved $state.
	 *
	 * @return NM_Backup_Zip|WP_Error
	 */
	public static function open( $path, $state = null ) {
		$zip          = new self();
		$zip->path    = $path;
		$zip->cd_path = $path . '.cd';
		$zip->size    = $state ? (int) $state['size'] : 0;
		$zip->cd_size = $state ? (int) $state['cd_size'] : 0;
		$zip->entries = $state ? (int) $state['entries'] : 0;

		if ( $state ) {
			clearstatcache( true, $zip->path );
			clearstatcache( true, $zip->cd_path );
			if ( ! file_exists( $zip->path ) || filesize( $zip->path ) < $zip->size || ! file_exists( $zip->cd_path ) || filesize( $zip->cd_path ) < $zip->cd_size ) {
				return new WP_Error( 'nm_zip_lost', __( 'The part-made backup file on this server is missing or shorter than expected.', 'ner-michoel-core' ) );
			}
		}

		$zip->fh  = fopen( $zip->path, $state ? 'r+b' : 'w+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$zip->cdh = fopen( $zip->cd_path, $state ? 'r+b' : 'w+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $zip->fh || ! $zip->cdh ) {
			$zip->release();
			return new WP_Error( 'nm_zip_open', __( 'Couldn’t write the backup file on this server (is the disk full, or wp-content not writable?).', 'ner-michoel-core' ) );
		}

		// Drop whatever came after the last save.
		ftruncate( $zip->fh, $zip->size );
		ftruncate( $zip->cdh, $zip->cd_size );
		fseek( $zip->fh, $zip->size );
		fseek( $zip->cdh, $zip->cd_size );

		return $zip;
	}

	public function state() {
		return array(
			'path'    => $this->path,
			'cd'      => $this->cd_path,
			'size'    => $this->size,
			'cd_size' => $this->cd_size,
			'entries' => $this->entries,
		);
	}

	public function size() {
		return $this->size;
	}

	public function entries() {
		return $this->entries;
	}

	/**
	 * Whether a file of this name is deflated (true) or stored (false).
	 */
	public static function deflates( $name ) {
		$ext = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
		return '' === $ext || false === strpos( ' ' . self::STORED . ' ', ' ' . $ext . ' ' );
	}

	/**
	 * Adds the file at $abs under the name $name (forward slashes, no leading slash). Returns the number of
	 * bytes read, or a WP_Error (the zip is then exactly as it was before the call).
	 */
	public function add_file( $abs, $name, $mtime = null ) {
		$in = @fopen( $abs, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- an unreadable file is reported, not warned about.
		if ( ! $in ) {
			return new WP_Error( 'nm_zip_read', __( 'unreadable', 'ner-michoel-core' ) );
		}
		$result = $this->add_stream( $in, $name, null === $mtime ? (int) @filemtime( $abs ) : (int) $mtime, self::deflates( $name ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		fclose( $in );
		return $result;
	}

	/**
	 * Adds $data as a file named $name.
	 */
	public function add_string( $name, $data, $mtime = null ) {
		$in = fopen( 'php://temp', 'w+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $in, $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		rewind( $in );
		$result = $this->add_stream( $in, $name, null === $mtime ? time() : (int) $mtime, true );
		fclose( $in );
		return $result;
	}

	private function add_stream( $in, $name, $mtime, $deflate ) {
		$name   = ltrim( str_replace( '\\', '/', $name ), '/' );
		$offset = $this->size;
		$method = ( $deflate && function_exists( 'deflate_init' ) ) ? 8 : 0;
		$dos    = self::dos_time( $mtime );

		if ( strlen( $name ) > 65535 ) {
			return new WP_Error( 'nm_zip_name', __( 'name too long', 'ner-michoel-core' ) );
		}

		// Local header with blank CRC and sizes: filled in below.
		fseek( $this->fh, $offset );
		$header = pack( 'VvvvvvVVVvv', 0x04034b50, 20, 0x0800, $method, $dos[0], $dos[1], 0, 0, 0, strlen( $name ), 0 ) . $name;
		if ( ! $this->write( $this->fh, $header ) ) {
			return $this->undo( $offset, __( 'disk full?', 'ner-michoel-core' ) );
		}

		$crc    = hash_init( 'crc32b' );
		$z      = 8 === $method ? deflate_init( ZLIB_ENCODING_RAW, array( 'level' => 6 ) ) : null;
		$read   = 0;
		$packed = 0;

		while ( ! feof( $in ) ) {
			$chunk = fread( $in, 1048576 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( false === $chunk ) {
				return $this->undo( $offset, __( 'read error', 'ner-michoel-core' ) );
			}
			if ( '' === $chunk ) {
				break;
			}
			hash_update( $crc, $chunk );
			$read += strlen( $chunk );
			$out   = $z ? deflate_add( $z, $chunk, ZLIB_NO_FLUSH ) : $chunk;
			if ( '' !== $out ) {
				if ( ! $this->write( $this->fh, $out ) ) {
					return $this->undo( $offset, __( 'disk full?', 'ner-michoel-core' ) );
				}
				$packed += strlen( $out );
			}
			if ( $read > self::MAX_FILE ) {
				return $this->undo( $offset, __( 'too big for a zip', 'ner-michoel-core' ) );
			}
		}
		if ( $z ) {
			$out = deflate_add( $z, '', ZLIB_FINISH );
			if ( '' !== $out ) {
				if ( ! $this->write( $this->fh, $out ) ) {
					return $this->undo( $offset, __( 'disk full?', 'ner-michoel-core' ) );
				}
				$packed += strlen( $out );
			}
		}

		$crc_value = unpack( 'N', hash_final( $crc, true ) );
		$crc_value = $crc_value[1];

		// Fill in the header, then back to the end.
		fseek( $this->fh, $offset + 14 );
		if ( ! $this->write( $this->fh, pack( 'VVV', $crc_value, $packed, $read ) ) ) {
			return $this->undo( $offset, __( 'disk full?', 'ner-michoel-core' ) );
		}
		$end = $offset + strlen( $header ) + $packed;
		fseek( $this->fh, $end );

		// Its central directory record: made by Unix (3) zip 2.0, a plain file readable by all (0644).
		$record = pack(
			'VvvvvvvVVVvvvvvVV',
			0x02014b50,
			0x0314,
			20,
			0x0800,
			$method,
			$dos[0],
			$dos[1],
			$crc_value,
			$packed,
			$read,
			strlen( $name ),
			0,
			0,
			0,
			0,
			0100644 << 16,
			$offset
		) . $name;
		fseek( $this->cdh, $this->cd_size );
		if ( ! $this->write( $this->cdh, $record ) ) {
			return $this->undo( $offset, __( 'disk full?', 'ner-michoel-core' ) );
		}

		$this->size     = $end;
		$this->cd_size += strlen( $record );
		$this->entries++;
		return $read;
	}

	/**
	 * Finishes the zip: the central directory and its end record go on the end. The '.cd' file is removed.
	 */
	public function close_archive() {
		if ( $this->entries > 65535 || $this->size > 0xFFFFFFFF || $this->cd_size > 0xFFFFFFFF ) {
			return new WP_Error( 'nm_zip_big', __( 'The backup piece got too big for a zip.', 'ner-michoel-core' ) );
		}
		fseek( $this->fh, $this->size );
		fflush( $this->cdh );
		fseek( $this->cdh, 0 );
		$copied = stream_copy_to_stream( $this->cdh, $this->fh, $this->cd_size );
		if ( $copied !== $this->cd_size ) {
			ftruncate( $this->fh, $this->size );
			return new WP_Error( 'nm_zip_close', __( 'Couldn’t finish the backup file (is the disk full?).', 'ner-michoel-core' ) );
		}
		$eocd = pack( 'VvvvvVVv', 0x06054b50, 0, 0, $this->entries, $this->entries, $this->cd_size, $this->size, 0 );
		if ( ! $this->write( $this->fh, $eocd ) ) {
			ftruncate( $this->fh, $this->size );
			return new WP_Error( 'nm_zip_close', __( 'Couldn’t finish the backup file (is the disk full?).', 'ner-michoel-core' ) );
		}
		fflush( $this->fh );
		$this->release();
		@unlink( $this->cd_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
		return true;
	}

	/**
	 * Lets go of the files without finishing (the end of a run); open() with state() carries on.
	 */
	public function release() {
		if ( $this->fh ) {
			fclose( $this->fh );
			$this->fh = null;
		}
		if ( $this->cdh ) {
			fclose( $this->cdh );
			$this->cdh = null;
		}
	}

	private function write( $handle, $bytes ) {
		$length = strlen( $bytes );
		$done   = 0;
		while ( $done < $length ) {
			$n = fwrite( $handle, 0 === $done ? $bytes : substr( $bytes, $done ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			if ( ! $n ) {
				return false;
			}
			$done += $n;
		}
		return true;
	}

	/** A file that couldn't be added: cut the zip back to where it was. */
	private function undo( $offset, $why ) {
		ftruncate( $this->fh, $offset );
		fseek( $this->fh, $offset );
		ftruncate( $this->cdh, $this->cd_size );
		fseek( $this->cdh, $this->cd_size );
		return new WP_Error( 'nm_zip_file', $why );
	}

	/** array( time, date ) in MS-DOS form, as a zip wants them (2-second steps, 1980 at the earliest). */
	private static function dos_time( $timestamp ) {
		$t = $timestamp > 315532800 ? $timestamp : 315532800; // 1980-01-01
		$d = getdate( $t );
		return array(
			( $d['hours'] << 11 ) | ( $d['minutes'] << 5 ) | ( $d['seconds'] >> 1 ),
			( ( $d['year'] - 1980 ) << 9 ) | ( $d['mon'] << 5 ) | $d['mday'],
		);
	}
}
