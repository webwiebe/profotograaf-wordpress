<?php
/**
 * Copies that need attention: counts, signature, section, filter, column and Site Health.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Modules\Sync_Flags;
use Profotograaf\Photo_Importer;
use Profotograaf\Plugin;
use Profotograaf\Reconcile;
use Profotograaf\Settings;
use Profotograaf\Sync_Attention;

class Sync_Attention_Test extends Wp_Test_Case {

	/**
	 * Post meta by attachment id.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $meta = array();

	private bool $can_upload = true;

	protected function setUp(): void {
		parent::setUp();
		$this->options[ Settings::OPTION ] = array( 'media_source' => true );
		Functions\when( 'get_post_meta' )->alias( fn( $id, $key ) => $this->meta[ $id ][ $key ] ?? '' );
		Functions\when( 'current_user_can' )->alias( fn() => $this->can_upload );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_key' )->alias( fn( $key ) => strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) ) );
		Functions\when( 'admin_url' )->alias( fn( $path = '' ) => 'https://example.com/wp-admin/' . $path );
		Functions\when( 'add_query_arg' )->alias( fn( $args, $url ) => $url . '?' . http_build_query( $args ) );
		Functions\when( '_n' )->alias( fn( $one, $many, $count ) => 1 === $count ? $one : $many );
		Functions\when( 'selected' )->alias( fn( $value, $current ) => $value === $current ? ' selected="selected"' : '' );
	}

	protected function tearDown(): void {
		unset( $_GET['profotograaf_source'], $_REQUEST['query'], $GLOBALS['pagenow'] );
		parent::tearDown();
	}

	private function counts( int $missing, int $changed, int $conflict ): void {
		$this->options[ Reconcile::COUNTS_OPTION ] = array(
			'changed'  => $changed,
			'missing'  => $missing,
			'conflict' => $conflict,
		);
	}

	public function test_the_signature_names_the_three_counts_so_a_new_problem_shows_again(): void {
		$this->counts( 2, 1, 0 );
		$first = Sync_Attention::signature();
		$this->counts( 2, 1, 1 );

		$this->assertSame( 'sync:2:1:0', $first );
		$this->assertSame( 'sync:2:1:1', Sync_Attention::signature() );
		$this->assertSame( 4, Sync_Attention::total() );
		$this->assertSame( 'Profotograaf: 1 imported photo needs attention.', Sync_Attention::message( 1 ) );
		$this->assertSame( 'Profotograaf: 4 imported photos need attention.', Sync_Attention::message( 4 ) );
	}

	public function test_the_column_says_the_state_in_words(): void {
		foreach ( array( 'missing', 'changed', 'conflict', 'kept', '' ) as $state ) {
			$this->assertNotSame( '', Sync_Attention::column_text( $state ) );
		}
		$this->assertSame( 'In sync', Sync_Attention::column_text( '' ) );
		$this->assertSame( 'Changed in both places', Sync_Attention::column_text( 'conflict' ) );
	}

	public function test_the_section_is_empty_without_flagged_copies(): void {
		$this->counts( 0, 0, 0 );

		$this->assertSame( '', Sync_Attention::section( fn() => true ) );
	}

	public function test_the_section_lists_flagged_copies_with_words_a_link_and_a_bulk_button(): void {
		$this->counts( 1, 1, 0 );
		$this->meta[5][ Reconcile::META_STATE ]  = 'missing';
		$this->meta[5][ Reconcile::META_REASON ] = 'deleted';
		$this->meta[6][ Reconcile::META_STATE ]  = 'changed';
		Functions\when( 'get_posts' )->justReturn( array( 5, 6 ) );
		Functions\when( 'get_the_title' )->alias( fn( $id ) => 5 === $id ? 'Spring' : '' );
		Functions\when( 'get_edit_post_link' )->alias( fn( $id ) => 'https://example.com/wp-admin/post.php?post=' . $id );

		$html = Sync_Attention::section( fn( $id ) => 5 === $id );

		$this->assertStringContainsString( '2 imported photos need attention', $html );
		$this->assertStringContainsString( 'Removed on Profotograaf: 1. Changed on Profotograaf: 1. Changed in both places: 0.', $html );
		$this->assertStringContainsString( 'post.php?post=5">Spring</a>', $html );
		$this->assertStringContainsString( 'was deleted on Profotograaf', $html );
		$this->assertStringContainsString( '(no title)', $html );
		$this->assertStringNotContainsString( 'post=6', $html );
		$this->assertStringContainsString( 'data-missing="1" data-sample="Spring"', $html );
		$this->assertStringContainsString( 'mode=list&amp;profotograaf_source=attention', str_replace( '&', '&amp;', $html ) );
	}

	public function test_without_missing_copies_or_the_capability_there_is_no_bulk_button(): void {
		$this->counts( 0, 1, 0 );
		Functions\when( 'get_posts' )->justReturn( array() );

		$this->assertStringNotContainsString( 'profotograaf-bulk__remove', Sync_Attention::section( fn() => true ) );

		$this->counts( 1, 0, 0 );
		$this->can_upload = false;
		$this->assertStringNotContainsString( 'profotograaf-bulk__remove', Sync_Attention::section( fn() => true ) );
	}

	public function test_the_site_health_item_is_good_without_flags_and_recommended_with_them(): void {
		$this->counts( 0, 0, 0 );
		$good = Sync_Attention::health_result();
		$this->counts( 0, 2, 0 );
		$flagged = Sync_Attention::health_result();

		$this->assertSame( 'good', $good['status'] );
		$this->assertSame( 'recommended', $flagged['status'] );
		$this->assertSame( 'orange', $flagged['badge']['color'] );
		$this->assertStringContainsString( 'profotograaf_source=attention', $flagged['actions'] );
		$this->assertSame( Sync_Attention::HEALTH_TEST, $flagged['test'] );
	}

	private function flags(): Sync_Flags {
		$flags = new Sync_Flags();
		$flags->register( new Plugin() );
		return $flags;
	}

	public function test_the_column_shows_only_for_imported_copies_while_the_source_is_on(): void {
		$flags = $this->flags();

		$columns = $flags->add_column( array( 'title' => 'File' ) );
		$this->assertSame( 'Profotograaf', $columns[ Sync_Flags::COLUMN ] );

		$this->meta[3] = array( Photo_Importer::META_PHOTO_ID => 'p', Reconcile::META_STATE => 'missing' );
		ob_start();
		$flags->render_column( Sync_Flags::COLUMN, 3 );
		$flags->render_column( Sync_Flags::COLUMN, 4 );
		$flags->render_column( 'other', 3 );
		$this->assertSame( 'Removed on Profotograaf', ob_get_clean() );

		$this->options[ Settings::OPTION ] = array( 'media_source' => false );
		$this->assertArrayNotHasKey( Sync_Flags::COLUMN, $flags->add_column( array() ) );
	}

	public function test_the_grid_filter_narrows_to_flagged_copies_only_when_asked(): void {
		$flags = $this->flags();
		$this->assertSame( array(), $flags->filter_grid( array() ) );

		$_REQUEST['query'] = array( 'profotograaf_source' => 'attention' );
		$args              = $flags->filter_grid( array( 'meta_query' => array( array( 'key' => 'x' ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- a query argument in a test.

		$this->assertCount( 2, $args['meta_query'] );
		$this->assertSame( Reconcile::META_STATE, $args['meta_query'][1]['key'] );
	}

	public function test_the_list_filter_narrows_the_main_query_on_the_library_screen(): void {
		$flags = $this->flags();
		$query = new class() {
			public array $set = array();

			public function is_main_query(): bool {
				return true;
			}

			public function set( string $key, $value ): void {
				$this->set[ $key ] = $value;
			}
		};

		$GLOBALS['pagenow'] = 'upload.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the filter reads the admin page global.
		$flags->filter_list( $query );
		$this->assertSame( array(), $query->set );

		$_GET['profotograaf_source'] = 'attention';
		$flags->filter_list( $query );
		$this->assertSame( Reconcile::META_STATE, $query->set['meta_query'][0]['key'] );

		$other              = clone $query;
		$other->set         = array();
		$GLOBALS['pagenow'] = 'edit.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the filter reads the admin page global.
		$flags->filter_list( $other );
		$this->assertSame( array(), $other->set );
	}

	public function test_the_list_toolbar_offers_the_filter_for_attachments_only(): void {
		$flags                       = $this->flags();
		$_GET['profotograaf_source'] = 'attention';

		ob_start();
		$flags->list_filter( 'post' );
		$this->assertSame( '', ob_get_clean() );

		ob_start();
		$flags->list_filter( 'attachment' );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'value="attention" selected="selected"', $html );
		$this->assertStringContainsString( 'name="profotograaf_source"', $html );
	}

	public function test_the_site_health_test_is_added_while_the_source_is_on(): void {
		$flags = $this->flags();

		$tests = $flags->add_test( array( 'direct' => array() ) );
		$this->assertArrayHasKey( Sync_Attention::HEALTH_TEST, $tests['direct'] );
		$this->assertSame( 'not an array', $flags->add_test( 'not an array' ) );

		$this->options[ Settings::OPTION ] = array( 'media_source' => false );
		$this->assertSame( array( 'direct' => array() ), $flags->add_test( array( 'direct' => array() ) ) );
	}

	public function test_it_registers_its_hooks(): void {
		$flags = $this->flags();

		$this->assertNotFalse( has_filter( 'manage_media_columns', array( $flags, 'add_column' ) ) );
		$this->assertNotFalse( has_filter( 'ajax_query_attachments_args', array( $flags, 'filter_grid' ) ) );
		$this->assertNotFalse( has_filter( 'site_status_tests', array( $flags, 'add_test' ) ) );
	}
}
