<?php

declare(strict_types=1);

namespace App\SiteCheck;

/** See docs/internals/runtime.md, "Site setup". */
final class PhpCommand
{
    public function __construct(
        private readonly string $binary = PHP_BINARY,
        private readonly string $release = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.'.'.PHP_RELEASE_VERSION,
    ) {}

    /** An absolute path for a line cron runs, never one holding the patch release while PATH has the same PHP under another name. */
    public function path(): string
    {
        // PHP_BINARY has its links resolved, which names the patch release's directory where a versioned name links into one.
        if (! str_contains($this->binary, $this->release)) {
            return $this->binary;
        }
        $minor = substr($this->release, 0, (int) strrpos($this->release, '.'));
        foreach (['php'.$minor, 'php'] as $name) {
            foreach (BackupTools::onPath($name) as $link) {
                if (! str_contains($link, $this->release) && BackupTools::same($link, $this->binary)) {
                    return $link;
                }
            }
        }

        return $this->binary;
    }

    /** What a sentence tells the operator to type: plain `php` where PATH's runs this PHP. */
    public function word(): string
    {
        $plain = BackupTools::locate('php');
        if ($this->binary === '' || ($plain !== null && BackupTools::same($plain, $this->binary))) {
            return 'php';
        }

        return $this->path();
    }
}
