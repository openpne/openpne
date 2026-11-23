<?php

declare(strict_types=1);

namespace App\Setup;

/** The lines openpne:setup prints for pasting into a login shell, bash or csh alike, or into cron. */
final class BackupLine
{
    public const SQLITE_FILE = 'backup.sqlite';

    public const MYSQL_FILE = 'backup.sql';

    /**
     * Whether every part can go inside single quotes in both shells and in cron: none of these closes
     * the quotes, expands in csh, or ends a cron line.
     */
    public static function safe(string ...$parts): bool
    {
        foreach ($parts as $part) {
            if (preg_match('/[\'"!$`\\\\%\x00-\x1f\x7f]/', $part) === 1) {
                return false;
            }
        }

        return true;
    }

    public static function sqlite(string $php, string $base, string $dataDirectory): string
    {
        return 'cd '.self::quote($base).' && '.self::quote($php).' artisan openpne:backup-sqlite '.self::quote($dataDirectory.'/'.self::SQLITE_FILE);
    }

    public static function mysql(string $tool, string $optionFile, string $database, string $dataDirectory): string
    {
        $copy = $dataDirectory.'/'.self::MYSQL_FILE;

        // In a subshell, so that the copy is made 0600 as SQLite's is, while the shell it is pasted into keeps its umask.
        return '(umask 077 && '.self::rotate($copy, self::quote($tool).' --defaults-extra-file='.self::quote($optionFile)
            .' --single-transaction --no-tablespaces '.self::quote($database).' > '.self::quote($copy.'.new')).')';
    }

    public static function cron(string $php, string $base): string
    {
        return '*/5 * * * * cd '.self::quote($base).' && '.self::quote($php).' artisan schedule:run > /dev/null 2>&1';
    }

    private static function rotate(string $copy, string $write): string
    {
        return 'rm -f '.self::quote($copy.'.new').' && '.$write.' && mv '.self::quote($copy.'.new').' '.self::quote($copy);
    }

    private static function quote(string $part): string
    {
        return "'".$part."'";
    }
}
