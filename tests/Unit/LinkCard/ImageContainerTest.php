<?php

declare(strict_types=1);

namespace Tests\Unit\LinkCard;

use App\LinkCard\ImageContainer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The question is "is this provably one still frame?", so every case that is not a completed walk
 * proving that must answer false. Several of these are shapes an earlier version got wrong — by
 * searching for markers, and then by treating its own block limit as an all-clear.
 */
class ImageContainerTest extends TestCase
{
    public function test_a_single_frame_gif_is_a_safe_still(): void
    {
        $this->assertTrue(ImageContainer::isSafeStill($this->gif(frames: 1, loopExtension: false), 'image/gif'));
    }

    public function test_a_real_still_gif_is_a_safe_still(): void
    {
        $this->assertTrue(ImageContainer::isSafeStill($this->encoded('gif'), 'image/gif'));
    }

    public function test_a_two_frame_gif_without_a_loop_extension_is_refused(): void
    {
        // The NETSCAPE looping extension controls repetition; it is not what makes a GIF animated,
        // and a two-frame file needs none. Requiring it — or counting a byte pattern that a non-zero
        // colour-table byte shifts — calls this a still image while a decoder expands both frames.
        $this->assertFalse(ImageContainer::isSafeStill($this->gif(frames: 2, loopExtension: false), 'image/gif'));
    }

    public function test_a_two_frame_gif_with_a_loop_extension_is_refused(): void
    {
        $this->assertFalse(ImageContainer::isSafeStill($this->gif(frames: 2, loopExtension: true), 'image/gif'));
    }

    public function test_an_animation_hidden_behind_the_block_limit_is_refused(): void
    {
        // The block limit is a CPU bound, not a window: padding the file with legal comment blocks
        // until the walk runs out of budget must not report a still image. Treating the limit as an
        // all-clear turns the bound itself into the fixed window this parser exists to avoid — a
        // ~20 KB file, well inside the read cap, that a decoder expands to two frames.
        $comment = "\x21\xFE\x01\x41\x00";

        $this->assertFalse(ImageContainer::isSafeStill(
            $this->gif(frames: 2, loopExtension: false, betweenFrames: str_repeat($comment, 4095)),
            'image/gif',
        ));
    }

    public function test_a_still_gif_padded_past_the_block_limit_is_also_refused(): void
    {
        // The honest counterpart of the case above. It cannot be told apart from the attack without
        // parsing further than the budget allows, so it is refused too — the cost is one card's
        // image, where the other direction costs the worker.
        $comment = "\x21\xFE\x01\x41\x00";

        $this->assertFalse(ImageContainer::isSafeStill(
            $this->gif(frames: 1, loopExtension: false, betweenFrames: str_repeat($comment, 4095)),
            'image/gif',
        ));
    }

    public function test_a_still_png_is_a_safe_still(): void
    {
        $this->assertTrue(ImageContainer::isSafeStill($this->encoded('png'), 'image/png'));
    }

    public function test_an_apng_is_refused_however_far_in_its_actl_sits(): void
    {
        // acTL is only required to precede IDAT; nothing bounds how much metadata may come first, so
        // a fixed-size prefix scan misses it.
        $padding = $this->pngChunk('tEXt', str_repeat('x', 8192));

        $this->assertFalse(ImageContainer::isSafeStill(
            $this->png($padding.$this->pngChunk('acTL', str_repeat("\x00", 8))),
            'image/png',
        ));
    }

    public function test_a_png_whose_text_mentions_actl_is_a_safe_still(): void
    {
        // The other direction: a marker search invents animations out of ordinary content.
        $this->assertTrue(ImageContainer::isSafeStill(
            $this->png($this->pngChunk('tEXt', 'Comment: acTL is a chunk name')),
            'image/png',
        ));
    }

    public function test_a_png_padded_past_the_block_limit_is_refused(): void
    {
        $chunks = str_repeat($this->pngChunk('tEXt', 'x'), 4096);

        $this->assertFalse(ImageContainer::isSafeStill($this->png($chunks), 'image/png'));
    }

    public function test_a_still_webp_is_a_safe_still(): void
    {
        // A real encode rather than a zero-filled chunk: the walk reads the canvas out of the
        // bitstream header, so a synthetic payload proves nothing about the still case.
        $gd = imagecreatetruecolor(40, 30);
        ob_start();
        imagewebp($gd, null, 80);

        $this->assertTrue(ImageContainer::isSafeStill((string) ob_get_clean(), 'image/webp'));
    }

    public function test_an_animated_webp_behind_padding_is_refused(): void
    {
        // A RIFF file may legally carry a JUNK chunk before ANIM, pushing it past any window.
        $this->assertFalse(ImageContainer::isSafeStill(
            $this->webp($this->riffChunk('JUNK', str_repeat("\x00", 5000)).$this->riffChunk('ANIM', str_repeat("\x00", 6))),
            'image/webp',
        ));
    }

    public function test_a_webp_flagged_animated_in_its_extended_header_is_refused(): void
    {
        $this->assertFalse(ImageContainer::isSafeStill($this->webp($this->riffChunk('VP8X', "\x02".str_repeat("\x00", 9))), 'image/webp'));
    }

    public function test_a_jpeg_needs_no_proof(): void
    {
        // The format has no animation container, so there is nothing to walk.
        $this->assertTrue(ImageContainer::isSafeStill($this->encoded('jpeg'), 'image/jpeg'));
    }

    /**
     * @param  string  $bytes  A container the parser cannot walk to a conclusion
     */
    #[DataProvider('unprovable')]
    public function test_anything_the_parser_cannot_finish_is_refused(string $bytes, string $mime): void
    {
        // "The parser gave up" and "this is a still image" must never be the same answer: the first
        // is what an attacker can arrange, and treating it as safe is how the guard is bypassed.
        $this->assertFalse(ImageContainer::isSafeStill($bytes, $mime));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unprovable(): array
    {
        return [
            'empty gif' => ['', 'image/gif'],
            'gif header only' => ['GIF89a', 'image/gif'],
            'gif with no trailer' => ["GIF89a\x08\x00\x08\x00\x00\x00\x00", 'image/gif'],
            'gif with an unknown block' => ["GIF89a\x08\x00\x08\x00\x00\x00\x00\x99\x3B", 'image/gif'],
            'png signature only' => ["\x89PNG\r\n\x1a\n", 'image/png'],
            'png with no image data' => ["\x89PNG\r\n\x1a\n", 'image/png'],
            'webp header only' => ['RIFF', 'image/webp'],
            'webp with a chunk running past the end' => ["RIFF\x10\x00\x00\x00WEBPVP8 \xFF\xFF\xFF\x7F", 'image/webp'],
            'unknown media type' => ['whatever', 'image/tiff'],
        ];
    }

    /** A structurally valid GIF with $frames image descriptors. */
    private function gif(int $frames, bool $loopExtension, string $betweenFrames = ''): string
    {
        // Global colour table with a deliberately non-zero final byte, which shifts the byte pattern
        // a naive scan keys on.
        $gif = "GIF89a\x08\x00\x08\x00\x80\x00\x00".pack('C*', 0xFF, 0xFF, 0xFF, 0x00, 0x00, 0x01);

        if ($loopExtension) {
            $gif .= "\x21\xFF\x0BNETSCAPE2.0\x03\x01\x00\x00\x00";
        }

        $frame = "\x21\xF9\x04\x00\x00\x00\x00\x00"           // Graphic Control Extension
            ."\x2C\x00\x00\x00\x00\x08\x00\x08\x00\x00"       // Image Descriptor, no local table
            ."\x02\x02\x44\x01\x00";                          // LZW code size + one sub-block

        for ($i = 0; $i < $frames; $i++) {
            $gif .= $frame;

            if ($i === 0) {
                $gif .= $betweenFrames;
            }
        }

        return $gif."\x3B";
    }

    private function encoded(string $format): string
    {
        $image = imagecreatetruecolor(8, 8);
        ob_start();
        match ($format) {
            'gif' => imagegif($image),
            'png' => imagepng($image),
            'jpeg' => imagejpeg($image),
        };

        return (string) ob_get_clean();
    }

    private function png(string $chunks): string
    {
        return "\x89PNG\r\n\x1a\n"
            .$this->pngChunk('IHDR', pack('NN', 8, 8)."\x08\x02\x00\x00\x00")
            .$chunks
            .$this->pngChunk('IDAT', "\x00")
            .$this->pngChunk('IEND', '');
    }

    private function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }

    private function webp(string $chunks): string
    {
        return 'RIFF'.pack('V', 4 + strlen($chunks)).'WEBP'.$chunks;
    }

    private function riffChunk(string $fourcc, string $data): string
    {
        return $fourcc.pack('V', strlen($data)).$data.(strlen($data) % 2 === 1 ? "\x00" : '');
    }
}
