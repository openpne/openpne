<?php

declare(strict_types=1);

namespace App\SiteCheck;

use App\Database\DatabaseServer;
use App\Database\DatabaseServerProbe;
use Database\Seeders\InstallSeeder;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** What the command line can tell about a site whose `.env` exists; never prints a secret's value. */
final class InstalledCheck
{
    public const STALE_JOB_SECONDS = 600;

    public function __construct(
        private readonly SiteLayout $layout,
        private readonly Migrator $migrator,
        private readonly DatabaseServerProbe $servers,
        private readonly SqliteSupport $sqlite,
    ) {}

    /** @return list<Finding> */
    public function run(): array
    {
        return array_values(array_filter([
            $this->envFile(),
            config('app.env') === 'production'
                ? Finding::ok('app.env', 'app_env')
                : Finding::fail('app.env', 'app_env_other', ['value' => (string) config('app.env')]),
            config('app.debug')
                ? Finding::fail('app.debug', 'app_debug_on')
                : Finding::ok('app.debug', 'app_debug'),
            filled(config('app.key'))
                ? Finding::ok('app.key', 'app_key')
                : Finding::fail('app.key', 'app_key_missing'),
            $this->appUrl(),
            ...$this->database(),
            file_exists($this->layout->base.'/.htaccess')
                ? Finding::ok('tree.htaccess', 'tree_htaccess')
                : Finding::fail('tree.htaccess', 'tree_htaccess_missing', ['path' => $this->layout->base]),
            ...$this->dataDirectory(),
            $this->queue(),
            $this->mail(),
        ]));
    }

    /** Whether APP_URL is what the web part can fetch: https, a host, and nothing after it. */
    public static function usableAppUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && ($parts['scheme'] ?? '') === 'https'
            && ($parts['host'] ?? '') !== ''
            && in_array($parts['path'] ?? '', ['', '/'], true)
            && ! isset($parts['query']) && ! isset($parts['fragment']) && ! isset($parts['user']);
    }

    private function envFile(): Finding
    {
        $mode = fileperms($this->layout->envFile) & 0o777;

        return ($mode & 0o077) === 0
            ? Finding::ok('env.mode', 'env_mode')
            : Finding::fail('env.mode', 'env_mode_open', ['mode' => sprintf('%o', $mode), 'path' => $this->layout->envFile]);
    }

    private function appUrl(): Finding
    {
        $url = (string) config('app.url');
        if (self::usableAppUrl($url)) {
            return Finding::ok('app.url', 'app_url', ['url' => $url]);
        }

        return Finding::fail('app.url', 'app_url_unusable', ['url' => self::shownUrl($url)]);
    }

    /** A URL with a user in it may carry a password, so only a URL without one is repeated. */
    public static function shownUrl(string $url): string
    {
        return parse_url($url, PHP_URL_USER) === null ? $url : '…';
    }

    /** @return list<Finding> */
    private function database(): array
    {
        $name = (string) config('database.default');
        $driver = (string) config("database.connections.{$name}.driver");
        if ($driver === 'sqlite') {
            $findings = $this->sqliteBackup();
        } else {
            $tool = BackupTools::mysql();
            $findings = [
                $tool !== null
                    ? Finding::ok('db.backup_tool', 'db_backup_tool', ['path' => $tool])
                    : Finding::fail('db.backup_tool', 'db_backup_tool_mysql'),
            ];
        }

        $server = null;
        try {
            $server = $this->servers->behind($name);
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            return [Finding::fail('db.connect', 'db_connect_failed', ['reason' => self::reason($e)]), ...self::server($server, $driver), ...$findings];
        }
        $findings = [Finding::ok('db.connect', 'db_connect', ['connection' => $name]), ...self::server($server, $driver), ...$findings];

        try {
            if (! $this->migrator->repositoryExists()) {
                return [...$findings, Finding::fail('db.migrations', 'db_not_installed')];
            }
            $files = $this->migrator->getMigrationFiles([database_path('migrations'), ...$this->migrator->paths()]);
            $pending = count(array_diff(array_keys($files), $this->migrator->getRepository()->getRan()));
            $findings[] = $pending === 0
                ? Finding::ok('db.migrations', 'db_migrations')
                : Finding::fail('db.migrations', 'db_migrations_pending', ['count' => $pending]);

            $seeded = false;
            foreach (InstallSeeder::GUARD_TABLES as $table) {
                $seeded = $seeded || (Schema::hasTable($table) && DB::table($table)->exists());
            }
            $findings[] = $seeded
                ? Finding::ok('db.initial', 'db_initial')
                : Finding::fail('db.initial', 'db_initial_missing');
        } catch (Throwable $e) {
            $findings[] = Finding::unknown('db.migrations', 'db_unreadable', ['reason' => self::reason($e)]);
        }

        return $findings;
    }

    /**
     * The copy is not tried, as it takes as long and as much disk as the database.
     *
     * @return list<Finding>
     */
    private function sqliteBackup(): array
    {
        $findings = [
            $this->sqlite->hasExtension()
                ? Finding::ok('db.backup_tool', 'db_backup_sqlite')
                : Finding::fail('db.backup_tool', 'db_backup_sqlite_missing'),
        ];
        $version = $this->sqlite->libraryVersion();
        if ($version !== null && version_compare($version, SqliteSupport::LIBRARY_MINIMUM, '<')) {
            $findings[] = Finding::fail('db.sqlite', 'db_sqlite_old', ['version' => $version, 'minimum' => SqliteSupport::LIBRARY_MINIMUM]);
        }

        return $findings;
    }

    /** @return list<Finding> */
    private static function server(?DatabaseServer $server, string $driver): array
    {
        return match (true) {
            $server === null => [],
            ! $server->supported() => [Finding::fail('db.server', 'db_server_mariadb', ['server' => $server->name(), 'minimum' => DatabaseServer::MARIADB_MINIMUM])],
            $server->driver() !== $driver => [Finding::fail('db.server', 'db_server_driver', ['server' => $server->name(), 'driver' => $server->driver(), 'current' => $driver])],
            default => [],
        };
    }

    /** @return list<Finding> */
    private function dataDirectory(): array
    {
        $directory = $this->layout->dataDirectory();
        if (! is_dir($directory)) {
            return [];
        }

        $mode = fileperms($directory) & 0o777;
        $findings = [
            is_file($directory.'/.htaccess') && is_readable($directory.'/.htaccess') && SiteLayout::denies(File::get($directory.'/.htaccess'))
                ? Finding::ok('data.htaccess', 'data_htaccess')
                : Finding::fail('data.htaccess', 'data_htaccess_missing', ['path' => $directory]),
            ($mode & 0o077) === 0
                ? Finding::ok('data.mode', 'data_mode')
                : Finding::fail('data.mode', 'data_mode_open', ['mode' => sprintf('%o', $mode), 'path' => $directory]),
        ];

        $name = (string) config('database.default');
        if (in_array(config("database.connections.{$name}.driver"), ['mysql', 'mariadb'], true)) {
            $findings[] = $this->mysqlOptionFile($name);
        }

        return $findings;
    }

    private function mysqlOptionFile(string $connection): Finding
    {
        $file = $this->layout->mysqlOptionFile();
        if (! file_exists($file)) {
            return Finding::fail('data.mysql_options', 'data_mysql_options_missing', ['path' => $file]);
        }
        $mode = fileperms($file) & 0o777;
        if (($mode & 0o077) !== 0) {
            return Finding::fail('data.mysql_options', 'data_mysql_options_open', ['mode' => sprintf('%o', $mode), 'path' => $file]);
        }
        $tool = BackupTools::mysql();
        if ($tool === null) {
            return Finding::unknown('data.mysql_options', 'data_mysql_options_no_tool');
        }
        if (! function_exists('proc_open')) {
            return Finding::unknown('data.mysql_options', 'data_mysql_options_no_proc_open');
        }

        // The option file's own path must come first: the dump tools read it nowhere else.
        $result = Process::timeout(120)->run([
            $tool, '--defaults-extra-file='.$file, '--no-data', '--single-transaction', '--no-tablespaces',
            (string) config("database.connections.{$connection}.database"),
        ]);

        return $result->successful()
            ? Finding::ok('data.mysql_options', 'data_mysql_options')
            : Finding::fail('data.mysql_options', 'data_mysql_options_refused', ['reason' => self::firstLine($result->errorOutput())]);
    }

    private function queue(): ?Finding
    {
        $name = (string) config('queue.default');
        $driver = (string) config("queue.connections.{$name}.driver");
        if ($driver !== 'database') {
            return Finding::ok('queue', 'queue_driver', ['driver' => $driver]);
        }

        try {
            $stale = DB::connection(config("queue.connections.{$name}.connection"))
                ->table((string) config("queue.connections.{$name}.table", 'jobs'))
                ->whereNull('reserved_at')
                ->where('available_at', '<=', now()->getTimestamp() - self::STALE_JOB_SECONDS)
                ->count();
        } catch (Throwable) {
            return null;
        }

        return $stale === 0
            ? Finding::ok('queue', 'queue_database')
            : Finding::fail('queue', 'queue_stale', ['count' => $stale]);
    }

    private function mail(): Finding
    {
        $mailer = (string) config('mail.default');
        $transport = (string) config("mail.mailers.{$mailer}.transport");

        return match ($transport) {
            'log' => $this->logKeepsDebug((string) (config("mail.mailers.{$mailer}.channel") ?? config('logging.default')))
                ? Finding::warn('mail', 'mail_log')
                : Finding::warn('mail', 'mail_log_discarded'),
            'array' => Finding::warn('mail', 'mail_array'),
            default => Finding::ok('mail', 'mail', ['transport' => $transport]),
        };
    }

    /** The log transport writes at debug, so a channel set above it keeps nothing of a mail. */
    private function logKeepsDebug(string $channel, int $depth = 0): bool
    {
        $config = config("logging.channels.{$channel}");
        if (! is_array($config) || $depth > 3) {
            return true;
        }
        if (($config['driver'] ?? null) === 'stack') {
            foreach ((array) ($config['channels'] ?? []) as $inner) {
                if ($this->logKeepsDebug(trim((string) $inner), $depth + 1)) {
                    return true;
                }
            }

            return false;
        }

        return ($config['driver'] ?? null) !== 'null' && strtolower((string) ($config['level'] ?? 'debug')) === 'debug';
    }

    /** An exception's first line, with the database password taken out wherever a driver repeated it. */
    public static function reason(Throwable $e): string
    {
        return self::firstLine($e->getMessage());
    }

    /** A command's first line of output, the database password taken out as in reason(). */
    public static function firstLine(string $text): string
    {
        $line = trim((string) strtok(trim($text), "\n"));
        $password = (string) config('database.connections.'.config('database.default').'.password');
        if ($password !== '') {
            $line = str_replace($password, '…', $line);
        }

        return mb_strimwidth($line, 0, 300, '…');
    }
}
