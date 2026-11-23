<?php

declare(strict_types=1);

namespace App\SiteCheck;

/** The commands docs/updating.md backs a MySQL database up with, found on PATH without running them. */
final class BackupTools
{
    public static function mysql(): ?string
    {
        return self::locate('mysqldump') ?? self::locate('mariadb-dump');
    }

    /** An absolute path, since cron runs with a shorter PATH than a login shell. */
    public static function locate(string $name): ?string
    {
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            if ($directory === '' || ! str_starts_with($directory, '/')) {
                continue;
            }
            $candidate = rtrim($directory, '/').'/'.$name;
            // Silenced: open_basedir turns a look outside it into a warning, which Laravel throws.
            if (@is_file($candidate) && @is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
