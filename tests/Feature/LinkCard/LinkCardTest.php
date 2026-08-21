<?php

declare(strict_types=1);

namespace Tests\Feature\LinkCard;

use App\Files\ImageDimensions;
use App\Models\File;
use App\Models\LinkCard;
use App\Support\LinkCardStatus;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LinkCardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The shape switch, at its boundaries.
     *
     * @param  array{int, int}|string|null  $size  the size the picture renders at, null for a picture
     *                                             with no recorded size, 'none' for no picture
     */
    #[DataProvider('imageSizes')]
    public function test_only_a_big_landscape_picture_is_drawn_full_width(array|string|null $size, bool $expected, string $why): void
    {
        $card = $this->cardWithImageSized($size);

        $this->assertSame($expected, $card->hasLargeImage(), $why);
    }

    /**
     * @return array<string, array{array{int, int}|string|null, bool, string}>
     */
    public static function imageSizes(): array
    {
        return [
            'the common og banner' => [[1200, 630], true, '1200x630 is what a preview image looks like.'],
            'exactly 4:3' => [[400, 300], true, 'The ratio boundary is inclusive, by cross-multiplication.'],
            'just inside 4:3' => [[399, 300], false, '399x300 is narrower than 4:3 and must stay a thumbnail.'],
            'square' => [[200, 200], false, 'A square picture is an icon, not a preview.'],
            'portrait' => [[600, 900], false, 'Taller than wide is never the full-width shape.'],
            'both sides just under the floor' => [[199, 149], false, 'Below 200 a side has nothing to enlarge.'],
            'at the height floor' => [[267, 200], true, 'The floor is inclusive: 200 is big enough.'],
            'one side under the floor' => [[200, 150], false, 'Both sides must clear it, not just the wide one.'],
            // The term neither Signal nor Mattermost has: Mattermost would draw this one wide.
            'wide but short' => [[1000, 150], false, 'A short banner drawn full width reads as a stripe.'],
            // `files` never records a zero side — ImageDimensions reads one as no size at all — so
            // the case an unmeasurable picture actually produces is this one, not a 0.
            'no size recorded' => [null, false, 'A picture nothing could measure cannot be laid out.'],
            'no picture at all' => ['none', false, 'Nothing to draw large.'],
        ];
    }

    public function test_the_shape_follows_the_size_the_picture_renders_at(): void
    {
        // The card row and the File disagree for a sideways-shot JPEG: the row holds what the
        // container declared (read from the header, before decoding, as part of the size guard) and
        // the File holds what the bytes draw as, EXIF Orientation applied. The shape has to follow
        // the second, or a portrait photo is laid out as a landscape one.
        $file = File::factory()->create(['width' => 300, 'height' => 900]);
        $card = LinkCard::factory()->create([
            'image_file_id' => $file->id,
            'image_width' => 900,
            'image_height' => 300,
        ]);

        $this->assertFalse($card->hasLargeImage(), 'The declared size won over the rendered one.');
    }

    public function test_the_two_recorded_sizes_really_do_diverge(): void
    {
        // Guards the premise of the test above rather than any of our code: if the header and the
        // rendered size ever stopped disagreeing, the care taken over which one to read would be
        // cargo, and this says so out loud instead.
        $bytes = (string) file_get_contents(base_path('tests/Fixtures/images/jpeg-gps-orientation.jpg'));

        $this->assertSame([12, 6], array_slice((array) getimagesizefromstring($bytes), 0, 2));
        $this->assertSame([6, 12], ImageDimensions::fromBytes($bytes));
    }

    public function test_a_url_can_only_have_one_card(): void
    {
        // The uniqueness is what makes a widely-shared link cost one fetch rather than one per post,
        // and it is what the fetch worker's claim relies on.
        LinkCard::factory()->create(['url_hash' => str_repeat('a', 64)]);

        $this->expectException(QueryException::class);

        LinkCard::factory()->create(['url_hash' => str_repeat('a', 64)]);
    }

    public function test_only_a_fetched_card_with_a_title_renders(): void
    {
        // A title is the minimum. An image alone is a mystery box, and a card with neither is worse
        // than the bare link it replaces — so status alone is not the test, because a fetch can
        // succeed against a page carrying no metadata at all.
        $this->assertTrue(LinkCard::factory()->create()->isRenderable());
        $this->assertFalse(LinkCard::factory()->pending()->create()->isRenderable());
        $this->assertFalse(LinkCard::factory()->failed()->create()->isRenderable());
        $this->assertFalse(LinkCard::factory()->create(['title' => null])->isRenderable());
        $this->assertFalse(LinkCard::factory()->create(['title' => ''])->isRenderable());
    }

    public function test_staleness_is_driven_by_the_expiry(): void
    {
        $this->assertFalse(LinkCard::factory()->create()->isStale());
        $this->assertTrue(LinkCard::factory()->stale()->create()->isStale());
        // Never fetched, so there is nothing to keep.
        $this->assertTrue(LinkCard::factory()->pending()->create()->isStale());
    }

    public function test_deleting_the_image_leaves_the_card_standing(): void
    {
        // A card whose picture went away is still a usable card; losing the row would silently
        // remove the preview from every post that shares the link.
        $file = File::factory()->create();
        $card = LinkCard::factory()->create(['image_file_id' => $file->id]);

        $file->delete();

        $this->assertSame(null, $card->fresh()?->image_file_id);
        $this->assertTrue($card->fresh()?->isRenderable());
    }

    public function test_the_status_column_round_trips_as_an_enum(): void
    {
        $card = LinkCard::factory()->failed()->create();

        $this->assertSame(LinkCardStatus::Failed, $card->fresh()?->status);
    }

    /**
     * A renderable card whose picture renders at $size — null for a picture whose size was never
     * recorded, 'none' for a card with no picture at all.
     *
     * @param  array{int, int}|string|null  $size
     */
    private function cardWithImageSized(array|string|null $size): LinkCard
    {
        $file = $size === 'none'
            ? null
            : File::factory()->create(['width' => $size[0] ?? null, 'height' => $size[1] ?? null]);

        return LinkCard::factory()->create(['image_file_id' => $file?->id]);
    }
}
