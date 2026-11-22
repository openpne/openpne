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

    /** The endings of the files beside a copy: a copy named with one could be another run's lock or work file. */
    private const RESERVED = ['.lock', '.new', ...self::BESIDE];

    public static function available(): bool
    {
        return class_exists(SQLite3::class);
    }

    /**
     * Writes a copy of $database at $path, replacing an earlier copy there once this one is whole;
     * nothing is touched where a file it would write or remove is the database, one beside it, or another run's.
     */
    public static function write(string $database, string $path): void
    {
        $target = self::target($path);
        self::refuseOverlap($database, $target);
        foreach (self::RESERVED as $suffix) {
            // A file system that ignores case would take p.LOCK for p.lock.
            if (str_ends_with(strtolower($target), $suffix)) {
                throw new RuntimeException("{$target} ends in {$suffix}, which openpne:backup-sqlite keeps for the files beside a copy.");
            }
        }
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
                self::flush($temp);
                if (! @rename($temp, $target)) {
                    throw new RuntimeException("{$target} could not be written.");
                }
                self::flushDirectory(dirname($target));
            } finally {
                self::remove($temp);
            }
        } finally {
            fclose($lock);
        }
    }

    /** Copies $database into $empty, an empty file the caller made, leaving the copy out of WAL and not yet flushed to disk. */
    public static function copy(string $database, string $empty): void
    {
        $from = new SQLite3($database, SQLITE3_OPEN_READONLY);
        try {
            $from->enableExceptions(true);
            $from->busyTimeout(self::WAIT_MS);
            $to = new SQLite3($empty, SQLITE3_OPEN_READWRITE);
            try {
                $to->enableExceptions(true);
                // SQLite flushes the copy before it lets go of the database's read lock, so a slow disk would hold every commit up.
                $to->exec('PRAGMA synchronous=OFF');
                $failed = null;
                try {
                    $from->backup($to);
                } catch (Exception $e) {
                    $failed = $e;
                }
                // SQLite records why a backup failed on the connection it writes to, where PHP does not look when the backup cannot start.
                if ($failed !== null || $to->lastErrorCode() !== 0) {
                    throw new RuntimeException("{$database} could not be copied: {$to->lastErrorMsg()}.", 0, $failed);
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
        if (is_link($file) || (file_exists($file) && ! is_file($file))) {
            throw new RuntimeException("{$file} is not a plain file, and the lock has to be one.");
        }
        $umask = umask(0o077);
        try {
            $handle = @fopen($file, 'c');
        } finally {
            umask($umask);
        }
        if ($handle === false) {
            throw new RuntimeException("{$file} cannot be written to.");
        }
        if (! flock($handle, LOCK_EX | LOCK_NB, $held)) {
            fclose($handle);

            throw new RuntimeException($held ? 'Another openpne:backup-sqlite is writing '.substr($file, 0, -strlen('.lock')).'.' : "{$file} could not be locked.");
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

    private static function flush(string $file): void
    {
        $handle = @fopen($file, 'r+');
        if ($handle === false || ! fsync($handle)) {
            throw new RuntimeException("{$file} could not be flushed to disk.");
        }
        fclose($handle);
    }

    private static function flushDirectory(string $directory): void
    {
        // Best effort: some file systems cannot flush a directory, and the copy under its name is whole either way.
        $handle = @fopen($directory, 'r');
        if ($handle !== false) {
            @fsync($handle);
            fclose($handle);
        }
    }

    private static function remove(string $file): void
    {
        foreach (['', ...self::BESIDE] as $suffix) {
            @unlink($file.$suffix);
        }
    }
}
