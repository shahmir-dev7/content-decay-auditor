<?php
/**
 * Activation and deactivation.
 *
 * @package DecayAuditor
 */

namespace DecayAuditor;

defined( 'ABSPATH' ) || exit;

/**
 * Lifecycle. No tables: scores live in post meta.
 */
class Lifecycle {

	/**
	 * Schedule the weekly scan, on every site for network activation.
	 *
	 * @param bool $network_wide Network activation.
	 */
	public static function activate( $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			) as $blog_id ) {
				switch_to_blog( (int) $blog_id );
				self::schedule();
				restore_current_blog();
			}
			return;
		}
		self::schedule();
	}

	/**
	 * Schedule for the current site.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( 'cdaudit_scan' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', 'cdaudit_scan' );
		}
	}

	/**
	 * Stop cron; scores are kept until uninstall.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'cdaudit_scan' );
	}
}
