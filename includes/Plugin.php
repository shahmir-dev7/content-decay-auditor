<?php
/**
 * Hook wiring.
 *
 * @package DecayAuditor
 */

namespace DecayAuditor;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin bootstrap. Nothing runs on the front end.
 */
class Plugin {

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_filter( 'cron_schedules', array( __CLASS__, 'weekly_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- WordPress has no built-in weekly schedule.
		add_action( 'cdaudit_scan', array( Scanner::class, 'cron' ) );
		if ( is_admin() ) {
			Admin::init();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'decay', Cli::class );
		}
	}

	/**
	 * Add a weekly cron interval when no other plugin has.
	 *
	 * @param array<string,array<string,mixed>> $schedules Schedules.
	 * @return array<string,array<string,mixed>>
	 */
	public static function weekly_schedule( $schedules ) {
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = array(
				'interval' => WEEK_IN_SECONDS,
				'display'  => __( 'Once weekly', 'content-decay-auditor' ),
			);
		}
		return $schedules;
	}
}
