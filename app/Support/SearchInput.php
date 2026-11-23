<?php

namespace App\Support;

/** See docs/internals/text-comparison.md, "Search limits". */
final class SearchInput
{
    /** Code points of one field, spaces included. */
    public const MAX_LENGTH = 100;

    public const MAX_TERMS = 10;

    /** @throws SearchInputRejected */
    public static function ensureLength(string $value): void
    {
        if (mb_strlen($value) > self::MAX_LENGTH) {
            throw new SearchInputRejected(__('Search with up to :max characters.', ['max' => self::MAX_LENGTH]));
        }
    }

    /**
     * @param  list<string>  $terms
     *
     * @throws SearchInputRejected
     */
    public static function ensureTermCount(array $terms): void
    {
        if (count($terms) > self::MAX_TERMS) {
            throw new SearchInputRejected(__('Search with up to :max keywords.', ['max' => self::MAX_TERMS]));
        }
    }
}
