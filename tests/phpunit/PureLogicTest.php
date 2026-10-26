<?php
/**
 * Pure-logic tests: scoring and link extraction.
 *
 * @package DecayAuditor
 */

use DecayAuditor\Links;
use DecayAuditor\Scorer;
use PHPUnit\Framework\TestCase;

/**
 * @covers \DecayAuditor\Scorer
 * @covers \DecayAuditor\Links::extract
 */
class PureLogicTest extends TestCase {

	public function test_healthy_post_scores_zero() {
		$r = Scorer::score( 30, 10, 900, 0 );
		$this->assertSame( 0, $r['score'] );
		$this->assertSame( array(), $r['reasons'] );
	}

	public function test_each_signal_contributes_and_reports_a_reason() {
		$this->assertSame( array( 'stale_2y', 'old_3y', 'thin_150', 'broken:2' ), Scorer::score( 2000, 800, 50, 2 )['reasons'] );
		$this->assertSame( 40 + 10 + 25 + 24, Scorer::score( 2000, 800, 50, 2 )['score'] );
		$this->assertSame( 30, Scorer::score( 10, 400, 900, 0 )['score'] );
		$this->assertSame( 15, Scorer::score( 10, 200, 900, 0 )['score'] );
		$this->assertSame( 12, Scorer::score( 10, 10, 200, 0 )['score'] );
		$this->assertSame( 5, Scorer::score( 800, 10, 900, 0 )['score'] );
	}

	public function test_score_is_capped_and_broken_links_are_capped() {
		$this->assertSame( 25, Scorer::score( 0, 0, 900, 50 )['score'] );
		$this->assertSame( 100, Scorer::score( 5000, 5000, 1, 50 )['score'] );
	}

	public function test_boundaries_are_exclusive() {
		$this->assertSame( 0, Scorer::score( 0, 180, 300, 0 )['score'] );
		$this->assertSame( 15, Scorer::score( 0, 181, 300, 0 )['score'] );
	}

	public function test_extract_finds_hrefs_once_and_decodes_entities() {
		$html = '<p><a class="x" href="/a">A</a> <a href=\'/b?x=1&amp;y=2\'>B</a> <A HREF="/a">dupe</A> <a name="no-href">n</a></p>';
		$this->assertSame( array( '/a', '/b?x=1&y=2' ), Links::extract( $html ) );
		$this->assertSame( array(), Links::extract( 'no links here' ) );
	}
}
