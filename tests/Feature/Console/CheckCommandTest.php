<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\SiteCheck\BackupTools;
use App\SiteCheck\BeforeInstallCheck;
use App\SiteCheck\InstalledCheck;
use App\SiteCheck\PhpCommand;
use App\SiteCheck\SiteLayout;
use App\SiteCheck\SqliteSupport;
use App\SiteCheck\WebCheck;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\LockableFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\Concerns\FakesDatabaseServer;
use Tests\TestCase;

class CheckCommandTest extends TestCase
{
    use FakesDatabaseServer;
    use RefreshDatabase;

    private const URL = 'https://sns.example.com';

    private const PARENT_URL = 'https://account.example.net/holder';

    private string $root;

    private SiteLayout $layout;

    private string|false $path;

    private string $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/openpne-check-test-'.bin2hex(random_bytes(4));
        $base = $this->root.'/site';
        foreach (['public', 'bootstrap/cache', 'storage/app/private', 'storage/app/public', 'storage/framework/cache/data',
            'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'tools'] as $directory) {
            mkdir($base.'/'.$directory, 0o755, true);
        }
        $this->layout = new SiteLayout($base, $base.'/public', $base.'/storage', $base.'/bootstrap/cache', $base.'/.env');
        $this->app->instance(SiteLayout::class, $this->layout);

        $this->connection = (string) config('database.default');
        $this->path = getenv('PATH');
        $this->tool('mysqldump');
        symlink(PHP_BINARY, $base.'/tools/php');
        putenv('PATH='.$base.'/tools');

        config([
            'app.env' => 'production',
            'app.debug' => false,
            'app.url' => self::URL,
            'mail.default' => 'smtp',
            'trustedproxy.proxies' => [],
        ]);
    }

    protected function tearDown(): void
    {
        putenv('PATH='.$this->path);
        (new Filesystem)->deleteDirectory($this->root);
        // RefreshDatabase rolls back the default connection, which the MySQL tests point elsewhere.
        config(['database.default' => $this->connection]);

        parent::tearDown();
    }

    // Before installing

    public function test_before_runs_without_env_and_passes_on_a_host_that_has_what_a_site_needs(): void
    {
        $this->artisan('openpne:check', ['--lang' => 'en'])
            ->expectsOutputToContain('PHP on the command line: '.PHP_VERSION)
            ->expectsOutputToContain('There is no '.$this->layout->envFile.' yet')
            ->doesntExpectOutputToContain('[FAIL]')
            ->assertSuccessful();
    }

    public function test_the_extension_list_is_the_one_the_install_guide_names(): void
    {
        $guide = (string) file_get_contents(base_path('docs/installing.md'));
        $this->assertSame(1, preg_match('/with the extensions (.+?), and\s+`pdo_sqlite`/s', $guide, $match));
        preg_match_all('/`([a-z_]+)`/', $match[1], $names);

        $this->assertSame($names[1], BeforeInstallCheck::EXTENSIONS);
    }

    public function test_a_directory_that_cannot_be_written_fails_and_the_parent_counts_only_before_install(): void
    {
        rmdir($this->layout->storage.'/logs');
        chmod($this->layout->parent(), 0o555);

        try {
            $this->artisan('openpne:check', ['--only' => 'before', '--lang' => 'en'])
                ->expectsOutputToContain('These cannot be written to: storage/logs, '.$this->layout->parent().'.')
                ->assertFailed();

            $this->installEnv();
            $this->artisan('openpne:check', ['--only' => 'before', '--lang' => 'en'])
                ->expectsOutputToContain('These cannot be written to: storage/logs.')
                ->assertFailed();
        } finally {
            chmod($this->layout->parent(), 0o755);
        }
    }

    public function test_backup_tools_are_found_on_path_without_being_run(): void
    {
        $tools = $this->layout->base.'/tools';
        unlink($tools.'/mysqldump');
        $this->tool('mariadb-dump');

        $this->assertSame($tools.'/mariadb-dump', BackupTools::mysql());
        $this->tool('mysqldump');
        $this->assertSame($tools.'/mysqldump', BackupTools::mysql());
        $this->assertFileDoesNotExist($tools.'/ran');

        unlink($tools.'/mysqldump');
        unlink($tools.'/mariadb-dump');
        // Not executable is not found.
        file_put_contents($tools.'/mysqldump', '');
        $this->assertNull(BackupTools::mysql());
    }

    public function test_a_host_with_no_backup_tool_fails_before_install(): void
    {
        $this->sqliteLibrary('3.26.0', extension: false);
        unlink($this->layout->base.'/tools/mysqldump');

        $this->artisan('openpne:check', ['--only' => 'before', '--lang' => 'en'])
            ->expectsOutputToContain('No database can be chosen for a new site here: SQLite needs pdo_sqlite on SQLite 3.26.0 or later, and PHP\'s sqlite3 extension; MySQL and MariaDB need')
            ->assertFailed();
    }

    /** @return array<string, array{?string, bool, string, list<string>}> */
    public static function sqliteVersions(): array
    {
        return [
            'the library setup takes, and the extension' => ['3.26.0', true, 'Databases: SQLite (backed up through PHP\'s sqlite3 extension), MySQL or MariaDB', []],
            'the library too old' => ['3.25.3', true, 'Databases: MySQL or MariaDB only', [
                'SQLite cannot be used here: pdo_sqlite on the command line runs on SQLite 3.25.3, and OpenPNE needs 3.26.0 or later.',
            ]],
            'no sqlite3 extension' => ['3.26.0', false, 'Databases: MySQL or MariaDB only', [
                'SQLite cannot be used here: PHP on the command line has no sqlite3 extension, which the backup uses.',
            ]],
            'no pdo_sqlite beside the extension' => [null, true, 'Databases: MySQL or MariaDB only', [
                'SQLite cannot be used here: PHP on the command line has no pdo_sqlite.',
            ]],
        ];
    }

    /** @param list<string> $lacks */
    #[DataProvider('sqliteVersions')]
    public function test_sqlite_passes_only_at_the_versions_setup_takes_and_each_thing_it_lacks_is_named(?string $library, bool $extension, string $databases, array $lacks): void
    {
        $this->sqliteLibrary($library, $extension);

        $check = $this->artisan('openpne:check', ['--only' => 'before', '--lang' => 'en'])->expectsOutputToContain($databases);
        foreach ($lacks as $line) {
            $check->expectsOutputToContain($line);
        }
        if ($lacks === []) {
            $check->doesntExpectOutputToContain('SQLite cannot be used here');
        }
        $check->expectsOutputToContain('Warnings: '.count($lacks).'.')->assertSuccessful();
    }

    public function test_a_host_without_any_part_of_sqlite_says_what_it_needs_without_a_warning(): void
    {
        $this->sqliteLibrary(null, extension: false);

        $this->artisan('openpne:check', ['--only' => 'before', '--lang' => 'en'])
            ->expectsOutputToContain('Databases: MySQL or MariaDB only (backed up with '.$this->layout->base.'/tools/mysqldump), which needs a MySQL server 8.0.17 or later, or a MariaDB server 10.11 or later. SQLite needs pdo_sqlite on SQLite 3.26.0 or later, and PHP\'s sqlite3 extension.')
            ->doesntExpectOutputToContain('SQLite cannot be used here')
            ->expectsOutputToContain('Warnings: 0.')
            ->assertSuccessful();
    }

    public function test_a_sqlite_version_that_cannot_be_read_is_not_taken_for_too_old(): void
    {
        $this->sqliteLibrary(null, pdo: true);

        $this->artisan('openpne:check', ['--only' => 'before', '--lang' => 'en'])
            ->expectsOutputToContain('Databases: SQLite (backed up through PHP\'s sqlite3 extension), MySQL or MariaDB')
            ->doesntExpectOutputToContain('SQLite cannot be used here')
            ->assertSuccessful();
        if ($this->sqlite()) {
            $this->installEnv();
            Artisan::call('openpne:install');
            $this->artisan('openpne:check', ['--only' => 'installed', '--lang' => 'en'])
                ->doesntExpectOutputToContain('and OpenPNE needs')
                ->run();
        }
    }

    public function test_the_sqlite_pdo_sqlite_runs_on_is_read(): void
    {
        $this->assertMatchesRegularExpression('/\A3\.\d+\.\d+\z/', (string) $this->app->make(SqliteSupport::class)->libraryVersion());
    }

    public function test_an_installed_site_is_not_told_what_sqlite_lacks(): void
    {
        $this->installEnv();
        $this->sqliteLibrary('3.25.3', extension: false);

        $this->artisan('openpne:check', ['--only' => 'before', '--lang' => 'en'])
            ->expectsOutputToContain('Databases: MySQL or MariaDB only')
            ->doesntExpectOutputToContain('SQLite cannot be used here')
            ->assertSuccessful();
    }

    // The installed site

    public function test_an_installed_site_set_up_as_the_guide_says_passes(): void
    {
        $this->installEnv();
        Artisan::call('openpne:install');

        $this->artisan('openpne:check', ['--only' => 'installed', '--lang' => 'en'])
            ->expectsOutputToContain('[ OK ] Every migration has run.')
            ->expectsOutputToContain('[ OK ] The initial data is there.')
            ->expectsOutputToContain('[ OK ] The database is backed up with '.($this->sqlite() ? 'php artisan openpne:backup-sqlite.' : $this->layout->base.'/tools/'))
            ->doesntExpectOutputToContain('[FAIL]')
            ->assertSuccessful();
    }

    public function test_an_installed_sqlite_site_fails_without_the_extension_and_below_the_library_setup_takes(): void
    {
        if (! $this->sqlite()) {
            $this->markTestSkipped('The site is on MySQL.');
        }
        $this->installEnv();
        Artisan::call('openpne:install');
        $this->sqliteLibrary('3.25.3', extension: false);

        $this->artisan('openpne:check', ['--only' => 'installed', '--lang' => 'en'])
            ->expectsOutputToContain('PHP on the command line has no sqlite3 extension, and the backup of an SQLite database (php artisan openpne:backup-sqlite, docs/updating.md) needs it.')
            ->expectsOutputToContain('PHP on the command line runs on SQLite 3.25.3, and OpenPNE needs 3.26.0 or later.')
            ->assertFailed();
    }

    public function test_an_installed_sqlite_site_passes_at_the_library_setup_takes(): void
    {
        if (! $this->sqlite()) {
            $this->markTestSkipped('The site is on MySQL.');
        }
        $this->installEnv();
        Artisan::call('openpne:install');
        $this->sqliteLibrary('3.26.0');

        $this->artisan('openpne:check', ['--only' => 'installed', '--lang' => 'en'])
            ->expectsOutputToContain('[ OK ] The database is backed up with php artisan openpne:backup-sqlite.')
            ->doesntExpectOutputToContain('and OpenPNE needs')
            ->doesntExpectOutputToContain('[FAIL]')
            ->assertSuccessful();
    }

    public function test_each_setting_a_production_site_needs_fails_when_it_is_off(): void
    {
        $this->installEnv(0o644);
        unlink($this->layout->base.'/.htaccess');
        $this->sqliteLibrary('3.40.0', extension: false);
        unlink($this->layout->base.'/tools/mysqldump');
        config(['app.env' => 'local', 'app.debug' => true, 'app.key' => '', 'app.url' => 'http://sns.example.com/sub']);

        $this->artisan('openpne:check', ['--only' => 'installed', '--lang' => 'en'])
            ->expectsOutputToContain('.env is mode 644, readable by others: chmod 600 '.$this->layout->envFile)
            ->expectsOutputToContain('APP_ENV is local')
            ->expectsOutputToContain('APP_DEBUG is on')
            ->expectsOutputToContain('APP_KEY is empty')
            ->expectsOutputToContain('APP_URL is http://sns.example.com/sub:')
            ->expectsOutputToContain($this->sqlite() ? 'PHP on the command line has no sqlite3 extension, and the backup of an SQLite database' : 'on PATH, and the backup of')
            ->expectsOutputToContain('The initial data is missing: php artisan openpne:install')
            ->expectsOutputToContain($this->layout->base.'/.htaccess is missing')
            ->assertFailed();
    }

    public function test_the_php_the_lines_name_is_shown_and_said_where_path_s_php_is_another(): void
    {
        $binary = $this->root.'/pkg/php-8.4.1/bin/php';
        mkdir(dirname($binary), 0o755, true);
        file_put_contents($binary, "#!/bin/sh\n");
        chmod($binary, 0o755);
        $php = $this->layout->base.'/tools/php8.4';
        symlink($binary, $php);
        unlink($this->layout->base.'/tools/php');
        $this->tool('php');
        $this->app->instance(PhpCommand::class, new PhpCommand($binary, '8.4.1'));

        $this->artisan('openpne:check', ['--only' => 'before', '--lang' => 'en'])
            ->expectsOutputToContain('PHP on the command line: '.PHP_VERSION.' ('.$php.').');

        $this->installEnv();
        config(['app.key' => '']);
        $this->artisan('openpne:check', ['--only' => 'installed', '--lang' => 'en'])
            ->expectsOutputToContain('APP_KEY is empty: '.$php.' artisan key:generate')
            ->expectsOutputToContain('The initial data is missing: '.$php.' artisan openpne:install')
            ->expectsOutputToContain($this->sqlite() ? 'The database is backed up with '.$php.' artisan openpne:backup-sqlite.' : '[ OK ] The database is backed up with')
            ->assertFailed();
        $this->assertFileDoesNotExist($this->layout->base.'/tools/ran');
    }

    public function test_mariadb_s_mysqldump_is_named_as_setup_names_it(): void
    {
        $tools = $this->layout->base.'/tools';
        $dump = $this->root.'/pkg/mariadb-11.4.2/bin/mariadb-dump';
        mkdir(dirname($dump), 0o755, true);
        file_put_contents($dump, "#!/bin/sh\n");
        chmod($dump, 0o755);
        unlink($tools.'/mysqldump');
        symlink($dump, $tools.'/mysqldump');
        symlink($dump, $tools.'/mariadb-dump');
        $this->sqliteLibrary(null, extension: false);

        $this->artisan('openpne:check', ['--only' => 'before', '--lang' => 'en'])
            ->expectsOutputToContain('Databases: MySQL or MariaDB only (backed up with '.$tools.'/mariadb-dump)')
            ->assertSuccessful();
    }

    public function test_pending_migrations_are_counted(): void
    {
        $this->installEnv();
        Artisan::call('openpne:install');
        $migrations = $this->root.'/migrations';
        mkdir($migrations);
        file_put_contents($migrations.'/2999_01_01_000000_later.php', '<?php return new class extends Illuminate\Database\Migrations\Migration {};');
        $this->app->make('migrator')->path($migrations);

        $this->artisan('openpne:check', ['--only' => 'installed', '--lang' => 'en'])
            ->expectsOutputToContain('Migrations not yet run: 1.')
            ->assertFailed();
    }

    public function test_the_data_directory_is_judged_only_where_it_exists(): void
    {
        $this->installEnv();
        Artisan::call('openpne:install');
        $this->artisan('openpne:check', ['--only' => 'installed', '--lang' => 'en'])
            ->doesntExpectOutputToContain('openpne-data')
            ->assertSuccessful();

        mkdir($this->layout->dataDirectory(), 0o755);
        $this->artisan('openpne:check', ['--only' => 'installed', '--lang' => 'en'])
            ->expectsOutputToContain($this->layout->dataDirectory().'/.htaccess is missing')
            ->expectsOutputToContain($this->layout->dataDirectory().' is mode 755')
            ->assertFailed();

        file_put_contents($this->layout->dataDirectory().'/.htaccess', "RewriteEngine On\n");
        $this->artisan('openpne:check', ['--only' => 'installed', '--lang' => 'en'])
            ->expectsOutputToContain($this->layout->dataDirectory().'/.htaccess is missing or not the release\'s top .htaccess')
            ->assertFailed();

        copy($this->layout->base.'/.htaccess', $this->layout->dataDirectory().'/.htaccess');
        chmod($this->layout->dataDirectory(), 0o700);
        // On the MySQL lane the option file is judged too, and its dump is not run.
        file_put_contents($this->layout->mysqlOptionFile(), "[client]\n");
        chmod($this->layout->mysqlOptionFile(), 0o600);
        Process::fake();
        $this->artisan('openpne:check', ['--only' => 'installed', '--lang' => 'en'])->assertSuccessful();
    }

    public function test_the_mysql_backup_settings_are_tried_with_a_schema_only_dump_and_never_print_the_password(): void
    {
        $this->installEnv();
        $this->tool('mysqldump');
        mkdir($this->layout->dataDirectory(), 0o700);
        copy($this->layout->base.'/.htaccess', $this->layout->dataDirectory().'/.htaccess');
        $this->useUnreachableMysql('sentinel-Pa55word');
        $file = $this->layout->mysqlOptionFile();

        $this->artisan('openpne:check', ['--only' => 'installed', '--lang' => 'en'])
            ->expectsOutputToContain($file.' is missing')
            ->assertFailed();

        file_put_contents($file, "[client]\n");
        chmod($file, 0o644);
        $this->artisan('openpne:check', ['--only' => 'installed', '--lang' => 'en'])
            ->expectsOutputToContain($file.' is mode 644')
            ->assertFailed();

        chmod($file, 0o600);
        Process::fake(['*' => Process::result(exitCode: 2, errorOutput: "mysqldump: Got error: 1045: Access denied for user 'site' (using password sentinel-Pa55word)\nmore")]);
        $output = $this->runCheck(['--only' => 'installed']);
        $this->assertStringContainsString("The MySQL backup fails with the settings in openpne-data/: mysqldump: Got error: 1045: Access denied for user 'site' (using password …)", $output);
        $this->assertStringNotContainsString('sentinel-Pa55word', $output);
        $this->assertStringNotContainsString((string) config('app.key'), $output);

        Process::fake();
        $this->runCheck(['--only' => 'installed']);
        Process::assertRan(fn (PendingProcess $process): bool => $process->command === [
            $this->layout->base.'/tools/mysqldump', '--defaults-extra-file='.$file, '--no-data',
            '--single-transaction', '--no-tablespaces', 'site_db',
        ]);
    }

    public function test_jobs_left_in_the_database_queue_say_nothing_works_it(): void
    {
        $this->installEnv();
        Artisan::call('openpne:install');
        config(['queue.default' => 'database']);
        $job = ['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'created_at' => time()];
        DB::table('jobs')->insert([...$job, 'available_at' => time() - 60]);

        $this->artisan('openpne:check', ['--only' => 'installed', '--lang' => 'en'])
            ->expectsOutputToContain('No job has waited in the queue longer than ten minutes.')
            ->assertSuccessful();

        DB::table('jobs')->insert([...$job, 'available_at' => time() - InstalledCheck::STALE_JOB_SECONDS - 60]);
        $this->artisan('openpne:check', ['--only' => 'installed', '--lang' => 'en'])
            ->expectsOutputToContain('waited in the queue longer than ten minutes: 1.')
            ->assertFailed();
    }

    public function test_mail_written_to_the_log_is_a_warning_and_says_when_the_log_drops_it(): void
    {
        $this->installEnv();
        Artisan::call('openpne:install');
        config(['mail.default' => 'log', 'mail.mailers.log.channel' => null, 'logging.default' => 'daily']);

        config(['logging.channels.daily.level' => 'debug']);
        $this->artisan('openpne:check', ['--only' => 'installed', '--lang' => 'en'])
            ->expectsOutputToContain('[WARN] Mail is not sent: MAIL_MAILER=log')
            ->assertSuccessful();

        config(['logging.channels.daily.level' => 'warning']);
        $this->artisan('openpne:check', ['--only' => 'installed', '--lang' => 'en'])
            ->expectsOutputToContain('[WARN] Mail is neither sent nor kept')
            ->assertSuccessful();
    }

    public function test_a_database_that_does_not_answer_is_named_without_its_password(): void
    {
        $this->installEnv();
        $this->useUnreachableMysql('sentinel-Pa55word');

        $output = $this->runCheck(['--only' => 'installed']);

        $this->assertStringContainsString('[FAIL] The database does not answer:', $output);
        $this->assertStringNotContainsString('sentinel-Pa55word', $output);
    }

    public function test_a_site_on_a_mariadb_server_fails_even_where_it_answers(): void
    {
        $this->installEnv();
        Artisan::call('openpne:install');
        $this->fakeDatabaseServer('10.6.28-MariaDB-ubu2204');

        $this->artisan('openpne:check', ['--only' => 'installed', '--lang' => 'en'])
            ->expectsOutputToContain('[ OK ] The database (')
            ->expectsOutputToContain('[FAIL] The database server is MariaDB 10.6.28, and OpenPNE runs on MariaDB 10.11 or later only')
            ->expectsOutputToContain('[ OK ] Every migration has run.')
            ->assertFailed();
    }

    public function test_a_driver_that_does_not_fit_the_server_fails(): void
    {
        $this->installEnv();
        Artisan::call('openpne:install');
        $driver = (string) DB::connection()->getDriverName();
        $this->fakeDatabaseServer($driver === 'mariadb' ? '8.4.11' : '10.11.19-MariaDB-ubu2204');

        $this->artisan('openpne:check', ['--only' => 'installed', '--lang' => 'en'])
            ->expectsOutputToContain('[FAIL] The database server is '.($driver === 'mariadb' ? 'MySQL 8.4.11, but DB_CONNECTION is mariadb' : "MariaDB 10.11.19, but DB_CONNECTION is {$driver}"))
            ->assertFailed();
    }

    public function test_a_mariadb_server_refusing_the_connection_is_named_beside_the_refusal(): void
    {
        $this->installEnv();
        $this->useUnreachableMysql('sentinel-Pa55word');
        $this->fakeDatabaseServer('10.6.28-MariaDB-ubu2204');

        $output = $this->runCheck(['--only' => 'installed']);

        $this->assertStringContainsString('[FAIL] The database does not answer:', $output);
        $this->assertStringContainsString('[FAIL] The database server is MariaDB 10.6.28', $output);
    }

    public function test_the_language_follows_the_option_then_the_shell_then_app_locale(): void
    {
        $this->artisan('openpne:check', ['--only' => 'before', '--lang' => 'ja'])
            ->expectsOutputToContain('入れる前に分かること')
            ->assertSuccessful();

        $saved = getenv('LANG');
        try {
            putenv('LANG=en_US.UTF-8');
            config(['app.locale' => 'ja']);
            $this->artisan('openpne:check', ['--only' => 'before'])->expectsOutputToContain('Before installing');

            putenv('LANG=C.UTF-8');
            $this->artisan('openpne:check', ['--only' => 'before'])->expectsOutputToContain('入れる前に分かること');

            putenv('LANG=ja_JP.UTF-8');
            config(['app.locale' => 'en']);
            $this->artisan('openpne:check', ['--only' => 'before'])->expectsOutputToContain('入れる前に分かること');
        } finally {
            putenv($saved === false ? 'LANG' : "LANG={$saved}");
        }
    }

    public function test_every_line_resolves_in_both_languages(): void
    {
        $this->installEnv();
        $this->fakeHost();

        foreach (['en', 'ja'] as $lang) {
            $this->assertStringNotContainsString('console.check.', $this->runCheck(['--lang' => $lang]));
        }
        $this->assertSame(array_keys((require lang_path('en/console.php'))['check']), array_keys((require lang_path('ja/console.php'))['check']));
    }

    // From the web

    public function test_a_site_served_from_public_passes_and_leaves_no_file_behind(): void
    {
        $this->installEnv();
        $this->fakeHost();

        $this->artisan('openpne:check', ['--only' => 'web', '--lang' => 'en'])
            ->expectsOutputToContain('[ OK ] The document root is '.$this->layout->public.'.')
            ->expectsOutputToContain('[ OK ] The site\'s own files cannot be read from the web.')
            ->expectsOutputToContain('[ OK ] The web runs PHP 8.3.30 (fpm-fcgi)')
            ->expectsOutputToContain('[ OK ] The web runs PHP as this account.')
            ->expectsOutputToContain('[ OK ] No header that a proxy in front adds reached the site')
            ->doesntExpectOutputToContain('[FAIL]')
            ->assertSuccessful();

        $this->assertSame([], $this->probeFiles());
        Http::assertSent(fn (Request $request): bool => $request->hasHeader(WebCheck::HEADER) && str_ends_with($request->url(), '.php'));
    }

    public function test_a_document_root_on_the_release_is_named_whether_or_not_its_403_works(): void
    {
        $this->installEnv();
        $this->fakeHost(['root' => 'release']);
        $this->artisan('openpne:check', ['--only' => 'web', '--lang' => 'en'])
            ->expectsOutputToContain('[FAIL] The document root is '.$this->layout->base.', the site\'s own directory')
            ->expectsOutputToContain('[ ?? ] Whether the site\'s own files can be read from the web is checked once the document root is right.')
            ->expectsOutputToContain('[ ?? ] The web\'s PHP is checked once the document root is right.')
            ->assertFailed();

        $this->fakeHost(['root' => 'release', 'deny' => false]);
        $this->artisan('openpne:check', ['--only' => 'web', '--lang' => 'en'])
            ->expectsOutputToContain('[FAIL] The document root is '.$this->layout->base)
            ->expectsOutputToContain('[FAIL] Files in these directories can be read from the web, .env and the database among them: '.$this->layout->base.', '.$this->layout->storage.'.')
            ->assertFailed();
    }

    public function test_a_server_that_leaves_subdirectories_unprotected_is_caught_through_storage(): void
    {
        $this->installEnv();
        $this->fakeHost(['root' => 'release', 'deny' => 'top-only']);

        $this->artisan('openpne:check', ['--only' => 'web', '--lang' => 'en'])
            ->expectsOutputToContain('can be read from the web, .env and the database among them: '.$this->layout->storage.'.')
            ->assertFailed();
    }

    public function test_each_answer_for_the_public_file_names_its_own_cause(): void
    {
        $this->installEnv();
        $cases = [
            403 => 'refuses with 403. Make the document root '.$this->layout->public.'. If it already is, the server applies the rules of '.$this->layout->base.' to public/ as well (RewriteOptions InheritDown)',
            404 => 'answers 404 for a file in '.$this->layout->public.': the document root is elsewhere.',
            500 => 'the server refuses the site\'s .htaccess. It needs mod_rewrite, and AllowOverride FileInfo',
            200 => 'answers with a page that is not this site\'s file',
            502 => 'answers 502 for a file in',
        ];
        foreach ($cases as $status => $words) {
            $this->fakeHost(['status' => $status]);
            $this->artisan('openpne:check', ['--only' => 'web', '--lang' => 'en'])
                ->expectsOutputToContain($words)
                ->assertFailed();
        }

        $this->fakeHost(['status' => 'unreachable']);
        $this->artisan('openpne:check', ['--only' => 'web', '--lang' => 'en'])
            ->expectsOutputToContain(self::URL.' cannot be reached: cURL error 6: Could not resolve host: sns.example.com. The domain')
            ->expectsOutputToContain('[ ?? ] Whether the site\'s own files can be read from the web is checked once the document root is right.')
            ->assertFailed();

        $this->fakeHost(['status' => 301]);
        $this->artisan('openpne:check', ['--only' => 'web', '--lang' => 'en'])
            ->expectsOutputToContain(self::URL.' redirects to https://www.sns.example.com/')
            ->assertFailed();
        Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://www.'));
    }

    public function test_what_the_web_php_answers_is_judged(): void
    {
        $this->installEnv();
        $this->fakeHost(['php' => ['version' => '8.2.29', 'sapi' => 'cgi-fcgi', 'missing' => ['intl', 'pdo_sqlite', 'pdo_mysql'],
            'ini' => ['memory_limit' => '128M', 'upload_max_filesize' => '2M', 'post_max_size' => '8M', 'opcache.file_cache' => '']]]);

        $this->artisan('openpne:check', ['--only' => 'web', '--lang' => 'en'])
            ->expectsOutputToContain('[FAIL] The web runs PHP 8.2.29 (the command line runs '.PHP_VERSION.')')
            ->expectsOutputToContain('[FAIL] PHP extensions missing on the web: intl, pdo_')
            ->expectsOutputToContain('[WARN] memory_limit on the web is 128M')
            ->expectsOutputToContain('[WARN] Upload sizes on the web are too small (upload_max_filesize 2M, post_max_size 8M): set them to at least 10M and 31M')
            ->expectsOutputToContain('[WARN] The web runs PHP as CGI (cgi-fcgi)')
            ->assertFailed();
    }

    public function test_upload_sizes_of_zero_are_no_limit(): void
    {
        $this->installEnv();
        $this->fakeHost(['php' => ['ini' => ['upload_max_filesize' => '0', 'post_max_size' => '0']]]);

        $this->artisan('openpne:check', ['--only' => 'web', '--lang' => 'en'])
            ->expectsOutputToContain('[ OK ] Upload sizes on the web: upload_max_filesize 0, post_max_size 0.')
            ->assertSuccessful();
    }

    public function test_the_web_heading_does_not_repeat_a_url_with_a_user_in_it(): void
    {
        $this->installEnv();
        config(['app.url' => 'https://site:sentinel-Pa55word@sns.example.com']);

        $output = $this->runCheck(['--only' => 'web']);

        $this->assertStringContainsString('From the web (…)', $output);
        $this->assertStringNotContainsString('sentinel-Pa55word', $output);
    }

    public function test_the_parent_address_is_not_repeated_with_a_user_in_it(): void
    {
        $this->installEnv();
        $this->fakeHost([]);

        $output = $this->runCheck(['--only' => 'web', '--parent-url' => 'https://site:sentinel-Pa55word@account.example.net/holder']);

        $this->assertStringContainsString('[ ?? ] … does not show the directory holding the site', $output);
        $this->assertStringNotContainsString('sentinel-Pa55word', $output);
    }

    public function test_a_web_php_running_as_another_user_fails(): void
    {
        $this->installEnv();
        $this->assertNotSame(fileowner($this->layout->envFile), fileowner('/'));
        $this->fakeHost(['php' => ['wrote' => 'another user']]);

        $this->artisan('openpne:check', ['--only' => 'web', '--lang' => 'en'])
            ->expectsOutputToContain('[FAIL] The web runs PHP as another user than this account.')
            ->assertFailed();
        $this->assertSame([], $this->probeFiles());
    }

    public function test_a_web_php_that_cannot_write_or_is_not_run_fails(): void
    {
        $this->installEnv();
        $this->fakeHost(['php' => ['wrote' => false]]);
        $this->artisan('openpne:check', ['--only' => 'web', '--lang' => 'en'])
            ->expectsOutputToContain('[FAIL] The web\'s PHP cannot write to '.$this->layout->storage.'/framework')
            ->assertFailed();

        $this->fakeHost(['php' => 'source', 'up' => [200, "<?php\n\nrequire __DIR__.'/../vendor/autoload.php';\n"]]);
        $this->artisan('openpne:check', ['--only' => 'web', '--lang' => 'en'])
            ->expectsOutputToContain('[FAIL] /up came back as the source of index.php')
            ->expectsOutputToContain('[FAIL] PHP is not run for .php files')
            ->assertFailed();

        // A warning printed before the answer is PHP that ran, not its source come back.
        $this->fakeHost(['php' => ['prefix' => "Warning: something on line 1\n"]]);
        $this->artisan('openpne:check', ['--only' => 'web', '--lang' => 'en'])
            ->expectsOutputToContain('[FAIL] The web\'s PHP could not be asked (200)')
            ->assertFailed();

        $this->fakeHost(['up' => [500, 'Composer detected issues in your platform: Your Composer dependencies require a PHP version ">= 8.3.0".']]);
        $this->artisan('openpne:check', ['--only' => 'web', '--lang' => 'en'])
            ->expectsOutputToContain('[FAIL] The site stops at Composer\'s check of the PHP version')
            ->assertFailed();
    }

    public function test_proxy_headers_warn_and_nothing_seen_becomes_configuration(): void
    {
        $this->installEnv();
        $this->fakeHost(['php' => ['proxy_headers' => ['X-Forwarded-For', 'CF-Connecting-IP'], 'peer' => '203.0.113.9']]);

        $output = $this->runCheck(['--only' => 'web']);
        $this->assertStringContainsString('[WARN] Headers that a proxy in front adds reached the site (X-Forwarded-For, CF-Connecting-IP), and TRUSTED_PROXIES is empty', $output);
        $this->assertStringContainsString('docs/installing.md, step 5, says how to tell', $output);
        $this->assertStringNotContainsString('203.0.113.9', $output);
        $this->assertSame([], config('trustedproxy.proxies'));
        $this->assertStringNotContainsString('TRUSTED_PROXIES', (string) file_get_contents($this->layout->envFile));

        config(['trustedproxy.proxies' => '198.51.100.0/24']);
        $this->assertStringContainsString('[WARN] The request reached the site from an address outside TRUSTED_PROXIES', $this->runCheck(['--only' => 'web']));

        config(['trustedproxy.proxies' => '198.51.100.0/24, 203.0.113.0/24']);
        $this->assertStringContainsString('[ OK ] The proxy in front is in TRUSTED_PROXIES.', $this->runCheck(['--only' => 'web']));

        foreach (['**', 'REMOTE_ADDR', '198.51.100.0/24,REMOTE_ADDR'] as $proxies) {
            config(['trustedproxy.proxies' => $proxies]);
            $this->assertStringContainsString('[ OK ] The proxy in front is in TRUSTED_PROXIES.', $this->runCheck(['--only' => 'web']), $proxies);
        }
        config(['trustedproxy.proxies' => '198.51.100.0/24,*']);
        $this->assertStringContainsString('[WARN] The request reached the site from an address outside TRUSTED_PROXIES', $this->runCheck(['--only' => 'web']));
        $this->fakeHost(['php' => ['proxy_headers' => ['X-Forwarded-For'], 'peer' => '10.0.0.5']]);
        config(['trustedproxy.proxies' => 'PRIVATE_SUBNETS']);
        $this->assertStringContainsString('[ OK ] The proxy in front is in TRUSTED_PROXIES.', $this->runCheck(['--only' => 'web']));
        // In IpUtils::PRIVATE_SUBNETS but not the PRIVATE_RANGES that Request trusts for the keyword.
        $this->fakeHost(['php' => ['proxy_headers' => ['X-Forwarded-For'], 'peer' => '198.18.0.1']]);
        $this->assertStringContainsString('[WARN] The request reached the site from an address outside TRUSTED_PROXIES', $this->runCheck(['--only' => 'web']));
    }

    public function test_the_parent_address_is_proved_before_it_is_trusted_with_a_verdict(): void
    {
        $this->installEnv();
        mkdir($this->layout->dataDirectory(), 0o700);

        $this->fakeHost(['parent' => 'deny']);
        $this->artisan('openpne:check', ['--only' => 'web', '--parent-url' => self::PARENT_URL, '--lang' => 'en'])
            ->expectsOutputToContain('[ OK ] From '.self::PARENT_URL.', the site\'s own files cannot be read.')
            ->assertSuccessful();

        $this->fakeHost(['parent' => 'serve']);
        $this->artisan('openpne:check', ['--only' => 'web', '--parent-url' => self::PARENT_URL, '--lang' => 'en'])
            ->expectsOutputToContain('From '.self::PARENT_URL.', files in these directories can be read: '.$this->layout->base.', '.$this->layout->storage.', '.$this->layout->dataDirectory().'.')
            ->assertFailed();

        $this->fakeHost(['parent' => 'elsewhere']);
        $this->artisan('openpne:check', ['--only' => 'web', '--parent-url' => self::PARENT_URL, '--lang' => 'en'])
            ->expectsOutputToContain('[ ?? ] '.self::PARENT_URL.' does not show the directory holding the site (404)')
            ->assertSuccessful();
        $this->assertSame([], $this->probeFiles());
    }

    public function test_the_parent_address_is_not_judged_without_its_proof(): void
    {
        $this->installEnv();
        $this->fakeHost(['parent' => 'deny']);
        foreach ([$this->layout->parent(), $this->layout->base] as $unwritable) {
            chmod($unwritable, 0o555);
            try {
                $this->artisan('openpne:check', ['--only' => 'web', '--parent-url' => self::PARENT_URL, '--lang' => 'en'])
                    ->expectsOutputToContain('[ ?? ] Not checked: '.$unwritable.' cannot be written to.')
                    ->doesntExpectOutputToContain('the site\'s own files cannot be read.')
                    ->assertSuccessful();
            } finally {
                chmod($unwritable, 0o755);
            }
        }
    }

    public function test_the_probe_files_go_even_when_the_check_breaks_off(): void
    {
        $this->installEnv();
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '.php')) {
                throw new RuntimeException('broken off');
            }

            return $this->serveFile($this->layout->public.substr($request->url(), strlen(self::URL)));
        });

        try {
            Artisan::call('openpne:check', ['--only' => 'web']);
            $this->fail('The exception should reach the caller.');
        } catch (RuntimeException $e) {
            $this->assertSame('broken off', $e->getMessage());
        }

        $this->fakeHost();
        $this->assertSame([], $this->probeFiles());
        $lock = new LockableFile($this->layout->storage.'/framework/openpne-check.lock', 'c');
        $lock->getExclusiveLock();
        $lock->close();
    }

    public function test_a_second_check_neither_cleans_nor_fetches_while_the_first_holds_the_lock(): void
    {
        $this->installEnv();
        Http::fake();
        $leftover = $this->layout->public.'/openpne-check-'.str_repeat('a', 32).'.php';
        file_put_contents($leftover, '');
        touch($leftover, time() - WebCheck::LEFTOVER_SECONDS - 60);
        $lock = new LockableFile($this->layout->storage.'/framework/openpne-check.lock', 'c');
        $lock->getExclusiveLock();

        try {
            $this->artisan('openpne:check', ['--only' => 'web', '--lang' => 'en'])
                ->expectsOutputToContain('[ ?? ] Not checked: another check of the web is running.')
                ->assertSuccessful();
        } finally {
            $lock->close();
        }

        Http::assertNothingSent();
        $this->assertFileExists($leftover);
    }

    public function test_a_lock_file_this_account_cannot_open_is_named(): void
    {
        $this->installEnv();
        $lock = $this->layout->storage.'/framework/'.WebCheck::LOCK_FILE;
        touch($lock);
        chmod($lock, 0o444);

        $this->artisan('openpne:check', ['--only' => 'web', '--lang' => 'en'])
            ->expectsOutputToContain('[ ?? ] Not checked: '.$lock.' cannot be written to.')
            ->assertSuccessful();
    }

    public function test_only_leftovers_older_than_ten_minutes_are_removed(): void
    {
        $this->installEnv();
        $this->fakeHost();
        $old = $this->layout->base.'/openpne-check-'.str_repeat('b', 32).'.txt';
        $oldInParent = $this->layout->parent().'/openpne-check-'.str_repeat('e', 32).'.txt';
        $recent = $this->layout->public.'/openpne-check-'.str_repeat('c', 32).'.php';
        $foreign = $this->layout->public.'/openpne-check-notours.txt';
        foreach ([$old, $oldInParent, $recent, $foreign] as $file) {
            file_put_contents($file, '');
            touch($file, time() - WebCheck::LEFTOVER_SECONDS - 60);
        }
        touch($recent, time() - WebCheck::LEFTOVER_SECONDS + 60);

        $this->runCheck(['--only' => 'web']);

        $this->assertFileDoesNotExist($old);
        $this->assertFileDoesNotExist($oldInParent);
        $this->assertFileExists($recent);
        $this->assertFileExists($foreign);
    }

    public function test_the_php_probe_answers_only_its_token(): void
    {
        $token = bin2hex(random_bytes(16));
        $probe = $this->layout->public.'/probe.php';
        $owner = $this->layout->storage.'/framework/probe.owner';
        file_put_contents($probe, WebCheck::probeSource($token, $owner));

        [$status, $body] = $this->runProbe($probe, 'wrong');
        $this->assertSame(['404', ''], [$status, $body]);
        $this->assertFileDoesNotExist($owner);

        [$status, $body] = $this->runProbe($probe, $token);
        $answer = json_decode($body, true);
        $this->assertSame('200', $status);
        $this->assertSame($token, $answer['token']);
        $this->assertSame(PHP_VERSION, $answer['version']);
        $this->assertContains('mbstring', $answer['extensions']);
        $this->assertSame(['memory_limit', 'upload_max_filesize', 'post_max_size', 'max_execution_time', 'opcache.enable', 'opcache.file_cache'], array_keys($answer['ini']));
        $this->assertTrue($answer['wrote']);
        $this->assertFileExists($owner);
    }

    public function test_the_probe_carries_a_path_with_quotes_intact(): void
    {
        $source = WebCheck::probeSource(str_repeat('d', 32), "/tmp/it's \\ here.owner");

        $this->assertStringContainsString("\$ownerFile = '/tmp/it\\'s \\\\ here.owner';", $source);
        $this->assertStringNotContainsString('{{', $source);
    }

    // Helpers

    private function installEnv(int $mode = 0o600): void
    {
        file_put_contents($this->layout->envFile, "APP_ENV=production\n");
        chmod($this->layout->envFile, $mode);
        file_put_contents($this->layout->base.'/.htaccess', SiteLayout::DENY);
    }

    /** The SQLite pdo_sqlite runs on, null for a PHP without pdo_sqlite unless $pdo says it is there. */
    private function sqliteLibrary(?string $version, bool $extension = true, ?bool $pdo = null): void
    {
        $this->app->instance(SqliteSupport::class, new class($version, $extension, $pdo ?? $version !== null) extends SqliteSupport
        {
            public function __construct(private readonly ?string $version, private readonly bool $extension, private readonly bool $pdo) {}

            public function hasExtension(): bool
            {
                return $this->extension;
            }

            public function hasPdo(): bool
            {
                return $this->pdo;
            }

            public function libraryVersion(): ?string
            {
                return $this->version;
            }
        });
    }

    private function sqlite(): bool
    {
        return config('database.connections.'.$this->connection.'.driver') === 'sqlite';
    }

    /** An executable that leaves a mark if anything runs it. */
    private function tool(string $name): void
    {
        $path = $this->layout->base.'/tools/'.$name;
        file_put_contents($path, "#!/bin/sh\ntouch \"\$(dirname \"\$0\")/ran\"\n");
        chmod($path, 0o755);
    }

    private function useUnreachableMysql(string $password): void
    {
        config([
            'database.connections.check_mysql' => [
                'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'site_db',
                'username' => 'site', 'password' => $password, 'charset' => 'utf8mb4', 'prefix' => '',
            ],
            'database.default' => 'check_mysql',
        ]);
    }

    /** @param array<string, string> $options */
    private function runCheck(array $options): string
    {
        Artisan::call('openpne:check', [...$options, '--lang' => $options['--lang'] ?? 'en']);

        return Artisan::output();
    }

    /** @return list<string> */
    private function probeFiles(): array
    {
        $found = [];
        foreach ([$this->layout->public, $this->layout->base, $this->layout->storage, $this->layout->storage.'/framework',
            $this->layout->parent(), $this->layout->dataDirectory()] as $directory) {
            foreach (glob($directory.'/openpne-check-*') ?: [] as $file) {
                $found[] = $file;
            }
        }

        return $found;
    }

    /** @return array{string, string} the status and the body */
    private function runProbe(string $probe, string $token): array
    {
        $process = new SymfonyProcess([PHP_BINARY, '-r',
            '$_SERVER["HTTP_X_OPENPNE_CHECK"] = $argv[1]; register_shutdown_function(function () { fwrite(STDERR, (string) http_response_code()); }); include $argv[2];',
            '--', $token, $probe]);
        $process->mustRun();

        return [$process->getErrorOutput(), $process->getOutput()];
    }

    /**
     * A web server over the temporary site: `root` is what APP_URL serves (public or release), `deny`
     * whether the release's top .htaccess works (true, false, or top-only for a server that does not
     * carry it into subdirectories), `parent` what --parent-url shows.
     *
     * @param  array<string, mixed>  $host
     */
    private function fakeHost(array $host = []): void
    {
        $host += ['root' => 'public', 'deny' => true, 'status' => null, 'php' => [], 'up' => [200, '{}'], 'parent' => 'deny'];
        $layout = $this->layout;
        // Http::fake() adds to the stubs already there, the first that answers winning.
        Http::swap(new HttpFactory($this->app->make(Dispatcher::class)));

        Http::fake(function (Request $request) use ($host, $layout) {
            $url = $request->url();
            if (str_starts_with($url, self::PARENT_URL.'/')) {
                $path = substr($url, strlen(self::PARENT_URL));
                $inSite = str_starts_with($path, '/site/') || str_starts_with($path, '/'.SiteLayout::DATA_DIRECTORY.'/');
                if ($host['parent'] === 'elsewhere' || ($inSite && $host['parent'] === 'deny')) {
                    return Http::response('', $host['parent'] === 'elsewhere' ? 404 : 403);
                }

                return $this->serveFile($layout->parent().$path);
            }

            $path = substr($url, strlen(self::URL));
            if ($host['status'] === 'unreachable') {
                return (Http::failedConnection())($request);
            }
            if (is_int($host['status']) && str_ends_with($path, '.txt')) {
                return Http::response('<html>elsewhere</html>', $host['status'], $host['status'] === 301 ? ['Location' => 'https://www.sns.example.com/'] : []);
            }
            if ($path === '/up') {
                return Http::response($host['up'][1], $host['up'][0]);
            }

            if ($host['root'] === 'release') {
                if (str_starts_with($path, '/public/')) {
                    return $this->serveFile($layout->base.$path);
                }
                $nested = substr_count($path, '/') > 1;
                if ($host['deny'] === true || ($host['deny'] === 'top-only' && ! $nested)) {
                    return Http::response('', 403);
                }

                return $this->serveFile($layout->base.$path);
            }

            if (str_ends_with($path, '.php')) {
                return $this->runFakePhp($layout->public.$path, $request, $host['php']);
            }

            // public/ is the root: a path that is no file reaches the front controller's 404.
            return $this->serveFile($layout->public.$path);
        });
    }

    private function serveFile(string $path): mixed
    {
        return is_file($path) ? Http::response((string) file_get_contents($path), 200) : Http::response('', 404);
    }

    /** @param array<string, mixed>|string $php */
    private function runFakePhp(string $path, Request $request, array|string $php): mixed
    {
        $source = (string) file_get_contents($path);
        if ($php === 'source') {
            return Http::response($source, 200);
        }
        preg_match("/\\\$token = '([0-9a-f]{32})';/", $source, $token);
        preg_match("/\\\$ownerFile = '([^']+)';/", $source, $owner);
        if (($request->header(WebCheck::HEADER)[0] ?? null) !== $token[1]) {
            return Http::response('', 404);
        }

        $php += ['version' => '8.3.30', 'sapi' => 'fpm-fcgi', 'missing' => [], 'ini' => [], 'wrote' => true, 'proxy_headers' => [], 'peer' => '127.0.0.1', 'prefix' => ''];
        if ($php['wrote'] === 'another user') {
            // fileowner() follows the link to root's directory, and unlink() removes the link alone.
            symlink('/', $owner[1]);
        } elseif ($php['wrote']) {
            touch($owner[1]);
        }

        return Http::response($php['prefix'].json_encode([
            'token' => $token[1],
            'version' => $php['version'],
            'sapi' => $php['sapi'],
            'extensions' => array_values(array_diff([...BeforeInstallCheck::EXTENSIONS, 'pdo_sqlite', 'pdo_mysql'], $php['missing'])),
            'ini' => $php['ini'] + ['memory_limit' => '256M', 'upload_max_filesize' => '16M', 'post_max_size' => '32M', 'max_execution_time' => '30', 'opcache.enable' => '1', 'opcache.file_cache' => ''],
            'wrote' => (bool) $php['wrote'],
            'proxy_headers' => $php['proxy_headers'],
            'peer' => $php['peer'],
        ]), 200);
    }
}
