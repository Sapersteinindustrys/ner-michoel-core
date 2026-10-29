<?php
/**
 * Data for the series picker (assets/series-picker.js): when each term was last
 * used by a published post. The picker lists the three most recent at the top
 * under "Recent", and offers the rest as drop-downs, one per level, with a search
 * over every level (see series-tree.php for the tree itself).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Map of term ID => the newest publish date of a post in that term, as a
 * 'Y-m-d H:i:s' string. $post_type matters: a series can be used by both
 * shiur and written_shiur, and "recent" means recent *for the form you're
 * on* — editing a written shiur should surface series recently used by
 * written shiurim, not by audio. Terms with no matching published post are
 * left out.
 */
function ner_michoel_term_latest_dates( $taxonomy, $post_type = 'shiur' ) {
	global $wpdb;

	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->prepare(
			"SELECT tt.term_id, MAX( p.post_date ) AS last_date
			FROM {$wpdb->term_relationships} tr
			INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
			WHERE tt.taxonomy = %s AND p.post_type = %s AND p.post_status = 'publish'
			GROUP BY tt.term_id",
			$taxonomy,
			$post_type
		)
	);

	$dates = array();
	foreach ( (array) $rows as $row ) {
		$dates[ (int) $row->term_id ] = $row->last_date;
	}

	return $dates;
}

/**
 * The <option> list for a series picker drawn by PHP: every series, parents first, each saying its whole name
 * (its text), its own name (data-name), the series it sits inside (data-parent) and when it was last used
 * (data-last). Put an empty "None" option in front of it yourself.
 *
 * @param int    $selected  The series to mark chosen.
 * @param string $post_type Post type "Recent" is judged by.
 * @return string Escaped HTML.
 */
function ner_michoel_series_picker_options_html( $selected = 0, $post_type = 'shiur' ) {
	$html = '';
	foreach ( ner_michoel_series_rows( $post_type ) as $row ) {
		$html .= sprintf(
			'<option value="%1$d" data-name="%2$s" data-parent="%3$d"%4$s%5$s>%6$s</option>',
			(int) $row['id'],
			esc_attr( $row['name'] ),
			(int) $row['parent'],
			'' !== $row['last'] ? ' data-last="' . esc_attr( $row['last'] ) . '"' : '',
			selected( (int) $selected, (int) $row['id'], false ),
			esc_html( $row['path'] )
		);
	}
	return $html;
}

/**
 * The attributes that let a picker add a series or a sub-series (the route and the REST nonce), as one
 * escaped string for a <select data-series-picker>.
 */
function ner_michoel_series_picker_add_attrs() {
	return ' data-add-url="' . esc_url( rest_url( 'ner-michoel/v1/series/add' ) ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'wp_rest' ) ) . '"';
}
