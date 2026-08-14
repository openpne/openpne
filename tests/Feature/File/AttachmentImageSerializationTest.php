<?php

namespace Tests\Feature\File;

use App\Features\Diary\Serializers\DiarySerializer;
use App\Features\DirectMessage\Serializers\DirectMessageSerializer;
use App\Features\GroupEvent\Serializers\GroupEventSerializer;
use App\Features\GroupTalk\Serializers\GroupMessageSerializer;
use App\Features\GroupTopic\Serializers\GroupTopicSerializer;
use App\Features\Timeline\Serializers\TimelinePostSerializer;
use App\Models\DiaryImage;
use App\Models\DirectMessageFile;
use App\Models\File;
use App\Models\GroupEventImage;
use App\Models\GroupMessageImage;
use App\Models\GroupTopicImage;
use App\Models\TimelinePostImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every surface that carries an attachment serializes it the same way, so one client component
 * renders an image wherever it is placed: the OpenPNE 3-era 120px square, the fit and square
 * sources a Modern grid picks from, and the stored intrinsic size (docs/internals/images.md).
 */
class AttachmentImageSerializationTest extends TestCase
{
    use RefreshDatabase;

    /** @return iterable<string, array{callable(File): array<string, mixed>}> */
    public static function serializers(): iterable
    {
        // Closures, not rows: a data provider runs before the application boots, so nothing here
        // may touch the database until the test method calls it.
        yield 'timeline' => [fn (File $file): array => TimelinePostSerializer::image(
            TimelinePostImage::factory()->create(['file_id' => $file->getKey()]),
        )];

        yield 'diary' => [fn (File $file): array => DiarySerializer::image(
            DiaryImage::factory()->create(['file_id' => $file->getKey()]),
        )];

        yield 'group topic' => [fn (File $file): array => GroupTopicSerializer::image(
            GroupTopicImage::factory()->create(['file_id' => $file->getKey()]),
        )];

        yield 'group event' => [fn (File $file): array => GroupEventSerializer::image(
            GroupEventImage::factory()->create(['file_id' => $file->getKey()]),
        )];

        yield 'group talk' => [fn (File $file): array => GroupMessageSerializer::image(
            GroupMessageImage::factory()->create(['file_id' => $file->getKey()]),
        )];

        yield 'direct message' => [fn (File $file): array => DirectMessageSerializer::image(
            DirectMessageFile::factory()->create(['file_id' => $file->getKey()]),
        )];
    }

    /** @param  callable(File): array<string, mixed>  $serialize */
    #[DataProvider('serializers')]
    public function test_it_ships_every_source_and_the_intrinsic_size(callable $serialize): void
    {
        $file = File::factory()->create(['type' => 'image/png', 'width' => 1600, 'height' => 900]);

        $entry = $serialize($file);

        $this->assertSame($file->url(), $entry['url']);
        $this->assertSame($file->thumbnailUrl(600, 600), $entry['fitUrl']);
        $this->assertSame($file->thumbnailUrl(1200, 1200), $entry['fit2xUrl']);
        $this->assertSame($file->thumbnailUrl(600, 600, square: true), $entry['squareUrl']);
        $this->assertSame($file->thumbnailUrl(1200, 1200, square: true), $entry['square2xUrl']);
        $this->assertSame(1600, $entry['width']);
        $this->assertSame(900, $entry['height']);
    }

    /** @param  callable(File): array<string, mixed>  $serialize */
    #[DataProvider('serializers')]
    public function test_the_120px_square_is_unchanged(callable $serialize): void
    {
        // Classic and the older Modern rows still read thumbnailUrl, so the new sources are added
        // beside it, never in place of it.
        $file = File::factory()->create(['type' => 'image/png']);

        $this->assertSame($file->thumbnailUrl(120, 120, square: true), $serialize($file)['thumbnailUrl']);
    }

    /** @param  callable(File): array<string, mixed>  $serialize */
    #[DataProvider('serializers')]
    public function test_a_file_without_a_recorded_size_ships_nulls(callable $serialize): void
    {
        // Every row predating the columns, plus anything that did not decode: the client has to
        // handle an unknown size, so the serializer must not invent one.
        $entry = $serialize(File::factory()->create(['type' => 'image/png']));

        $this->assertNull($entry['width']);
        $this->assertNull($entry['height']);
    }
}
