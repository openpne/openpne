<?php

declare(strict_types=1);

namespace Tests\Feature\LinkCard;

use App\Models\LinkCard;
use App\Support\LinkCardStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

/**
 * The concurrency contract for fetching a card.
 *
 * A link posted by many people at once produces many jobs for one URL, and a job slow enough to lose
 * its lease can return after a newer one has already answered. Neither is rare enough to leave to
 * chance, and neither shows up in a single-threaded test unless it is written for.
 */
class LinkCardLeaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_claimant_wins_and_the_others_are_turned_away(): void
    {
        $card = LinkCard::factory()->pending()->create();

        // Two workers that both picked up the same URL. Separate instances, as separate processes
        // would be — the conditional UPDATE is what settles it, not anything held in memory.
        $first = LinkCard::findOrFail($card->id)->claimFetch(120);
        $second = LinkCard::findOrFail($card->id)->claimFetch(120);

        $this->assertNotNull($first);
        $this->assertNull($second, 'A second worker took a lease that was already held.');
    }

    public function test_a_lease_can_be_taken_again_once_it_expires(): void
    {
        // Otherwise a worker that died mid-fetch would strand the URL forever.
        $card = LinkCard::factory()->pending()->create();
        LinkCard::findOrFail($card->id)->claimFetch(120);

        Date::setTestNow(CarbonImmutable::now()->addMinutes(5));

        $this->assertNotNull(LinkCard::findOrFail($card->id)->claimFetch(120));
    }

    public function test_completing_under_the_lease_writes_the_result(): void
    {
        $card = LinkCard::factory()->pending()->create();
        $lease = LinkCard::findOrFail($card->id)->claimFetch(120);

        $this->assertTrue($card->completeFetch($lease, ['status' => LinkCardStatus::Ok, 'title' => 'Done']));
        $this->assertSame('Done', $card->fresh()?->title);
    }

    public function test_a_stale_worker_cannot_overwrite_a_newer_claimant(): void
    {
        // The case a claim alone does not cover. Worker A takes the lease and is slow; the lease
        // expires; worker B claims it and finishes. When A finally comes back with its own answer,
        // the affected-rows check at claim time is long past — only the fence stops it writing over
        // B's newer result.
        $card = LinkCard::factory()->pending()->create();

        $leaseA = LinkCard::findOrFail($card->id)->claimFetch(120);
        Date::setTestNow(CarbonImmutable::now()->addMinutes(5));
        $leaseB = LinkCard::findOrFail($card->id)->claimFetch(120);

        $this->assertNotNull($leaseB);
        $this->assertTrue($card->completeFetch($leaseB, ['title' => 'B result']));

        $this->assertFalse(
            $card->completeFetch($leaseA, ['title' => 'A result']),
            'A worker whose lease had expired overwrote the newer result.',
        );
        $this->assertSame('B result', $card->fresh()?->title);
    }

    public function test_a_released_lease_lets_the_next_refresh_claim(): void
    {
        // A successful fetch clears next_attempt_at, which is what makes the card claimable again
        // when it later goes stale.
        $card = LinkCard::factory()->pending()->create();
        $lease = LinkCard::findOrFail($card->id)->claimFetch(120);
        $card->completeFetch($lease, ['next_attempt_at' => null]);

        $this->assertNotNull(LinkCard::findOrFail($card->id)->claimFetch(120));
    }

    public function test_the_backoff_grows_and_then_stops_growing(): void
    {
        Date::setTestNow(CarbonImmutable::parse('2026-08-06 12:00:00'));

        $after = fn (int $failures): int => (int) CarbonImmutable::now()->diffInMinutes(LinkCard::backoffAfter($failures));

        $this->assertSame(30, $after(1));
        $this->assertSame(60, $after(2));
        $this->assertLessThan($after(5), $after(4), 'The wait must grow while failures accumulate.');

        // Clamped: the shift would otherwise run past any useful interval, and a URL that has failed
        // twenty times is not going to start working on a schedule.
        $this->assertSame($after(9), $after(20));
        $this->assertSame($after(9), $after(255));
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }
}
