<?php

declare(strict_types=1);

namespace App\Support;

/** See docs/internals/runtime.md, "Release marker". */
final class ReleaseMarker
{
    public const FILE = 'RELEASE';

    public static function sha(): ?string
    {
        $path = base_path(self::FILE);

        if (! is_file($path)) {
            return null;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (str_starts_with($line, 'sha=')) {
                $sha = trim(substr($line, 4));

                return $sha === '' ? null : $sha;
            }
        }

        return null;
    }
}
