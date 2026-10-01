<?php
/**
 * Photo list tests.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Profotograaf\Photo_List;

class Photo_List_Test extends Gallery_Test_Case {

	private Photo_List $photos;

	protected function setUp(): void {
		parent::setUp();
		$this->photos = new Photo_List( $this->api );
		$this->connect();
	}

	public function test_it_normalises_rows_from_a_list_or_a_photos_key(): void {
		$photo = array(
			'id'      => 'p-1',
			'width'   => 3000,
			'height'  => 2000,
			'title'   => 'Bride',
			'caption' => 'First dance',
			'images'  => array(
				array(
					'variant' => 'web',
					'url'     => 'https://x.example/web.jpg',
				),
				array(
					'variant' => 'thumb',
					'url'     => 'https://x.example/thumb.jpg',
				),
			),
		);
		$this->http->reply( 200, array( 'photos' => array( $photo, array( 'title' => 'no id' ) ) ) );
		$this->http->reply( 200, array( $photo ) );

		$from_key  = $this->photos->fetch( 'g 1' );
		$from_list = $this->photos->fetch( 'g-1' );

		$this->assertCount( 1, $from_key );
		$this->assertSame( 'p-1', $from_key[0]['id'] );
		$this->assertSame( 'https://x.example/thumb.jpg', $from_key[0]['thumb_url'] );
		$this->assertSame( 'First dance', $from_key[0]['caption'] );
		$this->assertSame( 3000, $from_key[0]['width'] );
		$this->assertSame( $from_key, $from_list );
		$this->assertSame( 'https://profotograaf.nl/api/v1/embed/galleries/g%201/photos', $this->http->requests[0]['url'] );
		$this->assertSame( 'https://profotograaf.nl/api/v1/embed/galleries/g-1/photos', $this->http->requests[1]['url'] );
	}

	public function test_a_photo_without_a_thumb_uses_its_first_image(): void {
		$this->http->reply(
			200,
			array(
				array(
					'id'     => 'p-1',
					'images' => array(
						array( 'url' => '' ),
						array(
							'variant' => 'web',
							'url'     => 'https://x.example/web.jpg',
						),
					),
				),
				array( 'id' => 'p-2' ),
			)
		);

		$rows = $this->photos->fetch( 'g-1' );

		$this->assertSame( 'https://x.example/web.jpg', $rows[0]['thumb_url'] );
		$this->assertSame( '', $rows[1]['thumb_url'] );
	}

	public function test_it_needs_a_gallery_id(): void {
		$error = $this->photos->fetch( ' ' );

		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'profotograaf_invalid', $error->get_error_code() );
		$this->assertSame( array(), $this->http->requests );
	}

	public function test_a_platform_error_is_returned_as_it_is(): void {
		$this->http->reply( 404, array( 'error' => 'not found' ) );

		$error = $this->photos->fetch( 'g-1' );

		$this->assertInstanceOf( \WP_Error::class, $error );
	}
}
