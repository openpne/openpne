<?php

declare(strict_types=1);

namespace Tests\Unit\Files;

use App\Files\AnimationProbe;
use Intervention\Gif\Builder;
use Tests\TestCase;

class AnimationProbeTest extends TestCase
{
    public function test_a_gif_is_animated_when_it_holds_more_than_one_frame(): void
    {
        $this->assertFalse(AnimationProbe::of($this->gif(1), 'image/gif'));
        $this->assertTrue(AnimationProbe::of($this->gif(3), 'image/gif'));
    }

    public function test_a_gif_cut_short_or_too_large_to_walk_is_not_known_either_way(): void
    {
        $this->assertNull(AnimationProbe::of(substr($this->gif(3), 0, 40), 'image/gif'));
        config(['openpne.images.max_gif_walk_kilobytes' => 1]);
        $this->assertNull(AnimationProbe::of(str_pad($this->gif(3), 1025, "\0"), 'image/gif'));
    }

    public function test_a_webp_is_animated_when_its_extended_header_says_so(): void
    {
        $this->assertTrue(AnimationProbe::of($this->webp('VP8X', "\x02".str_repeat("\x00", 9)), 'image/webp'));
        // The extended header with only the ICC or alpha flags: bit 0x20 or 0x10, never 0x02.
        $this->assertFalse(AnimationProbe::of($this->webp('VP8X', "\x30".str_repeat("\x00", 9)), 'image/webp'));
        $this->assertFalse(AnimationProbe::of($this->webp('VP8 ', str_repeat("\x00", 16)), 'image/webp'));
    }

    public function test_a_webp_cut_before_its_flags_is_not_known_either_way(): void
    {
        $this->assertNull(AnimationProbe::of(substr($this->webp('VP8X', "\x02".str_repeat("\x00", 9)), 0, 18), 'image/webp'));
        $this->assertNull(AnimationProbe::of('not a riff', 'image/webp'));
    }

    public function test_the_shipped_walk_bound_is_the_documented_eight_megabytes(): void
    {
        // Pinned against .env.example and docs/internals/images.md, which quote the number.
        $this->assertSame(8192, AnimationProbe::DEFAULT_GIF_WALK_KILOBYTES);
        config(['openpne.images.max_gif_walk_kilobytes' => 0]);
        $this->assertSame(8192 * 1024, AnimationProbe::maxGifWalkBytes());
        $this->assertFalse(AnimationProbe::wouldWalk('image/gif', 8192 * 1024 + 1));
        $this->assertTrue(AnimationProbe::wouldWalk('image/webp', PHP_INT_MAX));
    }

    public function test_a_png_or_jpeg_is_never_animated(): void
    {
        // Neither processor keeps an APNG's frames (the contract test pins the acTL chunk gone).
        $this->assertFalse(AnimationProbe::of((string) file_get_contents(base_path('tests/Fixtures/images/apng-2frames.png')), 'image/png'));
        $this->assertFalse(AnimationProbe::of((string) file_get_contents(base_path('tests/Fixtures/images/jpeg-copyright.jpg')), 'image/jpeg'));
    }

    private function gif(int $frames): string
    {
        $builder = Builder::canvas(8, 8);

        for ($i = 0; $i < $frames; $i++) {
            $gd = imagecreate(8, 8);
            imagecolorallocate($gd, ($i * 60) % 256, 40, 200);
            ob_start();
            imagegif($gd);
            $builder->addFrame(source: (string) ob_get_clean(), delay: 0.1);
        }

        return $builder->encode();
    }

    private function webp(string $fourcc, string $payload): string
    {
        $chunk = $fourcc.pack('V', strlen($payload)).$payload.(strlen($payload) % 2 ? "\0" : '');

        return 'RIFF'.pack('V', 4 + strlen($chunk)).'WEBP'.$chunk;
    }
}
