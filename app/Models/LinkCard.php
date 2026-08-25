<?php

declare(strict_types=1);

namespace App\Models;

use App\LinkCard\CardLayout;
use App\LinkCard\InternalCardResolver;
use App\Support\LinkCardStatus;
use Carbon\CarbonImmutable;
use Database\Factories\LinkCardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Cached preview metadata for one URL (the `link_cards` table).
 *
 * One row per normalised URL, shared by every body that mentions it. The row is created before
 * anything is known about the destination (status pending) and filled in by the fetch worker, so its
 * existence never implies the URL was reachable — only `isRenderable()` does.
 *
 * @property int $id
 * @property string $url_hash
 * @property string $url
 * @property LinkCardStatus $status
 * @property string|null $title
 * @property string|null $description
 * @property string|null $site_name
 * @property string|null $author_name
 * @property int|null $image_file_id
 * @property int|null $image_width
 * @property int|null $image_height
 * @property int $failure_count
 * @property Carbon|null $fetched_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $next_attempt_at
 * @property string|null $internal_context
 * @property int|null $internal_record_id
 */
#[Fillable([
    'url_hash', 'url', 'status', 'title', 'description', 'site_name', 'author_name',
    'image_file_id', 'image_width', 'image_height', 'failure_count',
    'fetched_at', 'expires_at', 'next_attempt_at',
    'internal_context', 'internal_record_id',
])]
class LinkCard extends Model
{
    /** @use HasFactory<LinkCardFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => LinkCardStatus::class,
            'image_file_id' => 'integer',
            'image_width' => 'integer',
            'image_height' => 'integer',
            'failure_count' => 'integer',
            'fetched_at' => 'datetime',
            'expires_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'internal_record_id' => 'integer',
            // internal_context stays a string on purpose: cast to InternalCardTarget, a value this
            // enum no longer has would throw as the row is hydrated. It is compared as text where it
            // is read, so an unrecognised one draws no card.
        ];
    }

    /**
     * Announce an internal row's target as this row is hydrated, so the page's cards are read
     * together rather than one query at a time.
     *
     * On the retrieval rather than at each list, because every list that draws cards already loads
     * them in one go, and an opt-in would be missing from whichever list nobody remembered. External
     * rows pay one property read ({@see InternalCardResolver}).
     */
    protected static function booted(): void
    {
        static::retrieved(function (self $card): void {
            if ($card->internal_context !== null) {
                app(InternalCardResolver::class)->note($card);
            }
        });
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(File::class, 'image_file_id');
    }

    /**
     * Whether this card has enough to draw.
     *
     * A title is the minimum: an image alone is a mystery box, and a card with neither is worse than
     * the bare link it would replace. Status alone is not the test — a fetch can succeed against a
     * page that carries no metadata at all.
     */
    public function isRenderable(): bool
    {
        return $this->status === LinkCardStatus::Ok && ($this->title ?? '') !== '';
    }

    /**
     * Whether this card's picture is worth drawing at full width, rather than as a thumbnail beside
     * the text. The threshold itself is {@see CardLayout::forImage()}, which a card built from a
     * record of this site reads too.
     *
     * **The dimensions come from the File, not from this row.** `image_width` / `image_height` here
     * are what the container declared and are read by nothing; `files` holds what the bytes actually
     * *render* at, EXIF Orientation applied ({@see App\Files\ImageDimensions}).
     */
    public function hasLargeImage(): bool
    {
        return CardLayout::forImage($this->image?->width, $this->image?->height) === CardLayout::Wide;
    }

    /** Whether the cached metadata is old enough to be worth fetching again. */
    public function isStale(): bool
    {
        return $this->expires_at === null || $this->expires_at->isPast();
    }

    /**
     * Whether this card is worth fetching right now.
     *
     * The single state machine, deliberately: the queueing side, the read path and the claim all have
     * to agree, and when they were each written separately they did not. `isStale()` alone is not it
     * — a failed card has no expiry, so it reads as stale forever and would be queued again every
     * time another record mentions the same URL, right through the backoff that exists to stop that.
     *
     * `next_attempt_at` in the future means one of two things, and both mean "not now": a worker is
     * holding the lease, or a failure is serving out its backoff.
     */
    public function isDueForFetch(): bool
    {
        if ($this->next_attempt_at !== null && $this->next_attempt_at->isFuture()) {
            return false;
        }

        return match ($this->status) {
            LinkCardStatus::Pending, LinkCardStatus::Failed => true,
            LinkCardStatus::Ok => $this->isStale(),
            // A URL of this site's own is never fetched, and its card is assembled from the record
            // it names on every render — there is nothing to refresh.
            LinkCardStatus::Internal => false,
        };
    }

    /**
     * Take the fetch lease for this card, or return null if someone else holds it.
     *
     * A conditional UPDATE is the whole mechanism: whichever worker's write matches the current
     * `next_attempt_at` wins, and the others see zero affected rows and stop. That is what keeps a
     * popular URL from being fetched by every worker that picks it up at once.
     *
     * The returned instant is a **fence token**, and it has to be carried into the write that
     * finishes the work. Without one, a slow worker whose lease expired — letting a second worker
     * claim and complete — would still overwrite that newer result when it eventually came back.
     */
    public function claimFetch(int $leaseSeconds): ?CarbonImmutable
    {
        $now = CarbonImmutable::now();
        $lease = $now->addSeconds($leaseSeconds);

        $taken = static::query()
            ->whereKey($this->getKey())
            // Nobody is holding it and no backoff is running.
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now))
            // Its own condition, deliberately outside the group below: an internal row carries no
            // expiry, so folded in there the `expires_at IS NULL` arm would satisfy the OR and the
            // claim would succeed — taking the lease on a row that must never be fetched, and
            // writing a timestamp into a row whose bookkeeping columns are all null by invariant.
            ->where('status', '!=', LinkCardStatus::Internal->value)
            // And it is actually due. This condition belongs in the UPDATE, not only in the caller:
            // ShouldBeUnique is an optimisation with a time window, so a duplicate job delayed past
            // it arrives after the first has already succeeded — with a released lease and a fresh
            // card — and would otherwise claim it and fetch the URL a second time for nothing.
            ->where(fn ($query) => $query
                ->where('status', '!=', LinkCardStatus::Ok->value)
                ->orWhereNull('expires_at')
                ->orWhere('expires_at', '<=', $now))
            ->update(['next_attempt_at' => $lease, 'updated_at' => $now]);

        return $taken === 1 ? $lease : null;
    }

    /**
     * Apply $attributes only if this card is still held under $lease.
     *
     * Returns false when the lease has moved on, meaning another worker has since claimed and
     * possibly finished; the caller's result is stale and must be dropped rather than written.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function completeFetch(CarbonImmutable $lease, array $attributes): bool
    {
        return static::query()
            ->whereKey($this->getKey())
            ->where('next_attempt_at', $lease)
            ->update($attributes + ['updated_at' => CarbonImmutable::now()]) === 1;
    }

    /**
     * How long to wait before retrying after $failures consecutive failures.
     *
     * Doubles up to a week. The exponent is clamped because failure_count is a TINYINT and the shift
     * would otherwise run past any useful interval long before the column overflows — a URL that has
     * failed ten times is not going to start working on a schedule.
     */
    public static function backoffAfter(int $failures): CarbonImmutable
    {
        return CarbonImmutable::now()->addMinutes(15 * (2 ** min(max($failures, 1), 9)));
    }
}
