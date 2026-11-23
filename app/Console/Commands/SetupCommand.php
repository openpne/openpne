<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsWebCheck;
use App\Database\DatabaseServer;
use App\Database\DatabaseServerProbe;
use App\Database\SqliteBackup;
use App\Setup\BackupLine;
use App\Setup\EnvFile;
use App\Setup\EnvValue;
use App\Setup\MysqlOptionFile;
use App\Setup\PrivateFile;
use App\Setup\ProxyList;
use App\Setup\SetupHost;
use App\Setup\SetupLock;
use App\Setup\StopSetup;
use App\SiteCheck\BackupTools;
use App\SiteCheck\Finding;
use App\SiteCheck\InstalledCheck;
use App\SiteCheck\SiteLayout;
use App\SiteCheck\SqliteSupport;
use App\SiteCheck\Verdict;
use Closure;
use DateTimeZone;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Encryption\Encrypter;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Prompts\Prompt;
use RuntimeException;
use SQLite3;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/** See docs/internals/runtime.md, "Site setup". */
class SetupCommand extends Command
{
    use RunsWebCheck;

    protected $signature = 'openpne:setup {--lang= : ja or en}';

    protected $description = 'Ask what a new site needs, and set it up';

    private const ADMIN_ATTEMPTS = 3;

    private const TRIAL_PREFIX = 'openpne-setup-';

    private const TRIAL_SECONDS = 300;

    /** What the database connection is made from, which must read here as in `.env`. */
    private const CONNECTION_KEYS = ['APP_KEY', 'DB_CONNECTION', 'DB_URL', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_SOCKET'];

    private string $locale = 'en';

    private SetupHost $host;

    private SiteLayout $layout;

    private ?bool $shownInputAccepted = null;

    private SetupLock $lock;

    private DatabaseServerProbe $servers;

    private SqliteSupport $sqlite;

    public function handle(SetupHost $host, DatabaseServerProbe $servers, SqliteSupport $sqlite): int
    {
        $this->host = $host;
        $this->servers = $servers;
        $this->sqlite = $sqlite;
        $lang = $this->option('lang');
        if ($lang !== null && ! in_array($lang, ['ja', 'en'], true)) {
            $this->error("Unknown language [{$lang}]. Expected ja or en.");

            return self::FAILURE;
        }
        $this->locale = $lang ?? CheckCommand::shellLocale() ?? 'en';

        // Without a terminal, Prompts falls back to questions that fail on the first required answer.
        if (! $host->interactive()) {
            $this->say('no_terminal');

            return self::FAILURE;
        }
        // Prompts would try stty through it at the first question, and print the failure before falling back.
        if (! $host->canSpawn()) {
            Prompt::fallbackWhen(true);
        }
        $this->layout = $this->laravel->bound(SiteLayout::class) ? $this->laravel->make(SiteLayout::class) : SiteLayout::of($this->laravel);
        try {
            $lock = SetupLock::take($this->layout->storage.'/framework/'.SetupLock::FILE);
        } catch (RuntimeException) {
            $this->say('lock_unwritable', ['path' => $this->layout->storage.'/framework'], 'error');

            return self::FAILURE;
        }
        if ($lock === null) {
            $this->say('busy', [], 'error');

            return self::FAILURE;
        }
        $this->lock = $lock;
        PrivateFile::removeStale($this->layout->base, EnvFile::TEMP_PREFIX);
        foreach ([self::TRIAL_PREFIX, PrivateFile::CREATE_PREFIX, '.htaccess.', basename($this->layout->mysqlOptionFile()).'.'] as $prefix) {
            PrivateFile::removeStale($this->layout->dataDirectory(), $prefix);
        }

        if ($lang === null) {
            $this->locale = (string) select('Language / 言語', ['ja' => '日本語', 'en' => 'English'], $this->locale);
        }

        try {
            return file_exists($this->layout->envFile) ? $this->continueSetup() : $this->startSetup();
        } catch (StopSetup) {
            return self::FAILURE;
        }
    }

    private function startSetup(): int
    {
        $this->say('intro', ['base' => $this->layout->base]);
        if ($this->call('openpne:check', ['--only' => 'before', '--lang' => $this->locale]) !== self::SUCCESS) {
            $this->newLine();
            $this->say('before_failed');

            return self::FAILURE;
        }
        $this->dataDirectory();

        $values = $this->askSettings();
        $refused = EnvFile::create($this->layout->envFile, $this->laravel->basePath('.env.example'), $values);
        if ($refused !== null) {
            $this->say($refused === EnvFile::UNREADABLE ? 'env_unreadable' : 'env_mismatch', ['key' => $refused], 'error');

            return self::FAILURE;
        }
        $this->say('env_written', ['path' => $this->layout->envFile]);

        if (! $this->host->canSpawn()) {
            $this->say('run_again');

            return self::FAILURE;
        }

        // A new process reads the new .env; a value the environment already holds would win over it there.
        $environment = getenv();
        foreach (array_keys(EnvFile::read(File::get($this->layout->envFile))) as $key) {
            unset($environment[$key]);
        }
        $code = $this->host->spawn(
            [PHP_BINARY, $this->laravel->basePath('artisan'), 'openpne:setup', '--lang='.$this->locale],
            $environment,
            $this->laravel->basePath(),
            $this->lock->handle,
        );

        return $code === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function continueSetup(): int
    {
        $env = $this->readEnv();
        // The web reads .env alone, so a value this shell holds would set up another site than it serves.
        foreach (self::CONNECTION_KEYS as $key) {
            if ((string) Env::get($key) !== EnvFile::asEnv($env[$key] ?? null)) {
                $this->say('env_overridden', ['key' => $key, 'path' => $this->layout->envFile], 'error');

                return self::FAILURE;
            }
        }
        $this->dataDirectory();
        $connection = (string) config('database.default');
        $settings = (array) config("database.connections.{$connection}");
        $driver = (string) ($settings['driver'] ?? '');

        $unanswered = null;
        try {
            $server = $this->servers->behind($connection);
        } catch (Throwable $e) {
            [$server, $unanswered] = [null, $e];
        }
        if ($server?->supported() === false) {
            $this->say('db_mariadb', ['server' => $server->name(), 'path' => $this->layout->envFile, 'minimum' => DatabaseServer::MARIADB_MINIMUM], 'error');

            return self::FAILURE;
        }
        if ($server !== null && $server->driver() !== $driver) {
            $this->say('db_driver', ['server' => $server->name(), 'driver' => $server->driver(), 'path' => $this->layout->envFile], 'error');

            return self::FAILURE;
        }
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->mysqlOptionFile($settings);
        }
        if ($driver === 'sqlite') {
            $this->sqliteFile((string) ($settings['database'] ?? ''));
        }
        if ($unanswered === null) {
            try {
                DB::connection()->getPdo();
            } catch (Throwable $e) {
                $unanswered = $e;
            }
        }
        if ($unanswered !== null) {
            $this->say('db_failed', ['reason' => InstalledCheck::reason($unanswered)], 'error');

            return self::FAILURE;
        }

        $hasAdmin = Schema::hasTable('admin_users') && DB::table('admin_users')->exists();
        if (! filled(config('app.key'))) {
            if ($hasAdmin) {
                $this->say('key_missing', ['path' => $this->layout->envFile], 'error');

                return self::FAILURE;
            }
            $key = self::newKey();
            if (EnvFile::set($this->layout->envFile, 'APP_KEY', $key) !== null) {
                $this->say('key_refused', ['path' => $this->layout->envFile], 'error');

                return self::FAILURE;
            }
            config(['app.key' => $key]);
            $this->say('key_written');
        }

        // An unpacked new version run here would otherwise migrate a live site outside maintenance mode.
        if ($hasAdmin && $this->pendingMigrations() > 0) {
            $this->say('pending_migrations', [], 'error');

            return self::FAILURE;
        }
        $this->say('install');
        // Its success ends with "Next: openpne:admin:create", which this command does next itself.
        $install = new BufferedOutput;
        try {
            $installed = Artisan::call('openpne:install', [], $install);
        } catch (Throwable $e) {
            $installed = self::FAILURE;
            $install->writeln(OutputFormatter::escape($e->getMessage()));
        } finally {
            // A command run points Prompts at its own output, which here is the buffer.
            $this->configurePrompts($this->input);
        }
        if ($installed !== self::SUCCESS) {
            $this->output->write($install->fetch());
            $this->say('install_failed', [], 'error');
            $nobody = $this->nobodyYet();
            // Never to remove one: a database given may hold another application's tables.
            $guide = match (true) {
                $nobody === null => null,
                ! $nobody => 'install_keep',
                $driver === 'sqlite' => 'install_restart_sqlite',
                default => 'install_restart_mysql',
            };
            if ($guide !== null) {
                $this->say($guide, ['path' => (string) ($settings['database'] ?? ''), 'new' => $driver === 'sqlite' ? $this->unusedSqliteFile() : '']);
            }

            return self::FAILURE;
        }
        // Not before: openpne:install seeds in the site's locale.
        $this->laravel->setLocale($this->locale);
        if (! $hasAdmin) {
            $this->createAdmin();
        }

        $findings = $this->waitForWeb();
        $this->offerProxies($findings);
        $this->closeDirectories();
        $this->inviteFirstMember();
        $this->backup($driver, $settings);
        $this->cron();

        $this->newLine();
        $this->say('done');

        return self::SUCCESS;
    }

    /** @return array<string, string> */
    private function askSettings(): array
    {
        $values = ['APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'LOG_LEVEL' => 'warning', 'APP_KEY' => self::newKey()];

        $values['APP_URL'] = rtrim($this->until(
            fn (): string => text($this->text('url'), placeholder: 'https://sns.example.com', required: true, hint: $this->text('url_hint')),
            fn (string $value): ?string => InstalledCheck::usableAppUrl(rtrim($value, '/')) ? EnvValue::problem($value) : 'url_unusable',
        ), '/');
        $values['APP_NAME'] = $this->until(
            fn (): string => text($this->text('name'), required: true, hint: $this->text('name_hint')),
            EnvValue::problem(...),
        );
        $values['APP_TIMEZONE'] = $this->until(
            fn (): string => text($this->text('timezone'), default: $this->locale === 'ja' ? 'Asia/Tokyo' : 'UTC', required: true, hint: $this->text('timezone_hint')),
            fn (string $value): ?string => in_array($value, DateTimeZone::listIdentifiers(), true) ? null : 'timezone_unknown',
        );

        $values += $this->askDatabase();
        $values += $this->askMail();
        $values['QUEUE_CONNECTION'] = (string) select($this->text('queue'), [
            'deferred' => $this->text('queue_deferred'),
            'database' => $this->text('queue_database'),
        ], 'deferred');

        return $values;
    }

    /** @return array<string, string> */
    private function askDatabase(): array
    {
        $sqlite = $this->sqlite->lacks() === [];
        $mysql = extension_loaded('pdo_mysql') ? BackupTools::mysql() : null;
        $options = array_filter(['sqlite' => $sqlite ? $this->text('db_sqlite') : null, 'mysql' => $mysql !== null ? $this->text('db_mysql') : null]);
        if ($options === []) {
            $this->say('db_none', [], 'error');

            throw new StopSetup;
        }
        if ($sqlite && $mysql === null) {
            $this->say('db_only_sqlite');
        }
        if (! $sqlite) {
            $this->say('db_only_mysql', ['minimum' => DatabaseServer::MARIADB_MINIMUM]);
            if (! confirm($this->text('db_only_mysql_go_on'), true)) {
                $this->say('db_only_mysql_stopped');

                throw new StopSetup;
            }
        }

        while (true) {
            $choice = count($options) === 1 ? (string) array_key_first($options) : (string) select($this->text('db'), $options, 'sqlite');
            if ($choice === 'sqlite') {
                $path = $this->layout->dataDirectory().'/database.sqlite';
                // A site started over runs on a database-<n>.sqlite beside it.
                $startedOver = preg_grep('/\/database-\d+\.sqlite\z/', glob($this->layout->dataDirectory().'/database-*.sqlite') ?: []);
                foreach ([$path, ...$startedOver] as $file) {
                    if (is_file($file) && filesize($file) > 0 && $this->holdsSite('sqlite', ['database' => $file])) {
                        $this->say('db_holds_site_sqlite', ['path' => $file, 'base' => $this->layout->base], 'error');

                        throw new StopSetup;
                    }
                }

                return [
                    'DB_CONNECTION' => 'sqlite',
                    'DB_DATABASE' => $path,
                    'DB_JOURNAL_MODE' => 'WAL',
                    'DB_SYNCHRONOUS' => 'NORMAL',
                ];
            }

            $settings = $this->askMysql($sqlite);
            if ($settings !== null) {
                return $settings;
            }
        }
    }

    /** @return array<string, string>|null null where the server is one OpenPNE does not run on and SQLite is there to choose instead */
    private function askMysql(bool $sqlite): ?array
    {
        while (true) {
            $settings = [
                'DB_HOST' => $this->until(fn (): string => text($this->text('mysql_host'), required: true, hint: $this->text('mysql_hint')), EnvValue::problem(...)),
                'DB_PORT' => $this->until(fn (): string => text($this->text('mysql_port'), default: '3306', required: true), fn (string $v): ?string => ctype_digit($v) ? null : 'port_number'),
                'DB_DATABASE' => $this->until(fn (): string => text($this->text('mysql_database'), required: true), EnvValue::problem(...)),
                'DB_USERNAME' => $this->until(fn (): string => text($this->text('mysql_username'), required: true), EnvValue::problem(...)),
            ];
            $this->acceptShownInput(viaPrompts: true);
            $settings['DB_PASSWORD'] = $this->until(fn (): string => password($this->text('mysql_password')), EnvValue::problem(...));

            $server = $this->mysqlServer($settings);
            if (is_string($server)) {
                $this->say('mysql_refused', ['reason' => $server], 'error');
            } elseif (! $server->supported()) {
                $this->say($sqlite ? 'mysql_mariadb' : 'mysql_mariadb_no_sqlite', ['server' => $server->name(), 'minimum' => DatabaseServer::MARIADB_MINIMUM], 'error');
                if ($sqlite) {
                    return null;
                }
            } elseif (! $this->holdsSite($server->driver(), self::mysqlConnection($settings))) {
                return ['DB_CONNECTION' => $server->driver(), ...$settings];
            } else {
                $this->say('db_holds_site_mysql', ['database' => $settings['DB_DATABASE']], 'error');
            }
            if (! confirm($this->text('mysql_again'), true)) {
                throw new StopSetup;
            }
        }
    }

    /** @return array<string, string> */
    private function askMail(): array
    {
        $choice = (string) select($this->text('mail'), ['later' => $this->text('mail_later'), 'smtp' => $this->text('mail_smtp')], 'later');
        if ($choice === 'later') {
            $this->say('mail_later_note');

            return [];
        }

        $values = [
            'MAIL_MAILER' => 'smtp',
            'MAIL_HOST' => $this->until(fn (): string => text($this->text('smtp_host'), required: true), EnvValue::problem(...)),
            'MAIL_PORT' => $this->until(fn (): string => text($this->text('smtp_port'), default: '587', required: true), fn (string $v): ?string => ctype_digit($v) ? null : 'port_number'),
        ];
        $username = $this->until(fn (): string => text($this->text('smtp_username'), hint: $this->text('smtp_username_hint')), EnvValue::problem(...));
        if ($username !== '') {
            $this->acceptShownInput(viaPrompts: true);
            $values['MAIL_USERNAME'] = $username;
            $values['MAIL_PASSWORD'] = $this->until(fn (): string => password($this->text('smtp_password')), EnvValue::problem(...));
        }
        $values['MAIL_FROM_ADDRESS'] = $this->until(
            fn (): string => text($this->text('smtp_from'), required: true),
            fn (string $v): ?string => filter_var($v, FILTER_VALIDATE_EMAIL) === false ? 'email_invalid' : EnvValue::problem($v),
        );

        return $values;
    }

    /**
     * @param  array<string, string>  $settings
     * @return DatabaseServer|string the server, which the connection of its own driver reached if it is supported, or what the server said in refusing
     */
    private function mysqlServer(array $settings): DatabaseServer|string
    {
        return $this->onConnection('mysql', self::mysqlConnection($settings), function (Connection $db) use ($settings): DatabaseServer|string {
            try {
                $server = $this->servers->behind((string) $db->getName());
                if ($server === null || ! $server->supported()) {
                    return $server ?? 'not a MySQL or MariaDB server';
                }
                $this->onConnection($server->driver(), self::mysqlConnection($settings), fn (Connection $own) => $own->getPdo());

                return $server;
            } catch (Throwable $e) {
                $line = trim((string) strtok(trim($e->getMessage()), "\n"));

                return $settings['DB_PASSWORD'] === '' ? $line : str_replace($settings['DB_PASSWORD'], '…', $line);
            }
        });
    }

    /**
     * @param  array<string, string>  $settings
     * @return array<string, string>
     */
    private static function mysqlConnection(array $settings): array
    {
        return [
            'host' => $settings['DB_HOST'],
            'port' => $settings['DB_PORT'],
            'database' => $settings['DB_DATABASE'],
            'username' => $settings['DB_USERNAME'],
            'password' => $settings['DB_PASSWORD'],
        ];
    }

    /**
     * A new APP_KEY would leave what that site encrypted unreadable.
     *
     * @param  array<string, string>  $connection
     */
    private function holdsSite(string $template, array $connection): bool
    {
        return $this->onConnection($template, $connection, function (Connection $db): bool {
            try {
                return $db->getSchemaBuilder()->hasTable('admin_users') && $db->table('admin_users')->exists();
            } catch (Throwable) {
                return false;
            }
        });
    }

    /**
     * @template T
     *
     * @param  array<string, string>  $connection
     * @param  Closure(Connection): T  $use
     * @return T
     */
    private function onConnection(string $template, array $connection, Closure $use): mixed
    {
        // Named per template, since the manager would hand a nested call the other driver's cached connection.
        $name = "openpne_setup_{$template}";
        config(["database.connections.{$name}" => array_merge((array) config("database.connections.{$template}"), ['url' => null], $connection)]);

        try {
            return $use(DB::connection($name));
        } finally {
            DB::purge($name);
        }
    }

    /** @return array<string, string|null> */
    private function readEnv(): array
    {
        try {
            $env = EnvFile::read(File::get($this->layout->envFile));
        } catch (Throwable) {
            $env = [];
        }
        if (EnvFile::asEnv($env['APP_URL'] ?? null) === '' || EnvFile::asEnv($env['DB_CONNECTION'] ?? null) === '') {
            $this->say('env_incomplete', ['path' => $this->layout->envFile], 'error');

            throw new StopSetup;
        }

        return $env;
    }

    private static function newKey(): string
    {
        return 'base64:'.base64_encode(Encrypter::generateKey((string) config('app.cipher')));
    }

    /** openpne-data/ beside the release, closed to others and denied to the web on every run, before anything goes in it. */
    private function dataDirectory(): void
    {
        $directory = $this->layout->dataDirectory();
        if (! is_dir($directory)) {
            if (! @mkdir($directory, 0o700)) {
                $this->say('data_unwritable', ['path' => $directory], 'error');

                throw new StopSetup;
            }
            $this->say('data_made', ['path' => $directory]);
        }
        clearstatcache(true, $directory);
        if ((fileperms($directory) & 0o077) !== 0 && ! @chmod($directory, 0o700)) {
            $this->say('data_open', ['path' => $directory], 'error');

            throw new StopSetup;
        }

        $htaccess = $directory.'/.htaccess';
        $temp = null;
        try {
            if (is_file($htaccess) && SiteLayout::denies(File::get($htaccess))) {
                return;
            }
            $temp = PrivateFile::temp($directory, '.htaccess.', SiteLayout::DENY, 0o644);
            PrivateFile::rename($temp, $htaccess);
        } catch (Throwable) {
            $this->say('data_htaccess_failed', ['path' => $htaccess], 'error');

            throw new StopSetup;
        } finally {
            if ($temp !== null) {
                @unlink($temp);
            }
        }
    }

    /** @param array<string, mixed> $settings */
    private function mysqlOptionFile(array $settings): void
    {
        $file = $this->layout->mysqlOptionFile();
        if (file_exists($file)) {
            return;
        }
        $tool = BackupTools::mysql();
        if ($tool === null) {
            $this->say('backup_no_mysql_tool', [], 'error');

            throw new StopSetup;
        }
        MysqlOptionFile::write($file, MysqlOptionFile::contents($settings, $tool));
        $this->say('mysql_options_written', ['path' => $file]);
    }

    private function sqliteFile(string $path): void
    {
        if ($path === '' || $path === ':memory:' || file_exists($path)) {
            return;
        }
        if (! PrivateFile::create($path, '', 0o600)) {
            $this->say('sqlite_unmade', ['path' => $path], 'error');

            throw new StopSetup;
        }
    }

    private function unusedSqliteFile(): string
    {
        $n = 2;
        while (file_exists($this->layout->dataDirectory()."/database-{$n}.sqlite") || is_link($this->layout->dataDirectory()."/database-{$n}.sqlite")) {
            $n++;
        }

        return $this->layout->dataDirectory()."/database-{$n}.sqlite";
    }

    private function pendingMigrations(): int
    {
        $migrator = $this->laravel->make('migrator');
        if (! $migrator->repositoryExists()) {
            return 0;
        }
        $files = $migrator->getMigrationFiles([database_path('migrations'), ...$migrator->paths()]);

        return count(array_diff(array_keys($files), $migrator->getRepository()->getRan()));
    }

    /** Null where the database cannot say, which is never taken for nobody. */
    private function nobodyYet(): ?bool
    {
        try {
            foreach (['members', 'admin_users'] as $table) {
                if (Schema::hasTable($table) && DB::table($table)->exists()) {
                    return false;
                }
            }
        } catch (Throwable) {
            return null;
        }

        return true;
    }

    private function createAdmin(): void
    {
        $username = Str::trim($this->until(
            fn (): string => text($this->text('admin'), required: true, hint: $this->text('admin_hint')),
            fn (string $value): ?string => Validator::make(['username' => Str::trim($value)], [
                'username' => ['required', 'string', 'max:64', Rule::unique('admin_users', 'username')],
            ])->fails() ? 'admin_invalid' : null,
        ));
        $this->acceptShownInput(viaPrompts: false);

        for ($attempt = 1; $attempt <= self::ADMIN_ATTEMPTS; $attempt++) {
            $this->say('admin_password');
            if ($this->call('openpne:admin:create', ['username' => $username]) === self::SUCCESS) {
                return;
            }
        }
        $this->say('admin_gave_up', [], 'error');

        throw new StopSetup;
    }

    /** @return list<Finding> what the passing check found, its warnings among them */
    private function waitForWeb(): array
    {
        $guided = false;
        while (true) {
            $this->newLine();
            $this->say('web_checking', ['url' => (string) config('app.url')]);
            $findings = $this->runWebCheck($this->layout);
            $runner = array_filter($findings, fn (Finding $f): bool => $f->id === 'web.runner' && $f->verdict === Verdict::Fail);
            $open = array_filter($findings, fn (Finding $f): bool => in_array($f->verdict, [Verdict::Fail, Verdict::Unknown], true));

            if ($runner !== []) {
                $this->findings($runner);
                $this->say('web_runner_stop', [], 'error');

                throw new StopSetup;
            }
            if ($open === []) {
                $this->findings(array_filter($findings, fn (Finding $f): bool => $f->verdict === Verdict::Warn));
                $this->say('web_passed');

                return $findings;
            }
            if (! $guided) {
                $this->say('web_guide', ['public' => $this->layout->public, 'url' => (string) config('app.url')]);
                $guided = true;
            }
            $this->findings($open);
            if (! confirm($this->text('web_again'), true, $this->text('web_again_yes'), $this->text('web_again_no'))) {
                $this->say('web_later');

                throw new StopSetup;
            }
        }
    }

    /** @param list<Finding> $findings */
    private function offerProxies(array $findings): void
    {
        $warned = array_filter($findings, fn (Finding $f): bool => $f->message === 'web_proxy_untrusted');
        $current = trim(EnvFile::asEnv(EnvFile::read(File::get($this->layout->envFile))['TRUSTED_PROXIES'] ?? null));
        if ($warned === [] || $current !== '') {
            return;
        }

        $this->say('proxy_intro');
        if (select($this->text('proxy'), ['later' => $this->text('proxy_later'), 'enter' => $this->text('proxy_enter')], 'later') !== 'enter') {
            $this->say('proxy_skipped');

            return;
        }
        $value = ProxyList::normalize($this->until(
            fn (): string => text($this->text('proxy_value'), required: true, hint: $this->text('proxy_value_hint')),
            fn (string $v): ?string => EnvValue::problem($v) ?? ProxyList::problem($v),
        ));
        if (! confirm($this->text('proxy_confirm', ['value' => $value]), true)) {
            $this->say('proxy_skipped');

            return;
        }
        $refused = EnvFile::set($this->layout->envFile, 'TRUSTED_PROXIES', $value);
        if ($refused !== null) {
            $this->say('proxy_refused', ['path' => $this->layout->envFile], 'error');

            throw new StopSetup;
        }
        $this->say('proxy_written');
    }

    /** Only once the web is known to run as this account, which otherwise could no longer read its logs and cache. */
    private function closeDirectories(): void
    {
        foreach ([$this->layout->storage, $this->layout->bootstrapCache] as $directory) {
            $mode = fileperms($directory) & 0o777;
            if (($mode & 0o007) !== 0) {
                chmod($directory, $mode & ~0o007);
            }
        }
        $this->say('closed');
    }

    private function inviteFirstMember(): void
    {
        while (confirm($this->text('invite'), true)) {
            $email = text($this->text('invite_email'), required: true);
            $this->say('invite_link');
            if ($this->call('openpne:member:invite', ['email' => $email]) === self::SUCCESS) {
                return;
            }
        }
    }

    /** @param array<string, mixed> $settings */
    private function backup(string $driver, array $settings): void
    {
        $this->newLine();
        $sqlite = $driver === 'sqlite';
        $tool = $sqlite ? ($this->sqlite->hasExtension() ? PHP_BINARY : null) : BackupTools::mysql();
        $data = $this->layout->dataDirectory();
        $database = (string) ($settings['database'] ?? '');
        if ($tool === null) {
            $this->say($sqlite ? 'backup_no_sqlite_extension' : 'backup_no_mysql_tool', [], 'error');

            return;
        }
        // Called mysqldump, MariaDB's tool warns at every run, cron's included.
        if (! $sqlite && MysqlOptionFile::isMariaDb($tool)) {
            $tool = realpath($tool) ?: $tool;
        }
        $option = $this->layout->mysqlOptionFile();
        $unsafe = array_filter($sqlite ? [$tool, $this->layout->base, $data] : [$tool, $database, $data, $option], fn (string $part): bool => ! BackupLine::safe($part));
        if ($unsafe !== []) {
            $this->say('backup_unsafe_path', ['path' => reset($unsafe)]);

            return;
        }
        $line = $sqlite ? BackupLine::sqlite($tool, $this->layout->base, $data) : BackupLine::mysql($tool, $option, $database, $data);

        $this->say('backup_line', ['path' => $data.'/'.($sqlite ? BackupLine::SQLITE_FILE : BackupLine::MYSQL_FILE)]);
        $this->line(OutputFormatter::escape($line));
        if (! $sqlite && ! $this->host->canSpawn()) {
            $this->say('backup_untried');

            return;
        }
        try {
            $failure = $sqlite ? $this->trySqliteBackup($database, $data) : $this->tryMysqlBackup($tool, $option, $database, $data);
        } catch (ProcessTimedOutException) {
            $failure = $this->text('backup_timeout', ['seconds' => self::TRIAL_SECONDS]);
        } catch (RuntimeException $e) {
            $failure = $e->getMessage();
        }
        if ($failure === null) {
            $this->say('backup_tried');
        } else {
            $this->say('backup_failed', ['reason' => $failure], 'error');
            if (! $sqlite && MysqlOptionFile::isMariaDb($tool) && str_contains($failure, '1045')) {
                $this->say('backup_mariadb_auth');
            }
        }
    }

    /** The copy the printed line takes, without the line's lock and rename, which leave nothing to try. */
    private function trySqliteBackup(string $database, string $data): ?string
    {
        $copy = PrivateFile::temp($data, self::TRIAL_PREFIX, '', 0o600);

        try {
            SqliteBackup::copy($database, $copy);
            $read = new SQLite3($copy, SQLITE3_OPEN_READONLY);
            try {
                $read->enableExceptions(true);
                $migrations = (int) $read->querySingle('SELECT count(*) FROM migrations');
            } finally {
                $read->close();
            }

            return $migrations > 0 ? null : $this->text('backup_no_tables');
        } catch (Exception $e) {
            return rtrim(self::firstLine($e->getMessage()), '.');
        } finally {
            foreach (['', '-wal', '-shm', '-journal'] as $suffix) {
                @unlink($copy.$suffix);
            }
        }
    }

    private function tryMysqlBackup(string $tool, string $option, string $database, string $data): ?string
    {
        $copy = PrivateFile::temp($data, self::TRIAL_PREFIX, '', 0o600);

        try {
            // The option file's own path must come first: the dump tools read it nowhere else.
            $dump = Process::timeout(self::TRIAL_SECONDS)->run([$tool, '--defaults-extra-file='.$option, '--single-transaction', '--no-tablespaces', '--result-file='.$copy, $database]);
            if (! $dump->successful()) {
                return InstalledCheck::firstLine($dump->errorOutput());
            }

            return PrivateFile::holds($copy, 'CREATE TABLE') ? null : $this->text('backup_no_tables');
        } finally {
            @unlink($copy);
        }
    }

    private function cron(): void
    {
        if (! BackupLine::safe(PHP_BINARY, $this->layout->base)) {
            $this->say('cron_unsafe_path');

            return;
        }
        $this->newLine();
        $this->say('cron');
        $this->line(OutputFormatter::escape(BackupLine::cron(PHP_BINARY, $this->layout->base)));
    }

    /** Asks once, before the first password this host would echo, whether to go on. */
    private function acceptShownInput(bool $viaPrompts): void
    {
        $shown = $viaPrompts ? ! $this->host->canSpawn() && ! $this->host->hidesTypedInput() : ! $this->host->hidesTypedInput();
        if (! $shown || $this->shownInputAccepted === true) {
            return;
        }
        $this->say('input_shown', [], 'warn');
        $this->shownInputAccepted = confirm($this->text('input_shown_go_on'), false);
        if (! $this->shownInputAccepted) {
            throw new StopSetup;
        }
    }

    /**
     * Asks until $problem, given the answer, names no key under console.setup.
     *
     * @param  Closure(): mixed  $ask
     * @param  Closure(string): ?string  $problem
     */
    private function until(Closure $ask, Closure $problem): string
    {
        while (true) {
            $value = (string) $ask();
            $key = $problem($value);
            if ($key === null) {
                return $value;
            }
            $this->say($key, [], 'error');
        }
    }

    /** @param array<Finding> $findings */
    private function findings(array $findings): void
    {
        foreach ($findings as $finding) {
            $this->line('  '.CheckCommand::render($finding, $this->locale));
        }
    }

    private static function firstLine(string $text): string
    {
        return mb_strimwidth(trim((string) strtok(trim($text), "\n")), 0, 300, '…');
    }

    /** @param array<string, string|int> $replace */
    private function text(string $key, array $replace = []): string
    {
        return CheckCommand::translate('setup.'.$key, $replace, $this->locale);
    }

    /** @param array<string, string|int> $replace */
    private function say(string $key, array $replace = [], string $style = 'line'): void
    {
        $this->{$style}(OutputFormatter::escape($this->text($key, $replace)));
    }
}
