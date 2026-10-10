<?php
/**
 * Location_Stripper tests.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Tests;

use PHPUnit\Framework\TestCase;
use Profotograaf\Location_Stripper;

class Location_Stripper_Test extends TestCase {

	public const LATITUDE = 'LATITUDE-DATA-24-BYTES!';

	/**
	 * A little endian TIFF with Make, Orientation and a GPS IFD.
	 */
	public static function tiff(): string {
		$ifd0  = pack( 'v', 3 );
		$ifd0 .= pack( 'vvVV', 0x010F, 2, 6, 50 );
		$ifd0 .= pack( 'vvVV', 0x0112, 3, 1, 1 );
		$ifd0 .= pack( 'vvVV', 0x8825, 4, 1, 56 );
		$ifd0 .= pack( 'V', 0 );
		$gps   = pack( 'v', 2 );
		$gps  .= pack( 'vvV', 0x0001, 2, 2 ) . "N\0\0\0";
		$gps  .= pack( 'vvVV', 0x0002, 5, 3, 86 );
		$gps  .= pack( 'V', 0 );
		return 'II' . pack( 'vV', 42, 8 ) . $ifd0 . "Canon\0" . $gps . str_pad( self::LATITUDE, 24, '!' );
	}

	public static function jpeg( string $tiff ): string {
		$payload = "Exif\0\0" . $tiff;
		return "\xFF\xD8\xFF\xE1" . pack( 'n', strlen( $payload ) + 2 ) . $payload . "\xFF\xDA\x00\x02" . 'scan';
	}

	/**
	 * Tags of IFD0 in a TIFF structure.
	 *
	 * @return array<int,int>
	 */
	private function tags( string $tiff ): array {
		$count = unpack( 'v', substr( $tiff, 8, 2 ) )[1];
		$tags  = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$tags[] = unpack( 'v', substr( $tiff, 10 + 12 * $i, 2 ) )[1];
		}
		return $tags;
	}

	public function test_a_jpeg_loses_its_gps_ifd_and_keeps_the_other_tags(): void {
		$input  = $this->jpeg( $this->tiff() );
		$output = Location_Stripper::strip( $input );

		$this->assertSame( strlen( $input ), strlen( $output ) );
		$this->assertStringNotContainsString( 'LATITUDE', $output );
		$this->assertStringContainsString( "Canon\0", $output );
		$this->assertStringEndsWith( 'scan', $output );

		$tiff = substr( $output, 4 + 2 + 6 );
		$this->assertSame( array( 0x010F, 0x0112 ), $this->tags( $tiff ) );
		// The next-IFD pointer follows the two remaining entries.
		$this->assertSame( 0, unpack( 'V', substr( $tiff, 8 + 2 + 24, 4 ) )[1] );
	}

	public function test_a_big_endian_exif_block_is_cleaned_too(): void {
		$ifd0  = pack( 'n', 1 ) . pack( 'nnNN', 0x8825, 4, 1, 26 ) . pack( 'N', 0 );
		$gps   = pack( 'n', 1 ) . pack( 'nnN', 0x0002, 5, 3 ) . pack( 'N', 44 ) . pack( 'N', 0 );
		$tiff  = 'MM' . pack( 'nN', 42, 8 ) . $ifd0 . $gps;
		$tiff  = str_pad( $tiff, 44, "\0" ) . str_pad( self::LATITUDE, 24, '!' );
		$input = $this->jpeg( $tiff );

		$output = Location_Stripper::strip( $input );

		$this->assertStringNotContainsString( 'LATITUDE', $output );
		$this->assertSame( 0, unpack( 'n', substr( $output, 4 + 2 + 6 + 8, 2 ) )[1], 'IFD0 holds no entry any more.' );
	}

	public function test_a_file_without_gps_is_unchanged(): void {
		$tiff  = 'II' . pack( 'vV', 42, 8 ) . pack( 'v', 1 ) . pack( 'vvVV', 0x0112, 3, 1, 1 ) . pack( 'V', 0 );
		$input = $this->jpeg( $tiff );

		$this->assertSame( $input, Location_Stripper::strip( $input ) );
	}

	public function test_an_exif_block_that_cannot_be_parsed_is_removed_whole(): void {
		$input  = $this->jpeg( 'II' . pack( 'vV', 42, 9999 ) . 'GPS-somewhere' );
		$output = Location_Stripper::strip( $input );

		$this->assertSame( strlen( $input ), strlen( $output ) );
		$this->assertStringNotContainsString( 'GPS-somewhere', $output );
	}

	public function test_a_gps_ifd_that_points_outside_the_block_removes_the_whole_block(): void {
		$tiff   = $this->tiff();
		$broken = substr_replace( $tiff, pack( 'V', 5000 ), 8 + 2 + 24 + 8, 4 );
		$output = Location_Stripper::strip( $this->jpeg( $broken ) );

		$this->assertStringNotContainsString( 'LATITUDE', $output );
		$this->assertStringNotContainsString( 'Canon', $output );
	}

	public function test_xmp_gps_properties_become_spaces_of_the_same_length(): void {
		$xmp     = '<x:xmpmeta><rdf:Description exif:GPSLatitude="52,10.5N" exif:Other="keep" dc:title="Bride">'
			. '<exif:GPSLongitude>5,3.2E</exif:GPSLongitude><exif:GPSMapDatum>WGS-84</exif:GPSMapDatum></rdf:Description></x:xmpmeta>';
		$payload = "http://ns.adobe.com/xap/1.0/\0" . $xmp;
		$input   = "\xFF\xD8\xFF\xE1" . pack( 'n', strlen( $payload ) + 2 ) . $payload . "\xFF\xDA\x00\x02scan";

		$output = Location_Stripper::strip( $input );

		$this->assertSame( strlen( $input ), strlen( $output ) );
		$this->assertStringNotContainsString( 'GPS', $output );
		$this->assertStringNotContainsString( '52,10.5N', $output );
		$this->assertStringContainsString( 'exif:Other="keep"', $output );
		$this->assertStringContainsString( 'dc:title="Bride"', $output );
	}

	public function test_a_png_exif_chunk_is_cleaned_and_its_checksum_stays_valid(): void {
		$chunk = static fn( string $type, string $data ): string => pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) );
		$input = "\x89PNG\r\n\x1A\n" . $chunk( 'IHDR', str_repeat( "\0", 13 ) ) . $chunk( 'eXIf', $this->tiff() ) . $chunk( 'IEND', '' );

		$output = Location_Stripper::strip( $input );

		$this->assertSame( strlen( $input ), strlen( $output ) );
		$this->assertStringNotContainsString( 'LATITUDE', $output );
		$this->assertStringContainsString( "Canon\0", $output );
		$start = strpos( $output, 'eXIf' );
		$size  = unpack( 'N', substr( $output, $start - 4, 4 ) )[1];
		$data  = substr( $output, $start + 4, $size );
		$this->assertSame( crc32( 'eXIf' . $data ), unpack( 'N', substr( $output, $start + 4 + $size, 4 ) )[1] );
	}

	public function test_a_png_raw_profile_text_chunk_is_removed(): void {
		$chunk = static fn( string $type, string $data ): string => pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) );
		$head  = "\x89PNG\r\n\x1A\n" . $chunk( 'IHDR', str_repeat( "\0", 13 ) );
		$tail  = $chunk( 'tEXt', "Comment\0kept" ) . $chunk( 'IEND', '' );
		$input = $head . $chunk( 'zTXt', "Raw profile type exif\0\0secretbytes" ) . $tail;

		$this->assertSame( $head . $tail, Location_Stripper::strip( $input ) );
	}

	public function test_a_webp_exif_chunk_is_cleaned(): void {
		$tiff = $this->tiff();
		$body = 'WEBPVP8 ' . pack( 'V', 4 ) . 'dataEXIF' . pack( 'V', strlen( $tiff ) ) . $tiff;
		$body = strlen( $tiff ) % 2 ? $body . "\0" : $body;
		$in   = 'RIFF' . pack( 'V', strlen( $body ) ) . $body;

		$output = Location_Stripper::strip( $in );

		$this->assertSame( strlen( $in ), strlen( $output ) );
		$this->assertStringNotContainsString( 'LATITUDE', $output );
		$this->assertStringContainsString( "Canon\0", $output );
	}

	public function test_a_gif_with_xmp_gps_is_cleaned_and_other_bytes_pass_through(): void {
		$gif = 'GIF89a<exif:GPSLatitude>52</exif:GPSLatitude>tail';
		$this->assertStringNotContainsString( 'GPS', Location_Stripper::strip( $gif ) );
		$this->assertSame( 'not an image', Location_Stripper::strip( 'not an image' ) );
	}

	public function test_it_knows_the_formats_it_can_clean(): void {
		foreach ( array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ) as $mime ) {
			$this->assertTrue( Location_Stripper::supports( $mime ), $mime );
		}
		$this->assertFalse( Location_Stripper::supports( 'image/heic' ) );
	}
}
