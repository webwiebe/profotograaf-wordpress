<?php
/**
 * Reconcile engine tests.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use Brain\Monkey\Functions;
use Profotograaf\Media_Baseline;
use Profotograaf\Reconcile;

class Reconcile_Test extends Wp_Test_Case {

	/**
	 * Post meta by attachment id.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $meta = array();

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'sanitize_text_field' )->alias( fn( $value ) => trim( strip_tags( (string) $value ) ) );
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
	}

	private static function base_hash( string $title = 'T', string $caption = 'C', string $alt = 'A' ): string {
		return Media_Baseline::text_hash( $title, $caption, $alt );
	}

	/**
	 * An imported copy row.
	 *
	 * @param array<string,mixed> $over Overrides.
	 * @return array<string,mixed>
	 */
	private static function row( array $over = array() ): array {
		return array_merge(
			array(
				'attachment_id'  => 11,
				'photo_id'       => 'p1',
				'gallery_id'     => 'g1',
				'version'        => 'aaaaaaaaaaaa',
				'state'          => '',
				'reason'         => '',
				'since'          => 0,
				'remote_version' => '',
				'text_base'      => self::base_hash(),
				'text_now'       => self::base_hash(),
				'file_edited'    => false,
				'text_edited'    => false,
			),
			$over
		);
	}

	/**
	 * A catalogue photo.
	 *
	 * @param array<string,mixed> $over Overrides.
	 * @return array<string,mixed>
	 */
	private static function photo( array $over = array() ): array {
		return array_merge(
			array(
				'id'         => 'p1',
				'gallery_id' => 'g1',
				'title'      => 'T',
				'caption'    => 'C',
				'alt'        => 'A',
				'version'    => 'aaaaaaaaaaaa',
			),
			$over
		);
	}

	/**
	 * A catalogue that lists the given photos.
	 *
	 * @param array<int,array<string,mixed>> $photos  Photos.
	 * @param int                            $dropped Photos left out.
	 * @return array<string,mixed>
	 */
	private static function catalogue( array $photos, int $dropped = 0 ): array {
		return array(
			'photos'  => $photos,
			'stale'   => false,
			'dropped' => $dropped,
		);
	}

	/**
	 * A catalogue without p1.
	 *
	 * @param int $dropped Photos the catalogue left out.
	 * @return array<string,mixed>
	 */
	private static function without_p1( int $dropped = 0 ): array {
		return self::catalogue(
			array(
				self::photo(
					array(
						'id'         => 'other',
						'gallery_id' => 'g9',
					)
				),
			),
			$dropped
		);
	}

	/**
	 * Feed that reports p1 deleted.
	 *
	 * @param string $gallery Gallery the photo left, empty for a library deletion.
	 * @return array<string,mixed>
	 */
	private static function deleted_feed( string $gallery = '' ): array {
		return array(
			'photos_deleted' => array(
				array(
					'id'         => 'p1',
					'gallery_id' => $gallery,
				),
			),
		);
	}

	/**
	 * Plans one run.
	 *
	 * @param array<string,mixed> $over Input overrides.
	 * @return array<string,mixed>
	 */
	private function plan( array $over = array() ): array {
		return Reconcile::plan(
			array_merge(
				array(
					'rows'      => array( self::row() ),
					'catalogue' => self::catalogue( array( self::photo() ) ),
					'galleries' => array(
						array(
							'id'         => 'g1',
							'embeddable' => true,
							'available'  => true,
						),
					),
					'feed'      => null,
					'now'       => 5000,
				),
				$over
			)
		);
	}

	public function test_an_untouched_listed_copy_has_no_change(): void {
		$plan = $this->plan();
		$this->assertSame( array(), $plan['changes'] );
		$this->assertSame( '', $plan['skipped'] );
	}

	public function test_a_new_file_version_marks_the_copy_changed(): void {
		$plan = $this->plan( array( 'catalogue' => self::catalogue( array( self::photo( array( 'version' => 'bbbbbbbbbbbb' ) ) ) ) ) );

		$change = $plan['changes'][11];
		$this->assertSame( 'changed', $change['state'] );
		$this->assertSame( 'bbbbbbbbbbbb', $change['remote_version'] );
		$this->assertSame( 'synced', $change['from'] );
		$this->assertSame( 5000, $change['since'] );
	}

	public function test_new_platform_text_marks_the_copy_changed_without_a_remote_version(): void {
		$catalogue = self::catalogue( array( self::photo( array( 'caption' => 'New caption' ) ) ) );

		$change = $this->plan( array( 'catalogue' => $catalogue ) )['changes'][11];

		$this->assertSame( 'changed', $change['state'] );
		$this->assertSame( '', $change['remote_version'] );
	}

	public function test_the_title_stands_in_for_an_empty_platform_alt(): void {
		$row       = self::row(
			array(
				'text_base' => self::base_hash( 'T', 'C', 'T' ),
				'text_now'  => self::base_hash( 'T', 'C', 'T' ),
			)
		);
		$catalogue = self::catalogue( array( self::photo( array( 'alt' => '' ) ) ) );

		$this->assertSame( array(), $this->plan( array( 'rows' => array( $row ), 'catalogue' => $catalogue ) )['changes'] );
	}

	public function test_text_that_wordpress_rewrote_on_insert_is_not_a_platform_change(): void {
		$row = self::row(
			array(
				'text_base' => self::base_hash( 'Rewritten', 'C', 'A' ),
				'text_now'  => self::base_hash( 'T', 'C', 'A' ),
			)
		);

		$this->assertSame( array(), $this->plan( array( 'rows' => array( $row ) ) )['changes'] );
	}

	public function test_a_local_edit_alone_stays_synced(): void {
		$row = self::row(
			array(
				'file_edited' => true,
				'text_edited' => true,
				'text_now'    => self::base_hash( 'Mine', 'C', 'A' ),
			)
		);

		$this->assertSame( array(), $this->plan( array( 'rows' => array( $row ) ) )['changes'] );
	}

	public function test_a_platform_change_over_a_local_edit_is_a_conflict(): void {
		$row       = self::row( array( 'file_edited' => true ) );
		$catalogue = self::catalogue( array( self::photo( array( 'version' => 'bbbbbbbbbbbb' ) ) ) );

		$change = $this->plan( array( 'rows' => array( $row ), 'catalogue' => $catalogue ) )['changes'][11];

		$this->assertSame( 'conflict', $change['state'] );
		$this->assertSame( 'bbbbbbbbbbbb', $change['remote_version'] );
	}

	public function test_a_text_edit_with_a_platform_text_change_is_a_conflict(): void {
		$row       = self::row(
			array(
				'text_edited' => true,
				'text_now'    => self::base_hash( 'Mine', 'C', 'A' ),
			)
		);
		$catalogue = self::catalogue( array( self::photo( array( 'title' => 'Theirs' ) ) ) );

		$this->assertSame( 'conflict', $this->plan( array( 'rows' => array( $row ), 'catalogue' => $catalogue ) )['changes'][11]['state'] );
	}

	public function test_a_change_that_the_platform_reverted_returns_to_synced(): void {
		$row = self::row(
			array(
				'state'          => 'changed',
				'since'          => 100,
				'remote_version' => 'bbbbbbbbbbbb',
			)
		);

		$change = $this->plan( array( 'rows' => array( $row ) ) )['changes'][11];

		$this->assertSame( 'synced', $change['state'] );
		$this->assertSame( 'changed', $change['from'] );
	}

	public function test_a_state_that_holds_keeps_its_date(): void {
		$row       = self::row(
			array(
				'state'          => 'changed',
				'since'          => 100,
				'remote_version' => 'bbbbbbbbbbbb',
			)
		);
		$catalogue = self::catalogue( array( self::photo( array( 'version' => 'cccccccccccc' ) ) ) );

		$change = $this->plan( array( 'rows' => array( $row ), 'catalogue' => $catalogue ) )['changes'][11];

		$this->assertSame( 100, $change['since'] );
		$this->assertSame( 'cccccccccccc', $change['remote_version'] );
	}

	public function test_a_copy_in_a_state_that_already_matches_has_no_change(): void {
		$row       = self::row(
			array(
				'state'          => 'changed',
				'since'          => 100,
				'remote_version' => 'bbbbbbbbbbbb',
			)
		);
		$catalogue = self::catalogue( array( self::photo( array( 'version' => 'bbbbbbbbbbbb' ) ) ) );

		$this->assertSame( array(), $this->plan( array( 'rows' => array( $row ), 'catalogue' => $catalogue ) )['changes'] );
	}

	public function test_kept_copies_are_never_touched(): void {
		$row = self::row( array( 'state' => 'kept' ) );

		$this->assertSame( array(), $this->plan( array( 'rows' => array( $row ), 'catalogue' => self::without_p1(), 'feed' => self::deleted_feed() ) )['changes'] );
	}

	public function test_a_photo_listed_in_another_gallery_updates_the_gallery_and_is_not_missing(): void {
		$catalogue = self::catalogue( array( self::photo( array( 'gallery_id' => 'g2' ) ) ) );

		$change = $this->plan( array( 'catalogue' => $catalogue, 'feed' => self::deleted_feed( 'g1' ) ) )['changes'][11];

		$this->assertSame( 'synced', $change['state'] );
		$this->assertSame( 'g2', $change['gallery_id'] );
	}

	public function test_reason_deleted(): void {
		$change = $this->plan( array( 'catalogue' => self::without_p1(), 'feed' => self::deleted_feed() ) )['changes'][11];

		$this->assertSame( 'missing', $change['state'] );
		$this->assertSame( 'deleted', $change['reason'] );
	}

	public function test_reason_deleted_needs_no_complete_catalogue(): void {
		$this->assertSame( 'deleted', $this->plan( array( 'catalogue' => self::without_p1( 40 ), 'feed' => self::deleted_feed() ) )['changes'][11]['reason'] );
	}

	public function test_reason_left_gallery(): void {
		$change = $this->plan( array( 'catalogue' => self::without_p1(), 'feed' => self::deleted_feed( 'g1' ) ) )['changes'][11];

		$this->assertSame( 'missing', $change['state'] );
		$this->assertSame( 'left_gallery', $change['reason'] );
	}

	public function test_left_gallery_is_not_decided_from_a_truncated_catalogue(): void {
		$plan = $this->plan( array( 'catalogue' => self::without_p1( 5 ), 'feed' => self::deleted_feed( 'g1' ) ) );

		$this->assertSame( array(), $plan['changes'] );
	}

	public function test_reason_gallery_deleted_from_the_feed(): void {
		$feed = array( 'galleries_deleted' => array( 'g1' ) );

		$this->assertSame( 'gallery_deleted', $this->plan( array( 'catalogue' => self::without_p1(), 'feed' => $feed ) )['changes'][11]['reason'] );
	}

	public function test_reason_gallery_deleted_from_a_gallery_list_without_the_gallery(): void {
		$galleries = array(
			array(
				'id'         => 'g9',
				'embeddable' => true,
				'available'  => true,
			),
		);

		$this->assertSame( 'gallery_deleted', $this->plan( array( 'catalogue' => self::without_p1(), 'galleries' => $galleries ) )['changes'][11]['reason'] );
	}

	public function test_a_full_gallery_list_proves_nothing(): void {
		$galleries = array();
		for ( $i = 0; $i < Reconcile::GALLERY_LIST_CAP; $i++ ) {
			$galleries[] = array(
				'id'         => 'x' . $i,
				'embeddable' => true,
				'available'  => true,
			);
		}

		$plan = $this->plan( array( 'catalogue' => self::without_p1(), 'galleries' => $galleries ) );

		$this->assertSame( array(), $plan['changes'] );
		$this->assertSame( array( 'g1' ), $plan['needs_lookup'] );
	}

	public function test_a_failed_gallery_list_does_not_flag_a_gallery(): void {
		$plan = $this->plan( array( 'catalogue' => self::without_p1(), 'galleries' => null ) );

		$this->assertSame( array(), $plan['changes'] );
		$this->assertSame( array( 'g1' ), $plan['needs_lookup'] );
	}

	/**
	 * @dataProvider not_serving
	 * @param array<string,bool> $flags Gallery flags.
	 */
	public function test_reason_unavailable( array $flags ): void {
		$galleries = array( array_merge( array( 'id' => 'g1' ), $flags ) );

		$change = $this->plan( array( 'catalogue' => self::without_p1(), 'galleries' => $galleries ) )['changes'][11];

		$this->assertSame( 'missing', $change['state'] );
		$this->assertSame( 'unavailable', $change['reason'] );
	}

	/**
	 * @return array<string,array<int,array<string,bool>>>
	 */
	public static function not_serving(): array {
		return array(
			'not available'  => array(
				array(
					'embeddable' => true,
					'available'  => false,
				),
			),
			'not embeddable' => array(
				array(
					'embeddable' => false,
					'available'  => true,
				),
			),
		);
	}

	public function test_an_unexplained_absence_asks_for_a_lookup_and_changes_nothing(): void {
		$plan = $this->plan( array( 'catalogue' => self::without_p1() ) );

		$this->assertSame( array(), $plan['changes'] );
		$this->assertSame( array( 'g1' ), $plan['needs_lookup'] );
	}

	public function test_reason_unknown_after_a_lookup_that_lacks_the_photo(): void {
		$plan = $this->plan( array( 'catalogue' => self::without_p1(), 'gallery_photos' => array( 'g1' => array( 'other' ) ) ) );

		$this->assertSame( 'unknown', $plan['changes'][11]['reason'] );
		$this->assertSame( array(), $plan['needs_lookup'] );
	}

	public function test_a_lookup_that_has_the_photo_clears_the_doubt(): void {
		$plan = $this->plan( array( 'catalogue' => self::without_p1( 3 ), 'gallery_photos' => array( 'g1' => array( 'p1' ) ) ) );

		$this->assertSame( array(), $plan['changes'] );
	}

	public function test_a_failed_lookup_flags_nothing(): void {
		$plan = $this->plan( array( 'catalogue' => self::without_p1(), 'gallery_photos' => array( 'g1' => false ) ) );

		$this->assertSame( array(), $plan['changes'] );
	}

	public function test_a_truncated_catalogue_still_flags_after_a_lookup(): void {
		$plan = $this->plan( array( 'catalogue' => self::without_p1( 8 ), 'gallery_photos' => array( 'g1' => array() ) ) );

		$this->assertSame( 'unknown', $plan['changes'][11]['reason'] );
	}

	public function test_a_stale_catalogue_decides_nothing(): void {
		$catalogue          = self::without_p1();
		$catalogue['stale'] = true;

		$plan = $this->plan( array( 'catalogue' => $catalogue, 'feed' => self::deleted_feed() ) );

		$this->assertSame( array(), $plan['changes'] );
		$this->assertSame( 'stale_catalogue', $plan['skipped'] );
	}

	public function test_a_paused_account_decides_nothing(): void {
		$plan = $this->plan( array( 'catalogue' => self::without_p1(), 'paused' => true ) );

		$this->assertSame( array(), $plan['changes'] );
		$this->assertSame( 'paused', $plan['skipped'] );
	}

	public function test_after_a_pairing_an_account_that_lists_no_copy_pauses_instead_of_flagging(): void {
		$plan = $this->plan( array( 'catalogue' => self::without_p1(), 'feed' => self::deleted_feed(), 'after_pairing' => true ) );

		$this->assertSame( array(), $plan['changes'] );
		$this->assertSame( 'account_changed', $plan['skipped'] );
	}

	public function test_after_a_pairing_an_account_that_lists_a_copy_is_reconciled(): void {
		$plan = $this->plan( array( 'after_pairing' => true ) );

		$this->assertSame( '', $plan['skipped'] );
	}

	/**
	 * @dataProvider clears_on_its_own
	 */
	public function test_unavailable_and_left_gallery_clear_when_the_photo_is_listed_again( string $reason ): void {
		$row = self::row(
			array(
				'state'  => 'missing',
				'reason' => $reason,
				'since'  => 100,
			)
		);

		$this->assertSame( 'synced', $this->plan( array( 'rows' => array( $row ) ) )['changes'][11]['state'] );
	}

	/**
	 * @return array<string,array<int,string>>
	 */
	public static function clears_on_its_own(): array {
		return array(
			'unavailable'  => array( 'unavailable' ),
			'left_gallery' => array( 'left_gallery' ),
		);
	}

	/**
	 * @dataProvider waits_for_the_author
	 */
	public function test_deleted_gallery_deleted_and_unknown_wait_for_the_author( string $reason ): void {
		$row = self::row(
			array(
				'state'  => 'missing',
				'reason' => $reason,
				'since'  => 100,
			)
		);

		$this->assertSame( array(), $this->plan( array( 'rows' => array( $row ) ) )['changes'] );
	}

	/**
	 * @dataProvider waits_for_the_author
	 */
	public function test_a_later_created_or_updated_entry_clears_any_reason( string $reason ): void {
		$row  = self::row(
			array(
				'state'  => 'missing',
				'reason' => $reason,
				'since'  => 100,
			)
		);
		$feed = array_merge( self::deleted_feed(), array( 'photos_changed' => array( 'p1' ) ) );

		$this->assertSame( 'synced', $this->plan( array( 'rows' => array( $row ), 'feed' => $feed ) )['changes'][11]['state'] );
	}

	/**
	 * @return array<string,array<int,string>>
	 */
	public static function waits_for_the_author(): array {
		return array(
			'deleted'         => array( 'deleted' ),
			'gallery_deleted' => array( 'gallery_deleted' ),
			'unknown'         => array( 'unknown' ),
		);
	}

	public function test_a_missing_copy_keeps_its_date_when_the_reason_changes(): void {
		$row  = self::row(
			array(
				'state'  => 'missing',
				'reason' => 'unavailable',
				'since'  => 100,
			)
		);
		$feed = array( 'galleries_deleted' => array( 'g1' ) );

		$change = $this->plan( array( 'rows' => array( $row ), 'catalogue' => self::without_p1(), 'feed' => $feed ) )['changes'][11];

		$this->assertSame( 'gallery_deleted', $change['reason'] );
		$this->assertSame( 100, $change['since'] );
	}

	public function test_a_created_entry_for_an_unlisted_photo_is_not_a_deletion(): void {
		$feed = array_merge( self::deleted_feed(), array( 'photos_changed' => array( 'p1' ) ) );

		$this->assertSame( array(), $this->plan( array( 'catalogue' => self::without_p1(), 'feed' => $feed ) )['changes'] );
	}

	public function test_apply_writes_meta_and_counts_and_never_deletes_content(): void {
		Functions\expect( 'wp_delete_attachment' )->never();
		Functions\expect( 'wp_delete_file' )->never();
		Functions\expect( 'wp_delete_post' )->never();

		$this->options[ Reconcile::COUNTS_OPTION ] = array(
			'changed'  => 1,
			'missing'  => 0,
			'conflict' => 0,
		);
		$this->meta[12]                            = array( '_profotograaf_sync_state' => 'changed' );

		$counts = Reconcile::apply(
			array(
				11 => array(
					'from'           => 'synced',
					'state'          => 'missing',
					'reason'         => 'deleted',
					'since'          => 5000,
					'remote_version' => '',
					'gallery_id'     => '',
				),
				12 => array(
					'from'           => 'changed',
					'state'          => 'synced',
					'reason'         => '',
					'since'          => 5000,
					'remote_version' => '',
					'gallery_id'     => 'g2',
				),
			)
		);

		$this->assertSame(
			array(
				'changed'  => 0,
				'missing'  => 1,
				'conflict' => 0,
			),
			$counts
		);
		$this->assertSame( $counts, $this->options[ Reconcile::COUNTS_OPTION ] );
		$this->assertFalse( $this->autoload[ Reconcile::COUNTS_OPTION ] );
		$this->assertSame( 'missing', $this->meta[11]['_profotograaf_sync_state'] );
		$this->assertSame( 'deleted', $this->meta[11]['_profotograaf_sync_reason'] );
		$this->assertSame( 5000, $this->meta[11]['_profotograaf_sync_since'] );
		$this->assertArrayNotHasKey( '_profotograaf_sync_state', $this->meta[12] );
		$this->assertSame( 'g2', $this->meta[12]['_profotograaf_gallery_id'] );
	}

	public function test_applying_the_same_plan_twice_counts_once(): void {
		$changes = array(
			11 => array(
				'from'           => 'synced',
				'state'          => 'missing',
				'reason'         => 'deleted',
				'since'          => 5000,
				'remote_version' => '',
				'gallery_id'     => '',
			),
		);

		Reconcile::apply( $changes );
		$counts = Reconcile::apply( $changes );

		$this->assertSame( 1, $counts['missing'] );
	}

	public function test_apply_does_not_overwrite_a_copy_the_author_kept_meanwhile(): void {
		$this->meta[11] = array( '_profotograaf_sync_state' => 'kept' );

		Reconcile::apply(
			array(
				11 => array(
					'from'           => 'synced',
					'state'          => 'missing',
					'reason'         => 'deleted',
					'since'          => 5000,
					'remote_version' => '',
					'gallery_id'     => '',
				),
			)
		);

		$this->assertSame( 'kept', $this->meta[11]['_profotograaf_sync_state'] );
		$this->assertSame( 0, Reconcile::counts()['missing'] );
	}

	public function test_apply_without_changes_leaves_the_counts_alone(): void {
		Reconcile::apply( array() );

		$this->assertArrayNotHasKey( Reconcile::COUNTS_OPTION, $this->options );
	}

	public function test_counts_never_go_below_zero(): void {
		$counts = Reconcile::apply(
			array(
				11 => array(
					'from'           => 'conflict',
					'state'          => 'synced',
					'reason'         => '',
					'since'          => 1,
					'remote_version' => '',
					'gallery_id'     => '',
				),
			)
		);

		$this->assertSame( 0, $counts['conflict'] );
	}

	public function test_rows_read_the_baseline_and_detect_local_edits(): void {
		$file = (string) tempnam( sys_get_temp_dir(), 'pfrc' );
		file_put_contents( $file, 'edited' );
		Functions\when( 'wp_get_original_image_path' )->justReturn( $file );
		Functions\when( 'get_post' )->alias(
			function () {
				$post               = new \WP_Post();
				$post->post_title   = 'Mine';
				$post->post_excerpt = 'C';
				return $post;
			}
		);
		$this->meta[11] = array(
			'_profotograaf_photo_id'   => 'p1',
			'_profotograaf_gallery_id' => 'g1',
			'_profotograaf_version'    => 'aaaaaaaaaaaa',
			'_profotograaf_file_hash'  => sha1( 'original' ),
			'_profotograaf_text_hash'  => self::base_hash(),
			'_wp_attachment_image_alt' => 'A',
		);
		$this->meta[12] = array( '_profotograaf_photo_id' => 'p2' );

		$rows = Reconcile::rows( array( 11, 12, 13 ) );
		unlink( $file );

		$this->assertCount( 2, $rows );
		$this->assertTrue( $rows[0]['file_edited'] );
		$this->assertTrue( $rows[0]['text_edited'] );
		$this->assertSame( '', $rows[0]['state'] );
		$this->assertFalse( $rows[1]['file_edited'], 'A copy without a baseline counts as unedited.' );
		$this->assertFalse( $rows[1]['text_edited'] );
	}

	public function test_rows_hash_only_the_copies_the_platform_lists_with_a_change(): void {
		$same    = (string) tempnam( sys_get_temp_dir(), 'pfrc' );
		$changed = (string) tempnam( sys_get_temp_dir(), 'pfrc' );
		file_put_contents( $same, 'edited' );
		file_put_contents( $changed, 'edited' );
		$hashed = array();
		Functions\when( 'wp_get_original_image_path' )->alias(
			function ( $id ) use ( $same, $changed, &$hashed ) {
				$hashed[] = $id;
				return 11 === $id ? $same : $changed;
			}
		);
		Functions\when( 'get_post' )->alias(
			function () {
				$post               = new \WP_Post();
				$post->post_title   = 'T';
				$post->post_excerpt = 'C';
				return $post;
			}
		);
		foreach ( array(
			11 => 'p1',
			12 => 'p2',
			13 => 'p3',
		) as $id => $photo ) {
			$this->meta[ $id ] = array(
				'_profotograaf_photo_id'   => $photo,
				'_profotograaf_version'    => 'aaaaaaaaaaaa',
				'_profotograaf_file_hash'  => sha1( 'original' ),
				'_profotograaf_text_hash'  => self::base_hash(),
				'_wp_attachment_image_alt' => 'A',
			);
		}
		$listed = array(
			'p1' => self::photo(
				array(
					'id'      => 'p1',
					'version' => 'aaaaaaaaaaaa',
				)
			),
			'p2' => self::photo(
				array(
					'id'      => 'p2',
					'version' => 'bbbbbbbbbbbb',
				)
			),
		);

		$rows = Reconcile::rows( array( 11, 12, 13 ), $listed );
		unlink( $same );
		unlink( $changed );

		$this->assertSame( array( 12 ), $hashed, 'Only the copy with a newer platform version is hashed.' );
		$this->assertFalse( $rows[0]['file_edited'] );
		$this->assertTrue( $rows[1]['file_edited'] );
		$this->assertFalse( $rows[2]['file_edited'], 'An unlisted copy is not hashed.' );
	}

	public function test_rows_hash_a_copy_whose_platform_text_changed(): void {
		$file = (string) tempnam( sys_get_temp_dir(), 'pfrc' );
		file_put_contents( $file, 'edited' );
		$hashed = 0;
		Functions\when( 'wp_get_original_image_path' )->alias(
			function () use ( $file, &$hashed ) {
				++$hashed;
				return $file;
			}
		);
		Functions\when( 'get_post' )->alias(
			function () {
				$post               = new \WP_Post();
				$post->post_title   = 'T';
				$post->post_excerpt = 'C';
				return $post;
			}
		);
		$this->meta[11] = array(
			'_profotograaf_photo_id'   => 'p1',
			'_profotograaf_version'    => 'aaaaaaaaaaaa',
			'_profotograaf_file_hash'  => sha1( 'original' ),
			'_profotograaf_text_hash'  => self::base_hash(),
			'_wp_attachment_image_alt' => 'A',
		);
		$same = array(
			'id'      => 'p1',
			'version' => 'aaaaaaaaaaaa',
			'title'   => 'T',
			'caption' => 'C',
			'alt'     => 'A',
		);

		$unchanged = Reconcile::rows( array( 11 ), array( 'p1' => self::photo( $same ) ) );
		$this->assertSame( 0, $hashed );
		$this->assertFalse( $unchanged[0]['file_edited'] );

		$retitled = Reconcile::rows( array( 11 ), array( 'p1' => self::photo( array_merge( $same, array( 'title' => 'New' ) ) ) ) );
		unlink( $file );
		$this->assertSame( 1, $hashed );
		$this->assertTrue( $retitled[0]['file_edited'] );
	}
}
