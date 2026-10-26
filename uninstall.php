<?php
/**
 * Remove all plugin data (per site on multisite).
 *
 * @package DecayAuditor
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site.
 */
function cdaudit_uninstall_site(): void {
	foreach ( array( '_cdaudit_score', '_cdaudit_reasons', '_cdaudit_reviewed', '_cdaudit_scanned' ) as $key ) {
		delete_post_meta_by_key( $key );
	}
	delete_option( 'cdaudit_threshold' );
	delete_option( 'cdaudit_cursor' );
	delete_option( 'cdaudit_last_scan' );
	wp_clear_scheduled_hook( 'cdaudit_scan' );
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $cdaudit_blog_id ) {
		switch_to_blog( (int) $cdaudit_blog_id );
		cdaudit_uninstall_site();
		restore_current_blog();
	}
} else {
	cdaudit_uninstall_site();
}
