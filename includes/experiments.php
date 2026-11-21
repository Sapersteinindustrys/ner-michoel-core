<?php
/**
 * "Experiments": one admin page (admins only) for features we are trying out
 * before they go on the public site. Nothing on this page changes what visitors
 * see unless an experiment's own switch says so.
 *
 * Experiments are a list (filter ner_michoel_experiments), each
 * array( 'id', 'label', 'desc', 'render' => callable ). The first one is
 * "Shiur audio cleanup" (audio-cleanup.php).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ner_michoel_experiments() {
	$list = array(
		array(
			'id'     => 'audio-cleanup',
			'label'  => __( 'Shiur audio cleanup', 'ner-michoel-core' ),
			'desc'   => __( 'Takes away background noise and echo, evens out the volume, picks the better track of a Sony recording and makes the file small. Listen before and after.', 'ner-michoel-core' ),
			'render' => 'ner_michoel_experiment_audio_cleanup',
		),
	);
	return array_values(
		array_filter(
			(array) apply_filters( 'ner_michoel_experiments', $list ),
			function ( $e ) {
				return ! empty( $e['id'] ) && ! empty( $e['render'] ) && is_callable( $e['render'] );
			}
		)
	);
}

function ner_michoel_experiments_menu() {
	add_menu_page(
		__( 'Experiments', 'ner-michoel-core' ),
		__( 'Experiments', 'ner-michoel-core' ),
		'manage_options',
		'nm-experiments',
		'ner_michoel_experiments_page',
		'dashicons-lightbulb',
		4
	);
}
add_action( 'admin_menu', 'ner_michoel_experiments_menu' );

function ner_michoel_experiments_current() {
	$list = ner_michoel_experiments();
	$want = isset( $_GET['experiment'] ) ? sanitize_key( wp_unslash( $_GET['experiment'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	foreach ( $list as $e ) {
		if ( $e['id'] === $want ) {
			return $e;
		}
	}
	return $list ? $list[0] : null;
}

function ner_michoel_experiments_assets( $hook ) {
	if ( 'toplevel_page_nm-experiments' !== $hook ) {
		return;
	}
	$e = ner_michoel_experiments_current();
	wp_enqueue_style( 'nm-experiments', NER_MICHOEL_CORE_URL . 'assets/experiments.css', array(), NER_MICHOEL_CORE_VERSION );
	if ( $e && 'audio-cleanup' === $e['id'] ) {
		wp_enqueue_media();
		wp_enqueue_script( 'nm-audio-cleanup', NER_MICHOEL_CORE_URL . 'assets/audio-cleanup.js', array( 'wp-api-fetch' ), NER_MICHOEL_CORE_VERSION, true );
		wp_localize_script(
			'nm-audio-cleanup',
			'nmAudioCleanup',
			array(
				'strengths' => nm_ac_strengths(),
				'helperUrl' => NER_MICHOEL_CORE_URL . 'tools/audio-cleanup-helper.py',
				'siteUrl'   => home_url( '/' ),
			)
		);
	}
}
add_action( 'admin_enqueue_scripts', 'ner_michoel_experiments_assets' );

function ner_michoel_experiments_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$list    = ner_michoel_experiments();
	$current = ner_michoel_experiments_current();
	?>
	<div class="wrap nm-exp">
		<h1><?php esc_html_e( 'Experiments', 'ner-michoel-core' ); ?></h1>
		<p class="nm-exp__intro"><?php esc_html_e( 'New features we are trying out. Try them here first: nothing on this page changes what visitors see unless you switch it on yourself.', 'ner-michoel-core' ); ?></p>
		<?php if ( count( $list ) > 1 ) : ?>
			<nav class="nm-exp__list" aria-label="<?php esc_attr_e( 'Experiments', 'ner-michoel-core' ); ?>">
				<?php foreach ( $list as $e ) : ?>
					<a class="nm-exp__pick<?php echo $current && $current['id'] === $e['id'] ? ' is-current' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => 'nm-experiments', 'experiment' => $e['id'] ), admin_url( 'admin.php' ) ) ); ?>">
						<strong><?php echo esc_html( $e['label'] ); ?></strong>
						<span><?php echo esc_html( $e['desc'] ); ?></span>
					</a>
				<?php endforeach; ?>
			</nav>
		<?php endif; ?>
		<?php if ( $current ) : ?>
			<h2 class="nm-exp__title"><?php echo esc_html( $current['label'] ); ?> <span class="nm-exp__badge"><?php esc_html_e( 'Experiment', 'ner-michoel-core' ); ?></span></h2>
			<p class="nm-exp__desc"><?php echo esc_html( $current['desc'] ); ?></p>
			<?php call_user_func( $current['render'] ); ?>
		<?php endif; ?>
	</div>
	<?php
}

/** The audio cleanup screen; audio-cleanup.js fills it. */
function ner_michoel_experiment_audio_cleanup() {
	?>
	<div id="nm-ac" class="nm-ac">
		<section class="nm-ac__box" aria-labelledby="nm-ac-try">
			<h3 id="nm-ac-try"><?php esc_html_e( '1. Try it on a recording', 'ner-michoel-core' ); ?></h3>
			<div class="nm-ac__source" role="radiogroup" aria-label="<?php esc_attr_e( 'Which recording', 'ner-michoel-core' ); ?>">
				<label class="nm-ac__choice"><input type="radio" name="nm-ac-src" value="upload" checked> <strong><?php esc_html_e( 'Upload a recording', 'ner-michoel-core' ); ?></strong><span><?php esc_html_e( 'From a Sony recorder? Upload both tracks; the better one is picked by itself.', 'ner-michoel-core' ); ?></span></label>
				<label class="nm-ac__choice"><input type="radio" name="nm-ac-src" value="shiur"> <strong><?php esc_html_e( 'Pick a shiur on the site', 'ner-michoel-core' ); ?></strong><span><?php esc_html_e( 'Cleans a copy. Listeners keep hearing the shiur as it is.', 'ner-michoel-core' ); ?></span></label>
			</div>
			<div class="nm-ac__panel" data-for="upload">
				<button type="button" class="button button-secondary" id="nm-ac-upload"><?php esc_html_e( 'Choose or upload audio files…', 'ner-michoel-core' ); ?></button>
				<ul class="nm-ac__files" id="nm-ac-files"></ul>
			</div>
			<div class="nm-ac__panel" data-for="shiur" hidden>
				<label class="screen-reader-text" for="nm-ac-search"><?php esc_html_e( 'Search shiurim', 'ner-michoel-core' ); ?></label>
				<input type="search" id="nm-ac-search" class="regular-text" placeholder="<?php esc_attr_e( 'Search by title or speaker…', 'ner-michoel-core' ); ?>">
				<ul class="nm-ac__shiurim" id="nm-ac-shiurim" role="listbox" aria-label="<?php esc_attr_e( 'Shiurim', 'ner-michoel-core' ); ?>"></ul>
			</div>
			<div class="nm-ac__opts">
				<label><?php esc_html_e( 'How much of it', 'ner-michoel-core' ); ?>
					<select id="nm-ac-limit">
						<option value="300"><?php esc_html_e( 'First 5 minutes (quick test)', 'ner-michoel-core' ); ?></option>
						<option value="900"><?php esc_html_e( 'First 15 minutes', 'ner-michoel-core' ); ?></option>
						<option value="0"><?php esc_html_e( 'The whole recording', 'ner-michoel-core' ); ?></option>
					</select>
				</label>
				<label><?php esc_html_e( 'Strength', 'ner-michoel-core' ); ?> <select id="nm-ac-strength"></select></label>
				<label class="nm-ac__check"><input type="checkbox" id="nm-ac-normalize" checked> <?php esc_html_e( 'Even out the volume', 'ner-michoel-core' ); ?></label>
				<label class="nm-ac__check"><input type="checkbox" id="nm-ac-silence"> <?php esc_html_e( 'Shorten long pauses (over 2 seconds)', 'ner-michoel-core' ); ?></label>
			</div>
			<p><button type="button" class="button button-primary button-hero" id="nm-ac-go" disabled><?php esc_html_e( 'Clean it up', 'ner-michoel-core' ); ?></button> <span class="nm-ac__note" id="nm-ac-go-note" role="status"></span></p>
		</section>

		<section class="nm-ac__box" aria-labelledby="nm-ac-results-title">
			<h3 id="nm-ac-results-title"><?php esc_html_e( '2. Listen and compare', 'ner-michoel-core' ); ?></h3>
			<p class="nm-ac__runner" id="nm-ac-runner" role="status"></p>
			<div id="nm-ac-results"><p class="nm-ac__empty"><?php esc_html_e( 'Loading…', 'ner-michoel-core' ); ?></p></div>
		</section>

		<section class="nm-ac__box" aria-labelledby="nm-ac-auto-title">
			<h3 id="nm-ac-auto-title"><?php esc_html_e( '3. Automatic cleanup for new shiurim', 'ner-michoel-core' ); ?></h3>
			<p><?php esc_html_e( 'Clean every new shiur as soon as its audio is uploaded. Off until you are happy with the results above.', 'ner-michoel-core' ); ?></p>
			<fieldset class="nm-ac__auto">
				<legend class="screen-reader-text"><?php esc_html_e( 'Automatic cleanup', 'ner-michoel-core' ); ?></legend>
				<label><input type="radio" name="nm-ac-auto" value="off"> <strong><?php esc_html_e( 'Off', 'ner-michoel-core' ); ?></strong> <span><?php esc_html_e( 'Nothing happens to new shiurim.', 'ner-michoel-core' ); ?></span></label>
				<label><input type="radio" name="nm-ac-auto" value="test"> <strong><?php esc_html_e( 'Clean, but listeners hear the original', 'ner-michoel-core' ); ?></strong> <span><?php esc_html_e( 'Each new shiur shows up in the list above to compare; you switch the good ones over.', 'ner-michoel-core' ); ?></span></label>
				<label><input type="radio" name="nm-ac-auto" value="on"> <strong><?php esc_html_e( 'Clean, and listeners hear the cleaned version', 'ner-michoel-core' ); ?></strong> <span><?php esc_html_e( 'The original is kept; “Use the original again” puts it back.', 'ner-michoel-core' ); ?></span></label>
			</fieldset>
			<p class="nm-ac__opts">
				<label><?php esc_html_e( 'Strength', 'ner-michoel-core' ); ?> <select id="nm-ac-auto-strength"></select></label>
				<label><?php esc_html_e( 'File size', 'ner-michoel-core' ); ?>
					<select id="nm-ac-bitrate">
						<option value="32"><?php esc_html_e( 'Smallest (32 kbps, about 14 MB an hour)', 'ner-michoel-core' ); ?></option>
						<option value="48"><?php esc_html_e( 'Small (48 kbps, about 21 MB an hour, like the site now)', 'ner-michoel-core' ); ?></option>
						<option value="64"><?php esc_html_e( 'Medium (64 kbps, about 28 MB an hour)', 'ner-michoel-core' ); ?></option>
						<option value="96"><?php esc_html_e( 'Large (96 kbps, about 42 MB an hour)', 'ner-michoel-core' ); ?></option>
					</select>
				</label>
				<button type="button" class="button" id="nm-ac-auto-save"><?php esc_html_e( 'Save', 'ner-michoel-core' ); ?></button>
				<span class="nm-ac__note" id="nm-ac-auto-note" role="status"></span>
			</p>
		</section>

		<section class="nm-ac__box" aria-labelledby="nm-ac-where-title">
			<h3 id="nm-ac-where-title"><?php esc_html_e( '4. Where the cleaning runs', 'ner-michoel-core' ); ?></h3>
			<p><?php esc_html_e( 'The web host is too small to run the noise remover, so the site hands the work to a free Kaggle account (it starts it there by itself) or to a computer running the cleanup helper. Only the recordings to clean and the cleaned files go back and forth.', 'ner-michoel-core' ); ?></p>
			<div class="nm-ac__where">
				<div>
					<h4><?php esc_html_e( 'Kaggle (recommended: nothing to keep switched on)', 'ner-michoel-core' ); ?></h4>
					<label class="nm-ac__check"><input type="checkbox" id="nm-ac-kg-on"> <?php esc_html_e( 'Start the cleaning on Kaggle by itself', 'ner-michoel-core' ); ?></label>
					<p><label><?php esc_html_e( 'Kaggle username', 'ner-michoel-core' ); ?><br><input type="text" id="nm-ac-kg-user" class="regular-text" autocomplete="off"></label></p>
					<p><label><?php esc_html_e( 'Kaggle API token', 'ner-michoel-core' ); ?><br><input type="password" id="nm-ac-kg-token" class="regular-text" autocomplete="new-password"></label><br>
						<small><?php esc_html_e( 'Kaggle > Settings > API > Create New Token. Saved on the site, never shown again. Needs a phone-verified Kaggle account (for internet in notebooks).', 'ner-michoel-core' ); ?></small></p>
					<p><button type="button" class="button" id="nm-ac-kg-save"><?php esc_html_e( 'Save', 'ner-michoel-core' ); ?></button>
						<button type="button" class="button" id="nm-ac-kg-start"><?php esc_html_e( 'Start now', 'ner-michoel-core' ); ?></button>
						<button type="button" class="button-link" id="nm-ac-kg-check"><?php esc_html_e( 'Check on it', 'ner-michoel-core' ); ?></button></p>
					<p class="nm-ac__note" id="nm-ac-kg-note" role="status"></p>
				</div>
				<div>
					<h4><?php esc_html_e( 'Or a computer of your own', 'ner-michoel-core' ); ?></h4>
					<p><?php esc_html_e( 'Faster to start, but the computer has to be on.', 'ner-michoel-core' ); ?> <a id="nm-ac-helper-dl" href="#" download><?php esc_html_e( 'Download the cleanup helper', 'ner-michoel-core' ); ?></a></p>
					<p><button type="button" class="button" id="nm-ac-key"><?php esc_html_e( 'Make a helper key', 'ner-michoel-core' ); ?></button> <span class="nm-ac__note" id="nm-ac-key-note"></span></p>
					<pre class="nm-ac__cmd" id="nm-ac-cmd" hidden></pre>
				</div>
			</div>
		</section>
	</div>
	<?php
}
