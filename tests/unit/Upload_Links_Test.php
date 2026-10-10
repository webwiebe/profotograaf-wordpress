<?php
/**
 * Upload_Links tests.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Profotograaf\Upload_Links;

class Upload_Links_Test extends Wp_Test_Case {

	private Upload_Links $links;

	protected function setUp(): void {
		parent::setUp();
		$this->links = new Upload_Links();
	}

	public function test_nothing_is_linked_by_default(): void {
		$this->assertSame( array(), $this->links->all() );
		$this->assertNull( $this->links->find( 'g-1' ) );
		$this->assertNull( $this->links->auto_link() );
	}

	public function test_a_link_records_who_made_it_and_when_and_is_stored_without_autoload(): void {
		$this->assertTrue( $this->links->add( 'g-1', 'Spring wedding', 5, 1234 ) );

		$this->assertSame(
			array(
				'gallery_id' => 'g-1',
				'title'      => 'Spring wedding',
				'created_by' => 5,
				'created_at' => 1234,
				'auto'       => false,
				'disabled'   => false,
			),
			$this->links->find( 'g-1' )
		);
		$this->assertFalse( $this->autoload['profotograaf_upload_links'] );
	}

	public function test_an_empty_gallery_id_is_refused(): void {
		$this->assertFalse( $this->links->add( '  ', 'X', 5, 1 ) );
		$this->assertSame( array(), $this->links->all() );
	}

	public function test_linking_a_gallery_twice_keeps_one_entry_and_re_enables_it(): void {
		$this->links->add( 'g-1', 'Old title', 5, 1 );
		$this->links->disable( 'g-1' );
		$this->links->add( 'g-1', 'New title', 6, 2 );

		$this->assertCount( 1, $this->links->all() );
		$this->assertFalse( $this->links->find( 'g-1' )['disabled'] );
		$this->assertSame( 'New title', $this->links->find( 'g-1' )['title'] );
		$this->assertSame( 5, $this->links->find( 'g-1' )['created_by'] );
	}

	public function test_only_one_link_sends_automatically(): void {
		$this->links->add( 'g-1', 'A', 5, 1 );
		$this->links->add( 'g-2', 'B', 5, 1 );

		$this->assertTrue( $this->links->set_auto( 'g-1', true ) );
		$this->assertTrue( $this->links->set_auto( 'g-2', true ) );

		$this->assertFalse( $this->links->find( 'g-1' )['auto'] );
		$this->assertSame( 'g-2', $this->links->auto_link()['gallery_id'] );

		$this->links->set_auto( 'g-2', false );
		$this->assertNull( $this->links->auto_link() );
		$this->assertFalse( $this->links->set_auto( 'g-unknown', true ) );
	}

	public function test_a_disabled_link_is_not_usable_and_loses_automatic_sending(): void {
		$this->links->add( 'g-1', 'A', 5, 1 );
		$this->links->add( 'g-2', 'B', 5, 1 );
		$this->links->set_auto( 'g-1', true );

		$this->links->disable( 'g-1' );

		$this->assertSame( array( 'g-2' ), array_column( $this->links->usable(), 'gallery_id' ) );
		$this->assertNull( $this->links->auto_link() );
		$this->assertFalse( $this->links->set_auto( 'g-1', true ) && $this->links->find( 'g-1' )['auto'] );
	}

	public function test_unlinking_removes_only_that_entry(): void {
		$this->links->add( 'g-1', 'A', 5, 1 );
		$this->links->add( 'g-2', 'B', 5, 1 );

		$this->links->remove( 'g-1' );

		$this->assertSame( array( 'g-2' ), array_column( $this->links->all(), 'gallery_id' ) );
	}

	public function test_a_damaged_option_is_read_as_far_as_it_is_valid(): void {
		$this->options['profotograaf_upload_links'] = array( 'junk', array( 'title' => 'no id' ), array( 'gallery_id' => 'g-3', 'auto' => 1 ) );

		$this->assertSame( array( 'g-3' ), array_column( $this->links->all(), 'gallery_id' ) );
		$this->options['profotograaf_upload_links'] = 'nonsense';
		$this->assertSame( array(), $this->links->all() );
	}
}
