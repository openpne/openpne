<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * An `_a` URL is made by ImageLadder alone and read by the hero alone, so any other file asking for
 * an animated variant or reading `animatedSources` has slipped past the rule (docs/internals/images.md,
 * "Which placements animate").
 */
class AnimatedSourcesProducersTest extends TestCase
{
    private const PRODUCERS = ['app/Files/ImageLadder.php'];

    private const CONSUMERS = ['resources/js/components/image-grid.tsx', 'resources/js/lib/image-sources.ts'];

    public function test_an_animated_variant_url_is_asked_for_by_the_ladder_alone(): void
    {
        $this->assertSame(self::PRODUCERS, $this->filesContaining('animated: true', ['app', 'routes', 'resources/views'], ['*.php']));
    }

    public function test_the_animated_ladder_is_read_by_the_hero_alone(): void
    {
        $this->assertSame(self::CONSUMERS, $this->filesContaining('animatedSources', ['resources/js'], ['*.ts', '*.tsx']));
    }

    /**
     * @param  list<string>  $in
     * @param  list<string>  $names
     * @return list<string>
     */
    private function filesContaining(string $needle, array $in, array $names): array
    {
        $finder = (new Finder)
            ->files()
            ->in(array_map(base_path(...), $in))
            ->name($names)
            ->notName('*.test.*')
            ->contains($needle);

        $found = [];
        foreach ($finder as $file) {
            $found[] = str_replace(base_path().'/', '', $file->getRealPath());
        }
        sort($found);

        return $found;
    }
}
