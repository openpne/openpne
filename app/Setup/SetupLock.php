<?php

declare(strict_types=1);

namespace App\Setup;

use RuntimeException;

/** One openpne:setup at a time for a site, the run it starts again included (docs/internals/runtime.md, "Site setup"). */
final class SetupLock
{
    public const FILE = 'openpne-setup.lock';

    /** The descriptor a run hands its lock on to the run it starts. */
    public const DESCRIPTOR = 3;

    /** @param resource $handle */
    private function __construct(public readonly mixed $handle) {}

    /** Null while another run holds it. */
    public static function take(string $path): ?self
    {
        $handed = self::handed($path);
        if ($handed !== null) {
            return new self($handed);
        }
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            throw new RuntimeException("{$path} cannot be written to.");
        }
        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        return new self($handle);
    }

    /** @return resource|null */
    private static function handed(string $path): mixed
    {
        $handle = @fopen('php://fd/'.self::DESCRIPTOR, 'r');
        if ($handle === false) {
            return null;
        }
        $held = fstat($handle);
        $file = @stat($path);
        // flock through another descriptor of the open file that holds the lock is granted at once.
        if ($held !== false && $file !== false && [$held['dev'], $held['ino']] === [$file['dev'], $file['ino']] && flock($handle, LOCK_EX | LOCK_NB)) {
            return $handle;
        }
        fclose($handle);

        return null;
    }
}
