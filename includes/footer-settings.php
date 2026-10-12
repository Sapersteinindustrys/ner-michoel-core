<?php
/**
 * Settings behind the Control Panel's Footer screen (Homepage & Look > Footer):
 * what the site footer says and links to. The theme prints the footer
 * (ner-michoel-child, inc/footer.php, assets/css/footer.css); this file keeps the
 * words and links and hands them over, so a redesign of the theme keeps them.
 *
 *   - The title ("Tomo Official site") and the small line at the very bottom.
 *   - A list of links to pages: a name and an address each, in order.
 *   - A Contribute button and an Amazon Smile link, each with its own words and
 *     address, and each can be switched off.
 *   - Whether the footer is on, and where: on the home page only (the default) or on
 *     every page except the Shiurim pages.
 *
 * All of it is kept in one option, nm_footer, which holds only what an administrator
 * has saved. A key that is missing, or a box left empty, means "the site's own": the
 * words in ner_michoel_footer_default_words(), and a list of links to the site's main
 * pages. Until the screen is saved the footer shows those, and the screen opens showing
 * them as things to change.
 *
 * A link to a page of this site is kept as a path ("/about/"), not a full address, and
 * made into a full one when it is shown, so it still works if the site moves to another
 * address (it is a demo on a temporary address for now).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The site's own words: what a box left empty means. {year} and {title} in the small
 * line become this year and the title.
 */
function ner_michoel_footer_default_words() {
	return array(
		'title'            => __( 'Tomo Official site', 'ner-michoel-core' ),
		'note'             => __( '© {year} {title}', 'ner-michoel-core' ),
		'contribute_label' => __( 'Contribute', 'ner-michoel-core' ),
		'contribute_url'   => '/contribute/',
		'smile_label'      => __( 'Amazon Smile', 'ner-michoel-core' ),
		'smile_url'        => 'https://smile.amazon.com/',
	);
}

/**
 * Where the footer can show: key => the words the screen offers.
 */
function ner_michoel_footer_scopes() {
	return array(
		'home' => __( 'The home page only', 'ner-michoel-core' ),
		'all'  => __( 'Every page, except the Shiurim pages', 'ner-michoel-core' ),
	);
}

/**
 * A text cut to a number of characters (not bytes).
 */
function ner_michoel_footer_cut( $text, $max ) {
	$text = (string) $text;
	return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max, 'UTF-8' ) : substr( $text, 0, $max );
}

/* ------------------------------------------------------------------ */
/* Addresses                                                           */
/* ------------------------------------------------------------------ */

/**
 * An address as it is kept: a path for a page of this site ("/about/"), a full
 * address for anything else (https://, mailto: or tel:). '' when it isn't one that
 * can be used. "www.example.org/page" without https:// gets it.
 */
function ner_michoel_footer_store_url( $raw ) {
	$url = trim( (string) $raw );
	if ( '' === $url || preg_match( '/\s/', $url ) ) {
		return '';
	}

	// A link to this site is kept as a path, so it still works if the site's address changes.
	$home  = untrailingslashit( home_url() );
	$bases = array_unique( array( $home, set_url_scheme( $home, 'http' ), set_url_scheme( $home, 'https' ) ) );
	foreach ( $bases as $base ) {
		if ( 0 === strcasecmp( $url, $base ) ) {
			return '/';
		}
		if ( 0 === stripos( $url, $base . '/' ) || 0 === stripos( $url, $base . '?' ) || 0 === stripos( $url, $base . '#' ) ) {
			$url = substr( $url, strlen( $base ) );
			if ( '/' !== $url[0] ) {
				$url = '/' . $url;
			}
			break;
		}
	}

	if ( 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ) {
		return esc_url_raw( $url ); // A path is left as it is; anything not allowed in one is removed.
	}
	if ( preg_match( '#^(mailto|tel):#i', $url ) ) {
		return esc_url_raw( $url, array( 'mailto', 'tel' ) );
	}
	if ( ! preg_match( '#^https?://#i', $url ) ) {
		if ( ! preg_match( '#^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+(/|\?|\#|$)#i', $url ) ) {
			return '';
		}
		$url = 'https://' . $url;
	}
	$clean = esc_url_raw( $url, array( 'http', 'https' ) );
	return ( '' !== $clean && wp_parse_url( $clean, PHP_URL_HOST ) ) ? $clean : '';
}

/**
 * A kept address as a full one, ready for a link.
 */
function ner_michoel_footer_resolve_url( $stored ) {
	$stored = (string) $stored;
	if ( 0 === strpos( $stored, '/' ) && 0 !== strpos( $stored, '//' ) ) {
		return home_url( $stored );
	}
	return $stored;
}

/**
 * Whether a full address leaves this site (it then opens in a new tab). mailto: and
 * tel: have no host, so they don't count.
 */
function ner_michoel_footer_is_external( $url ) {
	$host = wp_parse_url( (string) $url, PHP_URL_HOST );
	if ( ! $host ) {
		return false;
	}
	$plain = function ( $name ) {
		return preg_replace( '/^www\./', '', strtolower( (string) $name ) );
	};
	return $plain( $host ) !== $plain( wp_parse_url( home_url(), PHP_URL_HOST ) );
}

/* ------------------------------------------------------------------ */
/* What is saved                                                       */
/* ------------------------------------------------------------------ */

/**
 * What an administrator has saved, with anything of the wrong kind left out. Keys that
 * are missing mean "the site's own".
 */
function ner_michoel_footer_saved() {
	$raw = get_option( 'nm_footer', array() );
	if ( ! is_array( $raw ) ) {
		return array();
	}

	$saved = array();
	if ( isset( $raw['on'] ) ) {
		$saved['on'] = (bool) $raw['on'];
	}
	if ( isset( $raw['scope'] ) && is_string( $raw['scope'] ) && isset( ner_michoel_footer_scopes()[ $raw['scope'] ] ) ) {
		$saved['scope'] = $raw['scope'];
	}
	foreach ( array( 'title', 'note' ) as $key ) {
		if ( isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) && '' !== trim( $raw[ $key ] ) ) {
			$saved[ $key ] = $raw[ $key ];
		}
	}
	if ( isset( $raw['links'] ) && is_array( $raw['links'] ) ) {
		$saved['links'] = array(); // A saved list, even an empty one, replaces the site's own.
		foreach ( $raw['links'] as $link ) {
			if ( is_array( $link ) && isset( $link['label'], $link['url'] ) && is_string( $link['label'] ) && is_string( $link['url'] ) && '' !== $link['label'] && '' !== $link['url'] ) {
				$saved['links'][] = array(
					'label' => $link['label'],
					'url'   => $link['url'],
				);
			}
		}
	}
	foreach ( array( 'contribute', 'smile' ) as $key ) {
		if ( isset( $raw[ $key ] ) && is_array( $raw[ $key ] ) ) {
			$part = array();
			if ( isset( $raw[ $key ]['on'] ) ) {
				$part['on'] = (bool) $raw[ $key ]['on'];
			}
			foreach ( array( 'label', 'url' ) as $field ) {
				if ( isset( $raw[ $key ][ $field ] ) && is_string( $raw[ $key ][ $field ] ) && '' !== trim( $raw[ $key ][ $field ] ) ) {
					$part[ $field ] = $raw[ $key ][ $field ];
				}
			}
			$saved[ $key ] = $part;
		}
	}
	return $saved;
}

/**
 * The links the footer has until a list is saved: the pages in the site's header menu
 * (Contribute has its own button). Each is looked up without a database query, so a
 * site that never opens the screen pays nothing for them.
 */
function ner_michoel_footer_default_links() {
	$pairs = array( array( __( 'Home', 'ner-michoel-core' ), home_url( '/' ) ) );

	$archives = array(
		'shiur'         => __( 'Shiurim', 'ner-michoel-core' ),
		'written_shiur' => __( 'Written Shiurim', 'ner-michoel-core' ),
		'gallery'       => __( 'Galleries', 'ner-michoel-core' ),
	);
	foreach ( $archives as $type => $label ) {
		$url = post_type_exists( $type ) ? get_post_type_archive_link( $type ) : '';
		if ( $url ) {
			$pairs[] = array( $label, $url );
		}
	}

	$pairs[] = array( __( 'About', 'ner-michoel-core' ), home_url( '/about/' ) );
	$pairs[] = array( __( 'Contact', 'ner-michoel-core' ), home_url( '/contact/' ) );

	$links = array();
	foreach ( $pairs as $pair ) {
		$url = ner_michoel_footer_store_url( $pair[1] );
		if ( '' !== $url ) {
			$links[] = array(
				'label' => $pair[0],
				'url'   => $url,
			);
		}
	}
	return $links;
}

/**
 * The footer as the theme shows it, every address made full:
 *
 *   on         bool    false: print nothing (Astra's own footer stays)
 *   scope      string  'home' or 'all'
 *   title      string
 *   links      list of {label, url, external}
 *   contribute {label, url, external}, or null when switched off
 *   smile      {label, url, external}, or null when switched off
 *   note       string  the small line, {year} and {title} filled in
 */
function ner_michoel_get_footer() {
	$saved = ner_michoel_footer_saved();
	$words = ner_michoel_footer_default_words();

	$title = isset( $saved['title'] ) ? $saved['title'] : $words['title'];
	$note  = isset( $saved['note'] ) ? $saved['note'] : $words['note'];
	$note  = trim( str_replace( array( '{year}', '{title}' ), array( wp_date( 'Y' ), $title ), $note ) );

	$links = array();
	foreach ( isset( $saved['links'] ) ? $saved['links'] : ner_michoel_footer_default_links() as $link ) {
		$url     = ner_michoel_footer_resolve_url( $link['url'] );
		$links[] = array(
			'label'    => $link['label'],
			'url'      => $url,
			'external' => ner_michoel_footer_is_external( $url ),
		);
	}

	$buttons = array();
	foreach ( array( 'contribute', 'smile' ) as $key ) {
		$part = isset( $saved[ $key ] ) ? $saved[ $key ] : array();
		if ( isset( $part['on'] ) && ! $part['on'] ) {
			$buttons[ $key ] = null;
			continue;
		}
		$url             = ner_michoel_footer_resolve_url( isset( $part['url'] ) ? $part['url'] : $words[ $key . '_url' ] );
		$buttons[ $key ] = array(
			'label'    => isset( $part['label'] ) ? $part['label'] : $words[ $key . '_label' ],
			'url'      => $url,
			'external' => ner_michoel_footer_is_external( $url ),
		);
	}

	return array(
		'on'         => isset( $saved['on'] ) ? $saved['on'] : true,
		'scope'      => isset( $saved['scope'] ) ? $saved['scope'] : 'home',
		'title'      => $title,
		'links'      => $links,
		'contribute' => $buttons['contribute'],
		'smile'      => $buttons['smile'],
		'note'       => $note,
	);
}

/* ------------------------------------------------------------------ */
/* The screen                                                          */
/* ------------------------------------------------------------------ */

/**
 * What the Footer screen shows when it opens: what is saved, or empty boxes (with the
 * site's own words as their hint) where nothing is. The links are the site's own list
 * until one is saved, so what is on the site is what can be changed.
 */
function ner_michoel_footer_settings_values() {
	$saved = ner_michoel_footer_saved();

	$links = array();
	foreach ( isset( $saved['links'] ) ? $saved['links'] : ner_michoel_footer_default_links() as $link ) {
		$links[] = array(
			'label'    => $link['label'],
			'link_url' => ner_michoel_footer_resolve_url( $link['url'] ),
		);
	}

	$values = array(
		'on'    => isset( $saved['on'] ) ? $saved['on'] : true,
		'scope' => isset( $saved['scope'] ) ? $saved['scope'] : 'home',
		'title' => isset( $saved['title'] ) ? $saved['title'] : '',
		'note'  => isset( $saved['note'] ) ? $saved['note'] : '',
		'links' => $links,
	);
	foreach ( array( 'contribute', 'smile' ) as $key ) {
		$part                      = isset( $saved[ $key ] ) ? $saved[ $key ] : array();
		$values[ $key . '_on' ]    = isset( $part['on'] ) ? $part['on'] : true;
		$values[ $key . '_label' ] = isset( $part['label'] ) ? $part['label'] : '';
		$values[ $key . '_url' ]   = isset( $part['url'] ) ? ner_michoel_footer_resolve_url( $part['url'] ) : '';
	}
	return $values;
}

function ner_michoel_register_footer_settings_rest_route() {
	register_rest_route(
		'ner-michoel/v1',
		'/footer-settings',
		array(
			'methods'             => 'POST',
			'callback'            => 'ner_michoel_handle_footer_settings_rest',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		)
	);
}
add_action( 'rest_api_init', 'ner_michoel_register_footer_settings_rest_route' );

/**
 * Saves {on, scope, title, note, links: [{label, link_url}], contribute_on,
 * contribute_label, contribute_url, smile_on, smile_label, smile_url}. A key that is
 * not sent is left as it was. An empty box goes back to the site's own words. Unlike
 * the quiet fallbacks above, a link that cannot work is an error, so nothing is
 * dropped without the person saving it hearing about it.
 */
function ner_michoel_handle_footer_settings_rest( WP_REST_Request $request ) {
	$body = $request->get_json_params();
	if ( ! is_array( $body ) ) {
		$body = $request->get_params();
	}
	$data = ner_michoel_footer_saved();

	if ( array_key_exists( 'on', $body ) ) {
		$data['on'] = rest_sanitize_boolean( $body['on'] );
	}

	if ( isset( $body['scope'] ) ) {
		if ( ! is_string( $body['scope'] ) || ! isset( ner_michoel_footer_scopes()[ $body['scope'] ] ) ) {
			return new WP_Error( 'nm_footer_scope', __( 'Choose where the footer shows from the list.', 'ner-michoel-core' ), array( 'status' => 400 ) );
		}
		$data['scope'] = $body['scope'];
	}

	foreach ( array( 'title' => 80, 'note' => 200 ) as $key => $max ) {
		if ( array_key_exists( $key, $body ) ) {
			$value = is_string( $body[ $key ] ) ? trim( ner_michoel_footer_cut( sanitize_text_field( $body[ $key ] ), $max ) ) : '';
			if ( '' === $value ) {
				unset( $data[ $key ] );
			} else {
				$data[ $key ] = $value;
			}
		}
	}

	if ( isset( $body['links'] ) ) {
		if ( ! is_array( $body['links'] ) ) {
			return new WP_Error( 'nm_footer_links', __( 'The links were not sent properly.', 'ner-michoel-core' ), array( 'status' => 400 ) );
		}
		$links = array();
		foreach ( $body['links'] as $row ) {
			$label = ( is_array( $row ) && isset( $row['label'] ) && is_string( $row['label'] ) ) ? trim( ner_michoel_footer_cut( sanitize_text_field( $row['label'] ), 60 ) ) : '';
			$raw   = '';
			if ( is_array( $row ) ) {
				foreach ( array( 'link_url', 'url' ) as $field ) {
					if ( '' === $raw && isset( $row[ $field ] ) && is_string( $row[ $field ] ) ) {
						$raw = trim( $row[ $field ] );
					}
				}
			}
			if ( '' === $label && '' === $raw ) {
				continue; // A row that was added and left empty.
			}
			if ( '' === $label ) {
				/* translators: %s: a web address */
				return new WP_Error( 'nm_footer_link_name', sprintf( __( 'The link to %s needs a name.', 'ner-michoel-core' ), $raw ), array( 'status' => 400 ) );
			}
			$url = ner_michoel_footer_store_url( $raw );
			if ( '' === $url ) {
				/* translators: %s: the name of a link, like "About" */
				return new WP_Error( 'nm_footer_link_url', sprintf( __( 'The link “%s” needs a web address that works, like https://example.org or /about/.', 'ner-michoel-core' ), $label ), array( 'status' => 400 ) );
			}
			$links[] = array(
				'label' => $label,
				'url'   => $url,
			);
		}
		if ( count( $links ) > 40 ) {
			return new WP_Error( 'nm_footer_links_many', __( 'The footer can have up to 40 links.', 'ner-michoel-core' ), array( 'status' => 400 ) );
		}
		$data['links'] = $links;
	}

	foreach ( array( 'contribute', 'smile' ) as $key ) {
		$part = ( isset( $data[ $key ] ) && is_array( $data[ $key ] ) ) ? $data[ $key ] : array();

		if ( array_key_exists( $key . '_on', $body ) ) {
			$part['on'] = rest_sanitize_boolean( $body[ $key . '_on' ] );
		}
		if ( array_key_exists( $key . '_label', $body ) ) {
			$value = is_string( $body[ $key . '_label' ] ) ? trim( ner_michoel_footer_cut( sanitize_text_field( $body[ $key . '_label' ] ), 40 ) ) : '';
			if ( '' === $value ) {
				unset( $part['label'] );
			} else {
				$part['label'] = $value;
			}
		}
		if ( array_key_exists( $key . '_url', $body ) ) {
			$raw = is_string( $body[ $key . '_url' ] ) ? trim( $body[ $key . '_url' ] ) : '';
			if ( '' === $raw ) {
				unset( $part['url'] );
			} else {
				$url = ner_michoel_footer_store_url( $raw );
				if ( '' === $url ) {
					$name = 'contribute' === $key ? __( 'The Contribute button', 'ner-michoel-core' ) : __( 'The Amazon Smile link', 'ner-michoel-core' );
					/* translators: %s: the name of a button or link */
					return new WP_Error( 'nm_footer_' . $key . '_url', sprintf( __( '%s needs a web address that works, like https://example.org or /contribute/. Leave the box empty to use the site’s own.', 'ner-michoel-core' ), $name ), array( 'status' => 400 ) );
				}
				$part['url'] = $url;
			}
		}

		if ( $part ) {
			$data[ $key ] = $part;
		} else {
			unset( $data[ $key ] );
		}
	}

	update_option( 'nm_footer', $data );

	if ( function_exists( 'ner_michoel_purge_page_caches' ) ) {
		ner_michoel_purge_page_caches();
	}

	return new WP_REST_Response( array( 'saved' => true ), 200 );
}
