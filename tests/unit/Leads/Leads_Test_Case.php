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
use Profotograaf\Leads\Lead_Alerts;
use Profotograaf\Leads\Queue;
use Profotograaf\Settings;
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

	/**
	 * Mails sent with wp_mail: [ to, subject, body ].
	 *
	 * @var array<int,array{0:string,1:string,2:string}>
	 */
	protected array $mails = array();

	/**
	 * Whether wp_mail succeeds.
	 *
	 * @var bool
	 */
	protected bool $mail_works = true;

	private int $uuid = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->store                  = new Memory_Job_Store();
		$this->options['admin_email'] = 'admin@example.com';
		$this->queue                  = new Queue( $this->store, fn() => $this->now, new Lead_Alerts( new Settings() ) );
		$this->dispatcher             = new Dispatcher( new Form_Settings(), $this->queue );

		Functions\when( 'is_email' )->alias( fn( $email ) => false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false );
		Functions\when( 'sanitize_text_field' )->alias( fn( $value ) => trim( strip_tags( (string) $value ) ) );
		Functions\when( 'wp_specialchars_decode' )->returnArg();
		Functions\when( 'admin_url' )->alias( fn( $path = '' ) => 'https://photos.example.com/wp-admin/' . $path );
		Functions\when( 'wp_mail' )->alias(
			function ( $to, $subject, $body ) {
				$this->mails[] = array( $to, $subject, $body );
				return $this->mail_works;
			}
		);
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
