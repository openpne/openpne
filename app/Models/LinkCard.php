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
 * One row per normalised URL, shared by every body that mentions it. The row exists before anything
 * is known about the destination, so only `isRenderable()` says whether it can be drawn.
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
            // `internal_context` stays a string on purpose: cast to InternalCardTarget a retired
            // value would throw as the row is hydrated, and compared as text it just draws no card.
        ];
    }

    /**
     * See docs/internals/link-cards.md, "What a page of them costs".
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
     * Status alone is not the test: a fetch can succeed against a page carrying no metadata at all.
     */
    public function isRenderable(): bool
    {
        return $this->status === LinkCardStatus::Ok && ($this->title ?? '') !== '';
    }

    /**
     * The dimensions come from the File, not from this row's `image_width` / `image_height`, which
     * nothing reads (docs/internals/link-cards.md, "Two shapes, chosen by the picture").
     */
    public function hasLargeImage(): bool
    {
        return CardLayout::forImage($this->image?->width, $this->image?->height) === CardLayout::Wide;
    }

    public function isStale(): bool
    {
        return $this->expires_at === null || $this->expires_at->isPast();
    }

    /**
     * `next_attempt_at` in the future means either a held lease or a backoff being served out, and
     * both mean "not now". The queueing side, the read path and the claim all decide due-ness here
     * (docs/internals/link-cards.md, "Two workers, one URL").
     */
    public function isDueForFetch(): bool
    {
        if ($this->next_attempt_at !== null && $this->next_attempt_at->isFuture()) {
            return false;
        }

        return match ($this->status) {
            LinkCardStatus::Pending, LinkCardStatus::Failed => true,
            LinkCardStatus::Ok => $this->isStale(),
            LinkCardStatus::Internal => false,
        };
    }

    /**
     * The returned instant is a fence token and has to be carried into the write that finishes the
     * work (docs/internals/link-cards.md, "Two workers, one URL").
     */
    public function claimFetch(int $leaseSeconds): ?CarbonImmutable
    {
        $now = CarbonImmutable::now();
        $lease = $now->addSeconds($leaseSeconds);

        $taken = static::query()
            ->whereKey($this->getKey())
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now))
            // Deliberately outside the group below: an internal row carries no expiry, so folded in
            // there its `expires_at IS NULL` would satisfy the OR and take the lease on a row that
            // must never be fetched.
            ->where('status', '!=', LinkCardStatus::Internal->value)
            // Due-ness is re-checked here, not only in the caller, because `ShouldBeUnique` has a
            // window a delayed duplicate arrives after.
            ->where(fn ($query) => $query
                ->where('status', '!=', LinkCardStatus::Ok->value)
                ->orWhereNull('expires_at')
                ->orWhere('expires_at', '<=', $now))
            ->update(['next_attempt_at' => $lease, 'updated_at' => $now]);

        return $taken === 1 ? $lease : null;
    }

    /**
     * Returns false when the lease has moved on: the caller's result is stale and must be dropped
     * rather than written.
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
     * The exponent is clamped: `failure_count` is a TINYINT and the shift would run past any useful
     * interval long before the column overflows.
     */
    public static function backoffAfter(int $failures): CarbonImmutable
    {
        return CarbonImmutable::now()->addMinutes(15 * (2 ** min(max($failures, 1), 9)));
    }
}
