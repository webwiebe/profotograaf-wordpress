<?php
/**
 * Gallery embed markup.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Writes the embed.js markup for one gallery. The block and the shortcode both
 * call it, so they cannot drift apart.
 *
 * The output is the div from docs/embed-js.md: `data-profotograaf-gallery`,
 * `data-layout` and, inside, a link to the gallery that stays when JavaScript
 * is off or the platform does not answer. The script is queued once per page.
 */
class Gallery_Renderer {

	/**
	 * The display options. Each key is the render argument and the shortcode
	 * attribute. `setting` is the Settings_Schema entry that holds the site
	 * default, `attribute` the block attribute and `data` the name of the
	 * data-* attribute that embed.js reads. To pass one more option through,
	 * add an entry here, a schema entry and a block.json attribute.
	 *
	 * An entry with `list` set holds a list of photo ids, has no site default
	 * (so no `setting`) and is written comma separated. The block stores an
	 * array and the shortcode takes a comma separated string.
	 */
	public const OPTIONS = array(
		'columns'        => array(
			'setting'   => 'gallery_columns',
			'attribute' => 'columns',
			'data'      => 'data-columns',
		),
		'columns_tablet' => array(
			'setting'   => 'gallery_columns_tablet',
			'attribute' => 'columnsTablet',
			'data'      => 'data-columns-tablet',
		),
		'columns_mobile' => array(
			'setting'   => 'gallery_columns_mobile',
			'attribute' => 'columnsMobile',
			'data'      => 'data-columns-mobile',
		),
		'gap'            => array(
			'setting'   => 'gallery_gap',
			'attribute' => 'gap',
			'data'      => 'data-gap',
		),
		'ratio'          => array(
			'setting'   => 'gallery_ratio',
			'attribute' => 'ratio',
			'data'      => 'data-ratio',
		),
		'captions'       => array(
			'setting'   => 'gallery_captions',
			'attribute' => 'captions',
			'data'      => 'data-captions',
		),
		'sort'           => array(
			'setting'   => 'gallery_sort',
			'attribute' => 'sort',
			'data'      => 'data-sort',
		),
		'per_page'       => array(
			'setting'   => 'gallery_per_page',
			'attribute' => 'perPage',
			'data'      => 'data-per-page',
		),
		'load_more'      => array(
			'setting'   => 'gallery_load_more',
			'attribute' => 'loadMore',
			'data'      => 'data-load-more',
		),
		'lightbox'       => array(
			'setting'   => 'gallery_lightbox',
			'attribute' => 'lightbox',
			'data'      => 'data-lightbox',
		),
		'exclude'        => array(
			'list'      => true,
			'attribute' => 'excludedPhotoIds',
			'data'      => 'data-exclude',
		),
	);

	/**
	 * Most photo ids one gallery block can leave out.
	 */
	public const MAX_EXCLUDED = 500;

	/**
	 * Script handling.
	 *
	 * @var Embed_Script
	 */
	private Embed_Script $script;

	/**
	 * Gallery details.
	 *
	 * @var Gallery_Index
	 */
	private Gallery_Index $index;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Embed_Script  $script   Script handling.
	 * @param Gallery_Index $index    Gallery details.
	 * @param Settings      $settings Settings.
	 */
	public function __construct( Embed_Script $script, Gallery_Index $index, Settings $settings ) {
		$this->script   = $script;
		$this->index    = $index;
		$this->settings = $settings;
	}

	/**
	 * Whether a value can be a gallery id. Ids are opaque, so only the
	 * characters that could break the markup are refused.
	 *
	 * @param string $id Candidate.
	 */
	public static function valid_id( string $id ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $id );
	}

	/**
	 * Photo ids from a list or a comma separated string. Ids that are not valid,
	 * repeated or beyond MAX_EXCLUDED are dropped.
	 *
	 * @param mixed $value Array of ids or comma separated string.
	 * @return string[]
	 */
	public static function clean_ids( $value ): array {
		if ( is_string( $value ) ) {
			$value = explode( ',', $value );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		$ids = array();
		foreach ( $value as $id ) {
			$id = is_scalar( $id ) ? trim( (string) $id ) : '';
			if ( self::valid_id( $id ) ) {
				$ids[ $id ] = $id;
			}
		}
		return array_slice( array_values( $ids ), 0, self::MAX_EXCLUDED );
	}

	/**
	 * The shortcode attributes and render arguments of the display options,
	 * each with an empty string, which stands for "not set here".
	 *
	 * @return array<string,string>
	 */
	public static function option_defaults(): array {
		return array_fill_keys( array_keys( self::OPTIONS ), '' );
	}

	/**
	 * Render arguments for the display options of a block.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @return array<string,mixed>
	 */
	public static function options_from_block( array $attributes ): array {
		$args = array();
		foreach ( self::OPTIONS as $key => $option ) {
			$args[ $key ] = $attributes[ $option['attribute'] ] ?? '';
		}
		return $args;
	}

	/**
	 * Renders a gallery.
	 *
	 * Keys of $args: id (required), layout (grid, masonry or slideshow; the
	 * default layout from the settings when empty or unknown), title and url
	 * (the fallback link; looked up when both are empty), class (extra CSS
	 * classes for the div), the display options listed in OPTIONS and wrapper (a callable that takes the div's
	 * attributes and returns the attribute string; the block passes
	 * get_block_wrapper_attributes so its color, typography, border, spacing and
	 * anchor supports reach the div).
	 *
	 * A display option comes from $args first, from $shortcode second and from
	 * the site default last. An empty or invalid value falls through to the
	 * next layer.
	 *
	 * @param array<string,mixed> $args      Arguments, from the block.
	 * @param array<string,mixed> $shortcode Display options from shortcode attributes.
	 * @return string HTML. Without a valid id, editors get a notice and visitors
	 *                get nothing.
	 */
	public function render( array $args, array $shortcode = array() ): string {
		$id = isset( $args['id'] ) ? trim( (string) $args['id'] ) : '';
		if ( ! self::valid_id( $id ) ) {
			return $this->notice(
				__( 'The Profotograaf gallery cannot be shown because its gallery ID is missing or not valid. Choose the gallery again.', 'profotograaf' )
			);
		}

		$layout = (string) $this->settings->resolve( 'default_layout', array( 'default_layout' => $args['layout'] ?? '' ) );

		$this->script->enqueue();

		$classes = trim( 'profotograaf-gallery ' . ( isset( $args['class'] ) ? (string) $args['class'] : '' ) );

		$notice = $this->missing_from_index( $id )
			? $this->notice( __( 'This Profotograaf gallery was not found in your account. It may have been deleted. Choose another gallery.', 'profotograaf' ) )
			: '';

		return $notice . sprintf(
			'<div %1$s>%2$s<noscript>%3$s</noscript></div>',
			$this->wrapper_attributes( $args, $classes, $id, $layout, $this->data_attributes( $args, $shortcode ) ),
			$this->fallback_link( $id, $args ),
			esc_html__( 'This gallery needs JavaScript to be shown here.', 'profotograaf' )
		);
	}

	/**
	 * The data-* attributes of the display options that have a value.
	 *
	 * @param array<string,mixed> $block     Block layer.
	 * @param array<string,mixed> $shortcode Shortcode layer.
	 * @return array<string,string> Attribute name => value.
	 */
	private function data_attributes( array $block, array $shortcode ): array {
		$attributes = array();
		foreach ( self::OPTIONS as $key => $option ) {
			if ( ! empty( $option['list'] ) ) {
				$ids = self::clean_ids( $block[ $key ] ?? '' );
				$ids = array() !== $ids ? $ids : self::clean_ids( $shortcode[ $key ] ?? '' );
				if ( array() !== $ids ) {
					$attributes[ $option['data'] ] = implode( ',', $ids );
				}
				continue;
			}
			$layers = array();
			foreach ( array( $block, $shortcode ) as $layer ) {
				$layers[] = array( $option['setting'] => $layer[ $key ] ?? '' );
			}
			$value = (string) $this->settings->resolve( $option['setting'], ...$layers );
			if ( '' === $value ) {
				continue;
			}
			// Ratios are stored as 4-3 (a colon is not allowed in an option key).
			$attributes[ $option['data'] ] = 'ratio' === $key ? str_replace( '-', ':', $value ) : $value;
		}
		return $attributes;
	}

	/**
	 * The attribute string of the gallery div.
	 *
	 * @param array<string,mixed>  $args    Render arguments.
	 * @param string               $classes Classes, with the extra ones from the arguments.
	 * @param string               $id      Gallery id.
	 * @param string               $layout  Layout.
	 * @param array<string,string> $data    Display option data attributes.
	 */
	private function wrapper_attributes( array $args, string $classes, string $id, string $layout, array $data ): string {
		$wrapper = $args['wrapper'] ?? null;
		if ( is_callable( $wrapper ) ) {
			return (string) $wrapper(
				array(
					'class'                     => 'profotograaf-gallery',
					'data-profotograaf-gallery' => $id,
					'data-layout'               => $layout,
				) + $data + array( 'style' => 'min-height:8em' )
			);
		}
		$html = sprintf(
			'class="%1$s" data-profotograaf-gallery="%2$s" data-layout="%3$s"',
			esc_attr( $classes ),
			esc_attr( $id ),
			esc_attr( $layout )
		);
		foreach ( $data as $name => $value ) {
			$html .= sprintf( ' %1$s="%2$s"', $name, esc_attr( $value ) );
		}
		return $html . ' style="min-height:8em"';
	}

	/**
	 * A notice for editors. Visitors get nothing.
	 *
	 * @param string $message Translated message.
	 */
	private function notice( string $message ): string {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return '';
		}
		return sprintf(
			'<p class="profotograaf-gallery-notice" role="note" style="padding:.75em 1em;border:1px solid #dba617;border-inline-start-width:4px;background:#fcf9e8;color:#1d2327">%s</p>',
			esc_html( $message )
		);
	}

	/**
	 * Whether the stored gallery list is known and leaves this id out. The list
	 * comes from the platform, so a deleted gallery is absent from it. Reads the
	 * option only, and asks for a fresh list in the background so a gallery that
	 * is new since the last lookup stops being reported.
	 *
	 * @param string $id Gallery id.
	 */
	private function missing_from_index( string $id ): bool {
		$stored = get_option( Gallery_Index::OPTION, array() );
		if ( ! is_array( $stored ) || array() === $stored || isset( $stored[ $id ] ) ) {
			return false;
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			return false;
		}
		$this->index->find( $id );
		return true;
	}

	/**
	 * The link that stays when the script does not replace it.
	 *
	 * @param string              $id   Gallery id.
	 * @param array<string,mixed> $args Render arguments.
	 */
	private function fallback_link( string $id, array $args ): string {
		$title = isset( $args['title'] ) ? trim( wp_strip_all_tags( (string) $args['title'] ) ) : '';
		$url   = isset( $args['url'] ) ? $this->clean_url( (string) $args['url'] ) : '';

		if ( '' === $url ) {
			$known = $this->index->find( $id );
			if ( null !== $known ) {
				$url   = $this->clean_url( $known['url'] );
				$title = '' !== $title ? $title : trim( wp_strip_all_tags( $known['title'] ) );
			}
		}
		if ( '' === $url ) {
			return '';
		}

		$site_label = (string) $this->settings->resolve( 'fallback_link_label' );
		$label      = '' !== $title ? $title : ( '' !== $site_label ? $site_label : __( 'View this gallery on Profotograaf', 'profotograaf' ) );
		return sprintf( '<a href="%1$s" style="display:inline-block;padding:.5em 0">%2$s</a>', esc_url( $url ), esc_html( $label ) );
	}

	/**
	 * An http(s) URL, or an empty string.
	 *
	 * @param string $url Candidate.
	 */
	private function clean_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		return in_array( $scheme, array( 'http', 'https' ), true ) ? $url : '';
	}
}
