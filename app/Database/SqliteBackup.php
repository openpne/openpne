<?php

declare(strict_types=1);

namespace App\Database;

use Exception;
use RuntimeException;
use SQLite3;

/** See docs/internals/runtime.md, "SQLite backup". */
final class SqliteBackup
{
    /** As long as the site's own connections wait on a lock: pdo_sqlite's default. */
    public const WAIT_MS = 60000;

    private const BESIDE = ['-journal', '-wal', '-shm'];

    public static function available(): bool
    {
        return class_exists(SQLite3::class);
    }

    /**
     * Writes a copy of $database at $path, replacing an earlier copy there once this one is whole;
     * nothing is touched where any file it would write or remove is the database or one beside it.
     */
    public static function write(string $database, string $path): void
    {
        $target = self::target($path);
        self::refuseOverlap($database, $target);
        $lock = self::lock($target.'.lock');
        try {
            foreach (self::BESIDE as $suffix) {
                if (file_exists($target.$suffix) || is_link($target.$suffix)) {
                    throw new RuntimeException("{$target}{$suffix} is beside the earlier copy: something has that copy open, or stopped while writing to it. Close it, or remove that file once nothing has the copy open.");
                }
            }
            $temp = $target.'.new';
            self::remove($temp);
            try {
                self::empty($temp);
                self::copy($database, $temp);
                if (! @rename($temp, $target)) {
                    throw new RuntimeException("{$target} could not be written.");
                }
            } finally {
                self::remove($temp);
            }
        } finally {
            fclose($lock);
        }
    }

    /** Copies $database into $empty, an empty file the caller made, leaving the copy out of WAL. */
    public static function copy(string $database, string $empty): void
    {
        $from = new SQLite3($database, SQLITE3_OPEN_READONLY);
        try {
            $from->enableExceptions(true);
            $from->busyTimeout(self::WAIT_MS);
            $to = new SQLite3($empty, SQLITE3_OPEN_READWRITE);
            try {
                $to->enableExceptions(true);
                try {
                    $from->backup($to);
                } catch (Exception $e) {
                    // SQLite records why a backup failed on the connection it writes to.
                    throw new RuntimeException("{$database} could not be copied: {$to->lastErrorMsg()}.", 0, $e);
                }
                // The copy carries the WAL mark in its header, and a -wal file left beside it would be replayed into it.
                $mode = $to->querySingle('PRAGMA journal_mode=DELETE');
                if ($mode !== 'delete') {
                    throw new RuntimeException("{$empty} stayed in journal mode {$mode}.");
                }
            } finally {
                $to->close();
            }
        } finally {
            $from->close();
        }
    }

    private static function target(string $path): string
    {
        $path = str_starts_with($path, '/') ? $path : getcwd().'/'.$path;
        $name = basename($path);
        $directory = realpath(dirname($path));
        if ($directory === false || ! is_dir($directory) || in_array($name, ['', '.', '..'], true)) {
            throw new RuntimeException("{$path} is not a file in a directory that exists.");
        }
        $target = rtrim($directory, '/').'/'.$name;
        if (is_dir($target)) {
            throw new RuntimeException("{$target} is a directory.");
        }

        return $target;
    }

    /** Through a link, a relative path or a second name of the same file alike. */
    private static function refuseOverlap(string $database, string $target): void
    {
        $theirs = [];
        foreach (array_unique([$database, realpath($database) ?: $database]) as $file) {
            foreach (['', ...self::BESIDE] as $suffix) {
                $theirs[] = $file.$suffix;
            }
        }
        $mine = [$target.'.lock'];
        foreach ([$target, $target.'.new'] as $file) {
            foreach (['', ...self::BESIDE] as $suffix) {
                $mine[] = $file.$suffix;
            }
        }
        foreach ($mine as $file) {
            foreach ($theirs as $their) {
                if ($file === $their || realpath($file) === $their || self::sameFile($file, $their)) {
                    throw new RuntimeException("{$target} would write over the database {$database} or a file beside it.");
                }
            }
        }
    }

    private static function sameFile(string $a, string $b): bool
    {
        $one = @stat($a);
        $other = @stat($b);

        return $one !== false && $other !== false && [$one['dev'], $one['ino']] === [$other['dev'], $other['ino']];
    }

    /**
     * Held until the copy is renamed into place and the files beside it are removed; never removed itself.
     *
     * @return resource
     */
    private static function lock(string $file): mixed
    {
        $umask = umask(0o077);
        try {
            $handle = @fopen($file, 'c');
        } finally {
            umask($umask);
        }
        if ($handle === false) {
            throw new RuntimeException("{$file} cannot be written to.");
        }
        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            throw new RuntimeException('Another openpne:backup-sqlite is writing '.substr($file, 0, -strlen('.lock')).'.');
        }

        return $handle;
    }

    private static function empty(string $file): void
    {
        $umask = umask(0o077);
        try {
            $handle = @fopen($file, 'x');
        } finally {
            umask($umask);
        }
        if ($handle === false) {
            throw new RuntimeException("{$file} cannot be written to.");
        }
        fclose($handle);
    }

    private static function remove(string $file): void
    {
        foreach (['', ...self::BESIDE] as $suffix) {
            @unlink($file.$suffix);
        }
    }
}
