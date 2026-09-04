<?php

namespace App\Files;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * The Update FormRequests check the image cap and the Update Actions apply it from this same
 * normalized delta, so the two cannot drift.
 */
final class ImageEdit
{
    /**
     * @param  array<int, UploadedFile>  $additions  new uploads to add into free slots
     * @param  array<int, int>  $removals  image/file ids requested for removal (deduped ints)
     */
    private function __construct(
        public readonly array $additions,
        public readonly array $removals,
    ) {}

    /** The extraction SSoT: the images[] uploads and remove_images[] ids off the edit request. */
    public static function fromRequest(Request $request): self
    {
        $files = $request->file('images', []);

        return new self(
            // A malformed payload can make file() return a single UploadedFile rather than an array;
            // reject anything that isn't an UploadedFile (well-formed uploads are still the job of
            // PostImageRules) so this never blows up or mistypes downstream.
            is_array($files) ? array_values(array_filter($files, fn ($f) => $f instanceof UploadedFile)) : [],
            self::normalizeRemovals((array) $request->input('remove_images', [])),
        );
    }

    /** An empty delta (a text-only edit adds and removes nothing). */
    public static function none(): self
    {
        return new self([], []);
    }

    /**
     * Build a delta directly (tests), normalizing the same way fromRequest does.
     *
     * @param  array<int, UploadedFile>  $additions
     * @param  array<int, int|string>  $removals
     */
    public static function of(array $additions = [], array $removals = []): self
    {
        return new self(
            array_values(array_filter($additions, fn ($f) => $f instanceof UploadedFile)),
            self::normalizeRemovals($removals),
        );
    }

    /**
     * The removals that actually belong to the entity (its current image ids), so a bogus id from
     * another entity can't inflate the removed count.
     *
     * @param  array<int, int>  $currentIds
     * @return array<int, int>
     */
    public function removalsAmong(array $currentIds): array
    {
        return array_values(array_intersect($this->removals, $currentIds));
    }

    /**
     * How many of the entity's images survive the edit (current minus the ones being removed).
     *
     * @param  array<int, int>  $currentIds
     */
    public function keptCount(array $currentIds): int
    {
        return count($currentIds) - count($this->removalsAmong($currentIds));
    }

    /**
     * Whether the edit would push the post past the image cap: kept plus the new uploads.
     *
     * @param  array<int, int>  $currentIds
     */
    public function exceedsCap(array $currentIds): bool
    {
        return $this->keptCount($currentIds) + count($this->additions) > PostImages::MAX_IMAGES;
    }

    /**
     * Dedup first: a crafted remove_images=[id, id] must not count one image twice and so undercount
     * what is kept, slipping the cap.
     *
     * @param  array<int, int|string>  $removals
     * @return array<int, int>
     */
    private static function normalizeRemovals(array $removals): array
    {
        return array_values(array_unique(array_map('intval', $removals)));
    }
}
