<?php
/**
 * The Alumni Directory (Yoni, 2026-11-03).
 *
 * The office uploads an Excel sheet (or CSV) of alumni: names, the wife's name,
 * emails, phones, addresses, years and anything else. Each row of the sheet is
 * one row here, in its own table ({prefix}nm_alumni), with the sheet's columns.
 *
 *  - Sign-up (accounts.php): before anything else, the name and email typed are
 *    looked up here. A row matches when the email is in it, or when two of the
 *    words of the name typed are names in the row (first, last, wife's name...).
 *    The person is asked "Is this you?" with a few hints from the row (years,
 *    city, the wife's first name, the email and phone partly hidden). "Yes" ties
 *    the account to the row and lets them in (after the usual email code).
 *  - The Account page then has an "Alumni details" tab where they keep their row
 *    up to date (address, phone, emails, ...) and tick "Email me" (opt-in). The
 *    office gets an email with what changed.
 *  - Control Panel > Alumni > Alumni Directory: the whole table, searchable,
 *    sortable, filterable, editable, with a download of what is shown.
 *    Alumni > Upload Alumni Sheet: read a sheet, check the columns, then replace
 *    the table or add to / update it.
 *
 * An account that is already signed in, but not tied to a row, can find itself
 * from the same "Alumni details" tab.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NER_MICHOEL_ALUMNI_DIR_DB_VERSION', '1.0' );
define( 'NER_MICHOEL_ALUMNI_DIR_COLUMNS_OPTION', 'nm_alumni_dir_columns' );
define( 'NER_MICHOEL_ALUMNI_DIR_MAX_ROWS', 30000 );

/* ================================================================== */
/* Table                                                               */
/* ================================================================== */

function ner_michoel_alumni_dir_table() {
	global $wpdb;
	return $wpdb->prefix . 'nm_alumni';
}

function ner_michoel_alumni_dir_install() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$t       = ner_michoel_alumni_dir_table();
	$charset = $wpdb->get_charset_collate();
	dbDelta(
		"CREATE TABLE {$t} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		link_by VARCHAR(10) NOT NULL DEFAULT '',
		data LONGTEXT NOT NULL,
		names TEXT NOT NULL,
		emails TEXT NOT NULL,
		optin TINYINT NOT NULL DEFAULT 0,
		self_keys TEXT NOT NULL,
		created_at DATETIME NOT NULL,
		updated_at DATETIME NOT NULL,
		self_updated_at DATETIME NULL,
		PRIMARY KEY  (id),
		KEY user_id (user_id)
	) {$charset};"
	);
	update_option( 'nm_alumni_dir_db_version', NER_MICHOEL_ALUMNI_DIR_DB_VERSION );
}

/** Made on the first admin page load after the update (and again if it is ever missing). */
function ner_michoel_alumni_dir_maybe_install() {
	if ( get_option( 'nm_alumni_dir_db_version' ) !== NER_MICHOEL_ALUMNI_DIR_DB_VERSION ) {
		ner_michoel_alumni_dir_install();
	}
}
add_action( 'admin_init', 'ner_michoel_alumni_dir_maybe_install' );

/** True when the table is there (it is made on the first admin page load). */
function ner_michoel_alumni_dir_ready() {
	static $ready = null;
	if ( null === $ready ) {
		$ready = get_option( 'nm_alumni_dir_db_version' ) === NER_MICHOEL_ALUMNI_DIR_DB_VERSION;
	}
	return $ready;
}

/* ================================================================== */
/* Columns                                                             */
/* ================================================================== */

/** What a column can be. The kind decides how it is used (matching, hints, the alumnus's form). */
function ner_michoel_alumni_dir_kinds() {
	return array(
		'first'   => __( 'First name', 'ner-michoel-core' ),
		'last'    => __( 'Last name', 'ner-michoel-core' ),
		'name'    => __( 'Full name', 'ner-michoel-core' ),
		'spouse'  => __( 'Wife / spouse name', 'ner-michoel-core' ),
		'hebrew'  => __( 'Hebrew name', 'ner-michoel-core' ),
		'email'   => __( 'Email', 'ner-michoel-core' ),
		'phone'   => __( 'Phone', 'ner-michoel-core' ),
		'address' => __( 'Street address', 'ner-michoel-core' ),
		'city'    => __( 'City', 'ner-michoel-core' ),
		'state'   => __( 'State', 'ner-michoel-core' ),
		'zip'     => __( 'Zip', 'ner-michoel-core' ),
		'country' => __( 'Country', 'ner-michoel-core' ),
		'years'   => __( 'Years', 'ner-michoel-core' ),
		'other'   => __( 'Other', 'ner-michoel-core' ),
	);
}

/** Kinds whose words are names (for "two names match"). */
function ner_michoel_alumni_dir_name_kinds() {
	return array( 'first', 'last', 'name', 'spouse', 'hebrew' );
}

/** The kind a heading points to ("Wife's Name" is spouse, "E-mail 2" is email...). */
function ner_michoel_alumni_dir_guess_kind( $heading ) {
	$t = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $heading, 'UTF-8' ) : strtolower( (string) $heading );
	$t = trim( (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $t ) );
	if ( '' === $t ) {
		return 'other';
	}
	$has = function ( $pattern ) use ( $t ) {
		return (bool) preg_match( '/' . $pattern . '/u', $t );
	};
	if ( $has( '\b(e ?mail|email)\b' ) ) {
		return 'email';
	}
	if ( $has( '\b(wife|wifes|spouse|mrs|husband|maiden|rebbetzin)\b' ) ) {
		return $has( '\b(phone|cell|mobile)\b' ) ? 'phone' : 'spouse';
	}
	if ( $has( '\b(phone|cell|mobile|tel|telephone|fax)\b' ) ) {
		return 'phone';
	}
	if ( $has( '\bhebrew\b' ) || $has( "\xD7\xA9\xD7\x9D" ) ) { // שם
		return 'hebrew';
	}
	if ( $has( '^(first|first name|firstname|fname|given name|given)$' ) || $has( '^first\b' ) ) {
		return 'first';
	}
	if ( $has( '^(last|last name|lastname|lname|surname|family name|family)$' ) || $has( '^last\b' ) || $has( '\bsurname\b' ) ) {
		return 'last';
	}
	if ( $has( '^(name|full name|names|alumnus|alumni|student|talmid)$' ) ) {
		return 'name';
	}
	if ( $has( '\b(zip|postal|postcode|zipcode)\b' ) ) {
		return 'zip';
	}
	if ( $has( '\b(city|town)\b' ) ) {
		return 'city';
	}
	if ( $has( '\b(state|province|st)\b' ) ) {
		return 'state';
	}
	if ( $has( '\bcountry\b' ) ) {
		return 'country';
	}
	if ( $has( '\b(address|street|addr|apt)\b' ) ) {
		return 'address';
	}
	if ( $has( '\b(years?|class|shiur|from|until|zman|entered|left|graduated)\b' ) ) {
		return 'years';
	}
	return 'other';
}

/** The columns, in order: list of array( key, label, kind ). */
function ner_michoel_alumni_dir_columns() {
	$cols = get_option( NER_MICHOEL_ALUMNI_DIR_COLUMNS_OPTION, array() );
	if ( ! is_array( $cols ) ) {
		return array();
	}
	$kinds = ner_michoel_alumni_dir_kinds();
	$out   = array();
	foreach ( $cols as $c ) {
		if ( is_array( $c ) && ! empty( $c['key'] ) && preg_match( '/^c\d+$/', $c['key'] ) ) {
			$out[] = array(
				'key'   => $c['key'],
				'label' => (string) ( isset( $c['label'] ) ? $c['label'] : $c['key'] ),
				'kind'  => isset( $c['kind'], $kinds[ $c['kind'] ] ) ? $c['kind'] : 'other',
			);
		}
	}
	return $out;
}

function ner_michoel_alumni_dir_save_columns( array $cols ) {
	update_option( NER_MICHOEL_ALUMNI_DIR_COLUMNS_OPTION, array_values( $cols ), false );
}

/** A new column key (c1, c2...), never used before. */
function ner_michoel_alumni_dir_new_key( array $cols ) {
	$max = (int) get_option( 'nm_alumni_dir_col_seq', 0 );
	foreach ( $cols as $c ) {
		$max = max( $max, (int) substr( $c['key'], 1 ) );
	}
	$max++;
	update_option( 'nm_alumni_dir_col_seq', $max, false );
	return 'c' . $max;
}

/** A heading compared loosely ("E-mail" = "email "). */
function ner_michoel_alumni_dir_norm_label( $label ) {
	$t = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $label, 'UTF-8' ) : strtolower( (string) $label );
	return trim( (string) preg_replace( '/[^\p{L}\p{N}]+/u', '', $t ) );
}

/* ================================================================== */
/* Rows                                                                */
/* ================================================================== */

/** One cell tidied: no control characters, at most 400 characters (an address may have line breaks). */
function ner_michoel_alumni_dir_clean_cell( $value, $kind = 'other' ) {
	$value = (string) $value;
	if ( 'address' === $kind ) {
		$lines = preg_split( '/\r\n|\r|\n/', $value );
		$lines = array_filter( array_map( function ( $l ) {
			return ner_michoel_years_clean( $l, 200 );
		}, $lines ), 'strlen' );
		$value = implode( "\n", $lines );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 400, 'UTF-8' ) : substr( $value, 0, 400 );
	}
	$value = ner_michoel_years_clean( $value, 'other' === $kind ? 400 : 200 );
	if ( 'email' === $kind ) {
		$value = strtolower( $value );
	}
	return $value;
}

/** The emails in a row's data (every email column; a cell may hold two, "a@x.com; b@y.com"). */
function ner_michoel_alumni_dir_emails_of( array $data, array $cols ) {
	$out = array();
	foreach ( $cols as $c ) {
		if ( 'email' === $c['kind'] && ! empty( $data[ $c['key'] ] ) ) {
			foreach ( preg_split( '/[\s,;\/]+/', strtolower( (string) $data[ $c['key'] ] ) ) as $e ) {
				if ( is_email( $e ) ) {
					$out[] = $e;
				}
			}
		}
	}
	return array_values( array_unique( $out ) );
}

/** The name words in a row's data (first, last, full, wife's, Hebrew). */
function ner_michoel_alumni_dir_names_of( array $data, array $cols ) {
	$out = array();
	foreach ( $cols as $c ) {
		if ( in_array( $c['kind'], ner_michoel_alumni_dir_name_kinds(), true ) && ! empty( $data[ $c['key'] ] ) ) {
			$out = array_merge( $out, ner_michoel_years_tokens( $data[ $c['key'] ] ) );
		}
	}
	return array_values( array_unique( $out ) );
}

/** "Moshe Cohen" from a row: First + Last, else Full name. */
function ner_michoel_alumni_dir_display_name( array $data, array $cols ) {
	$first = '';
	$last  = '';
	$full  = '';
	foreach ( $cols as $c ) {
		$v = isset( $data[ $c['key'] ] ) ? trim( (string) $data[ $c['key'] ] ) : '';
		if ( '' === $v ) {
			continue;
		}
		if ( 'first' === $c['kind'] && '' === $first ) {
			$first = $v;
		} elseif ( 'last' === $c['kind'] && '' === $last ) {
			$last = $v;
		} elseif ( 'name' === $c['kind'] && '' === $full ) {
			$full = $v;
		}
	}
	$joined = trim( $first . ' ' . $last );
	return '' !== $joined ? $joined : $full;
}

/** A row from the database, unpacked. */
function ner_michoel_alumni_dir_unpack( $r ) {
	if ( ! $r ) {
		return null;
	}
	$data = json_decode( (string) $r->data, true );
	$self = json_decode( (string) $r->self_keys, true );
	return array(
		'id'              => (int) $r->id,
		'user_id'         => (int) $r->user_id,
		'link_by'         => (string) $r->link_by,
		'data'            => is_array( $data ) ? $data : array(),
		'optin'           => (int) $r->optin,
		'self_keys'       => is_array( $self ) ? $self : array(),
		'created_at'      => (string) $r->created_at,
		'updated_at'      => (string) $r->updated_at,
		'self_updated_at' => (string) $r->self_updated_at,
	);
}

function ner_michoel_alumni_dir_get( $id ) {
	global $wpdb;
	if ( ! ner_michoel_alumni_dir_ready() ) {
		return null;
	}
	$t = ner_michoel_alumni_dir_table();
	return ner_michoel_alumni_dir_unpack( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", (int) $id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

/** The row tied to an account, or null. */
function ner_michoel_alumni_dir_row_of_user( $user_id ) {
	global $wpdb;
	if ( ! $user_id || ! ner_michoel_alumni_dir_ready() ) {
		return null;
	}
	$t = ner_michoel_alumni_dir_table();
	return ner_michoel_alumni_dir_unpack( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE user_id = %d ORDER BY id LIMIT 1", (int) $user_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

function ner_michoel_alumni_dir_count() {
	global $wpdb;
	if ( ! ner_michoel_alumni_dir_ready() ) {
		return 0;
	}
	$t = ner_michoel_alumni_dir_table();
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

/**
 * Writes a row (new when $row['id'] is empty). $row: data, optin, user_id,
 * link_by, self_keys, self_updated_at. Returns the id, or 0.
 */
function ner_michoel_alumni_dir_write( array $row, array $cols = null ) {
	global $wpdb;
	$cols  = null === $cols ? ner_michoel_alumni_dir_columns() : $cols;
	$keys  = wp_list_pluck( $cols, 'kind', 'key' );
	$clean = array();
	foreach ( (array) ( isset( $row['data'] ) ? $row['data'] : array() ) as $k => $v ) {
		if ( isset( $keys[ $k ] ) && ( is_string( $v ) || is_numeric( $v ) ) ) {
			$v = ner_michoel_alumni_dir_clean_cell( $v, $keys[ $k ] );
			if ( '' !== $v ) {
				$clean[ $k ] = $v;
			}
		}
	}
	$now    = current_time( 'mysql', true );
	$fields = array(
		'user_id'    => isset( $row['user_id'] ) ? (int) $row['user_id'] : 0,
		'link_by'    => isset( $row['link_by'] ) ? substr( (string) $row['link_by'], 0, 10 ) : '',
		'data'       => wp_json_encode( (object) $clean ),
		'names'      => implode( ' ', ner_michoel_alumni_dir_names_of( $clean, $cols ) ),
		'emails'     => implode( ' ', ner_michoel_alumni_dir_emails_of( $clean, $cols ) ),
		'optin'      => empty( $row['optin'] ) ? 0 : 1,
		'self_keys'  => wp_json_encode( array_values( array_unique( isset( $row['self_keys'] ) ? (array) $row['self_keys'] : array() ) ) ),
		'updated_at' => $now,
	);
	if ( ! empty( $row['self_updated_at'] ) ) {
		$fields['self_updated_at'] = $row['self_updated_at'];
	}
	$t = ner_michoel_alumni_dir_table();
	if ( ! empty( $row['id'] ) ) {
		$ok = $wpdb->update( $t, $fields, array( 'id' => (int) $row['id'] ) );
		return false === $ok ? 0 : (int) $row['id'];
	}
	$fields['created_at'] = $now;
	$ok                   = $wpdb->insert( $t, $fields );
	return $ok ? (int) $wpdb->insert_id : 0;
}

function ner_michoel_alumni_dir_delete( $id ) {
	global $wpdb;
	$row = ner_michoel_alumni_dir_get( $id );
	if ( $row && $row['user_id'] ) {
		ner_michoel_alumni_dir_forget_user( $row['user_id'] );
	}
	return (bool) $wpdb->delete( ner_michoel_alumni_dir_table(), array( 'id' => (int) $id ) );
}

/** The user meta that says an account came in through the directory. */
function ner_michoel_alumni_dir_forget_user( $user_id ) {
	delete_user_meta( $user_id, 'nm_alumni_row' );
	if ( function_exists( 'ner_michoel_alumni_status' ) && 'matched' === ner_michoel_alumni_status( $user_id ) ) {
		$match = get_user_meta( $user_id, 'nm_alumni_match', true );
		if ( is_array( $match ) && isset( $match['by'] ) && 'directory' === $match['by'] ) {
			delete_user_meta( $user_id, 'nm_alumni_status' );
			delete_user_meta( $user_id, 'nm_alumni_match' );
			delete_user_meta( $user_id, 'nm_alumni_checked' );
		}
	}
}

/** Every row (for the panel's table and the matching). */
function ner_michoel_alumni_dir_all( $light = false ) {
	global $wpdb;
	if ( ! ner_michoel_alumni_dir_ready() ) {
		return array();
	}
	$t      = ner_michoel_alumni_dir_table();
	$select = $light ? 'id, user_id, names, emails' : '*';
	return (array) $wpdb->get_results( "SELECT {$select} FROM {$t} ORDER BY id" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

/**
 * Is the row already somebody's? An account that never confirmed its email
 * doesn't hold a row (it is cleaned away after a while), and nor does a deleted one.
 */
function ner_michoel_alumni_dir_held_by_other( $row_user_id, $me ) {
	$row_user_id = (int) $row_user_id;
	if ( ! $row_user_id || $row_user_id === (int) $me ) {
		return false;
	}
	$user = get_userdata( $row_user_id );
	if ( ! $user ) {
		return false;
	}
	if ( function_exists( 'ner_michoel_user_is_unverified' ) && ner_michoel_user_is_unverified( $row_user_id ) ) {
		return false;
	}
	return true;
}

/** Ties an account to a row (and lets it into the alumni-only parts). */
function ner_michoel_alumni_dir_link( $row_id, $user_id, $by ) {
	global $wpdb;
	$t = ner_michoel_alumni_dir_table();
	// Whatever the account held before, and whoever held this row before (an unconfirmed sign-up), let go.
	$old = (int) get_user_meta( $user_id, 'nm_alumni_row', true );
	if ( $old && $old !== (int) $row_id ) {
		$wpdb->update( $t, array( 'user_id' => 0, 'link_by' => '' ), array( 'id' => $old, 'user_id' => (int) $user_id ) );
	}
	$row = ner_michoel_alumni_dir_get( $row_id );
	if ( ! $row ) {
		return false;
	}
	if ( $row['user_id'] && $row['user_id'] !== (int) $user_id ) {
		ner_michoel_alumni_dir_forget_user( $row['user_id'] );
	}
	$wpdb->update( $t, array( 'user_id' => (int) $user_id, 'link_by' => substr( (string) $by, 0, 10 ) ), array( 'id' => (int) $row_id ) );
	update_user_meta( $user_id, 'nm_alumni_row', (int) $row_id );
	if ( function_exists( 'ner_michoel_alumni_status' ) && ! in_array( ner_michoel_alumni_status( $user_id ), array( 'approved' ), true ) ) {
		update_user_meta( $user_id, 'nm_alumni_status', 'matched' );
		update_user_meta( $user_id, 'nm_alumni_match', array( 'by' => 'directory', 'row' => (int) $row_id, 'how' => $by ) );
	}
	return true;
}

function ner_michoel_alumni_dir_unlink( $row_id ) {
	global $wpdb;
	$row = ner_michoel_alumni_dir_get( $row_id );
	if ( ! $row ) {
		return false;
	}
	if ( $row['user_id'] ) {
		ner_michoel_alumni_dir_forget_user( $row['user_id'] );
	}
	$wpdb->update( ner_michoel_alumni_dir_table(), array( 'user_id' => 0, 'link_by' => '' ), array( 'id' => (int) $row_id ) );
	return true;
}

/** A deleted account lets go of its row. */
function ner_michoel_alumni_dir_on_delete_user( $user_id ) {
	global $wpdb;
	if ( ner_michoel_alumni_dir_ready() ) {
		$wpdb->update( ner_michoel_alumni_dir_table(), array( 'user_id' => 0, 'link_by' => '' ), array( 'user_id' => (int) $user_id ) );
	}
}
add_action( 'deleted_user', 'ner_michoel_alumni_dir_on_delete_user' );

/* ================================================================== */
/* Matching a sign-up                                                  */
/* ================================================================== */

/**
 * Rows that may be this person: the email is in the row, or at least two words
 * of the name typed are names in the row. Best first, at most $limit. Rows held
 * by another account are left out. Returns list of array( id, by: email|name, score ).
 */
function ner_michoel_alumni_dir_candidates( $name, $email, $me = 0, $limit = 5 ) {
	if ( ! ner_michoel_alumni_dir_ready() ) {
		return array();
	}
	$typed = array_values( array_unique( ner_michoel_years_name_tokens( (string) $name ) ) );
	$email = strtolower( trim( (string) $email ) );
	$found = array();
	foreach ( ner_michoel_alumni_dir_all( true ) as $r ) {
		$score = 0;
		$by    = '';
		if ( '' !== $email && '' !== (string) $r->emails && in_array( $email, explode( ' ', (string) $r->emails ), true ) ) {
			$score = 100;
			$by    = 'email';
		}
		if ( count( $typed ) >= 2 && '' !== (string) $r->names ) {
			$have = array_flip( explode( ' ', (string) $r->names ) );
			$hits = 0;
			foreach ( $typed as $w ) {
				if ( isset( $have[ $w ] ) ) {
					$hits++;
				}
			}
			if ( $hits >= 2 ) {
				$score += 10 * $hits;
				$by     = $by ? $by : 'name';
			}
		}
		if ( $score && ! ner_michoel_alumni_dir_held_by_other( $r->user_id, $me ) ) {
			$found[] = array( 'id' => (int) $r->id, 'by' => $by, 'score' => $score );
		}
	}
	usort(
		$found,
		function ( $a, $b ) {
			return $b['score'] - $a['score'] ?: $a['id'] - $b['id'];
		}
	);
	return array_slice( $found, 0, $limit );
}

/** "m•••@gmail.com". */
function ner_michoel_alumni_dir_mask_email( $email ) {
	$parts = explode( '@', (string) $email, 2 );
	if ( 2 !== count( $parts ) ) {
		return '';
	}
	$first = function_exists( 'mb_substr' ) ? mb_substr( $parts[0], 0, 1, 'UTF-8' ) : substr( $parts[0], 0, 1 );
	return $first . "\xE2\x80\xA2\xE2\x80\xA2\xE2\x80\xA2@" . $parts[1];
}

/**
 * What "Is this you?" shows for a row: the name, and a few things only the
 * person would recognise, partly hidden: years, city, the wife's first name, the
 * email and the last two digits of the phone. Never the street address.
 */
function ner_michoel_alumni_dir_hints( array $row, array $cols ) {
	$data  = $row['data'];
	$hints = array();
	$name  = ner_michoel_alumni_dir_display_name( $data, $cols );
	if ( '' !== $name ) {
		$hints[] = array( 'label' => __( 'Name', 'ner-michoel-core' ), 'value' => $name );
	}
	$by_kind = array();
	foreach ( $cols as $c ) {
		if ( ! empty( $data[ $c['key'] ] ) && ! isset( $by_kind[ $c['kind'] ] ) ) {
			$by_kind[ $c['kind'] ] = (string) $data[ $c['key'] ];
		}
	}
	if ( isset( $by_kind['years'] ) ) {
		$hints[] = array( 'label' => __( 'Years', 'ner-michoel-core' ), 'value' => $by_kind['years'] );
	}
	$place = trim( implode( ', ', array_filter( array( isset( $by_kind['city'] ) ? $by_kind['city'] : '', isset( $by_kind['state'] ) ? $by_kind['state'] : '' ) ) ) );
	if ( '' !== $place ) {
		$hints[] = array( 'label' => __( 'City', 'ner-michoel-core' ), 'value' => $place );
	}
	if ( isset( $by_kind['spouse'] ) ) {
		$words = preg_split( '/\s+/u', trim( $by_kind['spouse'] ) );
		$hints[] = array( 'label' => __( 'Wife', 'ner-michoel-core' ), 'value' => $words[0] );
	}
	$emails = ner_michoel_alumni_dir_emails_of( $data, $cols );
	if ( $emails ) {
		$hints[] = array( 'label' => __( 'Email', 'ner-michoel-core' ), 'value' => ner_michoel_alumni_dir_mask_email( $emails[0] ) );
	}
	if ( isset( $by_kind['phone'] ) ) {
		$digits = preg_replace( '/\D/', '', $by_kind['phone'] );
		if ( strlen( $digits ) >= 4 ) {
			$hints[] = array( 'label' => __( 'Phone', 'ner-michoel-core' ), 'value' => sprintf( /* translators: %s: last two digits */ __( 'ending in %s', 'ner-michoel-core' ), substr( $digits, -2 ) ) );
		}
	}
	return $hints;
}

/**
 * Keeps the "Is this you?" choice for a moment: only the browser that got the
 * ticket can answer, only with one of the rows it was shown.
 */
function ner_michoel_alumni_dir_ticket_new( $user_id, array $cands, $optin ) {
	$token = bin2hex( random_bytes( 16 ) );
	set_transient(
		'nm_alumni_claim_' . hash( 'sha256', $token ),
		array(
			'user'  => (int) $user_id,
			'rows'  => wp_list_pluck( $cands, 'by', 'id' ),
			'optin' => $optin ? 1 : 0,
		),
		HOUR_IN_SECONDS
	);
	return $token;
}

function ner_michoel_alumni_dir_ticket_get( $token ) {
	$token = preg_replace( '/[^a-f0-9]/', '', (string) $token );
	if ( 32 !== strlen( $token ) ) {
		return null;
	}
	$data = get_transient( 'nm_alumni_claim_' . hash( 'sha256', $token ) );
	return is_array( $data ) ? $data : null;
}

function ner_michoel_alumni_dir_ticket_delete( $token ) {
	delete_transient( 'nm_alumni_claim_' . hash( 'sha256', preg_replace( '/[^a-f0-9]/', '', (string) $token ) ) );
}

/** The "Is this you?" part of an answer: ticket + rows with hints, or null when nothing matches. */
function ner_michoel_alumni_dir_offer( $user_id, $name, $email, $optin ) {
	$cands = ner_michoel_alumni_dir_candidates( $name, $email, $user_id );
	if ( ! $cands ) {
		return null;
	}
	$cols = ner_michoel_alumni_dir_columns();
	$out  = array();
	foreach ( $cands as $c ) {
		$row = ner_michoel_alumni_dir_get( $c['id'] );
		if ( $row ) {
			$out[] = array( 'id' => $row['id'], 'hints' => ner_michoel_alumni_dir_hints( $row, $cols ) );
		}
	}
	if ( ! $out ) {
		return null;
	}
	return array(
		'token'      => ner_michoel_alumni_dir_ticket_new( $user_id, $cands, $optin ),
		'candidates' => $out,
	);
}

/**
 * Called by the sign-up route (accounts.php) once the account exists. Returns
 * the offer for the reply, or null.
 */
function ner_michoel_alumni_dir_on_signup( $user_id, $name, $email, $optin ) {
	return ner_michoel_alumni_dir_offer( $user_id, $name, $email, $optin );
}

/* ================================================================== */
/* Routes: sign-up answer, the alumnus's own details                   */
/* ================================================================== */

function ner_michoel_alumni_dir_routes() {
	register_rest_route(
		'ner-michoel/v1',
		'/account-alumni-claim',
		array(
			'methods'             => 'POST',
			'callback'            => 'ner_michoel_alumni_dir_route_claim',
			'permission_callback' => '__return_true',
		)
	);
	register_rest_route(
		'ner-michoel/v1',
		'/account-alumni-find',
		array(
			'methods'             => 'POST',
			'callback'            => 'ner_michoel_alumni_dir_route_find',
			'permission_callback' => function () {
				return is_user_logged_in();
			},
		)
	);
	register_rest_route(
		'ner-michoel/v1',
		'/account-alumni-details',
		array(
			'methods'             => 'POST',
			'callback'            => 'ner_michoel_alumni_dir_route_details',
			'permission_callback' => function () {
				return is_user_logged_in();
			},
		)
	);
	foreach ( array( 'rows' => 'GET', 'row' => 'POST', 'row-delete' => 'POST', 'row-unlink' => 'POST', 'row-link' => 'POST', 'columns' => 'POST' ) as $path => $method ) {
		register_rest_route(
			'ner-michoel/v1',
			'/alumni-dir/' . $path,
			array(
				'methods'             => $method,
				'callback'            => 'ner_michoel_alumni_dir_admin_route',
				'permission_callback' => 'ner_michoel_alumni_dir_can_manage',
			)
		);
	}
}
add_action( 'rest_api_init', 'ner_michoel_alumni_dir_routes' );

/** The sign-up's answer is an open route (nobody is signed in yet), like the other sign-up steps. */
function ner_michoel_alumni_dir_open_route( $result ) {
	if ( ! is_wp_error( $result ) || 'rest_cookie_invalid_nonce' !== $result->get_error_code() ) {
		return $result;
	}
	$route = isset( $GLOBALS['wp']->query_vars['rest_route'] ) ? trim( (string) $GLOBALS['wp']->query_vars['rest_route'], '/' ) : '';
	if ( 'ner-michoel/v1/account-alumni-claim' !== $route ) {
		return $result;
	}
	wp_set_current_user( 0 );
	return null;
}
add_filter( 'rest_authentication_errors', 'ner_michoel_alumni_dir_open_route', 102 );

/**
 * "Yes, that's me" (row = its id) or "None of these" (row = none), after
 * sign-up or from the Account page. Ties the account to the row.
 */
function ner_michoel_alumni_dir_route_claim( WP_REST_Request $request ) {
	$token  = (string) $request->get_param( 'token' );
	$ticket = ner_michoel_alumni_dir_ticket_get( $token );
	if ( ! $ticket ) {
		return new WP_REST_Response( array( 'success' => false, 'message' => __( 'This step timed out. You can find yourself in the alumni list later from your Account page.', 'ner-michoel-core' ) ), 400 );
	}
	$user_id = (int) $ticket['user'];
	$user    = get_userdata( $user_id );
	if ( ! $user || ( is_user_logged_in() && get_current_user_id() !== $user_id ) ) {
		return new WP_REST_Response( array( 'success' => false, 'message' => __( 'This step timed out. Please try again.', 'ner-michoel-core' ) ), 400 );
	}
	$choice = (string) $request->get_param( 'row' );
	ner_michoel_alumni_dir_ticket_delete( $token );

	$linked = false;
	if ( 'none' !== $choice ) {
		$row_id = absint( $choice );
		if ( ! $row_id || ! isset( $ticket['rows'][ $row_id ] ) ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Please pick one of the rows shown.', 'ner-michoel-core' ) ), 400 );
		}
		$row = ner_michoel_alumni_dir_get( $row_id );
		if ( ! $row || ner_michoel_alumni_dir_held_by_other( $row['user_id'], $user_id ) ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => __( 'That entry was just taken by another account. The office can sort it out.', 'ner-michoel-core' ) ), 409 );
		}
		ner_michoel_alumni_dir_link( $row_id, $user_id, $ticket['rows'][ $row_id ] );
		if ( ! empty( $ticket['optin'] ) && ! $row['optin'] ) {
			global $wpdb;
			$wpdb->update( ner_michoel_alumni_dir_table(), array( 'optin' => 1 ), array( 'id' => $row_id ) );
		}
		$linked = true;
	}

	$out = array( 'success' => true, 'linked' => $linked );

	// Right after sign-up with no email code step: an account that was waiting for the office because of
	// alumni-only is let in by the match, and signed in now; one that still waits is told so.
	if ( ! is_user_logged_in() && ! ( function_exists( 'ner_michoel_user_is_unverified' ) && ner_michoel_user_is_unverified( $user_id ) ) ) {
		if ( function_exists( 'ner_michoel_alumni_is_blocked' ) && ner_michoel_alumni_is_blocked( $user_id ) ) {
			ner_michoel_alumni_tell_office_pending( $user_id );
			$out['alumni_pending'] = true;
			$out['message']        = ner_michoel_alumni_pending_message();
		} elseif ( $user->user_registered && strtotime( $user->user_registered . ' UTC' ) > time() - HOUR_IN_SECONDS ) {
			ner_michoel_sign_in_user( $user_id );
			$out['signed_in'] = true;
		}
	}
	return new WP_REST_Response( $out, 200 );
}

/** "Find me in the alumni list", for a signed-in account that isn't tied to a row. */
function ner_michoel_alumni_dir_route_find( WP_REST_Request $request ) {
	$user_id = get_current_user_id();
	$user    = wp_get_current_user();
	$key     = 'nm_alumni_find_' . $user_id;
	$tries   = (int) get_transient( $key );
	if ( $tries >= 15 ) {
		return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Too many searches. Please try again in an hour.', 'ner-michoel-core' ) ), 429 );
	}
	set_transient( $key, $tries + 1, HOUR_IN_SECONDS );
	$name  = sanitize_text_field( (string) $request->get_param( 'name' ) );
	$name  = '' !== $name ? $name : $user->display_name;
	$offer = ner_michoel_alumni_dir_offer( $user_id, $name, $user->user_email, false );
	if ( ! $offer ) {
		return new WP_REST_Response( array( 'success' => true, 'candidates' => array(), 'message' => __( 'We couldn’t find you. Type your first and last name as the yeshiva would have them, or ask the office.', 'ner-michoel-core' ) ), 200 );
	}
	return new WP_REST_Response( array_merge( array( 'success' => true ), $offer ), 200 );
}

/**
 * The alumnus's own row, for the Account page: list of fields (key, label,
 * kind, value, editable) and the opt-in. Null when the account has no row.
 */
function ner_michoel_alumni_dir_profile( $user_id ) {
	$row = ner_michoel_alumni_dir_row_of_user( $user_id );
	if ( ! $row ) {
		return null;
	}
	$fields = array();
	foreach ( ner_michoel_alumni_dir_columns() as $c ) {
		$fields[] = array(
			'key'      => $c['key'],
			'label'    => $c['label'],
			'kind'     => $c['kind'],
			'value'    => isset( $row['data'][ $c['key'] ] ) ? (string) $row['data'][ $c['key'] ] : '',
			'editable' => 'years' !== $c['kind'],
		);
	}
	return array( 'id' => $row['id'], 'fields' => $fields, 'optin' => (bool) $row['optin'] );
}

/** Is there anything to find the account in? (The Account page shows "Find me" only then.) */
function ner_michoel_alumni_dir_has_rows() {
	return ner_michoel_alumni_dir_count() > 0;
}

/** Saves the alumnus's own changes and emails the office what changed. */
function ner_michoel_alumni_dir_route_details( WP_REST_Request $request ) {
	$user_id = get_current_user_id();
	$row     = ner_michoel_alumni_dir_row_of_user( $user_id );
	if ( ! $row ) {
		return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Your account isn’t tied to the alumni list.', 'ner-michoel-core' ) ), 400 );
	}
	$cols    = ner_michoel_alumni_dir_columns();
	$sent    = $request->get_param( 'fields' );
	$sent    = is_array( $sent ) ? $sent : array();
	$data    = $row['data'];
	$self    = $row['self_keys'];
	$changes = array();
	foreach ( $cols as $c ) {
		if ( 'years' === $c['kind'] || ! array_key_exists( $c['key'], $sent ) || ! is_scalar( $sent[ $c['key'] ] ) ) {
			continue;
		}
		$new = ner_michoel_alumni_dir_clean_cell( wp_unslash( (string) $sent[ $c['key'] ] ), $c['kind'] );
		if ( 'email' === $c['kind'] && '' !== $new ) {
			$parts = array_filter( preg_split( '/[\s,;]+/', $new ) );
			foreach ( $parts as $p ) {
				if ( ! is_email( $p ) ) {
					return new WP_REST_Response( array( 'success' => false, 'message' => sprintf( /* translators: %s: column name */ __( '“%s” isn’t a valid email address.', 'ner-michoel-core' ), $c['label'] ) ), 400 );
				}
			}
		}
		$old = isset( $data[ $c['key'] ] ) ? (string) $data[ $c['key'] ] : '';
		if ( $new !== $old ) {
			$changes[]          = array( $c['label'], $old, $new );
			$data[ $c['key'] ] = $new;
			$self[]             = $c['key'];
		}
	}
	$optin = $request->get_param( 'optin' );
	$optin = null === $optin ? $row['optin'] : ( rest_sanitize_boolean( $optin ) ? 1 : 0 );
	if ( (int) $optin !== (int) $row['optin'] ) {
		$changes[] = array( __( 'Email me news and updates', 'ner-michoel-core' ), $row['optin'] ? 'Yes' : 'No', $optin ? 'Yes' : 'No' );
	}
	if ( ! $changes ) {
		return new WP_REST_Response( array( 'success' => true, 'message' => __( 'Nothing changed.', 'ner-michoel-core' ) ), 200 );
	}
	ner_michoel_alumni_dir_write(
		array(
			'id'              => $row['id'],
			'data'            => $data,
			'optin'           => $optin,
			'user_id'         => $row['user_id'],
			'link_by'         => $row['link_by'],
			'self_keys'       => $self,
			'self_updated_at' => current_time( 'mysql', true ),
		),
		$cols
	);
	ner_michoel_alumni_dir_tell_office( $user_id, $changes );
	return new WP_REST_Response( array( 'success' => true, 'message' => __( 'Saved. Thank you!', 'ner-michoel-core' ) ), 200 );
}

function ner_michoel_alumni_dir_tell_office( $user_id, array $changes ) {
	if ( ! function_exists( 'ner_michoel_alumni_office_email' ) ) {
		return;
	}
	$user  = get_userdata( $user_id );
	$lines = array();
	foreach ( $changes as $c ) {
		$lines[] = sprintf( "%s\n  Was: %s\n  Now: %s", $c[0], '' !== $c[1] ? str_replace( "\n", ', ', $c[1] ) : '(empty)', '' !== $c[2] ? str_replace( "\n", ', ', $c[2] ) : '(empty)' );
	}
	$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	wp_mail(
		ner_michoel_alumni_office_email(),
		sprintf( /* translators: 1: site name, 2: person's name */ __( '[%1$s] %2$s updated their alumni details', 'ner-michoel-core' ), $site, $user ? $user->display_name : '' ),
		sprintf( "%s (%s) updated their alumni details on the website:\n\n%s\n\nThe Alumni Directory:\n%s\n", $user ? $user->display_name : '', $user ? $user->user_email : '', implode( "\n\n", $lines ), ner_michoel_custom_admin_url( 'alumnidirectory' ) )
	);
}

/* ================================================================== */
/* Panel: the table (REST for assets/alumni-directory.js)              */
/* ================================================================== */

function ner_michoel_alumni_dir_can_manage() {
	return current_user_can( 'edit_users' );
}

/** A row as the panel's table gets it. */
function ner_michoel_alumni_dir_for_table( array $row ) {
	$account = null;
	if ( $row['user_id'] ) {
		$u = get_userdata( $row['user_id'] );
		if ( $u ) {
			$account = array(
				'id'         => (int) $u->ID,
				'name'       => $u->display_name,
				'email'      => $u->user_email,
				'unverified' => function_exists( 'ner_michoel_user_is_unverified' ) && ner_michoel_user_is_unverified( $u->ID ),
			);
		}
	}
	return array(
		'id'      => $row['id'],
		'd'       => (object) $row['data'],
		'optin'   => (bool) $row['optin'],
		'account' => $account,
		'by'      => $row['link_by'],
		'self'    => $row['self_keys'],
		'updated' => $row['updated_at'] ? $row['updated_at'] . 'Z' : '',
		'selfAt'  => $row['self_updated_at'] ? $row['self_updated_at'] . 'Z' : '',
	);
}

function ner_michoel_alumni_dir_admin_route( WP_REST_Request $request ) {
	$path = substr( $request->get_route(), strlen( '/ner-michoel/v1/alumni-dir/' ) );
	$cols = ner_michoel_alumni_dir_columns();
	$fail = function ( $message, $status = 400 ) {
		return new WP_REST_Response( array( 'success' => false, 'message' => $message ), $status );
	};
	if ( ! ner_michoel_alumni_dir_ready() ) {
		ner_michoel_alumni_dir_install();
	}

	switch ( $path ) {
		case 'rows':
			$rows = array();
			foreach ( ner_michoel_alumni_dir_all() as $r ) {
				$rows[] = ner_michoel_alumni_dir_for_table( ner_michoel_alumni_dir_unpack( $r ) );
			}
			return new WP_REST_Response( array( 'columns' => $cols, 'kinds' => ner_michoel_alumni_dir_kinds(), 'rows' => $rows ), 200 );

		case 'row':
			$id   = absint( $request->get_param( 'id' ) );
			$data = $request->get_param( 'data' );
			$data = is_array( $data ) ? $data : array();
			$old  = $id ? ner_michoel_alumni_dir_get( $id ) : null;
			if ( $id && ! $old ) {
				return $fail( __( 'That row was deleted.', 'ner-michoel-core' ), 404 );
			}
			$new_id = ner_michoel_alumni_dir_write(
				array(
					'id'              => $id,
					'data'            => $data,
					'optin'           => rest_sanitize_boolean( $request->get_param( 'optin' ) ),
					'user_id'         => $old ? $old['user_id'] : 0,
					'link_by'         => $old ? $old['link_by'] : '',
					'self_keys'       => $old ? $old['self_keys'] : array(),
					'self_updated_at' => $old ? $old['self_updated_at'] : '',
				),
				$cols
			);
			if ( ! $new_id ) {
				return $fail( __( 'The row could not be saved.', 'ner-michoel-core' ), 500 );
			}
			return new WP_REST_Response( array( 'success' => true, 'row' => ner_michoel_alumni_dir_for_table( ner_michoel_alumni_dir_get( $new_id ) ) ), 200 );

		case 'row-delete':
			$ids = array_filter( array_map( 'absint', (array) $request->get_param( 'ids' ) ) );
			foreach ( $ids as $id ) {
				ner_michoel_alumni_dir_delete( $id );
			}
			return new WP_REST_Response( array( 'success' => true, 'deleted' => array_values( $ids ) ), 200 );

		case 'row-unlink':
			$id = absint( $request->get_param( 'id' ) );
			ner_michoel_alumni_dir_unlink( $id );
			$row = ner_michoel_alumni_dir_get( $id );
			return new WP_REST_Response( array( 'success' => (bool) $row, 'row' => $row ? ner_michoel_alumni_dir_for_table( $row ) : null ), 200 );

		case 'row-link':
			$id    = absint( $request->get_param( 'id' ) );
			$email = sanitize_email( (string) $request->get_param( 'email' ) );
			$user  = $email ? get_user_by( 'email', $email ) : null;
			if ( ! $user ) {
				return $fail( __( 'No account has that email. The person needs to sign up on the Account page first.', 'ner-michoel-core' ) );
			}
			$held = ner_michoel_alumni_dir_row_of_user( $user->ID );
			if ( $held && $held['id'] !== $id ) {
				return $fail( __( 'That account is already tied to another row. Unlink it there first.', 'ner-michoel-core' ) );
			}
			if ( ! ner_michoel_alumni_dir_link( $id, $user->ID, 'office' ) ) {
				return $fail( __( 'That row was deleted.', 'ner-michoel-core' ), 404 );
			}
			return new WP_REST_Response( array( 'success' => true, 'row' => ner_michoel_alumni_dir_for_table( ner_michoel_alumni_dir_get( $id ) ) ), 200 );

		case 'columns':
			$sent  = $request->get_param( 'columns' );
			$kinds = ner_michoel_alumni_dir_kinds();
			if ( ! is_array( $sent ) ) {
				return $fail( 'Bad columns.' );
			}
			$known = wp_list_pluck( $cols, 'key' );
			$out   = array();
			foreach ( $sent as $c ) {
				if ( ! is_array( $c ) ) {
					continue;
				}
				$label = ner_michoel_years_clean( isset( $c['label'] ) ? (string) $c['label'] : '', 60 );
				$kind  = isset( $c['kind'] ) && isset( $kinds[ $c['kind'] ] ) ? $c['kind'] : 'other';
				$key   = isset( $c['key'] ) ? (string) $c['key'] : '';
				if ( '' === $label ) {
					continue;
				}
				if ( ! in_array( $key, $known, true ) ) {
					$key = ner_michoel_alumni_dir_new_key( array_merge( $cols, $out ) );
				}
				$out[] = array( 'key' => $key, 'label' => $label, 'kind' => $kind );
			}
			$before = wp_list_pluck( $cols, 'kind', 'key' );
			$after  = wp_list_pluck( $out, 'kind', 'key' );
			ner_michoel_alumni_dir_save_columns( $out );
			if ( array_diff_assoc( $before, $after ) ) { // a kind changed or a column went: matching is worked out again
				ner_michoel_alumni_dir_reindex( $out );
			}
			return new WP_REST_Response( array( 'success' => true, 'columns' => ner_michoel_alumni_dir_columns() ), 200 );
	}
	return $fail( 'Unknown.', 404 );
}

/** After columns change kind or go away: the names/emails used for matching are worked out again. */
function ner_michoel_alumni_dir_reindex( array $cols ) {
	foreach ( ner_michoel_alumni_dir_all() as $r ) {
		$row = ner_michoel_alumni_dir_unpack( $r );
		ner_michoel_alumni_dir_write( $row, $cols );
	}
}

/* ================================================================== */
/* Panel: upload a sheet                                               */
/* ================================================================== */

function ner_michoel_alumni_dir_screen_url( $tab, $args = array() ) {
	return ner_michoel_custom_admin_url( $tab, $args );
}

function ner_michoel_alumni_dir_flash( $message, $error = false ) {
	set_transient( 'nm_alumni_dir_flash_' . get_current_user_id(), array( 'error' => $error, 'message' => $message ), 300 );
}

function ner_michoel_alumni_dir_take_flash() {
	$key   = 'nm_alumni_dir_flash_' . get_current_user_id();
	$flash = get_transient( $key );
	if ( $flash ) {
		delete_transient( $key );
	}
	return is_array( $flash ) ? $flash : null;
}

function ner_michoel_alumni_dir_back( $args = array() ) {
	wp_safe_redirect( esc_url_raw( ner_michoel_alumni_dir_screen_url( 'alumniupload', $args ) ) );
	exit;
}

function ner_michoel_alumni_dir_guard() {
	if ( ! ner_michoel_alumni_dir_can_manage() ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'ner-michoel-core' ), '', array( 'response' => 403 ) );
	}
}

/** Step 1: the file is read and kept (two hours, only for whoever uploaded it). */
function ner_michoel_handle_alumni_dir_upload() {
	ner_michoel_alumni_dir_guard();
	check_admin_referer( 'nm_alumni_dir_upload', 'nm_alumni_dir_nonce' );
	$file = isset( $_FILES['nm_alumni_file'] ) ? $_FILES['nm_alumni_file'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	if ( ! $file || ! isset( $file['error'] ) || UPLOAD_ERR_NO_FILE === $file['error'] ) {
		ner_michoel_alumni_dir_flash( __( 'Choose a spreadsheet file first.', 'ner-michoel-core' ), true );
		ner_michoel_alumni_dir_back();
	}
	if ( UPLOAD_ERR_OK !== $file['error'] || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
		ner_michoel_alumni_dir_flash( __( 'The file did not upload. Please try again.', 'ner-michoel-core' ), true );
		ner_michoel_alumni_dir_back();
	}
	$read = ner_michoel_years_read_file( $file['tmp_name'] );
	if ( isset( $read['error'] ) ) {
		ner_michoel_alumni_dir_flash( $read['error'], true );
		ner_michoel_alumni_dir_back();
	}
	$sheets = array();
	foreach ( $read['sheets'] as $sheet ) {
		$rows = array();
		foreach ( $sheet['rows'] as $row ) {
			$cells = array_map(
				function ( $cell ) {
					return ner_michoel_alumni_dir_clean_cell( $cell, 'address' );
				},
				$row
			);
			if ( implode( '', $cells ) !== '' ) {
				$rows[] = $cells;
			}
		}
		if ( $rows ) {
			$sheets[] = array( 'name' => ner_michoel_years_clean( $sheet['name'], 60 ), 'rows' => $rows );
		}
	}
	if ( ! $sheets ) {
		ner_michoel_alumni_dir_flash( __( 'That file has no rows.', 'ner-michoel-core' ), true );
		ner_michoel_alumni_dir_back();
	}
	$token = bin2hex( random_bytes( 16 ) );
	$kept  = set_transient(
		'nm_alumni_dir_pending_' . $token,
		array(
			'user'   => get_current_user_id(),
			'file'   => ner_michoel_years_clean( sanitize_file_name( wp_unslash( $file['name'] ) ), 120 ),
			'sheets' => $sheets,
		),
		2 * HOUR_IN_SECONDS
	);
	if ( ! $kept ) {
		ner_michoel_alumni_dir_flash( __( 'The file was read, but could not be kept while you check it. Try a smaller file, with only the sheet that has the alumni.', 'ner-michoel-core' ), true );
		ner_michoel_alumni_dir_back();
	}
	ner_michoel_alumni_dir_back( array( 'review' => $token ) );
}
add_action( 'admin_post_nm_alumni_dir_upload', 'ner_michoel_handle_alumni_dir_upload' );

function ner_michoel_alumni_dir_pending( $token ) {
	$token = preg_replace( '/[^a-f0-9]/', '', (string) $token );
	if ( 32 !== strlen( $token ) ) {
		return null;
	}
	$data = get_transient( 'nm_alumni_dir_pending_' . $token );
	return ( is_array( $data ) && (int) $data['user'] === get_current_user_id() ) ? $data : null;
}

/** Which row of the sheet holds the headings: the first of the top ten with two or more known headings. */
function ner_michoel_alumni_dir_header_row( array $rows ) {
	foreach ( array_slice( $rows, 0, 10, true ) as $i => $row ) {
		$known = 0;
		foreach ( $row as $cell ) {
			if ( '' !== $cell && 'other' !== ner_michoel_alumni_dir_guess_kind( $cell ) ) {
				$known++;
			}
		}
		if ( $known >= 2 ) {
			return $i;
		}
	}
	return 0;
}

/**
 * The sheet's columns as the review screen shows them: for each one its heading,
 * guessed kind and the existing column it goes into (same heading).
 */
function ner_michoel_alumni_dir_sheet_columns( array $rows, $header ) {
	$width = 0;
	foreach ( $rows as $r ) {
		$width = max( $width, count( $r ) );
	}
	$existing = array();
	foreach ( ner_michoel_alumni_dir_columns() as $c ) {
		$existing[ ner_michoel_alumni_dir_norm_label( $c['label'] ) ] = $c;
	}
	$out = array();
	for ( $i = 0; $i < $width; $i++ ) {
		$heading = isset( $rows[ $header ][ $i ] ) ? trim( (string) $rows[ $header ][ $i ] ) : '';
		$has     = false;
		foreach ( $rows as $n => $r ) {
			if ( $n > $header && isset( $r[ $i ] ) && '' !== $r[ $i ] ) {
				$has = true;
				break;
			}
		}
		if ( ! $has && '' === $heading ) {
			continue;
		}
		$label = '' !== $heading ? ner_michoel_years_clean( $heading, 60 ) : sprintf( /* translators: %d: column number */ __( 'Column %d', 'ner-michoel-core' ), $i + 1 );
		$norm  = ner_michoel_alumni_dir_norm_label( $label );
		$match = isset( $existing[ $norm ] ) ? $existing[ $norm ] : null;
		$out[] = array(
			'index' => $i,
			'label' => $label,
			'kind'  => $match ? $match['kind'] : ner_michoel_alumni_dir_guess_kind( $label ),
			'into'  => $match ? $match['key'] : '',
			'empty' => ! $has,
		);
	}
	return $out;
}

/** The key that says two rows are the same person: their emails, or their first + last name. */
function ner_michoel_alumni_dir_identity( array $data, array $cols ) {
	$keys = array();
	foreach ( ner_michoel_alumni_dir_emails_of( $data, $cols ) as $e ) {
		$keys[] = 'e:' . $e;
	}
	$words = array();
	foreach ( $cols as $c ) {
		if ( in_array( $c['kind'], array( 'first', 'last', 'name' ), true ) && ! empty( $data[ $c['key'] ] ) ) {
			$words = array_merge( $words, ner_michoel_years_name_tokens( $data[ $c['key'] ] ) );
		}
	}
	$words = array_values( array_unique( $words ) );
	if ( count( $words ) >= 2 ) {
		sort( $words );
		$keys[] = 'n:' . implode( ' ', $words );
	}
	return $keys;
}

/** Step 2: "Use this sheet". Replace the table, or add to it and update the rows it already has. */
function ner_michoel_handle_alumni_dir_apply() {
	ner_michoel_alumni_dir_guard();
	check_admin_referer( 'nm_alumni_dir_apply', 'nm_alumni_dir_nonce' );
	if ( ! ner_michoel_alumni_dir_ready() ) {
		ner_michoel_alumni_dir_install();
	}
	$token   = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
	$pending = ner_michoel_alumni_dir_pending( $token );
	if ( ! $pending ) {
		ner_michoel_alumni_dir_flash( __( 'That upload has expired. Please upload the file again.', 'ner-michoel-core' ), true );
		ner_michoel_alumni_dir_back();
	}
	$sheet_i = isset( $_POST['sheet'] ) ? absint( $_POST['sheet'] ) : 0;
	$sheet   = isset( $pending['sheets'][ $sheet_i ] ) ? $pending['sheets'][ $sheet_i ] : $pending['sheets'][0];
	$header  = isset( $_POST['header'] ) ? absint( $_POST['header'] ) : 0;
	$mode    = isset( $_POST['mode'] ) && 'replace' === $_POST['mode'] ? 'replace' : 'merge';
	$kinds   = ner_michoel_alumni_dir_kinds();
	$use     = isset( $_POST['use'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['use'] ) ) : array();
	$set     = isset( $_POST['kind'] ) ? (array) wp_unslash( $_POST['kind'] ) : array();
	$labels  = isset( $_POST['label'] ) ? (array) wp_unslash( $_POST['label'] ) : array();
	$optin_i = isset( $_POST['optin_col'] ) && '' !== $_POST['optin_col'] ? absint( $_POST['optin_col'] ) : -1;

	// The sheet's columns -> the table's columns (same heading = same column; a new heading adds one).
	$cols  = ner_michoel_alumni_dir_columns();
	$by_nm = array();
	foreach ( $cols as $i => $c ) {
		$by_nm[ ner_michoel_alumni_dir_norm_label( $c['label'] ) ] = $i;
	}
	$map = array(); // sheet index -> column key
	foreach ( ner_michoel_alumni_dir_sheet_columns( $sheet['rows'], $header ) as $sc ) {
		$i = $sc['index'];
		if ( ! in_array( $i, $use, true ) || $i === $optin_i ) {
			continue;
		}
		$label = isset( $labels[ $i ] ) ? ner_michoel_years_clean( (string) $labels[ $i ], 60 ) : $sc['label'];
		$label = '' !== $label ? $label : $sc['label'];
		$kind  = isset( $set[ $i ] ) && isset( $kinds[ $set[ $i ] ] ) ? $set[ $i ] : $sc['kind'];
		$norm  = ner_michoel_alumni_dir_norm_label( $label );
		if ( isset( $by_nm[ $norm ] ) ) {
			$cols[ $by_nm[ $norm ] ]['kind'] = $kind;
			$map[ $i ]                         = $cols[ $by_nm[ $norm ] ]['key'];
		} else {
			$key           = ner_michoel_alumni_dir_new_key( $cols );
			$cols[]        = array( 'key' => $key, 'label' => $label, 'kind' => $kind );
			$by_nm[ $norm ] = count( $cols ) - 1;
			$map[ $i ]     = $key;
		}
	}
	if ( ! $map ) {
		ner_michoel_alumni_dir_flash( __( 'Tick at least one column to use.', 'ner-michoel-core' ), true );
		ner_michoel_alumni_dir_back( array( 'review' => $token ) );
	}
	$kinds_used = array_intersect_key( wp_list_pluck( $cols, 'kind', 'key' ), array_flip( $map ) );
	if ( ! array_intersect( $kinds_used, array( 'first', 'last', 'name', 'email' ) ) ) {
		ner_michoel_alumni_dir_flash( __( 'Mark at least one column as a name (First, Last or Full name) or as Email, so alumni can be found.', 'ner-michoel-core' ), true );
		ner_michoel_alumni_dir_back( array( 'review' => $token ) );
	}

	// The sheet's rows, read into the columns.
	$incoming = array();
	foreach ( $sheet['rows'] as $n => $r ) {
		if ( $n <= $header ) {
			continue;
		}
		$data = array();
		foreach ( $map as $i => $key ) {
			if ( isset( $r[ $i ] ) && '' !== trim( (string) $r[ $i ] ) ) {
				$data[ $key ] = (string) $r[ $i ];
			}
		}
		if ( ! $data ) {
			continue;
		}
		$optin = null;
		if ( $optin_i >= 0 && isset( $r[ $optin_i ] ) ) {
			$v     = strtolower( trim( (string) $r[ $optin_i ] ) );
			$optin = in_array( $v, array( 'yes', 'y', '1', 'true', 'x', 'opt in', 'optin', 'subscribed' ), true ) ? 1 : 0;
		}
		$incoming[] = array( 'data' => $data, 'optin' => $optin );
	}
	if ( count( $incoming ) > NER_MICHOEL_ALUMNI_DIR_MAX_ROWS ) {
		ner_michoel_alumni_dir_flash( sprintf( /* translators: %s: number */ __( 'That sheet has more than %s rows. Split it and upload it in parts.', 'ner-michoel-core' ), number_format_i18n( NER_MICHOEL_ALUMNI_DIR_MAX_ROWS ) ), true );
		ner_michoel_alumni_dir_back( array( 'review' => $token ) );
	}

	ner_michoel_alumni_dir_save_columns( $cols );

	// The rows already here, by identity.
	$existing = array();
	$index    = array();
	foreach ( ner_michoel_alumni_dir_all() as $r ) {
		$row                    = ner_michoel_alumni_dir_unpack( $r );
		$existing[ $row['id'] ] = $row;
		foreach ( ner_michoel_alumni_dir_identity( $row['data'], $cols ) as $k ) {
			if ( ! isset( $index[ $k ] ) ) {
				$index[ $k ] = $row['id'];
			}
		}
	}

	$added   = 0;
	$updated = 0;
	$seen    = array();
	foreach ( $incoming as $in ) {
		$match = 0;
		foreach ( ner_michoel_alumni_dir_identity( $in['data'], $cols ) as $k ) {
			if ( isset( $index[ $k ] ) && ! isset( $seen[ $index[ $k ] ] ) ) {
				$match = $index[ $k ];
				break;
			}
		}
		if ( $match ) {
			$row  = $existing[ $match ];
			$data = $row['data'];
			foreach ( $in['data'] as $k => $v ) {
				// What the alumnus changed on the site wins over the office's sheet.
				if ( in_array( $k, $row['self_keys'], true ) && '' !== (string) ( isset( $data[ $k ] ) ? $data[ $k ] : '' ) ) {
					continue;
				}
				$data[ $k ] = $v;
			}
			$row['data'] = $data;
			if ( null !== $in['optin'] && ! $row['self_updated_at'] ) {
				$row['optin'] = $in['optin'];
			}
			ner_michoel_alumni_dir_write( $row, $cols );
			$seen[ $match ] = true;
			$updated++;
		} else {
			$id = ner_michoel_alumni_dir_write( array( 'data' => $in['data'], 'optin' => (int) $in['optin'] ), $cols );
			if ( $id ) {
				$seen[ $id ] = true;
				foreach ( ner_michoel_alumni_dir_identity( $in['data'], $cols ) as $k ) {
					if ( ! isset( $index[ $k ] ) ) {
						$index[ $k ] = $id;
					}
				}
				$added++;
			}
		}
	}

	$removed = 0;
	$kept    = 0;
	if ( 'replace' === $mode ) {
		foreach ( $existing as $id => $row ) {
			if ( isset( $seen[ $id ] ) ) {
				continue;
			}
			if ( $row['user_id'] && get_userdata( $row['user_id'] ) ) {
				$kept++; // tied to an account: kept, so nobody loses their details
				continue;
			}
			ner_michoel_alumni_dir_delete( $id );
			$removed++;
		}
	}

	delete_transient( 'nm_alumni_dir_pending_' . preg_replace( '/[^a-f0-9]/', '', $token ) );
	update_option( 'nm_alumni_dir_last_upload', array( 'file' => $pending['file'], 'at' => time(), 'by' => get_current_user_id() ), false );
	$message = sprintf(
		/* translators: 1: added, 2: updated */
		__( 'Done: %1$s added, %2$s updated.', 'ner-michoel-core' ),
		number_format_i18n( $added ),
		number_format_i18n( $updated )
	);
	if ( 'replace' === $mode ) {
		$message .= ' ' . sprintf(
			/* translators: 1: removed, 2: kept */
			__( '%1$s not in the sheet were removed; %2$s not in the sheet were kept because they are tied to an account.', 'ner-michoel-core' ),
			number_format_i18n( $removed ),
			number_format_i18n( $kept )
		);
	}
	ner_michoel_alumni_dir_flash( $message );
	wp_safe_redirect( esc_url_raw( ner_michoel_alumni_dir_screen_url( 'alumnidirectory' ) ) );
	exit;
}
add_action( 'admin_post_nm_alumni_dir_apply', 'ner_michoel_handle_alumni_dir_apply' );

/* ================================================================== */
/* Panel screens                                                       */
/* ================================================================== */

function ner_michoel_alumni_dir_print_flash() {
	$flash = ner_michoel_alumni_dir_take_flash();
	if ( $flash ) {
		printf( '<div class="notice notice-%s"><p>%s</p></div>', $flash['error'] ? 'error' : 'success', esc_html( $flash['message'] ) );
	}
}

/** Control Panel > Alumni > Alumni Directory. */
function ner_michoel_render_alumni_directory_page() {
	if ( ! ner_michoel_alumni_dir_ready() ) {
		ner_michoel_alumni_dir_install();
	}
	wp_enqueue_style( 'ner-michoel-alumni-directory', NER_MICHOEL_CORE_URL . 'assets/alumni-directory.css', array(), NER_MICHOEL_CORE_VERSION );
	wp_enqueue_script( 'ner-michoel-alumni-directory', NER_MICHOEL_CORE_URL . 'assets/alumni-directory.js', array(), NER_MICHOEL_CORE_VERSION, true );
	wp_add_inline_script(
		'ner-michoel-alumni-directory',
		'window.nmAlumniDir = ' . wp_json_encode(
			array(
				'root'      => esc_url_raw( rest_url( 'ner-michoel/v1/alumni-dir' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'uploadUrl' => esc_url_raw( ner_michoel_alumni_dir_screen_url( 'alumniupload' ) ),
				'userUrl'   => esc_url_raw( admin_url( 'user-edit.php?user_id=' ) ),
			)
		) . ';',
		'before'
	);
	$last = get_option( 'nm_alumni_dir_last_upload' );
	?>
	<div class="wrap nm-dashboard nm-adir">
		<?php ner_michoel_alumni_dir_print_flash(); ?>
		<?php if ( is_array( $last ) && ! empty( $last['file'] ) ) : ?>
			<p class="description"><?php echo esc_html( sprintf( /* translators: 1: file name, 2: date */ __( 'Last sheet uploaded: %1$s, %2$s.', 'ner-michoel-core' ), $last['file'], wp_date( 'j M Y, g:i a', (int) $last['at'] ) ) ); ?></p>
		<?php endif; ?>
		<div id="nm-adir-app" class="nm-adir__app" aria-live="polite">
			<p><?php esc_html_e( 'Loading the directory…', 'ner-michoel-core' ); ?></p>
		</div>
		<noscript><p><?php esc_html_e( 'This table needs JavaScript.', 'ner-michoel-core' ); ?></p></noscript>
	</div>
	<?php
}

/** Control Panel > Alumni > Upload Alumni Sheet. */
function ner_michoel_render_alumni_upload_page() {
	$post_to = admin_url( 'admin-post.php' );
	$token   = isset( $_GET['review'] ) ? sanitize_text_field( wp_unslash( $_GET['review'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$pending = $token ? ner_michoel_alumni_dir_pending( $token ) : null;
	$count   = ner_michoel_alumni_dir_count();
	wp_enqueue_style( 'ner-michoel-alumni-directory', NER_MICHOEL_CORE_URL . 'assets/alumni-directory.css', array(), NER_MICHOEL_CORE_VERSION );
	?>
	<div class="wrap nm-dashboard nm-adir">
		<?php ner_michoel_alumni_dir_print_flash(); ?>

		<?php if ( ! $pending ) : ?>
			<?php if ( $token ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'That upload has expired. Please upload the file again.', 'ner-michoel-core' ); ?></p></div>
			<?php endif; ?>
			<p><?php echo esc_html( sprintf( /* translators: %s: number */ __( 'The directory has %s alumni now.', 'ner-michoel-core' ), number_format_i18n( $count ) ) ); ?></p>
			<form method="post" action="<?php echo esc_url( $post_to ); ?>" enctype="multipart/form-data" class="nm-adir__upload">
				<?php wp_nonce_field( 'nm_alumni_dir_upload', 'nm_alumni_dir_nonce' ); ?>
				<input type="hidden" name="action" value="nm_alumni_dir_upload" />
				<?php ner_michoel_panel_form_field(); ?>
				<p><input type="file" name="nm_alumni_file" accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" required /></p>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Upload and check', 'ner-michoel-core' ); ?></button></p>
				<p class="description"><?php esc_html_e( 'An Excel (.xlsx) or CSV file, one alumnus per row, with a heading row (First Name, Last Name, Wife, Email, Phone, Address, City, State, Zip, Years...). Nothing changes until you check the columns on the next step.', 'ner-michoel-core' ); ?></p>
			</form>
		<?php else : ?>
			<?php
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			$sheet_i = isset( $_GET['sheet'] ) ? absint( $_GET['sheet'] ) : 0;
			if ( ! isset( $pending['sheets'][ $sheet_i ] ) ) {
				$sheet_i = 0;
				$most    = 0;
				foreach ( $pending['sheets'] as $i => $s ) {
					if ( count( $s['rows'] ) > $most ) {
						$most    = count( $s['rows'] );
						$sheet_i = $i;
					}
				}
			}
			$sheet  = $pending['sheets'][ $sheet_i ];
			$header = isset( $_GET['header'] ) ? absint( $_GET['header'] ) - 1 : ner_michoel_alumni_dir_header_row( $sheet['rows'] );
			// phpcs:enable
			$header = max( 0, min( $header, count( $sheet['rows'] ) - 1 ) );
			$scols  = ner_michoel_alumni_dir_sheet_columns( $sheet['rows'], $header );
			$kinds  = ner_michoel_alumni_dir_kinds();
			$body   = array_slice( $sheet['rows'], $header + 1 );
			$here   = ner_michoel_alumni_dir_screen_url( 'alumniupload', array( 'review' => $token ) );
			?>
			<h2><?php echo esc_html( sprintf( /* translators: 1: file name, 2: rows */ __( 'Check %1$s (%2$s rows)', 'ner-michoel-core' ), $pending['file'], number_format_i18n( count( $body ) ) ) ); ?></h2>
			<?php if ( count( $pending['sheets'] ) > 1 ) : ?>
				<p><?php esc_html_e( 'Sheet:', 'ner-michoel-core' ); ?>
				<?php foreach ( $pending['sheets'] as $i => $s ) : ?>
					<?php if ( $i === $sheet_i ) : ?>
						<strong><?php echo esc_html( $s['name'] ); ?></strong>
					<?php else : ?>
						<a href="<?php echo esc_url( add_query_arg( 'sheet', $i, $here ) ); ?>"><?php echo esc_html( $s['name'] ); ?></a>
					<?php endif; ?>
				<?php endforeach; ?>
				</p>
			<?php endif; ?>
			<p class="description">
				<?php echo esc_html( sprintf( /* translators: %s: row number */ __( 'The headings were read from row %s.', 'ner-michoel-core' ), number_format_i18n( $header + 1 ) ) ); ?>
				<?php for ( $h = 1; $h <= min( 6, count( $sheet['rows'] ) ); $h++ ) : ?>
					<?php if ( $h !== $header + 1 ) : ?>
						<a href="<?php echo esc_url( add_query_arg( array( 'sheet' => $sheet_i, 'header' => $h ), $here ) ); ?>"><?php echo esc_html( sprintf( /* translators: %d: row */ __( 'Use row %d', 'ner-michoel-core' ), $h ) ); ?></a>
					<?php endif; ?>
				<?php endfor; ?>
			</p>

			<form method="post" action="<?php echo esc_url( $post_to ); ?>">
				<?php wp_nonce_field( 'nm_alumni_dir_apply', 'nm_alumni_dir_nonce' ); ?>
				<input type="hidden" name="action" value="nm_alumni_dir_apply" />
				<input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>" />
				<input type="hidden" name="sheet" value="<?php echo (int) $sheet_i; ?>" />
				<input type="hidden" name="header" value="<?php echo (int) $header; ?>" />
				<?php ner_michoel_panel_form_field(); ?>

				<div class="nm-adir__scroll">
				<table class="widefat striped nm-adir__review">
					<thead>
						<tr>
							<?php foreach ( $scols as $sc ) : ?>
								<th>
									<label class="nm-adir__use"><input type="checkbox" name="use[]" value="<?php echo (int) $sc['index']; ?>" <?php checked( ! $sc['empty'] ); ?> /> <?php esc_html_e( 'Use', 'ner-michoel-core' ); ?></label>
									<input type="text" name="label[<?php echo (int) $sc['index']; ?>]" value="<?php echo esc_attr( $sc['label'] ); ?>" class="nm-adir__label" aria-label="<?php esc_attr_e( 'Column name', 'ner-michoel-core' ); ?>" />
									<select name="kind[<?php echo (int) $sc['index']; ?>]" aria-label="<?php esc_attr_e( 'What this column is', 'ner-michoel-core' ); ?>">
										<?php foreach ( $kinds as $k => $label ) : ?>
											<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $sc['kind'], $k ); ?>><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
									<?php if ( $sc['into'] ) : ?>
										<span class="description"><?php esc_html_e( 'already in the directory', 'ner-michoel-core' ); ?></span>
									<?php else : ?>
										<span class="description"><?php esc_html_e( 'new column', 'ner-michoel-core' ); ?></span>
									<?php endif; ?>
								</th>
							<?php endforeach; ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( array_slice( $body, 0, 8 ) as $r ) : ?>
							<tr>
								<?php foreach ( $scols as $sc ) : ?>
									<td dir="auto"><?php echo nl2br( esc_html( isset( $r[ $sc['index'] ] ) ? $r[ $sc['index'] ] : '' ) ); ?></td>
								<?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				</div>
				<p class="description"><?php esc_html_e( 'The first 8 rows. Untick a column to leave it out; change a name or what a column is if it was guessed wrong. Names (First, Last, Full, Wife, Hebrew) and Email are what alumni are found by when they sign up.', 'ner-michoel-core' ); ?></p>

				<p>
					<label for="nm_adir_optin"><?php esc_html_e( 'A column that says who wants emails (yes / no), if the sheet has one:', 'ner-michoel-core' ); ?></label>
					<select name="optin_col" id="nm_adir_optin">
						<option value=""><?php esc_html_e( 'None', 'ner-michoel-core' ); ?></option>
						<?php foreach ( $scols as $sc ) : ?>
							<option value="<?php echo (int) $sc['index']; ?>" <?php selected( (bool) preg_match( '/opt|subscrib|newsletter|mailing/i', $sc['label'] ) ); ?>><?php echo esc_html( $sc['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>

				<fieldset>
					<p><label><input type="radio" name="mode" value="merge" checked /> <strong><?php esc_html_e( 'Add to the directory and update', 'ner-michoel-core' ); ?></strong> &mdash; <?php esc_html_e( 'rows already here (same email, or same first + last name) are updated; new ones are added; nothing is removed.', 'ner-michoel-core' ); ?></label></p>
					<p><label><input type="radio" name="mode" value="replace" /> <strong><?php esc_html_e( 'Replace the directory', 'ner-michoel-core' ); ?></strong> &mdash; <?php esc_html_e( 'the same, and rows not in this sheet are removed (except those tied to an account).', 'ner-michoel-core' ); ?></label></p>
				</fieldset>
				<p class="description"><?php esc_html_e( 'Details an alumnus changed on the website themselves are kept, not overwritten by the sheet.', 'ner-michoel-core' ); ?></p>
				<p>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Use this sheet', 'ner-michoel-core' ); ?></button>
					<a class="button" href="<?php echo esc_url( ner_michoel_alumni_dir_screen_url( 'alumniupload' ) ); ?>"><?php esc_html_e( 'Cancel', 'ner-michoel-core' ); ?></a>
				</p>
			</form>
		<?php endif; ?>
	</div>
	<?php
}
