<?php

declare(strict_types=1);

namespace App\SiteCheck;

use App\Database\DatabaseServer;

/** What the command line can tell before `.env` exists; reads neither `.env` nor the database. */
final class BeforeInstallCheck
{
    /** docs/installing.md, "What the server needs", less the database's own. */
    public const EXTENSIONS = [
        'ctype', 'curl', 'dom', 'exif', 'fileinfo', 'filter', 'gd', 'hash', 'iconv', 'intl', 'libxml',
        'mbstring', 'openssl', 'pcre', 'session', 'tokenizer', 'xml', 'xmlreader', 'zip', 'zlib',
    ];

    public const MEMORY_BYTES = 256 * 1024 * 1024;

    private const WRITABLE = [
        'storage/app/private', 'storage/app/public', 'storage/framework/cache/data',
        'storage/framework/sessions', 'storage/framework/views', 'storage/logs',
    ];

    public function __construct(private readonly SiteLayout $layout, private readonly SqliteSupport $sqlite, private readonly PhpCommand $php) {}

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
        $limit = (string) ini_get('memory_limit');

        return self::enoughMemory($limit)
            ? Finding::ok('cli.memory', 'cli_memory', ['value' => $limit])
            : Finding::warn('cli.memory', 'cli_memory_low', ['value' => $limit, 'php' => $this->php->word()]);
    }

    public static function enoughMemory(string $limit): bool
    {
        $bytes = self::bytes($limit);

        return $bytes === null || $bytes < 0 || $bytes >= self::MEMORY_BYTES;
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
