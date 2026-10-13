<?php
/**
 * Tidy the titles of the audio, video and PDF files already in the Library.
 *
 * New uploads get a clean title ("Bava Kama") instead of their file name ("bava-kama") from includes/file-names.php.
 * The files that were added before that still have the file name as their title, and the importer made most of
 * them ("100-bava-metzia-43a-tavroh-shasyah"). This gives them the same clean titles, from the Library Import page.
 *
 * Only the title changes. The file, its name and its address are not touched, so nothing on the site moves.
 *
 * - A title is only changed when it is just the file's name: no spaces, the same words as the file (so a title
 *   somebody typed, or one already made clean, is never touched).
 * - A file that belongs to a shiur takes that shiur's own title when it matches the file name ("Mitzvas Viduy in
 *   Tefillah (Haazinu 5787)"), otherwise the clean version of the file name.
 * - A file that is a second copy of another (its name ends -1 and the first copy is there) is left alone and
 *   listed, so the copy can be deleted and the name is free again.
 * - Files that share a name (apart from case, punctuation and the old upload stamp) are listed, because two of them
 *   look alike in every list. Nothing is deleted: that is for a person to decide.
 * - Each changed title remembers the old one (post meta _nm_title_tidy), and "Undo" puts it back, unless the title
 *   was changed again by hand since.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NER_MICHOEL_TIDY_META', '_nm_title_tidy' );

/**
 * The next rows of audio, video and PDF files after $after_id, looked at for tidying. Nothing is written.
 *
 * @return array {
 *     @type int   $scanned Rows looked at.
 *     @type int   $last    The last row's ID (0 when there were none).
 *     @type array $items   Titles to change: array( id, old, new, file ).
 *     @type int   $typed   Rows whose title is not a bare file name (typed by hand, or already clean): left alone.
 *     @type int   $clean   Rows already tidy.
 *     @type array $twins   Second copies left alone: array( id, file ).
 *     @type array $names   With $with_names: every row's name, array( id, "kind:name key", file ), to find files that share a name.
 * }
 */
function ner_michoel_tidy_scan_chunk( $after_id, $limit = 500, $with_names = false ) {
	global $wpdb;

	// %% is a literal percent sign for prepare(); the rest of the clause is fixed text.
	$kinds = "( p.post_mime_type LIKE 'audio/%%' OR p.post_mime_type LIKE 'video/%%' OR p.post_mime_type = 'application/pdf' )";
	$sql   = "SELECT p.ID, p.post_title, p.post_parent, p.post_mime_type, m.meta_value AS file
		FROM {$wpdb->posts} p
		INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attached_file'
		WHERE p.post_type = 'attachment' AND p.ID > %d AND {$kinds}
		ORDER BY p.ID ASC LIMIT %d";
	$rows  = (array) $wpdb->get_results( $wpdb->prepare( $sql, (int) $after_id, (int) $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

	$out = array(
		'scanned' => count( $rows ),
		'last'    => $rows ? (int) end( $rows )->ID : 0,
		'items'   => array(),
		'typed'   => 0,
		'clean'   => 0,
		'twins'   => array(),
		'names'   => array(),
	);
	if ( ! $rows ) {
		return $out;
	}

	// The shiur each file belongs to, for its own title.
	$parent_ids = array();
	foreach ( $rows as $row ) {
		if ( (int) $row->post_parent ) {
			$parent_ids[ (int) $row->post_parent ] = (int) $row->post_parent;
		}
	}
	$parent_titles = array();
	if ( $parent_ids ) {
		$in = implode( ',', $parent_ids );
		foreach ( (array) $wpdb->get_results( "SELECT ID, post_title FROM {$wpdb->posts} WHERE ID IN ({$in}) AND post_type IN ('shiur','written_shiur')" ) as $parent ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the IDs are integers.
			$parent_titles[ (int) $parent->ID ] = $parent->post_title;
		}
	}

	$pending = array();
	foreach ( $rows as $row ) {
		$base = wp_basename( (string) $row->file );
		$base_key = ner_michoel_name_key( $base );
		if ( $with_names ) {
			$out['names'][] = array( (int) $row->ID, ( 'application/pdf' === $row->post_mime_type ? 'pdf:' : 'media:' ) . $base_key, (string) $row->file );
		}
		// A title WordPress made from a file name is the name itself: no spaces, the same words as the file (WordPress's own number on the file aside). Anything else was written (or already tidied).
		if ( '' === $base_key || preg_match( '/\s/u', trim( (string) $row->post_title ) ) || ! ner_michoel_name_matches_file( $row->post_title, $base ) ) {
			$out['typed']++;
			continue;
		}

		$new = '';
		if ( isset( $parent_titles[ (int) $row->post_parent ] ) && ner_michoel_name_matches_file( $parent_titles[ (int) $row->post_parent ], $row->post_title ) ) {
			$new = $parent_titles[ (int) $row->post_parent ];
		}
		if ( '' === $new ) {
			$new = ner_michoel_title_from_filename( $row->post_title );
		}
		if ( '' === $new || $new === $row->post_title ) {
			$out['clean']++;
			continue;
		}
		$pending[] = array( (int) $row->ID, $row->post_title, $new, (string) $row->file, $base );
	}

	// A file whose name ends -1 (or -2 ...) next to a file with the same name without it is a second copy WordPress numbered.
	$twin_of = array();
	foreach ( $pending as $i => $item ) {
		if ( preg_match( '/^(.+)-[0-9]{1,3}(\.[A-Za-z0-9]+)$/', $item[4], $m ) ) {
			$dir              = dirname( $item[3] );
			$twin_of[ $i ]    = ( '.' === $dir ? '' : $dir . '/' ) . $m[1] . $m[2];
		}
	}
	$existing = array();
	if ( $twin_of ) {
		$places = implode( ',', array_fill( 0, count( $twin_of ), '%s' ) );
		$found  = (array) $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value IN ({$places})", array_values( $twin_of ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one %s per file.
		$existing = array_flip( $found );
	}
	foreach ( $pending as $i => $item ) {
		if ( isset( $twin_of[ $i ] ) && isset( $existing[ $twin_of[ $i ] ] ) ) {
			$out['twins'][] = array( $item[0], $item[3] );
			continue;
		}
		$out['items'][] = array( $item[0], $item[1], $item[2], $item[3] );
	}

	return $out;
}

/**
 * Everything the Library holds, looked at (nothing written): how many titles would change, a few examples, the
 * second copies, and what is already done.
 */
function ner_michoel_tidy_summary() {
	$total = array(
		'files'    => 0,
		'change'   => 0,
		'typed'    => 0,
		'clean'    => 0,
		'twins'    => array(),
		'same'     => array(),
		'examples' => array(),
		'tidied'   => 0,
	);
	$groups = array();
	$after  = 0;
	for ( $round = 0; $round < 100; $round++ ) { // Up to 50,000 files.
		$chunk = ner_michoel_tidy_scan_chunk( $after, 500, true );
		if ( ! $chunk['scanned'] ) {
			break;
		}
		foreach ( $chunk['names'] as $name ) {
			if ( ! preg_match( '/:$/', $name[1] ) ) { // A name with nothing in it can't be compared.
				$groups[ $name[1] ][] = $name[2];
			}
		}
		$total['files']  += $chunk['scanned'];
		$total['change'] += count( $chunk['items'] );
		$total['typed']  += $chunk['typed'];
		$total['clean']  += $chunk['clean'];
		$total['twins']   = array_merge( $total['twins'], $chunk['twins'] );
		if ( count( $total['examples'] ) < 8 ) {
			$total['examples'] = array_merge( $total['examples'], array_slice( $chunk['items'], 0, 8 - count( $total['examples'] ) ) );
		}
		$after = $chunk['last'];
		if ( $chunk['scanned'] < 500 ) {
			break;
		}
	}

	// Files that share a name (case, punctuation and the old site's number stamp aside): two of them look alike in every list.
	foreach ( $groups as $files ) {
		if ( count( $files ) > 1 ) {
			$total['same'][] = $files;
		}
	}

	global $wpdb;
	$total['tidied'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", NER_MICHOEL_TIDY_META ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	return $total;
}

/**
 * Changes the titles, in rounds of 500 files, for up to $seconds. Remembers each old title.
 *
 * @return array { @type int $changed  @type bool $finished }
 */
function ner_michoel_tidy_run( $seconds = 20 ) {
	global $wpdb;
	$deadline = microtime( true ) + max( 3, (int) $seconds );
	$changed  = 0;
	$after    = 0;

	for ( $round = 0; $round < 200; $round++ ) {
		$chunk = ner_michoel_tidy_scan_chunk( $after, 500 );
		if ( ! $chunk['scanned'] ) {
			return array( 'changed' => $changed, 'finished' => true );
		}
		foreach ( $chunk['items'] as $item ) {
			list( $id, $old, $new ) = $item;
			// Written straight to the table: no save hooks run for thousands of files, and the file itself is not touched.
			$wpdb->update( $wpdb->posts, array( 'post_title' => $new ), array( 'ID' => $id ) );
			update_post_meta( $id, NER_MICHOEL_TIDY_META, wp_slash( array( 'old' => $old, 'new' => $new ) ) ); // wp_slash: the meta functions take slashed data.
			clean_post_cache( $id );
			$changed++;
		}
		$after = $chunk['last'];
		if ( $chunk['scanned'] < 500 ) {
			return array( 'changed' => $changed, 'finished' => true );
		}
		if ( microtime( true ) > $deadline ) {
			return array( 'changed' => $changed, 'finished' => false );
		}
	}
	return array( 'changed' => $changed, 'finished' => false );
}

/**
 * Puts the old titles back. A title that was changed by hand after the tidy is left as it is.
 *
 * @return array { @type int $restored  @type int $kept  @type bool $finished }
 */
function ner_michoel_tidy_undo( $seconds = 20 ) {
	global $wpdb;
	$deadline = microtime( true ) + max( 3, (int) $seconds );
	$restored = 0;
	$kept     = 0;

	for ( $round = 0; $round < 200; $round++ ) {
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY meta_id ASC LIMIT 500", NER_MICHOEL_TIDY_META ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $rows ) {
			return array( 'restored' => $restored, 'kept' => $kept, 'finished' => true );
		}
		foreach ( $rows as $row ) {
			$id   = (int) $row->post_id;
			$memo = maybe_unserialize( $row->meta_value );
			$post = get_post( $id );
			if ( $post && is_array( $memo ) && isset( $memo['old'], $memo['new'] ) && $post->post_title === $memo['new'] ) {
				$wpdb->update( $wpdb->posts, array( 'post_title' => $memo['old'] ), array( 'ID' => $id ) );
				clean_post_cache( $id );
				$restored++;
			} else {
				$kept++;
			}
			delete_post_meta( $id, NER_MICHOEL_TIDY_META );
		}
		if ( microtime( true ) > $deadline ) {
			return array( 'restored' => $restored, 'kept' => $kept, 'finished' => false );
		}
	}
	return array( 'restored' => $restored, 'kept' => $kept, 'finished' => false );
}

function ner_michoel_handle_tidy_titles() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'ner-michoel-core' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'nm_tidy_titles', 'nm_tidy_nonce' );

	$undo   = ! empty( $_POST['nm_tidy_undo'] );
	$result = $undo ? ner_michoel_tidy_undo() : ner_michoel_tidy_run();
	$args   = array(
		'nm_tidy'  => $undo ? 'undone' : 'done',
		'nm_n'     => $undo ? $result['restored'] : $result['changed'],
		'nm_more'  => $result['finished'] ? 0 : 1,
		'nm_kept'  => $undo ? $result['kept'] : 0,
	);
	wp_safe_redirect( ner_michoel_panel_redirect_url( admin_url( 'admin.php?page=nm-library-import' ), 'library', $args ) );
	exit;
}
add_action( 'admin_post_nm_tidy_titles', 'ner_michoel_handle_tidy_titles' );

/**
 * The "File titles" part of the Library Import page (called from ner_michoel_render_library_import_page()).
 */
function ner_michoel_render_tidy_titles_card() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$summary = ner_michoel_tidy_summary();

	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$done  = isset( $_GET['nm_tidy'] ) ? sanitize_key( wp_unslash( $_GET['nm_tidy'] ) ) : '';
	$count = isset( $_GET['nm_n'] ) ? absint( $_GET['nm_n'] ) : 0;
	$more  = ! empty( $_GET['nm_more'] );
	$kept  = isset( $_GET['nm_kept'] ) ? absint( $_GET['nm_kept'] ) : 0;
	// phpcs:enable
	?>
	<div id="nm-tidy-titles">
	<h2><?php esc_html_e( 'File titles', 'ner-michoel-core' ); ?></h2>

	<?php if ( 'done' === $done || 'undone' === $done ) : ?>
		<div class="notice notice-success">
			<p>
				<?php
				if ( 'done' === $done ) {
					/* translators: %s: number of titles */
					echo esc_html( sprintf( _n( '%s title tidied.', '%s titles tidied.', $count, 'ner-michoel-core' ), number_format_i18n( $count ) ) );
				} else {
					/* translators: %s: number of titles */
					echo esc_html( sprintf( _n( '%s title put back.', '%s titles put back.', $count, 'ner-michoel-core' ), number_format_i18n( $count ) ) );
					if ( $kept ) {
						/* translators: %s: number of titles */
						echo ' ' . esc_html( sprintf( _n( '%s was changed by hand since, so it was left as it is.', '%s were changed by hand since, so they were left as they are.', $kept, 'ner-michoel-core' ), number_format_i18n( $kept ) ) );
					}
				}
				if ( $more ) {
					echo ' ' . esc_html__( 'There are more: press the button again to carry on.', 'ner-michoel-core' );
				}
				?>
			</p>
		</div>
	<?php endif; ?>

	<p><?php esc_html_e( 'Audio, video and PDF files in the library were titled after their file names, like “100-bava-metzia-43a-tavroh-shasyah”. New uploads now get a clean title (“100 Bava Metzia 43a Tavroh Shasyah”). This gives the files already here the same clean titles. Only the title changes: the files, their names and their addresses stay exactly as they are, so nothing on the site moves. A title somebody typed is never touched, and “Undo” puts the old titles back.', 'ner-michoel-core' ); ?></p>

	<table class="widefat striped" style="max-width:700px;">
		<tbody>
			<tr><th><?php esc_html_e( 'Audio, video and PDF files', 'ner-michoel-core' ); ?></th><td><?php echo esc_html( number_format_i18n( $summary['files'] ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Titles that are still a file name', 'ner-michoel-core' ); ?></th><td><?php echo esc_html( number_format_i18n( $summary['change'] ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Titles already written or tidy (left alone)', 'ner-michoel-core' ); ?></th><td><?php echo esc_html( number_format_i18n( $summary['typed'] + $summary['clean'] ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Second copies (left alone)', 'ner-michoel-core' ); ?></th><td><?php echo esc_html( number_format_i18n( count( $summary['twins'] ) ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Names that two or more files share', 'ner-michoel-core' ); ?></th><td><?php echo esc_html( number_format_i18n( count( $summary['same'] ) ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Tidied so far (can be undone)', 'ner-michoel-core' ); ?></th><td><?php echo esc_html( number_format_i18n( $summary['tidied'] ) ); ?></td></tr>
		</tbody>
	</table>

	<?php if ( $summary['examples'] ) : ?>
		<p><strong><?php esc_html_e( 'A few of the titles that would change:', 'ner-michoel-core' ); ?></strong></p>
		<table class="widefat striped" style="max-width:900px;">
			<thead><tr><th><?php esc_html_e( 'Now', 'ner-michoel-core' ); ?></th><th><?php esc_html_e( 'Would become', 'ner-michoel-core' ); ?></th></tr></thead>
			<tbody>
				<?php foreach ( $summary['examples'] as $example ) : ?>
					<tr><td><?php echo esc_html( $example[1] ); ?></td><td><?php echo esc_html( $example[2] ); ?></td></tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<?php if ( $summary['twins'] ) : ?>
		<p class="description">
			<?php esc_html_e( 'These look like a second copy of a file that is also in the library (the name ends -1, -2 and so on). They are left as they are; delete the extra copy and its name is free again:', 'ner-michoel-core' ); ?>
			<?php echo esc_html( implode( ', ', array_map( function ( $twin ) { return wp_basename( $twin[1] ); }, array_slice( $summary['twins'], 0, 10 ) ) ) ); ?>
			<?php echo count( $summary['twins'] ) > 10 ? esc_html( '…' ) : ''; ?>
		</p>
	<?php endif; ?>

	<?php if ( $summary['same'] ) : ?>
		<p class="description nm-tidy-same">
			<?php esc_html_e( 'These files have the same name, so they look alike in every list. If they are the same recording, keep one and delete the other from the media library:', 'ner-michoel-core' ); ?>
			<?php
			echo esc_html(
				implode(
					'; ',
					array_map(
						function ( $files ) {
							return implode( ' = ', array_map( 'wp_basename', array_slice( $files, 0, 4 ) ) );
						},
						array_slice( $summary['same'], 0, 6 )
					)
				)
			);
			echo count( $summary['same'] ) > 6 ? esc_html( '…' ) : '';
			?>
		</p>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px;">
		<?php wp_nonce_field( 'nm_tidy_titles', 'nm_tidy_nonce' ); ?>
		<input type="hidden" name="action" value="nm_tidy_titles" />
		<?php ner_michoel_panel_form_field(); ?>
		<p>
			<button type="submit" class="button button-primary" <?php disabled( 0 === $summary['change'] ); ?>><?php esc_html_e( 'Tidy the titles', 'ner-michoel-core' ); ?></button>
			<button type="submit" class="button" name="nm_tidy_undo" value="1" <?php disabled( 0 === $summary['tidied'] ); ?>><?php esc_html_e( 'Undo', 'ner-michoel-core' ); ?></button>
		</p>
	</form>
	</div>
	<?php
}
