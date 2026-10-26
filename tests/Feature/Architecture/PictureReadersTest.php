<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * A slot relation holds pictures and files alike, so a reader that means "photo" goes through
 * `pictures()` or `PostAttachments::partition()`; counting or testing the slot relation itself
 * would call a PDF a photo (docs/internals/attachments.md, "Pictures and files").
 */
class PictureReadersTest extends TestCase
{
    public function test_no_sql_count_or_existence_check_reads_the_slot_relation_as_pictures(): void
    {
        $slotCount = "/withCount\\(\\[?[^)]*'(images|files)'[^)]*\\)|withExists\\('(images|files)'\\)|loadCount\\('(images|files)'\\)/";

        $this->assertSame([], $this->filesContaining($slotCount, ['app'], ['*.php']));
        $this->assertSame([], $this->filesContaining('/images_count|files_exists|images_exists/', ['resources/views'], ['*.php']));
    }

    /** A slot existence read is allowed only as the "has files" half beside a pictures read on the same or an adjacent line. */
    public function test_a_slot_existence_read_sits_beside_a_pictures_read(): void
    {
        $slotExists = "/(images|files)_exists|->(images|files)\\(\\)->exists\\(\\)|whereHas\\('(images|files)'\\)|withExists\\(\\[[^\\]]*'(images|files)'/";

        $unpaired = [];
        foreach ($this->filesContaining($slotExists, ['app'], ['*.php']) as $file) {
            $lines = preg_split('/\R/', (string) file_get_contents(base_path($file))) ?: [];
            foreach ($lines as $i => $line) {
                if (preg_match($slotExists, $line) !== 1) {
                    continue;
                }
                $window = implode("\n", array_slice($lines, max(0, $i - 1), 3));
                if (! str_contains($window, 'pictures')) {
                    $unpaired[] = "{$file}:".($i + 1);
                }
            }
        }

        $this->assertSame([], $unpaired);
    }

    public function test_every_serializer_that_ships_images_partitions_them(): void
    {
        $shippers = $this->filesContaining("/'images' => .*->map\\(/", ['app'], ['*.php']);

        foreach ($shippers as $file) {
            $source = (string) file_get_contents(base_path($file));
            $this->assertStringContainsString('PostAttachments::partition', $source, "{$file} ships `images` without partitioning the slot rows");
        }
        $this->assertNotEmpty($shippers);
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
            ->contains($needle);

        $found = [];
        foreach ($finder as $file) {
            $found[] = str_replace(base_path().'/', '', $file->getRealPath());
        }
        sort($found);

        return $found;
    }
}
