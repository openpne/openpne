<?php

declare(strict_types=1);

namespace App\Setup;

use Dotenv\Dotenv;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

/** Writes `.env` through a file beside it, renamed into place only once what was written reads back as given. */
final class EnvFile
{
    public const TEMP_PREFIX = '.env.openpne-setup-';

    /** Signals that the written file did not parse at all. */
    public const UNREADABLE = '';

    /**
     * @param  array<string, string>  $values
     * @return string|null the key that did not read back, UNREADABLE, or null once `.env` is in place
     */
    public static function create(string $path, string $example, array $values): ?string
    {
        if (file_exists($path)) {
            throw new RuntimeException("{$path} exists.");
        }
        $temp = PrivateFile::temp(dirname($path), self::TEMP_PREFIX, File::get($example), 0o600);

        try {
            Env::writeVariables($values, $temp, true);

            return self::mismatch($temp, $values) ?? self::rename($temp, $path);
        } finally {
            @unlink($temp);
        }
    }

    /**
     * Sets one key and leaves every other key reading as it did.
     *
     * @return string|null as create()
     */
    public static function set(string $path, string $key, string $value): ?string
    {
        $contents = File::get($path);
        $temp = PrivateFile::temp(dirname($path), self::TEMP_PREFIX, $contents, fileperms($path) & 0o777);

        try {
            Env::writeVariables([$key => $value], $temp, true);
            $expected = [$key => $value] + self::read($contents);

            return self::mismatch($temp, $expected, exact: true) ?? self::rename($temp, $path);
        } finally {
            @unlink($temp);
        }
    }

    /** A value as read from the file, given as `(string) env()` gives it. */
    public static function asEnv(?string $value): string
    {
        return match (strtolower($value ?? '')) {
            'true', '(true)' => '1',
            'false', '(false)', 'empty', '(empty)', 'null', '(null)' => '',
            default => preg_match('/\A([\'"])(.*)\1\z/', (string) $value, $match) === 1 ? $match[2] : (string) $value,
        };
    }

    /** @return array<string, string|null> */
    public static function read(string $contents): array
    {
        return Dotenv::parse($contents);
    }

    /** @param array<string, string|null> $expected */
    private static function mismatch(string $file, array $expected, bool $exact = false): ?string
    {
        try {
            // Dotenv::parse reads no process environment, which would win over the file in env().
            $read = self::read(File::get($file));
        } catch (Throwable) {
            return self::UNREADABLE;
        }
        foreach ($expected as $key => $value) {
            if (! array_key_exists($key, $read) || $read[$key] !== $value) {
                return $key;
            }
        }
        $extra = array_diff_key($read, $expected);

        return $exact && $extra !== [] ? (string) array_key_first($extra) : null;
    }

    private static function rename(string $temp, string $path): null
    {
        PrivateFile::rename($temp, $path);

        return null;
    }
}
