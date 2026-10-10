<?php
/**
 * Reserved space tests: the CSS held for a gallery before embed.js draws it.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use PHPUnit\Framework\TestCase;
use Profotograaf\Reserved_Space;

class Reserved_Space_Test extends TestCase {

	/**
	 * The three ratios of a style, desktop first.
	 *
	 * @param string $style Inline style.
	 * @return string[]
	 */
	private function ratios( string $style ): array {
		preg_match_all( '/--pf-ar(?:-[tm])?:([^;]+)/', $style, $m );
		return $m[1];
	}

	public function test_the_style_holds_three_ratios_and_no_minimum_height(): void {
		$style = Reserved_Space::style();

		$this->assertStringNotContainsString( 'min-height', $style );
		$this->assertCount( 3, $this->ratios( $style ) );
	}

	public function test_a_grid_without_options_uses_four_three_and_two_columns_over_three_rows(): void {
		$this->assertSame( array( '4/3', '3/3', '2/3' ), $this->ratios( Reserved_Space::style( 'grid' ) ) );
	}

	public function test_masonry_defaults_to_fewer_columns(): void {
		$this->assertSame( array( '12/9', '8/9', '4/9' ), $this->ratios( Reserved_Space::style( 'masonry' ) ) );
	}

	public function test_the_page_size_sets_the_rows_per_breakpoint(): void {
		$style = Reserved_Space::style( 'grid', array( 'data-per-page' => '16' ) );

		// 16 photos: 4 columns 4 rows, 3 columns 6 rows, 2 columns 8 rows.
		$this->assertSame( array( '4/4', '3/6', '2/8' ), $this->ratios( $style ) );
	}

	public function test_the_photo_count_limits_the_page_size(): void {
		$style = Reserved_Space::style( 'grid', array( 'data-per-page' => '16' ), 5 );

		// 5 photos: 2 rows, 2 rows, 3 rows.
		$this->assertSame( array( '4/2', '3/2', '2/3' ), $this->ratios( $style ) );
	}

	public function test_the_photo_count_alone_sets_the_rows_and_a_larger_page_size_adds_none(): void {
		$this->assertSame( array( '4/2', '3/2', '2/3' ), $this->ratios( Reserved_Space::style( 'grid', array(), 5 ) ) );
		$this->assertSame( array( '4/2', '3/2', '2/3' ), $this->ratios( Reserved_Space::style( 'grid', array( 'data-per-page' => '40' ), 5 ) ) );
	}

	public function test_the_rows_are_capped(): void {
		$data = array(
			'data-columns'        => '1',
			'data-columns-tablet' => '1',
			'data-columns-mobile' => '1',
		);

		$this->assertSame( array( '1/10', '1/10', '1/10' ), $this->ratios( Reserved_Space::style( 'grid', $data, 400 ) ) );
	}

	public function test_set_columns_replace_the_defaults_and_empty_ones_follow_the_wider_screen(): void {
		$style = Reserved_Space::style( 'grid', array( 'data-columns' => '2', 'data-per-page' => '12' ) );

		// Desktop 2, tablet min(2, 3) = 2, phone min(2, 2) = 2.
		$this->assertSame( array( '2/6', '2/6', '2/6' ), $this->ratios( $style ) );

		$data = array(
			'data-columns'        => '6',
			'data-columns-tablet' => '4',
			'data-columns-mobile' => '3',
			'data-per-page'       => '12',
		);
		$this->assertSame( array( '6/2', '4/3', '3/4' ), $this->ratios( Reserved_Space::style( 'grid', $data ) ) );
	}

	public function test_step_down_caps_the_tablet_at_three_and_the_phone_at_two_or_one(): void {
		$this->assertSame( array( 3, 2 ), Reserved_Space::step_down( 'grid', 5 ) );
		$this->assertSame( array( 3, 1 ), Reserved_Space::step_down( 'masonry', 5 ) );
		$this->assertSame( array( 2, 2 ), Reserved_Space::step_down( 'grid', 2 ) );
		$this->assertSame( array( 1, 1 ), Reserved_Space::step_down( 'grid', 1 ) );
		$this->assertNull( Reserved_Space::step_down( 'slideshow', 5 ) );
	}

	public function test_set_columns_without_tablet_or_phone_step_down_like_the_markup(): void {
		$data = array(
			'data-columns'  => '5',
			'data-per-page' => '12',
		);
		$this->assertSame( array( '5/3', '3/4', '2/6' ), $this->ratios( Reserved_Space::style( 'grid', $data ) ) );
		$this->assertSame( array( '20/9', '12/12', '4/30' ), $this->ratios( Reserved_Space::style( 'masonry', $data ) ) );
	}

	public function test_an_explicit_tablet_value_caps_the_phone_estimate(): void {
		$data = array(
			'data-columns'        => '5',
			'data-columns-tablet' => '1',
			'data-per-page'       => '12',
		);
		$this->assertSame( array( '5/3', '1/10', '1/10' ), $this->ratios( Reserved_Space::style( 'grid', $data ) ) );
	}

	public function test_a_photo_shape_replaces_the_unknown_one(): void {
		$this->assertSame( array( '16/9', '12/9', '8/9' ), $this->ratios( Reserved_Space::style( 'grid', array( 'data-ratio' => '4:3' ) ) ) );
		$this->assertSame( array( '4/3', '3/3', '2/3' ), $this->ratios( Reserved_Space::style( 'grid', array( 'data-ratio' => '1:1' ) ) ) );
		$this->assertSame( array( '4/3', '3/3', '2/3' ), $this->ratios( Reserved_Space::style( 'grid', array( 'data-ratio' => 'original' ) ) ) );
		$this->assertSame( array( '12/9', '8/9', '4/9' ), $this->ratios( Reserved_Space::style( 'masonry', array( 'data-ratio' => 'original' ) ) ) );
	}

	public function test_masonry_ignores_a_ratio(): void {
		$this->assertSame( array( '12/9', '8/9', '4/9' ), $this->ratios( Reserved_Space::style( 'masonry', array( 'data-ratio' => '1:1' ) ) ) );
	}

	public function test_a_slideshow_is_one_tile(): void {
		$data = array(
			'data-columns'  => '4',
			'data-per-page' => '9',
		);

		$this->assertSame( array( '3/2', '3/2', '3/2' ), $this->ratios( Reserved_Space::style( 'slideshow', $data ) ) );
	}

	public function test_invalid_values_fall_back_to_the_defaults(): void {
		$data = array(
			'data-columns'  => 'x',
			'data-per-page' => '0',
			'data-ratio'    => '0:3',
		);

		$this->assertSame( array( '4/3', '3/3', '2/3' ), $this->ratios( Reserved_Space::style( 'grid', $data ) ) );
	}

	public function test_the_rule_picks_a_ratio_per_breakpoint(): void {
		$rule = Reserved_Space::rule();

		$this->assertStringContainsString( '[data-profotograaf-gallery]{aspect-ratio:var(--pf-ar);', $rule );
		$this->assertStringContainsString( '@media(max-width:900px){[data-profotograaf-gallery]{aspect-ratio:var(--pf-ar-t)}}', $rule );
		$this->assertStringContainsString( '@media(max-width:600px){[data-profotograaf-gallery]{aspect-ratio:var(--pf-ar-m)}}', $rule );
	}

	public function test_the_reservation_does_not_end_on_the_ready_attribute(): void {
		$this->assertStringNotContainsString( 'data-pf-ready', Reserved_Space::rule() );
	}

	public function test_an_undrawn_gallery_and_a_page_without_scripts_get_the_space_back(): void {
		$rule = Reserved_Space::rule();

		$this->assertStringContainsString( 'animation:pf-release 1ms ' . Reserved_Space::RELEASE_AFTER . 's forwards', $rule );
		$this->assertStringContainsString( '@keyframes pf-release{to{aspect-ratio:auto}}', $rule );
		$this->assertStringContainsString( '<noscript><style>[data-profotograaf-gallery]{aspect-ratio:auto!important}</style></noscript>', $rule );
	}
}
