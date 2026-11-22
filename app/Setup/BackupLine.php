<?php

declare(strict_types=1);

namespace App\Setup;

/** The lines openpne:setup prints for pasting into a login shell, bash or csh alike, or into cron. */
final class BackupLine
{
    public const SQLITE_FILE = 'backup.sqlite';

    public const MYSQL_FILE = 'backup.sql';

    /**
     * VACUUM INTO copies in one read transaction where `.backup` restarts on every write. SQLite
     * takes it under `-readonly` from 3.28.0.
     */
    public const SQLITE_MINIMUM = '3.28.0';

    /**
     * The sqlite3 shell does not wait on a lock unless told to. A minute is pdo_sqlite's default wait.
     */
    public const SQLITE_WAIT = '.timeout 60000';

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

    /** VACUUM INTO fails on an existing file, so the copy is written aside and moved over the last one. */
    public static function sqlite(string $tool, string $database, string $dataDirectory): string
    {
        $copy = $dataDirectory.'/'.self::SQLITE_FILE;

        return self::rotate($copy, self::quote($tool).' -readonly -cmd "'.self::SQLITE_WAIT.'" '.self::quote($database).' "VACUUM INTO \''.$copy.'.new\'"');
    }

    public static function mysql(string $tool, string $optionFile, string $database, string $dataDirectory): string
    {
        $copy = $dataDirectory.'/'.self::MYSQL_FILE;

        return self::rotate($copy, self::quote($tool).' --defaults-extra-file='.self::quote($optionFile)
            .' --single-transaction --no-tablespaces '.self::quote($database).' > '.self::quote($copy.'.new'));
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
