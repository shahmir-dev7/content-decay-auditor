<?php
/**
 * WP-CLI commands.
 *
 * @package DecayAuditor
 */

namespace DecayAuditor;

defined( 'ABSPATH' ) || exit;

/**
 * Content Decay Auditor commands.
 */
class Cli {

	/**
	 * Scan every published post of the audited types.
	 */
	public function scan(): void {
		$total = 0;
		do {
			$result = Scanner::scan_batch( 100 );
			$total += $result['scanned'];
		} while ( ! $result['done'] );
		\WP_CLI::success( sprintf( 'Scanned %d posts.', $total ) );
	}

	/**
	 * Print the refresh queue.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Rows to show. Default 20.
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function report( $args, $assoc_args ): void {
		$limit = isset( $assoc_args['limit'] ) ? max( 1, (int) $assoc_args['limit'] ) : 20;
		$rows  = array();
		foreach ( Scanner::queue( $limit ) as $post ) {
			$rows[] = array(
				'ID'      => $post->ID,
				'title'   => get_the_title( $post ),
				'score'   => (int) get_post_meta( $post->ID, Scanner::META_SCORE, true ),
				'reasons' => implode( ',', (array) get_post_meta( $post->ID, Scanner::META_REASONS, true ) ),
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'ID', 'title', 'score', 'reasons' ) );
	}

	/**
	 * Mark a post as reviewed.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Post ID.
	 *
	 * @param array<int,string> $args Positional args.
	 */
	public function review( $args ): void {
		if ( ! Scanner::mark_reviewed( (int) $args[0] ) ) {
			\WP_CLI::error( 'No such post.' );
		}
		\WP_CLI::success( 'Marked as reviewed.' );
	}
}
