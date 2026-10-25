<?php

declare(strict_types=1);

namespace App\Files;

use App\Models\File;

/**
 * The facts of a picture a processor gave: its size and, when the processor could tell, whether it animates,
 * each written whenever it differs (a canonical under another encoder is a different picture).
 */
final class ImageFacts
{
    public static function record(File $file, ProcessedImage $canonical): bool
    {
        $facts = ['width' => $canonical->width, 'height' => $canonical->height];

        if ($canonical->animated !== null) {
            $facts['animated'] = $canonical->animated;
        }

        return self::write($file, $facts);
    }

    /** @param  array<string, int|bool>  $facts */
    public static function write(File $file, array $facts): bool
    {
        $changed = array_filter($facts, fn (int|bool $value, string $key): bool => $file->{$key} !== $value, ARRAY_FILTER_USE_BOTH);

        if ($changed === []) {
            return false;
        }

        $file->update($changed);

        return true;
    }
}
