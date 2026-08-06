<?php

declare(strict_types=1);

namespace Tests\Unit\LinkCard;

use App\LinkCard\ImageContainer;
use PHPUnit\Framework\TestCase;

/**
 * Every case here is one a marker search gets wrong in one direction or the other, which is why this
 * walks the container structure instead.
 */
class ImageContainerTest extends TestCase
{
    public function test_a_two_frame_gif_without_a_loop_extension_is_animated(): void
    {
        // The NETSCAPE looping extension controls repetition; it is not what makes a GIF animated,
        // and a two-frame file needs none. Requiring it — or counting a byte pattern that a
        // non-zero colour-table byte shifts — reports this as a still image while a decoder
        // faithfully expands both frames.
        $this->assertTrue(ImageContainer::isAnimated($this->gif(frames: 2, loopExtension: false), 'image/gif'));
    }

    public function test_a_two_frame_gif_with_a_loop_extension_is_animated(): void
    {
        $this->assertTrue(ImageContainer::isAnimated($this->gif(frames: 2, loopExtension: true), 'image/gif'));
    }

    public function test_a_single_frame_gif_is_not_animated(): void
    {
        $this->assertFalse(ImageContainer::isAnimated($this->gif(frames: 1, loopExtension: false), 'image/gif'));
    }

    public function test_a_real_still_gif_is_not_animated(): void
    {
        $image = imagecreatetruecolor(8, 8);
        ob_start();
        imagegif($image);

        $this->assertFalse(ImageContainer::isAnimated((string) ob_get_clean(), 'image/gif'));
    }

    public function test_an_apng_is_animated_however_far_in_its_actl_sits(): void
    {
        // acTL is only required to precede IDAT; nothing bounds how much metadata may come first, so
        // a fixed-size prefix scan misses it.
        $padding = $this->pngChunk('tEXt', str_repeat('x', 8192));

        $this->assertTrue(ImageContainer::isAnimated($this->png($padding.$this->pngChunk('acTL', str_repeat("\x00", 8))), 'image/png'));
    }

    public function test_a_still_png_is_not_animated(): void
    {
        $image = imagecreatetruecolor(8, 8);
        ob_start();
        imagepng($image);

        $this->assertFalse(ImageContainer::isAnimated((string) ob_get_clean(), 'image/png'));
    }

    public function test_a_png_whose_text_mentions_actl_is_not_animated(): void
    {
        // The other direction: a marker search invents animations out of ordinary content.
        $this->assertFalse(ImageContainer::isAnimated($this->png($this->pngChunk('tEXt', 'Comment: acTL is a chunk name')), 'image/png'));
    }

    public function test_an_animated_webp_is_animated_behind_padding(): void
    {
        // A RIFF file may legally carry a JUNK chunk before ANIM, pushing it past any window.
        $this->assertTrue(ImageContainer::isAnimated(
            $this->webp($this->riffChunk('JUNK', str_repeat("\x00", 5000)).$this->riffChunk('ANIM', str_repeat("\x00", 6))),
            'image/webp',
        ));
    }

    public function test_a_webp_flagged_animated_in_its_extended_header_is_animated(): void
    {
        $this->assertTrue(ImageContainer::isAnimated($this->webp($this->riffChunk('VP8X', "\x02".str_repeat("\x00", 9))), 'image/webp'));
    }

    public function test_a_still_webp_is_not_animated(): void
    {
        $this->assertFalse(ImageContainer::isAnimated($this->webp($this->riffChunk('VP8 ', str_repeat("\x00", 16))), 'image/webp'));
    }

    public function test_truncated_and_malformed_input_terminates(): void
    {
        // A malformed file must not be able to spin the walk; these only need to return.
        $this->assertFalse(ImageContainer::isAnimated('', 'image/gif'));
        $this->assertFalse(ImageContainer::isAnimated('GIF89a', 'image/gif'));
        $this->assertFalse(ImageContainer::isAnimated(substr($this->gif(frames: 2, loopExtension: true), 0, 20), 'image/gif'));
        $this->assertFalse(ImageContainer::isAnimated("\x89PNG\r\n\x1a\n", 'image/png'));
        $this->assertFalse(ImageContainer::isAnimated('RIFF', 'image/webp'));
        // A chunk claiming a huge length must end the walk rather than loop on it.
        $this->assertFalse(ImageContainer::isAnimated($this->png($this->pngChunk('tEXt', 'x').str_repeat("\xFF", 8)), 'image/png'));
    }

    public function test_an_unknown_type_is_never_animated(): void
    {
        $this->assertFalse(ImageContainer::isAnimated('anything', 'image/jpeg'));
    }

    /** A structurally valid GIF with $frames image descriptors. */
    private function gif(int $frames, bool $loopExtension): string
    {
        // Global colour table with a deliberately non-zero final byte, which shifts the byte pattern
        // a naive scan keys on.
        $gif = "GIF89a\x08\x00\x08\x00\x80\x00\x00".pack('C*', 0xFF, 0xFF, 0xFF, 0x00, 0x00, 0x01);

        if ($loopExtension) {
            $gif .= "\x21\xFF\x0BNETSCAPE2.0\x03\x01\x00\x00\x00";
        }

        for ($i = 0; $i < $frames; $i++) {
            $gif .= "\x21\xF9\x04\x00\x00\x00\x00\x00";           // Graphic Control Extension
            $gif .= "\x2C\x00\x00\x00\x00\x08\x00\x08\x00\x00";   // Image Descriptor, no local table
            $gif .= "\x02\x02\x44\x01\x00";                       // LZW code size + one sub-block
        }

        return $gif."\x3B";
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
