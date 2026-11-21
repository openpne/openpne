<?php

declare(strict_types=1);

namespace App\Setup;

use RuntimeException;

final class PrivateFile
{
    /** A new file of a random name in $directory, narrowed to $mode before $contents go in; renamed into place by the caller. */
    public static function temp(string $directory, string $prefix, string $contents, int $mode): string
    {
        $path = $directory.'/'.$prefix.bin2hex(random_bytes(8));
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            throw new RuntimeException("{$directory} cannot be written to.");
        }
        fclose($handle);
        chmod($path, $mode);
        if (file_put_contents($path, $contents) !== strlen($contents)) {
            @unlink($path);
            throw new RuntimeException("{$path} could not be written.");
        }

        return $path;
    }

    public static function rename(string $temp, string $path): void
    {
        if (! @rename($temp, $path)) {
            throw new RuntimeException("{$path} could not be written.");
        }
    }
}
