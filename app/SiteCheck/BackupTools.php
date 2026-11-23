<?php

declare(strict_types=1);

namespace App\SiteCheck;

/** Finds commands without running them. */
final class BackupTools
{
    /** Named as PATH has it, not as the link resolves (docs/internals/runtime.md, "Site setup"). */
    public static function mysql(): ?string
    {
        $tool = self::locate('mysqldump') ?? self::locate('mariadb-dump');
        $real = $tool === null ? false : @realpath($tool);
        if ($real === false || basename($tool) === 'mariadb-dump' || ! str_contains(basename($real), 'mariadb')) {
            return $tool;
        }
        // Called mysqldump, MariaDB's tool warns at every run, cron's included.
        foreach (self::onPath('mariadb-dump') as $named) {
            if (self::same($named, $real)) {
                return $named;
            }
        }

        return $real;
    }

    /** An absolute path, since cron runs with a shorter PATH than a login shell. */
    public static function locate(string $name): ?string
    {
        return self::onPath($name)[0] ?? null;
    }

    /** @return list<string> every executable file of that name on PATH, in PATH's order */
    public static function onPath(string $name): array
    {
        $found = [];
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            if ($directory === '' || ! str_starts_with($directory, '/')) {
                continue;
            }
            $candidate = rtrim($directory, '/').'/'.$name;
            // Silenced: open_basedir turns a look outside it into a warning, which Laravel throws.
            if (@is_file($candidate) && @is_executable($candidate)) {
                $found[] = $candidate;
            }
        }

        return $found;
    }

    public static function same(string $path, string $other): bool
    {
        $real = @realpath($path);
        if ($real === false) {
            return false;
        }
        if ($real === @realpath($other)) {
            return true;
        }
        $a = @stat($real);
        $b = @stat($other);

        return $a !== false && $b !== false && $a['dev'] === $b['dev'] && $a['ino'] === $b['ino'];
    }
}
