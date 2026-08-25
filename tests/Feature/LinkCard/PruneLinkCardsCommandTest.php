<?php

declare(strict_types=1);

namespace Tests\Feature\LinkCard;

use App\LinkCard\CardContext;
use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\File;
use App\Models\GroupEvent;
use App\Models\GroupEventComment;
use App\Models\GroupMessage;
use App\Models\GroupTopic;
use App\Models\GroupTopicComment;
use App\Models\LinkCard;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\LinkCardStatus;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneLinkCardsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_runs_on_the_weekly_schedule(): void
    {
        $events = collect($this->app->make(Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'openpne:prune-link-cards'));

        $this->assertCount(1, $events, 'the prune is not registered on the schedule');
        $this->assertSame('10 3 * * 0', $events->first()->expression);
        $this->assertTrue($events->first()->runInBackground, 'a foreground sweep occupies schedule:run for its whole run');
    }

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

    public function test_an_internal_row_is_swept_on_the_same_terms_as_any_other(): void
    {
        // A pointer row is a row: nothing about it is special to the sweep, and a body that has since
        // been edited leaves one behind exactly as a fetched card does.
        $orphan = $this->agedCard(['status' => LinkCardStatus::Internal, 'internal_context' => 'diary', 'internal_record_id' => 1]);
        $used = $this->agedCard(['status' => LinkCardStatus::Internal, 'internal_context' => 'diary', 'internal_record_id' => 1]);
        Diary::factory()->for(Member::factory())->create(['link_card_id' => $used->id]);

        $this->artisan('openpne:prune-link-cards')->assertSuccessful();

        $this->assertDatabaseMissing('link_cards', ['id' => $orphan->id]);
        $this->assertDatabaseHas('link_cards', ['id' => $used->id]);
    }

    public function test_every_body_table_counts_as_a_reference(): void
    {
        // Any of these tables can hold the reference, and a sweep that checked only some would delete
        // cards that are visibly in use on the others — permanently, since the body it belonged to is
        // left marked as examined.
        $member = Member::factory()->create();

        $referenced = [
            'diaries' => Diary::factory()->for($member)->create(['link_card_id' => $this->agedCard()->id])->link_card_id,
            'group_topics' => GroupTopic::factory()->create(['link_card_id' => $this->agedCard()->id])->link_card_id,
            'group_events' => GroupEvent::factory()->create(['link_card_id' => $this->agedCard()->id])->link_card_id,
            'timeline_posts' => TimelinePost::factory()->for($member)->create(['link_card_id' => $this->agedCard()->id])->link_card_id,
            'group_messages' => GroupMessage::factory()->create(['link_card_id' => $this->agedCard()->id])->link_card_id,
            'diary_comments' => DiaryComment::factory()->create(['link_card_id' => $this->agedCard()->id])->link_card_id,
            'group_topic_comments' => GroupTopicComment::factory()->create(['link_card_id' => $this->agedCard()->id])->link_card_id,
            'group_event_comments' => GroupEventComment::factory()->create(['link_card_id' => $this->agedCard()->id])->link_card_id,
        ];

        // The sweep reads its tables from CardContext, so this list has to be that list: a kind added
        // there and not here would leave the claim in the method name untested.
        $this->assertSame(
            array_map(fn (CardContext $context): string => $context->table(), CardContext::cases()),
            array_keys($referenced),
        );

        $this->artisan('openpne:prune-link-cards')->assertSuccessful();

        foreach ($referenced as $table => $cardId) {
            $this->assertNotNull(
                LinkCard::find($cardId),
                "A card referenced from {$table} was pruned.",
            );
        }
    }

    public function test_a_card_adopted_after_it_was_selected_is_not_deleted(): void
    {
        // Cards are keyed by URL, so a new post of a URL nobody has used for weeks picks up the
        // *existing* row rather than making one — and the window between selecting candidates and
        // deleting them is exactly when that happens. cardFor does not touch updated_at, so the
        // grace period does not cover it.
        //
        // Getting this wrong loses the card permanently, not temporarily: the attach writes
        // link_card_id and link_card_synced_at together, so a delete landing between them leaves the
        // body marked examined with no card, which the read path reads as "no link here" forever.
        $card = $this->agedCard();

        $adopted = false;
        LinkCard::retrieved(function (LinkCard $model) use ($card, &$adopted): void {
            if ($adopted || $model->getKey() !== $card->id) {
                return;
            }
            $adopted = true;
            Diary::factory()->for(Member::factory())->create([
                'link_card_id' => $card->id,
                'link_card_synced_at' => CarbonImmutable::now(),
            ]);
        });

        $this->artisan('openpne:prune-link-cards')->assertSuccessful();

        $this->assertTrue($adopted, 'The adoption must have interleaved for this test to mean anything.');
        $this->assertNotNull(LinkCard::find($card->id), 'A card adopted mid-sweep was deleted.');
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
