<?php

namespace App\Support;

use RuntimeException;

/**
 * Entries are lowercase, so the caller folds case before {@see contains}. A missing or unreadable
 * data file throws rather than reading as "not common", so the check can never silently disable.
 */
class CommonPasswordList
{
    /** @var array<string, true>|null */
    private static ?array $set = null;

    private static ?string $path = null;

    public static function contains(string $password): bool
    {
        return isset(self::set()[$password]);
    }

    /** Override the data-file path (tests); null restores the default. Resets the memoized set. */
    public static function useList(?string $path): void
    {
        self::$path = $path;
        self::$set = null;
    }

    /** @return array<string, true> */
    private static function set(): array
    {
        if (self::$set !== null) {
            return self::$set;
        }

        $path = self::$path ?? resource_path('data/common-passwords.txt');
        $lines = @file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException("Common-password blocklist not readable: {$path}");
        }

        return self::$set = array_fill_keys($lines, true);
    }
}
