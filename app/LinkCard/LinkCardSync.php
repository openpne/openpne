<?php

declare(strict_types=1);

namespace App\LinkCard;

use App\Jobs\FetchLinkCard;
use App\Jobs\SyncLinkCard;
use App\Models\LinkCard;
use App\Support\LinkCardStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * Starts link-card work from a page view.
 *
 * Posting is not the only moment a card is needed. Records written before the feature was switched
 * on have never been looked at, and a card fetched a week ago has gone stale — neither is reachable
 * from a write, so without a read-side trigger the TTL would be a promise nothing kept and the
 * feature would only ever apply to future posts.
 *
 * Two rules keep that from becoming a stampede or a background crawl of the whole site:
 *
 *  - **Detail pages only.** A list renders many records; asking on each would queue a page's worth
 *    of jobs for someone scrolling past.
 *  - **Nothing runs inline.** This queues work and returns; a page view never waits on the network.
 *
 * Called from controllers, after authorization, and never from a serializer — a side effect belongs
 * on the path that decided the viewer may see this record, not in the code that formats it.
 */
final class LinkCardSync
{
    public function __construct(private readonly LinkCardSettings $settings) {}

    /**
     * Queue whatever this record still needs, if anything.
     *
     * The two cases are deliberately not the same job. A record nobody has parsed needs its body
     * read; a record whose card has merely expired already knows which URL it points at, and going
     * through SyncLinkCard again would re-parse the body and re-queue a job per view while the card
     * sits in its failure backoff — the claim would stop the fetch, but not the queue churn.
     */
    public function ensure(?Model $record): void
    {
        if ($record === null || ! $this->settings->enabled()) {
            return;
        }

        if ($record->getAttribute('link_card_synced_at') === null) {
            SyncLinkCard::dispatch($record::class, (int) $record->getKey());

            return;
        }

        $card = $record->getAttribute('link_card_id') === null
            ? null
            : ($record->relationLoaded('linkCard') ? $record->getRelation('linkCard') : LinkCard::find($record->getAttribute('link_card_id')));

        if ($card instanceof LinkCard && $this->needsRefetch($card)) {
            FetchLinkCard::dispatch($card->id);
        }
    }

    /**
     * Whether this card is due for another attempt.
     *
     * A failed card is governed by its backoff rather than by staleness: it has no expiry, and
     * `next_attempt_at` is the answer to "when is it worth asking again". A card still under lease —
     * a worker is on it right now — is due for nothing.
     */
    private function needsRefetch(LinkCard $card): bool
    {
        $due = $card->next_attempt_at === null || $card->next_attempt_at->isPast();

        if (! $due) {
            return false;
        }

        return match ($card->status) {
            LinkCardStatus::Pending => true,
            LinkCardStatus::Failed => true,
            LinkCardStatus::Ok => $card->isStale(),
        };
    }
}
