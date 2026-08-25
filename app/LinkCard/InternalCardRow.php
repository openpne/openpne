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
        if ($card->status === LinkCardStatus::Internal
            && $card->internal_context === $link->target?->value
            && $card->internal_record_id === $link->recordId) {
            return;
        }

        $attributes = self::attributes($link);
        $image = $card->image_file_id === null ? null : File::find($card->image_file_id);

        LinkCard::query()
            ->whereKey($card->getKey())
            ->update($attributes + ['updated_at' => CarbonImmutable::now()]);

        // A picture a fetch had already stored is now referenced by nothing, and no unreferenced-file
        // sweep can reach it while the row it hangs off still exists. Deleting the File takes its
        // bytes and its cached thumbnails with it (FileObserver).
        $image?->delete();

        $card->forceFill($attributes)->syncOriginal();
    }
}
