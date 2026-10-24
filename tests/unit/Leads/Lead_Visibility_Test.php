<?php
/**
 * Which forms send enquiries, which forms are new, and the submissions skipped
 * for lack of an email address.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests\Leads;

use Profotograaf\Leads\Bridge;
use Profotograaf\Leads\Dispatcher;
use Profotograaf\Leads\Enquiry_Form;
use Profotograaf\Leads\Form_Settings;
use Profotograaf\Leads\New_Forms;
use Profotograaf\Leads\Skip_Counter;
use Profotograaf\Leads\Submission;

/**
 * A bridge with a fixed list of forms.
 */
class Listing_Bridge implements Bridge {

	/**
	 * Forms as [ key => title ].
	 *
	 * @var array<string,string>
	 */
	private array $titles;

	private bool $active;

	/**
	 * Constructor.
	 *
	 * @param array<string,string> $titles Forms.
	 * @param bool                 $active Whether the form plugin is active.
	 */
	public function __construct( array $titles, bool $active = true ) {
		$this->titles = $titles;
		$this->active = $active;
	}

	public function id(): string {
		return 'listing';
	}

	public function label(): string {
		return 'Contact Form 7';
	}

	public function register(): void {}

	public function is_active(): bool {
		return $this->active;
	}

	public function forms(): array {
		$forms = array();
		foreach ( $this->titles as $key => $title ) {
			$forms[] = array(
				'key'    => $key,
				'title'  => $title,
				'fields' => array(),
			);
		}
		return $forms;
	}
}

class Lead_Visibility_Test extends Leads_Test_Case {

	private Skip_Counter $skips;

	protected function setUp(): void {
		parent::setUp();
		$this->skips = new Skip_Counter();
	}

	private function submission( string $key, string $email ): Submission {
		return new Submission(
			$key,
			'Contact Form 7: Wedding',
			array(
				array(
					'id'    => 'email',
					'label' => 'Email',
					'type'  => 'email',
					'value' => $email,
				),
			)
		);
	}

	public function test_a_form_that_was_never_seen_sends_by_default(): void {
		$this->assertTrue( ( new Form_Settings() )->enabled( 'cf7:12' ) );
	}

	public function test_a_form_switched_off_stays_off(): void {
		$this->options[ Form_Settings::OPTION ]['forms']['cf7:12'] = array( 'enabled' => false );

		$this->assertFalse( ( new Form_Settings() )->enabled( 'cf7:12' ) );
	}

	public function test_a_submission_of_a_new_form_is_queued(): void {
		$result = $this->dispatcher->submit( $this->submission( 'cf7:12', 'anna@example.com' ) );

		$this->assertSame( 'queued', $result );
	}

	public function test_new_forms_are_the_ones_without_a_saved_entry(): void {
		$this->options[ Form_Settings::OPTION ]['forms']['cf7:1'] = array( 'enabled' => true );
		$bridge = new Listing_Bridge(
			array(
				'cf7:1' => 'Known',
				'cf7:2' => 'Wedding',
			)
		);

		$labels = ( new New_Forms( array( $bridge ), new Form_Settings() ) )->labels();

		$this->assertSame( array( 'Contact Form 7: Wedding' ), $labels );
	}

	public function test_no_form_is_new_once_the_screen_saved_them_all(): void {
		$this->options[ Form_Settings::OPTION ]['forms'] = array(
			'cf7:2' => array( 'enabled' => false ),
		);
		$bridge = new Listing_Bridge( array( 'cf7:2' => 'Wedding' ) );

		$this->assertSame( array(), ( new New_Forms( array( $bridge ), new Form_Settings() ) )->labels() );
	}

	public function test_a_bridge_of_an_inactive_form_plugin_adds_no_new_forms(): void {
		$bridge = new Listing_Bridge( array( 'cf7:2' => 'Wedding' ), false );

		$this->assertSame( array(), ( new New_Forms( array( $bridge ), new Form_Settings() ) )->labels() );
	}

	public function test_the_native_block_is_never_listed_as_a_new_form(): void {
		$bridge = new Listing_Bridge( array( Enquiry_Form::FORM_KEY => 'Block' ) );

		$this->assertSame( array(), ( new New_Forms( array( $bridge ), new Form_Settings() ) )->labels() );
	}

	public function test_a_submission_without_an_email_is_counted_per_form(): void {
		$dispatcher = new Dispatcher( new Form_Settings(), $this->queue, $this->skips );

		$first  = $dispatcher->submit( $this->submission( 'cf7:12', '' ) );
		$second = $dispatcher->submit( $this->submission( 'cf7:12', '' ) );
		$dispatcher->submit( $this->submission( 'cf7:13', '' ) );

		$this->assertSame( 'no_email', $first );
		$this->assertSame( 'no_email', $second );
		$all = $this->skips->all();
		$this->assertSame( 2, $all['cf7:12']['count'] );
		$this->assertSame( 1, $all['cf7:13']['count'] );
		$this->assertSame( array(), $this->store->jobs );
	}

	public function test_the_skip_count_keeps_the_time_of_the_last_skip_and_no_visitor_data(): void {
		$dispatcher = new Dispatcher( new Form_Settings(), $this->queue, $this->skips );

		$dispatcher->submit( $this->submission( 'cf7:12', '' ) );

		$this->assertSame( $this->now, $this->skips->all()['cf7:12']['last_at'] );
		$this->assertStringNotContainsString( 'Wedding', (string) json_encode( $this->options[ Skip_Counter::OPTION ] ) );
	}

	public function test_a_queued_submission_is_not_a_skip(): void {
		$dispatcher = new Dispatcher( new Form_Settings(), $this->queue, $this->skips );

		$dispatcher->submit( $this->submission( 'cf7:12', 'anna@example.com' ) );

		$this->assertSame( array(), $this->skips->all() );
	}

	public function test_the_skip_counter_is_stored_without_autoload(): void {
		$this->skips->record( 'cf7:12', $this->now );

		$this->assertFalse( $this->autoload[ Skip_Counter::OPTION ] );
	}

	public function test_forgetting_a_form_clears_its_count(): void {
		$this->skips->record( 'cf7:12', $this->now );
		$this->skips->record( 'cf7:13', $this->now );

		$this->skips->forget( 'cf7:12' );

		$this->assertSame( array( 'cf7:13' ), array_keys( $this->skips->all() ) );
	}
}
