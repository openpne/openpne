<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsWebCheck;
use App\Setup\BackupLine;
use App\Setup\EnvFile;
use App\Setup\EnvValue;
use App\Setup\MysqlOptionFile;
use App\Setup\ProxyList;
use App\Setup\SetupHost;
use App\Setup\StopSetup;
use App\SiteCheck\BackupTools;
use App\SiteCheck\Finding;
use App\SiteCheck\InstalledCheck;
use App\SiteCheck\SiteLayout;
use App\SiteCheck\Verdict;
use Closure;
use DateTimeZone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Prompts\Prompt;
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

    private string $locale = 'en';

    private SetupHost $host;

    private SiteLayout $layout;

    private ?bool $shownInputAccepted = null;

    public function handle(SetupHost $host): int
    {
        $this->host = $host;
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
        if ($lang === null) {
            $this->locale = (string) select('Language / 言語', ['ja' => '日本語', 'en' => 'English'], $this->locale);
        }

        $this->layout = $this->laravel->bound(SiteLayout::class) ? $this->laravel->make(SiteLayout::class) : SiteLayout::of($this->laravel);

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
        );

        return $code === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function continueSetup(): int
    {
        $this->dataDirectory();
        $connection = (string) config('database.default');
        $settings = (array) config("database.connections.{$connection}");
        $driver = (string) ($settings['driver'] ?? '');

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->mysqlOptionFile($settings);
        }
        if ($driver === 'sqlite') {
            $this->sqliteFile((string) ($settings['database'] ?? ''));
        }
        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            $this->say('db_failed', ['reason' => InstalledCheck::reason($e)], 'error');

            return self::FAILURE;
        }

        if (! filled(config('app.key')) && $this->call('key:generate', ['--force' => true]) !== self::SUCCESS) {
            return self::FAILURE;
        }

        $hasAdmin = Schema::hasTable('admin_users') && DB::table('admin_users')->exists();
        // An unpacked new version run here would otherwise migrate a live site outside maintenance mode.
        if ($hasAdmin && $this->pendingMigrations() > 0) {
            $this->say('pending_migrations', [], 'error');

            return self::FAILURE;
        }
        $this->say('install');
        // Its success ends with "Next: openpne:admin:create", which this command does next itself.
        $install = new BufferedOutput;
        $installed = Artisan::call('openpne:install', [], $install);
        // A command run points Prompts at its own output, which here is the buffer.
        $this->configurePrompts($this->input);
        if ($installed !== self::SUCCESS) {
            $this->output->write($install->fetch());
            $this->say('install_failed', [], 'error');

            return self::FAILURE;
        }
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
        $values = ['APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'LOG_LEVEL' => 'warning'];

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
        $sqlite = extension_loaded('pdo_sqlite') ? BackupTools::sqlite() : null;
        if ($sqlite !== null && ($version = $this->sqliteVersion($sqlite)) !== null && version_compare($version, BackupLine::SQLITE_MINIMUM, '<')) {
            $this->say('sqlite_old', ['path' => $sqlite, 'version' => $version]);
            $sqlite = null;
        }
        $mysql = extension_loaded('pdo_mysql') ? BackupTools::mysql() : null;
        $options = array_filter(['sqlite' => $sqlite !== null ? $this->text('db_sqlite') : null, 'mysql' => $mysql !== null ? $this->text('db_mysql') : null]);
        if ($options === []) {
            $this->say('db_none', [], 'error');

            throw new StopSetup;
        }
        if (count($options) === 1) {
            $this->say($sqlite !== null ? 'db_only_sqlite' : 'db_only_mysql');
            $choice = (string) array_key_first($options);
        } else {
            $choice = (string) select($this->text('db'), $options, 'sqlite');
        }

        if ($choice === 'sqlite') {
            return [
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => $this->layout->dataDirectory().'/database.sqlite',
                'DB_JOURNAL_MODE' => 'WAL',
                'DB_SYNCHRONOUS' => 'NORMAL',
            ];
        }

        while (true) {
            $settings = [
                'DB_HOST' => $this->until(fn (): string => text($this->text('mysql_host'), required: true, hint: $this->text('mysql_hint')), EnvValue::problem(...)),
                'DB_PORT' => $this->until(fn (): string => text($this->text('mysql_port'), default: '3306', required: true), fn (string $v): ?string => ctype_digit($v) ? null : 'port_number'),
                'DB_DATABASE' => $this->until(fn (): string => text($this->text('mysql_database'), required: true), EnvValue::problem(...)),
                'DB_USERNAME' => $this->until(fn (): string => text($this->text('mysql_username'), required: true), EnvValue::problem(...)),
            ];
            $this->acceptShownInput(viaPrompts: true);
            $settings['DB_PASSWORD'] = $this->until(fn (): string => password($this->text('mysql_password')), EnvValue::problem(...));

            $refusal = $this->mysqlRefusal($settings);
            if ($refusal === null) {
                return ['DB_CONNECTION' => 'mysql', ...$settings];
            }
            $this->say('mysql_refused', ['reason' => $refusal], 'error');
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

    /** @param array<string, string> $settings */
    private function mysqlRefusal(array $settings): ?string
    {
        $name = 'openpne_setup';
        config(["database.connections.{$name}" => array_merge((array) config('database.connections.mysql'), [
            'url' => null,
            'host' => $settings['DB_HOST'],
            'port' => $settings['DB_PORT'],
            'database' => $settings['DB_DATABASE'],
            'username' => $settings['DB_USERNAME'],
            'password' => $settings['DB_PASSWORD'],
        ])]);

        try {
            DB::connection($name)->getPdo();

            return null;
        } catch (Throwable $e) {
            $line = trim((string) strtok(trim($e->getMessage()), "\n"));

            return $settings['DB_PASSWORD'] === '' ? $line : str_replace($settings['DB_PASSWORD'], '…', $line);
        } finally {
            DB::purge($name);
        }
    }

    /** openpne-data/ beside the release, made before the SQLite database goes in it. */
    private function dataDirectory(): void
    {
        $directory = $this->layout->dataDirectory();
        if (! is_dir($directory)) {
            if (! @mkdir($directory, 0o700)) {
                $this->say('data_unwritable', ['path' => $directory], 'error');

                throw new StopSetup;
            }
            chmod($directory, 0o700);
            $this->say('data_made', ['path' => $directory]);
        }
        $htaccess = $directory.'/.htaccess';
        if (! file_exists($htaccess)) {
            $top = $this->layout->base.'/.htaccess';
            if (! is_file($top) || ! File::copy($top, $htaccess)) {
                $this->say('data_htaccess_failed', ['path' => $htaccess, 'top' => $top], 'error');

                throw new StopSetup;
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
        if (! @touch($path)) {
            $this->say('data_unwritable', ['path' => dirname($path)], 'error');

            throw new StopSetup;
        }
        chmod($path, 0o600);
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
        $current = trim((string) (EnvFile::read(File::get($this->layout->envFile))['TRUSTED_PROXIES'] ?? ''));
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

    /** docs/installing.md, step 2: logs, keys and cached settings are the site user's alone, once the web is known to run as that user. */
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
        $tool = BackupTools::forDriver($driver);
        $data = $this->layout->dataDirectory();
        $database = (string) ($settings['database'] ?? '');
        if ($tool === null) {
            $this->say($driver === 'sqlite' ? 'backup_no_sqlite_tool' : 'backup_no_mysql_tool', [], 'error');

            return;
        }
        $option = $this->layout->mysqlOptionFile();
        if (! BackupLine::safe($tool, $database, $data, ...($driver === 'sqlite' ? [] : [$option]))) {
            $this->say('backup_unsafe_path', ['path' => $data]);

            return;
        }
        if ($driver === 'sqlite') {
            $version = $this->sqliteVersion($tool);
            if ($version !== null && version_compare($version, BackupLine::SQLITE_MINIMUM, '<')) {
                $this->say('backup_sqlite_old', ['path' => $tool, 'version' => $version], 'error');

                return;
            }
            $line = BackupLine::sqlite($tool, $database, $data);
        } else {
            $line = BackupLine::mysql($tool, $option, $database, $data);
        }

        $this->say('backup_line', ['path' => $data]);
        $this->line(OutputFormatter::escape($line));
        if (! $this->host->canSpawn()) {
            $this->say('backup_untried');

            return;
        }
        $failure = $driver === 'sqlite' ? $this->trySqliteBackup($tool, $database, $data) : $this->tryMysqlBackup($tool, $option, $database);
        if ($failure === null) {
            $this->say('backup_tried');
        } else {
            $this->say('backup_failed', ['reason' => $failure], 'error');
            if ($driver !== 'sqlite' && MysqlOptionFile::isMariaDb($tool) && str_contains($failure, '1045')) {
                $this->say('backup_mariadb_auth');
            }
        }
    }

    /** The copy is judged by reading a table from it: a `.backup` cut short leaves one that passes integrity_check with none. */
    private function trySqliteBackup(string $tool, string $database, string $data): ?string
    {
        $copy = $data.'/openpne-setup-'.bin2hex(random_bytes(8)).'.sqlite';

        try {
            $write = Process::timeout(300)->run([$tool, '-readonly', $database, "VACUUM INTO '{$copy}'"]);
            if (! $write->successful()) {
                return self::firstLine($write->errorOutput());
            }
            $read = Process::timeout(60)->run([$tool, '-readonly', $copy, 'SELECT count(*) FROM migrations']);

            return $read->successful() && (int) trim($read->output()) > 0 ? null : self::firstLine($read->errorOutput().$read->output());
        } finally {
            @unlink($copy);
        }
    }

    private function tryMysqlBackup(string $tool, string $option, string $database): ?string
    {
        // The option file's own path must come first: the dump tools read it nowhere else.
        $dump = Process::timeout(300)->run([$tool, '--defaults-extra-file='.$option, '--single-transaction', '--no-tablespaces', $database]);

        return $dump->successful() && str_contains($dump->output(), 'CREATE TABLE') ? null : InstalledCheck::firstLine($dump->errorOutput());
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

    private function sqliteVersion(string $tool): ?string
    {
        if (! $this->host->canSpawn()) {
            return null;
        }
        $result = Process::timeout(30)->run([$tool, '-version']);

        return $result->successful() && preg_match('/\A(\d+\.\d+\.\d+)/', trim($result->output()), $match) === 1 ? $match[1] : null;
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
