<?php
/**
 * Decay score (pure, no WordPress calls).
 *
 * @package DecayAuditor
 */

namespace DecayAuditor;

defined( 'ABSPATH' ) || exit;

/**
 * Scorer.
 */
class Scorer {

	/**
	 * Score a post from 0 (healthy) to 100 (refresh first).
	 *
	 * @param int $age_days      Days since first published.
	 * @param int $modified_days Days since last update or review, whichever is newer.
	 * @param int $words         Word count.
	 * @param int $broken_links  Broken internal links found.
	 * @return array{score:int,reasons:string[]} Reasons are codes: stale_2y, stale_1y, stale_6m, old_3y, old_2y, thin_150, thin_300, broken:N.
	 */
	public static function score( $age_days, $modified_days, $words, $broken_links ) {
		$score   = 0;
		$reasons = array();

		if ( $modified_days > 730 ) {
			$score    += 40;
			$reasons[] = 'stale_2y';
		} elseif ( $modified_days > 365 ) {
			$score    += 30;
			$reasons[] = 'stale_1y';
		} elseif ( $modified_days > 180 ) {
			$score    += 15;
			$reasons[] = 'stale_6m';
		}

		if ( $age_days > 1095 ) {
			$score    += 10;
			$reasons[] = 'old_3y';
		} elseif ( $age_days > 730 ) {
			$score    += 5;
			$reasons[] = 'old_2y';
		}

		if ( $words < 150 ) {
			$score    += 25;
			$reasons[] = 'thin_150';
		} elseif ( $words < 300 ) {
			$score    += 12;
			$reasons[] = 'thin_300';
		}

		if ( $broken_links > 0 ) {
			$score    += min( 25, 12 * (int) $broken_links );
			$reasons[] = 'broken:' . (int) $broken_links;
		}

		return array(
			'score'   => min( 100, $score ),
			'reasons' => $reasons,
		);
	}
}
