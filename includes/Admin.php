<?php
/**
 * Admin screen and handlers.
 *
 * @package DecayAuditor
 */

namespace DecayAuditor;

defined( 'ABSPATH' ) || exit;

/**
 * Admin.
 */
class Admin {

	const CAP = 'edit_others_posts';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_setting' ) );
		add_action( 'admin_post_cdaudit_review', array( __CLASS__, 'handle_review' ) );
		add_action( 'admin_post_cdaudit_scan', array( __CLASS__, 'handle_scan' ) );
	}

	/**
	 * Tools > Content Decay.
	 */
	public static function menu(): void {
		add_management_page( __( 'Content Decay', 'content-decay-auditor' ), __( 'Content Decay', 'content-decay-auditor' ), self::CAP, 'cdaudit', array( __CLASS__, 'render' ) );
	}

	/**
	 * Threshold setting.
	 */
	public static function register_setting(): void {
		register_setting(
			'cdaudit',
			'cdaudit_threshold',
			array(
				'type'              => 'integer',
				'default'           => 40,
				'sanitize_callback' => static function ( $value ) {
					return max( 1, min( 100, absint( $value ) ) );
				},
			)
		);
	}

	/**
	 * Human label for a reason code.
	 *
	 * @param string $code Reason code from Scorer.
	 * @return string
	 */
	public static function reason_label( $code ) {
		if ( 0 === strpos( $code, 'broken:' ) ) {
			/* translators: %d: number of broken internal links. */
			return sprintf( _n( '%d broken internal link', '%d broken internal links', (int) substr( $code, 7 ), 'content-decay-auditor' ), (int) substr( $code, 7 ) );
		}
		$labels = array(
			'stale_2y' => __( 'Not updated in over 2 years', 'content-decay-auditor' ),
			'stale_1y' => __( 'Not updated in over a year', 'content-decay-auditor' ),
			'stale_6m' => __( 'Not updated in over 6 months', 'content-decay-auditor' ),
			'old_3y'   => __( 'Published over 3 years ago', 'content-decay-auditor' ),
			'old_2y'   => __( 'Published over 2 years ago', 'content-decay-auditor' ),
			'thin_150' => __( 'Very thin (under 150 words)', 'content-decay-auditor' ),
			'thin_300' => __( 'Thin (under 300 words)', 'content-decay-auditor' ),
		);
		return isset( $labels[ $code ] ) ? $labels[ $code ] : '';
	}

	/**
	 * Capability gate; dies with 403.
	 *
	 * @param string $action  Nonce action.
	 * @param int    $post_id Post to check edit rights for, 0 for the general capability.
	 */
	private static function guard( $action, $post_id = 0 ): void {
		$allowed = $post_id ? current_user_can( 'edit_post', $post_id ) : current_user_can( self::CAP );
		if ( ! $allowed ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'content-decay-auditor' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Redirect back with a notice code.
	 *
	 * @param string $code Notice code.
	 */
	private static function back( $code ): void {
		wp_safe_redirect( add_query_arg( 'cdaudit_msg', $code, admin_url( 'tools.php?page=cdaudit' ) ) );
		exit;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- every handler calls guard() (check_admin_referer) first.

	/**
	 * Mark a post reviewed.
	 */
	public static function handle_review(): void {
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		self::guard( 'cdaudit_review_' . $post_id, $post_id );
		self::back( Scanner::mark_reviewed( $post_id ) ? 'reviewed' : 'invalid' );
	}

	/**
	 * Scan the next batch.
	 */
	public static function handle_scan(): void {
		self::guard( 'cdaudit_scan' );
		$result = Scanner::scan_batch( 100 );
		self::back( ( $result['done'] ? 'done-' : 'more-' ) . $result['scanned'] );
	}

	// phpcs:enable WordPress.Security.NonceVerification.Missing

	/**
	 * Render the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'content-decay-auditor' ), '', array( 'response' => 403 ) );
		}
		$post_url = esc_url( admin_url( 'admin-post.php' ) );
		$msg      = isset( $_GET['cdaudit_msg'] ) ? sanitize_key( wp_unslash( $_GET['cdaudit_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.

		echo '<div class="wrap"><h1>' . esc_html__( 'Content Decay Auditor', 'content-decay-auditor' ) . '</h1>';
		if ( 'reviewed' === $msg ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Marked as reviewed.', 'content-decay-auditor' ) . '</p></div>';
		} elseif ( 'invalid' === $msg ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'That post no longer exists.', 'content-decay-auditor' ) . '</p></div>';
		} elseif ( preg_match( '/^(done|more)-(\d+)$/', $msg, $m ) ) {
			/* translators: %d: number of posts scanned. */
			$text = sprintf( _n( 'Scanned %d post.', 'Scanned %d posts.', (int) $m[2], 'content-decay-auditor' ), (int) $m[2] );
			if ( 'more' === $m[1] ) {
				$text .= ' ' . __( 'More posts remain: scan again to continue.', 'content-decay-auditor' );
			}
			echo '<div class="notice notice-success"><p>' . esc_html( $text ) . '</p></div>';
		}

		$last = (int) get_option( 'cdaudit_last_scan', 0 );
		echo '<p>' . ( $last
			/* translators: %s: date of the last completed scan. */
			? esc_html( sprintf( __( 'Last full scan: %s.', 'content-decay-auditor' ), wp_date( get_option( 'date_format' ), $last ) ) )
			: esc_html__( 'No full scan has completed yet.', 'content-decay-auditor' ) ) . '</p>';
		echo '<form method="post" action="' . $post_url . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		wp_nonce_field( 'cdaudit_scan' );
		echo '<input type="hidden" name="action" value="cdaudit_scan"><button class="button button-primary">' . esc_html__( 'Scan next 100 posts', 'content-decay-auditor' ) . '</button></form>';

		echo '<h2>' . esc_html__( 'Refresh queue', 'content-decay-auditor' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Post', 'content-decay-auditor' ) . '</th><th>' . esc_html__( 'Score', 'content-decay-auditor' ) . '</th><th>' . esc_html__( 'Why', 'content-decay-auditor' ) . '</th><th>' . esc_html__( 'Last updated', 'content-decay-auditor' ) . '</th><th>' . esc_html__( 'Actions', 'content-decay-auditor' ) . '</th></tr></thead><tbody>';
		$posts = Scanner::queue( 50 );
		if ( ! $posts ) {
			echo '<tr><td colspan="5">' . esc_html__( 'Nothing needs refreshing. Run a scan if you have not yet.', 'content-decay-auditor' ) . '</td></tr>';
		}
		foreach ( $posts as $post ) {
			$reasons = array_filter( array_map( array( __CLASS__, 'reason_label' ), (array) get_post_meta( $post->ID, Scanner::META_REASONS, true ) ) );
			echo '<tr><td>';
			if ( current_user_can( 'edit_post', $post->ID ) ) {
				echo '<a href="' . esc_url( (string) get_edit_post_link( $post->ID ) ) . '">' . esc_html( get_the_title( $post ) ) . '</a>';
			} else {
				echo esc_html( get_the_title( $post ) );
			}
			echo '</td><td>' . (int) get_post_meta( $post->ID, Scanner::META_SCORE, true ) . '</td><td>' . esc_html( implode( '; ', $reasons ) ) . '</td><td>' . esc_html( get_the_modified_date( '', $post ) ) . '</td><td>';
			if ( current_user_can( 'edit_post', $post->ID ) ) {
				echo '<form method="post" action="' . $post_url . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
				wp_nonce_field( 'cdaudit_review_' . $post->ID );
				echo '<input type="hidden" name="action" value="cdaudit_review"><input type="hidden" name="post_id" value="' . (int) $post->ID . '"><button class="button">' . esc_html__( 'Mark reviewed', 'content-decay-auditor' ) . '</button></form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		if ( current_user_can( 'manage_options' ) ) {
			echo '<h2>' . esc_html__( 'Settings', 'content-decay-auditor' ) . '</h2><form method="post" action="options.php">';
			settings_fields( 'cdaudit' );
			echo '<label for="cdaudit_threshold">' . esc_html__( 'Queue threshold (score 1-100)', 'content-decay-auditor' ) . '</label> <input type="number" min="1" max="100" id="cdaudit_threshold" name="cdaudit_threshold" value="' . esc_attr( (string) Scanner::threshold() ) . '"> ';
			submit_button( __( 'Save', 'content-decay-auditor' ), 'secondary', 'submit', false );
			echo '</form>';
		}
		echo '</div>';
	}
}
