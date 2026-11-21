<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\AdminUser;
use App\Setup\EnvFile;
use App\Setup\SetupHost;
use App\SiteCheck\BeforeInstallCheck;
use App\SiteCheck\SiteLayout;
use App\SiteCheck\WebCheck;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

class SetupCommandTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://sns.example.com';

    private const HTACCESS = "RewriteEngine On\nRewriteRule ^ - [F]\n";

    private string $root;

    private SiteLayout $layout;

    private string|false $path;

    private string $connection;

    /** @var array{interactive: bool, spawn: bool, hide: bool, spawned: list<array{list<string>, array<string, string>}>} */
    private array $hostState = ['interactive' => true, 'spawn' => true, 'hide' => true, 'spawned' => []];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useRoot(sys_get_temp_dir().'/openpne-setup-test-'.bin2hex(random_bytes(4)));
        $this->connection = (string) config('database.default');
        $this->path = getenv('PATH');
        putenv('PATH='.$this->layout->base.'/tools');

        $state = &$this->hostState;
        $this->app->instance(SetupHost::class, new class($state) extends SetupHost
        {
            /** @param array<string, mixed> $state */
            public function __construct(private array &$state) {}

            public function interactive(): bool
            {
                return $this->state['interactive'];
            }

            public function canSpawn(): bool
            {
                return $this->state['spawn'];
            }

            public function hidesTypedInput(): bool
            {
                return $this->state['hide'];
            }

            public function spawn(array $command, array $environment, string $directory): int
            {
                $this->state['spawned'][] = [$command, $environment];

                return 0;
            }
        });
        config(['app.url' => self::URL, 'trustedproxy.proxies' => []]);
    }

    protected function tearDown(): void
    {
        putenv('PATH='.$this->path);
        putenv('APP_NAME');
        (new Filesystem)->deleteDirectory($this->root);
        config(['database.default' => $this->connection]);

        parent::tearDown();
    }

    // Before .env

    public function test_without_a_terminal_it_says_how_to_get_one_and_writes_nothing(): void
    {
        $this->hostState['interactive'] = false;

        $this->runSetup()->expectsOutputToContain('ssh -t')->assertFailed();

        $this->assertDirectoryDoesNotExist($this->layout->dataDirectory());
    }

    public function test_answers_become_env_beside_a_private_data_directory_and_a_new_process_goes_on(): void
    {
        $this->tools('sqlite3', 'mysqldump');
        putenv('APP_NAME=from the shell');

        $this->answerSite($this->runSetup())
            ->expectsChoice('Database', 'sqlite', ['sqlite' => 'SQLite (a file in openpne-data/)', 'mysql' => 'MySQL (the host\'s database server)'])
            ->expectsChoice('Mail', 'later', ['later' => 'Later (nothing is sent until then)', 'smtp' => 'An SMTP server'])
            ->expectsOutputToContain('none is kept in the log either')
            ->expectsChoice('Background work', 'deferred', $this->queueChoices())
            ->assertSuccessful();

        $data = $this->layout->dataDirectory();
        $env = EnvFile::read((string) file_get_contents($this->layout->envFile));
        $this->assertEquals([
            'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'LOG_LEVEL' => 'warning', 'APP_URL' => self::URL,
            'APP_NAME' => 'Example SNS', 'APP_TIMEZONE' => 'Asia/Tokyo', 'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $data.'/database.sqlite', 'DB_JOURNAL_MODE' => 'WAL', 'DB_SYNCHRONOUS' => 'NORMAL',
            'QUEUE_CONNECTION' => 'deferred', 'MAIL_MAILER' => 'log',
        ], array_intersect_key($env, array_flip(['APP_ENV', 'APP_DEBUG', 'LOG_LEVEL', 'APP_URL', 'APP_NAME', 'APP_TIMEZONE',
            'DB_CONNECTION', 'DB_DATABASE', 'DB_JOURNAL_MODE', 'DB_SYNCHRONOUS', 'QUEUE_CONNECTION', 'MAIL_MAILER'])));
        $this->assertSame(0o600, fileperms($this->layout->envFile) & 0o777);
        $this->assertSame(0o700, fileperms($data) & 0o777);
        $this->assertSame(self::HTACCESS, file_get_contents($data.'/.htaccess'));

        $this->assertCount(1, $this->hostState['spawned']);
        [$command, $environment] = $this->hostState['spawned'][0];
        $this->assertSame([PHP_BINARY, base_path('artisan'), 'openpne:setup', '--lang=en'], $command);
        $this->assertArrayNotHasKey('APP_NAME', $environment);
        $this->assertSame($this->layout->base.'/tools', $environment['PATH']);
    }

    public function test_a_refused_answer_is_asked_again_and_never_written(): void
    {
        $this->tools('sqlite3');

        $this->runSetup()
            ->expectsQuestion('The site\'s address', 'https://sns.example.com/sns')
            ->expectsOutputToContain('nothing after the domain')
            ->expectsQuestion('The site\'s address', self::URL.'/')
            ->expectsQuestion('The site\'s name', '(true)')
            ->expectsOutputToContain('read as something else from .env')
            ->expectsQuestion('The site\'s name', 'a"b\\c')
            ->expectsOutputToContain('does not read back from .env as typed')
            ->expectsQuestion('The site\'s name', 'Example SNS')
            ->expectsQuestion('Time zone', 'Tokyo')
            ->expectsQuestion('Time zone', 'UTC')
            ->expectsOutputToContain('The database is SQLite')
            ->expectsChoice('Mail', 'smtp', ['later' => 'Later (nothing is sent until then)', 'smtp' => 'An SMTP server'])
            ->expectsQuestion('SMTP server', 'smtp.example.com')
            ->expectsQuestion('Port', '587')
            ->expectsQuestion('User name', 'sns')
            ->expectsQuestion('Password', 'p${HOME}')
            ->expectsOutputToContain('which .env reads as a variable')
            ->expectsQuestion('Password', 'p#ss"w')
            ->expectsQuestion('The address mail is sent from', 'sns@example.com')
            ->expectsChoice('Background work', 'database', $this->queueChoices())
            ->assertSuccessful();

        $env = EnvFile::read((string) file_get_contents($this->layout->envFile));
        $this->assertSame(self::URL, $env['APP_URL']);
        $this->assertSame('UTC', $env['APP_TIMEZONE']);
        $this->assertSame(['smtp', 'smtp.example.com', '587', 'sns', 'p#ss"w', 'sns@example.com', 'database'], [
            $env['MAIL_MAILER'], $env['MAIL_HOST'], $env['MAIL_PORT'], $env['MAIL_USERNAME'], $env['MAIL_PASSWORD'],
            $env['MAIL_FROM_ADDRESS'], $env['QUEUE_CONNECTION'],
        ]);
    }

    public function test_mysql_that_refuses_is_said_without_its_password_and_stopping_leaves_no_env(): void
    {
        $this->tools('mysqldump');

        $this->answerSite($this->runSetup())
            ->expectsOutputToContain('The database is MySQL')
            ->expectsQuestion('MySQL server', '127.0.0.1')
            ->expectsQuestion('Port', '1')
            ->expectsQuestion('Database name', 'site_db')
            ->expectsQuestion('User name', 'site')
            ->expectsQuestion('Password', 'Sekret-9x')
            ->expectsOutputToContain('MySQL refused')
            ->doesntExpectOutputToContain('Sekret-9x')
            ->expectsConfirmation('Enter them again?', 'no')
            ->assertFailed();

        $this->assertFileDoesNotExist($this->layout->envFile);
        $this->assertSame([], $this->hostState['spawned']);
    }

    public function test_mysql_answers_that_connect_are_written(): void
    {
        $settings = (array) config('database.connections.'.$this->connection);
        if (($settings['driver'] ?? null) !== 'mysql') {
            $this->markTestSkipped('Needs the MySQL test connection.');
        }
        $this->tools('mysqldump');

        $this->answerSite($this->runSetup())
            ->expectsQuestion('MySQL server', (string) $settings['host'])
            ->expectsQuestion('Port', (string) $settings['port'])
            ->expectsQuestion('Database name', (string) $settings['database'])
            ->expectsQuestion('User name', (string) $settings['username'])
            ->expectsQuestion('Password', (string) $settings['password'])
            ->expectsChoice('Mail', 'later', ['later' => 'Later (nothing is sent until then)', 'smtp' => 'An SMTP server'])
            ->expectsChoice('Background work', 'deferred', $this->queueChoices())
            ->assertSuccessful();

        $env = EnvFile::read((string) file_get_contents($this->layout->envFile));
        $this->assertSame(['mysql', (string) $settings['database']], [$env['DB_CONNECTION'], $env['DB_DATABASE']]);
    }

    public function test_a_sqlite3_too_old_for_vacuum_into_cannot_be_chosen(): void
    {
        $this->tools('sqlite3');
        Process::fake(['*' => Process::result('3.26.0 2018-12-01 12:34:55 bf8c1b2b7a')]);

        $this->answerSite($this->runSetup())
            ->expectsOutputToContain('SQLite 3.26.0, and the backup needs 3.27 or later')
            ->expectsOutputToContain('No database can be backed up on this host')
            ->assertFailed();

        $this->assertFileDoesNotExist($this->layout->envFile);
    }

    public function test_a_host_without_proc_open_writes_env_and_asks_to_be_run_again(): void
    {
        $this->tools('sqlite3');
        $this->hostState['spawn'] = false;

        $this->answerSite($this->runSetup())
            ->expectsChoice('Mail', 'later', ['later' => 'Later (nothing is sent until then)', 'smtp' => 'An SMTP server'])
            ->expectsChoice('Background work', 'deferred', $this->queueChoices())
            ->expectsOutputToContain('Run the same command again to continue')
            ->assertFailed();

        $this->assertFileExists($this->layout->envFile);
        $this->assertSame([], $this->hostState['spawned']);
    }

    public function test_a_password_this_host_would_show_is_asked_only_once_agreed_to(): void
    {
        $this->tools('mysqldump');
        $this->hostState['spawn'] = false;
        $this->hostState['hide'] = false;

        $this->answerSite($this->runSetup())
            ->expectsQuestion('MySQL server', '127.0.0.1')
            ->expectsQuestion('Port', '3306')
            ->expectsQuestion('Database name', 'site_db')
            ->expectsQuestion('User name', 'site')
            ->expectsOutputToContain('a password shows on the screen')
            ->expectsConfirmation('Go on, with the password showing as you type?', 'no')
            ->assertFailed();

        $this->assertFileDoesNotExist($this->layout->envFile);
    }

    public function test_a_parent_directory_that_cannot_be_written_stops_before_any_question(): void
    {
        $this->tools('sqlite3');
        chmod($this->layout->parent(), 0o555);

        try {
            $this->runSetup()
                ->expectsOutputToContain('These cannot be written to: '.$this->layout->parent())
                ->expectsOutputToContain('then run this command again')
                ->assertFailed();
        } finally {
            chmod($this->layout->parent(), 0o755);
        }
        $this->assertDirectoryDoesNotExist($this->layout->dataDirectory());
    }

    // With .env

    public function test_a_site_with_env_is_installed_checked_closed_and_given_its_first_member_and_backup(): void
    {
        $this->withEnv();
        $this->fakeWeb();
        $this->fakeTools();
        chmod($this->layout->storage, 0o775);

        $this->runSetup()
            ->expectsOutputToContain('Creating the tables and the initial data')
            ->expectsQuestion('The first administrator\'s user name', 'admin')
            ->expectsQuestion('Password', 'correct horse battery staple')
            ->expectsQuestion('Confirm password', 'correct horse battery staple')
            ->expectsOutputToContain('Administrator [admin] created.')
            ->expectsOutputToContain('The web serves the site as it should.')
            ->expectsConfirmation('Print a registration link for the first member?', 'yes')
            ->expectsQuestion('Their email address', 'first@example.com')
            ->expectsOutputToContain('registers as [first@example.com]')
            ->expectsOutputToContain($this->backupLineStart())
            ->expectsOutputToContain('Tried once: the copy holds the site\'s tables.')
            ->expectsOutputToContain("*/5 * * * * cd '{$this->layout->base}' && '".PHP_BINARY."' artisan schedule:run")
            ->assertSuccessful();

        $this->assertTrue(AdminUser::where('username', 'admin')->exists());
        $this->assertTrue(DB::table('navigations')->exists());
        $this->assertSame(0o770, fileperms($this->layout->storage) & 0o777);
        $this->assertSame([], glob($this->layout->dataDirectory().'/openpne-setup-*') ?: []);
        if ($this->sqlite()) {
            $this->assertSame(0o600, fileperms($this->sqlitePath()) & 0o777);
            Process::assertRan(fn (PendingProcess $p): bool => is_array($p->command) && str_starts_with((string) ($p->command[3] ?? ''), 'VACUUM INTO '));
        }
    }

    public function test_run_again_it_skips_what_is_done_and_asks_about_the_first_member(): void
    {
        $this->withEnv();
        $this->fakeWeb();
        $this->fakeTools();
        AdminUser::create(['username' => 'admin', 'password' => 'correct horse battery staple']);

        $this->runSetup()
            ->doesntExpectOutputToContain('user name')
            ->expectsConfirmation('Print a registration link for the first member?', 'no')
            ->assertSuccessful();
    }

    public function test_an_administrator_with_migrations_not_run_stops_before_migrating(): void
    {
        $this->withEnv();
        AdminUser::create(['username' => 'admin', 'password' => 'correct horse battery staple']);
        $migrations = $this->root.'/migrations';
        mkdir($migrations);
        file_put_contents($migrations.'/2999_01_01_000000_setup_test_table.php',
            "<?php\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Support\\Facades\\Schema;\nreturn new class extends Migration { public function up(): void { Schema::create('setup_test_table', fn (\$t) => \$t->id()); } };\n");
        $this->app->make('migrator')->path($migrations);

        $this->runSetup()
            ->expectsOutputToContain('a new version unpacked over a running site')
            ->doesntExpectOutputToContain('Creating the tables')
            ->assertFailed();

        $this->assertFalse(DB::getSchemaBuilder()->hasTable('setup_test_table'));
    }

    public function test_a_web_php_running_as_another_user_stops_before_the_directories_are_closed(): void
    {
        $this->withEnv();
        $this->fakeWeb(['runner' => 'other']);
        AdminUser::create(['username' => 'admin', 'password' => 'correct horse battery staple']);
        chmod($this->layout->storage, 0o775);

        $this->runSetup()
            ->expectsOutputToContain('This host cannot run the site')
            ->assertFailed();

        $this->assertSame(0o775, fileperms($this->layout->storage) & 0o777);
    }

    public function test_the_web_is_waited_for_with_the_document_root_named_once(): void
    {
        $this->withEnv();
        $this->fakeTools();
        AdminUser::create(['username' => 'admin', 'password' => 'correct horse battery staple']);
        $calls = 0;
        $this->fakeWeb(['unreachable' => function () use (&$calls): bool {
            return $calls++ < 1;
        }]);

        $this->runSetup()
            ->expectsOutputToContain('make the domain\'s document root '.$this->layout->public)
            ->expectsOutputToContain('cannot be reached')
            ->expectsConfirmation('Check again?', 'yes')
            ->expectsOutputToContain('The web serves the site as it should.')
            ->expectsConfirmation('Print a registration link for the first member?', 'no')
            ->assertSuccessful();
    }

    public function test_stopping_while_the_web_is_not_ready_leaves_the_rest_for_the_next_run(): void
    {
        $this->withEnv();
        $this->fakeWeb(['unreachable' => fn (): bool => true]);
        AdminUser::create(['username' => 'admin', 'password' => 'correct horse battery staple']);
        chmod($this->layout->storage, 0o775);

        $this->runSetup()
            ->expectsConfirmation('Check again?', 'no')
            ->expectsOutputToContain('it goes on from here')
            ->assertFailed();

        $this->assertSame(0o775, fileperms($this->layout->storage) & 0o777);
    }

    public function test_proxy_addresses_are_typed_never_taken_from_what_was_seen_and_set_alone(): void
    {
        $this->withEnv("APP_NAME=\"Example SNS\"\nTRUSTED_PROXIES=\"\"\nMAIL_PASSWORD='p#ss'\n");
        $this->fakeWeb(['proxy_headers' => ['X-Forwarded-For'], 'peer' => '198.51.100.7']);
        $this->fakeTools();
        AdminUser::create(['username' => 'admin', 'password' => 'correct horse battery staple']);

        $this->runSetup()
            ->expectsOutputToContain('nothing the check saw is used')
            ->doesntExpectOutputToContain('198.51.100.7')
            ->expectsChoice('TRUSTED_PROXIES', 'enter', ['later' => 'Later (the site works without it)', 'enter' => 'Enter the proxy\'s addresses'])
            ->expectsQuestion('The proxy\'s addresses', '*')
            ->expectsOutputToContain('That trusts every address')
            ->expectsQuestion('The proxy\'s addresses', '0.0.0.0/00')
            ->expectsQuestion('The proxy\'s addresses', '10.0.0.0/7')
            ->expectsOutputToContain('wider than a proxy\'s')
            ->expectsQuestion('The proxy\'s addresses', '203.0.113.0/24, 2001:db8::/32')
            ->expectsConfirmation('Write TRUSTED_PROXIES=203.0.113.0/24,2001:db8::/32 into .env?', 'yes')
            ->expectsConfirmation('Print a registration link for the first member?', 'no')
            ->assertSuccessful();

        $this->assertSame(
            "APP_NAME=\"Example SNS\"\nTRUSTED_PROXIES=\"203.0.113.0/24,2001:db8::/32\"\nMAIL_PASSWORD='p#ss'\n",
            file_get_contents($this->layout->envFile),
        );
    }

    public function test_a_set_trusted_proxies_is_not_asked_about_and_env_is_untouched(): void
    {
        $before = "APP_NAME=x\nTRUSTED_PROXIES=192.0.2.1\n";
        $this->withEnv($before);
        $this->fakeWeb(['proxy_headers' => ['X-Forwarded-For']]);
        $this->fakeTools();
        AdminUser::create(['username' => 'admin', 'password' => 'correct horse battery staple']);

        $this->runSetup()
            ->doesntExpectOutputToContain('nothing the check saw is used')
            ->expectsConfirmation('Print a registration link for the first member?', 'no')
            ->assertSuccessful();

        $this->assertSame($before, file_get_contents($this->layout->envFile));
    }

    public function test_an_administrator_password_refused_three_times_stops(): void
    {
        $this->withEnv();

        $command = $this->runSetup()->expectsQuestion('The first administrator\'s user name', 'admin');
        for ($i = 0; $i < 3; $i++) {
            $command->expectsQuestion('Password', 'short')->expectsQuestion('Confirm password', 'short');
        }
        $command->expectsOutputToContain('No administrator was created')->assertFailed();

        $this->assertFalse(AdminUser::query()->exists());
    }

    public function test_the_mysql_option_file_is_its_own_step_for_an_env_written_by_hand(): void
    {
        $this->withEnv();
        config([
            'database.connections.setup_mysql' => [
                'driver' => 'mysql', 'host' => 'db.example.com', 'port' => 1, 'database' => 'site_db',
                'username' => 'site', 'password' => 'a"b#c', 'unix_socket' => '', 'charset' => 'utf8mb4', 'prefix' => '',
            ],
            'database.default' => 'setup_mysql',
        ]);

        $this->runSetup()->expectsOutputToContain('The database does not answer')->assertFailed();

        $file = $this->layout->mysqlOptionFile();
        $this->assertSame(0o600, fileperms($file) & 0o777);
        $this->assertStringContainsString("[client]\nhost=\"db.example.com\"\nport=\"1\"\nuser=\"site\"\npassword=\"a\\\"b#c\"\n", (string) file_get_contents($file));
    }

    public function test_a_backup_that_leaves_a_copy_without_tables_is_said_to_fail(): void
    {
        $this->withEnv();
        $this->fakeWeb();
        $this->fakeTools(backupWorks: false);
        AdminUser::create(['username' => 'admin', 'password' => 'correct horse battery staple']);

        $this->runSetup()
            ->expectsConfirmation('Print a registration link for the first member?', 'no')
            ->expectsOutputToContain('Tried once, and it failed: '.($this->sqlite() ? 'Error: in prepare, no such table: migrations' : 'mysqldump: Got error: 1044'))
            ->assertSuccessful();
    }

    public function test_a_path_a_shell_would_read_shows_no_line(): void
    {
        (new Filesystem)->deleteDirectory($this->root);
        $this->useRoot(sys_get_temp_dir().'/openpne-setup-test-'.bin2hex(random_bytes(4)), 'it!s');
        putenv('PATH='.$this->layout->base.'/tools');
        $this->withEnv();
        $this->fakeWeb();
        $this->fakeTools();
        AdminUser::create(['username' => 'admin', 'password' => 'correct horse battery staple']);

        $this->runSetup()
            ->expectsConfirmation('Print a registration link for the first member?', 'no')
            ->expectsOutputToContain('The backup line is not shown')
            ->expectsOutputToContain('The cron line is not shown')
            ->doesntExpectOutputToContain('backup.sql')
            ->assertSuccessful();
        Process::assertNotRan(fn (PendingProcess $p): bool => is_array($p->command) && count($p->command) > 2);
    }

    private function runSetup(): PendingCommand
    {
        return $this->artisan('openpne:setup', ['--lang' => 'en']);
    }

    private function answerSite(PendingCommand $command): PendingCommand
    {
        return $command
            ->expectsQuestion('The site\'s address', self::URL)
            ->expectsQuestion('The site\'s name', 'Example SNS')
            ->expectsQuestion('Time zone', 'Asia/Tokyo');
    }

    /** @return array<string, string> */
    private function queueChoices(): array
    {
        return [
            'deferred' => 'Nothing can be kept running on this host (usual on a shared host)',
            'database' => 'I keep a queue worker running myself',
        ];
    }

    private function useRoot(string $root, string $site = 'site'): void
    {
        $this->root = $root;
        $base = $root.'/'.$site;
        foreach (['public', 'bootstrap/cache', 'storage/app/private', 'storage/app/public', 'storage/framework/cache/data',
            'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'tools'] as $directory) {
            mkdir($base.'/'.$directory, 0o755, true);
        }
        file_put_contents($base.'/.htaccess', self::HTACCESS);
        $this->layout = new SiteLayout($base, $base.'/public', $base.'/storage', $base.'/bootstrap/cache', $base.'/.env');
        $this->app->instance(SiteLayout::class, $this->layout);
    }

    private function tools(string ...$names): void
    {
        foreach ($names as $name) {
            $path = $this->layout->base.'/tools/'.$name;
            file_put_contents($path, "#!/bin/sh\nexit 0\n");
            chmod($path, 0o755);
        }
    }

    /** The test database stands in for the site's; the SQLite path is only what the backup line names. */
    private function withEnv(string $contents = "APP_ENV=production\n"): void
    {
        $this->tools('sqlite3', 'mysqldump');
        file_put_contents($this->layout->envFile, $contents);
        chmod($this->layout->envFile, 0o600);
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        if (config('database.connections.'.$this->connection.'.driver') === 'sqlite') {
            config(['database.connections.'.$this->connection.'.database' => $this->sqlitePath()]);
        }
    }

    private function sqlitePath(): string
    {
        return $this->layout->dataDirectory().'/database.sqlite';
    }

    private function sqlite(): bool
    {
        return config('database.connections.'.$this->connection.'.driver') === 'sqlite';
    }

    private function backupLineStart(): string
    {
        $data = $this->layout->dataDirectory();
        $tools = $this->layout->base.'/tools';

        return $this->sqlite()
            ? "rm -f '{$data}/backup.sqlite.new' && '{$tools}/sqlite3' -readonly '{$this->sqlitePath()}'"
            : "rm -f '{$data}/backup.sql.new' && '{$tools}/mysqldump' --defaults-extra-file='{$this->layout->mysqlOptionFile()}'";
    }

    private function fakeTools(bool $backupWorks = true): void
    {
        Process::fake(function (PendingProcess $process) use ($backupWorks) {
            $command = is_array($process->command) ? $process->command : [$process->command];
            $last = (string) end($command);

            return match (true) {
                $last === '-version' => Process::result('3.46.1 2024-08-13 09:16:08 c9c2ab54ba'),
                str_ends_with((string) $command[0], 'mysqldump') => $backupWorks
                    ? Process::result("CREATE TABLE `migrations` (\n")
                    : Process::result('', "mysqldump: Got error: 1044: Access denied for user 'site'@'%' to database 'site_db'\n", 2),
                str_starts_with($last, 'SELECT') => $backupWorks
                    ? Process::result("80\n")
                    : Process::result('', 'Error: in prepare, no such table: migrations', 1),
                default => Process::result(),
            };
        });
    }

    /**
     * A web server serving public/ at APP_URL, its PHP answering as the test's own user unless `runner` is `other`.
     *
     * @param  array<string, mixed>  $web
     */
    private function fakeWeb(array $web = []): void
    {
        $web += ['runner' => 'self', 'proxy_headers' => [], 'peer' => '127.0.0.1', 'unreachable' => fn (): bool => false];
        $layout = $this->layout;
        Http::swap(new HttpFactory($this->app->make(Dispatcher::class)));

        Http::fake(function (Request $request) use ($web, $layout) {
            $path = substr($request->url(), strlen(self::URL));
            if (str_ends_with($path, '.txt') && ($web['unreachable'])()) {
                return (Http::failedConnection())($request);
            }
            if ($path === '/up') {
                return Http::response('{}', 200);
            }
            $file = $layout->public.$path;
            if (! str_ends_with($path, '.php')) {
                return is_file($file) ? Http::response((string) file_get_contents($file), 200) : Http::response('', 404);
            }

            $source = (string) file_get_contents($file);
            preg_match("/\\\$token = '([0-9a-f]{32})';/", $source, $token);
            preg_match("/\\\$ownerFile = '([^']+)';/", $source, $owner);
            if (($request->header(WebCheck::HEADER)[0] ?? null) !== $token[1]) {
                return Http::response('', 404);
            }
            $web['runner'] === 'other' ? symlink('/', $owner[1]) : touch($owner[1]);

            return Http::response((string) json_encode([
                'token' => $token[1], 'version' => '8.3.30', 'sapi' => 'fpm-fcgi',
                'extensions' => [...BeforeInstallCheck::EXTENSIONS, 'pdo_sqlite', 'pdo_mysql'],
                'ini' => ['memory_limit' => '256M', 'upload_max_filesize' => '16M', 'post_max_size' => '128M', 'max_execution_time' => '30', 'opcache.file_cache' => ''],
                'wrote' => true, 'proxy_headers' => $web['proxy_headers'], 'peer' => $web['peer'],
            ]), 200);
        });
    }
}
