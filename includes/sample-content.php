<?php
/**
 * One-click "Import Sample Content" for the Site Control Panel — seeds
 * this rebuild with a fixed, hardcoded batch of real content pulled from
 * the live nermichoel.org site (2 speakers, 1 series, 5 shiurim with
 * their actual audio), so the rebuild isn't empty while content
 * migration is still in progress.
 *
 * Restricted to `manage_options` (not the usual `edit_posts` this panel
 * otherwise uses) since this is a one-time migration action, not routine
 * day-to-day editing, and it reaches out to an external URL per shiur.
 *
 * Idempotent by design: re-running skips any shiur whose title already
 * exists (any status) and any speaker/series term that already exists,
 * so clicking the button twice never duplicates content.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fixed seed dataset. Not meant to grow into a general importer —
 * if more content needs importing later, that's a different feature.
 */
function ner_michoel_sample_content_dataset() {
	return array(
		array(
			'title'    => '025 Bava Kamma 28a- Eved Nirtzah Shemisarvim Bo',
			'speaker'  => 'Rabbi Klein',
			'series'   => 'Bava Kamma',
			'date'     => '2026-09-17',
			'duration' => '60:25',
			'audio'    => 'https://media.nermichoel.org/1789723459_025-Bava-Kamma-28a-Eved-Nirtzah-Shemisarvim-Bo.mp3',
		),
		array(
			'title'    => 'Bava Kamma Shiur #25 Daf 27a',
			'speaker'  => 'Rosh HaYeshiva',
			'series'   => 'Bava Kamma',
			'date'     => '2026-09-17',
			'duration' => '46:49',
			'audio'    => 'https://media.nermichoel.org/1789723206_Bava-Kamma-Shiur-25-Daf-27a.mp3',
		),
		array(
			'title'    => 'Bava Kamma Shiur #24 Daf 27a',
			'speaker'  => 'Rosh HaYeshiva',
			'series'   => 'Bava Kamma',
			'date'     => '2026-09-16',
			'duration' => '43:44',
			'audio'    => 'https://media.nermichoel.org/1789720885_Bava-Kamma-Shiur-24-Daf-27a.mp3',
		),
		array(
			'title'    => '024 Bava Kamma 28a- Gneiva Gzeila Shor Sheoloh',
			'speaker'  => 'Rabbi Klein',
			'series'   => 'Bava Kamma',
			'date'     => '2026-09-16',
			'duration' => '61:35',
			'audio'    => 'https://media.nermichoel.org/1789720944_024-Bava-Kamma-28a-Gneiva-Gzeila-Shor-Sheoloh.mp3',
		),
		array(
			'title'    => 'Bava Kamma 45b-46a (night)',
			'speaker'  => 'Rabbi Klein',
			'series'   => 'Bava Kamma',
			'date'     => '2026-09-16',
			'duration' => '28:06',
			'audio'    => 'https://media.nermichoel.org/1789722293_019-Bava-Kamma-45b-46a.mp3',
		),
	);
}

function ner_michoel_render_sample_content_page() {
	$dataset = ner_michoel_sample_content_dataset();
	$speakers = array_unique( wp_list_pluck( $dataset, 'speaker' ) );
	$series   = array_unique( wp_list_pluck( $dataset, 'series' ) );
	?>
	<div class="wrap nm-dashboard">
		<h1><?php esc_html_e( 'Import Sample Content', 'ner-michoel-core' ); ?></h1>

		<?php if ( isset( $_GET['nm_import'] ) && 'done' === $_GET['nm_import'] ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-success">
				<p>
					<?php
					printf(
						/* translators: 1: shiurim created, 2: shiurim skipped (already existed), 3: audio attachments that failed to download */
						esc_html__( 'Import finished: %1$d shiur(im) created, %2$d skipped (already existed), %3$d audio file(s) failed to download (post still created, no audio attached).', 'ner-michoel-core' ),
						isset( $_GET['created'] ) ? absint( $_GET['created'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
						isset( $_GET['skipped'] ) ? absint( $_GET['skipped'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
						isset( $_GET['audio_failed'] ) ? absint( $_GET['audio_failed'] ) : 0 // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<p><?php esc_html_e( 'Seeds this site with a fixed batch of real content pulled from the live nermichoel.org site, so the rebuild isn\'t empty while migration is in progress. Safe to click more than once — anything already imported (matched by title) is skipped, not duplicated.', 'ner-michoel-core' ); ?></p>

		<h2><?php esc_html_e( 'What this creates', 'ner-michoel-core' ); ?></h2>
		<ul>
			<li><?php echo esc_html( sprintf( /* translators: %s: comma-separated speaker names */ __( 'Speakers (if not already present): %s', 'ner-michoel-core' ), implode( ', ', $speakers ) ) ); ?></li>
			<li><?php echo esc_html( sprintf( /* translators: %s: comma-separated series names */ __( 'Series (if not already present): %s', 'ner-michoel-core' ), implode( ', ', $series ) ) ); ?></li>
		</ul>
		<table class="widefat striped" style="max-width:900px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Shiur', 'ner-michoel-core' ); ?></th>
					<th><?php esc_html_e( 'Speaker', 'ner-michoel-core' ); ?></th>
					<th><?php esc_html_e( 'Duration', 'ner-michoel-core' ); ?></th>
					<th><?php esc_html_e( 'Date', 'ner-michoel-core' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $dataset as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row['title'] ); ?></td>
						<td><?php echo esc_html( $row['speaker'] ); ?></td>
						<td><?php echo esc_html( $row['duration'] ); ?></td>
						<td><?php echo esc_html( $row['date'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description"><?php esc_html_e( 'Each shiur\'s real audio file is downloaded from nermichoel.org and attached automatically, the same as a manual upload.', 'ner-michoel-core' ); ?></p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'nm_import_sample_content', 'nm_import_sample_content_nonce' ); ?>
			<input type="hidden" name="action" value="nm_import_sample_content" />
			<p>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Import Sample Content Now', 'ner-michoel-core' ); ?></button>
			</p>
		</form>
	</div>
	<?php
}

/**
 * Finds an existing `speaker`/`series` term by name, creating it if
 * missing, and returns its term_id.
 */
function ner_michoel_sample_content_get_or_create_term( $name, $taxonomy ) {
	$term = get_term_by( 'name', $name, $taxonomy );
	if ( $term ) {
		return $term->term_id;
	}
	$result = wp_insert_term( $name, $taxonomy );
	if ( is_wp_error( $result ) ) {
		return 0;
	}
	return $result['term_id'];
}

/**
 * Downloads $url into the media library and attaches it to $post_id,
 * the same mechanism the "Upload Media" screen uses under the hood.
 * Returns the new attachment ID, or 0 if the download/attach fails —
 * the caller creates the shiur post regardless, just without audio.
 */
function ner_michoel_sample_content_sideload_audio( $url, $post_id ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$tmp_file = download_url( $url );
	if ( is_wp_error( $tmp_file ) ) {
		return 0;
	}

	$file_array = array(
		'name'     => wp_basename( wp_parse_url( $url, PHP_URL_PATH ) ),
		'tmp_name' => $tmp_file,
	);

	$attachment_id = media_handle_sideload( $file_array, $post_id );

	if ( is_wp_error( $attachment_id ) ) {
		if ( file_exists( $tmp_file ) ) {
			wp_delete_file( $tmp_file );
		}
		return 0;
	}

	return $attachment_id;
}

function ner_michoel_handle_import_sample_content() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'ner-michoel-core' ) );
	}
	check_admin_referer( 'nm_import_sample_content', 'nm_import_sample_content_nonce' );

	$created      = 0;
	$skipped      = 0;
	$audio_failed = 0;

	foreach ( ner_michoel_sample_content_dataset() as $row ) {
		$existing = get_posts(
			array(
				'post_type'      => 'shiur',
				'title'          => $row['title'],
				'post_status'    => 'any',
				'numberposts'    => 1,
				'fields'         => 'ids',
			)
		);

		if ( $existing ) {
			$skipped++;
			continue;
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'shiur',
				'post_title'  => $row['title'],
				'post_status' => 'publish',
				'post_date'   => $row['date'] . ' 00:00:00',
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			continue;
		}

		$created++;

		update_post_meta( $post_id, '_shiur_duration', sanitize_text_field( $row['duration'] ) );

		$speaker_id = ner_michoel_sample_content_get_or_create_term( $row['speaker'], 'speaker' );
		if ( $speaker_id ) {
			wp_set_object_terms( $post_id, array( $speaker_id ), 'speaker' );
		}

		$series_id = ner_michoel_sample_content_get_or_create_term( $row['series'], 'series' );
		if ( $series_id ) {
			wp_set_object_terms( $post_id, array( $series_id ), 'series' );
		}

		$attachment_id = ner_michoel_sample_content_sideload_audio( $row['audio'], $post_id );
		if ( $attachment_id ) {
			update_post_meta( $post_id, '_shiur_audio_id', $attachment_id );
		} else {
			$audio_failed++;
		}
	}

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'         => 'nm-import-sample-content',
				'nm_import'    => 'done',
				'created'      => $created,
				'skipped'      => $skipped,
				'audio_failed' => $audio_failed,
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}
add_action( 'admin_post_nm_import_sample_content', 'ner_michoel_handle_import_sample_content' );
