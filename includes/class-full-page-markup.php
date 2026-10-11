<?php
/**
 * Section markup of the full-page gallery.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Writes the sections of one full-page gallery in the markup contract of
 * docs/full-page-gallery.md and collects the flat photo list behind
 * `data-pf-index` for script.pf-fp-data. One instance renders one gallery.
 *
 * Every value from the payload is escaped where it is written.
 */
final class Full_Page_Markup {

	/**
	 * The payload.
	 *
	 * @var array<string,mixed>
	 */
	private array $gallery;

	/**
	 * Resolved display options: `lightbox` (bool), `link_to`, `new_tab` (bool)
	 * and `captions` (the block value, '' when unset).
	 *
	 * @var array{lightbox:bool,link_to:string,new_tab:bool,captions:string}
	 */
	private array $options;

	/**
	 * Caption and alt overrides by photo id.
	 *
	 * @var array<string,array<string,string>>
	 */
	private array $texts;

	/**
	 * The photos written so far, in render order.
	 *
	 * @var array<int,array{src:string,srcset:string,w:int,h:int,alt:string,caption:string}>
	 */
	private array $photos = array();

	/**
	 * Whether no photo section has been written yet.
	 *
	 * @var bool
	 */
	private bool $first = true;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed>                                              $gallery The payload.
	 * @param array{lightbox:bool,link_to:string,new_tab:bool,captions:string} $options Display options.
	 * @param array<string,array<string,string>>                               $texts   Caption and alt overrides by photo id.
	 */
	public function __construct( array $gallery, array $options, array $texts ) {
		$this->gallery = $gallery;
		$this->options = $options;
		$this->texts   = $texts;
	}

	/**
	 * The markup of every section, in order.
	 *
	 * @param array<int,array<string,mixed>> $sections Sequenced sections.
	 */
	public function sections( array $sections ): string {
		$html = '';
		foreach ( $sections as $section ) {
			$html .= 'text' === $section['type'] ? $this->text( $section ) : $this->section( $section );
		}
		return $html;
	}

	/**
	 * The photos behind `data-pf-index`, for script.pf-fp-data.
	 *
	 * @return array<int,array{src:string,srcset:string,w:int,h:int,alt:string,caption:string}>
	 */
	public function photos(): array {
		return $this->photos;
	}

	/**
	 * A text section.
	 *
	 * @param array<string,mixed> $section Sequenced section.
	 */
	private function text( array $section ): string {
		$heading = trim( (string) $section['heading'] );
		$text    = trim( (string) $section['text'] );
		return sprintf(
			'<section class="pf-fp-s pf-fp-text" data-type="text" style="--pf-align:%1$s">%2$s%3$s</section>',
			esc_attr( (string) $section['align'] ),
			'' === $heading ? '' : '<h2>' . esc_html( $heading ) . '</h2>',
			'' === $text ? '' : '<p>' . esc_html( $text ) . '</p>'
		);
	}

	/**
	 * A photo section.
	 *
	 * @param array<string,mixed> $section Sequenced section.
	 */
	private function section( array $section ): string {
		$type     = (string) $section['type'];
		$captions = 'off' === $section['captions'] && in_array( $this->options['captions'], array( 'below', 'overlay' ), true ) ? $this->options['captions'] : (string) $section['captions'];
		$attrs    = array(
			'class'     => 'pf-fp-s pf-fp-' . $type,
			'data-type' => $type,
			'style'     => $this->style( $section ),
		);
		if ( 'off' !== $captions ) {
			$attrs['data-captions'] = $captions;
		}
		if ( 'pair' === $type ) {
			$attrs['data-side'] = (string) $section['side'];
		}
		if ( 'hero' === $type && '' !== trim( (string) $section['title'] ) ) {
			$attrs['data-title'] = trim( (string) $section['title'] );
		}
		if ( 'filmstrip' === $type ) {
			$attrs += array(
				'tabindex'   => '0',
				'role'       => 'region',
				'aria-label' => __( 'Photo strip. Scroll sideways for more photos.', 'profotograaf' ),
			);
		}
		$eager       = $this->first;
		$this->first = false;
		$figures     = '';
		foreach ( array_values( (array) $section['photos'] ) as $position => $photo ) {
			$figures .= $this->figure( $section, (array) $photo, $position, $eager, 'off' !== $captions );
		}
		if ( 'filmstrip' === $type ) {
			$figures = '<div class="pf-fp-track">' . $figures . '</div>';
		}
		if ( isset( $attrs['data-title'] ) ) {
			$figures .= '<h2 class="pf-fp-title">' . esc_html( $attrs['data-title'] ) . '</h2>';
		}
		return '<section' . self::attributes( $attrs ) . '>' . $figures . '</section>';
	}

	/**
	 * The custom properties of a photo section.
	 *
	 * @param array<string,mixed> $section Sequenced section.
	 */
	private function style( array $section ): string {
		$gap = '--pf-gap:' . (int) ( $section['gap'] ?? 0 ) . 'px';
		switch ( $section['type'] ) {
			case 'grid':
				$ratio = 'original' === $section['ratio'] ? 'auto' : str_replace( '-', '/', (string) $section['ratio'] );
				return '--pf-cols:' . (int) $section['columns'] . ';--pf-cols-phone:' . Full_Page_Images::phone_columns( $section ) . ';--pf-ratio:' . $ratio . ';' . $gap;
			case 'masonry':
				return '--pf-cols:' . (int) $section['columns'] . ';--pf-cols-phone:' . Full_Page_Images::phone_columns( $section ) . ';' . $gap;
			case 'hero':
				$phone = min( (int) $section['height'], (int) ( Sections_Schema::phone( 'hero' )['height'] ?? $section['height'] ) );
				return '--pf-height:' . (int) $section['height'] . 'vh;--pf-height-phone:' . $phone . 'vh';
			case 'filmstrip':
				$phone = min( (int) $section['rowHeight'], (int) ( Sections_Schema::phone( 'filmstrip' )['rowHeight'] ?? $section['rowHeight'] ) );
				return '--pf-row:' . (int) $section['rowHeight'] . 'px;--pf-row-phone:' . $phone . 'px;' . $gap;
			default:
				return $gap;
		}
	}

	/**
	 * One photo: the figure, its link or lightbox opener, the image and the caption.
	 *
	 * @param array<string,mixed> $section  Sequenced section.
	 * @param array<string,mixed> $photo    Payload photo.
	 * @param int                 $position Position in the section.
	 * @param bool                $eager    Whether the photo is in the first photo section.
	 * @param bool                $captions Whether the section shows captions.
	 */
	private function figure( array $section, array $photo, int $position, bool $eager, bool $captions ): string {
		$index              = count( $this->photos );
		[ $width, $height ] = Full_Page_Images::size( $photo );
		$text               = $this->text_of( $photo, $index );
		$src                = Full_Page_Images::src( $photo );
		$this->photos[]     = array(
			'src'     => $src,
			'srcset'  => Full_Page_Images::srcset( $photo, false ),
			'w'       => $width,
			'h'       => $height,
			'alt'     => $text['alt'],
			'caption' => $text['caption'],
		);

		$square = 'grid' === $section['type'] && '1-1' === $section['ratio'];
		$srcset = Full_Page_Images::srcset( $photo, $square );
		$img    = array(
			'src'    => $src,
			'srcset' => $srcset,
			'sizes'  => '' === $srcset ? '' : Full_Page_Images::sizes( $section, $position, $height > 0 ? $width / $height : 1.5 ),
			'width'  => $width > 0 ? (string) $width : '',
			'height' => $height > 0 ? (string) $height : '',
			'alt'    => $text['alt'],
		);
		$img   += $eager ? array( 'loading' => 'eager' ) : array( 'loading' => 'lazy' );
		if ( $eager && 'hero' === $section['type'] ) {
			$img['fetchpriority'] = 'high';
		}
		$img['decoding'] = 'async';

		$focus  = Full_Page_Images::focus( $photo );
		$style  = '--pf-ar:' . ( $height > 0 ? round( $width / $height, 4 ) : 1.5 ) . ( '' === $focus ? '' : ';--pf-focus:' . $focus );
		$inner  = '<img' . self::attributes( $img, array( 'alt' ) ) . '>';
		$inner  = $this->opener( $photo, $index, $inner );
		$inner .= $captions && '' !== $text['caption'] ? '<figcaption>' . esc_html( $text['caption'] ) . '</figcaption>' : '';
		return '<figure class="pf-fp-photo" style="' . esc_attr( $style ) . '">' . $inner . '</figure>';
	}

	/**
	 * Wraps the image in what a click does: the lightbox opener (a link to the
	 * photo page that the script takes over), a link following the block's
	 * link setting, or a plain element when photos link nowhere.
	 *
	 * @param array<string,mixed> $photo Payload photo.
	 * @param int                 $index Position in the flat list.
	 * @param string              $img   The img tag.
	 */
	private function opener( array $photo, int $index, string $img ): string {
		if ( $this->options['lightbox'] ) {
			$href = Full_Page_Images::link( $photo, $this->gallery, 'page' );
			$href = '' !== $href ? $href : Full_Page_Images::src( $photo );
			return sprintf( '<a class="pf-fp-open" href="%1$s" data-pf-index="%2$d">%3$s</a>', esc_url( $href ), $index, $img );
		}
		$href = Full_Page_Images::link( $photo, $this->gallery, $this->options['link_to'] );
		if ( '' === $href ) {
			return '<span class="pf-fp-open">' . $img . '</span>';
		}
		$target = $this->options['new_tab'] ? ' target="_blank" rel="noopener noreferrer"' : '';
		return sprintf( '<a class="pf-fp-open" href="%1$s"%2$s>%3$s</a>', esc_url( $href ), $target, $img );
	}

	/**
	 * The alt text and caption of a photo. The block's per-photo text wins;
	 * otherwise they follow embed.js: the caption is the title and caption
	 * joined, the alt the photo's alt, title or caption, else the gallery title
	 * and the photo's number.
	 *
	 * @param array<string,mixed> $photo Payload photo.
	 * @param int                 $index Position in the flat list.
	 * @return array{alt:string,caption:string}
	 */
	private function text_of( array $photo, int $index ): array {
		$field    = static fn( string $key ): string => is_scalar( $photo[ $key ] ?? null ) ? trim( wp_strip_all_tags( (string) $photo[ $key ] ) ) : '';
		$override = $this->texts[ is_scalar( $photo['id'] ?? null ) ? (string) $photo['id'] : '' ] ?? array();
		$caption  = $override['caption'] ?? implode( ' - ', array_filter( array( $field( 'title' ), $field( 'caption' ) ) ) );
		$alt      = $override['alt'] ?? '';
		foreach ( array( 'alt', 'title', 'caption' ) as $key ) {
			$alt = '' !== $alt ? $alt : $field( $key );
		}
		if ( '' === $alt ) {
			$title = is_scalar( $this->gallery['title'] ?? null ) ? trim( wp_strip_all_tags( (string) $this->gallery['title'] ) ) : '';
			$alt   = trim( $title . ' ' . ( $index + 1 ) );
		}
		return array(
			'alt'     => $alt,
			'caption' => $caption,
		);
	}

	/**
	 * An attribute string. Empty values are left out, except the names in $keep.
	 *
	 * @param array<string,string> $attributes Name => value.
	 * @param string[]             $keep       Names written even when empty.
	 */
	private static function attributes( array $attributes, array $keep = array() ): string {
		$html = '';
		foreach ( $attributes as $name => $value ) {
			if ( '' === $value && ! in_array( $name, $keep, true ) ) {
				continue;
			}
			$html .= ' ' . $name . '="' . ( 'src' === $name ? esc_url( $value ) : esc_attr( $value ) ) . '"';
		}
		return $html;
	}
}
