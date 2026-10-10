<?php
/**
 * Removes GPS and location data from an image before it leaves the site.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Takes the GPS position out of the EXIF data of a JPEG, PNG, WebP or GIF file
 * and keeps everything else (camera, lens, exposure, date, orientation,
 * copyright). Used by the upload queue, so a position never reaches
 * Profotograaf by way of the Media Library.
 *
 * What is removed:
 *
 * - the GPS IFD of the EXIF block. Its entries and the values stored outside
 *   them are overwritten with zeros, and the pointer to it is removed from IFD0.
 * - `exif:GPS*` properties in XMP. They are replaced by spaces, which keeps
 *   every length in the file unchanged.
 *
 * An EXIF block that cannot be parsed is removed whole, because the position
 * cannot be shown to be absent from it. The file length of the other parts
 * never changes, so no offset needs to be fixed.
 */
final class Location_Stripper {

	private const GPS_POINTER = 0x8825;

	private const XMP_NAMESPACE = "http://ns.adobe.com/xap/1.0/\0";

	/**
	 * Byte sizes of the TIFF value types, indexed by type number.
	 *
	 * @var array<int,int>
	 */
	private const TYPE_SIZES = array(
		1  => 1,
		2  => 1,
		3  => 2,
		4  => 4,
		5  => 8,
		6  => 1,
		7  => 1,
		8  => 2,
		9  => 4,
		10 => 8,
		11 => 4,
		12 => 8,
	);

	/**
	 * Whether the format is one this class can clean.
	 *
	 * @param string $mime_type Media type.
	 */
	public static function supports( string $mime_type ): bool {
		return in_array( $mime_type, array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ), true );
	}

	/**
	 * Returns the file contents without GPS data. Unknown formats come back unchanged.
	 *
	 * @param string $bytes File contents.
	 */
	public static function strip( string $bytes ): string {
		if ( 0 === strncmp( $bytes, "\xFF\xD8", 2 ) ) {
			return self::strip_jpeg( $bytes );
		}
		if ( 0 === strncmp( $bytes, "\x89PNG\r\n\x1A\n", 8 ) ) {
			return self::strip_png( $bytes );
		}
		if ( 'RIFF' === substr( $bytes, 0, 4 ) && 'WEBP' === substr( $bytes, 8, 4 ) ) {
			return self::strip_webp( $bytes );
		}
		if ( 0 === strncmp( $bytes, 'GIF8', 4 ) ) {
			return self::blank_xmp( $bytes );
		}
		return $bytes;
	}

	/**
	 * Walks the JPEG segments up to the scan data.
	 *
	 * @param string $bytes JPEG contents.
	 */
	private static function strip_jpeg( string $bytes ): string {
		$length = strlen( $bytes );
		$pos    = 2;
		while ( $pos + 4 <= $length ) {
			if ( "\xFF" !== $bytes[ $pos ] ) {
				break;
			}
			$marker = ord( $bytes[ $pos + 1 ] );
			if ( 0xFF === $marker ) {
				++$pos;
				continue;
			}
			if ( 0xDA === $marker || 0xD9 === $marker ) {
				break;
			}
			$segment = (int) ( ord( $bytes[ $pos + 2 ] ) * 256 + ord( $bytes[ $pos + 3 ] ) );
			if ( $segment < 2 || $pos + 2 + $segment > $length ) {
				break;
			}
			if ( 0xE1 === $marker ) {
				$start   = $pos + 4;
				$payload = substr( $bytes, $start, $segment - 2 );
				if ( 0 === strncmp( $payload, "Exif\0\0", 6 ) ) {
					$tiff     = self::strip_tiff( substr( $payload, 6 ) );
					$replaced = null === $tiff ? str_repeat( "\0", $segment - 8 ) : $tiff;
					$bytes    = substr_replace( $bytes, $replaced, $start + 6, $segment - 8 );
				} elseif ( 0 === strncmp( $payload, self::XMP_NAMESPACE, strlen( self::XMP_NAMESPACE ) ) ) {
					$bytes = substr_replace( $bytes, self::blank_xmp( $payload ), $start, $segment - 2 );
				}
			}
			$pos += 2 + $segment;
		}//end while
		return $bytes;
	}

	/**
	 * Cleans the eXIf and XMP chunks of a PNG and fixes their checksums.
	 *
	 * @param string $bytes PNG contents.
	 */
	private static function strip_png( string $bytes ): string {
		$length = strlen( $bytes );
		$pos    = 8;
		while ( $pos + 12 <= $length ) {
			$size = self::number( 'N', substr( $bytes, $pos, 4 ) );
			$type = substr( $bytes, $pos + 4, 4 );
			if ( $pos + 12 + $size > $length ) {
				break;
			}
			$data = substr( $bytes, $pos + 8, $size );
			$new  = $data;
			if ( 'eXIf' === $type ) {
				$tiff = self::strip_tiff( $data );
				$new  = null === $tiff ? str_repeat( "\0", $size ) : $tiff;
			} elseif ( 'iTXt' === $type ) {
				$new = self::blank_xmp( $data );
			}
			if ( $new !== $data ) {
				$bytes = substr_replace( $bytes, $new . pack( 'N', crc32( $type . $new ) ), $pos + 8, $size + 4 );
			}
			$pos += 12 + $size;
		}
		return $bytes;
	}

	/**
	 * Cleans the EXIF and XMP chunks of a WebP file.
	 *
	 * @param string $bytes WebP contents.
	 */
	private static function strip_webp( string $bytes ): string {
		$length = strlen( $bytes );
		$pos    = 12;
		while ( $pos + 8 <= $length ) {
			$type = substr( $bytes, $pos, 4 );
			$size = self::number( 'V', substr( $bytes, $pos + 4, 4 ) );
			if ( $pos + 8 + $size > $length ) {
				break;
			}
			$data = substr( $bytes, $pos + 8, $size );
			if ( 'EXIF' === $type ) {
				$prefix = 0 === strncmp( $data, "Exif\0\0", 6 ) ? 6 : 0;
				$tiff   = self::strip_tiff( substr( $data, $prefix ) );
				$new    = substr( $data, 0, $prefix ) . ( null === $tiff ? str_repeat( "\0", $size - $prefix ) : $tiff );
				$bytes  = substr_replace( $bytes, $new, $pos + 8, $size );
			} elseif ( 'XMP ' === $type ) {
				$bytes = substr_replace( $bytes, self::blank_xmp( $data ), $pos + 8, $size );
			}
			$pos += 8 + $size + ( $size % 2 );
		}
		return $bytes;
	}

	/**
	 * Replaces every exif:GPS* property of an XMP packet by spaces of the same length.
	 *
	 * @param string $xmp Packet, or any bytes that may contain one.
	 */
	private static function blank_xmp( string $xmp ): string {
		if ( false === stripos( $xmp, 'exif:GPS' ) ) {
			return $xmp;
		}
		$blank = static fn( array $found ): string => str_repeat( ' ', strlen( $found[0] ) );
		$out   = preg_replace_callback( '~<exif:(GPS\w+)\b[^>]*?>.*?</exif:\1\s*>~is', $blank, $xmp );
		$out   = is_string( $out ) ? $out : $xmp;
		$out   = preg_replace_callback( '~<exif:GPS\w+\b[^>]*?/>~i', $blank, $out );
		$out   = is_string( $out ) ? $out : $xmp;
		$out   = preg_replace_callback( '~\sexif:GPS\w+\s*=\s*(?:"[^"]*"|\'[^\']*\')~i', $blank, $out );
		return is_string( $out ) ? $out : $xmp;
	}

	/**
	 * Removes the GPS IFD from a TIFF structure of the same length.
	 *
	 * @param string $tiff TIFF header and IFDs.
	 * @return string|null The cleaned structure, null when it cannot be parsed.
	 */
	private static function strip_tiff( string $tiff ): ?string {
		$length = strlen( $tiff );
		if ( $length < 8 ) {
			return null;
		}
		$order = substr( $tiff, 0, 2 );
		if ( 'II' !== $order && 'MM' !== $order ) {
			return null;
		}
		$little = 'II' === $order;
		$short  = $little ? 'v' : 'n';
		$long   = $little ? 'V' : 'N';
		$read   = static function ( string $format, int $at, int $size ) use ( $tiff, $length ): ?int {
			if ( $at < 0 || $at + $size > $length ) {
				return null;
			}
			return self::number( $format, substr( $tiff, $at, $size ) );
		};

		if ( 42 !== $read( $short, 2, 2 ) ) {
			return null;
		}
		$ifd0  = $read( $long, 4, 4 );
		$count = null === $ifd0 ? null : $read( $short, $ifd0, 2 );
		if ( null === $ifd0 || null === $count || $ifd0 + 2 + 12 * $count + 4 > $length ) {
			return null;
		}

		$entries = $ifd0 + 2;
		for ( $i = 0; $i < $count; $i++ ) {
			$at = $entries + 12 * $i;
			if ( self::GPS_POINTER !== $read( $short, $at, 2 ) ) {
				continue;
			}
			$gps = $read( $long, $at + 8, 4 );
			if ( null === $gps || ! self::zero_ifd( $tiff, $gps, $short, $long ) ) {
				return null;
			}
			// Close the gap: later entries and the next-IFD pointer move up one slot.
			$tail  = substr( $tiff, $at + 12, 12 * ( $count - $i - 1 ) + 4 );
			$tiff  = substr_replace( $tiff, $tail . str_repeat( "\0", 12 ), $at, strlen( $tail ) + 12 );
			$field = pack( $short, $count - 1 );
			return substr_replace( $tiff, $field, $ifd0, 2 );
		}
		return $tiff;
	}

	/**
	 * Overwrites a GPS IFD and the values it points to with zeros.
	 *
	 * @param string $tiff  TIFF structure, changed in place.
	 * @param int    $at    Offset of the IFD.
	 * @param string $short pack() format of a 16 bit value in the file's byte order.
	 * @param string $long  pack() format of a 32 bit value in the file's byte order.
	 * @return bool False when the IFD lies outside the structure.
	 */
	private static function zero_ifd( string &$tiff, int $at, string $short, string $long ): bool {
		$length = strlen( $tiff );
		if ( $at < 8 || $at + 2 > $length ) {
			return false;
		}
		$count = self::number( $short, substr( $tiff, $at, 2 ) );
		$end   = $at + 2 + 12 * $count + 4;
		if ( $end > $length ) {
			return false;
		}
		for ( $i = 0; $i < $count; $i++ ) {
			$entry = $at + 2 + 12 * $i;
			$type  = self::number( $short, substr( $tiff, $entry + 2, 2 ) );
			$items = self::number( $long, substr( $tiff, $entry + 4, 4 ) );
			$size  = ( self::TYPE_SIZES[ $type ] ?? 1 ) * $items;
			if ( $size > 4 ) {
				$value = self::number( $long, substr( $tiff, $entry + 8, 4 ) );
				if ( $value < 8 || $value + $size > $length ) {
					return false;
				}
				$tiff = substr_replace( $tiff, str_repeat( "\0", $size ), $value, $size );
			}
		}
		$tiff = substr_replace( $tiff, str_repeat( "\0", $end - $at ), $at, $end - $at );
		return true;
	}

	/**
	 * Reads one unsigned number, 0 when the bytes are too short.
	 *
	 * @param string $format pack() format code.
	 * @param string $bytes  The bytes to read.
	 */
	private static function number( string $format, string $bytes ): int {
		$read = unpack( $format, $bytes );
		return is_array( $read ) ? (int) $read[1] : 0;
	}
}
