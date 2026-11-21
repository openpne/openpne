<?php

declare(strict_types=1);

namespace Tests\Unit\Setup;

use App\Setup\EnvFile;
use Illuminate\Filesystem\Filesystem;
use Tests\TestCase;

final class EnvFileTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/openpne-envfile-'.bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_a_new_env_takes_the_example_with_every_answer_over_its_defaults(): void
    {
        $env = $this->directory.'/.env';
        $values = ['APP_ENV' => 'production', 'APP_NAME' => 'Example "SNS" #1', 'DB_DATABASE' => '/h/openpne-data/database.sqlite', 'MAIL_PASSWORD' => "p#ss'w"];

        $this->assertNull(EnvFile::create($env, base_path('.env.example'), $values));

        $read = EnvFile::read((string) file_get_contents($env));
        foreach ($values as $key => $value) {
            $this->assertSame($value, $read[$key]);
        }
        $this->assertSame('ja', $read['APP_LOCALE']);
        $this->assertSame(0o600, fileperms($env) & 0o777);
        $this->assertSame(['.env'], array_values(array_diff(scandir($this->directory), ['.', '..'])));
    }

    public function test_an_answer_that_does_not_read_back_leaves_no_env(): void
    {
        $example = $this->directory.'/example';
        // Dotenv takes the later of two lines; the write replaces the first.
        file_put_contents($example, "APP_NAME=OpenPNE\nAPP_NAME=Other\n");

        $this->assertSame('APP_NAME', EnvFile::create($this->directory.'/.env', $example, ['APP_NAME' => 'Example']));
        $this->assertSame(['example'], array_values(array_diff(scandir($this->directory), ['.', '..'])));
    }

    public function test_a_written_file_that_does_not_parse_leaves_no_env(): void
    {
        $example = $this->directory.'/example';
        file_put_contents($example, "MAIL_PASSWORD=\nAPP_NAME=OpenPNE\n");

        $this->assertSame(EnvFile::UNREADABLE, EnvFile::create($this->directory.'/.env', $example, ['MAIL_PASSWORD' => 'ab="cd']));
        $this->assertSame(['example'], array_values(array_diff(scandir($this->directory), ['.', '..'])));
    }

    public function test_setting_a_key_rewrites_its_line_and_no_other_byte(): void
    {
        $env = $this->directory.'/.env';
        foreach (['TRUSTED_PROXIES=""', 'TRUSTED_PROXIES= # set me', 'TRUSTED_PROXIES='] as $line) {
            $before = "APP_NAME=\"Example SNS\"\n# a comment\n{$line}\nMAIL_PASSWORD='p#ss'\n";
            file_put_contents($env, $before);
            chmod($env, 0o640);

            $this->assertNull(EnvFile::set($env, 'TRUSTED_PROXIES', '203.0.113.0/24'));

            $this->assertSame(str_replace($line, 'TRUSTED_PROXIES="203.0.113.0/24"', $before), file_get_contents($env));
            $this->assertSame(0o640, fileperms($env) & 0o777);
        }
    }

    public function test_a_key_written_twice_is_refused_and_the_file_left_as_it_was(): void
    {
        $env = $this->directory.'/.env';
        $before = "TRUSTED_PROXIES=\nAPP_NAME=x\nTRUSTED_PROXIES=\n";
        file_put_contents($env, $before);

        $this->assertSame('TRUSTED_PROXIES', EnvFile::set($env, 'TRUSTED_PROXIES', '203.0.113.10'));
        $this->assertSame($before, file_get_contents($env));
        $this->assertSame(['.env'], array_values(array_diff(scandir($this->directory), ['.', '..'])));
    }
}
