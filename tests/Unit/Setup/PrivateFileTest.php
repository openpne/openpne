<?php

declare(strict_types=1);

namespace Tests\Unit\Setup;

use App\Setup\PrivateFile;
use Illuminate\Filesystem\Filesystem;
use Tests\TestCase;

final class PrivateFileTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/openpne-privatefile-'.bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_a_file_is_made_at_its_mode_with_its_contents(): void
    {
        $umask = umask(0o022);
        try {
            $this->assertTrue(PrivateFile::create($this->directory.'/made', 'secret', 0o640));
        } finally {
            umask($umask);
        }

        $this->assertSame(0o640, fileperms($this->directory.'/made') & 0o777);
        $this->assertSame('secret', file_get_contents($this->directory.'/made'));
    }

    public function test_a_broken_link_of_the_name_is_never_followed(): void
    {
        symlink($this->directory.'/elsewhere', $this->directory.'/made');

        $this->assertFalse(PrivateFile::create($this->directory.'/made', 'secret', 0o600));
        $this->assertFileDoesNotExist($this->directory.'/elsewhere');
    }

    public function test_a_file_of_the_name_is_left_as_it_is(): void
    {
        file_put_contents($this->directory.'/made', 'before');

        $this->assertFalse(PrivateFile::create($this->directory.'/made', 'secret', 0o600));
        $this->assertSame('before', file_get_contents($this->directory.'/made'));
    }
}
