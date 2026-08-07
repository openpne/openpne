<?php

declare(strict_types=1);

namespace Tests\Feature\LinkCard;

use App\Jobs\FetchLinkCard;
use App\Jobs\SyncLinkCard;
use App\LinkCard\LinkCardSettings;
use App\LinkCard\LinkUrl;
use App\Models\Diary;
use App\Models\LinkCard;
use App\Models\Member;
use App\Support\BodyFormat;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SyncLinkCardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setSnsSetting(SnsSettingKey::LinkCardEnabled, true);
    }

    public function test_it_attaches_a_card_for_the_first_url(): void
    {
        // One card per body, as Twitter, Slack and Mastodon all do: a body full of links reads better
        // as links than as a stack of cards.
        Queue::fake();
        $diary = $this->diary('First https://example.com/one then https://example.com/two');

        (new SyncLinkCard(Diary::class, $diary->id))->handle($this->settings());

        $diary->refresh();
        $this->assertSame('https://example.com/one', $diary->linkCard?->url);
        $this->assertNotNull($diary->link_card_synced_at);
    }

    public function test_a_body_with_no_url_is_marked_as_examined(): void
    {
        // Without this the read path could not tell "looked at, has no link" from "never looked at",
        // and would queue a job on every view forever.
        Queue::fake();
        $diary = $this->diary('Nothing to see here.');

        (new SyncLinkCard(Diary::class, $diary->id))->handle($this->settings());

        $diary->refresh();
        $this->assertNull($diary->link_card_id);
        $this->assertNotNull($diary->link_card_synced_at);
        Queue::assertNothingPushed();
    }

    public function test_a_url_the_fetcher_would_refuse_yields_no_card(): void
    {
        // Normalisation is the filter as well as the key, so a body whose only link is unfetchable
        // gets no card rather than one that could never be filled in.
        Queue::fake();
        $diary = $this->diary('Write to mailto:someone@example.com or ftp://example.com/f');

        (new SyncLinkCard(Diary::class, $diary->id))->handle($this->settings());

        $this->assertNull($diary->fresh()?->link_card_id);
    }

    public function test_two_bodies_sharing_a_url_share_one_card(): void
    {
        // The whole reason cards are keyed by URL: a link everyone posts costs one fetch.
        Queue::fake();
        $one = $this->diary('Look: https://example.com/popular');
        $two = $this->diary('Also: https://example.com/popular');

        (new SyncLinkCard(Diary::class, $one->id))->handle($this->settings());
        (new SyncLinkCard(Diary::class, $two->id))->handle($this->settings());

        $this->assertSame($one->fresh()?->link_card_id, $two->fresh()?->link_card_id);
        $this->assertSame(1, LinkCard::count());
    }

    public function test_it_queues_a_fetch_for_a_card_nobody_has_fetched(): void
    {
        Queue::fake();
        $diary = $this->diary('https://example.com/new');

        (new SyncLinkCard(Diary::class, $diary->id))->handle($this->settings());

        Queue::assertPushed(FetchLinkCard::class);
    }

    public function test_it_does_not_queue_a_fetch_for_a_card_that_is_already_fresh(): void
    {
        Queue::fake();
        $url = (string) LinkUrl::normalize('https://example.com/known');
        LinkCard::factory()->create(['url' => $url, 'url_hash' => LinkUrl::hash($url)]);
        $diary = $this->diary("See {$url}");

        (new SyncLinkCard(Diary::class, $diary->id))->handle($this->settings());

        $this->assertNotNull($diary->fresh()?->link_card_id);
        Queue::assertNotPushed(FetchLinkCard::class);
    }

    public function test_it_queues_a_fetch_for_a_stale_card(): void
    {
        Queue::fake();
        $url = (string) LinkUrl::normalize('https://example.com/old');
        LinkCard::factory()->stale()->create(['url' => $url, 'url_hash' => LinkUrl::hash($url)]);
        $diary = $this->diary("See {$url}");

        (new SyncLinkCard(Diary::class, $diary->id))->handle($this->settings());

        Queue::assertPushed(FetchLinkCard::class);
    }

    public function test_it_does_nothing_while_the_setting_is_off(): void
    {
        // Checked here as well as where the job was queued: the setting can be turned off after a
        // job is already waiting.
        Queue::fake();
        $this->setSnsSetting(SnsSettingKey::LinkCardEnabled, false);
        $diary = $this->diary('https://example.com/x');

        (new SyncLinkCard(Diary::class, $diary->id))->handle($this->settings());

        $diary->refresh();
        $this->assertNull($diary->link_card_id);
        $this->assertNull($diary->link_card_synced_at, 'Leaving it unsynced is what lets it be picked up when the setting returns.');
        Queue::assertNothingPushed();
    }

    public function test_a_markdown_body_uses_its_links_not_its_code_spans(): void
    {
        // Extraction reads the parsed document, so what gets a card is what the reader sees linked.
        Queue::fake();
        $diary = $this->diary('`https://example.com/code` then [real](https://example.com/real)', BodyFormat::Markdown);

        (new SyncLinkCard(Diary::class, $diary->id))->handle($this->settings());

        $this->assertSame('https://example.com/real', $diary->fresh()?->linkCard?->url);
    }

    public function test_it_does_not_attach_a_card_to_a_body_that_changed_underneath_it(): void
    {
        // The job reads the body at the start and writes the result at the end. An edit in between
        // clears the marker so the new text gets examined — but an unconditional write would attach
        // the OLD body's card to the NEW text and mark it synced. Worse, ShouldBeUnique can still be
        // holding the lock, so the edit's own job is dropped and it stays that way.
        Queue::fake();
        $diary = $this->diary('Original https://example.com/original');

        // The edit has to land *between* the job's read and its write, so it is triggered from the
        // retrieved event — the moment the job has the old body in hand and has not written yet.
        $edited = false;
        Diary::retrieved(function (Diary $model) use (&$edited): void {
            if ($edited) {
                return;
            }
            $edited = true;
            Diary::whereKey($model->getKey())->update([
                'body' => 'Rewritten, no link at all',
                'link_card_synced_at' => null,
            ]);
        });

        (new SyncLinkCard(Diary::class, $diary->id))->handle($this->settings());

        $this->assertTrue($edited, 'The edit must have interleaved for this test to mean anything.');

        $diary->refresh();
        $this->assertNull($diary->link_card_id, 'A card from the previous body was attached to the new one.');
        $this->assertNull($diary->link_card_synced_at, 'The new body must stay unsynced so it is examined.');
    }

    public function test_syncing_does_not_bump_the_record_timestamp(): void
    {
        // Community topic and event lists order by updated_at, so a card synced from someone opening
        // an old post would float it back to the top of the board. saveQuietly does not help: it
        // suppresses events but still goes through performUpdate, which touches the timestamp.
        Queue::fake();
        $diary = $this->diary('https://example.com/a');
        $before = $diary->updated_at;

        $this->travelTo(now()->addHour());
        (new SyncLinkCard(Diary::class, $diary->id))->handle($this->settings());

        $this->assertEquals($before, $diary->fresh()?->updated_at);
    }

    public function test_it_does_not_queue_a_fetch_for_a_failed_card_still_in_backoff(): void
    {
        // isStale() alone reads a failed card as stale forever, since it has no expiry — so every new
        // record mentioning the same dead URL would queue a fetch straight through the backoff.
        Queue::fake();
        $url = (string) LinkUrl::normalize('https://example.com/dead');
        LinkCard::factory()->failed(failureCount: 3)->create(['url' => $url, 'url_hash' => LinkUrl::hash($url)]);
        $diary = $this->diary("Another mention of {$url}");

        (new SyncLinkCard(Diary::class, $diary->id))->handle($this->settings());

        $this->assertNotNull($diary->fresh()?->link_card_id, 'The card is still attached; only the fetch is withheld.');
        Queue::assertNotPushed(FetchLinkCard::class);
    }

    public function test_a_deleted_record_is_not_an_error(): void
    {
        Queue::fake();

        (new SyncLinkCard(Diary::class, 9999))->handle($this->settings());

        Queue::assertNothingPushed();
    }

    public function test_the_job_is_unique_per_record(): void
    {
        // An edit-and-edit-again produces two identical jobs; collapsing them before they queue is
        // cheaper than having both parse the same body.
        $this->assertSame(
            (new SyncLinkCard(Diary::class, 1))->uniqueId(),
            (new SyncLinkCard(Diary::class, 1))->uniqueId(),
        );
        $this->assertNotSame(
            (new SyncLinkCard(Diary::class, 1))->uniqueId(),
            (new SyncLinkCard(Diary::class, 2))->uniqueId(),
        );
    }

    private function diary(string $body, BodyFormat $format = BodyFormat::Plain): Diary
    {
        return Diary::factory()->for(Member::factory())->create(['body' => $body, 'format' => $format]);
    }

    private function settings(): LinkCardSettings
    {
        return $this->app->make(LinkCardSettings::class);
    }
}
