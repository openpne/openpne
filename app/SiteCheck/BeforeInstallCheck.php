<?php

declare(strict_types=1);

namespace App\SiteCheck;

use App\Database\DatabaseServer;
use App\Files\ImageSourceLimit;

/** What the command line can tell before `.env` exists; needs neither `.env` nor the database, the image limits read at their defaults without one. */
final class BeforeInstallCheck
{
    /** docs/installing.md, "What the server needs", less the database's own. */
    public const EXTENSIONS = [
        'ctype', 'curl', 'dom', 'exif', 'fileinfo', 'filter', 'gd', 'hash', 'iconv', 'intl', 'libxml',
        'mbstring', 'openssl', 'pcre', 'session', 'tokenizer', 'xml', 'xmlreader', 'zip', 'zlib',
    ];

    /** PHP's own default, the floor where no GD decode runs inside memory_limit. */
    public const MEMORY = '128M';

    private const WRITABLE = [
        'storage/app/private', 'storage/app/public', 'storage/framework/cache/data',
        'storage/framework/sessions', 'storage/framework/views', 'storage/logs',
    ];

    public function __construct(
        private readonly SiteLayout $layout,
        private readonly SqliteSupport $sqlite,
        private readonly PhpCommand $php,
        private readonly ?string $memoryLimit = null,
    ) {}

    /** @return list<Finding> */
    public function run(bool $installed): array
    {
        return [
            Finding::ok('cli.php', 'cli_php', ['version' => PHP_VERSION, 'path' => $this->php->path()]),
            $this->extensions(),
            $this->writable($installed),
            ...$this->databases($installed),
            function_exists('proc_open')
                ? Finding::ok('cli.proc_open', 'cli_proc_open')
                : Finding::warn('cli.proc_open', 'cli_proc_open_missing'),
            $this->memory(),
        ];
    }

    /** @return list<string> */
    public static function missingExtensions(array $loaded): array
    {
        return array_values(array_diff(self::EXTENSIONS, array_map('strtolower', $loaded)));
    }

    private function extensions(): Finding
    {
        $missing = self::missingExtensions(get_loaded_extensions());

        return $missing === []
            ? Finding::ok('cli.extensions', 'cli_extensions')
            : Finding::fail('cli.extensions', 'cli_extensions_missing', ['list' => implode(', ', $missing)]);
    }

    private function writable(bool $installed): Finding
    {
        $storage = $this->layout->storage;
        $paths = [];
        foreach (self::WRITABLE as $relative) {
            $paths[$relative] = $storage.substr($relative, strlen('storage'));
        }
        $paths['bootstrap/cache'] = $this->layout->bootstrapCache;
        // Where openpne-data/ is made, which only a site not yet installed still needs.
        if (! $installed) {
            $paths[$this->layout->parent()] = $this->layout->parent();
        }

        $refused = array_keys(array_filter($paths, fn (string $path): bool => ! is_dir($path) || ! is_writable($path)));

        return $refused === []
            ? Finding::ok('cli.writable', 'cli_writable')
            : Finding::fail('cli.writable', 'cli_writable_refused', ['list' => implode(', ', $refused)]);
    }

    /** @return list<Finding> */
    private function databases(bool $installed): array
    {
        $lacks = $this->sqlite->lacks();
        $sqlite = $lacks === [];
        $mysql = extension_loaded('pdo_mysql') ? BackupTools::mysql() : null;
        $needs = ['library' => SqliteSupport::LIBRARY_MINIMUM];
        $why = ! $installed && $this->sqlite->partlyThere() ? $lacks : [];

        if ($sqlite && $mysql !== null) {
            return [Finding::ok('cli.databases', 'cli_databases_both', ['mysql' => $mysql])];
        }
        if ($sqlite) {
            return [Finding::ok('cli.databases', 'cli_databases_sqlite')];
        }
        if ($mysql !== null) {
            return [Finding::ok('cli.databases', 'cli_databases_mysql', ['mysql' => $mysql, 'minimum' => DatabaseServer::MARIADB_MINIMUM, ...$needs]), ...$why];
        }

        // An installed site's own database is judged by InstalledCheck, with the tool it needs named.
        return [
            $installed
                ? Finding::warn('cli.databases', 'cli_databases_none', $needs)
                : Finding::fail('cli.databases', 'cli_databases_none', $needs),
            ...$why,
        ];
    }

    private function memory(): Finding
    {
        return self::commandMemory($this->memoryLimit ?? (string) ini_get('memory_limit'), defined('GD_BUNDLED') && (bool) constant('GD_BUNDLED'), self::opcacheOnTheCommandLine(), $this->php->word());
    }

    /** A command decodes stored sources up to the source byte cap, OpenPNE 3 rows among them, in its own process; a system libgd allocates outside memory_limit. */
    public static function commandMemory(string $limit, bool $bundledGd, bool $opcache, string $php): Finding
    {
        $bytes = self::bytes($limit);
        $replace = ['value' => $limit, 'php' => $php];

        if ($bundledGd && $bytes !== null && $bytes >= 0 && config('openpne.images.processor') === 'gd') {
            $cap = ImageSourceLimit::reachablePixels();

            $source = ImageSourceLimit::bytes();

            return ImageSourceLimit::pixelsFor($bytes, $opcache, $source) >= $cap
                ? Finding::ok('cli.memory', 'cli_memory', $replace)
                : Finding::warn('cli.memory', 'cli_memory_images_low', $replace + ['cap' => number_format($cap), 'needed' => ImageSourceLimit::megabytesFor($cap, $opcache, $source).'M']);
        }

        return self::enoughMemory($limit)
            ? Finding::ok('cli.memory', 'cli_memory', $replace)
            : Finding::warn('cli.memory', 'cli_memory_low', $replace + ['minimum' => self::MEMORY]);
    }

    public static function enoughMemory(string $limit): bool
    {
        $bytes = self::bytes($limit);

        return $bytes === null || $bytes < 0 || $bytes >= ini_parse_quantity(self::MEMORY);
    }

    private static function opcacheOnTheCommandLine(): bool
    {
        return extension_loaded('Zend OPcache')
            && filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN)
            && filter_var(ini_get('opcache.enable_cli'), FILTER_VALIDATE_BOOLEAN);
    }

    /** An ini size in bytes, -1 for unlimited, or null when it does not read as one. */
    public static function bytes(string $value): ?int
    {
        if (preg_match('/^\s*-?\d+\s*[kmg]?\s*$/i', $value) !== 1) {
            return null;
        }

        return (int) @ini_parse_quantity($value);
    }
}
