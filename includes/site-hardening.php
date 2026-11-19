<?php
/**
 * Site hardening and search-engine tags, done in-house so the site does not
 * lean on extra plugins for them.
 *
 * 1. Security headers on every page, the dashboard and the login page:
 *    no MIME sniffing, no framing by other sites (clickjacking), a strict
 *    referrer policy, camera / microphone / location switched off, and
 *    HSTS once the site is on HTTPS. The Content-Security-Policy here is the
 *    safe minimum (frame-ancestors, object-src, base-uri): it blocks framing,
 *    plugins and <base> hijacks without touching the theme's own scripts.
 * 2. The theme and plugin code editors in the dashboard are switched off
 *    (DISALLOW_FILE_EDIT). Updates still work; nobody who steals an admin
 *    password can paste code into a PHP file from the browser.
 * 3. Search-engine tags (description, Open Graph, Twitter card, a simple
 *    Organization schema on the homepage, noindex on search results), so
 *    Yoast SEO can be removed. While Yoast is active this part stays off, so
 *    nothing is printed twice. It reuses any description or title typed into
 *    Yoast's box, so nothing is lost when Yoast goes.
 *
 * Every piece can be turned off with a filter:
 *   ner_michoel_security_headers, ner_michoel_seo_tags (return false).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'DISALLOW_FILE_EDIT' ) ) {
	define( 'DISALLOW_FILE_EDIT', true );
}

/* ------------------------------------------------------------------
 * 1. Security headers.
 * ------------------------------------------------------------------ */

function ner_michoel_security_header_list() {
	$headers = array(
		'X-Content-Type-Options'  => 'nosniff',
		'X-Frame-Options'         => 'SAMEORIGIN',
		'Referrer-Policy'         => 'strict-origin-when-cross-origin',
		'Permissions-Policy'      => 'camera=(), microphone=(), geolocation=(), usb=(), interest-cohort=()',
		'Content-Security-Policy' => "frame-ancestors 'self'; object-src 'none'; base-uri 'self'",
	);
	if ( is_ssl() ) {
		$headers['Strict-Transport-Security'] = 'max-age=31536000';
	}
	return (array) apply_filters( 'ner_michoel_security_header_list', $headers );
}

function ner_michoel_send_security_headers() {
	if ( headers_sent() || ! apply_filters( 'ner_michoel_security_headers', true ) ) {
		return;
	}
	foreach ( ner_michoel_security_header_list() as $name => $value ) {
		header( $name . ': ' . $value );
	}
}
add_action( 'send_headers', 'ner_michoel_send_security_headers' );
add_action( 'admin_init', 'ner_michoel_send_security_headers' );
add_action( 'login_init', 'ner_michoel_send_security_headers' );

function ner_michoel_rest_security_headers( $response ) {
	if ( $response instanceof WP_HTTP_Response && apply_filters( 'ner_michoel_security_headers', true ) ) {
		$response->header( 'X-Content-Type-Options', 'nosniff' );
	}
	return $response;
}
add_filter( 'rest_post_dispatch', 'ner_michoel_rest_security_headers' );

/* ------------------------------------------------------------------
 * 3. Search-engine tags (only while Yoast SEO is not active).
 * ------------------------------------------------------------------ */

function ner_michoel_seo_tags_on() {
	if ( defined( 'WPSEO_VERSION' ) ) {
		return false; // Yoast is still installed and active: let it do this.
	}
	return (bool) apply_filters( 'ner_michoel_seo_tags', true );
}

/** A clean one-line description, at most 160 characters. */
function ner_michoel_seo_clean( $text ) {
	$text = wp_strip_all_tags( strip_shortcodes( (string) $text ), true );
	$text = trim( preg_replace( '/\s+/u', ' ', $text ) );
	if ( function_exists( 'mb_strlen' ) && mb_strlen( $text ) > 160 ) {
		$text = rtrim( mb_substr( $text, 0, 157 ) ) . '…';
	}
	return $text;
}

/** A value typed into Yoast's box, unless it holds Yoast's %%variables%%. */
function ner_michoel_seo_yoast_meta( $post_id, $key ) {
	$value = (string) get_post_meta( $post_id, '_yoast_wpseo_' . $key, true );
	return ( '' === $value || false !== strpos( $value, '%%' ) ) ? '' : $value;
}

function ner_michoel_seo_description() {
	$text = '';
	if ( is_singular() ) {
		$post = get_queried_object();
		if ( $post instanceof WP_Post ) {
			$text = ner_michoel_seo_yoast_meta( $post->ID, 'metadesc' );
			if ( '' === $text ) {
				$text = has_excerpt( $post ) ? $post->post_excerpt : wp_trim_words( $post->post_content, 40, '' );
			}
		}
	} elseif ( is_category() || is_tag() || is_tax() ) {
		$text = term_description();
	}
	if ( '' === trim( wp_strip_all_tags( (string) $text ) ) ) {
		$text = get_bloginfo( 'description' );
	}
	return ner_michoel_seo_clean( $text );
}

function ner_michoel_seo_image() {
	if ( is_singular() && has_post_thumbnail() ) {
		$src = wp_get_attachment_image_src( get_post_thumbnail_id(), 'large' );
		if ( $src ) {
			return $src[0];
		}
	}
	$logo = (int) get_theme_mod( 'custom_logo' );
	if ( $logo ) {
		$src = wp_get_attachment_image_src( $logo, 'full' );
		if ( $src ) {
			return $src[0];
		}
	}
	return get_site_icon_url( 512 );
}

function ner_michoel_seo_url() {
	if ( is_front_page() ) {
		return home_url( '/' );
	}
	if ( is_singular() ) {
		return get_permalink();
	}
	if ( is_category() || is_tag() || is_tax() ) {
		$link = get_term_link( get_queried_object() );
		return is_wp_error( $link ) ? '' : $link;
	}
	if ( is_post_type_archive() ) {
		return (string) get_post_type_archive_link( get_query_var( 'post_type' ) );
	}
	return '';
}

/* A plain (no %%variables%%) SEO title typed into Yoast's box keeps working. */
function ner_michoel_seo_document_title( $title ) {
	if ( ner_michoel_seo_tags_on() && is_singular() ) {
		$custom = ner_michoel_seo_yoast_meta( get_queried_object_id(), 'title' );
		if ( '' !== $custom ) {
			return $custom;
		}
	}
	return $title;
}
add_filter( 'pre_get_document_title', 'ner_michoel_seo_document_title', 20 );

/* Search results and "nothing found" pages are not worth indexing. */
function ner_michoel_seo_robots( $robots ) {
	if ( ner_michoel_seo_tags_on() && ( is_search() || is_404() ) ) {
		$robots['noindex'] = true;
		$robots['follow']  = true;
	}
	return $robots;
}
add_filter( 'wp_robots', 'ner_michoel_seo_robots' );

function ner_michoel_seo_print_tags() {
	if ( ! ner_michoel_seo_tags_on() || is_admin() ) {
		return;
	}
	$site  = get_bloginfo( 'name' );
	$desc  = ner_michoel_seo_description();
	$url   = ner_michoel_seo_url();
	$image = ner_michoel_seo_image();
	$title = wp_get_document_title();

	echo "\n<!-- Ner Michoel search tags -->\n";
	if ( '' !== $desc ) {
		printf( "<meta name=\"description\" content=\"%s\" />\n", esc_attr( $desc ) );
	}
	if ( is_front_page() && ! is_singular() ) {
		// WordPress prints rel=canonical for single pages only; add the homepage's.
		printf( "<link rel=\"canonical\" href=\"%s\" />\n", esc_url( home_url( '/' ) ) );
	}
	$og = array(
		'og:locale'      => str_replace( '-', '_', get_bloginfo( 'language' ) ),
		'og:type'        => ( is_singular() && ! is_front_page() ) ? 'article' : 'website',
		'og:title'       => $title,
		'og:description' => $desc,
		'og:url'         => $url,
		'og:site_name'   => $site,
		'og:image'       => $image,
	);
	foreach ( $og as $property => $content ) {
		if ( '' !== (string) $content ) {
			printf( "<meta property=\"%s\" content=\"%s\" />\n", esc_attr( $property ), 'og:url' === $property || 'og:image' === $property ? esc_url( $content ) : esc_attr( $content ) );
		}
	}
	printf( "<meta name=\"twitter:card\" content=\"%s\" />\n", $image ? 'summary_large_image' : 'summary' );

	if ( is_front_page() ) {
		$org = array(
			'@context' => 'https://schema.org',
			'@type'    => 'EducationalOrganization',
			'name'     => $site,
			'url'      => home_url( '/' ),
		);
		if ( $image ) {
			$org['logo'] = $image;
		}
		echo '<script type="application/ld+json">' . wp_json_encode( $org, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG ) . "</script>\n";
	}
	echo "<!-- / Ner Michoel search tags -->\n";
}
add_action( 'wp_head', 'ner_michoel_seo_print_tags', 1 );
