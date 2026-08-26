<?php

namespace Tests\Unit\Views;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Every thumbnail size a view or serializer asks for is in `openpne.images.allowed_sizes`
 * (docs/internals/images.md). A size outside the list is served as a 404 behind a broken image, and
 * only a fixture with a real picture would notice.
 */
class ThumbnailSizesTest extends TestCase
{
    public function test_every_requested_thumbnail_size_is_allowed(): void
    {
        $config = require dirname(__DIR__, 3).'/config/openpne.php';
        $allowed = $config['images']['allowed_sizes'];
        $offenders = [];

        foreach ([dirname(__DIR__, 3).'/resources/views', dirname(__DIR__, 3).'/app'] as $dir) {
            foreach (Finder::create()->files()->in($dir)->name(['*.php']) as $file) {
                $source = $file->getContents();
                preg_match_all('/<x-classic\.image\b[^\n]*?:size="(\d+)"/', $source, $squares);
                preg_match_all('/thumbnailUrl\((\d+),\s*(\d+)/', $source, $pairs, PREG_SET_ORDER);
                $sizes = array_map(static fn (string $n): string => "{$n}x{$n}", $squares[1]);
                foreach ($pairs as $pair) {
                    $sizes[] = "{$pair[1]}x{$pair[2]}";
                }
                foreach (array_unique($sizes) as $size) {
                    if (! in_array($size, $allowed, true)) {
                        $offenders[] = $file->getRelativePathname().': '.$size;
                    }
                }
            }
        }

        $this->assertSame([], $offenders);
    }
}
