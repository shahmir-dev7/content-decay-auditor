<?php
/**
 * Integration tests: scanning, queue, review handler, permissions, screen, CLI, cron, uninstall.
 *
 * @package DecayAuditor
 */

use DecayAuditor\Admin;
use DecayAuditor\Cli;
use DecayAuditor\Lifecycle;
use DecayAuditor\Links;
use DecayAuditor\Plugin;
use DecayAuditor\Scanner;

require_once __DIR__ . '/cli-stubs.php';

/**
 * Thrown by the wp_redirect trap.
 */
class Cda_Redirect_Caught extends RuntimeException {
}

/**
 * Integration tests.
 */
class IntegrationTest extends WP_UnitTestCase {

	/**
	 * Observe redirects instead of exiting.
	 */
	public function set_up() {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new Cda_Redirect_Caught( $location );
			}
		);
		$_POST = array();
		delete_option( 'cdaudit_cursor' );
	}

	/**
	 * Create a published post dated N days ago.
	 *
	 * @param int    $days_old Age in days (also last-modified age).
	 * @param string $content  Content.
	 * @param string $title    Title.
	 * @return int Post ID.
	 */
	private function old_post( $days_old, $content, $title = 'Old' ) {
		global $wpdb;
		$id   = self::factory()->post->create(
			array(
				'post_title'   => $title,
				'post_content' => $content,
				'post_status'  => 'publish',
			)
		);
		$date = gmdate( 'Y-m-d H:i:s', time() - $days_old * DAY_IN_SECONDS );
		$wpdb->update( $wpdb->posts, array( 'post_date' => $date, 'post_date_gmt' => $date, 'post_modified' => $date, 'post_modified_gmt' => $date ), array( 'ID' => $id ) ); // phpcs:ignore
		clean_post_cache( $id );
		return $id;
	}

	private function words( $n ) {
		return implode( ' ', array_fill( 0, $n, 'word' ) );
	}

	private function as_user( $role ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
	}

	private function redirect_of( $fn ) {
		try {
			$fn();
		} catch ( Cda_Redirect_Caught $e ) {
			return $e->getMessage();
		}
		$this->fail( 'Expected a redirect.' );
	}

	public function test_scan_scores_posts_and_builds_ranked_queue() {
		$bad     = $this->old_post( 900, 'short', 'Bad' );
		$mid     = $this->old_post( 200, $this->words( 400 ), 'Mid' );
		$healthy = $this->old_post( 5, $this->words( 400 ), 'Fresh' );

		$result = Scanner::scan_batch( 100 );
		$this->assertTrue( $result['done'] );
		$this->assertGreaterThanOrEqual( 3, $result['scanned'] );

		$this->assertSame( 0, (int) get_post_meta( $healthy, '_cdaudit_score', true ) );
		$this->assertSame( 15, (int) get_post_meta( $mid, '_cdaudit_score', true ) );
		$this->assertGreaterThanOrEqual( 40, (int) get_post_meta( $bad, '_cdaudit_score', true ) );

		$queue = wp_list_pluck( Scanner::queue(), 'ID' );
		$this->assertContains( $bad, $queue );
		$this->assertNotContains( $mid, $queue, 'Below the threshold of 40.' );
		$this->assertNotContains( $healthy, $queue );
		update_option( 'cdaudit_threshold', 10 );
		$this->assertContains( $mid, wp_list_pluck( Scanner::queue(), 'ID' ) );
		$this->assertGreaterThan( 0, (int) get_option( 'cdaudit_last_scan' ) );
	}

	public function test_scan_batches_resume_from_cursor() {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->old_post( 10, $this->words( 400 ) );
		}
		$first = Scanner::scan_batch( 2 );
		$this->assertSame( 2, $first['scanned'] );
		$this->assertFalse( $first['done'] );
		$this->assertGreaterThan( 0, (int) get_option( 'cdaudit_cursor' ) );
		$total = $first['scanned'];
		do {
			$next   = Scanner::scan_batch( 2 );
			$total += $next['scanned'];
		} while ( ! $next['done'] );
		$this->assertGreaterThanOrEqual( 5, $total );
		$this->assertFalse( get_option( 'cdaudit_cursor' ), 'Cursor cleared when finished.' );
	}

	public function test_broken_internal_links_are_detected() {
		$target = self::factory()->post->create( array( 'post_name' => 'real-post', 'post_status' => 'publish' ) );
		$term   = self::factory()->category->create( array( 'slug' => 'news' ) );
		$html   = '<a href="' . get_permalink( $target ) . '">ok</a>'
			. '<a href="/real-post/">ok relative</a>'
			. '<a href="/news/">term-ish</a>'
			. '<a href="https://elsewhere.example/gone">external</a>'
			. '<a href="mailto:a@b.c">mail</a><a href="#top">anchor</a>'
			. '<a href="/definitely-missing/">broken</a>'
			. '<a href="' . home_url( '/also-missing/' ) . '">broken 2</a>'
			. '<a href="/wp-content/uploads/nope.pdf">missing file</a>';
		$this->assertSame( 3, Links::count_broken( $html ) );
		$this->assertGreaterThan( 0, $term );
	}

	public function test_file_links_cannot_probe_outside_wp_content() {
		$this->assertFileExists( ABSPATH . 'wp-config.php', 'Precondition: a real file exists outside wp-content.' );
		$this->assertTrue( Links::is_broken( '/wp-content/../wp-config.php' ), 'Traversal is treated as broken, never as an existing file.' );
		$this->assertTrue( Links::is_broken( '/wp-content/%2e%2e/wp-config.php' ) );
		$existing = WP_CONTENT_DIR . '/index.php';
		$this->assertFileExists( $existing );
		$this->assertFalse( Links::is_broken( '/wp-content/index.php' ), 'A real file inside wp-content is fine.' );
	}
	public function test_mark_reviewed_resets_staleness_and_rescoring() {
		$id = $this->old_post( 900, $this->words( 400 ) );
		Scanner::scan_batch( 100 );
		$before = (int) get_post_meta( $id, '_cdaudit_score', true );
		$this->assertGreaterThanOrEqual( 40, $before );
		$this->assertTrue( Scanner::mark_reviewed( $id ) );
		$this->assertLessThan( $before, (int) get_post_meta( $id, '_cdaudit_score', true ) );
		$this->assertFalse( Scanner::mark_reviewed( 999999 ) );
	}

	public function test_review_handler_enforces_nonce_and_per_post_capability() {
		$id = $this->old_post( 900, 'x' );
		add_filter( 'wp_die_handler', static fn() => static function ( $m ) {
			throw new RuntimeException( is_string( $m ) ? $m : 'die' );
		} );

		// A contributor cannot edit someone else's published post.
		$this->as_user( 'contributor' );
		$_POST['post_id']     = $id;
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'cdaudit_review_' . $id );
		try {
			Admin::handle_review();
			$this->fail( 'Should have died.' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'permission', $e->getMessage() );
		}
		$this->assertSame( '', (string) get_post_meta( $id, '_cdaudit_reviewed', true ) );

		// An editor with a nonce for a DIFFERENT post is rejected (nonce is per post).
		$this->as_user( 'editor' );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'cdaudit_review_' . ( $id + 1 ) );
		try {
			Admin::handle_review();
			$this->fail( 'Should have died.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( '', (string) get_post_meta( $id, '_cdaudit_reviewed', true ) );
		}

		// An editor with the right nonce succeeds.
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'cdaudit_review_' . $id );
		$this->assertStringContainsString( 'cdaudit_msg=reviewed', $this->redirect_of( array( Admin::class, 'handle_review' ) ) );
		$this->assertNotSame( '', (string) get_post_meta( $id, '_cdaudit_reviewed', true ) );

		// A post that does not exist is refused by the capability check (WordPress denies edit rights on it).
		$_POST['post_id']     = 999999;
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'cdaudit_review_999999' );
		try {
			Admin::handle_review();
			$this->fail( 'Should have died.' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'permission', $e->getMessage() );
		}
	}

	public function test_scan_handler_requires_capability_and_reports_progress() {
		add_filter( 'wp_die_handler', static fn() => static function () {
			throw new RuntimeException( 'denied' );
		} );
		$this->as_user( 'subscriber' );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'cdaudit_scan' );
		try {
			Admin::handle_scan();
			$this->fail( 'Should have died.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'denied', $e->getMessage() );
		}
		$this->as_user( 'editor' );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'cdaudit_scan' );
		$this->assertMatchesRegularExpression( '/cdaudit_msg=done-\d+/', $this->redirect_of( array( Admin::class, 'handle_scan' ) ) );
	}

	public function test_screen_lists_queue_escapes_titles_and_hides_settings_from_editors() {
		$this->old_post( 900, 'x', '<script>alert(1)</script> Evil' );
		Scanner::scan_batch( 100 );

		$this->as_user( 'editor' );
		ob_start();
		Admin::render();
		$html = ob_get_clean();
		$this->assertStringNotContainsString( '<script>alert', $html );
		$this->assertStringContainsString( 'Mark reviewed', $html );
		$this->assertStringNotContainsString( 'cdaudit_threshold', $html, 'Settings are for administrators only.' );

		$this->as_user( 'administrator' );
		$_GET['cdaudit_msg'] = 'done-7';
		ob_start();
		Admin::render();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'cdaudit_threshold', $html );
		$this->assertStringContainsString( 'Scanned 7 posts.', $html );
		$this->assertStringContainsString( 'Not updated in over 2 years', $html );
	}

	public function test_screen_denies_subscribers() {
		$this->as_user( 'subscriber' );
		add_filter( 'wp_die_handler', static fn() => static function () {
			throw new RuntimeException( 'denied' );
		} );
		$this->expectException( RuntimeException::class );
		Admin::render();
	}

	public function test_threshold_setting_is_clamped_and_menu_registers() {
		$this->as_user( 'administrator' );
		Admin::menu();
		Admin::register_setting();
		update_option( 'cdaudit_threshold', '500' );
		$this->assertSame( 100, Scanner::threshold() );
		update_option( 'cdaudit_threshold', '-5' );
		$this->assertSame( 5, Scanner::threshold() );
	}

	public function test_reason_labels() {
		$this->assertSame( '1 broken internal link', Admin::reason_label( 'broken:1' ) );
		$this->assertSame( '3 broken internal links', Admin::reason_label( 'broken:3' ) );
		$this->assertSame( '', Admin::reason_label( 'unknown' ) );
	}

	public function test_cron_chains_until_done_and_lifecycle() {
		for ( $i = 0; $i < 105; $i++ ) {
			self::factory()->post->create( array( 'post_status' => 'publish' ) );
		}
		wp_clear_scheduled_hook( 'cdaudit_scan' );
		Scanner::cron();
		$this->assertNotFalse( wp_next_scheduled( 'cdaudit_scan' ), 'More posts remain: another run is scheduled.' );

		wp_clear_scheduled_hook( 'cdaudit_scan' );
		Lifecycle::activate( false );
		$this->assertNotFalse( wp_next_scheduled( 'cdaudit_scan' ) );
		Lifecycle::deactivate();
		$this->assertFalse( wp_next_scheduled( 'cdaudit_scan' ) );

		$this->assertArrayHasKey( 'weekly', Plugin::weekly_schedule( array() ) );
		$existing = array( 'weekly' => array( 'interval' => 1 ) );
		$this->assertSame( $existing, Plugin::weekly_schedule( $existing ), 'Does not override another plugin.' );
	}

	public function test_post_types_filter() {
		add_filter( 'cdaudit_post_types', static fn() => array( 'page' ) );
		$this->assertSame( array( 'page' ), Scanner::post_types() );
		add_filter( 'cdaudit_post_types', static fn() => array() );
		$this->assertSame( array( 'scanned' => 0, 'done' => true ), Scanner::scan_batch( 10 ) );
	}

	public function test_score_filter() {
		$id = $this->old_post( 900, 'x' );
		add_filter( 'cdaudit_score', static fn() => 7 );
		Scanner::score_post( get_post( $id ) );
		$this->assertSame( 7, (int) get_post_meta( $id, '_cdaudit_score', true ) );
	}

	public function test_cli_commands() {
		$id  = $this->old_post( 900, 'x', 'CLI post' );
		$cli = new Cli();
		$cli->scan();
		$cli->report( array(), array( 'limit' => '5' ) );
		$this->assertContains( $id, wp_list_pluck( $GLOBALS['cdaudit_cli_items'], 'ID' ) );
		$cli->review( array( (string) $id ) );
		$this->assertSame( 'success', WP_CLI::$log[ count( WP_CLI::$log ) - 1 ][0] );
		$this->expectException( RuntimeException::class );
		$cli->review( array( '999999' ) );
	}

	public function test_uninstall_removes_meta_options_and_cron() {
		$id = $this->old_post( 900, 'x' );
		Scanner::scan_batch( 100 );
		Scanner::mark_reviewed( $id );
		update_option( 'cdaudit_threshold', 55 );
		Lifecycle::schedule();
		define( 'WP_UNINSTALL_PLUGIN', 'content-decay-auditor/content-decay-auditor.php' );
		include dirname( __DIR__, 2 ) . '/uninstall.php';
		foreach ( array( '_cdaudit_score', '_cdaudit_reasons', '_cdaudit_reviewed', '_cdaudit_scanned' ) as $key ) {
			$this->assertSame( '', (string) get_post_meta( $id, $key, true ), $key );
		}
		$this->assertFalse( get_option( 'cdaudit_threshold' ) );
		$this->assertFalse( wp_next_scheduled( 'cdaudit_scan' ) );
	}
}
