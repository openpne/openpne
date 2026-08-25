<?php

declare(strict_types=1);

namespace App\LinkCard;

use App\Models\File;
use App\Models\LinkCard;
use App\Support\LinkCardStatus;
use Carbon\CarbonImmutable;

/**
 * The one state a card of this site's own URL is ever in, and the only way a row reaches it.
 *
 * Written from two places — the body parser, and the fetch job repairing a row that predates this —
 * so what "internal" means on a row is defined once. Everything but the URL and the pointer is null:
 * the table is shared by every body that mentions the URL, and what an internal card says depends on
 * who is reading it, so **no viewer-dependent content may be cached here**. That is the invariant
 * the null columns express, and it is why the conversion clears rather than leaves alone.
 *
 * The schedule columns go too. `expires_at` and `next_attempt_at` are the fetch lifecycle, and this
 * row has left it: nulling `next_attempt_at` also releases the lease, so a fetch still in flight
 * fails its fence and drops its result instead of writing metadata onto the converted row.
 */
final class InternalCardRow
{
    /**
     * The columns that make a row internal.
     *
     * @return array<string, mixed>
     */
    public static function attributes(InternalUrl $link): array
    {
        return [
            'status' => LinkCardStatus::Internal,
            'title' => null,
            'description' => null,
            'site_name' => null,
            'author_name' => null,
            'image_file_id' => null,
            'image_width' => null,
            'image_height' => null,
            'failure_count' => 0,
            'fetched_at' => null,
            'expires_at' => null,
            'next_attempt_at' => null,
            // Null for a URL of ours that names no record a card can be built from. The row still
            // exists, so the body counts as examined and stops being re-parsed; it simply draws
            // nothing.
            'internal_context' => $link->target?->value,
            'internal_record_id' => $link->recordId,
        ];
    }

    /** Move $card into that state, if it is not already there. */
    public static function convert(LinkCard $card, InternalUrl $link): void
    {
        $attributes = self::attributes($link);

        if (self::holds($card, $attributes)) {
            return;
        }

        LinkCard::query()
            ->whereKey($card->getKey())
            ->update($attributes + ['updated_at' => CarbonImmutable::now()]);

        self::deleteStoredPictures($card);

        $card->forceFill($attributes)->syncOriginal();
    }

    /**
     * Whether $card is already exactly the row this class describes.
     *
     * The whole state, not only the pointer: a row carrying a title, a picture or a schedule beside
     * an internal status is not in the one state internal rows have, and returning early on it would
     * leave a fetched card's metadata where the invariant says there is none.
     *
     * @param  array<string, mixed>  $attributes
     */
    private static function holds(LinkCard $card, array $attributes): bool
    {
        foreach ($attributes as $column => $value) {
            if ($card->getAttribute($column) !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * Delete every picture stored for $card — after the update, and by what the files say they
     * belong to rather than by the id the row held a moment ago.
     *
     * Read first, the id is a race: a fetch already in flight can store its own picture and write
     * `image_file_id` in the window before the update, and that picture would then be referenced by
     * nothing and reachable by nothing — no unreferenced-file sweep collects a file whose card row
     * still exists. Ordering it this way closes the window from both ends. The update nulls
     * `next_attempt_at`, which releases the lease, so every later write fails its fence and that
     * worker deletes its own image; anything stored before the update is still related to this card
     * and is collected here.
     */
    private static function deleteStoredPictures(LinkCard $card): void
    {
        $pictures = File::query()
            // Both halves, always: this deletes bytes, and a filter that named only the card would
            // take another card's pictures with it.
            ->where('related_entity_type', LinkCardImage::RELATED_TYPE)
            ->where('related_entity_id', $card->getKey())
            ->get();

        // One at a time, so FileObserver runs for each: the bytes and the cached thumbnails go too.
        foreach ($pictures as $picture) {
            $picture->delete();
        }
    }
}
