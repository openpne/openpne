<?php

namespace Tests\Feature\File;

use App\Features\Diary\Serializers\DiarySerializer;
use App\Features\DirectMessage\Serializers\DirectMessageSerializer;
use App\Features\GroupEvent\Serializers\GroupEventSerializer;
use App\Features\GroupTalk\Serializers\GroupMessageSerializer;
use App\Features\GroupTopic\Serializers\GroupTopicSerializer;
use App\Features\Timeline\Serializers\TimelinePostSerializer;
use App\Files\ImageProcessor;
use App\Files\ImageTransform;
use App\Models\DiaryImage;
use App\Models\DirectMessageFile;
use App\Models\File;
use App\Models\GroupEventImage;
use App\Models\GroupMessageImage;
use App\Models\GroupTopicImage;
use App\Models\TimelinePostImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FrameKeepingProcessor;
use Tests\TestCase;

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

    /** @return iterable<string, array{callable(): array<string, mixed>}> */
    public static function orphanedImages(): iterable
    {
        // The FK cascades, so no stored row can reach this state; an unsaved model is how the
        // guard gets exercised.
        yield 'timeline' => [fn (): array => TimelinePostSerializer::image(new TimelinePostImage)];
        yield 'diary' => [fn (): array => DiarySerializer::image(new DiaryImage)];
        yield 'group topic' => [fn (): array => GroupTopicSerializer::image(new GroupTopicImage)];
        yield 'group event' => [fn (): array => GroupEventSerializer::image(new GroupEventImage)];
        yield 'group talk' => [fn (): array => GroupMessageSerializer::image(new GroupMessageImage)];
        yield 'direct message' => [fn (): array => DirectMessageSerializer::image(new DirectMessageFile)];
    }

    /** @param  callable(File): array<string, mixed>  $serialize */
    #[DataProvider('serializers')]
    public function test_it_ships_the_fit_ladder_and_the_intrinsic_size(callable $serialize): void
    {
        $file = File::factory()->create(['type' => 'image/png', 'width' => 1600, 'height' => 900]);

        $entry = $serialize($file);

        $this->assertSame($file->url(), $entry['url']);
        $this->assertSame([
            ['url' => $file->thumbnailUrl(320, 320), 'box' => 320],
            ['url' => $file->thumbnailUrl(640, 640), 'box' => 640],
            ['url' => $file->thumbnailUrl(1200, 1200), 'box' => 1200],
        ], $entry['fitSources']);
        $this->assertSame(1600, $entry['width']);
        $this->assertSame(900, $entry['height']);
    }

    /** @param  callable(File): array<string, mixed>  $serialize */
    #[DataProvider('serializers')]
    public function test_the_crop_ladder_is_cut_at_the_cell_ratio(callable $serialize): void
    {
        // A `width` here is the candidate's true intrinsic width, which is what lets the client
        // ship it as a `w` descriptor; each rung's height must hold the cell's ratio, or CSS cover
        // would re-crop the source it was given.
        $file = File::factory()->create(['type' => 'image/png']);

        $crops = $serialize($file)['cropSources'];

        $this->assertSame([
            ['url' => $file->thumbnailUrl(300, 400, square: true), 'width' => 300],
            ['url' => $file->thumbnailUrl(600, 800, square: true), 'width' => 600],
        ], $crops['tall']);
        $this->assertSame([
            ['url' => $file->thumbnailUrl(300, 200, square: true), 'width' => 300],
            ['url' => $file->thumbnailUrl(600, 400, square: true), 'width' => 600],
        ], $crops['wide']);
    }

    /** @param  callable(File): array<string, mixed>  $serialize */
    #[DataProvider('serializers')]
    public function test_every_payload_carries_the_animated_ladder_and_a_still_ships_it_empty(callable $serialize): void
    {
        // The client reads `animatedSources.length` unguarded, so the key has to be there for a
        // still too — and empty, since only `animatedSources` may carry an `_a` URL.
        $entry = $serialize(File::factory()->create(['type' => 'image/gif', 'animated' => false]));

        $this->assertArrayHasKey('animatedSources', $entry);
        $this->assertSame([], $entry['animatedSources']);
    }

    /** @param  callable(File): array<string, mixed>  $serialize */
    #[DataProvider('serializers')]
    public function test_an_animating_file_ships_the_animated_rungs_apart_from_the_stills(callable $serialize): void
    {
        // Both halves of the gate: the recorded fact and a processor that keeps frames (the default
        // lane's GD does not, so without the stub every serializer would ship [] and pass for nothing).
        $this->app->instance(ImageProcessor::class, new FrameKeepingProcessor);
        $file = File::factory()->create(['type' => 'image/gif', 'animated' => true]);

        $entry = $serialize($file);

        $this->assertSame([
            ['url' => $file->thumbnailUrl(320, 320, animated: true), 'box' => 320],
            ['url' => $file->thumbnailUrl(640, 640, animated: true), 'box' => 640],
            ['url' => $file->thumbnailUrl(1200, 1200, animated: true), 'box' => 1200],
        ], $entry['animatedSources']);

        foreach (array_column($entry['animatedSources'], 'url') as $url) {
            $this->assertStringContainsString('_a/', $url);
        }
        $stills = [$entry['thumbnailUrl'], ...array_column($entry['fitSources'], 'url'), ...array_column(array_merge(...array_values($entry['cropSources'])), 'url')];
        foreach ($stills as $url) {
            $this->assertStringNotContainsString('_a/', $url);
        }
    }

    /** @param  callable(File): array<string, mixed>  $serialize */
    #[DataProvider('serializers')]
    public function test_every_ladder_size_is_whitelisted(callable $serialize): void
    {
        // An unlisted size is a 404, so a candidate the whitelist does not cover is a broken image
        // rather than a slow one; the animated rungs exist only under a frame-keeping processor.
        $this->app->instance(ImageProcessor::class, new FrameKeepingProcessor);
        $entry = $serialize(File::factory()->create(['type' => 'image/gif', 'animated' => true]));
        $rungs = array_merge($entry['fitSources'], $entry['animatedSources'], ...array_values($entry['cropSources']));
        $this->assertCount(10, $rungs);

        foreach (array_column($rungs, 'url') as $url) {
            $this->assertSame(1, preg_match('#/cache/img/[^/]+/([^/]+)/#', $url, $m), $url);
            $this->assertNotNull(ImageTransform::fromGeometry($m[1]), $m[1]);
        }
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

    /** @param  callable(): array<string, mixed>  $serialize */
    #[DataProvider('orphanedImages')]
    public function test_a_row_whose_file_is_gone_ships_no_sources(callable $serialize): void
    {
        // Empty, not a ladder of empty strings: a candidate with no URL is a broken request the
        // client would still make.
        $entry = $serialize();

        $this->assertSame([], $entry['fitSources']);
        $this->assertSame([], $entry['cropSources']);
        $this->assertSame([], $entry['animatedSources']);
    }
}
