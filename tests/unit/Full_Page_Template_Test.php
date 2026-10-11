<?php
/**
 * Tests for the full page template.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Full_Page_Template;
use Profotograaf\Modules\Full_Page_Template as Template_Module;
use Profotograaf\Plugin;

class Full_Page_Template_Test extends Wp_Test_Case {

	public function test_a_block_theme_gets_a_block_template_for_pages_and_posts(): void {
		Functions\when( 'wp_is_block_theme' )->justReturn( true );
		$registered = array();
		Functions\when( 'register_block_template' )->alias(
			function ( $name, $args ) use ( &$registered ) {
				$registered = array( $name, $args );
			}
		);

		( new Full_Page_Template() )->register_block_template();

		$this->assertSame( 'profotograaf//full-page', $registered[0] );
		$this->assertSame( 'Profotograaf full page', $registered[1]['title'] );
		$this->assertSame( array( 'page', 'post' ), $registered[1]['post_types'] );
		$this->assertStringContainsString( '<!-- wp:post-content', $registered[1]['content'] );
		$this->assertStringNotContainsString( 'wp:template-part', $registered[1]['content'] );
	}

	public function test_a_classic_theme_registers_no_block_template(): void {
		Functions\when( 'wp_is_block_theme' )->justReturn( false );
		Functions\expect( 'register_block_template' )->never();

		( new Full_Page_Template() )->register_block_template();
		$this->assertTrue( true );
	}

	public function test_the_template_list_gains_the_template_on_a_classic_theme_only(): void {
		Functions\when( 'wp_is_block_theme' )->justReturn( false );
		$list = ( new Full_Page_Template() )->add_to_list( array( 'wide.php' => 'Wide' ) );
		$this->assertSame( 'Profotograaf full page', $list[ Full_Page_Template::SLUG ] );
		$this->assertSame( 'Wide', $list['wide.php'] );

		Functions\when( 'wp_is_block_theme' )->justReturn( true );
		$this->assertSame( array(), ( new Full_Page_Template() )->add_to_list( array() ) );
	}

	public function test_a_page_using_the_template_gets_the_plugin_file(): void {
		Functions\when( 'wp_is_block_theme' )->justReturn( false );
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_page_template_slug' )->justReturn( Full_Page_Template::SLUG );

		$path = ( new Full_Page_Template() )->resolve( '/theme/page.php' );

		$this->assertSame( PROFOTOGRAAF_DIR . 'templates/full-page.php', $path );
		$this->assertFileExists( $path );
	}

	public function test_other_pages_and_block_themes_keep_their_template(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_page_template_slug' )->justReturn( 'other.php' );
		Functions\when( 'wp_is_block_theme' )->justReturn( false );
		$this->assertSame( '/theme/page.php', ( new Full_Page_Template() )->resolve( '/theme/page.php' ) );

		Functions\when( 'get_page_template_slug' )->justReturn( Full_Page_Template::SLUG );
		Functions\when( 'wp_is_block_theme' )->justReturn( true );
		$this->assertSame( '/theme/page.php', ( new Full_Page_Template() )->resolve( '/theme/page.php' ) );

		Functions\when( 'wp_is_block_theme' )->justReturn( false );
		Functions\when( 'is_singular' )->justReturn( false );
		$this->assertSame( '/theme/page.php', ( new Full_Page_Template() )->resolve( '/theme/page.php' ) );
	}

	public function test_the_module_hooks_everything(): void {
		( new Template_Module() )->register( new Plugin() );

		$this->assertNotFalse( has_action( 'init' ) );
		$this->assertNotFalse( has_filter( 'theme_page_templates' ) );
		$this->assertNotFalse( has_filter( 'theme_post_templates' ) );
		$this->assertNotFalse( has_filter( 'template_include' ) );
	}
}
