<?php

declare(strict_types=1);

namespace Tests\Unit\SiteCheck;

use App\SiteCheck\BackupTools;
use App\SiteCheck\PhpCommand;
use PHPUnit\Framework\TestCase;

final class CommandNamesTest extends TestCase
{
    private string $root;

    private string|false $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/openpne-names-'.bin2hex(random_bytes(4));
        mkdir($this->root);
        $this->path = getenv('PATH');
    }

    protected function tearDown(): void
    {
        putenv('PATH='.$this->path);
        exec('rm -rf '.escapeshellarg($this->root));
        parent::tearDown();
    }

    /** A host whose versioned name links into the directory of the patch release, which the next patch release replaces. */
    public function test_a_php_linked_into_the_patch_release_is_named_by_its_link(): void
    {
        $binary = $this->file('pkg/php-8.4.1/bin/php');
        $this->link('bin/php8.4', $binary);
        $this->onPath('bin');

        $php = new PhpCommand($binary, '8.4.1');

        $this->assertSame($this->root.'/bin/php8.4', $php->path());
        $this->assertSame($this->root.'/bin/php8.4', $php->word());
    }

    public function test_the_versioned_name_comes_before_php_and_php_is_said_where_it_runs_the_same(): void
    {
        $binary = $this->file('pkg/php-8.4.1/bin/php');
        $this->link('a/php', $binary);
        $this->link('b/php8.4', $binary);
        $this->onPath('a', 'b');

        $php = new PhpCommand($binary, '8.4.1');

        $this->assertSame($this->root.'/b/php8.4', $php->path());
        $this->assertSame('php', $php->word());
    }

    public function test_php_alone_is_taken_and_a_hard_link_is_the_same_file(): void
    {
        $binary = $this->file('pkg/php-8.4.1/bin/php');
        mkdir($this->root.'/bin', 0o755, true);
        link($binary, $this->root.'/bin/php');
        $this->onPath('bin');

        $this->assertSame($this->root.'/bin/php', (new PhpCommand($binary, '8.4.1'))->path());
    }

    public function test_names_holding_the_patch_release_or_running_another_php_are_passed_over(): void
    {
        $binary = $this->file('pkg/php-8.4.1/bin/php');
        $this->link('pkg/php-8.4.1/bin/php8.4', $binary);
        $this->link('bin/php8.4', $this->file('pkg/php-8.4.0/bin/php'));
        $this->onPath('pkg/php-8.4.1/bin', 'bin');

        $php = new PhpCommand($binary, '8.4.1');

        $this->assertSame($binary, $php->path());
        $this->assertSame('php', $php->word());
    }

    public function test_a_php_whose_path_has_no_patch_release_is_named_as_it_is(): void
    {
        $binary = $this->file('local/bin/php');
        $this->link('bin/php8.4', $binary);
        $this->onPath('bin');

        $php = new PhpCommand($binary, '8.4.1');

        $this->assertSame($binary, $php->path());
        $this->assertSame($binary, $php->word());
    }

    public function test_mariadb_s_mysqldump_is_named_mariadb_dump_as_path_has_it(): void
    {
        $dump = $this->file('pkg/mariadb-11.4.2/bin/mariadb-dump');
        $this->link('bin/mysqldump', $dump);
        $this->link('bin/mariadb-dump', $dump);
        $this->onPath('bin');

        $this->assertSame($this->root.'/bin/mariadb-dump', BackupTools::mysql());

        unlink($this->root.'/bin/mariadb-dump');
        $this->assertSame($dump, BackupTools::mysql());

        $this->file('bin/mariadb-dump');
        $this->assertSame($dump, BackupTools::mysql());
    }

    public function test_mysql_s_mysqldump_keeps_its_name(): void
    {
        $this->link('bin/mysqldump', $this->file('pkg/mysql-8.0.1/bin/mysqldump'));
        $this->onPath('bin');

        $this->assertSame($this->root.'/bin/mysqldump', BackupTools::mysql());
    }

    private function file(string $relative): string
    {
        $path = $this->root.'/'.$relative;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0o755, true);
        }
        file_put_contents($path, "#!/bin/sh\n");
        chmod($path, 0o755);

        return $path;
    }

    private function link(string $relative, string $target): void
    {
        $path = $this->root.'/'.$relative;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0o755, true);
        }
        symlink($target, $path);
    }

    private function onPath(string ...$relative): void
    {
        putenv('PATH='.implode(PATH_SEPARATOR, array_map(fn (string $d): string => $this->root.'/'.$d, $relative)));
    }
}
