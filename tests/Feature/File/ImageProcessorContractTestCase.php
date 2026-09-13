<?php

declare(strict_types=1);

namespace Tests\Feature\File;

use App\Files\ImageProcessingException;
use App\Files\ImageProcessor;
use App\Files\ImageSpec;
use App\Files\ProcessedImage;
use Intervention\Gif\Builder;
use Intervention\Gif\Decoder;
use Tests\TestCase;

/**
 * Every implementation is held to the same fixtures (tests/Fixtures/images/README.md): what the
 * seam promises is decided here, and a concrete test only names the processor.
 */
abstract class ImageProcessorContractTestCase extends TestCase
{
    private const GPS_SENTINEL = '2021:07:04';

    abstract protected function processor(): ImageProcessor;

    /** Whether the processor under test can read EXIF Orientation on this host. */
    protected function appliesOrientation(): bool
    {
        return true;
    }

    public function test_the_canonical_carries_no_source_metadata(): void
    {
        foreach (['jpeg-gps-orientation.jpg' => 'image/jpeg', 'png-meta.png' => 'image/png', 'webp-vp8x-meta.webp' => 'image/webp'] as $fixture => $mime) {
            $canonical = $this->canonical($this->fixture($fixture), $mime);

            $this->assertStringNotContainsString(self::GPS_SENTINEL, $canonical->bytes, $fixture);
            $this->assertStringNotContainsString('LEAK', $canonical->bytes, $fixture);
            $this->assertSame($mime, $canonical->mime, $fixture);
            $this->assertNotFalse(getimagesizefromstring($canonical->bytes), "{$fixture}: the canonical must itself decode.");
        }
    }

    public function test_copyright_and_comment_segments_do_not_survive_either(): void
    {
        // Backends that strip "metadata" tend to keep copyright by default; the contract is no
        // source text at all.
        $canonical = $this->canonical($this->fixture('jpeg-copyright.jpg'), 'image/jpeg');

        $this->assertStringNotContainsString('COPYRIGHT-LEAK', $canonical->bytes);
        $this->assertStringNotContainsString('COMMENT-LEAK', $canonical->bytes);
    }

    public function test_the_canonical_is_drawn_upright_and_records_the_rendered_size(): void
    {
        // The fixture is 12x6 declaring Orientation 6.
        $canonical = $this->canonical($this->fixture('jpeg-gps-orientation.jpg'), 'image/jpeg');
        $expected = $this->appliesOrientation() ? [6, 12] : [12, 6];

        $this->assertSame($expected, [$canonical->width, $canonical->height]);
        $this->assertSame($expected, $this->dimensions($canonical->bytes));
    }

    public function test_a_variant_of_an_animation_is_a_single_frame(): void
    {
        $variant = $this->processor()->process($this->animatedGif(), 'image/gif', ImageSpec::fit(120, 120, 'gif'));

        $this->assertSame(1, $this->frameCount($variant->bytes));
        $this->assertFalse($variant->animated);
    }

    public function test_the_canonical_keeps_an_animation_only_where_the_processor_says_so(): void
    {
        $canonical = $this->canonical($this->animatedGif(), 'image/gif');
        $preserves = $this->processor()->preservesAnimation();

        $this->assertSame($preserves ? 3 : 1, $this->frameCount($canonical->bytes));
        $this->assertSame($preserves, $canonical->animated);
    }

    public function test_an_apng_is_a_still(): void
    {
        // libpng ignores the animation chunks and libvips reads the first frame, so no processor keeps them.
        $canonical = $this->canonical($this->fixture('apng-2frames.png'), 'image/png');

        $this->assertStringNotContainsString('acTL', $canonical->bytes);
        $this->assertStringNotContainsString('fcTL', $canonical->bytes);
        $this->assertFalse($canonical->animated);
        $this->assertSame([4, 4], $this->dimensions($canonical->bytes));
    }

    public function test_fit_scales_down_inside_the_box_and_never_up(): void
    {
        $source = $this->png(240, 120);

        $this->assertSame([120, 60], $this->dimensions($this->processor()->process($source, 'image/png', ImageSpec::fit(120, 120, 'png'))->bytes));
        $this->assertSame([240, 120], $this->dimensions($this->processor()->process($source, 'image/png', ImageSpec::fit(1200, 1200, 'png'))->bytes));
    }

    public function test_cover_fills_the_box_exactly_whatever_its_ratio(): void
    {
        $source = $this->png(240, 120);

        $this->assertSame([300, 400], $this->dimensions($this->processor()->process($source, 'image/png', ImageSpec::cover(300, 400, 'png'))->bytes));
        $this->assertSame([120, 120], $this->dimensions($this->processor()->process($source, 'image/png', ImageSpec::cover(120, 120, 'png'))->bytes));
    }

    public function test_a_background_flattens_transparency(): void
    {
        $processed = $this->processor()->process($this->transparentPng(32), 'image/png', ImageSpec::cover(16, 16, 'png')->withBackground('ffffff'));

        $gd = imagecreatefromstring($processed->bytes);
        $this->assertNotFalse($gd);
        $rgba = imagecolorsforindex($gd, imagecolorat($gd, 8, 8));
        $this->assertSame([255, 255, 255, 0], [$rgba['red'], $rgba['green'], $rgba['blue'], $rgba['alpha']]);
    }

    public function test_bytes_that_are_not_an_image_fail_deterministically(): void
    {
        $this->expectException(ImageProcessingException::class);

        $this->processor()->process('definitely not a picture', 'image/png', ImageSpec::canonical('png'));
    }

    public function test_a_header_over_the_dimension_limit_is_refused_before_any_decode(): void
    {
        config(['openpne.images.max_upload_dimension' => 100]);

        try {
            $this->processor()->process($this->pngHeaderClaiming(40000, 40000), 'image/png', ImageSpec::canonical('png'));
            $this->fail('A 40000x40000 header was accepted.');
        } catch (ImageProcessingException $e) {
            // The message names the declared size: the bytes carry no pixels, so a decode would have
            // failed with a different complaint.
            $this->assertStringContainsString('40000x40000', $e->getMessage());
        }
    }

    public function test_source_bytes_over_the_cap_are_refused_before_any_decode(): void
    {
        config(['openpne.images.max_source_kilobytes' => 1]);

        try {
            $this->processor()->process($this->pngWithGarbagePixels(10, 10, 4096), 'image/png', ImageSpec::canonical('png'));
            $this->fail('A source over the cap was accepted.');
        } catch (ImageProcessingException $e) {
            // The body would fail the decoder with a different message, so this one proves the order.
            $this->assertStringContainsString('source limit', $e->getMessage());
        }
    }

    public function test_a_header_over_the_pixel_budget_is_refused_within_the_side_limit(): void
    {
        config(['openpne.images.max_upload_dimension' => 5000, 'openpne.images.max_source_pixels' => 1_000_000]);

        try {
            $this->processor()->process($this->pngHeaderClaiming(2000, 1000), 'image/png', ImageSpec::canonical('png'));
            $this->fail('2 MP was accepted against a 1 MP budget.');
        } catch (ImageProcessingException $e) {
            $this->assertStringContainsString('2000x1000', $e->getMessage());
        }
    }

    protected function canonical(string $bytes, string $mime): ProcessedImage
    {
        return $this->processor()->process($bytes, $mime, ImageSpec::canonical((string) ImageSpec::formatFor($mime)));
    }

    protected function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/images/'.$name));
    }

    /** @return array{0: int, 1: int} */
    private function dimensions(string $bytes): array
    {
        $size = getimagesizefromstring($bytes);

        return [$size[0], $size[1]];
    }

    protected function frameCount(string $bytes): int
    {
        return count(Decoder::decode($bytes)->frames());
    }

    protected function png(int $width, int $height): string
    {
        $gd = imagecreatetruecolor($width, $height);
        imagefill($gd, 0, 0, (int) imagecolorallocate($gd, 200, 30, 30));
        ob_start();
        imagepng($gd);

        return (string) ob_get_clean();
    }

    private function transparentPng(int $side): string
    {
        $gd = imagecreatetruecolor($side, $side);
        imagealphablending($gd, false);
        imagesavealpha($gd, true);
        imagefill($gd, 0, 0, (int) imagecolorallocatealpha($gd, 0, 0, 0, 127));
        ob_start();
        imagepng($gd);

        return (string) ob_get_clean();
    }

    /** $frames frames of different shades on a square logical screen of $side. */
    protected function animatedGif(int $frames = 3, int $side = 60): string
    {
        $builder = Builder::canvas($side, $side);

        for ($i = 0; $i < $frames; $i++) {
            $gd = imagecreate($side, $side);
            imagecolorallocate($gd, ($i * 37) % 256, 40, 200);
            ob_start();
            imagegif($gd);
            $builder->addFrame(source: (string) ob_get_clean(), delay: 0.1);
        }

        $builder->setLoops(0);

        return $builder->encode();
    }

    /** A complete PNG container whose IHDR declares $width x $height and which carries no pixels. */
    private function pngHeaderClaiming(int $width, int $height): string
    {
        return "\x89PNG\r\n\x1a\n".$this->pngChunk('IHDR', pack('NN', $width, $height)."\x08\x06\x00\x00\x00").$this->pngChunk('IEND', '');
    }

    /** As above, with an IDAT of $garbage bytes that is not a zlib stream. */
    protected function pngWithGarbagePixels(int $width, int $height, int $garbage): string
    {
        return "\x89PNG\r\n\x1a\n"
            .$this->pngChunk('IHDR', pack('NN', $width, $height)."\x08\x06\x00\x00\x00")
            .$this->pngChunk('IDAT', str_repeat("\xAB", $garbage))
            .$this->pngChunk('IEND', '');
    }

    private function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }
}
