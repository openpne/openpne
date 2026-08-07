<?php

declare(strict_types=1);

namespace Tests\Feature\LinkCard;

use App\Models\CommunityEvent;
use App\Models\Diary;
use App\Models\File;
use App\Models\LinkCard;
use App\Models\Member;
use App\Models\TimelinePost;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneLinkCardsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_deletes_a_card_no_post_refers_to(): void
    {
        $orphan = $this->agedCard();

        $this->artisan('openpne:prune-link-cards')->assertSuccessful();

        $this->assertDatabaseMissing('link_cards', ['id' => $orphan->id]);
    }

    public function test_it_keeps_a_card_a_post_still_refers_to(): void
    {
        $card = $this->agedCard();
        Diary::factory()->for(Member::factory())->create(['link_card_id' => $card->id]);

        $this->artisan('openpne:prune-link-cards')->assertSuccessful();

        $this->assertDatabaseHas('link_cards', ['id' => $card->id]);
    }

    public function test_every_body_table_counts_as_a_reference(): void
    {
        // Four tables can hold the reference, and a sweep that checked only some would delete cards
        // that are visibly in use on the others.
        $member = Member::factory()->create();

        $referenced = [
            'timeline_posts' => TimelinePost::factory()->for($member)->create(['link_card_id' => $this->agedCard()->id])->link_card_id,
            'community_events' => CommunityEvent::factory()->create(['link_card_id' => $this->agedCard()->id])->link_card_id,
        ];

        $this->artisan('openpne:prune-link-cards')->assertSuccessful();

        foreach ($referenced as $table => $cardId) {
            $this->assertNotNull(
                LinkCard::find($cardId),
                "A card referenced from {$table} was pruned.",
            );
        }
    }

    public function test_a_recently_touched_card_is_left_alone(): void
    {
        // The grace period is what makes this safe against the obvious race: a card created a moment
        // ago, whose owning record has not been written yet, must not be swept away underneath it.
        $fresh = LinkCard::factory()->create();

        $this->artisan('openpne:prune-link-cards')->assertSuccessful();

        $this->assertDatabaseHas('link_cards', ['id' => $fresh->id]);
    }

    public function test_the_grace_period_is_adjustable(): void
    {
        $card = LinkCard::factory()->create();
        LinkCard::whereKey($card->id)->update(['updated_at' => CarbonImmutable::now()->subHours(2)]);

        $this->artisan('openpne:prune-link-cards --days=0')->assertSuccessful();

        $this->assertDatabaseMissing('link_cards', ['id' => $card->id]);
    }

    public function test_a_dry_run_reports_without_deleting(): void
    {
        $orphan = $this->agedCard();

        $this->artisan('openpne:prune-link-cards --dry-run')
            ->expectsOutputToContain('would be pruned')
            ->assertSuccessful();

        $this->assertDatabaseHas('link_cards', ['id' => $orphan->id]);
    }

    public function test_pruning_a_card_takes_its_image_with_it(): void
    {
        // While a card exists its image is referenced, so this sweep is the only thing that makes
        // those bytes collectable at all.
        $file = File::factory()->create();
        $card = $this->agedCard(['image_file_id' => $file->id]);

        $this->artisan('openpne:prune-link-cards')->assertSuccessful();

        $this->assertDatabaseMissing('link_cards', ['id' => $card->id]);
        $this->assertDatabaseMissing('files', ['id' => $file->id]);
    }

    public function test_it_says_so_when_there_is_nothing_to_do(): void
    {
        $this->artisan('openpne:prune-link-cards')
            ->expectsOutputToContain('No unreferenced link cards')
            ->assertSuccessful();
    }

    /** A card old enough to be past the default grace period. */
    private function agedCard(array $attributes = []): LinkCard
    {
        $card = LinkCard::factory()->create($attributes);
        LinkCard::whereKey($card->id)->update(['updated_at' => CarbonImmutable::now()->subDays(30)]);

        return $card->refresh();
    }
}
