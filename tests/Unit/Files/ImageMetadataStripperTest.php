<?php

declare(strict_types=1);

namespace Tests\Unit\Files;

use App\Files\ImageMetadataStripException;
use App\Files\ImageMetadataStripper;
use Intervention\Image\ImageManager;
use Tests\TestCase;

/**
 * Container-level metadata stripping. Exercises the committed byte fixtures in
 * tests/Fixtures/images/, each carrying a searchable "LEAK"/date sentinel so a surviving byte is a
 * hard failure.
 *
 * Fixture recipe (regenerate with the throwaway generator, see the PR's scratchpad script): GD
 * writes a clean baseline image, then the generator splices hand-built EXIF/XMP/comment segments
 * (a real GPS IFD + Orientation) into the container:
 *   - jpeg-gps-orientation.jpg  APP1 EXIF with a GPS IFD (GPSDateStamp "2021:07:04") + Orientation 6,
 *                               base image 12x6 landscape.
 *   - jpeg-postsos-meta.jpg      progressive JPEG with a COM + EXIF(GPS) placed AFTER the last SOS.
 *   - jpeg-app2-mixed.jpg        a non-ICC APP2 ("MPFDROPME") and a real ICC_PROFILE APP2 ("ICCKEEPME").
 *   - png-meta.png               eXIf (GPS) + tEXt ("png-text-LEAK") after IHDR.
 *   - png-badcrc.png             png-meta with one chunk's CRC flipped.
 *   - webp-vp8x-meta.webp        VP8X (flags ICC+reserved+EXIF+XMP) + image + odd-length EXIF + "XMP ".
 *   - tiny.gif                   plain GIF, no metadata.
 */
class ImageMetadataStripperTest extends TestCase
{
    private const GPS_SENTINEL = '2021:07:04';

    private ImageMetadataStripper $stripper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stripper = new ImageMetadataStripper;
    }

    public function test_jpeg_strips_gps_keeps_orientation_only(): void
    {
        $input = $this->fixture('jpeg-gps-orientation.jpg');
        $this->assertStringContainsString(self::GPS_SENTINEL, $input, 'fixture must carry the GPS sentinel');
        $this->assertArrayHasKey('GPS', $this->exif($input), 'fixture EXIF must have a GPS section');

        $out = $this->stripper->strip($input, 'image/jpeg');

        $this->assertStringNotContainsString(self::GPS_SENTINEL, $out, 'GPS sentinel must be gone');
        $exif = $this->exif($out);
        $this->assertArrayNotHasKey('GPS', $exif, 'no GPS section may remain');
        $this->assertSame(6, $exif['IFD0']['Orientation'] ?? null, 'Orientation 6 preserved via minimal APP1');
        $this->assertCount(1, $exif['IFD0'] ?? [], 'IFD0 holds only Orientation');
        $this->assertNotFalse(getimagesizefromstring($out), 'output decodes via getimagesize');
    }

    public function test_jpeg_orientation_survivor_auto_orients_in_intervention(): void
    {
        $out = $this->stripper->strip($this->fixture('jpeg-gps-orientation.jpg'), 'image/jpeg');

        // SOF dimensions are unchanged (12x6); auto-orient rotates Orientation 6 by 90°, swapping them.
        $this->assertSame([12, 6], array_slice((array) getimagesizefromstring($out), 0, 2));
        $image = $this->manager()->decode($out);
        $this->assertSame(6, $image->width());
        $this->assertSame(12, $image->height());
    }

    public function test_jpeg_drops_metadata_placed_after_the_scan(): void
    {
        $input = $this->fixture('jpeg-postsos-meta.jpg');
        $this->assertStringContainsString('LEAK', $input);
        $this->assertStringContainsString(self::GPS_SENTINEL, $input);

        $out = $this->stripper->strip($input, 'image/jpeg');

        $this->assertStringNotContainsString('LEAK', $out, 'post-SOS COM dropped');
        $this->assertStringNotContainsString(self::GPS_SENTINEL, $out, 'post-SOS EXIF dropped');
        $this->assertNotFalse(getimagesizefromstring($out));
        $this->assertNotNull($this->manager()->decode($out));
    }

    public function test_jpeg_keeps_icc_drops_non_icc_app2(): void
    {
        $out = $this->stripper->strip($this->fixture('jpeg-app2-mixed.jpg'), 'image/jpeg');

        $this->assertStringNotContainsString('MPFDROPME', $out, 'non-ICC APP2 dropped');
        $this->assertStringContainsString('ICC_PROFILE', $out, 'ICC_PROFILE marker kept');
        $this->assertStringContainsString('ICCKEEPME', $out, 'ICC payload kept');
        $this->assertNotFalse(getimagesizefromstring($out));
    }

    public function test_png_drops_text_and_exif_keeps_structure(): void
    {
        $input = $this->fixture('png-meta.png');
        $this->assertStringContainsString('png-text-LEAK', $input);
        $this->assertStringContainsString(self::GPS_SENTINEL, $input);

        $out = $this->stripper->strip($input, 'image/png');

        $this->assertStringNotContainsString('png-text-LEAK', $out, 'tEXt dropped');
        $this->assertStringNotContainsString(self::GPS_SENTINEL, $out, 'eXIf dropped');
        $this->assertStringContainsString('IHDR', $out);
        $this->assertStringContainsString('IEND', $out);
        $this->assertNotFalse(getimagesizefromstring($out));
    }

    public function test_png_with_corrupted_crc_throws(): void
    {
        $this->expectException(ImageMetadataStripException::class);
        $this->stripper->strip($this->fixture('png-badcrc.png'), 'image/png');
    }

    public function test_webp_drops_exif_xmp_and_clears_only_those_vp8x_flags(): void
    {
        $input = $this->fixture('webp-vp8x-meta.webp');
        $this->assertStringContainsString('exif-odd-LEAK', $input);
        $this->assertStringContainsString('xmp-LEAK', $input);

        $out = $this->stripper->strip($input, 'image/webp');

        $this->assertStringNotContainsString('exif-odd-LEAK', $out, 'EXIF chunk dropped');
        $this->assertStringNotContainsString('xmp-LEAK', $out, 'XMP chunk dropped');
        $this->assertSame('RIFF', substr($out, 0, 4));
        $this->assertSame('WEBP', substr($out, 8, 4));
        // RIFF size must be recomputed to filesize-8 after the odd-length chunk was removed.
        $this->assertSame(strlen($out) - 8, unpack('V', substr($out, 4, 4))[1]);
        // Only EXIF (0x08) + XMP (0x04) cleared; ICC (0x20) + the reserved bit (0x80) survive.
        $vp8x = strpos($out, 'VP8X');
        $this->assertNotFalse($vp8x);
        $this->assertSame(0x80 | 0x20, ord($out[$vp8x + 8]));
        $this->assertNotFalse(getimagesizefromstring($out));
    }

    public function test_gif_passes_through_byte_identical(): void
    {
        $gif = $this->fixture('tiny.gif');

        $this->assertSame($gif, $this->stripper->strip($gif, 'image/gif'));
    }

    public function test_webp_rejects_chunks_past_the_declared_riff_size(): void
    {
        // A chunk appended after the declared RIFF payload must not be walked and promoted into the
        // recomputed output — that would carry metadata the strip claims to remove.
        $input = $this->fixture('webp-vp8x-meta.webp').'JUNK'."\x04\x00\x00\x00".'riff-trailer-LEAK';

        $this->expectException(ImageMetadataStripException::class);
        $this->stripper->strip($input, 'image/webp');
    }

    public function test_jpeg_drops_an_overlong_app14(): void
    {
        // Only the canonical fixed-length Adobe APP14 survives; a padded "Adobe…" payload is a
        // metadata channel and must be dropped, not kept on the prefix match alone.
        $payload = 'Adobe'.'app14-pad-LEAK';
        $app14 = "\xFF\xEE".pack('n', strlen($payload) + 2).$payload;
        $soi = substr($this->fixture('jpeg-gps-orientation.jpg'), 0, 2);
        $input = $soi.$app14.substr($this->fixture('jpeg-gps-orientation.jpg'), 2);

        $out = $this->stripper->strip($input, 'image/jpeg');

        $this->assertStringNotContainsString('app14-pad-LEAK', $out);
        $this->assertNotFalse(getimagesizefromstring($out));
    }

    public function test_png_rejects_trailing_data_after_iend(): void
    {
        $input = $this->fixture('png-meta.png').'png-trailer-LEAK';

        $this->expectException(ImageMetadataStripException::class);
        $this->stripper->strip($input, 'image/png');
    }

    public function test_garbage_input_throws(): void
    {
        $this->expectException(ImageMetadataStripException::class);
        $this->stripper->strip('this is not an image', 'image/jpeg');
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path("tests/Fixtures/images/{$name}"));
    }

    /** @return array<string, mixed> */
    private function exif(string $jpeg): array
    {
        $data = @exif_read_data('data://image/jpeg;base64,'.base64_encode($jpeg), null, true);

        return is_array($data) ? $data : [];
    }

    private function manager(): ImageManager
    {
        return app(ImageManager::class);
    }
}
