<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\AdminUser;
use App\Models\Member;
use App\Setup\EnvFile;
use App\Setup\SetupHost;
use App\Setup\SetupLock;
use App\SiteCheck\BeforeInstallCheck;
use App\SiteCheck\SiteLayout;
use App\SiteCheck\SqliteSupport;
use App\SiteCheck\WebCheck;
use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Arr;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesDatabaseServer;
use Tests\TestCase;

class SetupCommandTest extends TestCase
{
    use FakesDatabaseServer;
    use RefreshDatabase;

    private const URL = 'https://sns.example.com';

    private string $root;

    private SiteLayout $layout;

    private string|false $path;

    private string $connection;

    /** @var array{interactive: bool, spawn: bool, hide: bool, code: int, spawned: list<array{list<string>, array<string, string>, mixed}>} */
    private array $hostState = ['interactive' => true, 'spawn' => true, 'hide' => true, 'code' => 0, 'spawned' => []];

    /** @var array<string, array{mixed, mixed, string|false}> */
    private array $shell = [];

    /** @var array{extension: bool, library: ?string} */
    private array $sqliteState = ['extension' => false, 'library' => null];

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

            public function spawn(array $command, array $environment, string $directory, mixed $lock): int
            {
                $this->state['spawned'][] = [$command, $environment, $lock];

                return $this->state['code'];
            }
        });
        $sqlite = &$this->sqliteState;
        $this->app->instance(SqliteSupport::class, new class($sqlite) extends SqliteSupport
        {
            /** @param array{extension: bool, library: ?string} $state */
            public function __construct(private array &$state) {}

            public function hasExtension(): bool
            {
                return $this->state['extension'];
            }

            public function libraryVersion(): ?string
            {
                return $this->state['library'] ?? parent::libraryVersion();
            }
        });
        config(['app.url' => self::URL, 'trustedproxy.proxies' => []]);
    }

    protected function tearDown(): void
    {
        putenv('PATH='.$this->path);
        putenv('APP_NAME');
        foreach ($this->shell as $key => [$env, $server, $put]) {
            $env === null ? Arr::forget($_ENV, $key) : $_ENV[$key] = $env;
            $server === null ? Arr::forget($_SERVER, $key) : $_SERVER[$key] = $server;
            putenv($put === false ? $key : "{$key}={$put}");
        }
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
        $this->sqliteState['extension'] = true;
        $this->tools('mysqldump');
        putenv('APP_NAME=from the shell');
        mkdir($this->layout->dataDirectory(), 0o755);
        // A copy kept under a name of its own is not a site started over.
        $copy = new \PDO('sqlite:'.$this->layout->dataDirectory().'/database-2026-11-21.sqlite');
        $copy->exec('CREATE TABLE admin_users (id INTEGER PRIMARY KEY); INSERT INTO admin_users VALUES (1);');
        $copy = null;

        $this->answerSite($this->runSetup())
            ->expectsChoice('Database', 'sqlite', ['sqlite' => 'SQLite (a file in openpne-data/)', 'mysql' => 'MySQL or MariaDB (the host\'s database server)'])
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
        $this->assertSame(32, strlen((string) base64_decode(substr((string) $env['APP_KEY'], strlen('base64:')), true)));
        $this->assertSame(0o600, fileperms($this->layout->envFile) & 0o777);
        $this->assertSame(0o700, fileperms($data) & 0o777);
        $this->assertSame(SiteLayout::DENY, file_get_contents($data.'/.htaccess'));

        $this->assertCount(1, $this->hostState['spawned']);
        [$command, $environment, $lock] = $this->hostState['spawned'][0];
        $this->assertSame(stat($this->layout->storage.'/framework/'.SetupLock::FILE)['ino'], fstat($lock)['ino']);
        $this->assertSame([PHP_BINARY, base_path('artisan'), 'openpne:setup', '--lang=en'], $command);
        $this->assertArrayNotHasKey('APP_NAME', $environment);
        $this->assertSame($this->layout->base.'/tools', $environment['PATH']);
    }

    public function test_a_refused_answer_is_asked_again_and_never_written(): void
    {
        $this->sqliteState['extension'] = true;

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
            ->expectsConfirmation('Go on with MySQL or MariaDB?', 'yes')
            ->expectsOutputToContain('SQLite cannot be used here: PHP on the command line has no sqlite3 extension, which the backup uses.')
            ->expectsOutputToContain('The database is MySQL or MariaDB, as SQLite cannot be used here (the check above says why). It needs a database on the host\'s MySQL server 8.0.17 or later, or MariaDB server 10.11 or later.')
            ->expectsQuestion('Database server', '127.0.0.1')
            ->expectsQuestion('Port', '1')
            ->expectsQuestion('Database name', 'site_db')
            ->expectsQuestion('User name', 'site')
            ->expectsQuestion('Password', 'Sekret-9x')
            ->expectsOutputToContain('The database server refused')
            ->doesntExpectOutputToContain('Sekret-9x')
            ->expectsConfirmation('Enter them again?', 'no')
            ->assertFailed();

        $this->assertFileDoesNotExist($this->layout->envFile);
        $this->assertSame([], $this->hostState['spawned']);
    }

    public function test_mysql_answers_that_connect_are_written(): void
    {
        $settings = (array) config('database.connections.'.$this->connection);
        if (! in_array($settings['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Needs the MySQL or MariaDB test connection.');
        }
        $this->tools('mysqldump');

        $this->answerSite($this->runSetup())
            ->expectsConfirmation('Go on with MySQL or MariaDB?', 'yes')
            ->expectsQuestion('Database server', (string) $settings['host'])
            ->expectsQuestion('Port', (string) $settings['port'])
            ->expectsQuestion('Database name', (string) $settings['database'])
            ->expectsQuestion('User name', (string) $settings['username'])
            ->expectsQuestion('Password', (string) $settings['password'])
            ->expectsChoice('Mail', 'later', ['later' => 'Later (nothing is sent until then)', 'smtp' => 'An SMTP server'])
            ->expectsChoice('Background work', 'deferred', $this->queueChoices())
            ->assertSuccessful();

        $env = EnvFile::read((string) file_get_contents($this->layout->envFile));
        $this->assertSame([(string) $settings['driver'], (string) $settings['database']], [$env['DB_CONNECTION'], $env['DB_DATABASE']]);
    }

    public function test_a_mysql_database_holding_a_site_is_not_set_up_over(): void
    {
        $settings = (array) config('database.connections.'.$this->connection);
        if (! in_array($settings['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Needs the MySQL or MariaDB test connection.');
        }
        $this->tools('mysqldump');
        // Committed past the test's transaction, as setup reads through a connection of its own.
        $site = new \PDO("mysql:host={$settings['host']};port={$settings['port']};dbname={$settings['database']}", (string) $settings['username'], (string) $settings['password']);
        $site->exec("INSERT INTO admin_users (username, password) VALUES ('holds-a-site', 'x')");

        try {
            $this->answerSite($this->runSetup())
                ->expectsConfirmation('Go on with MySQL or MariaDB?', 'yes')
                ->expectsQuestion('Database server', (string) $settings['host'])
                ->expectsQuestion('Port', (string) $settings['port'])
                ->expectsQuestion('Database name', (string) $settings['database'])
                ->expectsQuestion('User name', (string) $settings['username'])
                ->expectsQuestion('Password', (string) $settings['password'])
                ->expectsOutputToContain("The database {$settings['database']} holds a site already.")
                ->expectsConfirmation('Enter them again?', 'no')
                ->assertFailed()
                ->run();
        } finally {
            $site->exec("DELETE FROM admin_users WHERE username = 'holds-a-site'");
        }

        $this->assertFileDoesNotExist($this->layout->envFile);
    }

    public function test_a_mariadb_server_given_for_mysql_is_named_and_sqlite_offered_again(): void
    {
        $this->sqliteState['extension'] = true;
        $this->tools('mysqldump');
        $this->fakeDatabaseServer('10.6.28-MariaDB-ubu2204');
        $databases = ['sqlite' => 'SQLite (a file in openpne-data/)', 'mysql' => 'MySQL or MariaDB (the host\'s database server)'];

        $this->answerSite($this->runSetup())
            ->expectsChoice('Database', 'mysql', $databases)
            ->expectsQuestion('Database server', 'db.example.com')
            ->expectsQuestion('Port', '3306')
            ->expectsQuestion('Database name', 'site_db')
            ->expectsQuestion('User name', 'site')
            ->expectsQuestion('Password', 'Sekret-9x')
            ->expectsOutputToContain('The server is MariaDB 10.6.28, and OpenPNE runs on MariaDB 10.11 or later only. Choose SQLite.')
            ->doesntExpectOutputToContain('The database server refused')
            ->expectsChoice('Database', 'sqlite', $databases)
            ->expectsChoice('Mail', 'later', ['later' => 'Later (nothing is sent until then)', 'smtp' => 'An SMTP server'])
            ->expectsChoice('Background work', 'deferred', $this->queueChoices())
            ->assertSuccessful();

        $env = EnvFile::read((string) file_get_contents($this->layout->envFile));
        $this->assertSame('sqlite', $env['DB_CONNECTION']);
        $this->assertArrayNotHasKey('DB_HOST', array_filter($env));
    }

    public function test_a_mariadb_server_on_a_host_without_sqlite_asks_for_another_server(): void
    {
        $this->tools('mysqldump');
        $this->fakeDatabaseServer('10.6.28-MariaDB-ubu2204');

        $this->answerSite($this->runSetup())
            ->expectsConfirmation('Go on with MySQL or MariaDB?', 'yes')
            ->expectsQuestion('Database server', 'db.example.com')
            ->expectsQuestion('Port', '3306')
            ->expectsQuestion('Database name', 'site_db')
            ->expectsQuestion('User name', 'site')
            ->expectsQuestion('Password', 'Sekret-9x')
            ->expectsOutputToContain('The server is MariaDB 10.6.28, and OpenPNE runs on MariaDB 10.11 or later only. Enter a MySQL server 8.0.17 or later, or a MariaDB server 10.11 or later, if the host has one.')
            ->expectsConfirmation('Enter them again?', 'no')
            ->assertFailed();

        $this->assertFileDoesNotExist($this->layout->envFile);
    }

    public function test_a_php_without_the_sqlite3_extension_and_no_mysql_fails_the_check_before_any_question(): void
    {
        $this->runSetup()
            ->expectsOutputToContain('SQLite cannot be used here: PHP on the command line has no sqlite3 extension, which the backup uses.')
            ->expectsOutputToContain('No database can be chosen for a new site here')
            ->expectsOutputToContain('Put right what is marked [FAIL] above')
            ->assertFailed();

        $this->assertFileDoesNotExist($this->layout->envFile);
    }

    /** @return array<string, array{string}> */
    public static function sqliteTooOld(): array
    {
        return [
            'as on Enterprise Linux 7' => ['3.7.17'],
            'just below' => ['3.25.3'],
        ];
    }

    #[DataProvider('sqliteTooOld')]
    public function test_a_sqlite_too_old_is_named_by_what_it_lacks_and_mysql_is_asked_about_before_its_server(string $library): void
    {
        $this->sqliteState['extension'] = true;
        $this->tools('mysqldump');
        $this->sqliteState['library'] = $library;

        $setup = $this->runSetup()
            ->expectsOutputToContain('Databases: MySQL or MariaDB only')
            ->expectsOutputToContain("SQLite cannot be used here: pdo_sqlite on the command line runs on SQLite {$library}, and OpenPNE needs 3.26.0 or later.");
        foreach (['has no pdo_sqlite', 'has no sqlite3 extension'] as $line) {
            $setup->doesntExpectOutputToContain($line);
        }
        $this->answerSite($setup)
            ->expectsOutputToContain('The database is MySQL or MariaDB, as SQLite cannot be used here')
            ->expectsConfirmation('Go on with MySQL or MariaDB?', 'no')
            ->expectsOutputToContain('.env has not been written.')
            ->assertFailed();

        $this->assertFileDoesNotExist($this->layout->envFile);
    }

    public function test_sqlite_at_the_versions_it_needs_is_chosen_without_a_question_about_mysql(): void
    {
        $this->sqliteState['extension'] = true;
        $this->sqliteState['library'] = '3.26.0';

        $this->answerSite($this->runSetup())
            ->doesntExpectOutputToContain('SQLite cannot be used here')
            ->expectsOutputToContain('The database is SQLite.')
            ->expectsChoice('Mail', 'later', ['later' => 'Later (nothing is sent until then)', 'smtp' => 'An SMTP server'])
            ->expectsChoice('Background work', 'deferred', $this->queueChoices())
            ->assertSuccessful();

        $this->assertSame('sqlite', EnvFile::read((string) file_get_contents($this->layout->envFile))['DB_CONNECTION']);
    }

    public function test_a_host_without_proc_open_writes_env_and_asks_to_be_run_again(): void
    {
        $this->sqliteState['extension'] = true;
        $this->hostState['spawn'] = false;

        $this->answerSite($this->runSetup())
            ->expectsChoice('Mail', 'later', ['later' => 'Later (nothing is sent until then)', 'smtp' => 'An SMTP server'])
            ->expectsChoice('Background work', 'deferred', $this->queueChoices())
            ->expectsOutputToContain('Run the same command again to continue')
            ->assertFailed();

        $this->assertFileExists($this->layout->envFile);
        $this->assertSame([], $this->hostState['spawned']);
    }

    public function test_the_first_process_fails_when_the_one_it_started_does(): void
    {
        $this->sqliteState['extension'] = true;
        $this->hostState['code'] = 1;

        $this->answerSite($this->runSetup())
            ->expectsChoice('Mail', 'later', ['later' => 'Later (nothing is sent until then)', 'smtp' => 'An SMTP server'])
            ->expectsChoice('Background work', 'deferred', $this->queueChoices())
            ->assertFailed();

        $this->assertCount(1, $this->hostState['spawned']);
    }

    public function test_a_password_this_host_would_show_stops_the_command_when_declined(): void
    {
        $this->tools('mysqldump');
        $this->hostState['spawn'] = false;
        $this->hostState['hide'] = false;

        $this->answerSite($this->runSetup())
            ->expectsConfirmation('Go on with MySQL or MariaDB?', 'yes')
            ->expectsQuestion('Database server', '127.0.0.1')
            ->expectsQuestion('Port', '3306')
            ->expectsQuestion('Database name', 'site_db')
            ->expectsQuestion('User name', 'site')
            ->expectsOutputToContain('a password shows on the screen')
            ->expectsConfirmation('Go on, with the password showing as you type?', 'no')
            ->assertFailed();

        $this->assertFileDoesNotExist($this->layout->envFile);
    }

    public function test_a_password_this_host_would_show_is_asked_about_once_when_agreed_to(): void
    {
        $this->tools('mysqldump');
        $this->hostState['spawn'] = false;
        $this->hostState['hide'] = false;

        $command = $this->answerSite($this->runSetup())->expectsConfirmation('Go on with MySQL or MariaDB?', 'yes');
        foreach ([true, false] as $first) {
            $command->expectsQuestion('Database server', '127.0.0.1')
                ->expectsQuestion('Port', '1')
                ->expectsQuestion('Database name', 'site_db')
                ->expectsQuestion('User name', 'site');
            if ($first) {
                $command->expectsConfirmation('Go on, with the password showing as you type?', 'yes');
            }
            $command->expectsQuestion('Password', 'x')
                ->expectsConfirmation('Enter them again?', $first ? 'yes' : 'no');
        }
        $command->assertFailed();
    }

    public function test_prompts_hide_a_password_through_proc_open_where_symfony_cannot(): void
    {
        $this->tools('mysqldump');
        $this->hostState['hide'] = false;

        $this->answerSite($this->runSetup())
            ->expectsConfirmation('Go on with MySQL or MariaDB?', 'yes')
            ->expectsQuestion('Database server', '127.0.0.1')
            ->expectsQuestion('Port', '1')
            ->expectsQuestion('Database name', 'site_db')
            ->expectsQuestion('User name', 'site')
            ->doesntExpectOutputToContain('a password shows on the screen')
            ->expectsQuestion('Password', 'x')
            ->expectsConfirmation('Enter them again?', 'no')
            ->assertFailed();
    }

    /** @return array<string, array{string}> */
    public static function sqliteSites(): array
    {
        return ['the first' => ['database.sqlite'], 'one started over' => ['database-2.sqlite']];
    }

    #[DataProvider('sqliteSites')]
    public function test_a_sqlite_database_holding_a_site_is_not_set_up_over(string $name): void
    {
        $this->sqliteState['extension'] = true;
        mkdir($this->layout->dataDirectory(), 0o700);
        touch($this->sqlitePath());
        $database = new \PDO('sqlite:'.$this->layout->dataDirectory().'/'.$name);
        $database->exec('CREATE TABLE admin_users (id INTEGER PRIMARY KEY); INSERT INTO admin_users VALUES (1);');
        $database = null;

        $this->answerSite($this->runSetup())
            ->expectsOutputToContain($this->layout->dataDirectory().'/'.$name.' holds a site already')
            ->assertFailed();

        $this->assertFileDoesNotExist($this->layout->envFile);
    }

    public function test_a_second_run_stops_at_once_while_one_holds_the_lock(): void
    {
        $lock = fopen($this->layout->storage.'/framework/'.SetupLock::FILE, 'c');
        $this->assertNotFalse($lock);
        flock($lock, LOCK_EX);

        try {
            $this->runSetup()->expectsOutputToContain('running for this site already')->assertFailed();
        } finally {
            fclose($lock);
        }
        $this->assertDirectoryDoesNotExist($this->layout->dataDirectory());
    }

    public function test_a_parent_directory_that_cannot_be_written_stops_before_any_question(): void
    {
        $this->sqliteState['extension'] = true;
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

    public function test_an_empty_env_is_not_taken_for_one_written_and_what_a_killed_run_left_is_cleared(): void
    {
        file_put_contents($this->layout->envFile, '');
        mkdir($this->layout->dataDirectory(), 0o700);
        $left = [$this->layout->base.'/.env.openpne-setup-0123456789abcdef', $this->layout->dataDirectory().'/openpne-setup-0123456789abcdef-wal'];
        $kept = $this->layout->dataDirectory().'/openpne-setup-notes';
        array_map(touch(...), [...$left, $kept]);

        $this->runSetup()
            ->expectsOutputToContain('empty or without APP_URL or DB_CONNECTION')
            ->doesntExpectOutputToContain('Creating the tables')
            ->assertFailed();

        $this->assertSame('', file_get_contents($this->layout->envFile));
        $this->assertSame([false, false, true], array_map(file_exists(...), [...$left, $kept]));
    }

    public function test_a_connection_setting_held_by_the_shell_stops_before_the_database_is_touched(): void
    {
        $this->withEnv();
        $this->shellEnv('DB_URL', 'sqlite:///'.$this->root.'/other.sqlite');

        $this->runSetup()
            ->expectsOutputToContain('DB_URL in this shell is not what '.$this->layout->envFile.' says')
            ->doesntExpectOutputToContain('Creating the tables')
            ->assertFailed();

        $this->assertFileDoesNotExist($this->root.'/other.sqlite');
    }

    public function test_an_empty_app_key_is_set_through_a_file_beside_env(): void
    {
        $this->shellEnv('APP_KEY', '');
        $this->withEnv();
        config(['app.key' => '']);
        $this->hostState['hide'] = false;

        $this->runSetup()
            ->expectsOutputToContain('Set APP_KEY in .env.')
            ->expectsQuestion('The first administrator\'s user name', 'admin')
            ->expectsConfirmation('Go on, with the password showing as you type?', 'no')
            ->assertFailed();

        $key = (string) EnvFile::read((string) file_get_contents($this->layout->envFile))['APP_KEY'];
        $this->assertStringStartsWith('base64:', $key);
        $this->assertSame($key, config('app.key'));
        $this->assertSame([], glob($this->layout->base.'/.env.*') ?: []);
    }

    public function test_an_empty_app_key_over_a_database_holding_a_site_is_not_replaced(): void
    {
        $this->shellEnv('APP_KEY', '');
        $before = $this->withEnv();
        config(['app.key' => '']);
        AdminUser::create(['username' => 'admin', 'password' => 'correct horse battery staple']);

        $this->runSetup()
            ->expectsOutputToContain('has no APP_KEY, but the database holds a site already')
            ->doesntExpectOutputToContain('Set APP_KEY in .env.')
            ->assertFailed();

        $this->assertSame($before, file_get_contents($this->layout->envFile));
    }

    public function test_an_open_data_directory_and_an_empty_htaccess_are_put_right(): void
    {
        $this->withEnv();
        $this->fakeWeb();
        AdminUser::create(['username' => 'admin', 'password' => 'correct horse battery staple']);
        mkdir($this->layout->dataDirectory(), 0o755);
        touch($this->layout->dataDirectory().'/.htaccess');
        $this->fakeTools();

        $this->runSetup()
            ->expectsConfirmation('Print a registration link for the first member?', 'no')
            ->assertSuccessful();

        $this->assertSame(0o700, fileperms($this->layout->dataDirectory()) & 0o777);
        $this->assertSame(SiteLayout::DENY, file_get_contents($this->layout->dataDirectory().'/.htaccess'));
    }

    public function test_a_data_directory_htaccess_that_does_not_deny_is_replaced_where_the_top_one_was_removed(): void
    {
        $this->withEnv();
        $this->fakeWeb();
        AdminUser::create(['username' => 'admin', 'password' => 'correct horse battery staple']);
        mkdir($this->layout->dataDirectory(), 0o700);
        file_put_contents($this->layout->dataDirectory().'/.htaccess', "RewriteEngine On\n");
        $this->fakeTools();
        unlink($this->layout->base.'/.htaccess');

        $this->runSetup()
            ->expectsConfirmation('Print a registration link for the first member?', 'no')
            ->assertSuccessful();

        $this->assertSame(SiteLayout::DENY, file_get_contents($this->layout->dataDirectory().'/.htaccess'));
    }

    /** @return array<string, array{Closure(Blueprint): mixed, string}> */
    public static function stoppedMigrations(): array
    {
        return [
            'inside a create' => [fn (Blueprint $t) => null, "Schema::create('setup_test_items', fn (\$t) => \$t->id());"],
            'inside an alter' => [fn (Blueprint $t) => $t->string('label')->nullable(), "Schema::table('setup_test_items', fn (\$t) => \$t->string('label')->nullable());"],
            'inside an index' => [function (Blueprint $t) {
                $t->string('label')->nullable();
                $t->index('label', 'setup_test_items_label');
            }, "Schema::table('setup_test_items', fn (\$t) => \$t->index('label', 'setup_test_items_label'));"],
        ];
    }

    /**
     * @param  Closure(Blueprint): mixed  $made
     */
    #[DataProvider('stoppedMigrations')]
    public function test_a_migration_stopped_midway_is_said_with_how_to_start_over_while_nobody_has_registered(Closure $made, string $up): void
    {
        $this->withEnv();
        // What the migration makes is there and the migration unrecorded, as a run killed after its statement leaves it.
        Schema::create('setup_test_items', function (Blueprint $t) use ($made) {
            $t->id();
            $made($t);
        });
        $this->migration($up);

        try {
            $this->runSetup()
                ->expectsOutputToContain('Creating them failed')
                ->expectsOutputToContain('Nobody has registered yet')
                ->doesntExpectOutputToContain('remove')
                ->assertFailed();
        } finally {
            // MySQL commits a table made inside the test's transaction.
            Schema::dropIfExists('setup_test_items');
        }
    }

    public function test_a_sqlite_start_over_is_into_an_unused_file_with_the_one_given_left_as_it_is(): void
    {
        $this->withEnv();
        if (config('database.connections.'.$this->connection.'.driver') !== 'sqlite') {
            $this->markTestSkipped('The file is the SQLite database\'s.');
        }
        mkdir($this->layout->dataDirectory(), 0o700);
        symlink($this->layout->base.'/public/leaked.sqlite', $this->layout->dataDirectory().'/database-2.sqlite');
        touch($this->layout->dataDirectory().'/database-3.sqlite');
        $this->migration("throw new RuntimeException('stopped');");

        $this->runSetup()
            ->expectsOutputToContain('put '.$this->layout->dataDirectory().'/database-4.sqlite in .env as DB_DATABASE, then run this command again. '.$this->layout->dataDirectory().'/database.sqlite is left as it is.')
            ->doesntExpectOutputToContain('remove')
            ->assertFailed();
    }

    public function test_a_broken_link_named_as_the_sqlite_database_is_never_followed(): void
    {
        $this->withEnv();
        if (! $this->sqlite()) {
            $this->markTestSkipped('The file is the SQLite database\'s.');
        }
        mkdir($this->layout->dataDirectory(), 0o700);
        $link = $this->layout->dataDirectory().'/database-2.sqlite';
        symlink($this->layout->base.'/public/leaked.sqlite', $link);
        config(['database.connections.'.$this->connection.'.database' => $link]);

        $this->runSetup()
            ->expectsOutputToContain($link.' could not be made')
            ->assertFailed();

        $this->assertFileDoesNotExist($this->layout->base.'/public/leaked.sqlite');
    }

    public function test_a_failed_install_with_members_in_is_not_offered_a_start_over(): void
    {
        $this->withEnv();
        Member::factory()->create();
        $this->migration("throw new RuntimeException('stopped');");

        $this->runSetup()
            ->expectsOutputToContain('do not remove it')
            ->doesntExpectOutputToContain('Nobody has registered yet')
            ->assertFailed();
    }

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
            ->expectsOutputToContain('The copy is written to '.$this->layout->dataDirectory().($this->sqlite() ? '/backup.sqlite,' : '/backup.sql,'))
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

    public function test_a_check_that_cannot_tell_is_not_taken_for_one_that_passed(): void
    {
        $this->withEnv();
        $this->fakeWeb();
        AdminUser::create(['username' => 'admin', 'password' => 'correct horse battery staple']);
        mkdir($this->layout->storage.'/framework/'.WebCheck::LOCK_FILE);

        $this->runSetup()
            ->expectsConfirmation('Check again?', 'no')
            ->doesntExpectOutputToContain('The web serves the site as it should.')
            ->assertFailed();
    }

    public function test_an_administrator_password_this_host_would_show_is_asked_about_first(): void
    {
        $this->withEnv();
        $this->hostState['hide'] = false;

        $this->runSetup()
            ->expectsQuestion('The first administrator\'s user name', 'admin')
            ->expectsConfirmation('Go on, with the password showing as you type?', 'no')
            ->assertFailed();

        $this->assertFalse(AdminUser::query()->exists());
    }

    public function test_proxy_addresses_are_typed_never_taken_from_what_was_seen_and_set_alone(): void
    {
        $before = $this->withEnv("APP_NAME=\"Example SNS\"\nTRUSTED_PROXIES=\"\"\nMAIL_PASSWORD='p#ss'\n");
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
            str_replace('TRUSTED_PROXIES=""', 'TRUSTED_PROXIES="203.0.113.0/24,2001:db8::/32"', $before),
            file_get_contents($this->layout->envFile),
        );
    }

    public function test_trusted_proxies_read_as_empty_by_the_config_is_asked_about(): void
    {
        $this->withEnv("TRUSTED_PROXIES=null\n");
        $this->fakeWeb(['proxy_headers' => ['X-Forwarded-For']]);
        $this->fakeTools();
        AdminUser::create(['username' => 'admin', 'password' => 'correct horse battery staple']);

        $this->runSetup()
            ->expectsChoice('TRUSTED_PROXIES', 'later', ['later' => 'Later (the site works without it)', 'enter' => 'Enter the proxy\'s addresses'])
            ->expectsConfirmation('Print a registration link for the first member?', 'no')
            ->assertSuccessful();
    }

    public function test_a_set_trusted_proxies_is_not_asked_about_and_env_is_untouched(): void
    {
        $before = $this->withEnv("APP_NAME=x\nTRUSTED_PROXIES=192.0.2.1\n");
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

    public function test_an_env_naming_a_mariadb_server_stops_before_anything_is_written_or_installed(): void
    {
        $this->withEnv();
        // A port nothing answers on, so connecting before asking the server would end in a refusal instead.
        config([
            'database.connections.setup_mysql' => [
                'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'site_db',
                'username' => 'site', 'password' => '', 'unix_socket' => '', 'charset' => 'utf8mb4', 'prefix' => '',
            ],
            'database.default' => 'setup_mysql',
        ]);
        $this->fakeDatabaseServer('10.6.28-MariaDB-ubu2204');

        $this->runSetup()
            ->expectsOutputToContain('The database server is MariaDB 10.6.28, and OpenPNE runs on MariaDB 10.11 or later only. Change the DB_ settings in '.$this->layout->envFile)
            ->doesntExpectOutputToContain('Creating the tables')
            ->assertFailed();

        $this->assertFileDoesNotExist($this->layout->mysqlOptionFile());
    }

    public function test_an_env_naming_the_mysql_driver_for_a_mariadb_server_is_told_the_driver(): void
    {
        $this->withEnv();
        config([
            'database.connections.setup_mysql' => [
                'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'site_db',
                'username' => 'site', 'password' => '', 'unix_socket' => '', 'charset' => 'utf8mb4', 'prefix' => '',
            ],
            'database.default' => 'setup_mysql',
        ]);
        $this->fakeDatabaseServer('11.8.9-MariaDB-ubu2404');

        $this->runSetup()
            ->expectsOutputToContain('The database server is MariaDB 11.8.9. Set DB_CONNECTION=mariadb in '.$this->layout->envFile)
            ->doesntExpectOutputToContain('Creating the tables')
            ->assertFailed();

        $this->assertFileDoesNotExist($this->layout->mysqlOptionFile());
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
        $this->fakeTools('fails');
        AdminUser::create(['username' => 'admin', 'password' => 'correct horse battery staple']);

        $this->runSetup()
            ->expectsConfirmation('Print a registration link for the first member?', 'no')
            ->expectsOutputToContain('Tried once, and it failed: '.($this->sqlite() ? $this->sqlitePath().' could not be copied: file is not a database. Until it works' : 'mysqldump: Got error: 1044'))
            ->assertSuccessful();
    }

    public function test_a_backup_that_runs_but_copies_no_table_is_said_to_fail(): void
    {
        $this->withEnv();
        $this->fakeWeb();
        $this->fakeTools('empty');
        AdminUser::create(['username' => 'admin', 'password' => 'correct horse battery staple']);

        $this->runSetup()
            ->expectsConfirmation('Print a registration link for the first member?', 'no')
            ->expectsOutputToContain('Tried once, and it failed: the copy holds no tables.')
            ->assertSuccessful();

        $this->assertSame([], glob($this->layout->dataDirectory().'/openpne-setup-*') ?: []);
    }

    public function test_a_rerun_without_the_sqlite3_extension_shows_no_line(): void
    {
        if (! $this->sqlite()) {
            $this->markTestSkipped('The site is on MySQL.');
        }
        $this->withEnv();
        $this->fakeWeb();
        $this->fakeTools();
        $this->sqliteState['extension'] = false;
        AdminUser::create(['username' => 'admin', 'password' => 'correct horse battery staple']);

        $this->runSetup()
            ->expectsConfirmation('Print a registration link for the first member?', 'no')
            ->expectsOutputToContain('PHP on the command line has no sqlite3 extension, and the backup of an SQLite database needs it. Ask the host for it.')
            ->doesntExpectOutputToContain('openpne:backup-sqlite')
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
            ->expectsOutputToContain('The backup line is not shown: '.$this->layout->base)
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
        file_put_contents($base.'/.htaccess', SiteLayout::DENY);
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

    /**
     * The test database stands in for the site's; the SQLite path is only the file setup's trial copies from.
     * The connection keys are what the test's environment holds, which setup requires `.env` to match.
     */
    private function withEnv(string $more = ''): string
    {
        $this->sqliteState['extension'] = true;
        $this->tools('mysqldump');
        $contents = 'APP_URL='.self::URL."\n";
        foreach (['APP_KEY', 'DB_CONNECTION', 'DB_URL', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_SOCKET'] as $key) {
            if (Env::get($key) !== null) {
                $contents .= $key.'="'.Env::get($key)."\"\n";
            }
        }
        $contents .= $more;
        file_put_contents($this->layout->envFile, $contents);
        chmod($this->layout->envFile, 0o600);
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        if (config('database.connections.'.$this->connection.'.driver') === 'sqlite') {
            config(['database.connections.'.$this->connection.'.database' => $this->sqlitePath()]);
        }

        return $contents;
    }

    /** Sets $key as a shell would before running the command, undone in tearDown. */
    private function shellEnv(string $key, string $value): void
    {
        $this->shell[$key] ??= [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
        $_ENV[$key] = $_SERVER[$key] = $value;
        putenv("{$key}={$value}");
    }

    /** A migration ordered after the site's own, so the one openpne:install has left to run. */
    private function migration(string $up): void
    {
        $migrations = $this->root.'/migrations';
        mkdir($migrations);
        file_put_contents($migrations.'/2999_01_01_000000_setup_test.php',
            "<?php\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Support\\Facades\\Schema;\nreturn new class extends Migration { public function up(): void { {$up} } };\n");
        $this->app->make('migrator')->path($migrations);
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
            ? "cd '{$this->layout->base}' && '".PHP_BINARY."' artisan openpne:backup-sqlite '{$data}/backup.sqlite'"
            : "(umask 077 && rm -f '{$data}/backup.sql.new' && '{$tools}/mysqldump' --defaults-extra-file='{$this->layout->mysqlOptionFile()}'";
    }

    /**
     * For SQLite the copy is taken in the process, so the file setup's trial copies from is made to hold what $backup says.
     *
     * @param  'works'|'empty'|'fails'  $backup
     */
    private function fakeTools(string $backup = 'works'): void
    {
        if ($this->sqlite()) {
            if (! is_dir($this->layout->dataDirectory())) {
                mkdir($this->layout->dataDirectory(), 0o700);
            }
            if ($backup === 'fails') {
                file_put_contents($this->sqlitePath(), str_repeat('not a database ', 512));
            } else {
                $site = new \PDO('sqlite:'.$this->sqlitePath());
                $site->exec('CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT)');
                if ($backup === 'works') {
                    $site->exec("INSERT INTO migrations (migration) VALUES ('0001_01_01_000000_create_members_table')");
                }
                $site = null;
            }
            chmod($this->sqlitePath(), 0o600);
        }
        Process::fake(function (PendingProcess $process) use ($backup) {
            $command = is_array($process->command) ? $process->command : [$process->command];
            $copy = preg_grep('/\A--result-file=/', $command);
            if ($copy !== [] && $backup !== 'fails') {
                file_put_contents(substr((string) reset($copy), strlen('--result-file=')), $backup === 'works' ? "CREATE TABLE `migrations` (\n" : "-- MySQL dump\n");
            }

            return match (true) {
                str_ends_with((string) $command[0], 'mysqldump') => $backup !== 'fails'
                    ? Process::result()
                    : Process::result('', "mysqldump: Got error: 1044: Access denied for user 'site'@'%' to database 'site_db'\n", 2),
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
