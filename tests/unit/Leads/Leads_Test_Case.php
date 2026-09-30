<?php
/**
 * Base for the lead tests.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests\Leads;

use Brain\Monkey\Functions;
use Profotograaf\Leads\Dispatcher;
use Profotograaf\Leads\Form_Settings;
use Profotograaf\Leads\Queue;
use Profotograaf\Tests\Wp_Test_Case;

/**
 * Adds the WordPress functions the lead code calls, a memory queue and a
 * dispatcher wired to it.
 */
abstract class Leads_Test_Case extends Wp_Test_Case {

	protected Memory_Job_Store $store;

	protected Queue $queue;

	protected Dispatcher $dispatcher;

	/**
	 * Cron events scheduled: [ timestamp, hook ].
	 *
	 * @var array<int,array{0:int,1:string}>
	 */
	protected array $scheduled = array();

	private int $uuid = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->store      = new Memory_Job_Store();
		$this->queue      = new Queue( $this->store, fn() => $this->now );
		$this->dispatcher = new Dispatcher( new Form_Settings(), $this->queue );

		Functions\when( 'is_email' )->alias( fn( $email ) => false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false );
		Functions\when( 'wp_generate_uuid4' )->alias( fn() => 'uuid-' . ( ++$this->uuid ) );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $timestamp, $hook ) {
				$this->scheduled[] = array( $timestamp, $hook );
				return true;
			}
		);
	}

	/**
	 * Switches a form on with an optional mapping.
	 *
	 * @param string               $key Form key.
	 * @param array<string,string> $map Mapping.
	 */
	protected function enable( string $key, array $map = array() ): void {
		$this->options[ Form_Settings::OPTION ]['forms'][ $key ] = array(
			'enabled' => true,
			'map'     => $map,
		);
	}

	/**
	 * The payload of the only queued job.
	 *
	 * @return array<string,mixed>
	 */
	protected function only_payload(): array {
		self::assertCount( 1, $this->store->jobs );
		return array_values( $this->store->jobs )[0]['payload'];
	}
}
