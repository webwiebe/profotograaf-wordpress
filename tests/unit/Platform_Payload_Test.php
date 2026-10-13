<?php
/**
 * The layout reader against a payload of a real platform.
 *
 * Runs only when PROFOTOGRAAF_PLATFORM_PAYLOAD names a file with the JSON of
 * GET /api/v1/embed/galleries/{id}. The platform repository's workflow
 * "WordPress plugin contract" starts its own stack, seeds a gallery with a
 * custom section layout and a pinned hero photo, and runs this test against
 * the answer. Without the variable the test is skipped.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use PHPUnit\Framework\TestCase;
use Profotograaf\Gallery_Layout;

class Platform_Payload_Test extends TestCase {

	/**
	 * The payload of the real platform.
	 *
	 * @return array<string,mixed>
	 */
	private function payload(): array {
		$file = getenv( 'PROFOTOGRAAF_PLATFORM_PAYLOAD' );
		if ( ! is_string( $file ) || '' === $file ) {
			$this->markTestSkipped( 'PROFOTOGRAAF_PLATFORM_PAYLOAD is not set.' );
		}
		$this->assertFileExists( $file );
		$data = json_decode( (string) file_get_contents( $file ), true );
		$this->assertIsArray( $data, 'the payload is JSON' );
		return $data;
	}

	/**
	 * The preset the seed chose.
	 */
	private function preset(): string {
		$preset = getenv( 'PROFOTOGRAAF_PLATFORM_PRESET' );
		return is_string( $preset ) && '' !== $preset ? $preset : 'custom';
	}

	public function test_the_platform_sends_the_layout_the_plugin_reads(): void {
		$gallery = $this->payload();
		$preset  = $this->preset();

		$this->assertSame( $preset, Gallery_Layout::preset_of( $gallery ), 'the plugin knows the preset the platform sent' );

		$resolved = Gallery_Layout::resolve( $gallery, Gallery_Layout::PRESET, array(), array() );
		$this->assertSame( $preset, $resolved['preset'] );
		$this->assertSame( array(), 'custom' === $preset ? $resolved['picks'] : $resolved['custom'] );
	}

	public function test_a_custom_layout_of_the_platform_keeps_its_sections_and_seats_the_pinned_photo(): void {
		$gallery = $this->payload();
		if ( 'custom' !== $this->preset() ) {
			$this->markTestSkipped( 'The preset of the seeded gallery is not custom.' );
		}
		$resolved = Gallery_Layout::resolve( $gallery, Gallery_Layout::PRESET, array(), array() );
		$types    = array_map( static fn ( $section ) => is_array( $section ) ? ( $section['type'] ?? '' ) : '', $resolved['custom'] );

		$this->assertContains( 'hero', $types );
		$this->assertContains( 'text', $types, 'the text section of the seed' );

		$photos = array_map( static fn ( $photo ) => (string) ( $photo['id'] ?? '' ), (array) ( $gallery['photos'] ?? array() ) );
		$seated = array();
		foreach ( $resolved['custom'] as $section ) {
			foreach ( (array) ( is_array( $section ) ? ( $section['photoIds'] ?? array() ) : array() ) as $id ) {
				$seated[] = (string) $id;
			}
		}
		$this->assertNotEmpty( $seated, 'a photo is pinned in the seed' );
		foreach ( $seated as $id ) {
			$this->assertContains( $id, $photos, 'a pinned photo is in the gallery' );
		}
		$pinned = getenv( 'PROFOTOGRAAF_PLATFORM_PINNED' );
		if ( is_string( $pinned ) && '' !== $pinned ) {
			$this->assertSame( $pinned, $seated[0] ?? '', 'the hero holds the photo pinned in the seed' );
		}
	}
}
