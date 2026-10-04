<?php

declare(strict_types=1);

namespace App\LinkCard;

use App\Models\LinkCard;
use App\Support\LinkCardStatus;
use Carbon\CarbonImmutable;

/**
 * Everything but the URL and the pointer is null: the row is shared by every body mentioning the
 * URL, so no viewer-dependent content may be cached on it. Nulling `next_attempt_at` also releases
 * the lease, so a fetch still in flight fails its fence instead of writing metadata onto the
 * converted row.
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
            // Null for a URL of ours that names no record: the row still exists, so the body counts
            // as examined and simply draws nothing.
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

        LinkCardImage::deleteAllFor((int) $card->getKey());

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
}
