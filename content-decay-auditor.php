<?php
/**
 * Plugin Name:       Content Decay Auditor
 * Description:       Finds old, thin or stale posts and builds a prioritised refresh queue, with one-click "mark reviewed". No external services.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            shahmir-dev7
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       content-decay-auditor
 * Domain Path:       /languages
 *
 * @package DecayAuditor
 */

defined( 'ABSPATH' ) || exit;

define( 'CDAUDIT_VERSION', '1.0.0' );
define( 'CDAUDIT_FILE', __FILE__ );

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'DecayAuditor\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$file = __DIR__ . '/includes/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'DecayAuditor\\Lifecycle', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'DecayAuditor\\Lifecycle', 'deactivate' ) );

DecayAuditor\Plugin::init();
