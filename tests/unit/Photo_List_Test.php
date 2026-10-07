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
		$this->assertSame( 'https://profotograaf.nl/api/v1/embed/galleries/g%201/photos?limit=200&offset=0', $this->http->requests[0]['url'] );
		$this->assertSame( 'https://profotograaf.nl/api/v1/embed/galleries/g-1/photos?limit=200&offset=0', $this->http->requests[1]['url'] );
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

	/**
	 * A page of the photo list route.
	 *
	 * @param int $from  Index of the first photo.
	 * @param int $count Photos on the page.
	 * @param int $total Photos in the gallery.
	 * @return array<string,mixed>
	 */
	private static function page( int $from, int $count, int $total ): array {
		$photos = array();
		for ( $i = $from; $i < $from + $count; $i++ ) {
			$photos[] = array( 'id' => 'p-' . $i );
		}
		return array(
			'photos' => $photos,
			'total'  => $total,
			'limit'  => 200,
			'offset' => $from,
		);
	}

	public function test_it_follows_the_offset_until_the_total_is_reached(): void {
		$this->http->reply( 200, self::page( 0, 200, 450 ) );
		$this->http->reply( 200, self::page( 200, 200, 450 ) );
		$this->http->reply( 200, self::page( 400, 50, 450 ) );

		$rows = $this->photos->fetch( 'g-1' );

		$this->assertCount( 450, $rows );
		$this->assertSame( 'p-0', $rows[0]['id'] );
		$this->assertSame( 'p-449', $rows[449]['id'] );
		$base = 'https://profotograaf.nl/api/v1/embed/galleries/g-1/photos';
		$this->assertSame(
			array( $base . '?limit=200&offset=0', $base . '?limit=200&offset=200', $base . '?limit=100&offset=400' ),
			array_column( $this->http->requests, 'url' )
		);
	}

	public function test_it_stops_at_the_most_photos_a_block_can_leave_out(): void {
		$this->http->reply( 200, self::page( 0, 200, 1000 ) );
		$this->http->reply( 200, self::page( 200, 200, 1000 ) );
		$this->http->reply( 200, self::page( 400, 100, 1000 ) );

		$rows = $this->photos->fetch( 'g-1' );

		$this->assertCount( 500, $rows );
		$this->assertCount( 3, $this->http->requests );
		$this->assertSame( 'https://profotograaf.nl/api/v1/embed/galleries/g-1/photos?limit=100&offset=400', $this->http->requests[2]['url'] );
	}

	public function test_an_empty_page_ends_the_list_even_when_the_total_says_more(): void {
		$this->http->reply( 200, self::page( 0, 200, 450 ) );
		$this->http->reply( 200, self::page( 200, 0, 450 ) );

		$rows = $this->photos->fetch( 'g-1' );

		$this->assertCount( 200, $rows );
		$this->assertCount( 2, $this->http->requests );
	}

	public function test_an_error_on_a_later_page_is_returned(): void {
		$this->http->reply( 200, self::page( 0, 200, 450 ) );
		$this->http->reply( 500, array( 'error' => 'boom' ) );

		$error = $this->photos->fetch( 'g-1' );

		$this->assertInstanceOf( \WP_Error::class, $error );
	}

	public function test_a_photo_without_a_thumb_variant_uses_its_thumbnail_url(): void {
		$this->http->reply(
			200,
			array(
				'photos' => array(
					array(
						'id'            => 'p-1',
						'thumbnail_url' => 'https://x.example/t.jpg',
						'images'        => array(
							array(
								'variant' => 'web',
								'url'     => 'https://x.example/web.jpg',
							),
						),
					),
					array(
						'id'            => 'p-2',
						'thumbnail_url' => 'https://x.example/t2.jpg',
						'images'        => array(
							array(
								'variant' => 'thumb',
								'url'     => 'https://x.example/thumb2.jpg',
							),
						),
					),
				),
				'total'  => 2,
			)
		);

		$rows = $this->photos->fetch( 'g-1' );

		$this->assertSame( 'https://x.example/t.jpg', $rows[0]['thumb_url'] );
		$this->assertSame( 'https://x.example/thumb2.jpg', $rows[1]['thumb_url'] );
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
