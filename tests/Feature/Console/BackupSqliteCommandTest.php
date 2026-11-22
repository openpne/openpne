<?php

namespace Tests\Feature\Console;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use SQLite3;
use Tests\TestCase;

class BackupSqliteCommandTest extends TestCase
{
    private string $root;

    private string $database;

    private string $default;

    private string|false $cwd;

    /** Holds rows in the -wal file until the test ends, as a running site's connections do. */
    private ?PDO $site = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/openpne-backup-test-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/data', 0o700, true);
        $this->database = $this->root.'/data/database.sqlite';
        $this->site = new PDO('sqlite:'.$this->database);
        $this->site->exec('PRAGMA journal_mode=WAL');
        $this->site->exec('PRAGMA wal_autocheckpoint=0');
        $this->site->exec('CREATE TABLE members (id INTEGER PRIMARY KEY, name TEXT)');
        $this->site->exec("INSERT INTO members (name) VALUES ('first'), ('second')");

        $this->default = (string) config('database.default');
        $this->cwd = getcwd();
        config(['database.connections.backup_site' => ['driver' => 'sqlite', 'database' => $this->database, 'prefix' => '', 'foreign_key_constraints' => true]]);
        config(['database.default' => 'backup_site']);
    }

    protected function tearDown(): void
    {
        DB::purge('backup_site');
        config(['database.default' => $this->default]);
        $this->site = null;
        if ($this->cwd !== false) {
            chdir($this->cwd);
        }
        (new Filesystem)->deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_the_copy_holds_what_the_wal_file_holds_and_is_out_of_wal_and_private(): void
    {
        $this->assertFileExists($this->database.'-wal');
        $copy = $this->root.'/backup.sqlite';

        $this->artisan('openpne:backup-sqlite', ['path' => $copy])->assertSuccessful();

        $this->assertSame(['first', 'second'], $this->names($copy));
        $this->assertSame("\x01\x01", file_get_contents($copy, false, null, 18, 2));
        $this->assertSame(0o600, fileperms($copy) & 0o777);
        $this->assertSame(['backup.sqlite', 'backup.sqlite.lock'], $this->beside($copy));
    }

    public function test_an_earlier_copy_is_replaced_and_what_a_killed_run_left_is_removed(): void
    {
        $copy = $this->root.'/backup.sqlite';
        $this->artisan('openpne:backup-sqlite', ['path' => $copy])->assertSuccessful();
        $this->site->exec("INSERT INTO members (name) VALUES ('third')");
        file_put_contents($copy.'.new', 'half a copy');
        file_put_contents($copy.'.new-journal', 'its journal');

        $this->artisan('openpne:backup-sqlite', ['path' => $copy])->assertSuccessful();

        $this->assertSame(['first', 'second', 'third'], $this->names($copy));
        $this->assertSame(['backup.sqlite', 'backup.sqlite.lock'], $this->beside($copy));
    }

    /** @return array<string, array{string}> */
    public static function overlaps(): array
    {
        return [
            'the database' => ['data/database.sqlite'],
            'its -wal file' => ['data/database.sqlite-wal'],
            'its -shm file, through a directory spelled otherwise' => ['data/../data/./database.sqlite-shm'],
            'its journal' => ['data/database.sqlite-journal'],
            'a link to it' => ['link.sqlite'],
            'a directory linked to its own' => ['linked/database.sqlite'],
            'a second name of it' => ['hard.sqlite'],
            'a copy whose file beside it is the database' => ['data/database'],
        ];
    }

    #[DataProvider('overlaps')]
    public function test_a_path_that_reaches_the_database_or_a_file_beside_it_is_refused_before_anything_is_touched(string $path): void
    {
        if ($path === 'data/database') {
            $this->renameDatabase($this->root.'/data/database.new');
        }
        symlink($this->database, $this->root.'/link.sqlite');
        symlink($this->root.'/data', $this->root.'/linked');
        link($this->database, $this->root.'/hard.sqlite');
        touch($this->database.'-journal');
        $before = $this->files();
        chdir($this->root);

        $this->artisan('openpne:backup-sqlite', ['path' => $path])
            ->expectsOutputToContain('or a file beside it.')
            ->assertFailed();

        $this->assertSame($before, $this->files());
    }

    /** @return array<string, array{string}> */
    public static function endings(): array
    {
        return [
            'another run\'s lock' => ['.lock'],
            'another run\'s work file' => ['.new'],
            'the journal of that work file' => ['.new-journal'],
            'a -wal file beside a copy' => ['-wal'],
        ];
    }

    #[DataProvider('endings')]
    public function test_a_path_named_as_a_file_beside_a_copy_is_refused_and_another_run_keeps_its_lock(string $ending): void
    {
        $copy = $this->root.'/backup.sqlite';
        $first = fopen($copy.'.lock', 'c');
        $this->assertTrue(flock($first, LOCK_EX | LOCK_NB));
        file_put_contents($copy.'.new', 'the first run\'s copy so far');
        $before = $this->files();

        $this->artisan('openpne:backup-sqlite', ['path' => $copy.$ending])
            ->expectsOutputToContain('which openpne:backup-sqlite keeps for the files beside a copy.')
            ->assertFailed();

        $this->assertSame($before, $this->files());
        $this->artisan('openpne:backup-sqlite', ['path' => $copy])
            ->expectsOutputToContain('Another openpne:backup-sqlite is writing '.$copy.'.')
            ->assertFailed();
        fclose($first);
    }

    public function test_a_lock_that_is_a_link_is_refused_before_anything_is_touched(): void
    {
        $copy = $this->root.'/backup.sqlite';
        symlink($this->root.'/data/made-through-the-link', $copy.'.lock');
        $before = $this->files();

        $this->artisan('openpne:backup-sqlite', ['path' => $copy])
            ->expectsOutputToContain($copy.'.lock is not a plain file')
            ->assertFailed();

        $this->assertSame($before, $this->files());
    }

    public function test_a_copy_that_fails_leaves_the_earlier_copy_as_it_was(): void
    {
        $copy = $this->root.'/backup.sqlite';
        $this->artisan('openpne:backup-sqlite', ['path' => $copy])->assertSuccessful();
        $earlier = md5_file($copy);
        file_put_contents($this->root.'/data/broken.sqlite', str_repeat('not a database ', 512));
        config(['database.connections.backup_site.database' => $this->root.'/data/broken.sqlite']);
        DB::purge('backup_site');

        $this->artisan('openpne:backup-sqlite', ['path' => $copy])
            ->expectsOutputToContain('file is not a database')
            ->assertFailed();

        $this->assertSame($earlier, md5_file($copy));
        $this->assertSame(['backup.sqlite', 'backup.sqlite.lock'], $this->beside($copy));
    }

    public function test_a_file_beside_the_earlier_copy_stops_the_copy_and_leaves_that_copy(): void
    {
        $copy = $this->root.'/backup.sqlite';
        $this->artisan('openpne:backup-sqlite', ['path' => $copy])->assertSuccessful();
        $opened = new SQLite3($copy);
        $opened->exec('PRAGMA journal_mode=WAL');
        $opened->exec('CREATE TABLE notes (id INTEGER PRIMARY KEY)');
        $earlier = md5_file($copy);

        $this->artisan('openpne:backup-sqlite', ['path' => $copy])
            ->expectsOutputToContain($copy.'-wal is beside the earlier copy')
            ->assertFailed();

        $this->assertSame($earlier, md5_file($copy));
        $this->assertFileDoesNotExist($copy.'.new');
        $opened->close();
    }

    public function test_a_second_run_to_the_same_path_is_refused_while_the_first_holds_it(): void
    {
        $copy = $this->root.'/backup.sqlite';
        $first = fopen($copy.'.lock', 'c');
        $this->assertTrue(flock($first, LOCK_EX | LOCK_NB));
        file_put_contents($copy.'.new', 'the first run\'s copy so far');

        $this->artisan('openpne:backup-sqlite', ['path' => $copy])
            ->expectsOutputToContain('Another openpne:backup-sqlite is writing '.$copy.'.')
            ->assertFailed();

        fclose($first);
        $this->assertSame('the first run\'s copy so far', file_get_contents($copy.'.new'));
        $this->assertFileDoesNotExist($copy);
    }

    public function test_a_directory_that_does_not_exist_fails(): void
    {
        $this->artisan('openpne:backup-sqlite', ['path' => $this->root.'/nowhere/backup.sqlite'])
            ->expectsOutputToContain('is not a file in a directory that exists.')
            ->assertFailed();
    }

    public function test_a_site_on_mysql_is_refused(): void
    {
        config(['database.connections.backup_site' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'site']]);
        DB::purge('backup_site');

        $this->artisan('openpne:backup-sqlite', ['path' => $this->root.'/backup.sqlite'])
            ->expectsOutputToContain('The site\'s database is not SQLite.')
            ->assertFailed();

        $this->assertFileDoesNotExist($this->root.'/backup.sqlite.lock');
    }

    /** @return list<string> */
    private function names(string $copy): array
    {
        $read = new PDO('sqlite:'.$copy);

        return $read->query('SELECT name FROM members ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return list<string> */
    private function beside(string $copy): array
    {
        return array_map('basename', glob($copy.'*') ?: []);
    }

    /** @return array<string, string> each file under the root with what it holds */
    private function files(): array
    {
        $files = [];
        foreach (array_merge(glob($this->root.'/*') ?: [], glob($this->root.'/data/*') ?: []) as $file) {
            $files[$file] = match (true) {
                is_link($file) => 'link to '.readlink($file),
                is_dir($file) => 'directory',
                // A connection reading a WAL database writes into its -shm, and SQLite 3.26 leaves other bytes there.
                str_ends_with($file, '-shm') => 'inode '.fileinode($file).', '.filesize($file).' bytes',
                default => md5_file($file),
            };
        }
        ksort($files);

        return $files;
    }

    private function renameDatabase(string $to): void
    {
        $this->site = null;
        DB::purge('backup_site');
        foreach (['', '-wal', '-shm'] as $suffix) {
            if (file_exists($this->database.$suffix)) {
                rename($this->database.$suffix, $to.$suffix);
            }
        }
        $this->database = $to;
        config(['database.connections.backup_site.database' => $to]);
        $this->site = new PDO('sqlite:'.$to);
        $this->site->query('SELECT count(*) FROM members');
    }
}
