<?php

declare(strict_types=1);

namespace App\Setup;

use RuntimeException;

final class PrivateFile
{
    public const CREATE_PREFIX = '.openpne-new.';

    /** A new file of a random name in $directory, made at $mode or narrower and written through its own handle; renamed into place, or removed, by the caller. */
    public static function temp(string $directory, string $prefix, string $contents, int $mode): string
    {
        $path = $directory.'/'.$prefix.bin2hex(random_bytes(8));
        $umask = umask(~$mode & 0o777);
        try {
            $handle = @fopen($path, 'x');
        } finally {
            umask($umask);
        }
        if ($handle === false) {
            throw new RuntimeException("{$directory} cannot be written to.");
        }
        try {
            $written = fwrite($handle, $contents);
        } finally {
            fclose($handle);
        }
        if ($written !== strlen($contents)) {
            @unlink($path);
            throw new RuntimeException("{$path} could not be written.");
        }

        return $path;
    }

    /** False where anything has the name, a broken link included, which PHP's fopen() x would follow. */
    public static function create(string $path, string $contents, int $mode): bool
    {
        try {
            $temp = self::temp(dirname($path), self::CREATE_PREFIX, $contents, $mode);
        } catch (RuntimeException) {
            return false;
        }
        try {
            if (function_exists('link') && @link($temp, $path)) {
                return true;
            }

            // Where disable_functions holds link() or the filesystem has no hard links: rename() never follows a link either, though it replaces one made since this check.
            return ! is_link($path) && ! file_exists($path) && @rename($temp, $path);
        } finally {
            @unlink($temp);
        }
    }

    /** Removes what temp() left with $prefix in $directory; the caller holds SetupLock, so no run is writing one. */
    public static function removeStale(string $directory, string $prefix): void
    {
        foreach (@scandir($directory) ?: [] as $name) {
            if (preg_match('/\A'.preg_quote($prefix, '/').'[0-9a-f]{16}(?:-wal|-shm|-journal)?\z/', $name) === 1) {
                @unlink($directory.'/'.$name);
            }
        }
    }

    /** Read a piece at a time, so a dump of any size fits in memory. */
    public static function holds(string $path, string $needle): bool
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return false;
        }
        $tail = '';

        try {
            while (($chunk = fread($handle, 1 << 16)) !== false && $chunk !== '') {
                if (str_contains($tail.$chunk, $needle)) {
                    return true;
                }
                $tail = substr($chunk, -strlen($needle));
            }
        } finally {
            fclose($handle);
        }

        return false;
    }

    public static function rename(string $temp, string $path): void
    {
        if (! @rename($temp, $path)) {
            throw new RuntimeException("{$path} could not be written.");
        }
    }
}
