<?php

declare(strict_types=1);

namespace App\Jobs;

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
 * Works out which card a body should point at, and attaches it.
 *
 * Split from the fetch because the two are keyed differently and fail differently. This one is about
 * a record — cheap, local, and repeated per post — while FetchLinkCard is about a URL, shared by
 * every record that mentions it, and is the part that touches the network. Keeping them apart is
 * what lets a thousand posts of the same link cost one request.
 *
 * Only the first URL becomes a card. That is what Twitter, Slack and Mastodon all do, and a body
 * full of links is better served by the links themselves than by a stack of cards.
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
     * Queue this record for a card, once the write that produced it is committed.
     *
     * `afterCommit` is the point: the job re-reads the record by id, and the body writes it follows
     * all happen inside a transaction. Dispatched plainly, a worker can pick the job up before that
     * transaction commits and find nothing — or, worse, find the row as it was before the edit and
     * conclude the old URL is still current.
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
        if (! $settings->enabled()) {
            return;
        }

        $record = $this->model::query()->find($this->id);

        if ($record === null) {
            return;
        }

        $body = (string) $record->getAttribute('body');
        $format = $record->getAttribute('format') ?? BodyFormat::Plain;
        $url = $this->firstFetchableUrl($body, $format);

        if ($url === null) {
            // Marked as looked at, so the read path stops asking. The body genuinely has no card.
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
     * Point $record at $cardId, but only if its body is still the one this job read.
     *
     * Two reasons this is a conditional query rather than a save:
     *
     *  - **It must not win a race with an edit.** The body is read at the start of the job and the
     *    result written at the end; in between someone can save new text, which clears the marker so
     *    the new body gets examined. An unconditional write would then attach the *old* body's card
     *    to the new text and mark it synced — and because ShouldBeUnique may still be holding the
     *    lock, the edit's own job can be dropped, leaving it that way.
     *  - **It must not touch `updated_at`.** Even saveQuietly, which only suppresses events, goes
     *    through performUpdate and bumps the timestamp. Group topic and event lists are ordered
     *    by `updated_at`, so a card synced from someone viewing an old post would float it back to
     *    the top of the board.
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

    /**
     * The row for $url, created if this is the first time anyone has posted it.
     *
     * firstOrCreate races against another worker doing the same, and the unique index is what
     * settles it; the loser reads back the winner's row rather than failing the job.
     */
    private function cardFor(string $url): LinkCard
    {
        $hash = LinkUrl::hash($url);

        try {
            return LinkCard::firstOrCreate(
                ['url_hash' => $hash],
                ['url' => $url, 'status' => LinkCardStatus::Pending],
            );
        } catch (UniqueConstraintViolationException) {
            return LinkCard::where('url_hash', $hash)->firstOrFail();
        }
    }
}
