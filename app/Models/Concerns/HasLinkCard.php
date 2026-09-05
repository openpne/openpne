<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\LinkCard;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait HasLinkCard
{
    public function linkCard(): BelongsTo
    {
        return $this->belongsTo(LinkCard::class);
    }

    /**
     * Must run inside the same transaction as the body write. Clearing `link_card_synced_at` too is
     * what offers the record to the read path again: a non-null value means "examined, no URL".
     */
    public function clearLinkCard(): void
    {
        $this->link_card_id = null;
        $this->link_card_synced_at = null;
        // An already-loaded relation would go on serving the old card from this same instance.
        $this->unsetRelation('linkCard');
    }

    /**
     * Call it after filling the model and before saving it, so Eloquent's own dirty tracking answers
     * the question. Only `body` and `format` count: an edit to a title, a visibility or a venue
     * leaves the URL where it was, and clearing there would re-fetch the same page for nothing.
     */
    public function clearLinkCardIfBodyChanged(): void
    {
        if ($this->isDirty(['body', 'format'])) {
            $this->clearLinkCard();
        }
    }

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
