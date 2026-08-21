<?php

declare(strict_types=1);

namespace Tests\Feature\LinkCard;

use App\Jobs\FetchLinkCard;
use App\Jobs\SyncLinkCard;
use App\LinkCard\LinkCardSync;
use App\Models\Diary;
use App\Models\LinkCard;
use App\Models\Member;
use App\Support\SnsSettingKey;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The read-side trigger, which is what makes the feature apply to anything other than future posts:
 * records written before it was switched on have never been examined, and a card fetched a week ago
 * has expired. Neither is reachable from a write.
 */
class LinkCardSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setSnsSetting(SnsSettingKey::LinkCardEnabled, true);
        Queue::fake();
    }

    public function test_a_record_nobody_has_examined_is_queued_for_parsing(): void
    {
        $diary = $this->diary(['link_card_synced_at' => null]);

        $this->sync()->ensure($diary);

        Queue::assertPushed(SyncLinkCard::class);
    }

    public function test_an_expired_card_is_refetched_directly(): void
    {
        // Not through SyncLinkCard: the record already knows which URL it points at, and re-parsing
        // the body per view would queue a job every time while the card sat in its backoff. The
        // claim stops the duplicate fetch, but nothing stops the queue churn.
        $card = LinkCard::factory()->stale()->create();
        $diary = $this->diary(['link_card_id' => $card->id, 'link_card_synced_at' => CarbonImmutable::now()]);

        $this->sync()->ensure($diary);

        Queue::assertPushed(FetchLinkCard::class);
        Queue::assertNotPushed(SyncLinkCard::class);
    }

    public function test_a_fresh_card_needs_nothing(): void
    {
        $card = LinkCard::factory()->create();
        $diary = $this->diary(['link_card_id' => $card->id, 'link_card_synced_at' => CarbonImmutable::now()]);

        $this->sync()->ensure($diary);

        Queue::assertNothingPushed();
    }

    public function test_a_failed_card_still_inside_its_backoff_is_left_alone(): void
    {
        // A page that is simply gone must not cost a job on every view.
        $card = LinkCard::factory()->failed(failureCount: 3)->create();
        $diary = $this->diary(['link_card_id' => $card->id, 'link_card_synced_at' => CarbonImmutable::now()]);

        $this->sync()->ensure($diary);

        Queue::assertNothingPushed();
    }

    public function test_a_failed_card_past_its_backoff_is_retried(): void
    {
        $card = LinkCard::factory()->failed()->create(['next_attempt_at' => CarbonImmutable::now()->subMinute()]);
        $diary = $this->diary(['link_card_id' => $card->id, 'link_card_synced_at' => CarbonImmutable::now()]);

        $this->sync()->ensure($diary);

        Queue::assertPushed(FetchLinkCard::class);
    }

    public function test_a_card_another_worker_is_fetching_is_left_alone(): void
    {
        $card = LinkCard::factory()->stale()->create(['next_attempt_at' => CarbonImmutable::now()->addMinutes(2)]);
        $diary = $this->diary(['link_card_id' => $card->id, 'link_card_synced_at' => CarbonImmutable::now()]);

        $this->sync()->ensure($diary);

        Queue::assertNothingPushed();
    }

    public function test_a_body_examined_and_found_to_have_no_url_needs_nothing(): void
    {
        // synced_at set with no card is the "looked at, has no link" state; without distinguishing it
        // from "never looked at", every view of such a body would queue a job forever.
        $diary = $this->diary(['link_card_id' => null, 'link_card_synced_at' => CarbonImmutable::now()]);

        $this->sync()->ensure($diary);

        Queue::assertNothingPushed();
    }

    public function test_nothing_is_queued_while_the_setting_is_off(): void
    {
        $this->setSnsSetting(SnsSettingKey::LinkCardEnabled, false);
        $diary = $this->diary(['link_card_synced_at' => null]);

        $this->sync()->ensure($diary);

        Queue::assertNothingPushed();
    }

    public function test_turning_the_setting_back_on_picks_up_what_was_missed(): void
    {
        // Records posted while it was off keep a null synced_at, so they are indistinguishable from
        // any other never-examined record once it returns.
        $this->setSnsSetting(SnsSettingKey::LinkCardEnabled, false);
        $diary = $this->diary(['link_card_synced_at' => null]);
        $this->sync()->ensure($diary);
        Queue::assertNothingPushed();

        $this->setSnsSetting(SnsSettingKey::LinkCardEnabled, true);
        $this->sync()->ensure($diary->fresh());

        Queue::assertPushed(SyncLinkCard::class);
    }

    public function test_a_page_of_records_is_asked_about_one_by_one(): void
    {
        $records = collect([$this->diary(['link_card_synced_at' => null]), $this->diary(['link_card_synced_at' => null])]);

        $this->sync()->ensureAll($records);

        Queue::assertPushed(SyncLinkCard::class, 2);
    }

    public function test_a_page_queues_nothing_while_the_setting_is_off(): void
    {
        // Answered once for the batch rather than once per record: the setting is read through a
        // cache the default store keeps in the database, so a page of a busy room would otherwise
        // pay a query per row to be told the same thing.
        $this->setSnsSetting(SnsSettingKey::LinkCardEnabled, false);

        $this->sync()->ensureAll([$this->diary(['link_card_synced_at' => null])]);

        Queue::assertNothingPushed();
    }

    public function test_a_null_record_is_not_an_error(): void
    {
        $this->sync()->ensure(null);

        Queue::assertNothingPushed();
    }

    private function diary(array $attributes): Diary
    {
        return Diary::factory()->for(Member::factory())->create($attributes + ['body' => 'See https://example.com/a']);
    }

    private function sync(): LinkCardSync
    {
        return $this->app->make(LinkCardSync::class);
    }
}
