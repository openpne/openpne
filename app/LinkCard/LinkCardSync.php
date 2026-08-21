<?php

declare(strict_types=1);

namespace App\LinkCard;

use App\Jobs\FetchLinkCard;
use App\Jobs\SyncLinkCard;
use App\Models\LinkCard;
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
 *    of jobs for someone scrolling past. Talk is the one exception, and {@see ensureAll()} states
 *    what makes it one.
 *  - **Nothing runs inline.** This queues work and returns; a page view never waits on the network.
 *
 * Called from controllers, after authorization, and never from a serializer — a side effect belongs
 * on the path that decided the viewer may see this record, not in the code that formats it.
 */
final class LinkCardSync
{
    public function __construct(private readonly LinkCardSettings $settings) {}

    /**
     * The same question asked of a whole page of records — talk's read trigger.
     *
     * A conversation has no detail page: the page *is* the record. So the rule above is relaxed for
     * it, and what keeps a list trigger from being a crawl is three bounds holding at once:
     *
     *  - a record is examined **once in its life** (`link_card_synced_at`), so re-opening the same
     *    conversation queues nothing;
     *  - a refresh is gated by `LinkCard::isDueForFetch`, which holds a failing URL inside its
     *    backoff rather than re-queueing it per view;
     *  - a URL is one card, so what a room of a thousand messages can cost is the number of
     *    *distinct* links in it, not the number of rows.
     *
     * Pass only the records the page actually renders. Rows read to decorate them — a reply's parent
     * — are not on screen and are not loaded with their card, so examining them would both mark
     * bodies nobody looked at and lazy-load a query per row.
     *
     * @param  iterable<Model>  $records
     */
    public function ensureAll(iterable $records): void
    {
        foreach ($records as $record) {
            // Read on the first row rather than on entry: the talk poll runs every few seconds and
            // usually answers with no rows at all, and the setting is behind a cache the default
            // store keeps in the database. A page with nothing on it asks nothing.
            if (! $this->settings->enabled()) {
                return;
            }

            $this->ensure($record);
        }
    }

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

        // The same predicate the queueing side and the claim use — see LinkCard::isDueForFetch.
        if ($card instanceof LinkCard && $card->isDueForFetch()) {
            FetchLinkCard::dispatch($card->id);
        }
    }
}
