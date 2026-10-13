<?php
/**
 * Advanced > Backups in the Control Panel: how the backups stand (the one running, with its progress), the
 * three copies on Bunny with a Download button each, the last runs, and the settings (on or off, the hour,
 * email on failure, a Storage Zone of the backups' own). The engine is backups.php; assets/backups.js keeps
 * the page up to date through its REST routes and draws the parts that change.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What assets/backups.js needs: where to ask, and the screen's words.
 */
function ner_michoel_backup_screen_config() {
	return array(
		'restUrl'    => esc_url_raw( rest_url( 'ner-michoel/v1/backups' ) ),
		'nonce'      => wp_create_nonce( 'wp_rest' ),
		'storageUrl' => esc_url_raw( ner_michoel_custom_admin_url( 'storage' ) ),
		'text'       => array(
			'notSetTitle'   => __( 'Bunny storage isn’t set up yet', 'ner-michoel-core' ),
			'notSet'        => __( 'The backups are kept on Bunny. Fill in Advanced > Storage (Bunny) first, then come back here.', 'ner-michoel-core' ),
			'openStorage'   => __( 'Open Storage (Bunny)', 'ner-michoel-core' ),
			'runningTitle'  => __( 'Backing up now', 'ner-michoel-core' ),
			/* translators: 1: when it started, 2: list of copies */
			'runningSub'    => __( 'Started %1$s. Replaces the %2$s copy when it’s done.', 'ner-michoel-core' ),
			'retrying'      => __( 'Had a problem and tries again in a minute or two: ', 'ner-michoel-core' ),
			'stop'          => __( 'Stop', 'ner-michoel-core' ),
			'stopTitle'     => __( 'Stop this backup?', 'ner-michoel-core' ),
			'stopText'      => __( 'The copies already on Bunny stay as they are. You can start again any time.', 'ner-michoel-core' ),
			'stopYes'       => __( 'Stop the backup', 'ner-michoel-core' ),
			'keepGoing'     => __( 'Keep going', 'ner-michoel-core' ),
			'stopped'       => __( 'Stopped. The copies on Bunny weren’t changed.', 'ner-michoel-core' ),
			'okTitle'       => __( 'Backups are up to date', 'ner-michoel-core' ),
			/* translators: 1: when, 2: size */
			'lastOk'        => __( 'Last backup: %1$s (%2$s).', 'ner-michoel-core' ),
			/* translators: %s: when */
			'next'          => __( 'Next: %s.', 'ner-michoel-core' ),
			'nightlyOff'    => __( 'Nightly backups are off. You can still back up by hand.', 'ner-michoel-core' ),
			'failedTitle'   => __( 'The last backup didn’t finish', 'ner-michoel-core' ),
			'failedKeep'    => __( 'The copies already on Bunny weren’t touched.', 'ner-michoel-core' ),
			'overdueTitle'  => __( 'No backup for over a day', 'ner-michoel-core' ),
			/* translators: %s: when */
			'overdue'       => __( 'The last good backup was %s. The nightly backup starts when the site has a visitor after its hour, so on a very quiet night it can be late. Press Back up now to make one.', 'ner-michoel-core' ),
			'noneTitle'     => __( 'No backup yet', 'ner-michoel-core' ),
			/* translators: %s: when */
			'noneNext'      => __( 'The first one runs %s, or press Back up now.', 'ner-michoel-core' ),
			'none'          => __( 'Press Back up now to make the first one.', 'ner-michoel-core' ),
			'backUpNow'     => __( 'Back up now', 'ner-michoel-core' ),
			'starting'      => __( 'Starting…', 'ner-michoel-core' ),
			'started'       => __( 'Backup started. It carries on by itself if you leave this page.', 'ner-michoel-core' ),
			'done'          => __( 'Backup finished and checked on Bunny.', 'ner-michoel-core' ),
			'failedToast'   => __( 'The backup didn’t finish. See the details at the top.', 'ner-michoel-core' ),
			'slotDaily'     => __( 'Daily copy', 'ner-michoel-core' ),
			'slotMonthly'   => __( 'Monthly copy', 'ner-michoel-core' ),
			'slotBimonthly' => __( 'Every two months', 'ner-michoel-core' ),
			'slotDailySub'  => __( 'Replaced every night', 'ner-michoel-core' ),
			'slotMonthSub'  => __( 'Replaced on the 1st of each month', 'ner-michoel-core' ),
			'slotBiSub'     => __( 'Replaced on the 1st of every other month', 'ner-michoel-core' ),
			'slotEmpty'     => __( 'Empty for now: the next backup fills it.', 'ner-michoel-core' ),
			'slotGone'      => __( 'This copy isn’t on Bunny any more (deleted there?). The next backup fills the slot again.', 'ner-michoel-core' ),
			'slotCheckFail' => __( 'Couldn’t check Bunny just now: ', 'ner-michoel-core' ),
			'download'      => __( 'Download', 'ner-michoel-core' ),
			/* translators: 1, 2: numbers */
			'piece'         => __( 'Piece %1$s of %2$s', 'ner-michoel-core' ),
			'colWhen'       => __( 'When', 'ner-michoel-core' ),
			'colHow'        => __( 'How', 'ner-michoel-core' ),
			'colResult'     => __( 'Result', 'ner-michoel-core' ),
			'colSize'       => __( 'Size', 'ner-michoel-core' ),
			'colTook'       => __( 'Took', 'ner-michoel-core' ),
			'howSchedule'   => __( 'Nightly', 'ner-michoel-core' ),
			'howManual'     => __( 'By hand', 'ner-michoel-core' ),
			/* translators: %s: list of copies */
			'resultOk'      => __( 'Done: %s', 'ner-michoel-core' ),
			'resultFailed'  => __( 'Didn’t finish: ', 'ner-michoel-core' ),
			'resultStopped' => __( 'Stopped by hand', 'ner-michoel-core' ),
			'noRuns'        => __( 'No backups have run yet.', 'ner-michoel-core' ),
			'daily'         => __( 'daily', 'ner-michoel-core' ),
			'monthly'       => __( 'monthly', 'ner-michoel-core' ),
			'bimonthly'     => __( 'every-two-months', 'ner-michoel-core' ),
			'and'           => __( 'and', 'ner-michoel-core' ),
			'saving'        => __( 'Saving…', 'ner-michoel-core' ),
			'savingZone'    => __( 'Checking the Storage Zone with Bunny…', 'ner-michoel-core' ),
			'saved'         => __( 'Saved.', 'ner-michoel-core' ),
			'keySaved'      => __( 'Saved: leave empty to keep it', 'ner-michoel-core' ),
			'removeZoneQ'   => __( 'Keep the backups in the media Storage Zone again?', 'ner-michoel-core' ),
			'removeZoneT'   => __( 'The next backup goes back to the media zone and fills all three copies there. The copies in the separate zone stay there until you delete them in bunny.net.', 'ner-michoel-core' ),
			'removeZoneYes' => __( 'Use the media zone', 'ner-michoel-core' ),
			'cancel'        => __( 'Cancel', 'ner-michoel-core' ),
			'error'         => __( 'Something went wrong: ', 'ner-michoel-core' ),
			/* translators: 1: Storage Zone name, 2: folder name */
			'whereMedia'    => __( 'On Bunny, in the media Storage Zone “%1$s”, folder %2$s.', 'ner-michoel-core' ),
			/* translators: 1: Storage Zone name, 2: folder name */
			'whereOwn'      => __( 'On Bunny, in the backups’ own Storage Zone “%1$s”, folder %2$s.', 'ner-michoel-core' ),
			'whereNone'     => __( 'Nowhere yet: Bunny storage isn’t set up.', 'ner-michoel-core' ),
			'hintMedia'     => __( 'A file in the media zone can be downloaded through its public address by anyone who knows its exact name. The folder has a long random name, shown only here. For full privacy, use a separate Storage Zone (below).', 'ner-michoel-core' ),
			'hintOwn'       => __( 'With no Pull Zone connected to it, only someone with its password can read it.', 'ner-michoel-core' ),
			'seconds'       => __( 's', 'ner-michoel-core' ),
			'minutes'       => __( 'min', 'ner-michoel-core' ),
		),
	);
}

function ner_michoel_render_backups_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$data = ner_michoel_backup_status_data();
	$s    = $data['settings'];
	$tz   = wp_timezone_string();
	?>
	<div class="nmb" data-nmb-initial="<?php echo esc_attr( wp_json_encode( $data ) ); ?>">

		<section class="nm-card nmb-status" aria-live="polite" data-nmb="status">
			<p class="nmb-muted"><?php esc_html_e( 'Loading…', 'ner-michoel-core' ); ?></p>
		</section>

		<section class="nmb-slots" aria-label="<?php esc_attr_e( 'The copies on Bunny', 'ner-michoel-core' ); ?>" data-nmb="slots"></section>

		<section class="nm-card nmb-log" aria-labelledby="nmb-log-title">
			<h2 class="nm-card__title" id="nmb-log-title"><?php echo ner_michoel_admin_icon( 'clock' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Recent backups', 'ner-michoel-core' ); ?></h2>
			<div class="nmb-table-wrap" data-nmb="log"></div>
		</section>

		<section class="nm-card nmb-settings" aria-labelledby="nmb-settings-title">
			<h2 class="nm-card__title" id="nmb-settings-title"><?php echo ner_michoel_admin_icon( 'settings' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Settings', 'ner-michoel-core' ); ?></h2>
			<form class="nmb-form" data-nmb="form" novalidate>
				<div class="nm-settings-field">
					<label class="nm-settings-checkbox<?php echo $s['enabled'] ? ' is-on' : ''; ?>"><input type="checkbox" name="enabled" <?php checked( $s['enabled'] ); ?>> <span><?php esc_html_e( 'Back up the site every night', 'ner-michoel-core' ); ?></span></label>
				</div>
				<div class="nm-settings-field">
					<label for="nmb-hour"><?php esc_html_e( 'At', 'ner-michoel-core' ); ?></label>
					<select id="nmb-hour" name="hour" class="nm-cms-input nmb-hour">
						<?php for ( $h = 0; $h < 24; $h++ ) : ?>
							<option value="<?php echo esc_attr( $h ); ?>" <?php selected( $s['hour'], $h ); ?>><?php echo esc_html( gmdate( get_option( 'time_format' ) ? get_option( 'time_format' ) : 'g:i a', $h * HOUR_IN_SECONDS ) ); ?></option>
						<?php endfor; ?>
					</select>
					<?php /* translators: %s: time zone */ ?>
					<p class="nm-cms-hint"><?php echo esc_html( sprintf( __( 'The site’s time (%s). A quiet hour is best: packing the site keeps the server busy for a few minutes.', 'ner-michoel-core' ), $tz ) ); ?></p>
				</div>
				<div class="nm-settings-field">
					<?php /* translators: %s: email address */ ?>
					<label class="nm-settings-checkbox<?php echo $s['email'] ? ' is-on' : ''; ?>"><input type="checkbox" name="email" <?php checked( $s['email'] ); ?>> <span><?php echo esc_html( sprintf( __( 'Email %s if a backup doesn’t finish', 'ner-michoel-core' ), $s['adminEmail'] ) ); ?></span></label>
				</div>

				<div class="nm-settings-field nmb-where">
					<span class="nmb-label"><?php esc_html_e( 'Where the copies are kept', 'ner-michoel-core' ); ?></span>
					<p class="nmb-where__line" data-nmb="where"></p>
					<p class="nm-cms-hint" data-nmb="where-hint"></p>
				</div>

				<details class="nmb-zone" <?php echo $s['zone_name'] ? 'open' : ''; ?>>
					<summary><?php esc_html_e( 'Use a separate Storage Zone for the backups (more private)', 'ner-michoel-core' ); ?></summary>
					<p class="nm-cms-hint nmb-zone__intro"><?php esc_html_e( 'Best: a Storage Zone with no Pull Zone connected, so nobody can download from it without its password. In bunny.net: Storage > Add Storage Zone, then copy its name and its password (FTP & API Access). Leave these empty to keep the backups in the media Storage Zone.', 'ner-michoel-core' ); ?></p>
					<div class="nmb-zone__grid">
						<div class="nm-settings-field">
							<label for="nmb-zone"><?php esc_html_e( 'Storage Zone name', 'ner-michoel-core' ); ?></label>
							<input type="text" id="nmb-zone" name="zone_name" class="nm-cms-input" value="<?php echo esc_attr( $s['zone_name'] ); ?>" autocomplete="off" spellcheck="false">
						</div>
						<div class="nm-settings-field">
							<label for="nmb-region"><?php esc_html_e( 'Region', 'ner-michoel-core' ); ?></label>
							<input type="text" id="nmb-region" name="region" class="nm-cms-input" value="<?php echo esc_attr( $s['region'] ); ?>" placeholder="ny" autocomplete="off" spellcheck="false">
							<p class="nm-cms-hint"><?php esc_html_e( 'The start of its address, like “ny” in ny.storage.bunnycdn.com. Empty for Germany (storage.bunnycdn.com).', 'ner-michoel-core' ); ?></p>
						</div>
						<div class="nm-settings-field">
							<label for="nmb-key"><?php esc_html_e( 'Password', 'ner-michoel-core' ); ?></label>
							<input type="password" id="nmb-key" name="api_key" class="nm-cms-input" value="" placeholder="<?php echo $s['has_key'] ? esc_attr__( 'Saved: leave empty to keep it', 'ner-michoel-core' ) : ''; ?>" autocomplete="new-password">
						</div>
					</div>
					<?php if ( $s['zone_name'] ) : ?>
						<button type="button" class="nm-btn nm-btn--sm nmb-zone__remove" data-nmb="remove-zone"><?php esc_html_e( 'Go back to the media Storage Zone', 'ner-michoel-core' ); ?></button>
					<?php endif; ?>
				</details>

				<div class="nmb-form__foot">
					<button type="submit" class="nm-btn nm-btn--primary"><?php esc_html_e( 'Save', 'ner-michoel-core' ); ?></button>
					<span class="nmb-muted" data-nmb="form-status" aria-live="polite"></span>
				</div>
			</form>
		</section>

		<section class="nm-card nmb-about" aria-labelledby="nmb-about-title">
			<h2 class="nm-card__title" id="nmb-about-title"><?php echo ner_michoel_admin_icon( 'info' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'What a backup holds, and putting the site back', 'ner-michoel-core' ); ?></h2>
			<ul class="nmb-plain">
				<li><?php esc_html_e( 'The whole database (shiurim, pages, settings, accounts) and the wp-content folder (themes, plugins, uploaded pictures), in one zip.', 'ner-michoel-core' ); ?></li>
				<li><?php esc_html_e( 'The audio, video and PDF files kept on Bunny aren’t copied again: they are already on Bunny, and the database keeps their addresses.', 'ner-michoel-core' ); ?></li>
				<li><?php esc_html_e( 'A new copy only replaces an old one once it is on Bunny in full and checked; the old one is deleted after that, never before.', 'ner-michoel-core' ); ?></li>
				<li><?php esc_html_e( 'Each zip has a README.txt with the steps to put the site back: import database.sql in phpMyAdmin, then upload the wp-content folder.', 'ner-michoel-core' ); ?></li>
			</ul>
		</section>
	</div>
	<?php
}
