<?php
/**
 * Tests for the maintenance actions of the Enquiry forms screen.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests\Leads;

use Brain\Monkey\Functions;
use Profotograaf\Api_Client;
use Profotograaf\Connection;
use Profotograaf\Embed_Script;
use Profotograaf\Gallery_Index;
use Profotograaf\Leads\Site_Tools;
use Profotograaf\Tests\Fake_Transport;
use Profotograaf\Tests\Wp_Test_Case;

class Site_Tools_Test extends Wp_Test_Case {

	private Fake_Transport $http;

	private Site_Tools $tools;

	protected function setUp(): void {
		parent::setUp();
		$this->connect( 100000 );
		$this->http  = new Fake_Transport();
		$this->tools = new Site_Tools( new Api_Client( new Connection(), $this->http, $this->clock() ), new Embed_Script() );

		Functions\when( 'number_format_i18n' )->returnArg();
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( fn( $response ) => is_array( $response ) ? (int) ( $response['code'] ?? 0 ) : 0 );
		Functions\when( 'wp_remote_retrieve_header' )->alias( fn( $response, $name ) => $response[ $name ] ?? '' );
	}

	public function test_syncing_stores_the_galleries_and_reports_how_many(): void {
		$this->http->reply(
			200,
			array(
				array(
					'id'    => 'g1',
					'title' => 'Wedding',
					'url'   => 'https://profotograaf.nl/g/1',
				),
				array(
					'id'    => 'g2',
					'title' => 'Family',
					'url'   => 'https://profotograaf.nl/g/2',
				),
			)
		);

		$result = $this->tools->sync_galleries();

		$this->assertSame( 'success', $result['type'] );
		$this->assertSame( 'Synced 2 galleries.', $result['message'] );
		$this->assertSame( array( 'g1', 'g2' ), array_keys( $this->options[ Gallery_Index::OPTION ] ) );
	}

	public function test_a_failed_sync_shows_the_platform_message_and_keeps_the_index(): void {
		$this->options[ Gallery_Index::OPTION ] = array( 'old' => array( 'title' => 'Old', 'url' => '' ) );
		$this->http->reply( 500, array( 'error' => 'boom' ) );

		$result = $this->tools->sync_galleries();

		$this->assertSame( 'error', $result['type'] );
		$this->assertStringStartsWith( 'The galleries could not be synced: ', $result['message'] );
		$this->assertSame( array( 'old' ), array_keys( $this->options[ Gallery_Index::OPTION ] ) );
	}

	public function test_clearing_removes_the_index_and_the_lookup_guard(): void {
		$this->options[ Gallery_Index::OPTION ]          = array( 'g1' => array( 'title' => 'A', 'url' => '' ) );
		$this->transients[ Gallery_Index::LOOKUP_GUARD ] = 1;

		$result = $this->tools->clear_gallery_index();

		$this->assertSame( 'success', $result['type'] );
		$this->assertArrayNotHasKey( Gallery_Index::OPTION, $this->options );
		$this->assertArrayNotHasKey( Gallery_Index::LOOKUP_GUARD, $this->transients );
	}

	public function test_rechecking_reports_the_version_the_platform_serves(): void {
		Functions\when( 'wp_remote_head' )->justReturn(
			array(
				'code' => 200,
				'etag' => '"0123456789ab"',
			)
		);

		$result = $this->tools->check_embed_version();

		$this->assertSame( 'success', $result['type'] );
		$this->assertSame( 'The embed script is at version 0123456789ab.', $result['message'] );
	}

	public function test_rechecking_reports_an_error_when_no_version_is_known(): void {
		Functions\when( 'wp_remote_head' )->justReturn( new \WP_Error( 'http_request_failed', 'timeout' ) );

		$result = $this->tools->check_embed_version();

		$this->assertSame( 'error', $result['type'] );
	}

	public function test_a_result_is_shown_once_to_the_user_it_was_left_for(): void {
		Site_Tools::remember(
			array(
				'type'    => 'success',
				'message' => 'Done.',
			)
		);

		$this->assertSame( 'Done.', Site_Tools::take()['message'] );
		$this->assertNull( Site_Tools::take() );
	}
}
