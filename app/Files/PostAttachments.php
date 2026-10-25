<?php

namespace App\Files;

use App\Models\File;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A post's slot rows hold pictures and files alike; this is where the two are told apart, by the
 * stored type, so a reader that means "photo" asks for pictures (docs/internals/attachments.md, "Pictures and files").
 */
final class PostAttachments
{
    /**
     * The slot rows whose file this version draws inline, as a relation so counts and existence
     * checks stay in SQL.
     *
     * @template TRelated of Model
     * @template TDeclaring of Model
     *
     * @param  HasMany<TRelated, TDeclaring>  $slots
     * @return HasMany<TRelated, TDeclaring>
     */
    public static function pictures(HasMany $slots): HasMany
    {
        return $slots->whereIn('file_id', File::query()->select('id')->whereIn('type', ImageSpec::rasterMimes()));
    }

    /**
     * Loaded slot rows split for display; a row whose File is gone stays with the pictures, which
     * tolerate it (ImageLadder).
     *
     * @template TRow of Model
     *
     * @param  Collection<int, TRow>  $slots
     * @return array{images: Collection<int, TRow>, files: Collection<int, TRow>}
     */
    public static function partition(Collection $slots): array
    {
        [$images, $files] = $slots->partition(fn (Model $slot): bool => $slot->file === null || FileResponse::isRaster($slot->file));

        return ['images' => $images->values(), 'files' => $files->values()];
    }
}
