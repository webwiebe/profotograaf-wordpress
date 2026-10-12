<?php
/**
 * Actions on flagged imported copies: keep, watch again, usage and removal.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Media_Baseline;
use Profotograaf\Photo_Importer;
use Profotograaf\Reconcile;
use Profotograaf\Sync_Actions;
use Profotograaf\Sync_Actions_Rest;
use Profotograaf\Sync_Field;

/**
 * Just enough of a REST request.
 */
class Sync_Actions_Request {

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $params Params.
	 */
	public function __construct( private array $params = array() ) {}

	/**
	 * Param.
	 *
	 * @param string $name Name.
	 */
	public function get_param( $name ) {
		return $this->params[ $name ] ?? null;
	}
}

class Sync_Actions_Test extends Wp_Test_Case {

	/**
	 * Post meta by attachment id.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $meta = array();

	/**
	 * Attachment ids wp_delete_attachment() was called with.
	 *
	 * @var array<int,int>
	 */
	private array $deleted = array();

	/**
	 * Ids the capability check allows.
	 *
	 * @var array<int,int>
	 */
	private array $editable = array();

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_post_type' )->justReturn( 'attachment' );
		Functions\when( 'get_post_meta' )->alias( fn( $id, $key ) => $this->meta[ $id ][ $key ] ?? '' );
		Functions\when( 'update_post_meta' )->alias(
			function ( $id, $key, $value ) {
				$this->meta[ $id ][ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_post_meta' )->alias(
			function ( $id, $key ) {
				unset( $this->meta[ $id ][ $key ] );
				return true;
			}
		);
		Functions\when( 'wp_delete_attachment' )->alias(
			function ( $id ) {
				$this->deleted[] = $id;
				return (object) array( 'ID' => $id );
			}
		);
		Functions\when( 'current_user_can' )->alias( fn( $cap, $id = 0 ) => 'upload_files' === $cap || in_array( $id, $this->editable, true ) );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
	}

	/**
	 * Makes an imported copy.
	 *
	 * @param int    $id    Attachment id.
	 * @param string $state Sync state.
	 */
	private function copy( int $id, string $state = '' ): void {
		$this->meta[ $id ] = array( Photo_Importer::META_PHOTO_ID => 'p' . $id );
		if ( '' !== $state ) {
			$this->meta[ $id ][ Reconcile::META_STATE ]  = $state;
			$this->meta[ $id ][ Reconcile::META_REASON ] = Reconcile::REASON_DELETED;
		}
	}

	public function test_keep_sets_kept_records_the_decision_and_moves_the_count(): void {
		$this->options[ Reconcile::COUNTS_OPTION ] = array(
			'changed'  => 0,
			'missing'  => 2,
			'conflict' => 0,
		);
		$this->copy( 11, Reconcile::STATE_MISSING );

		$result = Sync_Actions::keep( 11, 7 );

		$this->assertSame( Reconcile::STATE_KEPT, $result['state'] );
		$this->assertSame( Reconcile::STATE_KEPT, $this->meta[11][ Reconcile::META_STATE ] );
		$this->assertArrayNotHasKey( Reconcile::META_REASON, $this->meta[11] );
		$this->assertSame( 7, $this->meta[11][ Sync_Actions::META_DECISION ]['user'] );
		$this->assertSame( 1, Reconcile::counts()['missing'] );
	}

	public function test_keep_on_a_synced_copy_stops_watching_without_touching_the_counts(): void {
		$this->copy( 12 );

		Sync_Actions::keep( 12, 7 );

		$this->assertSame( Reconcile::STATE_KEPT, $this->meta[12][ Reconcile::META_STATE ] );
		$this->assertSame( 0, Reconcile::counts()['missing'] );
	}

	public function test_watch_again_clears_kept(): void {
		$this->copy( 13, Reconcile::STATE_KEPT );
		$this->meta[13][ Sync_Actions::META_DECISION ] = array( 'action' => 'keep' );

		$result = Sync_Actions::watch_again( 13 );

		$this->assertSame( Reconcile::STATE_SYNCED, $result['state'] );
		$this->assertArrayNotHasKey( Reconcile::META_STATE, $this->meta[13] );
		$this->assertArrayNotHasKey( Sync_Actions::META_DECISION, $this->meta[13] );
	}

	public function test_files_that_are_no_imported_copies_are_refused(): void {
		$this->meta[20] = array();
		$this->meta[21] = array(
			Photo_Importer::META_PHOTO_ID => 'p',
			Media_Baseline::META_ORIGIN   => Media_Baseline::ORIGIN_UPLOAD,
		);

		$this->assertInstanceOf( \WP_Error::class, Sync_Actions::keep( 20, 7 ) );
		$this->assertInstanceOf( \WP_Error::class, Sync_Actions::remove( 21 ) );
		$this->assertSame( array(), $this->deleted );
	}

	public function test_remove_deletes_the_attachment_and_lowers_the_count(): void {
		$this->options[ Reconcile::COUNTS_OPTION ] = array(
			'changed'  => 0,
			'missing'  => 1,
			'conflict' => 0,
		);
		$this->copy( 14, Reconcile::STATE_MISSING );

		$result = Sync_Actions::remove( 14 );

		$this->assertSame( array( 14 ), $this->deleted );
		$this->assertSame( 14, $result['attachment_id'] );
		$this->assertSame( 0, Reconcile::counts()['missing'] );
	}

	public function test_a_failed_delete_leaves_the_counts_alone(): void {
		Functions\when( 'wp_delete_attachment' )->justReturn( false );
		$this->options[ Reconcile::COUNTS_OPTION ] = array(
			'changed'  => 0,
			'missing'  => 1,
			'conflict' => 0,
		);
		$this->copy( 15, Reconcile::STATE_MISSING );

		$this->assertInstanceOf( \WP_Error::class, Sync_Actions::remove( 15 ) );
		$this->assertSame( 1, Reconcile::counts()['missing'] );
	}

	public function test_the_rest_route_refuses_removal_without_confirmation(): void {
		$this->copy( 16, Reconcile::STATE_MISSING );
		$rest = new Sync_Actions_Rest();

		$one  = $rest->remove( new Sync_Actions_Request( array( 'attachment_id' => 16 ) ) );
		$many = $rest->remove_missing( new Sync_Actions_Request() );

		$this->assertInstanceOf( \WP_Error::class, $one );
		$this->assertInstanceOf( \WP_Error::class, $many );
		$this->assertSame( array(), $this->deleted );
	}

	public function test_the_rest_route_needs_edit_post_on_the_attachment(): void {
		$rest           = new Sync_Actions_Rest();
		$this->editable = array( 17 );

		$this->assertTrue( $rest->can_edit( new Sync_Actions_Request( array( 'attachment_id' => 17 ) ) ) );
		$this->assertFalse( $rest->can_edit( new Sync_Actions_Request( array( 'attachment_id' => 18 ) ) ) );
	}

	public function test_bulk_removal_takes_one_batch_and_skips_what_the_user_may_not_edit(): void {
		$this->options[ Reconcile::COUNTS_OPTION ] = array(
			'changed'  => 0,
			'missing'  => 3,
			'conflict' => 0,
		);
		foreach ( array( 31, 32, 33 ) as $id ) {
			$this->copy( $id, Reconcile::STATE_MISSING );
		}
		$seen = array();
		Functions\when( 'get_posts' )->alias(
			function ( $args ) use ( &$seen ) {
				$seen = $args;
				return array( 31, 32, 33 );
			}
		);

		$result = Sync_Actions::remove_missing_batch( static fn( int $id ): bool => 32 !== $id );

		$this->assertSame( Sync_Actions::BATCH, $seen['posts_per_page'] );
		$this->assertSame( Reconcile::STATE_MISSING, $seen['meta_value'] );
		$this->assertSame( array( 31, 33 ), $this->deleted );
		$this->assertSame( 2, $result['removed'] );
		$this->assertSame( 1, $result['skipped'] );
		// The skipped copy is still flagged but this user cannot remove it, so nothing is left for another request.
		$this->assertSame( 0, $result['remaining'] );
	}

	public function test_usage_counts_posts_and_caps_the_answer(): void {
		$this->copy( 40 );
		$GLOBALS['wpdb'] = new class() { // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the usage search reads the global.
			public string $posts    = 'wp_posts';
			public string $postmeta = 'wp_postmeta';
			public string $sql      = '';

			public function esc_like( string $text ): string {
				return addcslashes( $text, '_%\\' );
			}

			public function prepare( string $sql, array $args ): string {
				$this->sql = $sql;
				return $sql . '|' . implode( '|', $args );
			}

			public function get_col( string $query ): array {
				return array_fill( 0, Sync_Actions::USAGE_CAP + 1, '1' );
			}
		};

		$usage = Sync_Actions::usage( 40 );

		$this->assertSame( Sync_Actions::USAGE_CAP, $usage['count'] );
		$this->assertTrue( $usage['capped'] );
		$this->assertStringContainsString( '_thumbnail_id', $GLOBALS['wpdb']->sql );
		unset( $GLOBALS['wpdb'] );
	}

	public function test_the_field_names_the_state_in_words_and_offers_the_buttons(): void {
		$this->copy( 50, Reconcile::STATE_MISSING );

		$html = Sync_Field::html( 50, true );

		$this->assertStringContainsString( 'role="status"', $html );
		$this->assertStringContainsString( 'was deleted on Profotograaf', $html );
		$this->assertStringContainsString( 'data-action="keep"', $html );
		$this->assertStringContainsString( 'data-action="remove"', $html );
		$this->assertStringNotContainsString( 'button', Sync_Field::html( 50, false ) );
	}

	public function test_the_field_offers_stop_watching_for_a_synced_copy_and_watch_again_for_a_kept_one(): void {
		$this->copy( 51 );
		$this->copy( 52, Reconcile::STATE_KEPT );

		$this->assertStringContainsString( 'Stop watching', Sync_Field::html( 51, true ) );
		$this->assertStringContainsString( 'Watch again', Sync_Field::html( 52, true ) );
	}
}
