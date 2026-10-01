<?php
/**
 * Multisite tests: network activation, new sites, deactivation, overview and the network page.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Admin\Network_Page;
use Profotograaf\Connection;
use Profotograaf\Leads\Delivery;
use Profotograaf\Modules\Token_Refresh;
use Profotograaf\Multisite;
use Profotograaf\Plugin;
use Profotograaf\Settings;
use Profotograaf\Tests\Leads\Memory_Job_Store;

class Multisite_Test extends Wp_Test_Case {

	/**
	 * Options per site id.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $sites = array();

	/**
	 * Scheduled hooks per site id.
	 *
	 * @var array<int,array<string,bool>>
	 */
	private array $crons = array();

	private int $current = 1;

	/**
	 * Site ids switched to, in order.
	 *
	 * @var array<int,int>
	 */
	private array $switched = array();

	/**
	 * Arguments the last get_sites call received.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $site_queries = array();

	/**
	 * Network wide options.
	 *
	 * @var array<string,mixed>
	 */
	private array $network = array();

	protected function setUp(): void {
		parent::setUp();
		$this->sites = array(
			1 => array(),
			2 => array(),
			3 => array(),
		);
		$this->crons = array(
			1 => array(),
			2 => array(),
			3 => array(),
		);

		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'plugin_basename' )->justReturn( 'profotograaf/profotograaf.php' );
		Functions\when( 'get_site_option' )->alias( fn( $name, $fallback = false ) => $this->network[ $name ] ?? $fallback );
		Functions\when( 'get_sites' )->alias(
			function ( $args ) {
				$this->site_queries[] = $args;
				if ( ! empty( $args['count'] ) ) {
					return count( $this->sites );
				}
				$ids = array_keys( $this->sites );
				return array_slice( $ids, (int) ( $args['offset'] ?? 0 ), ! empty( $args['number'] ) ? (int) $args['number'] : null );
			}
		);
		Functions\when( 'switch_to_blog' )->alias(
			function ( $id ) {
				$this->switched[] = (int) $id;
				$this->current    = (int) $id;
				return true;
			}
		);
		Functions\when( 'restore_current_blog' )->alias(
			function () {
				$this->current = 1;
				return true;
			}
		);
		Functions\when( 'get_option' )->alias( fn( $name, $fallback = false ) => $this->sites[ $this->current ][ $name ] ?? $fallback );
		Functions\when( 'add_option' )->alias(
			function ( $name, $value = '' ) {
				if ( array_key_exists( $name, $this->sites[ $this->current ] ) ) {
					return false;
				}
				$this->sites[ $this->current ][ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->sites[ $this->current ][ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_next_scheduled' )->alias( fn( $hook ) => ! empty( $this->crons[ $this->current ][ $hook ] ) ? 1 : false );
		Functions\when( 'wp_schedule_event' )->alias(
			function ( $when, $recurrence, $hook ) {
				$this->crons[ $this->current ][ $hook ] = true;
				return true;
			}
		);
		Functions\when( '_get_cron_array' )->alias(
			fn() => array( 100 => array_map( fn() => array(), $this->crons[ $this->current ] ) )
		);
		Functions\when( 'wp_clear_scheduled_hook' )->alias(
			function ( $hook ) {
				unset( $this->crons[ $this->current ][ $hook ] );
				return 1;
			}
		);
		Functions\when( 'home_url' )->alias( fn( $path = '' ) => 'https://site' . $this->current . '.example.com' . $path );
		Functions\when( 'admin_url' )->alias( fn( $path = '' ) => 'https://site' . $this->current . '.example.com/wp-admin/' . $path );
	}

	public function test_network_activation_prepares_every_subsite_without_a_connection(): void {
		( new Multisite() )->activate( true );

		foreach ( array( 1, 2, 3 ) as $id ) {
			$this->assertIsArray( $this->sites[ $id ][ Settings::OPTION ], "site $id has settings" );
			$this->assertArrayHasKey( Settings::VERSION_OPTION, $this->sites[ $id ] );
			$this->assertArrayNotHasKey( Connection::OPTION, $this->sites[ $id ] );
			$this->assertTrue( $this->crons[ $id ][ Delivery::SWEEP ] );
		}
		$this->current = 2;
		$this->assertSame( 'disconnected', ( new Connection() )->status()['state'] );
		$this->assertSame( array( 1, 2, 3 ), $this->switched );
	}

	public function test_network_activation_asks_for_every_site_not_only_the_first_hundred(): void {
		( new Multisite() )->activate( true );

		$this->assertSame( 0, $this->site_queries[0]['number'] );
	}

	public function test_network_activation_keeps_settings_a_site_already_has(): void {
		$this->sites[2][ Settings::OPTION ] = array( 'default_layout' => 'masonry' );

		( new Multisite() )->activate( true );

		$this->assertSame( array( 'default_layout' => 'masonry' ), $this->sites[2][ Settings::OPTION ] );
	}

	public function test_single_site_activation_touches_only_this_site(): void {
		( new Multisite() )->activate( false );

		$this->assertSame( array(), $this->switched );
		$this->assertArrayHasKey( Settings::OPTION, $this->sites[1] );
		$this->assertArrayNotHasKey( Settings::OPTION, $this->sites[2] );
	}

	public function test_plugin_activate_runs_the_multisite_activation(): void {
		Plugin::activate( true );

		$this->assertArrayHasKey( Settings::OPTION, $this->sites[3] );
	}

	public function test_a_new_site_is_prepared_when_the_plugin_is_network_active(): void {
		$this->network['active_sitewide_plugins'] = array( 'profotograaf/profotograaf.php' => 1 );

		( new Multisite() )->initialize_site( (object) array( 'blog_id' => 3 ) );

		$this->assertArrayHasKey( Settings::OPTION, $this->sites[3] );
		$this->assertArrayNotHasKey( Settings::OPTION, $this->sites[2] );
		$this->assertTrue( $this->crons[3][ Delivery::SWEEP ] );
	}

	public function test_a_new_site_is_left_alone_when_the_plugin_is_not_network_active(): void {
		( new Multisite() )->initialize_site( (object) array( 'blog_id' => 3 ) );

		$this->assertSame( array(), $this->sites[3] );
		$this->assertSame( array(), $this->switched );
	}

	public function test_register_hooks_new_sites_and_the_network_menu(): void {
		( new Multisite() )->register();

		$this->assertNotFalse( has_action( 'wp_initialize_site' ) );
		$this->assertNotFalse( has_action( 'network_admin_menu' ) );
	}

	public function test_network_deactivation_clears_the_crons_of_every_subsite(): void {
		foreach ( array( 1, 2, 3 ) as $id ) {
			$this->crons[ $id ] = array(
				Token_Refresh::HOOK  => true,
				Delivery::SWEEP      => true,
				Delivery::HOOK       => true,
				'someone_elses_hook' => true,
			);
		}

		Plugin::deactivate( true );

		foreach ( array( 1, 2, 3 ) as $id ) {
			$this->assertSame( array( 'someone_elses_hook' => true ), $this->crons[ $id ], "site $id" );
		}
	}

	public function test_single_site_deactivation_clears_only_this_site(): void {
		$this->crons[1] = array( Delivery::SWEEP => true );
		$this->crons[2] = array( Delivery::SWEEP => true );

		Plugin::deactivate();

		$this->assertSame( array(), $this->crons[1] );
		$this->assertSame( array( Delivery::SWEEP => true ), $this->crons[2] );
	}

	public function test_overview_lists_the_state_and_failed_leads_of_each_subsite(): void {
		$this->network['active_sitewide_plugins'] = array( 'profotograaf/profotograaf.php' => 1 );
		$this->sites[1]['blogname']               = 'Main';
		$this->sites[2]['blogname']               = 'Studio';
		$this->sites[2][ Connection::OPTION ]     = array(
			'access_token'  => 'a',
			'refresh_token' => 'r',
		);
		$this->sites[3][ Connection::OPTION ]     = array( 'last_error' => 'The account ended the connection.' );

		$store       = new Memory_Job_Store();
		$store->jobs = array(
			'a' => array( 'status' => 'failed' ),
			'b' => array( 'status' => 'queued' ),
			'c' => array( 'status' => 'failed' ),
		);

		$overview = ( new Multisite( $store ) )->overview();

		$this->assertSame( 3, $overview['total'] );
		$this->assertSame( array( 'disconnected', 'connected', 'revoked' ), array_column( $overview['rows'], 'state' ) );
		$this->assertSame( array( 'Main', 'Studio', '' ), array_column( $overview['rows'], 'name' ) );
		$this->assertSame( array( 2, 2, 2 ), array_column( $overview['rows'], 'failed' ) );
		$this->assertSame( 'https://site2.example.com/wp-admin/options-general.php?page=profotograaf', $overview['rows'][1]['settings_url'] );
	}

	public function test_overview_marks_sites_without_the_plugin_as_inactive(): void {
		$this->sites[1]['active_plugins'] = array( 'profotograaf/profotograaf.php' );

		$store       = new Memory_Job_Store();
		$store->jobs = array( 'a' => array( 'status' => 'failed' ) );

		$overview = ( new Multisite( $store ) )->overview();

		$this->assertSame( array( 'disconnected', 'inactive', 'inactive' ), array_column( $overview['rows'], 'state' ) );
		$this->assertSame( array( 1, 0, 0 ), array_column( $overview['rows'], 'failed' ) );
	}

	public function test_overview_pages_through_the_sites(): void {
		$this->sites = array();
		for ( $id = 1; $id <= Multisite::PAGE_SIZE + 2; $id++ ) {
			$this->sites[ $id ] = array();
			$this->crons[ $id ] = array();
		}

		$overview = ( new Multisite( new Memory_Job_Store() ) )->overview( 2 );

		$this->assertSame( Multisite::PAGE_SIZE + 2, $overview['total'] );
		$this->assertSame( array( 51, 52 ), array_column( $overview['rows'], 'id' ) );
	}

	public function test_the_network_page_renders_the_rows_for_a_network_admin(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'number_format_i18n' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		$this->network['active_sitewide_plugins'] = array( 'profotograaf/profotograaf.php' => 1 );
		$this->sites[2]['blogname']               = 'Studio';

		ob_start();
		( new Network_Page( new Multisite( new Memory_Job_Store() ) ) )->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Studio', $html );
		$this->assertStringContainsString( 'https://site2.example.com/wp-admin/options-general.php?page=profotograaf', $html );
		$this->assertStringContainsString( 'Not connected', $html );
	}

	public function test_the_network_page_refuses_users_without_the_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'wp_die' )->alias(
			function () {
				throw new \RuntimeException( 'died' );
			}
		);

		$this->expectException( \RuntimeException::class );
		( new Network_Page( new Multisite( new Memory_Job_Store() ) ) )->render();
	}

	public function test_the_network_page_adds_its_menu_entry_and_labels_every_state(): void {
		Functions\expect( 'add_submenu_page' )->once()->with( 'settings.php', 'Profotograaf', 'Profotograaf', 'manage_network_options', Network_Page::SLUG, \Mockery::type( 'array' ) );

		( new Network_Page( new Multisite( new Memory_Job_Store() ) ) )->add_menu();

		$this->assertSame( 'Connected', Network_Page::state_label( 'connected' ) );
		$this->assertSame( 'Connection ended', Network_Page::state_label( 'revoked' ) );
		$this->assertSame( 'Plugin not active', Network_Page::state_label( 'inactive' ) );
		$this->assertSame( 'Not connected', Network_Page::state_label( 'disconnected' ) );
	}
}
