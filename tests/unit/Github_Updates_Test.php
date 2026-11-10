<?php
/**
 * Tests for the update source that reads GitHub releases.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Profotograaf\Github_Updates;

/**
 * The update check, its cache and the plugin details modal.
 */
class Github_Updates_Test extends Wp_Test_Case {

	private const FILE = 'profotograaf/profotograaf.php';

	private const ZIP = 'https://github.com/webwiebe/profotograaf-wordpress/releases/download/v0.3.0/profotograaf.zip';

	/**
	 * Requests made, in order.
	 *
	 * @var string[]
	 */
	private array $requests = array();

	/**
	 * What the fake HTTP layer answers per URL: array{int,string} or null for a transport failure.
	 *
	 * @var array<string,array{int,string}|null>
	 */
	private array $answers = array();

	/**
	 * Release JSON for a tag.
	 *
	 * @param string $tag Tag name.
	 */
	private function release( string $tag = 'v0.3.0' ): string {
		return (string) wp_json_encode(
			array(
				'tag_name'   => $tag,
				'html_url'   => 'https://github.com/webwiebe/profotograaf-wordpress/releases/tag/' . $tag,
				'draft'      => false,
				'prerelease' => false,
				'assets'     => array(
					array(
						'name'                 => 'profotograaf.zip',
						'browser_download_url' => self::ZIP,
					),
				),
			)
		);
	}

	/**
	 * Builds the subject with a fake HTTP layer.
	 *
	 * @param string $installed Installed version.
	 */
	private function updates( string $installed = '0.2.4' ): Github_Updates {
		$this->answers[ Github_Updates::RELEASE_URL ] = array( 200, $this->release() );
		return new Github_Updates(
			$installed,
			self::FILE,
			function ( string $url ): ?array {
				$this->requests[] = $url;
				return $this->answers[ $url ] ?? null;
			},
			fn(): int => $this->now
		);
	}

	public function test_a_newer_release_is_offered_with_its_zip(): void {
		$update = $this->updates()->filter_update( false, array(), self::FILE );

		$this->assertSame( '0.3.0', $update['version'] );
		$this->assertSame( self::ZIP, $update['package'] );
		$this->assertSame( self::FILE, $update['plugin'] );
		$this->assertSame( 'profotograaf', $update['slug'] );
	}

	public function test_the_same_or_an_older_release_offers_nothing(): void {
		$this->assertFalse( $this->updates( '0.3.0' )->filter_update( false, array(), self::FILE ) );
		$this->assertFalse( $this->updates( '0.4.0' )->filter_update( false, array(), self::FILE ) );
	}

	public function test_another_plugin_is_left_alone(): void {
		$this->assertFalse( $this->updates()->filter_update( false, array(), 'other/other.php' ) );
		$this->assertSame( array(), $this->requests );
	}

	public function test_a_malformed_answer_offers_nothing(): void {
		foreach ( array( 'not json', '{}', '{"tag_name":"latest"}', '{"tag_name":"v0.3.0","assets":[]}', '{"tag_name":"v0.3.0","assets":[{"name":"profotograaf.zip","browser_download_url":"https://example.com/x.zip"}]}' ) as $body ) {
			$this->transients                             = array();
			$updates                                      = $this->updates();
			$this->answers[ Github_Updates::RELEASE_URL ] = array( 200, $body );
			$this->assertFalse( $updates->filter_update( false, array(), self::FILE ), $body );
		}
	}

	public function test_a_failed_or_rate_limited_request_offers_nothing_and_keeps_the_screen_working(): void {
		$updates                                      = $this->updates();
		$this->answers[ Github_Updates::RELEASE_URL ] = array( 403, '{"message":"rate limit"}' );
		$this->assertFalse( $updates->filter_update( false, array(), self::FILE ) );

		$this->transients                             = array();
		$this->answers[ Github_Updates::RELEASE_URL ] = null;
		$this->assertFalse( $updates->filter_update( false, array(), self::FILE ) );
	}

	public function test_the_check_asks_github_at_most_once_in_twelve_hours_even_after_a_failure(): void {
		$updates = $this->updates();
		$updates->filter_update( false, array(), self::FILE );
		$updates->filter_update( false, array(), self::FILE );
		$this->assertCount( 1, $this->requests );

		$this->transients                             = array();
		$this->requests                               = array();
		$this->answers[ Github_Updates::RELEASE_URL ] = array( 500, '' );
		$updates->filter_update( false, array(), self::FILE );
		$updates->filter_update( false, array(), self::FILE );
		$this->assertCount( 1, $this->requests );
	}

	public function test_the_details_modal_shows_the_changelog_of_the_new_version(): void {
		$readme = "=== Profotograaf ===\nStable tag: 0.3.0\nTested up to: 7.1\nRequires PHP: 8.1\n\n== Changelog ==\n\n= 0.3.0 =\n* Fix: first.\n* New: second.\n\n= 0.2.4 =\n* Fix: old.\n";
		$this->answers[ Github_Updates::readme_url( '0.3.0' ) ] = array( 200, $readme );
		$info = $this->updates()->plugin_information( false, 'plugin_information', (object) array( 'slug' => 'profotograaf' ) );

		$this->assertSame( '0.3.0', $info->version );
		$this->assertSame( self::ZIP, $info->download_link );
		$this->assertSame( '7.1', $info->tested );
		$this->assertSame( '<ul><li>Fix: first.</li><li>New: second.</li></ul>', $info->sections['changelog'] );
	}

	public function test_the_details_modal_ignores_other_plugins_and_actions(): void {
		$updates = $this->updates();
		$this->assertFalse( $updates->plugin_information( false, 'plugin_information', (object) array( 'slug' => 'other' ) ) );
		$this->assertFalse( $updates->plugin_information( false, 'query_plugins', (object) array( 'slug' => 'profotograaf' ) ) );
		$this->assertSame( array(), $this->requests );
	}
}
