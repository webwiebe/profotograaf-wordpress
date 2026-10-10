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
	 * default (null when the option has none), `attribute` the block attribute
	 * (null when core supplies the value, see options_from_block()) and `data`
	 * the name of the data-* attribute that embed.js reads. To pass one more
	 * option through, add an entry here, a schema entry and a block.json
	 * attribute.
	 *
	 * An entry with `list` set holds photo ids, has no site default and is
	 * written comma separated (an array from the block, a string from the
	 * shortcode). Cropping is the `ratio` option and the corner radius is the
	 * border radius block support, so neither has an entry of its own.
	 *
	 * @var array<string,array{setting:string|null,attribute:string|null,data:string,list?:bool}>
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
			'setting'   => null,
			'attribute' => 'excludedPhotoIds',
			'data'      => 'data-exclude',
		),
		'duotone'        => array(
			'setting'   => 'gallery_duotone',
			'attribute' => null,
			'data'      => 'data-duotone',
		),
		'link_to'        => array(
			'setting'   => 'gallery_link_to',
			'attribute' => 'linkTo',
			'data'      => 'data-link-to',
		),
		'link_new_tab'   => array(
			'setting'   => 'gallery_link_new_tab',
			'attribute' => 'linkNewTab',
			'data'      => 'data-link-new-tab',
		),
		'image_text'     => array(
			'setting'   => null,
			'attribute' => 'imageText',
			'data'      => 'data-image-text',
		),
	);

	/** Most photo ids one gallery block can leave out. */
	public const MAX_EXCLUDED = 500;

	/** Most per-image entries kept. */
	public const IMAGE_TEXT_LIMIT = 200;

	/** Longest caption or alt text kept, in characters. */
	public const IMAGE_TEXT_LENGTH = 500;

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
	 * A duotone chosen with the block's own color support (a preset slug or two
	 * colours) becomes the `duotone` argument as two hex colours, so embed.js
	 * draws it inside the shadow root, where the filter rule WordPress writes for
	 * the block cannot reach. A block that turns duotone off (`unset`) gets the
	 * value `none`, which also switches the site-wide duotone off. A value that
	 * cannot be resolved to two hex colours leaves the site-wide duotone in place.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @return array<string,mixed>
	 */
	public static function options_from_block( array $attributes ): array {
		$args = array();
		foreach ( self::OPTIONS as $key => $option ) {
			$args[ $key ] = null === $option['attribute'] ? '' : ( $attributes[ $option['attribute'] ] ?? '' );
		}
		$block_duotone = Block_Duotone::resolve( $attributes['style']['color']['duotone'] ?? null );
		if ( '' !== $block_duotone ) {
			$args['duotone'] = $block_duotone;
		}
		if ( is_array( $args['image_text'] ) ) {
			$args['image_text'] = self::clean_image_text( $args['image_text'] );
		}
		return $args;
	}

	/**
	 * Cleans two hex colours such as `#1a1a2e,#f5c542`, the duotone shadow and
	 * highlight.
	 *
	 * @param mixed $value Raw value.
	 * @return string|null The colours in lower case, an empty string for an empty
	 *                     value, or null when the value is not two hex colours.
	 */
	public static function clean_duotone( $value ): ?string {
		return Block_Duotone::clean( $value );
	}

	/**
	 * Cleans the per-image captions and alt texts into the JSON that embed.js
	 * reads: a list of objects with `id` and a `caption` and/or `alt`.
	 *
	 * @param mixed $raw A list of arrays, or the same as a JSON string (from the shortcode).
	 * @return string JSON, or an empty string when nothing usable is left.
	 */
	public static function clean_image_text( $raw ): string {
		if ( is_string( $raw ) ) {
			$raw = '' === trim( $raw ) ? array() : json_decode( $raw, true );
		}
		if ( ! is_array( $raw ) ) {
			return '';
		}
		$clean = array();
		foreach ( $raw as $item ) {
			$entry = self::clean_image_entry( $item );
			if ( null !== $entry ) {
				$clean[] = $entry;
			}
			if ( count( $clean ) >= self::IMAGE_TEXT_LIMIT ) {
				break;
			}
		}
		return array() === $clean ? '' : (string) wp_json_encode( $clean );
	}

	/**
	 * Cleans one per-image entry.
	 *
	 * @param mixed $item Raw entry.
	 * @return array<string,string>|null The entry, or null without a valid id or any text.
	 */
	private static function clean_image_entry( $item ): ?array {
		if ( ! is_array( $item ) || ! isset( $item['id'] ) || ! is_scalar( $item['id'] ) ) {
			return null;
		}
		$id = trim( (string) $item['id'] );
		if ( ! self::valid_id( $id ) ) {
			return null;
		}
		$entry = array( 'id' => $id );
		foreach ( array( 'caption', 'alt' ) as $field ) {
			$text = isset( $item[ $field ] ) && is_scalar( $item[ $field ] ) ? trim( sanitize_text_field( (string) $item[ $field ] ) ) : '';
			if ( '' !== $text ) {
				$entry[ $field ] = mb_substr( $text, 0, self::IMAGE_TEXT_LENGTH );
			}
		}
		return count( $entry ) > 1 ? $entry : null;
	}

	/**
	 * Renders a gallery.
	 *
	 * Keys of $args: id (required), layout (grid, masonry or slideshow; the
	 * default layout from the settings when empty or unknown; when that is the
	 * platform default, the drawn layout of the gallery from the index), title and url
	 * (the fallback link; looked up when both are empty), class (extra CSS
	 * classes for the div), the display options listed in OPTIONS and wrapper
	 * (a callable that takes the div's attributes and returns the attribute
	 * string; the block passes get_block_wrapper_attributes so its color,
	 * typography, border, spacing and anchor supports reach the div).
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
		if ( '' === $layout ) {
			// Platform default: the layout of the gallery on Profotograaf, as embed.js draws it.
			$layout = $this->index->drawn_layout( $id );
		}

		$this->script->enqueue();

		$classes = trim( 'profotograaf-gallery ' . ( isset( $args['class'] ) ? (string) $args['class'] : '' ) );

		$notice = $this->missing_from_index( $id )
			? $this->notice( __( 'This Profotograaf gallery was not found in your account. It may have been deleted. Choose another gallery.', 'profotograaf' ) )
			: '';

		$data = $this->data_attributes( $args, $shortcode, $layout );

		if ( $this->index->is_empty( $id ) ) {
			// The last gallery list counted no photos: no reserved space, no
			// fallback link (it would lead to an empty page) and no failed look.
			$data['data-pf-empty'] = '';
			return $notice . $this->script->preconnect() . Reserved_Space::rule() . sprintf(
				'<div %1$s>%2$s</div>',
				$this->wrapper_attributes( $args, $classes, $id, $layout, $data ),
				Empty_Gallery::hint( true )
			);
		}

		return $notice . $this->script->preconnect() . Reserved_Space::rule() . sprintf(
			'<div %1$s>%2$s%3$s<noscript>%4$s</noscript></div>',
			$this->wrapper_attributes( $args, $classes, $id, $layout, $data ),
			$this->fallback_link( $id, $args ),
			Empty_Gallery::hint( false ),
			esc_html__( 'This gallery needs JavaScript to be shown here.', 'profotograaf' )
		);
	}

	/**
	 * The data-* attributes of the display options that have a value.
	 *
	 * @param array<string,mixed> $block     Block layer.
	 * @param array<string,mixed> $shortcode Shortcode layer.
	 * @param string              $layout    Resolved layout.
	 * @return array<string,string> Attribute name => value.
	 */
	private function data_attributes( array $block, array $shortcode, string $layout ): array {
		$attributes = array();
		foreach ( self::OPTIONS as $key => $option ) {
			$ids   = ! empty( $option['list'] ) ? self::clean_ids( $block[ $key ] ?? '' ) : array();
			$value = ! empty( $option['list'] )
				? implode( ',', array() !== $ids ? $ids : self::clean_ids( $shortcode[ $key ] ?? '' ) )
				: $this->option_value( $key, $option['setting'], $block, $shortcode );
			if ( '' === $value ) {
				continue;
			}
			// Masonry keeps each photo's own shape, so it sends no ratio, from the
			// block or from the site-wide Photo shape.
			if ( 'ratio' === $key && 'masonry' === $layout ) {
				continue;
			}
			// Ratios are stored as 4-3 (a colon is not allowed in an option key).
			$attributes[ $option['data'] ] = 'ratio' === $key ? str_replace( '-', ':', $value ) : $value;
		}
		return Reserved_Space::with_step_down( $attributes, $layout );
	}

	/**
	 * One option resolved: block, then shortcode, then the site default.
	 *
	 * @param string              $key       Render argument.
	 * @param string|null         $setting   Schema key of the site default, if any.
	 * @param array<string,mixed> $block     Block layer.
	 * @param array<string,mixed> $shortcode Shortcode layer.
	 */
	private function option_value( string $key, ?string $setting, array $block, array $shortcode ): string {
		if ( null === $setting ) {
			$text = self::clean_image_text( $block[ $key ] ?? '' );
			return '' !== $text ? $text : self::clean_image_text( $shortcode[ $key ] ?? '' );
		}
		if ( 'duotone' === $key && 'none' === ( $block[ $key ] ?? '' ) ) {
			return '';
		}
		$layers = array();
		foreach ( array( $block, $shortcode ) as $layer ) {
			$layers[] = array( $setting => $layer[ $key ] ?? '' );
		}
		return (string) $this->settings->resolve( $setting, ...$layers );
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
				) + $data + ( isset( $data['data-pf-empty'] ) ? array() : array( 'style' => Reserved_Space::style( $layout, $data, $this->index->count( $id ) ) ) )
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
		if ( isset( $data['data-pf-empty'] ) ) {
			return $html;
		}
		return $html . sprintf( ' style="%s"', esc_attr( Reserved_Space::style( $layout, $data, $this->index->count( $id ) ) ) );
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
		return ( new Fallback_Link( $this->index, $this->settings ) )->render( $id, $args );
	}
}
