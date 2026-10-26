<?php
/**
 * Bootstrap for WordPress integration tests (run inside wp-env tests-cli).
 *
 * @package DecayAuditor
 */

$cdaudit_tests_dir = getenv( 'WP_TESTS_DIR' ) ? getenv( 'WP_TESTS_DIR' ) : '/wordpress-phpunit';

require_once $cdaudit_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__, 2 ) . '/content-decay-auditor.php';
	}
);

require $cdaudit_tests_dir . '/includes/bootstrap.php';
