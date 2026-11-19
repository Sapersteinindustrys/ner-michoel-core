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
						<li><?php esc_html_e( 'Read “Our way, TorahAnytime’s way” and look at the settings below: speaker first or subject first, and how a speaker’s Gemara is split.', 'ner-michoel-core' ); ?></li>
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
						<dt><?php esc_html_e( 'Series: Move', 'ner-michoel-core' ); ?></dt><dd><?php esc_html_e( '“Goes to” is the subject, written with / between the levels (Gemara / Bava Kamma / Perek 3). With speaker first, each shiur then goes to its own speaker’s group (Rabbi Klein → Gemara), and the masechta and perek become topic buttons. The place is made if it is not there.', 'ner-michoel-core' ); ?></dd>
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

		<details class="nm-resort-guide nm-resort-compare" open>
			<summary><?php esc_html_e( 'Our way, TorahAnytime’s way, and the other choices', 'ner-michoel-core' ); ?></summary>
			<div class="nm-resort-compare__body">
				<h3><?php esc_html_e( 'Our way (the default): speaker first', 'ner-michoel-core' ); ?></h3>
				<p><?php esc_html_e( 'Each speaker has his own group. Inside it are his series: Gemara, Parsha, Halacha, Moadim, and so on. Only the ones he has.', 'ner-michoel-core' ); ?></p>
				<p class="nm-resort-example"><?php esc_html_e( 'Rabbi Klein → Gemara · Rabbi Klein → Halacha · Rosh HaYeshiva → Parsha', 'ner-michoel-core' ); ?></p>
				<ul>
					<li><?php esc_html_e( 'All of one speaker’s Gemara is in one series, “Gemara”. You can split it into one series per masechta if you want, but never deeper than Speaker → Gemara → Bava Kamma.', 'ner-michoel-core' ); ?></li>
					<li><?php esc_html_e( 'What is no longer a folder becomes a topic: the masechta, the parsha, the Yom Tov, the Hilchos. On a series page the listener presses a topic to see only those shiurim (Rabbi Klein → Gemara, then “Bava Kamma”).', 'ner-michoel-core' ); ?></li>
					<li><?php esc_html_e( 'Things with no one speaker go in a “General” group: Yarchei Kallah, Hespedim, Dinners, CD sets. Shiurim with no speaker go there too, until someone gives them one.', 'ner-michoel-core' ); ?></li>
				</ul>
				<p><strong><?php esc_html_e( 'Why:', 'ner-michoel-core' ); ?></strong> <?php esc_html_e( 'This is one yeshiva’s shiurim. Listeners follow their own rebbi, so they look for him first. Two clicks bring them to his shiurim on a subject, and the topic buttons narrow it down. Nothing is more than three levels deep.', 'ner-michoel-core' ); ?></p>

				<h3><?php esc_html_e( 'TorahAnytime’s way: subject first', 'ner-michoel-core' ); ?></h3>
				<p><?php esc_html_e( 'TorahAnytime has hundreds of speakers, so it starts with the subject. What it calls “Topics” is a tree two levels deep (Gemara → Bava Kamma, Parsha → Vayigash, Holidays → Chanukah). What it calls a “Series” is one speaker’s course, like “Mishna Berura Hilchos Shabbos” by one rav. Speakers have their own pages.', 'ner-michoel-core' ); ?></p>

				<table class="widefat nm-resort-compare__table">
					<thead><tr><th></th><th><?php esc_html_e( 'Ours (speaker first)', 'ner-michoel-core' ); ?></th><th><?php esc_html_e( 'TorahAnytime (subject first)', 'ner-michoel-core' ); ?></th></tr></thead>
					<tbody>
						<tr><th><?php esc_html_e( 'First thing you pick', 'ner-michoel-core' ); ?></th><td><?php esc_html_e( 'The speaker', 'ner-michoel-core' ); ?></td><td><?php esc_html_e( 'The subject', 'ner-michoel-core' ); ?></td></tr>
						<tr><th><?php esc_html_e( 'A path looks like', 'ner-michoel-core' ); ?></th><td><?php esc_html_e( 'Rabbi Klein → Gemara', 'ner-michoel-core' ); ?></td><td><?php esc_html_e( 'Gemara → Bava Kamma', 'ner-michoel-core' ); ?></td></tr>
						<tr><th><?php esc_html_e( 'How deep', 'ner-michoel-core' ); ?></th><td><?php esc_html_e( '2 levels (3 if you split Gemara)', 'ner-michoel-core' ); ?></td><td><?php esc_html_e( '2 levels', 'ner-michoel-core' ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Masechta, parsha, Yom Tov', 'ner-michoel-core' ); ?></th><td><?php esc_html_e( 'Topic buttons inside the series', 'ner-michoel-core' ); ?></td><td><?php esc_html_e( 'The second level of the tree', 'ner-michoel-core' ); ?></td></tr>
						<tr><th><?php esc_html_e( 'A speaker’s course', 'ner-michoel-core' ); ?></th><td><?php esc_html_e( 'His series (split Gemara by masechta and year for one per year)', 'ner-michoel-core' ); ?></td><td><?php esc_html_e( 'A “Series”', 'ner-michoel-core' ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Best for', 'ner-michoel-core' ); ?></th><td><?php esc_html_e( 'A yeshiva whose listeners follow their rebbeim', 'ner-michoel-core' ); ?></td><td><?php esc_html_e( 'A big library with many speakers', 'ner-michoel-core' ); ?></td></tr>
					</tbody>
				</table>

				<h3><?php esc_html_e( 'The other choices, and how to switch', 'ner-michoel-core' ); ?></h3>
				<ul>
					<li><strong><?php esc_html_e( 'Subject first (like TorahAnytime):', 'ner-michoel-core' ); ?></strong> <?php esc_html_e( 'pick “Subject first” below. Paths become Gemara → Bava Kamma → Perek 3. Listeners pick a subject first, then find the speaker inside.', 'ner-michoel-core' ); ?></li>
					<li><strong><?php esc_html_e( 'Split Gemara by masechta:', 'ner-michoel-core' ); ?></strong> <?php esc_html_e( 'Rabbi Klein → Gemara → Bava Kamma. Good for a speaker with many masechtos. Choose it for all speakers, or only for some.', 'ner-michoel-core' ); ?></li>
					<li><strong><?php esc_html_e( 'Split Gemara by masechta and year:', 'ner-michoel-core' ); ?></strong> <?php esc_html_e( 'Rabbi Klein → Gemara → Bava Kamma 5787. Each year’s shiur is its own course, like TorahAnytime’s “Series”.', 'ner-michoel-core' ); ?></li>
					<li><strong><?php esc_html_e( 'Courses by speaker and year (subject first only):', 'ner-michoel-core' ); ?></strong> <?php esc_html_e( 'Gemara → Bava Kamma → “Rabbi Klein: Bava Kamma 5787”.', 'ner-michoel-core' ); ?></li>
				</ul>
				<p><?php esc_html_e( 'To switch: change the setting below, press Preview to see the numbers, then Apply. If the site is already re-sorted, press Undo first, change the setting, then Apply again. Nothing is lost either way.', 'ner-michoel-core' ); ?></p>
			</div>
		</details>

		<div class="nm-resort-state" data-nm-state hidden></div>

		<section class="nm-resort-settings" aria-labelledby="nm-resort-settings-title">
			<h2 id="nm-resort-settings-title"><?php esc_html_e( 'Settings', 'ner-michoel-core' ); ?></h2>
			<fieldset class="nm-resort-layout">
				<legend><strong><?php esc_html_e( 'How the series are arranged', 'ner-michoel-core' ); ?></strong></legend>
				<label><input type="radio" name="nm-layout" value="speaker" data-nm-setting="layout" />
					<span><strong><?php esc_html_e( 'Speaker first (our way)', 'ner-michoel-core' ); ?></strong>
					<small><?php esc_html_e( 'Rabbi Klein → Gemara, Rabbi Klein → Parsha. Masechta, parsha and Yom Tov are topic buttons inside.', 'ner-michoel-core' ); ?></small></span></label>
				<label><input type="radio" name="nm-layout" value="subject" data-nm-setting="layout" />
					<span><strong><?php esc_html_e( 'Subject first (like TorahAnytime)', 'ner-michoel-core' ); ?></strong>
					<small><?php esc_html_e( 'Gemara → Bava Kamma → Perek 3, Parsha → Sefer Bereishis → Vayigash.', 'ner-michoel-core' ); ?></small></span></label>
			</fieldset>
			<div class="nm-resort-settings__grid">
				<label class="nm-resort-number" data-nm-for="speaker">
					<span><strong><?php esc_html_e( 'A speaker’s Gemara', 'ner-michoel-core' ); ?></strong>
					<small><?php esc_html_e( 'For every speaker, unless you pick something else for him below. Never deeper than Speaker → Gemara → one more.', 'ner-michoel-core' ); ?></small></span>
					<select data-nm-setting="gemara_split">
						<option value="one"><?php esc_html_e( 'One “Gemara” series', 'ner-michoel-core' ); ?></option>
						<option value="masechta"><?php esc_html_e( 'A series per masechta', 'ner-michoel-core' ); ?></option>
						<option value="masechta_year"><?php esc_html_e( 'A series per masechta and year', 'ner-michoel-core' ); ?></option>
					</select>
				</label>
				<label class="nm-resort-number" data-nm-for="speaker">
					<span><strong><?php esc_html_e( 'Name of the group with no one speaker', 'ner-michoel-core' ); ?></strong>
					<small><?php esc_html_e( 'Yarchei Kallah, Hespedim, Dinners, CD sets, and shiurim with no speaker.', 'ner-michoel-core' ); ?></small></span>
					<input type="text" data-nm-setting="general_name" />
				</label>
				<label class="nm-resort-switch" data-nm-for="speaker">
					<input type="checkbox" data-nm-setting="split_others" />
					<span><strong><?php esc_html_e( 'Split the other subjects too', 'ner-michoel-core' ); ?></strong>
					<small><?php esc_html_e( 'Off: Rabbi Klein → Parsha (the parsha is a topic button). On: Rabbi Klein → Parsha → Vayigash, Rabbi Klein → Halacha → Hilchos Shabbos.', 'ner-michoel-core' ); ?></small></span>
				</label>
				<label class="nm-resort-switch">
					<input type="checkbox" data-nm-setting="keep_perek" />
					<span><strong><?php esc_html_e( 'Keep the Perek', 'ner-michoel-core' ); ?></strong>
					<small><?php esc_html_e( 'Speaker first: the perek is a topic button (“Bava Kamma Perek 3”). Subject first: Gemara → Bava Kamma → Perek 3. Off: no perek at all.', 'ner-michoel-core' ); ?></small></span>
				</label>
				<label class="nm-resort-switch" data-nm-for="subject">
					<input type="checkbox" data-nm-setting="courses" />
					<span><strong><?php esc_html_e( 'Courses by speaker and year', 'ner-michoel-core' ); ?></strong>
					<small><?php esc_html_e( 'Subject first only: Gemara → Bava Kamma → “Rabbi Klein: Bava Kamma 5787” (the year from the title, or from the date).', 'ner-michoel-core' ); ?></small></span>
				</label>
				<label class="nm-resort-number">
					<span><strong><?php esc_html_e( 'Hide small topics', 'ner-michoel-core' ); ?></strong>
					<small><?php esc_html_e( 'After Apply, the site’s topic lists leave out topics with fewer shiurim than this (Yom Tov topics always show). 0 shows all.', 'ner-michoel-core' ); ?></small></span>
					<input type="number" min="0" max="100" data-nm-setting="min_topic" />
				</label>
			</div>
			<div class="nm-resort-speakers" data-nm-for="speaker">
				<strong><?php esc_html_e( 'A different Gemara for some speakers', 'ner-michoel-core' ); ?></strong>
				<small><?php esc_html_e( 'Leave on “Same as above” unless one speaker needs his Gemara split differently.', 'ner-michoel-core' ); ?></small>
				<div data-nm-speaker-split></div>
			</div>
			<div class="nm-resort-sections">
				<strong><?php esc_html_e( 'Subjects (in this order)', 'ner-michoel-core' ); ?></strong>
				<small><?php esc_html_e( 'Speaker first: the series inside each speaker, in this order. Subject first: the top level. Rename one and every row going there follows.', 'ner-michoel-core' ); ?></small>
				<ol data-nm-sections></ol>
				<button type="button" class="button" data-nm-add-section><?php esc_html_e( '+ Add a subject', 'ner-michoel-core' ); ?></button>
				<button type="button" class="button button-primary" data-nm-save-sections><?php esc_html_e( 'Save subjects', 'ner-michoel-core' ); ?></button>
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
