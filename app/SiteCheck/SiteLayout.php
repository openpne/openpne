<?php

declare(strict_types=1);

namespace App\SiteCheck;

use Illuminate\Contracts\Foundation\Application;

/** The directories openpne:check reads and writes; the data directory sits beside the release, not inside it (docs/internals/runtime.md, "Site check"). */
final class SiteLayout
{
    public const DATA_DIRECTORY = 'openpne-data';

    public const MYSQL_OPTION_FILE = 'mysql-backup.cnf';

    public const DENY = <<<'HTACCESS'
        # Refuses every request when a document root is this directory, or holds it, rather than being public/
        # (docs/internals/runtime.md, "Apache .htaccess files").
        # Not wrapped in <IfModule>: without mod_rewrite Apache answers 500 rather than handing out the files.
        RewriteEngine On
        RewriteRule ^ - [F]

        HTACCESS;

    /** Byte for byte, as Apache reads lines its own way (a comment ending in a backslash takes the next line); a release changing DENY keeps accepting the bytes earlier ones wrote, or every updated site fails openpne:check. */
    public static function denies(string $contents): bool
    {
        return $contents === self::DENY;
    }

    public function __construct(
        public readonly string $base,
        public readonly string $public,
        public readonly string $storage,
        public readonly string $bootstrapCache,
        public readonly string $envFile,
    ) {}

    public static function of(Application $app): self
    {
        return new self(
            $app->basePath(),
            $app->publicPath(),
            $app->storagePath(),
            $app->bootstrapPath('cache'),
            $app->environmentFilePath(),
        );
    }

    public function parent(): string
    {
        return dirname($this->base);
    }

    public function dataDirectory(): string
    {
        return $this->parent().DIRECTORY_SEPARATOR.self::DATA_DIRECTORY;
    }

    public function mysqlOptionFile(): string
    {
        return $this->dataDirectory().DIRECTORY_SEPARATOR.self::MYSQL_OPTION_FILE;
    }

    /** Whether storage/ is the release's own directory, and so served wherever the release is. */
    public function storageInTree(): bool
    {
        return realpath($this->storage) === realpath($this->base.DIRECTORY_SEPARATOR.'storage');
    }
}
