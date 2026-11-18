<?php
/**
 * Control Panel > Shiurim > Series & Topics Re-sort: the plan from series-resort.php as a
 * spreadsheet the admin changes in place, with a guide, the settings, Download / Upload,
 * Preview, Apply and Undo. The table itself is drawn by assets/series-resort.js.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ner_michoel_render_series_resort_page() {
	if ( ! current_user_can( 'manage_categories' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'ner-michoel-core' ) );
	}
	ner_michoel_resort_install();
	wp_enqueue_style( 'ner-michoel-series-resort', NER_MICHOEL_CORE_URL . 'assets/series-resort.css', array(), NER_MICHOEL_CORE_VERSION );
	wp_enqueue_script( 'ner-michoel-series-resort', NER_MICHOEL_CORE_URL . 'assets/series-resort.js', array(), NER_MICHOEL_CORE_VERSION, true );
	wp_add_inline_script(
		'ner-michoel-series-resort',
		'window.nmResort = ' . wp_json_encode(
			array(
				'api'     => esc_url_raw( rest_url( 'ner-michoel/v1/resort/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'csv'     => esc_url_raw( add_query_arg( array( 'action' => 'nm_resort_csv', '_wpnonce' => wp_create_nonce( 'nm_resort_csv' ) ), admin_url( 'admin-post.php' ) ) ),
				'actions' => ner_michoel_resort_actions(),
			)
		) . ';',
		'before'
	);
	?>
	<div class="wrap nm-dashboard nm-resort" id="nm-resort">
		<h1><?php esc_html_e( 'Series & Topics Re-sort', 'ner-michoel-core' ); ?></h1>

		<details class="nm-resort-guide" open>
			<summary><?php esc_html_e( 'How this works (read this first)', 'ner-michoel-core' ); ?></summary>
			<div class="nm-resort-guide__body">
				<div class="nm-resort-guide__col">
					<h3><?php esc_html_e( 'Series and topics', 'ner-michoel-core' ); ?></h3>
					<p><?php esc_html_e( 'A series is where a shiur lives, like a folder: Gemara, then Bava Kamma, then Perek 3. Each shiur sits in one place. Visitors browse the series to find a shiur.', 'ner-michoel-core' ); ?></p>
					<p><?php esc_html_e( 'A topic is a label a shiur can have several of, like Chanukah or Emunah. Topics cut across the series: a Chanukah shiur can be in Gemara Shabbos and still be found under Chanukah. The homepage “In season” box shows the Yom Tov topics at the right time of year.', 'ner-michoel-core' ); ?></p>
					<p><?php esc_html_e( 'The old site’s folders came over as series six levels deep, with “Weekly Parsha” and the Yamim Tovim mixed inside each other, and every folder name also became a topic. This screen puts them into a few clear sections. Nothing changes on the site until you press Apply, and Undo puts everything back.', 'ner-michoel-core' ); ?></p>
				</div>
				<div class="nm-resort-guide__col">
					<h3><?php esc_html_e( 'Step by step', 'ner-michoel-core' ); ?></h3>
					<ol>
						<li><?php esc_html_e( 'Look at the settings below (keep the Perek level? courses by speaker and year?).', 'ner-michoel-core' ); ?></li>
						<li><?php esc_html_e( 'Go through the tabs. Each row is one series, topic, speaker or shiur, with where it goes. Tick “Only rows that need a look” to see just the ones the computer was not sure about.', 'ner-michoel-core' ); ?></li>
						<li><?php esc_html_e( 'Change a row by picking another choice or typing where it goes. It saves by itself. Press ✓ when a row is right.', 'ner-michoel-core' ); ?></li>
						<li><?php esc_html_e( 'If you prefer Excel or Google Sheets: Download the spreadsheet, change the “action”, “goes to” and “approved” columns, and Upload it back.', 'ner-michoel-core' ); ?></li>
						<li><?php esc_html_e( 'Press Preview to see the numbers: how many shiurim move, which new series are made, which old ones go.', 'ner-michoel-core' ); ?></li>
						<li><?php esc_html_e( 'Press Apply. It works in the background (a few minutes) and keeps a full copy first. Press Undo at any time afterwards to go back.', 'ner-michoel-core' ); ?></li>
					</ol>
				</div>
				<div class="nm-resort-guide__col">
					<h3><?php esc_html_e( 'What the choices do', 'ner-michoel-core' ); ?></h3>
					<dl>
						<dt><?php esc_html_e( 'Series: Move', 'ner-michoel-core' ); ?></dt><dd><?php esc_html_e( 'Its shiurim go to the place in “Goes to”, written with / between the levels (Gemara / Bava Kamma / Perek 3). Two series with the same “Goes to” become one. The place is made if it is not there.', 'ner-michoel-core' ); ?></dd>
						<dt><?php esc_html_e( 'Series: Keep', 'ner-michoel-core' ); ?></dt><dd><?php esc_html_e( 'Left exactly where it is.', 'ner-michoel-core' ); ?></dd>
						<dt><?php esc_html_e( 'Series: By title', 'ner-michoel-core' ); ?></dt><dd><?php esc_html_e( 'For “Shiurim Videos” and “Videos”: each shiur is placed by its own title, on the “Shiurim by title” tab.', 'ner-michoel-core' ); ?></dd>
						<dt><?php esc_html_e( 'Series: Delete', 'ner-michoel-core' ); ?></dt><dd><?php esc_html_e( 'The series goes. Its shiurim (if any) are taken out of it.', 'ner-michoel-core' ); ?></dd>
						<dt><?php esc_html_e( 'Add topics', 'ner-michoel-core' ); ?></dt><dd><?php esc_html_e( 'Topics its shiurim get, like the Yom Tov that was a folder before.', 'ner-michoel-core' ); ?></dd>
						<dt><?php esc_html_e( 'Topic: Keep / Rename / Merge / Drop', 'ner-michoel-core' ); ?></dt><dd><?php esc_html_e( 'Rename changes its name. Merge moves its shiurim to the topic in “Goes to” and removes it. Drop takes it off every shiur and removes it (for words that were only folder names).', 'ner-michoel-core' ); ?></dd>
						<dt><?php esc_html_e( 'Confidence', 'ner-michoel-core' ); ?></dt><dd><?php esc_html_e( 'High: the rule is sure. Medium: probably right. Low: please decide.', 'ner-michoel-core' ); ?></dd>
					</dl>
				</div>
			</div>
		</details>

		<div class="nm-resort-state" data-nm-state hidden></div>

		<section class="nm-resort-settings" aria-labelledby="nm-resort-settings-title">
			<h2 id="nm-resort-settings-title"><?php esc_html_e( 'Settings', 'ner-michoel-core' ); ?></h2>
			<div class="nm-resort-settings__grid">
				<label class="nm-resort-switch">
					<input type="checkbox" data-nm-setting="keep_perek" />
					<span><strong><?php esc_html_e( 'Keep the Perek level in Gemara', 'ner-michoel-core' ); ?></strong>
					<small><?php esc_html_e( 'On: Gemara / Bava Kamma / Perek 3. Off: all of a masechta’s shiurim in one list (Gemara / Bava Kamma).', 'ner-michoel-core' ); ?></small></span>
				</label>
				<label class="nm-resort-switch">
					<input type="checkbox" data-nm-setting="courses" />
					<span><strong><?php esc_html_e( 'Courses by speaker and year', 'ner-michoel-core' ); ?></strong>
					<small><?php esc_html_e( 'Gemara shiurim also go into a course under the masechta, like “Rabbi Klein: Bava Kamma 5787” (the year from the title, or from the date).', 'ner-michoel-core' ); ?></small></span>
				</label>
				<label class="nm-resort-number">
					<span><strong><?php esc_html_e( 'Hide small topics', 'ner-michoel-core' ); ?></strong>
					<small><?php esc_html_e( 'After Apply, the site’s topic lists leave out topics with fewer shiurim than this (Yom Tov topics always show). 0 shows all.', 'ner-michoel-core' ); ?></small></span>
					<input type="number" min="0" max="100" data-nm-setting="min_topic" />
				</label>
			</div>
			<div class="nm-resort-sections">
				<strong><?php esc_html_e( 'Sections (the first level, in this order)', 'ner-michoel-core' ); ?></strong>
				<small><?php esc_html_e( 'Rename one and every row going there follows. Use the arrows to change the order the Shiurim page shows them in.', 'ner-michoel-core' ); ?></small>
				<ol data-nm-sections></ol>
				<button type="button" class="button" data-nm-add-section><?php esc_html_e( '+ Add a section', 'ner-michoel-core' ); ?></button>
				<button type="button" class="button button-primary" data-nm-save-sections><?php esc_html_e( 'Save sections', 'ner-michoel-core' ); ?></button>
			</div>
		</section>

		<section class="nm-resort-sheet" aria-labelledby="nm-resort-sheet-title">
			<h2 id="nm-resort-sheet-title"><?php esc_html_e( 'The plan', 'ner-michoel-core' ); ?></h2>
			<div class="nm-resort-tabs" role="tablist" data-nm-tabs>
				<button type="button" role="tab" data-kind="series"><?php esc_html_e( 'Series', 'ner-michoel-core' ); ?> <span></span></button>
				<button type="button" role="tab" data-kind="shiur"><?php esc_html_e( 'Shiurim by title', 'ner-michoel-core' ); ?> <span></span></button>
				<button type="button" role="tab" data-kind="topic"><?php esc_html_e( 'Topics', 'ner-michoel-core' ); ?> <span></span></button>
				<button type="button" role="tab" data-kind="speaker"><?php esc_html_e( 'Speakers', 'ner-michoel-core' ); ?> <span></span></button>
				<button type="button" role="tab" data-kind="byspeak"><?php esc_html_e( 'Shiurim with no speaker', 'ner-michoel-core' ); ?> <span></span></button>
			</div>
			<div class="nm-resort-tools">
				<input type="search" data-nm-search placeholder="<?php esc_attr_e( 'Search names and places…', 'ner-michoel-core' ); ?>" />
				<label><input type="checkbox" data-nm-review checked /> <?php esc_html_e( 'Only rows that need a look', 'ner-michoel-core' ); ?></label>
				<button type="button" class="button" data-nm-approve-shown><?php esc_html_e( '✓ Approve all shown', 'ner-michoel-core' ); ?></button>
				<span class="nm-resort-tools__gap"></span>
				<a class="button" data-nm-download href="#"><?php esc_html_e( 'Download spreadsheet', 'ner-michoel-core' ); ?></a>
				<label class="button nm-resort-upload"><?php esc_html_e( 'Upload spreadsheet', 'ner-michoel-core' ); ?><input type="file" accept=".csv,text/csv" data-nm-upload hidden /></label>
				<button type="button" class="button-link" data-nm-regenerate><?php esc_html_e( 'Start the plan again', 'ner-michoel-core' ); ?></button>
			</div>
			<p class="nm-resort-hint" data-nm-hint></p>
			<div class="nm-resort-table-wrap">
				<table class="widefat striped nm-resort-table">
					<thead><tr data-nm-head></tr></thead>
					<tbody data-nm-body><tr><td><?php esc_html_e( 'Loading the plan…', 'ner-michoel-core' ); ?></td></tr></tbody>
				</table>
			</div>
			<div class="nm-resort-pager" data-nm-pager></div>
			<datalist id="nm-resort-places"></datalist>
			<datalist id="nm-resort-topics"></datalist>
			<datalist id="nm-resort-speakers"></datalist>
		</section>

		<section class="nm-resort-go" aria-labelledby="nm-resort-go-title">
			<h2 id="nm-resort-go-title"><?php esc_html_e( 'Preview and Apply', 'ner-michoel-core' ); ?></h2>
			<p><?php esc_html_e( 'Preview counts what Apply would do and changes nothing. Apply keeps a copy of every series, topic and speaker, and of each shiur it changes, then works through the shiurim in the background. Keep this page open to see it move; it carries on if you close it.', 'ner-michoel-core' ); ?></p>
			<p>
				<button type="button" class="button button-secondary" data-nm-preview><?php esc_html_e( 'Preview', 'ner-michoel-core' ); ?></button>
				<button type="button" class="button button-primary" data-nm-apply><?php esc_html_e( 'Apply the plan', 'ner-michoel-core' ); ?></button>
				<button type="button" class="button nm-resort-undo" data-nm-undo><?php esc_html_e( 'Undo', 'ner-michoel-core' ); ?></button>
			</p>
			<div class="nm-resort-progress" data-nm-progress hidden><div></div></div>
			<div class="nm-resort-preview" data-nm-preview-out></div>
		</section>
	</div>
	<?php
}
