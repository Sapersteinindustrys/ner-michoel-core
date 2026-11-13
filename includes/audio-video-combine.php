<?php
/**
 * One shiur, one post: its audio and its video together (R' Ariel Katz, 2026-11-06).
 *
 * The old site kept a shiur's audio and its video as two items, and the import
 * brought them in as two posts (the video in "Shiurim Videos" with a Vimeo link),
 * joined only by the Listen / Watch switch (shiur-formats.php). New shiurim are
 * made the way the Add Shiur form makes them: an audio file and a Vimeo link on
 * one post. This file joins the pairs already on the site, from a button on
 * Control Panel > Advanced > Library Import ("Audio and video as one shiur"):
 *
 * - a pair is an audio shiur (an audio file, no Vimeo link) and a video shiur
 *   (a Vimeo link, no file) that the format matching says are the same shiur
 *   (same title and speaker);
 * - the audio post gets the video's Vimeo link, and the video post is set to
 *   Draft (not deleted), marked with the post it went into. Its old address
 *   sends visitors on to the audio post;
 * - Undo puts every pair back as it was.
 *
 * A video that is a file on the site (not Vimeo) can't share a post with an
 * audio file (a shiur holds one file), so those pairs stay as two and are counted.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NER_MICHOEL_AV_COMBINE_LOG', 'nm_av_combine_log' );

/**
 * The pairs that can be joined now: array( array( 'audio' => id, 'video' => id ) ),
 * and in $files how many pairs have a video file (which stay as two).
 */
function ner_michoel_av_combine_pairs( &$files = 0 ) {
	$files = 0;
	$all   = get_option( defined( 'NER_MICHOEL_SHIUR_FORMATS_OPTION' ) ? NER_MICHOEL_SHIUR_FORMATS_OPTION : 'nm_shiur_formats', array() );
	if ( ! is_array( $all ) ) {
		return array();
	}
	$pairs = array();
	$seen  = array();
	foreach ( $all as $formats ) {
		if ( empty( $formats['a'] ) || empty( $formats['v'] ) ) {
			continue;
		}
		$audio = (int) $formats['a'];
		$video = (int) $formats['v'];
		if ( $audio === $video || isset( $seen[ $audio . ':' . $video ] ) ) {
			continue;
		}
		$seen[ $audio . ':' . $video ] = true;
		if ( 'shiur' !== get_post_type( $audio ) || 'shiur' !== get_post_type( $video ) || 'publish' !== get_post_status( $audio ) || 'publish' !== get_post_status( $video ) ) {
			continue;
		}
		// The audio post: an audio file and no video yet.
		if ( 'audio' !== ner_michoel_get_shiur_media_type( $audio ) || '' !== ner_michoel_get_shiur_vimeo_id( $audio ) || ! get_post_meta( $audio, '_shiur_audio_id', true ) ) {
			continue;
		}
		$type = ner_michoel_get_shiur_media_type( $video );
		if ( 'video' === $type ) {
			++$files;
			continue;
		}
		if ( 'video-embed' !== $type || get_post_meta( $video, '_shiur_audio_id', true ) ) {
			continue;
		}
		$pairs[] = array(
			'audio' => $audio,
			'video' => $video,
		);
	}
	return $pairs;
}

/** Joins every pair. Returns how many were joined. */
function ner_michoel_av_combine_run() {
	$pairs = ner_michoel_av_combine_pairs();
	$log   = get_option( NER_MICHOEL_AV_COMBINE_LOG );
	$log   = is_array( $log ) && isset( $log['pairs'] ) ? $log : array( 'pairs' => array() );
	$done  = 0;
	foreach ( $pairs as $pair ) {
		$vimeo = ner_michoel_get_shiur_vimeo_id( $pair['video'] );
		if ( '' === $vimeo ) {
			continue;
		}
		update_post_meta( $pair['audio'], '_shiur_vimeo_id', $vimeo );
		update_post_meta( $pair['video'], '_nm_av_combined_into', $pair['audio'] );
		wp_update_post(
			array(
				'ID'          => $pair['video'],
				'post_status' => 'draft',
			)
		);
		$log['pairs'][] = array( $pair['audio'], $pair['video'], $vimeo );
		++$done;
	}
	if ( $done ) {
		$log['time'] = time();
		update_option( NER_MICHOEL_AV_COMBINE_LOG, $log, false );
		if ( function_exists( 'ner_michoel_shiur_index_rebuild' ) ) {
			ner_michoel_shiur_index_rebuild();
		}
	}
	return $done;
}

/** Puts every joined pair back: the video post published again, the audio post without the link. */
function ner_michoel_av_combine_undo() {
	$log = get_option( NER_MICHOEL_AV_COMBINE_LOG );
	if ( ! is_array( $log ) || empty( $log['pairs'] ) ) {
		return 0;
	}
	$back = 0;
	foreach ( $log['pairs'] as $pair ) {
		list( $audio, $video, $vimeo ) = $pair;
		if ( ner_michoel_get_shiur_vimeo_id( $audio ) === $vimeo ) {
			delete_post_meta( $audio, '_shiur_vimeo_id' );
		}
		if ( (int) get_post_meta( $video, '_nm_av_combined_into', true ) === (int) $audio ) {
			delete_post_meta( $video, '_nm_av_combined_into' );
			if ( 'draft' === get_post_status( $video ) ) {
				wp_update_post(
					array(
						'ID'          => $video,
						'post_status' => 'publish',
					)
				);
			}
		}
		++$back;
	}
	delete_option( NER_MICHOEL_AV_COMBINE_LOG );
	if ( function_exists( 'ner_michoel_shiur_index_rebuild' ) ) {
		ner_michoel_shiur_index_rebuild();
	}
	return $back;
}

/**
 * The old address of a video that was joined into its audio sends the visitor to
 * the audio post's page, at its video.
 */
function ner_michoel_av_combine_redirect() {
	if ( ! is_404() ) {
		return;
	}
	$slug = get_query_var( 'shiur' );
	if ( ! $slug ) {
		$slug = get_query_var( 'alumni_shiur' );
	}
	if ( ! $slug ) {
		$slug = get_query_var( 'name' );
	}
	if ( ! is_string( $slug ) || '' === $slug ) {
		return;
	}
	$found = get_posts(
		array(
			'post_type'      => array( 'shiur', 'alumni_shiur' ), // an alumni rule may have moved it
			'name'           => sanitize_title( $slug ),
			'post_status'    => 'draft',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_key'       => '_nm_av_combined_into', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		)
	);
	if ( ! $found ) {
		return;
	}
	$into = (int) get_post_meta( $found[0], '_nm_av_combined_into', true );
	if ( $into && 'publish' === get_post_status( $into ) ) {
		wp_safe_redirect( get_permalink( $into ) . '#nm-watch', 301 );
		exit;
	}
}
add_action( 'template_redirect', 'ner_michoel_av_combine_redirect', 5 );

/** The part of the Library Import tools card. $hidden prints a tool form's hidden fields. */
function ner_michoel_render_av_combine_section( $form_url, $hidden ) {
	$files = 0;
	$pairs = ner_michoel_av_combine_pairs( $files );
	$log   = get_option( NER_MICHOEL_AV_COMBINE_LOG );
	?>
	<h2 style="margin-top:28px;"><?php esc_html_e( 'Audio and video as one shiur', 'ner-michoel-core' ); ?></h2>
	<p><?php esc_html_e( 'The old site kept a shiur\'s audio and its Vimeo video as two items, so they came in as two shiurim. This puts the video\'s Vimeo link on the audio shiur, so one shiur has Listen and Watch, like a shiur made with Add Shiur. The video shiur is set to Draft (not deleted) and its address goes to the audio shiur. A video that is a file here (not Vimeo) stays a shiur of its own.', 'ner-michoel-core' ); ?></p>
	<?php if ( $pairs ) : ?>
		<form method="post" action="<?php echo esc_url( $form_url ); ?>">
			<?php $hidden( 'combine_av' ); ?>
			<button type="submit" class="button"><?php echo esc_html( sprintf( /* translators: %d: pairs */ _n( 'Join %d audio and video', 'Join %d audios and their videos', count( $pairs ), 'ner-michoel-core' ), count( $pairs ) ) ); ?></button>
		</form>
	<?php else : ?>
		<p class="description"><?php esc_html_e( 'No audio and Vimeo video are waiting to be joined.', 'ner-michoel-core' ); ?></p>
	<?php endif; ?>
	<?php if ( $files ) : ?>
		<p class="description"><?php echo esc_html( sprintf( /* translators: %d: pairs */ __( '%d shiurim have a video file (not Vimeo) next to their audio; they stay as two.', 'ner-michoel-core' ), $files ) ); ?></p>
	<?php endif; ?>
	<?php if ( is_array( $log ) && ! empty( $log['pairs'] ) ) : ?>
		<form method="post" action="<?php echo esc_url( $form_url ); ?>" style="margin-top:10px;">
			<?php $hidden( 'undo_av' ); ?>
			<span class="description"><?php echo esc_html( sprintf( /* translators: 1: pairs, 2: date */ __( '%1$d were joined, last on %2$s.', 'ner-michoel-core' ), count( $log['pairs'] ), wp_date( 'j M Y, H:i', (int) $log['time'] ) ) ); ?></span>
			<button type="submit" class="button button-link"><?php esc_html_e( 'Undo', 'ner-michoel-core' ); ?></button>
		</form>
	<?php endif; ?>
	<?php
}
