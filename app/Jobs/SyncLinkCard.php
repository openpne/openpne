<?php

declare(strict_types=1);

namespace App\Jobs;

use App\LinkCard\InternalCardRow;
use App\LinkCard\InternalUrl;
use App\LinkCard\LinkCardSettings;
use App\LinkCard\LinkUrl;
use App\Models\LinkCard;
use App\Support\BodyFormat;
use App\Support\BodyRenderer;
use App\Support\LinkCardStatus;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Keyed by the record where FetchLinkCard is keyed by the URL, and only a body's first URL becomes
 * a card (docs/internals/link-cards.md, "When a card is fetched").
 */
class SyncLinkCard implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Long enough to swallow the duplicates an edit-in-quick-succession produces. */
    public int $uniqueFor = 300;

    /**
     * @param  class-string<Model>  $model
     */
    public function __construct(public readonly string $model, public readonly int $id) {}

    /**
     * afterCommit is the point: the job re-reads the record by id, and the writes it follows happen
     * inside a transaction a worker could otherwise read before or during.
     */
    public static function for(Model $record): void
    {
        self::dispatch($record::class, (int) $record->getKey())->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->model.':'.$this->id;
    }

    public function handle(LinkCardSettings $settings): void
    {
        $record = $this->model::query()->find($this->id);

        if ($record === null) {
            return;
        }

        $body = (string) $record->getAttribute('body');
        $format = $record->getAttribute('format') ?? BodyFormat::Plain;
        $url = $this->firstFetchableUrl($body, $format);
        $link = $url === null ? null : InternalUrl::of($url);

        if ($link !== null && $link->isSelfHosted) {
            $this->attach($record, $body, $format, $this->internalCardFor($url, $link)?->id);

            return;
        }

        // Read after the body, not on entry: the switch governs fetching, and a card of one of this
        // site's own pages needs none, so it is decided above whatever the switch says.
        if (! $settings->enabled()) {
            return;
        }

        if ($url === null) {
            // Marked as looked at, so the read path stops asking about a body that has no card.
            $this->attach($record, $body, $format, null);

            return;
        }

        $card = $this->cardFor($url);

        if (! $this->attach($record, $body, $format, $card->id)) {
            // The body changed under us; the card belongs to text that is no longer there.
            return;
        }

        if ($card->isDueForFetch()) {
            FetchLinkCard::dispatch($card->id);
        }
    }

    /**
     * Conditional on the body still being the one this job read: an unconditional write would put
     * the old body's card under new text, and the edit's own job can be dropped while ShouldBeUnique
     * holds the lock. It updates through the query builder because even saveQuietly bumps
     * updated_at, which the boards are ordered by.
     */
    private function attach(Model $record, string $body, BodyFormat $format, ?int $cardId): bool
    {
        $query = $record->newQuery()->whereKey($record->getKey())->where('body', $body);

        // timeline_posts has no format column; there is nothing to pin for it.
        if ($record->getAttribute('format') !== null) {
            $query->where('format', $format->value);
        }

        return $query->toBase()->update([
            'link_card_id' => $cardId,
            'link_card_synced_at' => CarbonImmutable::now(),
        ]) === 1;
    }

    /**
     * The first URL in the body that this app would actually fetch.
     *
     * Normalisation is the filter as well as the key: it rejects the schemes, ports and credential
     * forms the fetcher would refuse anyway, so a body whose only link is `mailto:` yields no card
     * rather than a card that can never be filled in.
     */
    private function firstFetchableUrl(string $body, BodyFormat $format): ?string
    {
        foreach (BodyRenderer::urls($body, $format) as $url) {
            $normalized = LinkUrl::normalize($url);

            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    /** The row for $url, awaiting its fetch. */
    private function cardFor(string $url): LinkCard
    {
        return $this->rowFor($url, ['status' => LinkCardStatus::Pending]);
    }

    /**
     * No row is minted for an address of ours that names nothing a card can be built from, while a
     * row already there is converted either way
     * (docs/internals/link-cards.md, "Rows written before this existed").
     */
    private function internalCardFor(string $url, InternalUrl $link): ?LinkCard
    {
        $card = $link->target === null
            ? LinkCard::where('url_hash', LinkUrl::hash($url))->first()
            : $this->rowFor($url, InternalCardRow::attributes($link));

        if ($card === null) {
            return null;
        }

        InternalCardRow::convert($card, $link);

        return $card;
    }

    /**
     * The row for $url, created with $attributes if this is the first time anyone has posted it.
     *
     * firstOrCreate races against another worker doing the same, and the unique index is what
     * settles it; the loser reads back the winner's row rather than failing the job.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function rowFor(string $url, array $attributes): LinkCard
    {
        $hash = LinkUrl::hash($url);

        try {
            return LinkCard::firstOrCreate(['url_hash' => $hash], ['url' => $url] + $attributes);
        } catch (UniqueConstraintViolationException) {
            return LinkCard::where('url_hash', $hash)->firstOrFail();
        }
    }
}
