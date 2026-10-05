<?php
/**
 * Data for the series picker (assets/series-picker.js): when each term was last
 * used by a published post. The picker lists the three most recent at the top
 * under "Recent", then the rest.
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
