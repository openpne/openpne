<?php

declare(strict_types=1);

namespace Tests\Feature\LinkCard;

use App\Models\File;
use App\Models\LinkCard;
use App\Support\LinkCardStatus;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LinkCardTest extends TestCase
{
    use RefreshDatabase;

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
}
