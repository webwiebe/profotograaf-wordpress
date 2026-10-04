<?php
/**
 * Shared setup for the gallery block, shortcode and oEmbed tests.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Api_Client;
use Profotograaf\Connection;
use Profotograaf\Embed_Script;
use Profotograaf\Gallery_Index;
use Profotograaf\Gallery_Renderer;
use Profotograaf\Plugin;
use Profotograaf\Settings;

/**
 * Stubs the WordPress functions the gallery classes use and builds a renderer
 * over a faked transport.
 */
abstract class Gallery_Test_Case extends Wp_Test_Case {

	protected Fake_Transport $http;

	protected Api_Client $api;

	protected Plugin $plugin;

	protected Embed_Script $script;

	protected Gallery_Renderer $renderer;

	/**
	 * Scripts queued through wp_enqueue_script, by handle.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	protected array $enqueued = array();

	/**
	 * The connection hint the first gallery on a page carries.
	 */
	protected const HINT = '<link rel="preconnect" href="https://profotograaf.nl"><link rel="preconnect" href="https://profotograaf.nl" crossorigin>';


	protected function setUp(): void {
		parent::setUp();
		defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );

		Functions\when( 'esc_url' )->alias( fn( $url ) => htmlspecialchars( (string) $url, ENT_QUOTES ) );
		Functions\when( 'esc_url_raw' )->alias( fn( $url ) => (string) $url );
		Functions\when( 'wp_strip_all_tags' )->alias( fn( $text ) => trim( strip_tags( (string) $text ) ) );
		Functions\when( 'shortcode_atts' )->alias( fn( $pairs, $atts ) => array_merge( $pairs, array_intersect_key( $atts, $pairs ) ) );
		Functions\when( 'did_action' )->justReturn( 0 );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );
		Functions\when( 'wp_enqueue_script' )->alias(
			function ( $handle, $src = '', $deps = array(), $ver = false, $args = array() ) {
				$this->enqueued[ $handle ] = compact( 'src', 'deps', 'ver', 'args' );
			}
		);

		$this->http     = new Fake_Transport();
		$connection     = new Connection();
		$this->api      = new Api_Client( $connection, $this->http, $this->clock() );
		$this->plugin   = new Plugin( $connection, $this->api, $this->clock() );
		$this->script   = new Embed_Script();
		$this->renderer = new Gallery_Renderer( $this->script, new Gallery_Index( $this->api ), new Settings() );
	}

	/**
	 * Gallery list row as the platform returns it.
	 *
	 * @param string $id    Gallery id.
	 * @param string $title Title.
	 * @return array<string,mixed>
	 */
	protected function row( string $id = 'g-1', string $title = 'Spring wedding' ): array {
		return array(
			'id'          => $id,
			'slug'        => 'spring-wedding',
			'title'       => $title,
			'url'         => 'https://profotograaf.nl/share/g/spring-wedding',
			'embeddable'  => true,
			'available'   => true,
			'photo_count' => 12,
			'cover_url'   => 'https://cdn.example/cover.jpg',
			'updated_at'  => '2026-09-01T10:00:00Z',
		);
	}
}
