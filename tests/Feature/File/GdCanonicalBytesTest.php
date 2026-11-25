<?php

declare(strict_types=1);

namespace Tests\Feature\File;

use App\Files\GdImageProcessor;
use App\Files\ImageProcessingException;
use App\Files\ImageSpec;
use App\Files\ImageTooLargeException;
use GdImage;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\ImageManager;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The reference is intervention/image's own orientation and encoder, which the processor stops calling
 * for a canonical; existing canonicals are kept, so a new one must come out the same.
 */
class GdCanonicalBytesTest extends TestCase
{
    /** @return array<string, array{int}> */
    public static function orientations(): array
    {
        return array_combine(array_map(fn (int $o): string => "orientation {$o}", range(1, 8)), array_map(fn (int $o): array => [$o], range(1, 8)));
    }

    #[DataProvider('orientations')]
    public function test_a_jpeg_canonical_is_the_encoders_bytes_for_every_orientation(int $orientation): void
    {
        if (! extension_loaded('exif')) {
            $this->markTestSkipped('Orientation is read only with ext-exif.');
        }

        // Non-square and patterned, so a swapped side or a mirrored row changes the bytes; 72 dpi, as imagerotate() leaves its result at GD's 96.
        $source = $this->pattern(37, 23);
        imageresolution($source, 72);
        $jpeg = $this->withOrientation($this->encode($source, 'jpg'), $orientation);

        $this->assertSame(sha1($this->reference($jpeg, 'jpg')), sha1($this->canonical($jpeg, 'image/jpeg', 'jpg')));
    }

    public function test_an_opaque_png_and_gif_canonical_are_the_encoders_bytes(): void
    {
        $palette = imagecreate(30, 20);
        for ($i = 0; $i < 6; $i++) {
            imagefilledrectangle($palette, $i * 5, 0, $i * 5 + 4, 19, (int) imagecolorallocate($palette, $i * 40, 255 - $i * 40, 90));
        }

        // RGB with a tRNS colour, the shape an optimiser leaves of an RGBA PNG whose alpha is all or nothing; its variants keep the transparency only if the canonical is RGBA.
        $keyed = $this->pattern(24, 16);
        $key = (int) imagecolorallocate($keyed, 250, 0, 250);
        imagefilledrectangle($keyed, 6, 4, 17, 11, $key);
        imagecolortransparent($keyed, $key);

        $interlaced = $this->pattern(41, 17);
        imageinterlace($interlaced, true);

        $sources = [
            'truecolor png' => [$this->encode($this->pattern(41, 17), 'png'), 'image/png', 'png'],
            'interlaced png' => [$this->encode($interlaced, 'png'), 'image/png', 'png'],
            'interlaced gif' => [$this->encode($interlaced, 'gif'), 'image/gif', 'gif'],
            'keyed truecolor png' => [$this->encode($keyed, 'png'), 'image/png', 'png'],
            'palette png' => [$this->encode($palette, 'png'), 'image/png', 'png'],
            'gif' => [$this->encode($this->pattern(41, 17), 'gif'), 'image/gif', 'gif'],
            'palette gif' => [$this->encode($palette, 'gif'), 'image/gif', 'gif'],
        ];

        foreach ($sources as $name => [$bytes, $mime, $format]) {
            $this->assertSame(sha1($this->reference($bytes, $format)), sha1($this->canonical($bytes, $mime, $format)), $name);
        }
    }

    public function test_a_transparent_png_and_gif_canonical_keep_the_encoders_pixels(): void
    {
        $alpha = imagecreatetruecolor(24, 16);
        imagealphablending($alpha, false);
        imagesavealpha($alpha, true);
        for ($x = 0; $x < 24; $x++) {
            imagefilledrectangle($alpha, $x, 0, $x, 15, (int) imagecolorallocatealpha($alpha, $x * 10, 80, 200, intdiv($x * 127, 23)));
        }

        $keyed = imagecreate(24, 16);
        imagecolorallocate($keyed, 10, 200, 30);
        $key = (int) imagecolorallocate($keyed, 250, 0, 250);
        // libgd does not paint with the colour already marked transparent, so the key is set after the fill.
        imagefilledrectangle($keyed, 6, 4, 17, 11, $key);
        imagecolortransparent($keyed, $key);

        $sources = [
            'alpha png' => [$this->encode($alpha, 'png'), 'image/png', 'png'],
            'keyed gif' => [$this->encode($keyed, 'gif'), 'image/gif', 'gif'],
        ];

        foreach ($sources as $name => [$bytes, $mime, $format]) {
            $this->assertSame($this->rgba($this->reference($bytes, $format)), $this->rgba($this->canonical($bytes, $mime, $format)), $name);
        }
    }

    public function test_a_row_whose_type_names_jpeg_over_a_transparent_png_gets_the_encoders_white(): void
    {
        $alpha = imagecreatetruecolor(8, 8);
        imagealphablending($alpha, false);
        imagesavealpha($alpha, true);
        imagefilledrectangle($alpha, 0, 0, 7, 7, (int) imagecolorallocatealpha($alpha, 0, 0, 0, 127));
        $png = $this->encode($alpha, 'png');

        $this->assertSame(sha1($this->reference($png, 'jpg')), sha1($this->canonical($png, 'image/jpeg', 'jpg')));
    }

    #[DataProvider('orientations')]
    public function test_the_limit_counts_a_turn_exactly_where_the_processor_turns(int $orientation): void
    {
        if (! extension_loaded('exif')) {
            $this->markTestSkipped('Orientation is read only with ext-exif.');
        }

        // 1025 x 8 counts as 2048 x 8 upright and 1024 x 1025 turned, either side of this limit.
        $jpeg = $this->withOrientation($this->encode($this->pattern(1025, 8), 'jpg'), $orientation);
        config(['openpne.images.max_source_pixels' => 100_000]);
        try {
            (new GdImageProcessor)->process($jpeg, 'image/jpeg', ImageSpec::canonical('jpg'));
            $counted = false;
        } catch (ImageTooLargeException) {
            $counted = true;
        }

        config(['openpne.images.max_source_pixels' => 0]);
        $turned = (new GdImageProcessor)->process($jpeg, 'image/jpeg', ImageSpec::canonical('jpg'))->width === 8;

        $this->assertSame($turned, $counted);
        $this->assertSame($orientation >= 5, $turned);
    }

    public function test_a_quality_outside_the_encoders_range_is_a_processing_failure_on_the_native_path(): void
    {
        $jpeg = $this->encode($this->pattern(8, 8), 'jpg');

        foreach ([101, -1] as $quality) {
            config(['openpne.images.quality' => $quality]);
            try {
                $this->canonical($jpeg, 'image/jpeg', 'jpg');
                $this->fail("Quality {$quality} was written.");
            } catch (ImageProcessingException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function canonical(string $bytes, string $mime, string $format): string
    {
        return (new GdImageProcessor)->process($bytes, $mime, ImageSpec::canonical($format))->bytes;
    }

    private function reference(string $bytes, string $format): string
    {
        $image = (new ImageManager(GdDriver::class, decodeAnimation: false))->decode($bytes);

        return $image->encodeUsingFileExtension($format, quality: (int) config('openpne.images.quality'))->toString();
    }

    private function pattern(int $width, int $height): GdImage
    {
        $gd = imagecreatetruecolor($width, $height);
        for ($x = 0; $x < $width; $x++) {
            for ($y = 0; $y < $height; $y++) {
                imagesetpixel($gd, $x, $y, ($x * 7 % 256) << 16 | ($y * 11 % 256) << 8 | (($x + $y) * 5 % 256));
            }
        }

        return $gd;
    }

    private function encode(GdImage $gd, string $format): string
    {
        ob_start();
        match ($format) {
            'jpg' => imagejpeg($gd, null, 90),
            'png' => imagepng($gd),
            'gif' => imagegif($gd),
        };

        return (string) ob_get_clean();
    }

    /** Splices a minimal big-endian EXIF APP1 holding only IFD0 Orientation in after the SOI marker. */
    private function withOrientation(string $jpeg, int $orientation): string
    {
        $tiff = 'MM'.pack('nN', 42, 8).pack('n', 1).pack('nnNnn', 0x0112, 3, 1, $orientation, 0).pack('N', 0);
        $app1 = "Exif\0\0".$tiff;

        return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpeg, 2);
    }

    /** @return list<int> every pixel's RGBA as a browser draws it, fully transparent pixels reduced to their alpha */
    private function rgba(string $bytes): array
    {
        $gd = imagecreatefromstring($bytes);
        $this->assertNotFalse($gd);
        $pixels = [];

        for ($y = 0; $y < imagesy($gd); $y++) {
            for ($x = 0; $x < imagesx($gd); $x++) {
                $c = imagecolorsforindex($gd, imagecolorat($gd, $x, $y));
                $pixels[] = $c['alpha'] === 127 ? 127 << 24 : $c['alpha'] << 24 | $c['red'] << 16 | $c['green'] << 8 | $c['blue'];
            }
        }

        return $pixels;
    }
}
