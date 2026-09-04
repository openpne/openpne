<?php

declare(strict_types=1);

namespace App\LinkCard;

use App\Jobs\FetchLinkCard;
use App\Jobs\SyncLinkCard;
use App\Models\LinkCard;
use Illuminate\Database\Eloquent\Model;

/**
 * Starts link-card work from a page view, which is what reaches records written before the feature
 * was on and cards that have since expired. Detail pages only (a list would queue a page's worth of
 * jobs, talk being the one exception, {@see ensureAll()}), nothing runs inline, and it is called
 * from controllers after authorization and never from a serializer.
 */
final class LinkCardSync
{
    public function __construct(private readonly LinkCardSettings $settings) {}

    /**
     * Talk's read trigger, which stays bounded only because a record is examined once in its life,
     * a refresh is gated by `LinkCard::isDueForFetch`, and a URL is one card. Pass only the records
     * the page renders: a reply's parent arrives without its card loaded, so examining it would mark
     * a body nobody looked at and lazy-load a query per row.
     *
     * @param  iterable<Model>  $records
     */
    public function ensureAll(iterable $records): void
    {
        foreach ($records as $record) {
            // Read on the first row, not on entry: the talk poll usually answers with no rows and the
            // setting sits behind a database-backed cache, so an empty page asks nothing and a page
            // with rows asks once (`LinkCardSettings` memoises).
            if (! $this->settings->enabled()) {
                return;
            }

            $this->ensure($record);
        }
    }

    /**
     * The two cases are not the same job: a record nobody has parsed needs its body read, while one
     * whose card has expired knows its URL. Going through `SyncLinkCard` for the latter would
     * re-parse the body and re-queue a job per view while the card sits in its backoff; the claim
     * would stop the fetch but not the queue churn.
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
