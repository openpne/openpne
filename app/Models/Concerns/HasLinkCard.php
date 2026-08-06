<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\LinkCard;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A body that can carry a link preview card for the first URL it mentions.
 *
 * Shared rather than repeated because the invalidation rule is the part that is easy to get wrong,
 * and getting it wrong in one of four places would be invisible: an edited body keeps showing the
 * card of the URL it used to contain until the queue catches up — and if the feature is switched off
 * in between, it keeps showing it indefinitely, because nothing will run to correct it.
 */
trait HasLinkCard
{
    public function linkCard(): BelongsTo
    {
        return $this->belongsTo(LinkCard::class);
    }

    /**
     * Detach the current card because the body it was derived from has changed.
     *
     * Must run inside the same transaction as the body write. Clearing `link_card_synced_at` as well
     * is what makes the read path pick the record up again: without it the record looks like one
     * that was examined and found to have no URL.
     */
    public function clearLinkCard(): void
    {
        $this->link_card_id = null;
        $this->link_card_synced_at = null;
    }

    /** Whether a card has been resolved for the body as it stands now. */
    public function hasSyncedLinkCard(): bool
    {
        return $this->link_card_synced_at !== null;
    }

    /**
     * @return array{link_card_id: int|null, link_card_synced_at: CarbonImmutable}
     */
    public static function linkCardAttributes(?int $cardId): array
    {
        return ['link_card_id' => $cardId, 'link_card_synced_at' => CarbonImmutable::now()];
    }
}
