<?php

declare(strict_types=1);

namespace Tests\Unit\Files;

use App\Files\ImageInspector;
use App\Files\ImageStructure;
use Intervention\Gif\Builder;
use Tests\TestCase;

/**
 * The walk has to separate "one frame", "several frames" and "could not be read". The third is the
 * one that matters: a file the parser gave up on must not be routed anywhere, and the cases below
 * are the shapes that would otherwise be read as an answer.
 */
class ImageInspectorTest extends TestCase
{
    public function test_a_still_of_each_format_is_recognised(): void
    {
        foreach ([['image/jpeg', $this->jpeg()], ['image/png', $this->png()], ['image/gif', $this->gif(1)]] as [$mime, $bytes]) {
            $inspection = ImageInspector::inspect($bytes, $mime);

            $this->assertSame(ImageStructure::Still, $inspection->structure, $mime);
            $this->assertSame(1, $inspection->frames, $mime);
            $this->assertSame(40, $inspection->width, $mime);
        }
    }

    public function test_a_multi_frame_gif_is_animated_with_its_frame_count(): void
    {
        $inspection = ImageInspector::inspect($this->gif(3), 'image/gif');

        $this->assertSame(ImageStructure::Animated, $inspection->structure);
        $this->assertSame(3, $inspection->frames);
    }

    public function test_a_truncated_container_is_invalid_rather_than_still(): void
    {
        // The case a boolean "is it animated?" gets wrong: the walk never reaches the trailer, so it
        // has proved nothing — but nothing in the bytes it did read looked animated either.
        $gif = $this->gif(2);

        $this->assertSame(ImageStructure::Invalid, ImageInspector::inspect(substr($gif, 0, -20), 'image/gif')->structure);
    }

    public function test_bytes_after_the_gif_trailer_are_invalid(): void
    {
        $this->assertSame(ImageStructure::Invalid, ImageInspector::inspect($this->gif(1).'appended', 'image/gif')->structure);
    }

    public function test_a_png_with_a_broken_crc_is_invalid(): void
    {
        $png = $this->png();
        $corrupted = substr_replace($png, "\x00\x00\x00\x00", strlen($png) - 8, 4);

        $this->assertSame(ImageStructure::Invalid, ImageInspector::inspect($corrupted, 'image/png')->structure);
    }

    public function test_an_unsupported_media_type_is_invalid(): void
    {
        $this->assertSame(ImageStructure::Invalid, ImageInspector::inspect($this->png(), 'image/svg+xml')->structure);
    }

    public function test_the_shape_is_reported_for_the_caller_to_bound(): void
    {
        // Deployment limits (the upload rules' per-side cap, the outbound pixel cap) are the
        // caller's; this class reports what a decode would cost so they can be applied first.
        $inspection = ImageInspector::inspect($this->gif(3), 'image/gif');

        $this->assertSame(40 * 40 * 3, $inspection->decodedPixels());
    }

    public function test_an_animation_over_the_pixel_budget_is_invalid(): void
    {
        // 40x40 frames are tiny, so this trips the frame ceiling rather than the pixel budget —
        // which is the point of having both.
        $this->assertSame(ImageStructure::Invalid, ImageInspector::inspect($this->gif(201), 'image/gif')->structure);
    }

    public function test_an_extended_webp_still_reads_its_canvas(): void
    {
        $webp = $this->webp([$this->vp8x(animated: false, width: 40, height: 30), $this->chunk('VP8 ', str_repeat("\x00", 12))]);
        $inspection = ImageInspector::inspect($webp, 'image/webp');

        $this->assertSame(ImageStructure::Still, $inspection->structure);
        $this->assertSame([40, 30], [$inspection->width, $inspection->height]);
    }

    public function test_an_animated_webp_counts_its_frames(): void
    {
        $webp = $this->webp([
            $this->vp8x(animated: true, width: 40, height: 30),
            $this->chunk('ANIM', str_repeat("\x00", 6)),
            $this->chunk('ANMF', str_repeat("\x00", 16)),
            $this->chunk('ANMF', str_repeat("\x00", 16)),
        ]);
        $inspection = ImageInspector::inspect($webp, 'image/webp');

        $this->assertSame(ImageStructure::Animated, $inspection->structure);
        $this->assertSame(2, $inspection->frames);
    }

    public function test_a_webp_whose_header_and_chunks_disagree_is_invalid(): void
    {
        // Either direction is a disagreement the walk cannot resolve, so neither pipeline gets it.
        $flagWithoutFrames = $this->webp([$this->vp8x(animated: true, width: 40, height: 30), $this->chunk('VP8 ', str_repeat("\x00", 12))]);
        $framesWithoutFlag = $this->webp([
            $this->vp8x(animated: false, width: 40, height: 30),
            $this->chunk('ANIM', str_repeat("\x00", 6)),
            $this->chunk('ANMF', str_repeat("\x00", 16)),
        ]);

        $this->assertSame(ImageStructure::Invalid, ImageInspector::inspect($flagWithoutFrames, 'image/webp')->structure);
        $this->assertSame(ImageStructure::Invalid, ImageInspector::inspect($framesWithoutFlag, 'image/webp')->structure);
    }

    public function test_a_webp_whose_riff_size_does_not_describe_the_buffer_is_invalid(): void
    {
        $webp = $this->webp([$this->vp8x(animated: false, width: 40, height: 30), $this->chunk('VP8 ', str_repeat("\x00", 12))]);

        $this->assertSame(ImageStructure::Invalid, ImageInspector::inspect($webp."\x00\x00", 'image/webp')->structure);
    }

    /** @param list<string> $chunks */
    private function webp(array $chunks): string
    {
        $payload = 'WEBP'.implode('', $chunks);

        return 'RIFF'.pack('V', strlen($payload)).$payload;
    }

    private function chunk(string $fourcc, string $payload): string
    {
        // RIFF pads an odd-sized payload to an even boundary.
        return $fourcc.pack('V', strlen($payload)).$payload.(strlen($payload) % 2 === 1 ? "\x00" : '');
    }

    private function vp8x(bool $animated, int $width, int $height): string
    {
        $flags = $animated ? "\x02" : "\x00";
        $minusOne = fn (int $n): string => substr(pack('V', $n - 1), 0, 3); // 24-bit little endian

        return $this->chunk('VP8X', $flags."\x00\x00\x00".$minusOne($width).$minusOne($height));
    }

    private function jpeg(): string
    {
        $gd = imagecreatetruecolor(40, 40);
        ob_start();
        imagejpeg($gd, null, 80);

        return (string) ob_get_clean();
    }

    private function png(): string
    {
        $gd = imagecreatetruecolor(40, 40);
        ob_start();
        imagepng($gd);

        return (string) ob_get_clean();
    }

    private function gif(int $frames): string
    {
        $builder = Builder::canvas(40, 40);

        for ($i = 0; $i < $frames; $i++) {
            $builder->addFrame(source: $this->gifFrame($i), delay: 0.1);
        }

        return $builder->encode();
    }

    private function gifFrame(int $shade): string
    {
        $gd = imagecreate(40, 40);
        imagecolorallocate($gd, $shade % 255, 40, 200);
        ob_start();
        imagegif($gd);

        return (string) ob_get_clean();
    }
}
