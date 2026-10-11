<?php
/**
 * Client galleries block: the optional link to one delivered gallery.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Modules\Client_Galleries;
use Profotograaf\Settings;

class Client_Galleries_Gallery_Test extends Wp_Test_Case {

	private const SHARE = 'https://profotograaf.nl/share/g/spring-wedding';

	private Client_Galleries $block;

	protected function setUp(): void {
		parent::setUp();
		$this->block = new Client_Galleries( new Settings() );
		Functions\when( 'esc_url' )->alias( fn( $url ) => (string) $url );
		Functions\when( 'esc_html' )->alias( fn( $text ) => htmlspecialchars( (string) $text, ENT_QUOTES ) );
		Functions\when( 'get_block_wrapper_attributes' )->justReturn( 'class="wp-block-profotograaf-client-galleries"' );
		$this->as_editor( false );
	}

	private function as_editor( bool $editor ): void {
		Functions\when( 'current_user_can' )->justReturn( $editor );
	}

	/**
	 * Output of the block before the gallery attribute existed, captured from
	 * that version of render(). Blocks without a gallery must keep it byte for
	 * byte.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:bool,2:string}>
	 */
	public static function golden(): array {
		$portal = '<div class="wp-block-profotograaf-client-galleries"><h3 class="wp-block-profotograaf-client-galleries__heading">Find your gallery</h3><p class="wp-block-profotograaf-client-galleries__text">Sign in to the client portal to see your photos.</p><div class="wp-block-button"><a class="wp-block-profotograaf-client-galleries__button wp-block-button__link wp-element-button" href="https://profotograaf.nl/studio/client">Open my gallery</a></div></div>';
		$notice = '<div class="wp-block-profotograaf-client-galleries"><p class="wp-block-profotograaf-client-galleries__notice">Only editors see this note. The &quot;Find your gallery&quot; block has no Profotograaf address yet. Enter it in the block settings. Until then visitors do not see this block.</p></div>';
		return array(
			'portal only'                  => array( array( 'portal' => 'studio' ), false, $portal ),
			'all text, paragraph, new tab' => array(
				array(
					'portal'       => 'photos.example.com',
					'portalPath'   => '/portal',
					'heading'      => 'Hi <b>there</b>',
					'headingLevel' => 0,
					'description'  => 'Your "photos" & more',
					'buttonLabel'  => 'Go',
					'openInNewTab' => true,
				),
				false,
				'<div class="wp-block-profotograaf-client-galleries"><p class="wp-block-profotograaf-client-galleries__heading">Hi &lt;b&gt;there&lt;/b&gt;</p><p class="wp-block-profotograaf-client-galleries__text">Your &quot;photos&quot; &amp; more</p><div class="wp-block-button"><a class="wp-block-profotograaf-client-galleries__button wp-block-button__link wp-element-button" href="https://photos.example.com/portal" target="_blank" rel="noopener noreferrer">Go</a></div></div>',
			),
			'h5 with saved defaults'       => array(
				array(
					'portal'       => 'https://profotograaf.nl/studio/client',
					'heading'      => '',
					'description'  => '',
					'buttonLabel'  => '',
					'headingLevel' => 5,
					'openInNewTab' => false,
				),
				false,
				str_replace( array( '<h3', '</h3>' ), array( '<h5', '</h5>' ), $portal ),
			),
			'no address, editor'           => array( array(), true, $notice ),
			'no address, visitor'          => array( array(), false, '' ),
			'invalid address, editor'      => array( array( 'portal' => 'my studio' ), true, $notice ),
		);
	}

	/**
	 * @dataProvider golden
	 * @param array<string,mixed> $attributes
	 */
	public function test_without_a_gallery_the_output_is_unchanged( array $attributes, bool $editor, string $expected ): void {
		$this->as_editor( $editor );

		$this->assertSame( $expected, $this->block->render( $attributes ) );
	}

	/**
	 * The block.json defaults of the new attributes, and gallery values that
	 * are no link, give the same output as before.
	 *
	 * @dataProvider golden
	 * @param array<string,mixed> $attributes
	 */
	public function test_default_or_unusable_gallery_values_keep_the_output( array $attributes, bool $editor, string $expected ): void {
		$this->as_editor( $editor );

		foreach ( array( '', '   ', 'javascript:alert(1)', '/share/g/a', 'https:///share', 'https://profotograaf.nl/a b', 42, null ) as $gallery ) {
			$this->assertSame(
				$expected,
				$this->block->render( $attributes + array( 'gallery' => $gallery, 'linkTo' => 'portal' ) ),
				'gallery ' . var_export( $gallery, true )
			);
		}
	}

	public function test_a_gallery_links_to_its_share_url_in_the_same_tab(): void {
		$html = $this->block->render( array( 'gallery' => self::SHARE ) );

		$this->assertSame(
			'<div class="wp-block-profotograaf-client-galleries"><h3 class="wp-block-profotograaf-client-galleries__heading">Your photos</h3><p class="wp-block-profotograaf-client-galleries__text">See the photos in your gallery.</p><div class="wp-block-button"><a class="wp-block-profotograaf-client-galleries__button wp-block-button__link wp-element-button" href="https://profotograaf.nl/share/g/spring-wedding">Open your gallery</a></div></div>',
			$html
		);
	}

	public function test_a_gallery_wins_over_the_portal_and_ignores_the_new_tab_setting(): void {
		$html = $this->block->render(
			array(
				'portal'       => 'studio',
				'gallery'      => '  ' . self::SHARE . '  ',
				'linkTo'       => 'gallery',
				'openInNewTab' => true,
				'heading'      => 'Hello <i>Anna</i>',
				'headingLevel' => 2,
				'buttonLabel'  => 'See them',
			)
		);

		$this->assertStringContainsString( 'href="' . self::SHARE . '"', $html );
		$this->assertStringNotContainsString( 'studio/client', $html );
		$this->assertStringNotContainsString( 'target=', $html );
		$this->assertStringContainsString( '<h2 class="wp-block-profotograaf-client-galleries__heading">Hello &lt;i&gt;Anna&lt;/i&gt;</h2>', $html );
		$this->assertStringContainsString( '>See them</a>', $html );
	}

	public function test_the_gallery_link_makes_no_install_claim(): void {
		$html = strtolower( $this->block->render( array( 'gallery' => self::SHARE ) ) );

		$this->assertStringNotContainsString( 'install', $html );
		$this->assertStringNotContainsString( ' app', $html );
	}

	public function test_the_variation_without_a_gallery_asks_editors_to_choose_one(): void {
		$this->as_editor( true );

		$html = $this->block->render( array( 'linkTo' => 'gallery', 'portal' => 'studio' ) );

		$this->assertStringContainsString( 'Choose a gallery in the block settings.', $html );
		$this->assertStringNotContainsString( 'href=', $html );
	}

	public function test_the_variation_without_a_gallery_shows_visitors_nothing(): void {
		$this->assertSame( '', $this->block->render( array( 'linkTo' => 'gallery', 'portal' => 'studio' ) ) );
	}

	/**
	 * @return array<string,array{0:mixed,1:string}>
	 */
	public static function gallery_values(): array {
		return array(
			'share link'      => array( self::SHARE, self::SHARE ),
			'subdomain, http' => array( 'http://studio.profotograaf.nl/share/g/a', 'http://studio.profotograaf.nl/share/g/a' ),
			'trimmed'         => array( ' ' . self::SHARE . "\n", self::SHARE ),
			'empty'           => array( '', '' ),
			'relative'        => array( '/share/g/a', '' ),
			'no host'         => array( 'https:///share/g/a', '' ),
			'javascript'      => array( 'javascript:alert(1)', '' ),
			'ftp'             => array( 'ftp://profotograaf.nl/a', '' ),
			'space inside'    => array( 'https://profotograaf.nl/a b', '' ),
			'not a string'    => array( array( self::SHARE ), '' ),
		);
	}

	/**
	 * @dataProvider gallery_values
	 * @param mixed $value
	 */
	public function test_gallery_url( $value, string $expected ): void {
		$this->assertSame( $expected, Client_Galleries::gallery_url( $value ) );
	}

	public function test_block_json_declares_the_gallery_attributes_and_variation(): void {
		$json = json_decode( (string) file_get_contents( PROFOTOGRAAF_DIR . 'blocks/client-galleries/block.json' ), true );

		$this->assertSame( array( 'type' => 'string', 'default' => '' ), $json['attributes']['gallery'] );
		$this->assertSame( 'portal', $json['attributes']['linkTo']['default'] );
		$this->assertSame( array( 'portal', Client_Galleries::LINK_TO_GALLERY ), $json['attributes']['linkTo']['enum'] );
		$this->assertSame( 'Open a delivered gallery', $json['variations'][0]['title'] );
		$this->assertSame( array( 'linkTo' => Client_Galleries::LINK_TO_GALLERY ), $json['variations'][0]['attributes'] );
	}
}
