<?php
/**
 * Series & Topics re-sort (Yoni, 2026-11-18): a plan for putting the old site's folders into
 * a few fixed sections, which the admin looks over and changes before anything happens.
 *
 * The series came over as the old site's category paths, up to six levels, with "Weekly
 * Parsha", "Moadim" and holidays nested inside each other ("Parsha / Sefer Bereishis /
 * Vayigash / Weekly Parsha / Moadim / Chanukah"), and the topics were copied from every
 * part of those names (topics.php). The re-sort proposes, for every series, a place in
 *
 *     Section / Sefer or Masechta / (Perek) / (a course: "Rabbi Klein: Bava Kamma 5787")
 *
 * with the stripped holidays and parshiyos kept as topics, a place by title for the
 * shiurim that sit only in "Shiurim Videos" / "Videos", and for every topic whether it
 * stays, is renamed, is merged into another, or goes (a word that was only a folder).
 *
 * Nothing changes until the admin presses Apply. Apply first keeps a copy of every term
 * and of each shiur's series, topics and speakers it changes, so Undo puts it all back.
 * After Apply, new imports follow the plan (the filters at the bottom).
 *
 * The rows live in the table {prefix}nm_resort_rows, one per series, topic, speaker, and
 * per shiur where it is placed by its title. Screen: series-resort-screen.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NER_MICHOEL_RESORT_DB_VERSION', '1' );

/* ------------------------------------------------------------------
 * Storage.
 * ------------------------------------------------------------------ */

function ner_michoel_resort_table() {
	global $wpdb;
	return $wpdb->prefix . 'nm_resort_rows';
}

function ner_michoel_resort_backup_table() {
	global $wpdb;
	return $wpdb->prefix . 'nm_resort_backup';
}

function ner_michoel_resort_install() {
	if ( NER_MICHOEL_RESORT_DB_VERSION === get_option( 'nm_resort_db_version' ) ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$charset = $wpdb->get_charset_collate();
	$rows    = ner_michoel_resort_table();
	$backup  = ner_michoel_resort_backup_table();
	dbDelta(
		"CREATE TABLE {$rows} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		kind VARCHAR(12) NOT NULL,
		ref_id BIGINT UNSIGNED NOT NULL,
		current_label VARCHAR(500) NOT NULL DEFAULT '',
		shiurim INT UNSIGNED NOT NULL DEFAULT 0,
		action VARCHAR(12) NOT NULL DEFAULT 'keep',
		target VARCHAR(500) NOT NULL DEFAULT '',
		add_topics VARCHAR(500) NOT NULL DEFAULT '',
		confidence VARCHAR(8) NOT NULL DEFAULT 'low',
		note VARCHAR(500) NOT NULL DEFAULT '',
		status VARCHAR(10) NOT NULL DEFAULT 'proposed',
		PRIMARY KEY  (id),
		UNIQUE KEY kind_ref (kind, ref_id)
	) {$charset};"
	);
	dbDelta(
		"CREATE TABLE {$backup} (
		post_id BIGINT UNSIGNED NOT NULL,
		series TEXT NULL,
		topics TEXT NULL,
		speakers TEXT NULL,
		PRIMARY KEY  (post_id)
	) {$charset};"
	);
	update_option( 'nm_resort_db_version', NER_MICHOEL_RESORT_DB_VERSION );
}
add_action( 'admin_init', 'ner_michoel_resort_install' );

/** What each kind of row can do. */
function ner_michoel_resort_actions() {
	return array(
		'series'  => array( 'move', 'keep', 'by_title', 'delete' ),
		'shiur'   => array( 'move', 'keep' ),
		'topic'   => array( 'keep', 'rename', 'merge', 'drop' ),
		'speaker' => array( 'keep', 'rename', 'merge' ),
		'byspeak' => array( 'assign', 'keep' ),
	);
}

/* ------------------------------------------------------------------
 * Settings.
 * ------------------------------------------------------------------ */

function ner_michoel_resort_default_sections() {
	return array( 'Gemara', 'Parsha', 'Mishna', 'Halacha', 'Moadim', 'Mussar & Vaadim', 'Shmuess', 'Events' );
}

/** How a speaker's Gemara can be split: one series, one per masechta, one per masechta and year. */
function ner_michoel_resort_split_modes() {
	return array( 'one', 'masechta', 'masechta_year' );
}

/**
 * layout: 'speaker' (Yoni, 2026-11-19: Speaker / Gemara, Speaker / Parsha ...; the default) or
 * 'subject' (Gemara / Bava Kamma / Perek 3, like TorahAnytime's topics). general_name: the
 * group for what has no one speaker (events, CD sets) and for shiurim nobody gave a speaker.
 * gemara_split: in the speaker layout, a speaker's Gemara as one series or split (never deeper
 * than Speaker / Gemara / that series); speaker_split: the same per speaker (name => mode).
 * split_others: the other subjects split by their sefer / parsha / area too.
 * keep_perek: subject layout: Gemara keeps a Perek level; speaker layout: "Bava Kamma Perek 3"
 * as a topic. courses: subject layout only: a course per speaker and year under the masechta.
 * min_topic: once applied, the site's topic lists leave out topics with fewer shiurim.
 * sections: the subjects, in this order.
 */
function ner_michoel_resort_settings() {
	$saved = get_option( 'nm_resort_settings' );
	$saved = is_array( $saved ) ? $saved : array();
	$split = isset( $saved['gemara_split'] ) && in_array( $saved['gemara_split'], ner_michoel_resort_split_modes(), true ) ? $saved['gemara_split'] : 'one';
	$per   = array();
	foreach ( ( isset( $saved['speaker_split'] ) && is_array( $saved['speaker_split'] ) ? $saved['speaker_split'] : array() ) as $who => $mode ) {
		if ( in_array( $mode, ner_michoel_resort_split_modes(), true ) ) {
			$per[ (string) $who ] = $mode;
		}
	}
	$out = array(
		'layout'        => isset( $saved['layout'] ) && 'subject' === $saved['layout'] ? 'subject' : 'speaker',
		'general_name'  => isset( $saved['general_name'] ) && '' !== trim( (string) $saved['general_name'] ) ? trim( (string) $saved['general_name'] ) : 'General',
		'gemara_split'  => $split,
		'speaker_split' => $per,
		'split_others'  => ! empty( $saved['split_others'] ),
		'keep_perek'    => isset( $saved['keep_perek'] ) ? (bool) $saved['keep_perek'] : true,
		'courses'       => isset( $saved['courses'] ) ? (bool) $saved['courses'] : false,
		'min_topic'     => isset( $saved['min_topic'] ) ? max( 0, (int) $saved['min_topic'] ) : 5,
		'sections'      => ! empty( $saved['sections'] ) && is_array( $saved['sections'] ) ? array_values( $saved['sections'] ) : ner_michoel_resort_default_sections(),
	);
	return $out;
}

/** Where the plan is: idle (being reviewed), applying, applied, undoing. */
function ner_michoel_resort_state() {
	$state = get_option( 'nm_resort_state' );
	$state = is_array( $state ) ? $state : array();
	return array_merge(
		array(
			'phase'      => 'idle',
			'cursor'     => 0,
			'done'       => 0,
			'total'      => 0,
			'changed'    => 0,
			'step'       => '',
			'applied_at' => 0,
			'message'    => '',
		),
		$state
	);
}

function ner_michoel_resort_save_state( array $state ) {
	update_option( 'nm_resort_state', $state, false );
}

function ner_michoel_resort_is_applied() {
	$state = ner_michoel_resort_state();
	return 'applied' === $state['phase'];
}

/* ------------------------------------------------------------------
 * Words the rules know.
 * ------------------------------------------------------------------ */

function ner_michoel_resort_masechtos() {
	return array( 'Berochos', 'Shabbos', 'Eiruvin', 'Pesachim', 'Shekalim', 'Yoma', 'Sukkah', 'Beitzah', 'Rosh Hashanah', 'Taanis', 'Megillah', 'Moed Katan', 'Chagigah', 'Yevamos', 'Kesubos', 'Nedarim', 'Nazir', 'Sotah', 'Gittin', 'Kiddushin', 'Bava Kamma', 'Bava Metzia', 'Bava Basra', 'Sanhedrin', 'Makkos', 'Shevuos', 'Avodah Zarah', 'Horayos', 'Zevachim', 'Menachos', 'Chullin', 'Bechoros', 'Arachin', 'Temurah', 'Kerisos', 'Meilah', 'Tamid', 'Niddah' );
}

/** Other spellings of masechtos in titles => the name above. */
function ner_michoel_resort_masechta_aliases() {
	return array(
		'Arvei Pesachim' => 'Pesachim',
		'Brachos'        => 'Berochos',
		'Berachos'       => 'Berochos',
		'Brochos'        => 'Berochos',
		'Eruvin'         => 'Eiruvin',
		'Ketubot'        => 'Kesubos',
		'Kesuvos'        => 'Kesubos',
		'Bava Kama'      => 'Bava Kamma',
		'Bava Metziah'   => 'Bava Metzia',
		'Bava Batra'     => 'Bava Basra',
		'Shevuot'        => 'Shevuos',
		'Chulin'         => 'Chullin',
		'Gitin'          => 'Gittin',
		'Kidushin'       => 'Kiddushin',
		'Yevamot'        => 'Yevamos',
		'Pesachim'       => 'Pesachim',
	);
}

function ner_michoel_resort_sedarim() {
	return array( 'Zeraim', 'Moed', 'Nashim', 'Nezikin', 'Kodshim', 'Taharos' );
}

/** Lower case, letters and digits only: how two spellings are compared. */
function ner_michoel_resort_key( $text ) {
	$text = strtolower( html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	return preg_replace( '/[^a-z0-9]+/', '', $text );
}

/** The masechta a name is ('' when none). */
function ner_michoel_resort_masechta( $name ) {
	static $map = null;
	if ( null === $map ) {
		$map = array();
		foreach ( ner_michoel_resort_masechtos() as $m ) {
			$map[ ner_michoel_resort_key( $m ) ] = $m;
		}
		foreach ( ner_michoel_resort_masechta_aliases() as $alias => $m ) {
			$map[ ner_michoel_resort_key( $alias ) ] = $m;
		}
	}
	$key = ner_michoel_resort_key( $name );
	return isset( $map[ $key ] ) ? $map[ $key ] : '';
}

/**
 * The Yom Tov a piece of text is about, by the topic name the homepage seasons use, or ''.
 * $loose also finds words inside a longer text ("Shiurim on Chanukah", "Countdown to Matan Torah").
 */
function ner_michoel_resort_holiday( $text, $loose = false ) {
	$text = html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	if ( function_exists( 'ner_michoel_topic_occasion' ) ) {
		$found = ner_michoel_topic_occasion( $text );
		if ( '' !== $found ) {
			return $found;
		}
	}
	if ( ! $loose ) {
		return '';
	}
	$words = array(
		'/\bchanuk|\bchanuka|\bneiros\b|\bmizmor shir chanukas/i' => 'Chanukah',
		'/\bpurim\b|\bmegillas esther\b|\bamalek\b/i'          => 'Purim',
		'/\bpesach\b|\bleil haseder\b|\bhaggadah\b|\bcharus\b|\bgeula\b/i' => 'Pesach',
		'/\bsefiras? ?ha.?omer\b/i'                            => 'Sefiras Haomer',
		'/\bshavuos\b|\bmatan torah\b|\bnaaseh v.nishma\b|\bkabbolas hatorah\b/i' => 'Shavuos',
		'/\btisha b.?av\b|\bbein hametzarim\b|\b3 weeks\b|\bthree weeks\b|\b17th of tammuz\b|\bnachamu\b|\bchurban\b|\bkinnos\b/i' => 'Tisha Bav & 3 Weeks',
		'/\brosh hashan|\byomim noraim\b|\belul\b|\bshanah tova\b|\bshofar\b/i' => 'Rosh Hashanah',
		'/\byom kippur\b|\bteshuv/i'                           => 'Yom Kippur',
		'/\bsuk+os\b|\bsuc+os\b|\bsuccas\b|\barba minim\b|\bsukkah & arba/i' => 'Sukkos',
		'/\basara b.?teves\b/i'                                => "Asara B'Teves",
	);
	foreach ( $words as $re => $name ) {
		if ( preg_match( $re, $text ) ) {
			return $name;
		}
	}
	return '';
}

/** The parsha numbers a whole part stands for (array() when it is not only parsha names). */
function ner_michoel_resort_parsha_numbers( $part ) {
	return function_exists( 'ner_michoel_parsha_match_name' ) ? ner_michoel_parsha_match_name( $part ) : array();
}

function ner_michoel_resort_sefer_for_parsha( $number ) {
	if ( $number <= 12 ) {
		return 'Sefer Bereishis';
	}
	if ( $number <= 23 ) {
		return 'Sefer Shemos';
	}
	if ( $number <= 33 ) {
		return 'Sefer Vayikra';
	}
	return $number <= 43 ? 'Sefer Bamidbar' : 'Sefer Devarim';
}

/**
 * A parsha's place: the existing "Parsha / Sefer X / Name" series whose name is these
 * parshiyos (so "Mattos-Massei" is found for 42 and 43), else a new one by its name.
 *
 * @param int[] $numbers
 * @return string[] Path parts.
 */
function ner_michoel_resort_parsha_path( array $numbers ) {
	static $index = null;
	if ( null === $index ) {
		$index = array();
		foreach ( ner_michoel_series_label_map() as $label ) {
			$parts = ner_michoel_series_split_name( html_entity_decode( $label, ENT_QUOTES, 'UTF-8' ) );
			if ( 3 === count( $parts ) && 'parsha' === ner_michoel_resort_key( $parts[0] ) ) {
				$found = ner_michoel_resort_parsha_numbers( $parts[2] );
				if ( $found ) {
					$index[ implode( ',', $found ) ] = $parts;
					foreach ( $found as $n ) {
						if ( ! isset( $index[ (string) $n ] ) ) {
							$index[ (string) $n ] = $parts;
						}
					}
				}
			}
		}
	}
	sort( $numbers );
	$key = implode( ',', $numbers );
	if ( isset( $index[ $key ] ) ) {
		return $index[ $key ];
	}
	if ( isset( $index[ (string) $numbers[0] ] ) ) {
		return $index[ (string) $numbers[0] ];
	}
	$names = function_exists( 'ner_michoel_parsha_names' ) ? ner_michoel_parsha_names() : array();
	return array( 'Parsha', ner_michoel_resort_sefer_for_parsha( $numbers[0] ), isset( $names[ $numbers[0] ] ) ? $names[ $numbers[0] ] : (string) $numbers[0] );
}

/* ------------------------------------------------------------------
 * The rules: a proposal for one series.
 * ------------------------------------------------------------------ */

/**
 * The proposal for a series by its path parts:
 * array( action, target parts, add topics, confidence, note ).
 *
 * @param string[] $p
 */
function ner_michoel_resort_propose_series( array $p ) {
	$p     = array_values( array_map( 'trim', $p ) );
	$top   = ner_michoel_resort_key( $p[0] );
	$rest  = array_slice( $p, 1 );
	$add   = array();
	$notes = array();

	// What is left after the place is taken: holidays and parshiyos become topics; folder words go.
	$strip = function ( array $parts ) use ( &$add, &$notes ) {
		foreach ( $parts as $part ) {
			$key     = ner_michoel_resort_key( $part );
			$holiday = ner_michoel_resort_holiday( $part );
			if ( '' !== $holiday ) {
				$add[] = $holiday;
			} elseif ( ner_michoel_resort_parsha_numbers( $part ) ) {
				$add[] = $part;
			} elseif ( in_array( $key, array( 'weeklyparsha', 'moadim', 'gemara', 'halacha', 'othershiurim', 'parsha' ), true ) || preg_match( '/^sefer(bereishis|shemos|vayikra|bamidbar|devarim)$/', $key ) ) {
				continue;
			} elseif ( 'currentevents' === $key ) {
				$add[] = 'Current Events';
			} else {
				$notes[] = sprintf( '"%s" left out', $part );
			}
		}
	};
	$out = function ( $action, array $target, $confidence, $note = '' ) use ( &$add, &$notes ) {
		$all = array_filter( array_merge( '' !== $note ? array( $note ) : array(), $notes ) );
		return array( $action, $target, array_values( array_unique( $add ) ), $confidence, implode( '; ', $all ) );
	};

	// The flat buckets: each shiur is placed by its title.
	if ( 1 === count( $p ) && preg_match( '/videos?$/i', $p[0] ) ) {
		return $out( 'by_title', $p, 'medium', 'Each shiur is placed by its title (see Shiurim by title)' );
	}

	switch ( $top ) {
		case 'gemara':
			if ( ! $rest ) {
				return $out( 'move', array( 'Gemara' ), 'high' );
			}
			$m = ner_michoel_resort_masechta( $rest[0] );
			if ( '' === $m ) {
				$seder = in_array( $rest[0], ner_michoel_resort_sedarim(), true );
				return $out( 'move', array( 'Gemara', $rest[0] ), 'low', $seder ? 'A Seder, not a masechta: pick the masechta' : 'Not a masechta name' );
			}
			$target = array( 'Gemara', $m );
			$conf   = 'high';
			$after  = array_slice( $rest, 1 );
			if ( $after && preg_match( '/^perek\s*(\d+)\s*(.*)$/i', $after[0], $mm ) ) {
				$target[] = 'Perek ' . $mm[1];
				if ( '' !== trim( $mm[2], " -\t" ) ) {
					$notes[] = sprintf( '"%s" kept as Perek %s', $after[0], $mm[1] );
					$conf    = 'medium';
				}
				$after = array_slice( $after, 1 );
				// "Perek 1 / Perek 1-Iyun": the same perek again.
				while ( $after && preg_match( '/^perek\s*' . $mm[1] . '\b/i', $after[0] ) ) {
					$notes[] = sprintf( '"%s" kept as Perek %s', $after[0], $mm[1] );
					$conf    = 'medium';
					$after   = array_slice( $after, 1 );
				}
			}
			foreach ( $after as $part ) {
				if ( preg_match( '/^aggad/i', $part ) ) {
					$add[] = 'Aggadita';
				} elseif ( 'shiurklali' === ner_michoel_resort_key( $part ) ) {
					$notes[] = 'Shiur Klali kept with its perek';
				} else {
					$strip( array( $part ) );
				}
			}
			return $out( 'move', $target, $after ? ( 'high' === $conf ? 'medium' : $conf ) : $conf );

		case 'parsha':
			if ( ! $rest ) {
				return $out( 'move', array( 'Parsha' ), 'low', 'Shiurim in "Parsha" itself: give each a parsha' );
			}
			if ( 1 === count( $rest ) ) {
				return $out( 'move', array( 'Parsha', $rest[0] ), 'high' );
			}
			$numbers = ner_michoel_resort_parsha_numbers( $rest[1] );
			if ( ! $numbers ) {
				return $out( 'move', array( 'Parsha', $rest[0], $rest[1] ), 'low', 'Not a parsha name' );
			}
			$after = array_slice( $rest, 2 );
			$conf  = 'high';
			foreach ( $after as $part ) {
				if ( ner_michoel_resort_parsha_numbers( $part ) ) {
					$conf = 'medium';
					$notes[] = sprintf( 'Also %s: added as a topic', $part );
				}
			}
			$strip( $after );
			return $out( 'move', array( 'Parsha', $rest[0], $rest[1] ), $conf );

		case 'moadim':
			if ( ! $rest ) {
				return $out( 'move', array( 'Moadim' ), 'high' );
			}
			$holiday = ner_michoel_resort_holiday( $rest[0], true );
			$name    = '' !== $holiday ? $holiday : $rest[0];
			$add[]   = $name;
			$after   = array_slice( $rest, 1 );
			$conf    = 'high';
			// "Moadim / Pesach / Sefiras Haomer": its own Yom Tov.
			if ( $after && '' !== ner_michoel_resort_holiday( $after[0] ) && ner_michoel_resort_holiday( $after[0] ) !== $name ) {
				$name  = ner_michoel_resort_holiday( $after[0] );
				$add[] = $name;
				$after = array_slice( $after, 1 );
				$conf  = 'medium';
			}
			$strip( $after );
			return $out( 'move', array( 'Moadim', $name ), $after ? 'medium' : $conf );

		case 'halacha':
			if ( ! $rest ) {
				return $out( 'move', array( 'Halacha' ), 'high' );
			}
			$holiday = ner_michoel_resort_holiday( $rest[0], true );
			if ( '' !== $holiday ) {
				$add[] = $holiday;
			}
			$strip( array_slice( $rest, 1 ) );
			return $out( 'move', array( 'Halacha', $rest[0] ), 'high' );

		case 'mishna':
			return $out( 'move', $p, 'high' );

		case 'vaadim':
			$target = array_merge( array( 'Mussar & Vaadim' ), $rest );
			if ( $rest ) {
				$holiday = ner_michoel_resort_holiday( $rest[0], true );
				if ( '' !== $holiday ) {
					$add[] = $holiday;
				}
			}
			return $out( 'move', $target, 'high' );

		case 'sefer':
		case 'seferhamitzvos':
			return $out( 'move', array_merge( array( 'Mussar & Vaadim' ), $p ), 'medium', 'Sefer Hamitzvos put with the Vaadim' );

		case 'shiurklali':
			return $out( 'move', array( 'Gemara', 'Shiur Klali' ), 'low', 'Not tied to a masechta: check where these belong' );

		case 'yarcheikallah':
			return $out( 'move', array_merge( array( 'Events', 'Yarchei Kallah' ), $rest ), 'high' );

		case 'nermichoelcdprojects':
			if ( $rest ) {
				$holiday = ner_michoel_resort_holiday( $rest[0], true );
				if ( '' !== $holiday ) {
					$add[] = $holiday;
				}
			}
			return $out( 'move', array_merge( array( 'Events', 'CD Sets' ), $rest ), $rest ? 'high' : 'high' );

		case 'othershiurim':
			if ( ! $rest ) {
				return $out( 'move', array( 'Events' ), 'medium', 'The old "Other Shiurim" folder' );
			}
			$key = ner_michoel_resort_key( $rest[0] );
			if ( false !== strpos( $key, 'dinner' ) ) {
				return $out( 'move', array( 'Events', 'Dinners', $rest[0] ), 'high' );
			}
			if ( preg_match( '/hesped|levaya|yahrzeit/', $key ) ) {
				return $out( 'move', array( 'Events', 'Hespedim', $rest[0] ), 'high' );
			}
			if ( 'currentevents' === $key ) {
				$add[] = 'Current Events';
				return $out( 'move', array( 'Mussar & Vaadim', 'Current Events' ), 'medium' );
			}
			$strip( array_slice( $rest, 1 ) );
			return $out( 'move', array( 'Events', 'Other' ), 'low', 'No clear place: check these shiurim' );
	}

	if ( preg_match( '/^shmuess/i', $p[0] ) ) {
		$who = preg_match( '/\(([^)]+)\)/', $p[0], $mm ) ? $mm[1] : 'Shmuessen';
		return $out( 'move', array( 'Shmuess', $who ), 'high' );
	}
	if ( preg_match( '/^hespedim/i', $p[0] ) ) {
		return $out( 'move', array_merge( array( 'Events', 'Hespedim', $p[0] ), $rest ), 'high' );
	}
	$sections = array_map( 'ner_michoel_resort_key', ner_michoel_resort_settings()['sections'] );
	if ( in_array( $top, $sections, true ) ) {
		return $out( 'move', $p, 'high' );
	}
	return $out( 'move', $p, 'low', 'No rule for this folder: pick its section' );
}

/**
 * A place for a shiur from its title alone (the "Shiurim Videos" bucket):
 * array( target parts or array(), add topics, confidence, note ).
 */
function ner_michoel_resort_propose_title( $title ) {
	$title   = trim( html_entity_decode( wp_strip_all_tags( (string) $title ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	$add     = array();
	$holiday = ner_michoel_resort_holiday( $title, true );
	if ( '' !== $holiday ) {
		$add[] = $holiday;
	}

	// A masechta with a shiur number or a daf: "Bava Metzia #8", "Shabbos 21b", "Berochos Shiur #30".
	$names = array_merge( ner_michoel_resort_masechtos(), array_keys( ner_michoel_resort_masechta_aliases() ) );
	usort(
		$names,
		function ( $a, $b ) {
			return strlen( $b ) - strlen( $a );
		}
	);
	foreach ( $names as $name ) {
		$re = '/(?:^|[\s(])' . preg_quote( $name, '/' ) . '\s*(?:shiur\s*)?(#\s*\d+|\d+\s*[ab]\b|daf\b)/i';
		if ( preg_match( $re, $title ) ) {
			return array( array( 'Gemara', ner_michoel_resort_masechta( $name ) ), $add, 'high', 'Perek not in the title' );
		}
	}

	// A parsha in brackets, or at the start: "(Lech Lecha 5787)".
	if ( preg_match( '/\(([^()]+)\)\s*$/', $title, $m ) ) {
		$inside  = trim( preg_replace( '/\b(5\d{3}|#\s*\d+|\d{4})\b/', '', $m[1] ) );
		$numbers = ner_michoel_resort_parsha_numbers( $inside );
		if ( $numbers ) {
			return array( ner_michoel_resort_parsha_path( $numbers ), array_values( array_diff( $add, array( '' ) ) ), 'high', '' );
		}
		$in_holiday = ner_michoel_resort_holiday( $inside, true );
		if ( '' !== $in_holiday ) {
			return array( array( 'Moadim', $in_holiday ), array( $in_holiday ), 'high', '' );
		}
	}
	if ( function_exists( 'ner_michoel_parsha_in_title' ) ) {
		$numbers = ner_michoel_parsha_in_title( $title );
		if ( $numbers ) {
			return array( ner_michoel_resort_parsha_path( $numbers ), $add, 'medium', 'Parsha found in the title' );
		}
	}
	if ( preg_match( '/pirkei avos/i', $title ) ) {
		return array( array( 'Mussar & Vaadim', 'Pirkei Avos' ), $add, 'high', '' );
	}
	if ( preg_match( '/\bhilchos\s+([a-z\' ]+?)(?:\s*[#(\-:]|$)/i', $title, $m ) ) {
		return array( array( 'Halacha', 'Hilchos ' . ucwords( trim( $m[1] ) ) ), $add, 'medium', '' );
	}
	if ( preg_match( '/yarchei kallah|morning of learning/i', $title ) ) {
		return array( array( 'Events', 'Yarchei Kallah' ), $add, 'medium', '' );
	}
	if ( preg_match( '/hesped|yahrzeit|levaya|shiva/i', $title ) ) {
		return array( array( 'Events', 'Hespedim' ), $add, 'medium', '' );
	}
	if ( preg_match( '/dinner/i', $title ) ) {
		return array( array( 'Events', 'Dinners' ), $add, 'medium', '' );
	}
	if ( '' !== $holiday ) {
		return array( array( 'Moadim', $holiday ), $add, 'medium', 'Yom Tov word in the title' );
	}
	if ( preg_match( '/^vaad\b/i', $title ) ) {
		return array( array( 'Mussar & Vaadim' ), $add, 'low', 'A vaad: pick which' );
	}
	if ( preg_match( '/orach chaim|yoreh deah|shulchan aruch|\bhalach/i', $title ) ) {
		return array( array( 'Halacha' ), $add, 'low', 'Halacha: pick which' );
	}
	return array( array(), $add, 'low', 'Could not tell from the title' );
}

/**
 * The proposal for a topic: array( action, target, confidence, note ). In the speaker layout the
 * masechtos, Sedarim and vaad names stay: they are the filters inside a speaker's series.
 */
function ner_michoel_resort_propose_topic( $name, $layout = 'speaker' ) {
	$by_speaker = 'speaker' === $layout;
	$name = trim( html_entity_decode( (string) $name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	$key  = ner_michoel_resort_key( $name );

	if ( function_exists( 'ner_michoel_topic_occasion' ) && '' !== ner_michoel_topic_occasion( $name ) ) {
		return array( 'keep', '', 'high', 'Yom Tov: the homepage "In season" box uses it' );
	}
	$folders = array( 'gemara', 'mishna', 'mishnayomis', 'moadim', 'halacha', 'weeklyparsha', 'parsha', 'vaadim', 'shiurimvideos', 'videos', 'othershiurim', 'nermichoelcdprojects', 'shiurklali', 'firstyear', 'secondyear', 'thirdyear', 'rabbitwerskyvideos', 'mishnayosbiyun' );
	if ( in_array( $key, $folders, true ) || preg_match( '/^sefer(bereishis|shemos|vayikra|bamidbar|devarim)$/', $key ) ) {
		return array( 'drop', '', 'high', 'Only a folder name: the series says it' );
	}
	if ( $by_speaker && in_array( $name, ner_michoel_resort_sedarim(), true ) ) {
		return array( 'keep', '', 'high', 'A Seder: the filter inside a speaker’s Mishna series' );
	}
	if ( $by_speaker && '' !== ner_michoel_resort_masechta( $name ) ) {
		return array( 'keep', '', 'high', 'A masechta: the filter inside a speaker’s Gemara series' );
	}
	if ( in_array( $name, ner_michoel_resort_sedarim(), true ) ) {
		return array( 'drop', '', 'high', 'A Seder of Mishna Yomis: the series says it' );
	}
	if ( '' !== ner_michoel_resort_masechta( $name ) ) {
		return array( 'drop', '', 'high', 'A masechta: the Gemara series says it' );
	}
	if ( preg_match( '/yarchei kallah|hesped|levaya|yahrzeit|shiva|dinner|melava malka|divrei chizuk|morning of learning|twersky|\b(friday shiurim)\b/i', $name ) ) {
		return array( 'drop', '', 'high', 'An event: it is an Events series' );
	}
	if ( preg_match( '/^aggad/i', $name ) ) {
		return array( 'Aggadita' === $name ? 'keep' : 'merge', 'Aggadita' === $name ? '' : 'Aggadita', 'medium', '' );
	}
	$merge = array(
		'biureitefillah'                     => 'Tefillah',
		'siddur'                             => 'Tefillah',
		'tefillosyomimnoraimmashgiach'       => 'Tefillah',
		'tefillosyomimnoraimroshyeshiva'     => 'Tefillah',
		'yesodeihadas'                       => 'Emunah',
		'yesodosofemunah'                    => 'Emunah',
		'bechirahchofshisandhashgochoprotis' => 'Emunah',
		'understandingantisemitism'          => 'Current Events',
		'learningtorahduringawar'            => 'Current Events',
	);
	if ( isset( $merge[ $key ] ) ) {
		return array( 'merge', $merge[ $key ], 'medium', '' );
	}
	$rename = array(
		'teshuvalemaaseh'           => 'Teshuva',
		'sholombayisshovavim5775'   => 'Sholom Bayis',
		'chinuchvaad'               => 'Chinuch',
		'yorehdeahissurvheter'      => "Issur V'Heter",
		'yorehdeahhilchosniddah'    => 'Hilchos Niddah',
	);
	if ( isset( $rename[ $key ] ) ) {
		return array( 'rename', $rename[ $key ], 'medium', '' );
	}
	if ( preg_match( '/^(avreichim vaad|bereishis second year|hamaspik)/i', $name ) ) {
		return $by_speaker ? array( 'keep', '', 'medium', 'The name of a vaad: the filter inside a speaker’s Mussar & Vaadim' ) : array( 'drop', '', 'medium', 'The name of a vaad: it is a series' );
	}
	if ( ner_michoel_resort_parsha_numbers( $name ) ) {
		return array( 'keep', '', 'high', 'A parsha: the "This week" box finds shiurim by it' );
	}
	if ( preg_match( '/^hilchos\b|kitzur|issur|halach/i', $name ) ) {
		return array( 'keep', '', 'high', 'An area of halacha' );
	}
	if ( preg_match( '/mesillas|pirkei avos|nefesh hachaim|mishlei|megillas esther|shir hashirim|haggadah|moreh nevuchim|sefer hamitzvos|current events|dealing with|tefillah|emunah|teshuv|mussar|chinuch|taama dekra|rinah shel torah/i', $name ) ) {
		return array( 'keep', '', 'high', 'A subject' );
	}
	// The name of a CD set or another single folder.
	return array( 'keep', '', 'low', 'Check: a subject people look for, or only a folder name?' );
}

/* ------------------------------------------------------------------
 * Making the rows.
 * ------------------------------------------------------------------ */

/** Speakers that are not a person: their shiurim get one each. */
function ner_michoel_resort_is_unknown_speaker( $name ) {
	return in_array( ner_michoel_resort_key( $name ), array( '', 'other', 'unknown', 'various' ), true );
}

/**
 * Makes the rows from the terms as they are now. $fresh false keeps every row the admin
 * changed or approved (only counts and labels are brought up to date); true starts again.
 *
 * @return array Counts by kind.
 */
function ner_michoel_resort_generate( $fresh = false ) {
	global $wpdb;
	ner_michoel_resort_install();
	$table = ner_michoel_resort_table();
	if ( $fresh ) {
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
	$kept = array();
	foreach ( (array) $wpdb->get_results( "SELECT kind, ref_id, status FROM {$table}" ) as $row ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$kept[ $row->kind . ':' . $row->ref_id ] = $row->status;
	}
	$rows = array();

	// Series.
	ner_michoel_series_forget_labels();
	$labels  = ner_michoel_series_label_map();
	$terms   = ner_michoel_series_all_terms();
	$targets = array();
	$buckets = array();
	foreach ( $terms as $term ) {
		$label = html_entity_decode( isset( $labels[ $term->term_id ] ) ? $labels[ $term->term_id ] : $term->name, ENT_QUOTES, 'UTF-8' );
		list( $action, $target, $add, $conf, $note ) = ner_michoel_resort_propose_series( ner_michoel_series_split_name( $label ) );
		if ( 'by_title' === $action ) {
			$buckets[] = (int) $term->term_id;
		}
		$rows[] = array( 'series', (int) $term->term_id, $label, (int) $term->count, $action, implode( ' / ', $target ), implode( ', ', $add ), $conf, $note );
		if ( 'by_title' !== $action ) {
			$targets[] = $target;
		}
	}

	// Shiurim placed by their title.
	foreach ( $buckets as $bucket ) {
		foreach ( (array) get_objects_in_term( $bucket, 'series' ) as $post_id ) {
			$post = get_post( (int) $post_id );
			if ( ! $post ) {
				continue;
			}
			list( $target, $add, $conf, $note ) = ner_michoel_resort_propose_title( $post->post_title );
			if ( $target ) {
				$targets[] = $target;
			}
			$rows[] = array( 'shiur', (int) $post->ID, html_entity_decode( $post->post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), 1, $target ? 'move' : 'keep', implode( ' / ', $target ), implode( ', ', $add ), $conf, $note );
		}
	}

	// A series with no shiurim of its own goes, unless it is a folder in the new layout.
	$prefixes = array();
	foreach ( $targets as $target ) {
		for ( $i = 1; $i <= count( $target ); $i++ ) {
			$prefixes[ ner_michoel_series_path_key( array_slice( $target, 0, $i ) ) ] = true;
		}
	}
	foreach ( $rows as $i => $row ) {
		if ( 'series' === $row[0] && 0 === $row[3] && 'by_title' !== $row[4] ) {
			$own = ner_michoel_series_path_key( ner_michoel_series_split_name( $row[2] ) );
			$sub = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'series' AND parent = %d", $row[1] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! isset( $prefixes[ $own ] ) && ! $sub ) {
				$rows[ $i ][4] = 'delete';
				$rows[ $i ][7] = 'high';
				$rows[ $i ][8] = 'No shiurim and nothing inside it';
			} elseif ( ! isset( $prefixes[ $own ] ) ) {
				$rows[ $i ][8] = trim( 'An empty folder; removed when nothing is left in it. ' . $rows[ $i ][8] );
			}
		}
	}

	// Topics.
	foreach ( get_terms( array( 'taxonomy' => 'topic', 'hide_empty' => false ) ) as $term ) {
		list( $action, $target, $conf, $note ) = ner_michoel_resort_propose_topic( $term->name, ner_michoel_resort_settings()['layout'] );
		$rows[] = array( 'topic', (int) $term->term_id, html_entity_decode( $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), (int) $term->count, $action, $target, '', $conf, $note );
	}

	// Speakers, and the shiurim whose speaker is not a person.
	foreach ( get_terms( array( 'taxonomy' => 'speaker', 'hide_empty' => false ) ) as $term ) {
		$name = html_entity_decode( $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( preg_match( '/\bzt$/i', $name ) ) {
			$rows[] = array( 'speaker', (int) $term->term_id, $name, (int) $term->count, 'rename', $name . '"l', '', 'medium', 'The name looks cut short' );
		} elseif ( ner_michoel_resort_is_unknown_speaker( trim( $name, " -–" ) ) ) {
			$rows[] = array( 'speaker', (int) $term->term_id, $name, (int) $term->count, 'keep', '', '', 'low', 'Not a person: give each of its shiurim a speaker (Shiurim with no speaker)' );
			foreach ( (array) get_objects_in_term( (int) $term->term_id, 'speaker' ) as $post_id ) {
				$post  = get_post( (int) $post_id );
				$guess = $post ? ner_michoel_resort_speaker_in_title( $post->post_title ) : '';
				if ( $post ) {
					$rows[] = array( 'byspeak', (int) $post->ID, html_entity_decode( $post->post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), 1, '' !== $guess ? 'assign' : 'keep', $guess, '', '' !== $guess ? 'medium' : 'low', '' !== $guess ? 'Speaker named in the title' : 'Pick the speaker' );
				}
			}
		} else {
			$rows[] = array( 'speaker', (int) $term->term_id, $name, (int) $term->count, 'keep', '', '', 'high', '' );
		}
	}

	$counts = array();
	foreach ( $rows as $row ) {
		$id = $row[0] . ':' . $row[1];
		if ( isset( $kept[ $id ] ) && 'proposed' !== $kept[ $id ] ) {
			$wpdb->update( $table, array( 'current_label' => $row[2], 'shiurim' => $row[3] ), array( 'kind' => $row[0], 'ref_id' => $row[1] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} else {
			$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'kind'          => $row[0],
					'ref_id'        => $row[1],
					'current_label' => mb_substr( $row[2], 0, 500 ),
					'shiurim'       => $row[3],
					'action'        => $row[4],
					'target'        => mb_substr( $row[5], 0, 500 ),
					'add_topics'    => mb_substr( $row[6], 0, 500 ),
					'confidence'    => $row[7],
					'note'          => mb_substr( $row[8], 0, 500 ),
					'status'        => 'proposed',
				)
			);
		}
		$counts[ $row[0] ] = ( isset( $counts[ $row[0] ] ) ? $counts[ $row[0] ] : 0 ) + 1;
		unset( $kept[ $id ] );
	}
	// Rows for terms or shiurim that are gone.
	foreach ( array_keys( $kept ) as $id ) {
		list( $kind, $ref ) = explode( ':', $id );
		$wpdb->delete( $table, array( 'kind' => $kind, 'ref_id' => (int) $ref ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
	update_option( 'nm_resort_generated', time(), false );
	return $counts;
}

/** A speaker's name in a title ("Kinnos 5778, Rabbi Avrohom Meiselman"), by the speakers there are. */
function ner_michoel_resort_speaker_in_title( $title ) {
	static $names = null;
	if ( null === $names ) {
		$names = array();
		foreach ( get_terms( array( 'taxonomy' => 'speaker', 'hide_empty' => false ) ) as $term ) {
			$name = html_entity_decode( $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( ! ner_michoel_resort_is_unknown_speaker( trim( $name, " -–" ) ) && strlen( $name ) > 6 ) {
				$names[] = $name;
			}
		}
		usort(
			$names,
			function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
	}
	$title = html_entity_decode( (string) $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	foreach ( $names as $name ) {
		$short = preg_replace( '/^(rabbi|rav|harav)\s+/i', '', $name );
		if ( false !== stripos( $title, $name ) || ( strlen( $short ) > 6 && false !== stripos( $title, $short ) ) ) {
			return $name;
		}
	}
	if ( preg_match( '/rosh ?ha?yeshiva/i', $title ) ) {
		foreach ( $names as $name ) {
			if ( preg_match( '/rosh ?ha?yeshiva/i', $name ) ) {
				return $name;
			}
		}
	}
	return '';
}

/** Every row as arrays for the screen. */
function ner_michoel_resort_rows( $kind = '' ) {
	global $wpdb;
	$table = ner_michoel_resort_table();
	$sql   = "SELECT id, kind, ref_id, current_label, shiurim, action, target, add_topics, confidence, note, status FROM {$table}";
	if ( '' !== $kind ) {
		$sql .= $wpdb->prepare( ' WHERE kind = %s', $kind );
	}
	$rows = (array) $wpdb->get_results( $sql . ' ORDER BY kind, current_label', ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
	foreach ( $rows as &$row ) {
		$row['id']      = (int) $row['id'];
		$row['ref_id']  = (int) $row['ref_id'];
		$row['shiurim'] = (int) $row['shiurim'];
	}
	return $rows;
}

/**
 * Saves the admin's change to one row. $change: action, target, add_topics, status
 * ('approved' to approve; any other change makes it 'edited'). A WP_Error when it doesn't fit.
 */
function ner_michoel_resort_save_row( $id, array $change ) {
	global $wpdb;
	$table = ner_michoel_resort_table();
	$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( ! $row ) {
		return new WP_Error( 'nm_resort_no_row', __( 'That row is gone. Reload the page.', 'ner-michoel-core' ), array( 'status' => 404 ) );
	}
	$data = array();
	if ( isset( $change['action'] ) ) {
		$actions = ner_michoel_resort_actions();
		$action  = sanitize_key( $change['action'] );
		if ( ! in_array( $action, $actions[ $row['kind'] ], true ) ) {
			return new WP_Error( 'nm_resort_action', __( 'That choice is not for this row.', 'ner-michoel-core' ), array( 'status' => 400 ) );
		}
		$data['action'] = $action;
	}
	if ( isset( $change['target'] ) ) {
		$target = sanitize_text_field( (string) $change['target'] );
		if ( in_array( $row['kind'], array( 'series', 'shiur' ), true ) ) {
			$target = implode( ' / ', ner_michoel_series_split_name( str_replace( array( ' > ', '>' ), ' / ', $target ) ) );
		}
		$data['target'] = mb_substr( $target, 0, 500 );
	}
	if ( isset( $change['add_topics'] ) ) {
		$names              = array_filter( array_map( 'trim', explode( ',', sanitize_text_field( (string) $change['add_topics'] ) ) ) );
		$data['add_topics'] = mb_substr( implode( ', ', array_unique( $names ) ), 0, 500 );
	}
	$action = isset( $data['action'] ) ? $data['action'] : $row['action'];
	$target = isset( $data['target'] ) ? $data['target'] : $row['target'];
	if ( in_array( $action, array( 'move', 'rename', 'merge', 'assign' ), true ) && '' === trim( $target ) ) {
		$data['status'] = 'proposed';
	}
	if ( isset( $change['status'] ) && in_array( $change['status'], array( 'approved', 'proposed' ), true ) ) {
		if ( 'approved' === $change['status'] && in_array( $action, array( 'move', 'rename', 'merge', 'assign' ), true ) && '' === trim( $target ) ) {
			return new WP_Error( 'nm_resort_target', __( 'Say where it goes first.', 'ner-michoel-core' ), array( 'status' => 400 ) );
		}
		$data['status'] = $change['status'];
	} elseif ( $data && ! isset( $data['status'] ) ) {
		$data['status'] = 'edited';
	}
	if ( $data ) {
		$wpdb->update( $table, $data, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

/** A row needs the admin when nobody looked at it and the rule was not sure, or it says nowhere to go. */
function ner_michoel_resort_needs_review( array $row ) {
	if ( 'proposed' !== $row['status'] ) {
		return false;
	}
	if ( in_array( $row['action'], array( 'move', 'rename', 'merge', 'assign' ), true ) && '' === trim( (string) $row['target'] ) ) {
		return true;
	}
	if ( 'byspeak' === $row['kind'] ) {
		return true;
	}
	return 'high' !== $row['confidence'];
}

/* ------------------------------------------------------------------
 * The spreadsheet: download and upload.
 * ------------------------------------------------------------------ */

function ner_michoel_resort_csv_columns() {
	return array( 'row', 'kind', 'id', 'current', 'shiurim', 'action', 'goes to', 'add topics', 'confidence', 'note', 'approved' );
}

function ner_michoel_resort_csv_download() {
	if ( ! current_user_can( 'manage_categories' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'ner-michoel-core' ) );
	}
	check_admin_referer( 'nm_resort_csv' );
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="series-topics-plan-' . gmdate( 'Y-m-d' ) . '.csv"' );
	$out = fopen( 'php://output', 'w' );
	fwrite( $out, "\xEF\xBB\xBF" ); // Excel reads it as UTF-8.
	fputcsv( $out, ner_michoel_resort_csv_columns() );
	foreach ( ner_michoel_resort_rows() as $row ) {
		fputcsv( $out, array( $row['id'], $row['kind'], $row['ref_id'], $row['current_label'], $row['shiurim'], $row['action'], $row['target'], $row['add_topics'], $row['confidence'], $row['note'], 'approved' === $row['status'] ? 'yes' : '' ) );
	}
	fclose( $out );
	exit;
}
add_action( 'admin_post_nm_resort_csv', 'ner_michoel_resort_csv_download' );

/**
 * Reads an edited spreadsheet back: each line is matched by its "row" number, and only
 * "action", "goes to", "add topics" and "approved" are read.
 *
 * @return array|WP_Error array( 'changed' => n, 'same' => n, 'problems' => string[] ).
 */
function ner_michoel_resort_csv_upload( $path ) {
	$fh = fopen( $path, 'r' );
	if ( ! $fh ) {
		return new WP_Error( 'nm_resort_csv', __( 'The file could not be read.', 'ner-michoel-core' ) );
	}
	$head = fgetcsv( $fh );
	if ( ! $head ) {
		fclose( $fh );
		return new WP_Error( 'nm_resort_csv', __( 'The file is empty.', 'ner-michoel-core' ) );
	}
	$head[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $head[0] );
	$col     = array_flip( array_map( 'strtolower', array_map( 'trim', $head ) ) );
	foreach ( array( 'row', 'action', 'goes to' ) as $need ) {
		if ( ! isset( $col[ $need ] ) ) {
			fclose( $fh );
			/* translators: %s: column name */
			return new WP_Error( 'nm_resort_csv', sprintf( __( 'The column "%s" is missing. Start from a downloaded spreadsheet.', 'ner-michoel-core' ), $need ) );
		}
	}
	global $wpdb;
	$table   = ner_michoel_resort_table();
	$changed = 0;
	$same    = 0;
	$problem = array();
	$line    = 1;
	while ( ( $cells = fgetcsv( $fh ) ) !== false ) {
		$line++;
		$id = isset( $cells[ $col['row'] ] ) ? (int) $cells[ $col['row'] ] : 0;
		if ( ! $id ) {
			continue;
		}
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row ) {
			/* translators: %d: line number */
			$problem[] = sprintf( __( 'Line %d: no such row (the plan was made again since)', 'ner-michoel-core' ), $line );
			continue;
		}
		$change = array(
			'action' => trim( (string) $cells[ $col['action'] ] ),
			'target' => trim( (string) $cells[ $col['goes to'] ] ),
		);
		if ( isset( $col['add topics'] ) && isset( $cells[ $col['add topics'] ] ) ) {
			$change['add_topics'] = trim( (string) $cells[ $col['add topics'] ] );
		}
		$approved = isset( $col['approved'] ) && isset( $cells[ $col['approved'] ] ) && preg_match( '/^(y|yes|x|1|true|approved)$/i', trim( (string) $cells[ $col['approved'] ] ) );
		$differs  = $change['action'] !== $row['action'] || $change['target'] !== $row['target'] || ( isset( $change['add_topics'] ) && $change['add_topics'] !== $row['add_topics'] ) || ( $approved !== ( 'approved' === $row['status'] ) );
		if ( ! $differs ) {
			$same++;
			continue;
		}
		if ( $approved ) {
			$change['status'] = 'approved';
		}
		$saved = ner_michoel_resort_save_row( $id, $change );
		if ( is_wp_error( $saved ) ) {
			/* translators: 1: line number, 2: problem */
			$problem[] = sprintf( __( 'Line %1$d: %2$s', 'ner-michoel-core' ), $line, $saved->get_error_message() );
			continue;
		}
		$changed++;
	}
	fclose( $fh );
	return array(
		'changed'  => $changed,
		'same'     => $same,
		'problems' => array_slice( $problem, 0, 50 ),
	);
}

/* ------------------------------------------------------------------
 * The plan as maps, and what it does to one shiur.
 * ------------------------------------------------------------------ */

/** The rows as lookups by term or post ID, with the settings. Built once per request. */
function ner_michoel_resort_plan( $reset = false ) {
	static $plan = null;
	if ( $reset ) {
		$plan = null;
	}
	if ( null !== $plan ) {
		return $plan;
	}
	$plan = array(
		'series'   => array(),
		'shiur'    => array(),
		'topic'    => array(),
		'topicby'  => array(),
		'speaker'  => array(),
		'byspeak'  => array(),
		'settings' => ner_michoel_resort_settings(),
	);
	foreach ( ner_michoel_resort_rows() as $row ) {
		$add = array_filter( array_map( 'trim', explode( ',', $row['add_topics'] ) ) );
		$one = array(
			'action' => $row['action'],
			'target' => trim( $row['target'] ),
			'add'    => array_values( $add ),
			'label'  => $row['current_label'],
		);
		$plan[ $row['kind'] ][ $row['ref_id'] ] = $one;
		if ( 'topic' === $row['kind'] ) {
			$plan['topicby'][ ner_michoel_resort_key( $row['current_label'] ) ] = $one;
		}
	}
	return $plan;
}

/** A topic name as it will be after the plan ('' when it goes). */
function ner_michoel_resort_topic_name_after( $name, $plan, $never_drop = false ) {
	$key = ner_michoel_resort_key( $name );
	for ( $guard = 0; $guard < 5 && isset( $plan['topicby'][ $key ] ); $guard++ ) {
		$rule = $plan['topicby'][ $key ];
		if ( 'drop' === $rule['action'] ) {
			return $never_drop ? $name : '';
		}
		if ( ( 'rename' === $rule['action'] || 'merge' === $rule['action'] ) && '' !== $rule['target'] ) {
			$name = $rule['target'];
			$key  = ner_michoel_resort_key( $name );
			continue;
		}
		break;
	}
	return $name;
}

/** The Hebrew year a shiur was given in: from its title ("5787") or its date. */
function ner_michoel_resort_shiur_year( $title, $date ) {
	if ( preg_match( '/\b(57[5-9]\d)\b/', (string) $title, $m ) ) {
		return $m[1];
	}
	if ( function_exists( 'ner_michoel_hebrew_from_fixed' ) && preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', (string) $date, $m ) ) {
		$hebrew = ner_michoel_hebrew_from_fixed( ner_michoel_fixed_from_gregorian( (int) $m[1], (int) $m[2], (int) $m[3] ) );
		return (string) $hebrew[0];
	}
	return '';
}

/** Words in a path that are only folders, never a topic. */
function ner_michoel_resort_is_folder_word( $part ) {
	$key = ner_michoel_resort_key( $part );
	return (bool) preg_match( '/^(perek\d|sefer(bereishis|shemos|vayikra|bamidbar|devarim)$|weeklyparsha$|moadim$|othershiurim$|mishnayomis$)/', $key );
}

/**
 * Where a shiur lands, with the settings. $parts is its subject path from the plan
 * ("Gemara / Bava Kamma / Perek 3"). Returns array( 'path' => parts, 'topics' => names ):
 * the levels the layout leaves out of the path come back as topics, so a series page can
 * still be filtered by them (Rabbi Klein / Gemara, topic "Bava Kamma").
 *
 * Speaker layout (the default): Speaker / Subject, and for Gemara, when split, Speaker /
 * Gemara / Bava Kamma (or "Bava Kamma 5787"); never deeper. Events go into the General
 * group (General / Yarchei Kallah / the event), and so do shiurim with no speaker.
 * Subject layout: the subject path, without the Perek level when it is off, and with a course
 * ("Rabbi Klein: Bava Kamma 5787") under the masechta when courses are on.
 *
 * @param string[] $parts
 */
function ner_michoel_resort_place( array $parts, array $settings, $speaker = '', $title = '', $date = '' ) {
	$parts = array_values( $parts );
	if ( ! $parts ) {
		return array( 'path' => array(), 'topics' => array() );
	}
	$is_gemara = 'gemara' === ner_michoel_resort_key( $parts[0] );
	$has_person = '' !== $speaker && ! ner_michoel_resort_is_unknown_speaker( trim( $speaker, " -–" ) );

	if ( 'speaker' !== $settings['layout'] ) {
		if ( ! $is_gemara ) {
			return array( 'path' => $parts, 'topics' => array() );
		}
		if ( ! $settings['keep_perek'] ) {
			$parts = array_values(
				array_filter(
					$parts,
					function ( $part ) {
						return ! preg_match( '/^perek\b/i', $part );
					}
				)
			);
		}
		if ( $settings['courses'] && count( $parts ) >= 2 && '' !== ner_michoel_resort_masechta( $parts[1] ) && $has_person ) {
			$year = ner_michoel_resort_shiur_year( $title, $date );
			if ( '' !== $year ) {
				array_splice( $parts, 2, 0, array( $speaker . ': ' . $parts[1] . ' ' . $year ) );
			}
		}
		return array( 'path' => $parts, 'topics' => array() );
	}

	$section = $parts[0];
	$rest    = array_slice( $parts, 1 );
	$topics  = array();
	foreach ( $rest as $part ) {
		if ( ! ner_michoel_resort_is_folder_word( $part ) ) {
			$topics[] = $part;
		}
	}
	// Events have no one speaker: General / Yarchei Kallah / the event.
	if ( 'events' === ner_michoel_resort_key( $section ) ) {
		$path = array_merge( array( $settings['general_name'] ), array_slice( $rest, 0, 2 ) );
		return array( 'path' => $path, 'topics' => array_values( array_diff( $topics, $path ) ) );
	}
	$path = array( $has_person ? $speaker : $settings['general_name'], $section );
	if ( $is_gemara ) {
		$mode = $has_person && isset( $settings['speaker_split'][ $speaker ] ) ? $settings['speaker_split'][ $speaker ] : $settings['gemara_split'];
		$m    = $rest ? ner_michoel_resort_masechta( $rest[0] ) : '';
		if ( '' !== $m && 'one' !== $mode ) {
			$year   = 'masechta_year' === $mode ? ner_michoel_resort_shiur_year( $title, $date ) : '';
			$path[] = trim( $m . ' ' . $year );
		}
		// The perek as a topic of its own masechta ("Bava Kamma Perek 3"): a filter, not a level.
		if ( $settings['keep_perek'] && '' !== $m && isset( $rest[1] ) && preg_match( '/^perek\s*(\d+)/i', $rest[1], $pm ) ) {
			$topics[] = $m . ' Perek ' . $pm[1];
		}
	} elseif ( $settings['split_others'] && 'shmuess' !== ner_michoel_resort_key( $section ) && $topics ) {
		// Parsha: the parsha, not the sefer; the others: their sefer, area or vaad.
		$path[] = 'parsha' === ner_michoel_resort_key( $section ) && count( $topics ) > 0 ? end( $topics ) : $topics[0];
	}
	if ( 'shmuess' === ner_michoel_resort_key( $section ) ) {
		$topics = array(); // "Mashgiach" / "Rosh Yeshiva" is the speaker already.
	}
	return array( 'path' => $path, 'topics' => array_values( array_diff( $topics, $path ) ) );
}

/**
 * What the plan does to one shiur. $have: array( 'series' => ids, 'topic' => ids, 'speaker' => ids ).
 * Returns array( 'series' => list of paths (string[]) or term IDs (int), 'topics' => names or IDs,
 * 'speakers' => names or IDs ). An ID means "stays as it is".
 */
function ner_michoel_resort_shiur_after( $post_id, array $have, $title, $date, array $plan ) {
	$settings = $plan['settings'];
	$speakers = array();
	$speaker  = '';
	foreach ( $have['speaker'] as $sid ) {
		$rule = isset( $plan['speaker'][ $sid ] ) ? $plan['speaker'][ $sid ] : null;
		$own  = isset( $plan['byspeak'][ $post_id ] ) ? $plan['byspeak'][ $post_id ] : null;
		if ( $own && 'assign' === $own['action'] && '' !== $own['target'] ) {
			$speakers[] = $own['target'];
			$speaker    = $own['target'];
		} elseif ( $rule && in_array( $rule['action'], array( 'rename', 'merge' ), true ) && '' !== $rule['target'] ) {
			$speakers[] = $rule['target'];
			$speaker    = $rule['target'];
		} else {
			$speakers[] = (int) $sid;
			if ( '' === $speaker && $rule ) {
				$speaker = $rule['label'];
			}
		}
	}

	$series = array();
	$add    = array();
	$levels = array();
	foreach ( $have['series'] as $sid ) {
		$rule = isset( $plan['series'][ $sid ] ) ? $plan['series'][ $sid ] : null;
		if ( $rule && 'by_title' === $rule['action'] ) {
			$rule = isset( $plan['shiur'][ $post_id ] ) ? $plan['shiur'][ $post_id ] : null;
		}
		$by_title = isset( $plan['series'][ $sid ] ) && 'by_title' === $plan['series'][ $sid ]['action'];
		// Speaker layout: a shiur its title said nothing about goes to its speaker's "Other Shiurim".
		if ( $by_title && 'speaker' === $settings['layout'] && ( ! $rule || '' === $rule['target'] ) ) {
			$rule = array( 'action' => 'move', 'target' => 'Other Shiurim', 'add' => $rule ? $rule['add'] : array() );
		}
		if ( ! $rule || 'keep' === $rule['action'] || ( 'move' === $rule['action'] && '' === $rule['target'] ) ) {
			$series[] = (int) $sid;
			continue;
		}
		if ( 'delete' === $rule['action'] ) {
			continue;
		}
		$place    = ner_michoel_resort_place( ner_michoel_series_split_name( $rule['target'] ), $settings, $speaker, $title, $date );
		$series[] = $place['path'];
		$add      = array_merge( $add, $rule['add'] );
		$levels   = array_merge( $levels, $place['topics'] );
	}

	$topics = array();
	foreach ( $have['topic'] as $tid ) {
		$rule = isset( $plan['topic'][ $tid ] ) ? $plan['topic'][ $tid ] : null;
		if ( ! $rule || 'keep' === $rule['action'] || 'rename' === $rule['action'] ) {
			$topics[] = (int) $tid; // A rename changes the topic itself, not the shiur.
		} elseif ( 'merge' === $rule['action'] && '' !== $rule['target'] ) {
			$topics[] = ner_michoel_resort_topic_name_after( $rule['target'], $plan );
		}
	}
	// The levels the layout left out of the path stay as topics even when the plan drops that topic
	// as a folder word (a masechta in a speaker's one Gemara series); a rename or merge is followed.
	foreach ( $levels as $name ) {
		$after = ner_michoel_resort_topic_name_after( $name, $plan, true );
		if ( '' !== $after ) {
			$topics[] = $after;
		}
	}
	foreach ( $add as $name ) {
		$after = ner_michoel_resort_topic_name_after( $name, $plan );
		if ( '' !== $after ) {
			$topics[] = $after;
		}
	}
	return array(
		'series'   => $series,
		'topics'   => $topics,
		'speakers' => $speakers,
	);
}

/**
 * Every shiur and written shiur with a series, topic or speaker, after $after, as
 * post ID => array( title, date, series, topic, speaker ). One query.
 */
function ner_michoel_resort_load_posts( $after = 0, $limit = 0 ) {
	global $wpdb;
	$ids_sql = $wpdb->prepare(
		"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
		INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
		INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy IN ('series', 'topic', 'speaker')
		WHERE p.post_type IN ('shiur', 'written_shiur') AND p.ID > %d ORDER BY p.ID",
		$after
	);
	if ( $limit ) {
		$ids_sql .= $wpdb->prepare( ' LIMIT %d', $limit );
	}
	$ids = array_map( 'intval', (array) $wpdb->get_col( $ids_sql ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
	if ( ! $ids ) {
		return array();
	}
	$in   = implode( ',', $ids );
	$out  = array();
	$rows = $wpdb->get_results( "SELECT ID, post_title, post_date FROM {$wpdb->posts} WHERE ID IN ({$in})" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	foreach ( $rows as $row ) {
		$out[ (int) $row->ID ] = array(
			'title'   => $row->post_title,
			'date'    => $row->post_date,
			'series'  => array(),
			'topic'   => array(),
			'speaker' => array(),
		);
	}
	$rels = $wpdb->get_results( "SELECT tr.object_id, tt.term_id, tt.taxonomy FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.taxonomy IN ('series', 'topic', 'speaker') AND tr.object_id IN ({$in})" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	foreach ( $rels as $rel ) {
		$out[ (int) $rel->object_id ][ $rel->taxonomy ][] = (int) $rel->term_id;
	}
	ksort( $out );
	return $out;
}

/** True when the shiur's terms would change. */
function ner_michoel_resort_changes( array $have, array $after, array $labels ) {
	$now_series = array();
	foreach ( $have['series'] as $id ) {
		$now_series[] = ner_michoel_series_path_key( ner_michoel_series_split_name( html_entity_decode( isset( $labels[ $id ] ) ? $labels[ $id ] : '', ENT_QUOTES, 'UTF-8' ) ) );
	}
	$new_series = array();
	foreach ( $after['series'] as $s ) {
		$new_series[] = is_int( $s ) ? ner_michoel_series_path_key( ner_michoel_series_split_name( html_entity_decode( isset( $labels[ $s ] ) ? $labels[ $s ] : '', ENT_QUOTES, 'UTF-8' ) ) ) : ner_michoel_series_path_key( $s );
	}
	sort( $now_series );
	$new_series = array_values( array_unique( $new_series ) );
	sort( $new_series );
	$series = $now_series !== $new_series;
	$topics = ner_michoel_resort_name_set( $have['topic'], 'topic' ) !== ner_michoel_resort_name_set( $after['topics'], 'topic' );
	$speak  = ner_michoel_resort_name_set( $have['speaker'], 'speaker' ) !== ner_michoel_resort_name_set( $after['speakers'], 'speaker' );
	return array( $series, $topics, $speak );
}

/** Term IDs and names as one sorted list of name keys, to compare before and after. */
function ner_michoel_resort_name_set( array $items, $taxonomy ) {
	static $names = array();
	if ( ! isset( $names[ $taxonomy ] ) ) {
		$names[ $taxonomy ] = array();
		foreach ( get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) ) as $term ) {
			$names[ $taxonomy ][ (int) $term->term_id ] = $term->name;
		}
	}
	$out = array();
	foreach ( $items as $item ) {
		$name = is_int( $item ) ? ( isset( $names[ $taxonomy ][ $item ] ) ? $names[ $taxonomy ][ $item ] : '#' . $item ) : $item;
		$out[ ner_michoel_resort_key( $name ) ] = true;
	}
	ksort( $out );
	return array_keys( $out );
}

/* ------------------------------------------------------------------
 * Preview.
 * ------------------------------------------------------------------ */

/** What Apply would do, counted, without changing anything. */
function ner_michoel_resort_preview() {
	$plan   = ner_michoel_resort_plan( true );
	$labels = ner_michoel_series_label_map();
	$index  = ner_michoel_series_label_index();
	$posts  = ner_michoel_resort_load_posts();
	$moved  = 0;
	$topic  = 0;
	$speak  = 0;
	$none   = 0;
	$new    = array();
	$used   = array();
	$by_sec = array();
	foreach ( $posts as $id => $have ) {
		$after                    = ner_michoel_resort_shiur_after( $id, $have, $have['title'], $have['date'], $plan );
		list( $s, $t, $p )        = ner_michoel_resort_changes( $have, $after, $labels );
		$moved                   += $s ? 1 : 0;
		$topic                   += $t ? 1 : 0;
		$speak                   += $p ? 1 : 0;
		if ( $have['series'] && ! $after['series'] ) {
			$none++;
		}
		foreach ( $after['series'] as $path ) {
			$parts = is_int( $path ) ? ner_michoel_series_split_name( html_entity_decode( isset( $labels[ $path ] ) ? $labels[ $path ] : '', ENT_QUOTES, 'UTF-8' ) ) : $path;
			if ( ! $parts ) {
				continue;
			}
			$by_sec[ $parts[0] ] = ( isset( $by_sec[ $parts[0] ] ) ? $by_sec[ $parts[0] ] : 0 ) + 1;
			for ( $i = 1; $i <= count( $parts ); $i++ ) {
				$key          = ner_michoel_series_path_key( array_slice( $parts, 0, $i ) );
				$used[ $key ] = true;
				if ( ! isset( $index[ $key ] ) ) {
					$new[ $key ] = implode( ' / ', array_slice( $parts, 0, $i ) );
				}
			}
		}
	}
	// Old series nothing is left in, and not a folder in the new layout.
	$gone = 0;
	foreach ( $labels as $id => $label ) {
		$key = ner_michoel_series_path_key( ner_michoel_series_split_name( html_entity_decode( $label, ENT_QUOTES, 'UTF-8' ) ) );
		if ( ! isset( $used[ $key ] ) ) {
			$gone++;
		}
	}
	$topics = array( 'rename' => 0, 'merge' => 0, 'drop' => 0 );
	foreach ( $plan['topic'] as $rule ) {
		if ( isset( $topics[ $rule['action'] ] ) ) {
			$topics[ $rule['action'] ]++;
		}
	}
	arsort( $by_sec );
	ksort( $new );
	$depth = array();
	foreach ( array_keys( $used ) as $key ) {
		$depth[ substr_count( $key, "\x1f" ) + 1 ] = ( isset( $depth[ substr_count( $key, "\x1f" ) + 1 ] ) ? $depth[ substr_count( $key, "\x1f" ) + 1 ] : 0 ) + 1;
	}
	ksort( $depth );
	return array(
		'layout'         => $plan['settings']['layout'],
		'groups'         => count( $by_sec ),
		'series_total'   => count( $used ),
		'by_level'       => $depth,
		'shiurim'        => count( $posts ),
		'moved'          => $moved,
		'topics_changed' => $topic,
		'speakers'       => $speak,
		'no_series'      => $none,
		'series_new'     => count( $new ),
		'series_new_list' => array_slice( array_values( $new ), 0, 400 ),
		'series_gone'    => $gone,
		'topic_actions'  => $topics,
		'sections'       => $by_sec,
	);
}

/* ------------------------------------------------------------------
 * Apply and Undo, in steps.
 * ------------------------------------------------------------------ */

/** A copy of every series, topic and speaker term, with its meta, before Apply. */
function ner_michoel_resort_snapshot_terms() {
	$out = array();
	foreach ( array( 'series', 'topic', 'speaker' ) as $taxonomy ) {
		foreach ( get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) ) as $term ) {
			$meta = array();
			foreach ( (array) get_term_meta( $term->term_id ) as $key => $values ) {
				$meta[ $key ] = array_map( 'maybe_unserialize', (array) $values );
			}
			$out[] = array(
				'id'          => (int) $term->term_id,
				'taxonomy'    => $taxonomy,
				'name'        => $term->name,
				'slug'        => $term->slug,
				'parent'      => (int) $term->parent,
				'description' => $term->description,
				'meta'        => $meta,
			);
		}
	}
	return $out;
}

/** The term ID for a series path, made when missing; cached for the request. */
function ner_michoel_resort_series_id( array $parts ) {
	static $cache = array();
	$key = ner_michoel_series_path_key( $parts );
	if ( isset( $cache[ $key ] ) ) {
		return $cache[ $key ];
	}
	$found = ner_michoel_series_find_or_create_path( implode( ' / ', $parts ) );
	ner_michoel_series_forget_labels();
	$cache[ $key ] = is_array( $found ) ? (int) $found['term_id'] : 0;
	return $cache[ $key ];
}

/** The term ID for a topic or speaker name, made when missing. */
function ner_michoel_resort_term_id( $name, $taxonomy ) {
	$found = term_exists( $name, $taxonomy );
	if ( ! $found ) {
		$found = wp_insert_term( $name, $taxonomy );
		if ( is_wp_error( $found ) ) {
			$existing = $found->get_error_data( 'term_exists' );
			return $existing ? (int) $existing : 0;
		}
	}
	return (int) ( is_array( $found ) ? $found['term_id'] : $found );
}

/** Starts Apply: keeps the copies, then the steps do the shiurim. */
function ner_michoel_resort_apply_start() {
	$state = ner_michoel_resort_state();
	if ( in_array( $state['phase'], array( 'applying', 'undoing' ), true ) ) {
		return new WP_Error( 'nm_resort_busy', __( 'It is already working. Wait for it to finish.', 'ner-michoel-core' ), array( 'status' => 409 ) );
	}
	if ( 'applied' === $state['phase'] ) {
		return new WP_Error( 'nm_resort_applied', __( 'The plan is already applied. Undo it first to apply it again.', 'ner-michoel-core' ), array( 'status' => 409 ) );
	}
	global $wpdb;
	ner_michoel_resort_install();
	$wpdb->query( 'DELETE FROM ' . ner_michoel_resort_backup_table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
	update_option( 'nm_resort_snapshot', ner_michoel_resort_snapshot_terms(), false );
	$total = count( ner_michoel_resort_load_posts() );
	ner_michoel_resort_save_state(
		array(
			'phase'   => 'applying',
			'step'    => 'shiurim',
			'cursor'  => 0,
			'done'    => 0,
			'total'   => $total,
			'changed' => 0,
			'message' => '',
			'started' => time(),
		)
	);
	wp_schedule_single_event( time() + 20, 'nm_resort_tick' );
	return ner_michoel_resort_state();
}

/**
 * One step of Apply or Undo, for up to $seconds. Called by the screen while it is open and
 * by WP-Cron otherwise; one at a time.
 */
function ner_michoel_resort_step( $seconds = 15 ) {
	$state = ner_michoel_resort_state();
	if ( ! in_array( $state['phase'], array( 'applying', 'undoing' ), true ) ) {
		return $state;
	}
	if ( get_transient( 'nm_resort_lock' ) ) {
		return $state;
	}
	set_transient( 'nm_resort_lock', 1, 2 * MINUTE_IN_SECONDS );
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
	$deadline = time() + $seconds;
	wp_defer_term_counting( true );
	if ( 'applying' === $state['phase'] ) {
		$state = ner_michoel_resort_apply_steps( $state, $deadline );
	} else {
		$state = ner_michoel_resort_undo_steps( $state, $deadline );
	}
	wp_defer_term_counting( false );
	ner_michoel_resort_save_state( $state );
	delete_transient( 'nm_resort_lock' );
	if ( in_array( $state['phase'], array( 'applying', 'undoing' ), true ) ) {
		wp_schedule_single_event( time() + 20, 'nm_resort_tick' );
	}
	return $state;
}
add_action( 'nm_resort_tick', 'ner_michoel_resort_step' );

function ner_michoel_resort_apply_steps( array $state, $deadline ) {
	global $wpdb;
	$plan   = ner_michoel_resort_plan( true );
	$backup = ner_michoel_resort_backup_table();

	while ( 'shiurim' === $state['step'] && time() < $deadline ) {
		$posts = ner_michoel_resort_load_posts( $state['cursor'], 100 );
		if ( ! $posts ) {
			$state['step'] = 'terms';
			break;
		}
		$labels = ner_michoel_series_label_map();
		foreach ( $posts as $id => $have ) {
			$state['cursor'] = $id;
			$state['done']++;
			$after              = ner_michoel_resort_shiur_after( $id, $have, $have['title'], $have['date'], $plan );
			list( $s, $t, $p ) = ner_michoel_resort_changes( $have, $after, $labels );
			if ( ! $s && ! $t && ! $p ) {
				continue;
			}
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$backup} (post_id, series, topics, speakers) VALUES (%d, %s, %s, %s)", $id, implode( ',', $have['series'] ), implode( ',', $have['topic'] ), implode( ',', $have['speaker'] ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $s ) {
				$ids = array();
				foreach ( $after['series'] as $path ) {
					$ids[] = is_int( $path ) ? $path : ner_michoel_resort_series_id( $path );
				}
				wp_set_object_terms( $id, array_values( array_unique( array_filter( $ids ) ) ), 'series' );
			}
			if ( $t ) {
				$ids = array();
				foreach ( $after['topics'] as $topic ) {
					$ids[] = is_int( $topic ) ? $topic : ner_michoel_resort_term_id( $topic, 'topic' );
				}
				wp_set_object_terms( $id, array_values( array_unique( array_filter( $ids ) ) ), 'topic' );
			}
			if ( $p ) {
				$ids = array();
				foreach ( $after['speakers'] as $speaker ) {
					$ids[] = is_int( $speaker ) ? $speaker : ner_michoel_resort_term_id( $speaker, 'speaker' );
				}
				wp_set_object_terms( $id, array_values( array_unique( array_filter( $ids ) ) ), 'speaker' );
			}
			$state['changed']++;
			if ( time() >= $deadline ) {
				break;
			}
		}
	}

	if ( 'terms' === $state['step'] && time() < $deadline + 30 ) {
		wp_defer_term_counting( false );
		ner_michoel_resort_recount();
		// Topics: renamed in place; merged and dropped ones go.
		foreach ( $plan['topic'] as $tid => $rule ) {
			if ( 'rename' === $rule['action'] && '' !== $rule['target'] ) {
				$other = term_exists( $rule['target'], 'topic' );
				if ( $other && (int) ( is_array( $other ) ? $other['term_id'] : $other ) !== (int) $tid ) {
					continue; // A topic by that name is there already: left for the admin.
				}
				wp_update_term( (int) $tid, 'topic', array( 'name' => $rule['target'], 'slug' => sanitize_title( $rule['target'] ) ) );
			} elseif ( in_array( $rule['action'], array( 'merge', 'drop' ), true ) ) {
				$term = get_term( (int) $tid, 'topic' );
				if ( $term && ! is_wp_error( $term ) && 0 === (int) $term->count ) {
					wp_delete_term( (int) $tid, 'topic' );
				}
			}
		}
		foreach ( $plan['speaker'] as $sid => $rule ) {
			if ( 'rename' === $rule['action'] && '' !== $rule['target'] && ! term_exists( $rule['target'], 'speaker' ) ) {
				wp_update_term( (int) $sid, 'speaker', array( 'name' => $rule['target'] ) );
			} elseif ( 'merge' === $rule['action'] ) {
				$term = get_term( (int) $sid, 'speaker' );
				if ( $term && ! is_wp_error( $term ) && 0 === (int) $term->count ) {
					wp_delete_term( (int) $sid, 'speaker' );
				}
			}
		}
		// Old series with nothing left in them or under them, deepest first.
		ner_michoel_resort_delete_empty_series();
		// The sections in the admin's order on the Shiurim page: at the top, or inside each speaker.
		$parents = 'speaker' === $plan['settings']['layout'] ? get_terms( array( 'taxonomy' => 'series', 'parent' => 0, 'hide_empty' => false, 'fields' => 'ids' ) ) : array( 0 );
		foreach ( (array) $parents as $parent ) {
			$pos = 0;
			foreach ( $plan['settings']['sections'] as $section ) {
				$id = ner_michoel_series_find_child( $section, (int) $parent );
				if ( $id ) {
					update_term_meta( $id, 'nm_order', ++$pos );
				}
			}
		}
		ner_michoel_series_forget_labels();
		$state['phase']      = 'applied';
		$state['step']       = '';
		$state['applied_at'] = time();
		/* translators: %s: number of shiurim */
		$state['message'] = sprintf( __( 'Applied. %s shiurim were changed.', 'ner-michoel-core' ), number_format_i18n( $state['changed'] ) );
	}
	return $state;
}

/** Brings the term counts up to date after the deferred counting. */
function ner_michoel_resort_recount() {
	foreach ( array( 'series', 'topic', 'speaker' ) as $taxonomy ) {
		$ids = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'fields' => 'tt_ids' ) );
		if ( $ids && ! is_wp_error( $ids ) ) {
			wp_update_term_count_now( $ids, $taxonomy );
		}
	}
}

/** Deletes series that hold no shiurim and have no series inside, again and again from the bottom. */
function ner_michoel_resort_delete_empty_series() {
	global $wpdb;
	for ( $round = 0; $round < 10; $round++ ) {
		$ids = $wpdb->get_col( "SELECT tt.term_id FROM {$wpdb->term_taxonomy} tt WHERE tt.taxonomy = 'series' AND tt.count = 0 AND NOT EXISTS (SELECT 1 FROM {$wpdb->term_taxonomy} c WHERE c.taxonomy = 'series' AND c.parent = tt.term_id) AND NOT EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr WHERE tr.term_taxonomy_id = tt.term_taxonomy_id)" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $ids ) {
			return;
		}
		foreach ( $ids as $id ) {
			wp_delete_term( (int) $id, 'series' );
		}
	}
}

/** Starts Undo. */
function ner_michoel_resort_undo_start() {
	$state = ner_michoel_resort_state();
	if ( 'applied' !== $state['phase'] && ! ( 'applying' === $state['phase'] && ! empty( $state['stuck'] ) ) ) {
		return new WP_Error( 'nm_resort_not_applied', __( 'There is nothing to undo.', 'ner-michoel-core' ), array( 'status' => 409 ) );
	}
	global $wpdb;
	$state['phase']  = 'undoing';
	$state['step']   = 'terms';
	$state['cursor'] = 0;
	$state['done']   = 0;
	$state['total']  = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ner_michoel_resort_backup_table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
	ner_michoel_resort_save_state( $state );
	wp_schedule_single_event( time() + 20, 'nm_resort_tick' );
	return $state;
}

function ner_michoel_resort_undo_steps( array $state, $deadline ) {
	global $wpdb;
	$snapshot = get_option( 'nm_resort_snapshot' );
	$snapshot = is_array( $snapshot ) ? $snapshot : array();
	$map      = get_option( 'nm_resort_undo_map' );
	$map      = is_array( $map ) ? $map : array();

	if ( 'terms' === $state['step'] ) {
		// Every term as it was: names put back, and the deleted ones made again (parents first).
		$by = array();
		foreach ( $snapshot as $term ) {
			$by[ $term['taxonomy'] . ':' . $term['id'] ] = $term;
		}
		// First the terms that are still there, so a name or address a renamed term took is free again.
		foreach ( $snapshot as $term ) {
			$now = get_term( $term['id'], $term['taxonomy'] );
			if ( $now && ! is_wp_error( $now ) ) {
				wp_update_term( $term['id'], $term['taxonomy'], array( 'name' => $term['name'], 'slug' => $term['slug'], 'description' => $term['description'] ) );
			}
		}
		for ( $round = 0; $round < 8; $round++ ) {
			$left = 0;
			foreach ( $snapshot as $term ) {
				$key = $term['taxonomy'] . ':' . $term['id'];
				if ( isset( $map[ $key ] ) ) {
					continue;
				}
				$parent = 0;
				if ( $term['parent'] ) {
					$pkey = $term['taxonomy'] . ':' . $term['parent'];
					if ( ! isset( $map[ $pkey ] ) ) {
						$left++;
						continue;
					}
					$parent = $map[ $pkey ];
				}
				$now = get_term( $term['id'], $term['taxonomy'] );
				if ( $now && ! is_wp_error( $now ) ) {
					wp_update_term( $term['id'], $term['taxonomy'], array( 'name' => $term['name'], 'slug' => $term['slug'], 'parent' => $parent, 'description' => $term['description'] ) );
					$map[ $key ] = (int) $term['id'];
					continue;
				}
				$made = wp_insert_term( $term['name'], $term['taxonomy'], array( 'slug' => $term['slug'], 'parent' => $parent, 'description' => $term['description'] ) );
				if ( is_wp_error( $made ) ) {
					$existing = $made->get_error_data( 'term_exists' );
					$map[ $key ] = $existing ? (int) $existing : 0;
					continue;
				}
				$map[ $key ] = (int) $made['term_id'];
				foreach ( $term['meta'] as $meta_key => $values ) {
					foreach ( $values as $value ) {
						add_term_meta( $map[ $key ], $meta_key, $value );
					}
				}
			}
			if ( ! $left ) {
				break;
			}
		}
		// The section order Apply set.
		foreach ( $snapshot as $term ) {
			if ( 'series' === $term['taxonomy'] && isset( $map[ 'series:' . $term['id'] ] ) && $map[ 'series:' . $term['id'] ] === $term['id'] ) {
				$old = isset( $term['meta']['nm_order'][0] ) ? $term['meta']['nm_order'][0] : '';
				if ( '' === $old ) {
					delete_term_meta( $term['id'], 'nm_order' );
				} else {
					update_term_meta( $term['id'], 'nm_order', $old );
				}
			}
		}
		update_option( 'nm_resort_undo_map', $map, false );
		// The plan's rows follow the terms that were made again, so the admin's choices stay with them.
		$table = ner_michoel_resort_table();
		foreach ( $map as $key => $new ) {
			list( $taxonomy, $old ) = explode( ':', $key );
			if ( $new && (int) $old !== (int) $new ) {
				$wpdb->update( $table, array( 'ref_id' => (int) $new ), array( 'kind' => $taxonomy, 'ref_id' => (int) $old ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}
		$state['step'] = 'shiurim';
	}

	$backup = ner_michoel_resort_backup_table();
	while ( 'shiurim' === $state['step'] && time() < $deadline ) {
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$backup} WHERE post_id > %d ORDER BY post_id LIMIT 100", $state['cursor'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $rows ) {
			$state['step'] = 'tidy';
			break;
		}
		foreach ( $rows as $row ) {
			foreach ( array( 'series' => 'series', 'topics' => 'topic', 'speakers' => 'speaker' ) as $col => $taxonomy ) {
				$ids = array();
				foreach ( array_filter( explode( ',', (string) $row->$col ) ) as $old ) {
					$key   = $taxonomy . ':' . (int) $old;
					$ids[] = isset( $map[ $key ] ) ? $map[ $key ] : (int) $old;
				}
				wp_set_object_terms( (int) $row->post_id, array_values( array_filter( $ids ) ), $taxonomy );
			}
			$state['cursor'] = (int) $row->post_id;
			$state['done']++;
			if ( time() >= $deadline ) {
				break;
			}
		}
	}

	if ( 'tidy' === $state['step'] ) {
		wp_defer_term_counting( false );
		ner_michoel_resort_recount();
		// Terms Apply made that are empty again.
		$had = array();
		foreach ( $map as $id ) {
			$had[ (int) $id ] = true;
		}
		foreach ( array( 'topic', 'speaker' ) as $taxonomy ) {
			foreach ( get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) ) as $term ) {
				if ( ! isset( $had[ (int) $term->term_id ] ) && 0 === (int) $term->count ) {
					wp_delete_term( (int) $term->term_id, $taxonomy );
				}
			}
		}
		for ( $round = 0; $round < 10; $round++ ) {
			$gone = 0;
			foreach ( ner_michoel_series_all_terms() as $term ) {
				if ( ! isset( $had[ (int) $term->term_id ] ) && 0 === (int) $term->count && ! get_term_children( (int) $term->term_id, 'series' ) ) {
					wp_delete_term( (int) $term->term_id, 'series' );
					$gone++;
				}
			}
			if ( ! $gone ) {
				break;
			}
		}
		ner_michoel_series_forget_labels();
		delete_option( 'nm_resort_undo_map' );
		$state['phase']   = 'idle';
		$state['step']    = '';
		$state['message'] = __( 'Undone. Everything is back as it was before Apply.', 'ner-michoel-core' );
		// The rows point at terms by ID: made again from what is there now, keeping the admin's choices.
		ner_michoel_resort_generate( false );
	}
	return $state;
}

/* ------------------------------------------------------------------
 * REST.
 * ------------------------------------------------------------------ */

function ner_michoel_resort_can() {
	return current_user_can( 'manage_categories' );
}

function ner_michoel_resort_register_routes() {
	$routes = array(
		'/resort/rows'     => array( 'GET', 'ner_michoel_resort_route_rows' ),
		'/resort/row'      => array( 'POST', 'ner_michoel_resort_route_row' ),
		'/resort/approve'  => array( 'POST', 'ner_michoel_resort_route_approve' ),
		'/resort/generate' => array( 'POST', 'ner_michoel_resort_route_generate' ),
		'/resort/settings' => array( 'POST', 'ner_michoel_resort_route_settings' ),
		'/resort/preview'  => array( 'POST', 'ner_michoel_resort_route_preview' ),
		'/resort/apply'    => array( 'POST', 'ner_michoel_resort_route_apply' ),
		'/resort/undo'     => array( 'POST', 'ner_michoel_resort_route_undo' ),
		'/resort/status'   => array( 'POST', 'ner_michoel_resort_route_status' ),
		'/resort/upload'   => array( 'POST', 'ner_michoel_resort_route_upload' ),
	);
	foreach ( $routes as $path => $route ) {
		register_rest_route(
			'ner-michoel/v1',
			$path,
			array(
				'methods'             => $route[0],
				'callback'            => $route[1],
				'permission_callback' => 'ner_michoel_resort_can',
			)
		);
	}
}
add_action( 'rest_api_init', 'ner_michoel_resort_register_routes' );

function ner_michoel_resort_summary() {
	$rows   = ner_michoel_resort_rows();
	$counts = array();
	foreach ( $rows as $row ) {
		$kind = $row['kind'];
		if ( ! isset( $counts[ $kind ] ) ) {
			$counts[ $kind ] = array( 'rows' => 0, 'review' => 0, 'approved' => 0 );
		}
		$counts[ $kind ]['rows']++;
		$counts[ $kind ]['review']   += ner_michoel_resort_needs_review( $row ) ? 1 : 0;
		$counts[ $kind ]['approved'] += 'approved' === $row['status'] ? 1 : 0;
	}
	return $counts;
}

function ner_michoel_resort_route_rows() {
	ner_michoel_resort_install();
	if ( ! get_option( 'nm_resort_generated' ) ) {
		ner_michoel_resort_generate( true );
	}
	$rows = ner_michoel_resort_rows();
	foreach ( $rows as &$row ) {
		$row['review'] = ner_michoel_resort_needs_review( $row );
	}
	return array(
		'rows'      => $rows,
		'summary'   => ner_michoel_resort_summary(),
		'settings'  => ner_michoel_resort_settings(),
		'state'     => ner_michoel_resort_state(),
		'generated' => (int) get_option( 'nm_resort_generated' ),
		'topics'    => array_values( wp_list_pluck( get_terms( array( 'taxonomy' => 'topic', 'hide_empty' => false ) ), 'name' ) ),
		'speakers'  => array_values( wp_list_pluck( get_terms( array( 'taxonomy' => 'speaker', 'hide_empty' => false ) ), 'name' ) ),
	);
}

function ner_michoel_resort_locked() {
	$state = ner_michoel_resort_state();
	return 'idle' !== $state['phase'] ? new WP_Error( 'nm_resort_locked', __( 'The plan is applied (or being applied). Undo it to change the plan.', 'ner-michoel-core' ), array( 'status' => 409 ) ) : null;
}

function ner_michoel_resort_route_row( WP_REST_Request $request ) {
	$locked = ner_michoel_resort_locked();
	if ( $locked ) {
		return $locked;
	}
	$change = array();
	foreach ( array( 'action', 'target', 'add_topics', 'status' ) as $key ) {
		if ( null !== $request->get_param( $key ) ) {
			$change[ $key ] = (string) $request->get_param( $key );
		}
	}
	$row = ner_michoel_resort_save_row( absint( $request->get_param( 'id' ) ), $change );
	if ( is_wp_error( $row ) ) {
		return $row;
	}
	$row['id']      = (int) $row['id'];
	$row['ref_id']  = (int) $row['ref_id'];
	$row['shiurim'] = (int) $row['shiurim'];
	$row['review']  = ner_michoel_resort_needs_review( $row );
	return $row;
}

/** Approves the rows sent (the ones the admin has on screen). */
function ner_michoel_resort_route_approve( WP_REST_Request $request ) {
	$locked = ner_michoel_resort_locked();
	if ( $locked ) {
		return $locked;
	}
	$done = 0;
	$skip = 0;
	foreach ( array_slice( array_map( 'absint', (array) $request->get_param( 'ids' ) ), 0, 3000 ) as $id ) {
		$saved = ner_michoel_resort_save_row( $id, array( 'status' => 'approved' ) );
		if ( is_wp_error( $saved ) ) {
			$skip++;
		} else {
			$done++;
		}
	}
	return array( 'approved' => $done, 'skipped' => $skip );
}

function ner_michoel_resort_route_generate( WP_REST_Request $request ) {
	$locked = ner_michoel_resort_locked();
	if ( $locked ) {
		return $locked;
	}
	return array( 'counts' => ner_michoel_resort_generate( (bool) $request->get_param( 'fresh' ) ) );
}

function ner_michoel_resort_route_settings( WP_REST_Request $request ) {
	$locked = ner_michoel_resort_locked();
	if ( $locked ) {
		return $locked;
	}
	$now  = ner_michoel_resort_settings();
	$layout = null !== $request->get_param( 'layout' ) ? ( 'subject' === $request->get_param( 'layout' ) ? 'subject' : 'speaker' ) : $now['layout'];
	$split  = (string) $request->get_param( 'gemara_split' );
	$per    = $now['speaker_split'];
	if ( is_array( $request->get_param( 'speaker_split' ) ) ) {
		$per = array();
		foreach ( $request->get_param( 'speaker_split' ) as $who => $mode ) {
			if ( in_array( $mode, ner_michoel_resort_split_modes(), true ) ) {
				$per[ sanitize_text_field( (string) $who ) ] = $mode;
			}
		}
	}
	$general = null !== $request->get_param( 'general_name' ) ? trim( str_replace( '/', '-', sanitize_text_field( (string) $request->get_param( 'general_name' ) ) ) ) : $now['general_name'];
	$save    = array(
		'layout'        => $layout,
		'general_name'  => '' !== $general ? $general : 'General',
		'gemara_split'  => in_array( $split, ner_michoel_resort_split_modes(), true ) ? $split : $now['gemara_split'],
		'speaker_split' => $per,
		'split_others'  => null !== $request->get_param( 'split_others' ) ? (bool) $request->get_param( 'split_others' ) : $now['split_others'],
		'keep_perek' => null !== $request->get_param( 'keep_perek' ) ? (bool) $request->get_param( 'keep_perek' ) : $now['keep_perek'],
		'courses'    => null !== $request->get_param( 'courses' ) ? (bool) $request->get_param( 'courses' ) : $now['courses'],
		'min_topic'  => null !== $request->get_param( 'min_topic' ) ? min( 100, absint( $request->get_param( 'min_topic' ) ) ) : $now['min_topic'],
		'sections'   => $now['sections'],
	);
	$sections = $request->get_param( 'sections' );
	if ( is_array( $sections ) ) {
		// Each entry: array( 'from' => name it had, 'to' => name now ). A rename carries into the rows.
		$list = array();
		global $wpdb;
		$table = ner_michoel_resort_table();
		foreach ( $sections as $section ) {
			$to   = isset( $section['to'] ) ? trim( sanitize_text_field( (string) $section['to'] ) ) : '';
			$from = isset( $section['from'] ) ? trim( sanitize_text_field( (string) $section['from'] ) ) : '';
			if ( '' === $to || false !== strpos( $to, '/' ) ) {
				continue;
			}
			$list[] = $to;
			if ( '' !== $from && $from !== $to ) {
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, target FROM {$table} WHERE kind IN ('series', 'shiur') AND (target = %s OR target LIKE %s)", $from, $wpdb->esc_like( $from . ' / ' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				foreach ( $rows as $row ) {
					$wpdb->update( $table, array( 'target' => $to . substr( $row->target, strlen( $from ) ) ), array( 'id' => (int) $row->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				}
			}
		}
		if ( $list ) {
			$save['sections'] = array_values( array_unique( $list ) );
		}
	}
	update_option( 'nm_resort_settings', $save, false );
	ner_michoel_resort_plan( true );
	// The topic suggestions depend on the layout: made again (rows the admin changed stay).
	if ( $layout !== $now['layout'] ) {
		ner_michoel_resort_generate( false );
	}
	return ner_michoel_resort_settings();
}

function ner_michoel_resort_route_preview() {
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
	return ner_michoel_resort_preview();
}

function ner_michoel_resort_route_apply( WP_REST_Request $request ) {
	if ( 'yes' !== $request->get_param( 'confirm' ) ) {
		return new WP_Error( 'nm_resort_confirm', __( 'Apply needs confirm: yes.', 'ner-michoel-core' ), array( 'status' => 400 ) );
	}
	$state = ner_michoel_resort_apply_start();
	return is_wp_error( $state ) ? $state : ner_michoel_resort_step( 10 );
}

function ner_michoel_resort_route_undo( WP_REST_Request $request ) {
	if ( 'yes' !== $request->get_param( 'confirm' ) ) {
		return new WP_Error( 'nm_resort_confirm', __( 'Undo needs confirm: yes.', 'ner-michoel-core' ), array( 'status' => 400 ) );
	}
	$state = ner_michoel_resort_undo_start();
	return is_wp_error( $state ) ? $state : ner_michoel_resort_step( 10 );
}

/** The state; while it works, also one more step (so it moves on while the screen is open). */
function ner_michoel_resort_route_status() {
	return ner_michoel_resort_step( 15 );
}

function ner_michoel_resort_route_upload( WP_REST_Request $request ) {
	$locked = ner_michoel_resort_locked();
	if ( $locked ) {
		return $locked;
	}
	$files = $request->get_file_params();
	if ( empty( $files['file']['tmp_name'] ) || ! is_uploaded_file( $files['file']['tmp_name'] ) ) {
		return new WP_Error( 'nm_resort_csv', __( 'Choose the spreadsheet file (.csv) first.', 'ner-michoel-core' ), array( 'status' => 400 ) );
	}
	$result = ner_michoel_resort_csv_upload( $files['file']['tmp_name'] );
	if ( is_wp_error( $result ) ) {
		$result->add_data( array( 'status' => 400 ) );
	}
	return $result;
}

/* ------------------------------------------------------------------
 * After Apply: imports and the site follow the plan.
 * ------------------------------------------------------------------ */

/** The speaker a new import's name becomes under the plan (renamed or merged speakers followed). */
function ner_michoel_resort_speaker_after( $name ) {
	$plan = ner_michoel_resort_plan();
	$key  = ner_michoel_resort_key( $name );
	foreach ( $plan['speaker'] as $rule ) {
		if ( ner_michoel_resort_key( $rule['label'] ) === $key ) {
			return in_array( $rule['action'], array( 'rename', 'merge' ), true ) && '' !== $rule['target'] ? $rule['target'] : $rule['label'];
		}
	}
	return (string) $name;
}

/**
 * After Apply, a new import goes where the plan would have put it: its old-site folder (or, for a
 * video that only has "Shiurim Videos", its title) gives the subject, and the layout the place
 * (Rabbi Klein / Gemara). 0 leaves it to the importer as before.
 */
function ner_michoel_resort_import_for_row( $series_id, $row ) {
	if ( $series_id || ! ner_michoel_resort_is_applied() ) {
		return $series_id;
	}
	$settings = ner_michoel_resort_settings();
	$path     = trim( (string) ( isset( $row->series ) ? $row->series : '' ) );
	$parts    = array();
	if ( '' !== $path ) {
		list( $action, $target ) = ner_michoel_resort_propose_series( ner_michoel_series_split_name( html_entity_decode( $path, ENT_QUOTES, 'UTF-8' ) ) );
		if ( 'by_title' !== $action ) {
			$parts = $target;
		}
	}
	if ( ! $parts && ! empty( $row->title ) ) {
		list( $target, , $conf ) = ner_michoel_resort_propose_title( $row->title );
		if ( $target && 'low' !== $conf ) {
			$parts = $target;
		} elseif ( 'speaker' === $settings['layout'] && '' !== $path ) {
			$parts = array( 'Other Shiurim' );
		}
	}
	if ( ! $parts ) {
		return $series_id;
	}
	$speaker = ner_michoel_resort_speaker_after( isset( $row->speaker ) ? $row->speaker : '' );
	$date    = isset( $row->shiur_date ) ? (string) $row->shiur_date : '';
	$place   = ner_michoel_resort_place( $parts, array_merge( $settings, array( 'courses' => false ) ), $speaker, isset( $row->title ) ? $row->title : '', $date );
	return $place['path'] ? ner_michoel_resort_series_id( $place['path'] ) : $series_id;
}
add_filter( 'ner_michoel_import_series_for_row', 'ner_michoel_resort_import_for_row', 10, 2 );

/** The topics a folder name gives, after the plan: dropped ones left out, merged and renamed ones followed, Yom Tov added. */
function ner_michoel_resort_import_topics( $names, $series_name ) {
	if ( ! ner_michoel_resort_is_applied() ) {
		return $names;
	}
	$plan = ner_michoel_resort_plan();
	list( $action, $target, $add ) = ner_michoel_resort_propose_series( ner_michoel_series_split_name( html_entity_decode( (string) $series_name, ENT_QUOTES, 'UTF-8' ) ) );
	$out = array();
	foreach ( array_merge( (array) $names, $add ) as $name ) {
		$after = ner_michoel_resort_topic_name_after( $name, $plan );
		if ( '' !== $after ) {
			$out[ ner_michoel_resort_key( $after ) ] = $after;
		}
	}
	// The levels the layout leaves out of the series, as topics (a masechta in a speaker's Gemara).
	if ( 'by_title' !== $action && $target ) {
		$place = ner_michoel_resort_place( $target, $plan['settings'], 'Someone' );
		foreach ( $place['topics'] as $name ) {
			$after = ner_michoel_resort_topic_name_after( $name, $plan, true );
			if ( '' !== $after ) {
				$out[ ner_michoel_resort_key( $after ) ] = $after;
			}
		}
	}
	return array_values( $out );
}
add_filter( 'ner_michoel_topic_names_from_series', 'ner_michoel_resort_import_topics', 10, 2 );

/** Once applied, the site's topic lists leave out the topics with fewer shiurim than the setting. */
function ner_michoel_resort_hide_small_topics( $terms, $taxonomies, $args ) {
	if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ! in_array( 'topic', (array) $taxonomies, true ) || empty( $args['hide_empty'] ) || ! is_array( $terms ) || ! ner_michoel_resort_is_applied() ) {
		return $terms;
	}
	$min = ner_michoel_resort_settings()['min_topic'];
	if ( $min < 2 ) {
		return $terms;
	}
	return array_values(
		array_filter(
			$terms,
			function ( $term ) use ( $min ) {
				return ! is_object( $term ) || ! isset( $term->count ) || (int) $term->count >= $min || ( function_exists( 'ner_michoel_topic_occasion' ) && '' !== ner_michoel_topic_occasion( $term->name ) );
			}
		)
	);
}
add_filter( 'get_terms', 'ner_michoel_resort_hide_small_topics', 10, 3 );
