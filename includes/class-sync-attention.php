<?php
/**
 * What needs attention among the imported copies.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Counts, filter, list column, Import screen section and Site Health item for
 * copies the sync flagged `changed`, `missing` or `conflict`
 * (docs/media-lifecycle.md, rule 1). The counts come from the one option
 * Reconcile keeps, so no screen counts meta on a page load.
 */
final class Sync_Attention {

	/** Value of the Source filter that narrows to flagged copies. */
	public const FILTER_VAL = 'attention';

	public const HEALTH_TEST = 'profotograaf_sync_attention';

	/** Most copies the Import screen lists. */
	public const LIST_LIMIT = 20;

	/** Flagged states, in the order the signature names them. */
	public const STATES = array( Reconcile::STATE_MISSING, Reconcile::STATE_CHANGED, Reconcile::STATE_CONFLICT );

	/**
	 * Total number of flagged copies.
	 */
	public static function total(): int {
		return array_sum( Reconcile::counts() );
	}

	/**
	 * Signature of the problem for the admin notice: it changes when a count
	 * changes, so a dismissed notice returns for a new problem only.
	 */
	public static function signature(): string {
		$counts = Reconcile::counts();
		return 'sync:' . $counts['missing'] . ':' . $counts['changed'] . ':' . $counts['conflict'];
	}

	/**
	 * Notice text for a number of copies.
	 *
	 * @param int $total Number of flagged copies.
	 */
	public static function message( int $total ): string {
		return sprintf(
			/* translators: %d: number of imported photos. */
			_n( 'Profotograaf: %d imported photo needs attention.', 'Profotograaf: %d imported photos need attention.', $total, 'profotograaf' ),
			$total
		);
	}

	/**
	 * URL of the Media Library list narrowed to flagged copies.
	 */
	public static function list_url(): string {
		return add_query_arg(
			array(
				'mode'                                  => 'list',
				Modules\Media_Import_Screen::FILTER_ARG => self::FILTER_VAL,
			),
			admin_url( 'upload.php' )
		);
	}

	/**
	 * The meta query that selects flagged copies.
	 *
	 * @return array<string,mixed>
	 */
	public static function meta_query(): array {
		return array(
			'key'     => Reconcile::META_STATE,
			'value'   => array( Reconcile::STATE_CHANGED, Reconcile::STATE_MISSING, Reconcile::STATE_CONFLICT ),
			'compare' => 'IN',
		);
	}

	/**
	 * Short status text of a copy for the list column. Plain words, so the
	 * state never rests on colour.
	 *
	 * @param string $state Sync state.
	 */
	public static function column_text( string $state ): string {
		switch ( $state ) {
			case Reconcile::STATE_MISSING:
				return __( 'Removed on Profotograaf', 'profotograaf' );
			case Reconcile::STATE_CHANGED:
				return __( 'Changed on Profotograaf', 'profotograaf' );
			case Reconcile::STATE_CONFLICT:
				return __( 'Changed in both places', 'profotograaf' );
			case Reconcile::STATE_KEPT:
				return __( 'Kept', 'profotograaf' );
			default:
				return __( 'In sync', 'profotograaf' );
		}
	}

	/**
	 * The HTML of the Import screen section: the counts, a bulk removal for
	 * missing copies and the first flagged copies. Empty when nothing needs
	 * attention.
	 *
	 * @param callable $can_edit Receives an attachment id, answers whether the user may edit it.
	 */
	public static function section( callable $can_edit ): string {
		$counts = Reconcile::counts();
		$total  = array_sum( $counts );
		if ( 0 === $total ) {
			return '';
		}
		$items  = '';
		$sample = array();
		foreach ( self::flagged() as $id ) {
			$state  = (string) get_post_meta( $id, Reconcile::META_STATE, true );
			$reason = (string) get_post_meta( $id, Reconcile::META_REASON, true );
			$title  = get_the_title( $id );
			if ( Reconcile::STATE_MISSING === $state && count( $sample ) < 5 ) {
				$sample[] = (string) $title;
			}
			$link   = $can_edit( $id ) ? get_edit_post_link( $id ) : '';
			$name   = '' === (string) $title ? __( '(no title)', 'profotograaf' ) : (string) $title;
			$items .= '<li>' . ( $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( $name ) . '</a>' : esc_html( $name ) )
				. ' <span class="description">' . esc_html( self::column_text( $state ) . '. ' . Sync_Field::message( $state, $reason ) ) . '</span></li>';
		}
		$out  = '<div class="profotograaf-attention" role="region" aria-labelledby="profotograaf-attention-title">';
		$out .= '<h2 id="profotograaf-attention-title">' . esc_html( self::message( $total ) ) . '</h2>';
		$out .= '<p>' . esc_html(
			sprintf(
				/* translators: 1: number of photos removed on Profotograaf, 2: number changed there, 3: number changed in both places. */
				__( 'Removed on Profotograaf: %1$d. Changed on Profotograaf: %2$d. Changed in both places: %3$d.', 'profotograaf' ),
				$counts['missing'],
				$counts['changed'],
				$counts['conflict']
			)
		) . ' <a href="' . esc_url( self::list_url() ) . '">' . esc_html__( 'Show them in the Media Library', 'profotograaf' ) . '</a></p>';
		if ( $counts['missing'] > 0 && current_user_can( 'upload_files' ) ) {
			$out .= sprintf(
				'<p><button type="button" class="button profotograaf-bulk__remove" data-missing="%1$d" data-sample="%4$s">%2$s</button> <button type="button" class="button profotograaf-bulk__stop" hidden>%3$s</button> <span class="profotograaf-bulk__status" role="status" aria-live="polite"></span></p>',
				$counts['missing'],
				esc_html__( 'Remove all copies removed on Profotograaf', 'profotograaf' ),
				esc_html__( 'Stop', 'profotograaf' ),
				esc_attr( implode( ', ', $sample ) )
			);
		}
		return $out . '<ul>' . $items . '</ul></div>';
	}

	/**
	 * Ids of the first flagged copies, newest flag first.
	 *
	 * @return array<int,int>
	 */
	private static function flagged(): array {
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'meta_query'     => array( self::meta_query() ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the flagged copies, capped.
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'posts_per_page' => self::LIST_LIMIT,
				'no_found_rows'  => true,
			)
		);
		return array_map( 'intval', $ids );
	}

	/**
	 * Site Health result for the copies that need attention.
	 *
	 * @return array<string,mixed>
	 */
	public static function health_result(): array {
		$total  = self::total();
		$result = array(
			'label'       => __( 'Imported Profotograaf photos are in sync', 'profotograaf' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Profotograaf', 'profotograaf' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'No imported photo was removed or changed on Profotograaf.', 'profotograaf' ) . '</p>',
			'actions'     => '',
			'test'        => self::HEALTH_TEST,
		);
		if ( $total > 0 ) {
			$result['label']          = self::message( $total );
			$result['status']         = 'recommended';
			$result['badge']['color'] = 'orange';
			$result['description']    = '<p>' . esc_html__( 'Photos removed or changed on Profotograaf stay on this site until you decide. Nothing is deleted for you.', 'profotograaf' ) . '</p>';
			$result['actions']        = '<p><a href="' . esc_url( self::list_url() ) . '">' . esc_html__( 'Show them in the Media Library', 'profotograaf' ) . '</a></p>';
		}
		return $result;
	}
}
