<?php
/**
 * Scans posts, stores scores, builds the refresh queue.
 *
 * @package DecayAuditor
 */

namespace DecayAuditor;

defined( 'ABSPATH' ) || exit;

/**
 * Scanner.
 */
class Scanner {

	const META_SCORE    = '_cdaudit_score';
	const META_REASONS  = '_cdaudit_reasons';
	const META_REVIEWED = '_cdaudit_reviewed';
	const META_SCANNED  = '_cdaudit_scanned';

	/**
	 * Post types to audit.
	 *
	 * @return string[]
	 */
	public static function post_types() {
		/**
		 * Filters the post types that are audited.
		 *
		 * @param string[] $types Default post and page.
		 */
		$types = apply_filters( 'cdaudit_post_types', array( 'post', 'page' ) );
		return array_values( array_filter( array_map( 'sanitize_key', (array) $types ) ) );
	}

	/**
	 * Minimum score that puts a post in the queue (1-100).
	 *
	 * @return int
	 */
	public static function threshold() {
		return max( 1, min( 100, (int) get_option( 'cdaudit_threshold', 40 ) ) );
	}

	/**
	 * Word count (whitespace-separated; undercounts CJK text).
	 *
	 * @param string $content Post content.
	 * @return int
	 */
	public static function word_count( $content ) {
		$text = trim( wp_strip_all_tags( strip_shortcodes( (string) $content ) ) );
		return '' === $text ? 0 : count( (array) preg_split( '/\s+/u', $text ) );
	}

	/**
	 * Score one post and store the result in post meta.
	 *
	 * @param \WP_Post $post Post.
	 * @return array{score:int,reasons:string[]}
	 */
	public static function score_post( \WP_Post $post ) {
		$now      = time();
		$modified = (int) strtotime( $post->post_modified_gmt . ' UTC' );
		$reviewed = (int) get_post_meta( $post->ID, self::META_REVIEWED, true );
		$created  = (int) strtotime( $post->post_date_gmt . ' UTC' );

		$result = Scorer::score(
			(int) floor( ( $now - $created ) / DAY_IN_SECONDS ),
			(int) floor( ( $now - max( $modified, $reviewed ) ) / DAY_IN_SECONDS ),
			self::word_count( $post->post_content ),
			Links::count_broken( $post->post_content )
		);

		/**
		 * Filters the final decay score of a post.
		 *
		 * @param int      $score Score 0-100.
		 * @param \WP_Post $post  Post.
		 */
		$result['score'] = max( 0, min( 100, (int) apply_filters( 'cdaudit_score', $result['score'], $post ) ) );

		update_post_meta( $post->ID, self::META_SCORE, $result['score'] );
		update_post_meta( $post->ID, self::META_REASONS, $result['reasons'] );
		update_post_meta( $post->ID, self::META_SCANNED, $now );
		return $result;
	}

	/**
	 * Scan the next batch of published posts, resuming from a stored cursor.
	 *
	 * @param int $size Batch size.
	 * @return array{scanned:int,done:bool}
	 */
	public static function scan_batch( $size = 100 ) {
		global $wpdb;
		$types  = self::post_types();
		$cursor = (int) get_option( 'cdaudit_cursor', 0 );
		if ( ! $types ) {
			return array(
				'scanned' => 0,
				'done'    => true,
			);
		}
		$in  = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$sql = "SELECT ID FROM %i WHERE post_status = 'publish' AND post_type IN ($in) AND ID > %d ORDER BY ID ASC LIMIT %d";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in is a list of %s placeholders.
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, array_merge( array( $wpdb->posts ), $types, array( $cursor, (int) $size ) ) ) );

		foreach ( $ids as $id ) {
			$post = get_post( (int) $id );
			if ( $post instanceof \WP_Post ) {
				self::score_post( $post );
			}
		}

		$done = count( $ids ) < (int) $size;
		if ( $done ) {
			delete_option( 'cdaudit_cursor' );
			update_option( 'cdaudit_last_scan', time(), false );
		} else {
			update_option( 'cdaudit_cursor', (int) end( $ids ), false );
		}
		return array(
			'scanned' => count( $ids ),
			'done'    => $done,
		);
	}

	/**
	 * Weekly cron: scan a batch, chain another run until the archive is done.
	 */
	public static function cron(): void {
		$result = self::scan_batch( 100 );
		if ( ! $result['done'] ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'cdaudit_scan' );
		}
	}

	/**
	 * Mark a post as reviewed today and rescore it.
	 *
	 * @param int $post_id Post ID.
	 * @return bool False when the post does not exist.
	 */
	public static function mark_reviewed( $post_id ) {
		$post = get_post( (int) $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}
		update_post_meta( $post->ID, self::META_REVIEWED, time() );
		self::score_post( $post );
		return true;
	}

	/**
	 * The refresh queue, highest score first.
	 *
	 * @param int $limit Max posts.
	 * @return \WP_Post[]
	 */
	public static function queue( $limit = 50 ) {
		$query = new \WP_Query(
			array(
				'post_type'              => self::post_types(),
				'post_status'            => 'publish',
				'meta_key'               => self::META_SCORE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'orderby'                => 'meta_value_num',
				'order'                  => 'DESC',
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => self::META_SCORE,
						'value'   => self::threshold(),
						'compare' => '>=',
						'type'    => 'NUMERIC',
					),
				),
				'posts_per_page'         => (int) $limit,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);
		return $query->posts;
	}
}
