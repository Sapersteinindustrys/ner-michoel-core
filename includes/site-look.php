<?php
/**
 * Site look: the fonts and colors of the whole site, chosen in the Control
 * Panel (Homepage & Look > Colors & Fonts) instead of by editing CSS.
 *
 * What is stored: one option, nm_site_look = array(
 *   'heading' => a font slug from ner_michoel_look_fonts(), or '' (the site's own),
 *   'body'    => the same, for text,
 *   'colors'  => array( brand, accent, fresh, soft, ink ) as #rrggbb,
 *   'palette' => the id of the palette they came from, 'custom', or '' ).
 * Nothing is printed until a choice differs from the site's own look (the
 * "Ner Michoel (original)" palette and no fonts), so a site that never
 * opens the screen is byte-for-byte what it was.
 *
 * The contract with the theme is a set of CSS custom properties, not PHP.
 * This file defines them on :root (ner_michoel_look_css()), and the theme's
 * stylesheets read each one as var(--nm-look-<name>, <the value it always had>),
 * so either package can be updated first:
 *   fonts   --nm-look-font-body, --nm-look-font-heading
 *   colors  --nm-look-brand, -accent, -fresh, -soft, -ink (the five a palette
 *           has) and the shades the browser works out from them with
 *           color-mix() (see ner_michoel_look_css_parts()).
 * The shades need color-mix() (Chrome 111, Safari 16.2, Firefox 113), so the
 * color block sits in an @supports rule: an older browser keeps the original look.
 *
 * It also points Astra's own palette variables at the five colors (links,
 * buttons, headings, text and the page background) and prints Google Fonts for
 * the chosen fonts, as the theme already does for Frank Ruhl Libre.
 *
 * The Control Panel shows the real homepage in an iframe (?nm_look_preview=1,
 * administrators only) and streams unsaved choices into it with postMessage,
 * so the preview is the site's own CSS, not a drawing of it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ------------------------------------------------------------------ */
/* Fonts                                                               */
/* ------------------------------------------------------------------ */

/**
 * The font catalog: popular Google Fonts, each checked against fonts.googleapis.com
 * for its weights. 'hebrew' says whether the family has Hebrew letters; one that
 * does not gets a Hebrew-capable partner after it in the stack (see
 * ner_michoel_look_font_partner()), so a Hebrew word in a title is not set in
 * whatever the visitor's system happens to have. 'generic' is the fallback kind.
 *
 * @return array slug => array( name, cat, weights, hebrew, generic )
 */
function ner_michoel_look_fonts() {
	static $fonts = null;
	if ( null !== $fonts ) {
		return $fonts;
	}

	$rows = array(
		// Sans-serif.
		array( 'inter', 'Inter', 'sans', '400;500;600;700', false, 'sans' ),
		array( 'roboto', 'Roboto', 'sans', '400;500;700', false, 'sans' ),
		array( 'open-sans', 'Open Sans', 'sans', '400;500;600;700', true, 'sans' ),
		array( 'montserrat', 'Montserrat', 'sans', '400;500;600;700', false, 'sans' ),
		array( 'poppins', 'Poppins', 'sans', '400;500;600;700', false, 'sans' ),
		array( 'lato', 'Lato', 'sans', '400;700', false, 'sans' ),
		array( 'nunito', 'Nunito', 'sans', '400;500;600;700', false, 'sans' ),
		array( 'work-sans', 'Work Sans', 'sans', '400;500;600;700', false, 'sans' ),
		array( 'dm-sans', 'DM Sans', 'sans', '400;500;600;700', false, 'sans' ),
		array( 'source-sans-3', 'Source Sans 3', 'sans', '400;500;600;700', false, 'sans' ),
		array( 'raleway', 'Raleway', 'sans', '400;500;600;700', false, 'sans' ),
		array( 'manrope', 'Manrope', 'sans', '400;500;600;700', false, 'sans' ),
		array( 'mulish', 'Mulish', 'sans', '400;500;600;700', false, 'sans' ),
		array( 'heebo', 'Heebo', 'sans', '400;500;600;700', true, 'sans' ),
		array( 'assistant', 'Assistant', 'sans', '400;500;600;700', true, 'sans' ),
		array( 'rubik', 'Rubik', 'sans', '400;500;600;700', true, 'sans' ),
		array( 'varela-round', 'Varela Round', 'sans', '400', true, 'sans' ),
		array( 'alef', 'Alef', 'sans', '400;700', true, 'sans' ),
		array( 'arimo', 'Arimo', 'sans', '400;500;600;700', true, 'sans' ),
		// Serif.
		array( 'frank-ruhl-libre', 'Frank Ruhl Libre', 'serif', '400;500;700', true, 'serif' ),
		array( 'playfair-display', 'Playfair Display', 'serif', '400;500;600;700', false, 'serif' ),
		array( 'merriweather', 'Merriweather', 'serif', '400;700', false, 'serif' ),
		array( 'lora', 'Lora', 'serif', '400;500;600;700', false, 'serif' ),
		array( 'libre-baskerville', 'Libre Baskerville', 'serif', '400;700', false, 'serif' ),
		array( 'eb-garamond', 'EB Garamond', 'serif', '400;500;600;700', false, 'serif' ),
		array( 'cormorant-garamond', 'Cormorant Garamond', 'serif', '400;500;600;700', false, 'serif' ),
		array( 'crimson-pro', 'Crimson Pro', 'serif', '400;500;600;700', false, 'serif' ),
		array( 'pt-serif', 'PT Serif', 'serif', '400;700', false, 'serif' ),
		array( 'source-serif-4', 'Source Serif 4', 'serif', '400;500;600;700', false, 'serif' ),
		array( 'tinos', 'Tinos', 'serif', '400;700', true, 'serif' ),
		array( 'david-libre', 'David Libre', 'serif', '400;500;700', true, 'serif' ),
		array( 'miriam-libre', 'Miriam Libre', 'serif', '400;500;600;700', true, 'serif' ),
		array( 'bitter', 'Bitter', 'serif', '400;500;600;700', false, 'serif' ),
		// Display (for headings).
		array( 'oswald', 'Oswald', 'display', '400;500;600;700', false, 'sans' ),
		array( 'bebas-neue', 'Bebas Neue', 'display', '400', false, 'sans' ),
		array( 'suez-one', 'Suez One', 'display', '400', true, 'serif' ),
		array( 'secular-one', 'Secular One', 'display', '400', true, 'sans' ),
		array( 'amatic-sc', 'Amatic SC', 'display', '400;700', true, 'sans' ),
		array( 'abril-fatface', 'Abril Fatface', 'display', '400', false, 'serif' ),
		// Handwriting (for headings).
		array( 'caveat', 'Caveat', 'hand', '400;500;600;700', false, 'cursive' ),
		array( 'dancing-script', 'Dancing Script', 'hand', '400;500;600;700', false, 'cursive' ),
	);

	$fonts = array();
	foreach ( $rows as $row ) {
		$fonts[ $row[0] ] = array(
			'name'    => $row[1],
			'cat'     => $row[2],
			'weights' => $row[3],
			'hebrew'  => $row[4],
			'generic' => $row[5],
		);
	}
	return $fonts;
}

function ner_michoel_look_font_categories() {
	return array(
		'sans'    => __( 'Sans-serif', 'ner-michoel-core' ),
		'serif'   => __( 'Serif', 'ner-michoel-core' ),
		'display' => __( 'Display', 'ner-michoel-core' ),
		'hand'    => __( 'Handwriting', 'ner-michoel-core' ),
	);
}

/**
 * The slug of the Hebrew-capable family that follows a font with no Hebrew
 * letters in its stack, or '' when the font has them.
 */
function ner_michoel_look_font_partner( $slug ) {
	$fonts = ner_michoel_look_fonts();
	if ( ! isset( $fonts[ $slug ] ) || $fonts[ $slug ]['hebrew'] ) {
		return '';
	}
	return 'serif' === $fonts[ $slug ]['generic'] ? 'frank-ruhl-libre' : 'heebo';
}

/**
 * A font's CSS font-family value: the font, its Hebrew partner if it needs one,
 * then fallbacks of the same kind.
 */
function ner_michoel_look_font_stack( $slug ) {
	$fonts = ner_michoel_look_fonts();
	if ( ! isset( $fonts[ $slug ] ) ) {
		return '';
	}
	$parts   = array( '"' . $fonts[ $slug ]['name'] . '"' );
	$partner = ner_michoel_look_font_partner( $slug );
	if ( $partner ) {
		$parts[] = '"' . $fonts[ $partner ]['name'] . '"';
	}
	switch ( $fonts[ $slug ]['generic'] ) {
		case 'serif':
			array_push( $parts, '"Iowan Old Style"', '"Palatino Linotype"', 'Georgia', 'serif' );
			break;
		case 'cursive':
			$parts[] = 'cursive';
			break;
		default:
			array_push( $parts, 'system-ui', '-apple-system', '"Segoe UI"', 'Roboto', '"Helvetica Neue"', 'Arial', 'sans-serif' );
	}
	return implode( ', ', $parts );
}

/**
 * The family part of a Google Fonts CSS2 URL for one font: "Open+Sans:wght@400;500;600;700".
 */
function ner_michoel_look_font_spec( $slug ) {
	$fonts = ner_michoel_look_fonts();
	if ( ! isset( $fonts[ $slug ] ) ) {
		return '';
	}
	return str_replace( ' ', '+', $fonts[ $slug ]['name'] ) . ':wght@' . $fonts[ $slug ]['weights'];
}

/**
 * The Google Fonts stylesheet the chosen fonts (and their Hebrew partners) need,
 * or '' when no font is chosen. Families are sorted so the address is stable.
 */
function ner_michoel_look_fonts_url( $look ) {
	$specs = array();
	foreach ( array( 'heading', 'body' ) as $part ) {
		if ( '' === $look[ $part ] ) {
			continue;
		}
		$specs[ ner_michoel_look_font_spec( $look[ $part ] ) ] = true;
		$partner = ner_michoel_look_font_partner( $look[ $part ] );
		if ( $partner ) {
			$specs[ ner_michoel_look_font_spec( $partner ) ] = true;
		}
	}
	if ( ! $specs ) {
		return '';
	}
	$specs = array_keys( $specs );
	sort( $specs, SORT_STRING );
	return 'https://fonts.googleapis.com/css2?family=' . implode( '&family=', $specs ) . '&display=swap';
}

/**
 * One stylesheet that shows every font's name in its own face, for the picker:
 * `text=` keeps each file to the few letters the names use (about 7 KB a font).
 */
function ner_michoel_look_picker_fonts_url() {
	$fonts = ner_michoel_look_fonts();
	$specs = array();
	$chars = '';
	foreach ( $fonts as $font ) {
		$specs[] = str_replace( ' ', '+', $font['name'] );
		$chars  .= $font['name'];
	}
	sort( $specs, SORT_STRING );
	$letters = array_unique( preg_split( '//u', $chars, -1, PREG_SPLIT_NO_EMPTY ) );
	sort( $letters, SORT_STRING );
	return 'https://fonts.googleapis.com/css2?family=' . implode( '&family=', $specs ) . '&text=' . rawurlencode( implode( '', $letters ) ) . '&display=swap';
}

/* ------------------------------------------------------------------ */
/* Colors                                                              */
/* ------------------------------------------------------------------ */

/**
 * The five colors a look has, in the order a palette lists them. Each is a job
 * on the site rather than a swatch name, so an admin knows what moves.
 */
function ner_michoel_look_roles() {
	return array(
		'brand'  => array(
			'label' => __( 'Main color', 'ner-michoel-core' ),
			'hint'  => __( 'The big banners, the Contribute button, links and buttons. Pick a rich, darker color: white text sits on it.', 'ner-michoel-core' ),
		),
		'accent' => array(
			'label' => __( 'Accent', 'ner-michoel-core' ),
			'hint'  => __( 'Warm touches: the small line above the welcome, the gold buttons, Mazal Tov.', 'ner-michoel-core' ),
		),
		'fresh'  => array(
			'label' => __( 'Highlight', 'ner-michoel-core' ),
			'hint'  => __( 'Small labels, the “New” tags and play buttons.', 'ner-michoel-core' ),
		),
		'soft'   => array(
			'label' => __( 'Background', 'ner-michoel-core' ),
			'hint'  => __( 'The soft color behind the pages. Keep it very light.', 'ner-michoel-core' ),
		),
		'ink'    => array(
			'label' => __( 'Text', 'ner-michoel-core' ),
			'hint'  => __( 'Headings and text. Keep it dark.', 'ner-michoel-core' ),
		),
	);
}

function ner_michoel_look_palette_groups() {
	return array(
		'vibrant' => __( 'Vibrant', 'ner-michoel-core' ),
		'classic' => __( 'More palettes', 'ner-michoel-core' ),
	);
}

/**
 * 30 palettes: 13 vibrant, then 17 calmer ones (the first of those is the site's
 * own, original look). Each is five colors: main, accent, highlight, background, text. Every one
 * was checked for: white text on the main color (at least 5.4:1, and on the banner's
 * lightest shade), the highlight and the dark accent shade readable on white (3.5:1),
 * the light accent shade readable on the banner (4.5:1), text on the background
 * (12:1), a light background, and no two palettes alike.
 *
 * @return array of array( id, name, group, colors by role )
 */
function ner_michoel_look_palettes() {
	static $palettes = null;
	if ( null !== $palettes ) {
		return $palettes;
	}

	$rows = array(
			array( 'electric-blue', 'Electric Blue', 'vibrant', array( '#1d4ed8', '#f59e0b', '#0e7490', '#eff6ff', '#0f1b3d' ) ),
			array( 'clear-sky', 'Clear Sky', 'vibrant', array( '#0369a1', '#fb923c', '#0d9488', '#f0f9ff', '#082f49' ) ),
			array( 'lagoon', 'Lagoon', 'vibrant', array( '#0f766e', '#fb923c', '#0369a1', '#f0fdfa', '#042f2e' ) ),
			array( 'emerald-city', 'Emerald City', 'vibrant', array( '#047857', '#eab308', '#0891b2', '#ecfdf5', '#052e22' ) ),
			array( 'spring-green', 'Spring Green', 'vibrant', array( '#147739', '#facc15', '#0d9488', '#f0fdf4', '#14341f' ) ),
			array( 'lime-zest', 'Lime Zest', 'vibrant', array( '#47730e', '#f59e0b', '#0f766e', '#f7fee7', '#1a2e05' ) ),
			array( 'golden-hour', 'Golden Hour', 'vibrant', array( '#a84d08', '#fcd34d', '#0f766e', '#fffbeb', '#3a1c05' ) ),
			array( 'sunset', 'Sunset', 'vibrant', array( '#b83e0b', '#f59e0b', '#be185d', '#fff7ed', '#3b1204' ) ),
			array( 'cherry-red', 'Cherry Red', 'vibrant', array( '#b91c1c', '#fbbf24', '#15803d', '#fef2f2', '#3f0a0a' ) ),
			array( 'rose-garden', 'Rose Garden', 'vibrant', array( '#be123c', '#f59e0b', '#0d9488', '#fff1f2', '#4c0519' ) ),
			array( 'fuchsia-night', 'Fuchsia Night', 'vibrant', array( '#a21caf', '#facc15', '#7c3aed', '#fdf4ff', '#35073f' ) ),
			array( 'violet-pop', 'Violet Pop', 'vibrant', array( '#6d28d9', '#f472b6', '#0891b2', '#f5f3ff', '#1e1146' ) ),
			array( 'indigo-dream', 'Indigo Dream', 'vibrant', array( '#4338ca', '#fb7185', '#059669', '#eef2ff', '#1e1b4b' ) ),
			array( 'ner-michoel', 'Ner Michoel (original)', 'classic', array( '#1b2f52', '#d6a547', '#2f8f5b', '#f0f5fa', '#0f172a' ) ),
			array( 'midnight-brass', 'Midnight & Brass', 'classic', array( '#172554', '#c9a227', '#2f7f9e', '#f1f5f9', '#0b1220' ) ),
			array( 'slate-sand', 'Slate & Sand', 'classic', array( '#334155', '#b08968', '#557a5f', '#f5f2ec', '#1c1917' ) ),
			array( 'forest-floor', 'Forest Floor', 'classic', array( '#1f4d3a', '#b08d57', '#3f7a53', '#f2f5f0', '#14231b' ) ),
			array( 'burgundy-cream', 'Burgundy & Cream', 'classic', array( '#6b1f2a', '#c49a45', '#607f35', '#faf4ec', '#2a1215' ) ),
			array( 'charcoal-amber', 'Charcoal & Amber', 'classic', array( '#2b2f36', '#d4a24c', '#2f7f7b', '#f3f4f6', '#111318' ) ),
			array( 'terracotta', 'Terracotta', 'classic', array( '#8c4a2f', '#d9a05b', '#53815c', '#faf3ec', '#2e1a12' ) ),
			array( 'dusty-blue', 'Dusty Blue', 'classic', array( '#3b5b7e', '#d9a66b', '#3f7d77', '#eff4f8', '#16222e' ) ),
			array( 'olive-grove', 'Olive Grove', 'classic', array( '#556b2f', '#c8963e', '#3f7d58', '#f4f6ec', '#1f2a12' ) ),
			array( 'plum-pearl', 'Plum & Pearl', 'classic', array( '#5b3a63', '#c9a77c', '#38806f', '#f6f2f7', '#25132b' ) ),
			array( 'graphite-copper', 'Graphite & Copper', 'classic', array( '#3a3f47', '#b87333', '#367f77', '#f4f1ee', '#17191c' ) ),
			array( 'deep-teal', 'Deep Teal', 'classic', array( '#1e5461', '#d4a64a', '#3a8374', '#eef5f5', '#0f2328' ) ),
			array( 'mocha', 'Mocha', 'classic', array( '#5a3e2b', '#c89b5c', '#587b49', '#f7f1ea', '#24170f' ) ),
			array( 'indigo-ink', 'Indigo Ink', 'classic', array( '#2f3a7a', '#d0a14a', '#357878', '#f1f2fa', '#111633' ) ),
			array( 'rosewood', 'Rosewood', 'classic', array( '#7a3b4b', '#d1a15f', '#467f6c', '#fbf3f3', '#2b1218' ) ),
			array( 'pine-ivory', 'Pine & Ivory', 'classic', array( '#264653', '#e9c46a', '#1f7a6e', '#f7f4ea', '#10222a' ) ),
			array( 'black-gold', 'Black & Gold', 'classic', array( '#1a1a1a', '#c9a227', '#2f8f5b', '#f5f5f4', '#0a0a0a' ) ),
	);

	$roles    = array_keys( ner_michoel_look_roles() );
	$palettes = array();
	foreach ( $rows as $row ) {
		$palettes[] = array(
			'id'     => $row[0],
			'name'   => $row[1],
			'group'  => $row[2],
			'colors' => array_combine( $roles, $row[3] ),
		);
	}
	return $palettes;
}

/**
 * The site's own colors, as the design first had them: the "Ner Michoel (original)" palette.
 */
function ner_michoel_look_default_colors() {
	foreach ( ner_michoel_look_palettes() as $palette ) {
		if ( 'ner-michoel' === $palette['id'] ) {
			return $palette['colors'];
		}
	}
	return array();
}

/**
 * #rgb or #rrggbb in, lowercase #rrggbb out, or '' for anything else.
 */
function ner_michoel_look_clean_hex( $value ) {
	$hex = is_string( $value ) ? sanitize_hex_color( trim( $value ) ) : '';
	if ( ! $hex ) {
		return '';
	}
	if ( 4 === strlen( $hex ) ) {
		$hex = '#' . $hex[1] . $hex[1] . $hex[2] . $hex[2] . $hex[3] . $hex[3];
	}
	return strtolower( $hex );
}

/* ------------------------------------------------------------------ */
/* The saved look                                                      */
/* ------------------------------------------------------------------ */

/**
 * Turns anything (a saved option, a request body) into a valid look: unknown fonts
 * become '' (the site's own), a missing or bad color falls back to today's.
 */
function ner_michoel_look_normalize( $raw ) {
	$raw      = is_array( $raw ) ? $raw : array();
	$fonts    = ner_michoel_look_fonts();
	$defaults = ner_michoel_look_default_colors();

	$look = array(
		'heading' => ( isset( $raw['heading'] ) && is_string( $raw['heading'] ) && isset( $fonts[ $raw['heading'] ] ) ) ? $raw['heading'] : '',
		'body'    => ( isset( $raw['body'] ) && is_string( $raw['body'] ) && isset( $fonts[ $raw['body'] ] ) ) ? $raw['body'] : '',
		'colors'  => array(),
		'palette' => '',
	);

	$colors_in = ( isset( $raw['colors'] ) && is_array( $raw['colors'] ) ) ? $raw['colors'] : array();
	foreach ( $defaults as $role => $default ) {
		$hex                     = isset( $colors_in[ $role ] ) ? ner_michoel_look_clean_hex( $colors_in[ $role ] ) : '';
		$look['colors'][ $role ] = $hex ? $hex : $default;
	}

	// Which palette the colors came from, so the screen can mark it. Worked out from the colors, not trusted.
	$look['palette'] = 'custom';
	foreach ( ner_michoel_look_palettes() as $palette ) {
		if ( $palette['colors'] === $look['colors'] ) {
			$look['palette'] = $palette['id'];
			break;
		}
	}
	return $look;
}

function ner_michoel_get_site_look() {
	return ner_michoel_look_normalize( get_option( 'nm_site_look', array() ) );
}

/**
 * True when the colors differ from the site's own, so the color block is needed.
 */
function ner_michoel_look_colors_customized( $colors ) {
	return $colors !== ner_michoel_look_default_colors();
}

function ner_michoel_look_is_customized( $look ) {
	return '' !== $look['heading'] || '' !== $look['body'] || ner_michoel_look_colors_customized( $look['colors'] );
}

/* ------------------------------------------------------------------ */
/* The CSS                                                             */
/* ------------------------------------------------------------------ */

/**
 * The parts of the override stylesheet that do not depend on the chosen values.
 * The Control Panel's live preview assembles the same string in JavaScript from
 * these (assets/custom-admin-look.js), so the two cannot drift; the browser test
 * compares what the page prints with what the screen builds.
 */
function ner_michoel_look_css_parts() {
	$mix = function ( $of, $pct, $with ) {
		return 'color-mix(in srgb,var(--nm-look-' . $of . ') ' . $pct . '%,' . $with . ')';
	};

	// Shades worked out in the browser from the five colors. Names are what the theme's stylesheets read.
	$derived = array(
		// From the main color.
		'brand-hover'        => $mix( 'brand', 82, '#000' ),
		'brand-2'            => $mix( 'brand', 88, '#fff' ),
		'brand-tint'         => $mix( 'brand', 10, '#fff' ),
		'brand-tint-2'       => $mix( 'brand', 18, '#fff' ),
		'brand-tint-line'    => $mix( 'brand', 20, '#fff' ),
		'brand-wash'         => $mix( 'brand', 4, '#fff' ),
		'brand-glow'         => $mix( 'brand', 10, 'transparent' ),
		'brand-ring'         => $mix( 'brand', 20, 'transparent' ),
		'brand-shadow'       => $mix( 'brand', 60, 'transparent' ),
		'brand-shadow-2'     => $mix( 'brand', 90, 'transparent' ),
		'brand-shadow-3'     => $mix( 'brand', 80, 'transparent' ),
		'hero-1'             => $mix( 'brand', 82, '#000' ),
		'hero-2'             => $mix( 'brand', 64, '#000' ),
		'hero-3'             => $mix( 'brand', 46, '#000' ),
		// The bottom banner carries 78%-white text, so it starts a little darker than the main color.
		'cta-1'              => $mix( 'brand', 86, '#000' ),
		'cta-2'              => $mix( 'brand', 56, '#000' ),
		'deep'               => $mix( 'brand', 40, '#000' ),
		'deep-2'             => $mix( 'brand', 62, '#000' ),
		'bar'                => $mix( 'brand', 52, '#000' ),
		'bar-2'              => $mix( 'brand', 74, '#000' ),
		'bar-3'              => $mix( 'brand', 92, '#fff' ),
		// From the accent.
		'accent-bright'      => $mix( 'accent', 55, '#fff' ),
		'accent-deep'        => $mix( 'accent', 62, '#000' ),
		'accent-ink'         => $mix( 'accent', 48, '#000' ),
		'accent-pale'        => $mix( 'accent', 40, '#fff' ),
		// The gold buttons carry white text, so their gradient runs darker than the accent itself.
		'gold-1'             => $mix( 'accent', 75, '#000' ),
		'gold-2'             => $mix( 'accent', 58, '#000' ),
		'gold-1-hover'       => $mix( 'gold-1', 88, '#fff' ),
		'gold-2-hover'       => $mix( 'gold-2', 88, '#fff' ),
		'gold-shadow'        => $mix( 'accent-deep', 90, 'transparent' ),
		'gold-shadow-hover'  => $mix( 'accent-deep', 95, 'transparent' ),
		'badge-1'            => $mix( 'accent', 58, '#fff' ),
		'badge-2'            => $mix( 'accent', 92, '#000' ),
		'badge-shadow'       => $mix( 'accent-deep', 85, 'transparent' ),
		'gold-soft'          => $mix( 'accent', 14, '#fff' ),
		'gold-line'          => $mix( 'accent', 22, '#fff' ),
		'gold-line-2'        => $mix( 'accent', 26, '#fff' ),
		'mazal-bg'           => $mix( 'accent', 8, '#fff' ),
		'glow-gold'          => $mix( 'accent', 20, 'transparent' ),
		'glow-gold-2'        => $mix( 'accent', 26, 'transparent' ),
		'glow-gold-3'        => $mix( 'accent', 24, 'transparent' ),
		'glow-bright'        => $mix( 'accent', 28, 'transparent' ),
		'ring-gold'          => $mix( 'accent-bright', 70, 'transparent' ),
		// From the highlight.
		'fresh-dark'         => $mix( 'fresh', 78, '#000' ),
		'fresh-soft'         => $mix( 'fresh', 13, '#fff' ),
		'fresh-tint'         => $mix( 'fresh', 9, '#fff' ),
		'glow-fresh'         => $mix( 'fresh', 16, 'transparent' ),
		// From the text color and the background.
		'ink-2'              => $mix( 'ink', 82, '#fff' ),
		'muted'              => $mix( 'ink', 70, '#fff' ),
		'faint'              => $mix( 'ink', 44, '#fff' ),
		'line'               => $mix( 'ink', 11, '#fff' ),
		'line-soft'          => $mix( 'ink', 7, '#fff' ),
		'surface-soft'       => $mix( 'soft', 55, '#fff' ),
		'page'               => $mix( 'soft', 65, '#fff' ),
	);

	$colors = '';
	foreach ( $derived as $name => $value ) {
		$colors .= '--nm-look-' . $name . ':' . $value . ';';
	}
	// Astra's own palette: links and buttons, their hover, headings, text, the page background.
	$colors .= '--ast-global-color-0:var(--nm-look-brand);--ast-global-color-1:var(--nm-look-brand-hover);--ast-global-color-2:var(--nm-look-ink);--ast-global-color-3:var(--nm-look-ink-2);--ast-global-color-5:var(--nm-look-soft);';

	return array(
		'supports'     => '@supports (color:color-mix(in srgb,red 50%,blue))',
		'colors'       => $colors,
		// The dark app (Modern's sibling, 24Six) and the player: the highlight is their accent. Printed after the saved appearance options, so it wins.
		'dark'         => 'body.is-nm-app,.sh-player--24six,.sh-sheet--24six{--sh-accent:var(--nm-look-fresh);--sh-accent-hover:var(--nm-look-fresh-dark);}',
		'font_body'    => 'body,button,input,select,textarea,.ast-button,.ast-custom-button{font-family:var(--nm-look-font-body);}body.is-nm-app,.sh-player--24six,.sh-sheet--24six{--sh-font:var(--nm-look-font-body);}',
		'font_heading' => 'h1,h2,h3,h4,h5,h6,.entry-title,.entry-title a,.ast-archive-title,.widget-title,.site-title,.site-title a{font-family:var(--nm-look-font-heading);}',
	);
}

/**
 * The five role declarations of a look: "--nm-look-brand:#1b2f52;...".
 */
function ner_michoel_look_role_declarations( $colors ) {
	$out = '';
	foreach ( ner_michoel_look_roles() as $role => $unused ) {
		$out .= '--nm-look-' . $role . ':' . $colors[ $role ] . ';';
	}
	return $out;
}

/**
 * The override stylesheet for a look ('' when it is the site's own).
 */
function ner_michoel_look_css( $look ) {
	$parts = ner_michoel_look_css_parts();
	$css   = '';

	$vars = '';
	if ( '' !== $look['body'] ) {
		$vars .= '--nm-look-font-body:' . ner_michoel_look_font_stack( $look['body'] ) . ';';
	}
	if ( '' !== $look['heading'] ) {
		$vars .= '--nm-look-font-heading:' . ner_michoel_look_font_stack( $look['heading'] ) . ';';
	}
	if ( '' !== $vars ) {
		$css .= ':root{' . $vars . '}';
	}
	if ( '' !== $look['body'] ) {
		$css .= $parts['font_body'];
	}
	if ( '' !== $look['heading'] ) {
		$css .= $parts['font_heading'];
	}
	if ( ner_michoel_look_colors_customized( $look['colors'] ) ) {
		$css .= $parts['supports'] . '{:root{' . ner_michoel_look_role_declarations( $look['colors'] ) . $parts['colors'] . '}' . $parts['dark'] . '}';
	}
	return $css;
}

/* ------------------------------------------------------------------ */
/* On the site                                                         */
/* ------------------------------------------------------------------ */

/**
 * True for the Control Panel's preview frame: ?nm_look_preview=1, administrators only.
 */
function ner_michoel_look_is_preview() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flag, and only an administrator gets anything from it.
	return isset( $_GET['nm_look_preview'] ) && current_user_can( 'manage_options' );
}

/**
 * Prints the look in the page's head, after every stylesheet (priority 99), so
 * it also wins over the saved Appearance options (priority 20).
 */
function ner_michoel_print_site_look() {
	$look    = ner_michoel_get_site_look();
	$preview = ner_michoel_look_is_preview();
	$css     = ner_michoel_look_css( $look );
	$fonts   = ner_michoel_look_fonts_url( $look );

	if ( ! $preview && '' === $css && '' === $fonts ) {
		return;
	}

	if ( '' !== $fonts ) {
		echo '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
		echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
		echo '<link rel="stylesheet" id="ner-michoel-site-look-fonts" href="' . esc_url( $fonts ) . '">' . "\n";
	}
	// The CSS is built only from the validated colors, the catalog's own font names and fixed strings above, never from raw input.
	echo '<style id="ner-michoel-site-look">' . $css . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

	if ( $preview ) {
		ner_michoel_print_look_preview_listener();
	}
}
add_action( 'wp_head', 'ner_michoel_print_site_look', 99 );

/**
 * The preview frame's end of the conversation with the Control Panel: it takes
 * the panel's {css, fonts} (unsaved choices) and swaps them in, and it stops
 * links from leaving the frame.
 */
function ner_michoel_print_look_preview_listener() {
	?>
<script id="ner-michoel-look-preview">
(function () {
	var origin = window.location.origin;
	window.addEventListener('message', function (e) {
		if (e.origin !== origin || e.source !== window.parent || !e.data || e.data.type !== 'nm-look') {
			return;
		}
		var style = document.getElementById('ner-michoel-site-look');
		if (!style) {
			style = document.createElement('style');
			style.id = 'ner-michoel-site-look';
			document.head.appendChild(style);
		}
		style.textContent = String(e.data.css || '');
		var link = document.getElementById('ner-michoel-site-look-fonts');
		if (e.data.fonts) {
			if (!link) {
				link = document.createElement('link');
				link.rel = 'stylesheet';
				link.id = 'ner-michoel-site-look-fonts';
				document.head.appendChild(link);
			}
			if (link.getAttribute('href') !== e.data.fonts) {
				link.setAttribute('href', e.data.fonts);
			}
		} else if (link) {
			link.parentNode.removeChild(link);
		}
	});
	document.addEventListener('click', function (e) {
		if (e.target && e.target.closest && e.target.closest('a')) {
			e.preventDefault();
		}
	}, true);
	try {
		window.parent.postMessage({ type: 'nm-look-ready' }, origin);
	} catch (err) {}
})();
</script>
	<?php
}

/**
 * No toolbar inside the preview frame. WordPress decides this at the start of
 * init, and also adds a top margin for the bar if it says yes, so the answer
 * has to be given that early.
 */
function ner_michoel_look_preview_toolbar( $show ) {
	return ner_michoel_look_is_preview() ? false : $show;
}
add_filter( 'show_admin_bar', 'ner_michoel_look_preview_toolbar' );

/* ------------------------------------------------------------------ */
/* Saving                                                              */
/* ------------------------------------------------------------------ */

/**
 * After a save that changes what visitors see (the look, the homepage's words),
 * asks the page caches this site might have to forget their pages: the look is
 * printed into every page's head, so a cached page would keep the old one. Each
 * call is made only if that cache exists. Whatever else listens for the action
 * can do the same.
 */
function ner_michoel_purge_page_caches() {
	do_action( 'epc_purge' ); // Bluehost / Endurance Page Cache.
	if ( function_exists( 'wp_cache_clear_cache' ) ) {
		wp_cache_clear_cache(); // WP Super Cache.
	}
	if ( function_exists( 'w3tc_flush_all' ) ) {
		w3tc_flush_all(); // W3 Total Cache.
	}
	if ( function_exists( 'rocket_clean_domain' ) ) {
		rocket_clean_domain(); // WP Rocket.
	}
	do_action( 'litespeed_purge_all' ); // LiteSpeed Cache.
	do_action( 'ner_michoel_purge_page_caches' );
}

function ner_michoel_register_site_look_rest_route() {
	register_rest_route(
		'ner-michoel/v1',
		'/site-look-settings',
		array(
			'methods'             => 'POST',
			'callback'            => 'ner_michoel_handle_site_look_rest',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		)
	);
}
add_action( 'rest_api_init', 'ner_michoel_register_site_look_rest_route' );

/**
 * Saves {heading, body, colors: {brand, accent, fresh, soft, ink}}. Unlike the
 * quiet fallbacks in ner_michoel_look_normalize(), a bad value here is an error,
 * so a script posting to the route finds out.
 */
function ner_michoel_handle_site_look_rest( WP_REST_Request $request ) {
	$body = $request->get_json_params();
	if ( ! is_array( $body ) ) {
		$body = $request->get_params();
	}
	$fonts = ner_michoel_look_fonts();

	foreach ( array( 'heading', 'body' ) as $part ) {
		if ( isset( $body[ $part ] ) && '' !== $body[ $part ] && ( ! is_string( $body[ $part ] ) || ! isset( $fonts[ $body[ $part ] ] ) ) ) {
			return new WP_Error( 'nm_look_font', __( 'That font is not in the list.', 'ner-michoel-core' ), array( 'status' => 400 ) );
		}
	}
	if ( isset( $body['colors'] ) ) {
		if ( ! is_array( $body['colors'] ) ) {
			return new WP_Error( 'nm_look_color', __( 'The colors were not sent properly.', 'ner-michoel-core' ), array( 'status' => 400 ) );
		}
		foreach ( ner_michoel_look_roles() as $role => $info ) {
			if ( isset( $body['colors'][ $role ] ) && '' === ner_michoel_look_clean_hex( $body['colors'][ $role ] ) ) {
				/* translators: %s: the name of a color, like "Main color" */
				return new WP_Error( 'nm_look_color', sprintf( __( '%s is not a color. Use six characters like #1b2f52.', 'ner-michoel-core' ), $info['label'] ), array( 'status' => 400 ) );
			}
		}
	}

	$look = ner_michoel_look_normalize( $body );
	update_option( 'nm_site_look', $look );
	ner_michoel_purge_page_caches();

	return new WP_REST_Response(
		array(
			'saved'      => true,
			'look'       => $look,
			'customized' => ner_michoel_look_is_customized( $look ),
		),
		200
	);
}

/* ------------------------------------------------------------------ */
/* For the Control Panel screen                                        */
/* ------------------------------------------------------------------ */

/**
 * What the Colors & Fonts screen shows when it opens.
 */
function ner_michoel_site_look_panel_values() {
	return ner_michoel_get_site_look();
}

/**
 * Everything the screen needs besides the saved look: the lists, the CSS pieces
 * it builds the preview from, and the preview's address.
 */
function ner_michoel_site_look_panel_extra() {
	$fonts = array();
	foreach ( ner_michoel_look_fonts() as $slug => $font ) {
		$partner = ner_michoel_look_font_partner( $slug );
		$fonts[] = array(
			'slug'    => $slug,
			'name'    => $font['name'],
			'cat'     => $font['cat'],
			'hebrew'  => $font['hebrew'],
			'stack'   => ner_michoel_look_font_stack( $slug ),
			'spec'    => ner_michoel_look_font_spec( $slug ),
			'partner' => $partner ? ner_michoel_look_font_spec( $partner ) : '',
		);
	}

	$palettes = array();
	foreach ( ner_michoel_look_palettes() as $palette ) {
		$palettes[] = $palette;
	}

	$roles = array();
	foreach ( ner_michoel_look_roles() as $key => $role ) {
		$roles[] = array(
			'key'   => $key,
			'label' => $role['label'],
			'hint'  => $role['hint'],
		);
	}

	return array(
		'fonts'       => $fonts,
		'categories'  => ner_michoel_look_font_categories(),
		'palettes'    => $palettes,
		'groups'      => ner_michoel_look_palette_groups(),
		'roles'       => $roles,
		'defaults'    => array(
			'colors'  => ner_michoel_look_default_colors(),
			'palette' => 'ner-michoel',
		),
		'css'         => ner_michoel_look_css_parts(),
		'google'      => 'https://fonts.googleapis.com/css2',
		'picker_url'  => ner_michoel_look_picker_fonts_url(),
		'preview_url' => add_query_arg( 'nm_look_preview', '1', home_url( '/' ) ),
	);
}
