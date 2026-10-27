<?php

namespace Tests\Unit\Support;

use App\Support\ReleaseMarker;
use Tests\TestCase;

class ReleaseMarkerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/release-marker-'.uniqid();
        mkdir($this->dir);
        $this->app->setBasePath($this->dir);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir.'/RELEASE');
        @rmdir($this->dir);

        parent::tearDown();
    }

    public function test_no_file_is_null(): void
    {
        $this->assertNull(ReleaseMarker::sha());
    }

    public function test_the_sha_line_is_read(): void
    {
        file_put_contents($this->dir.'/RELEASE', "ref=main\nsha=00917cda \nbuilt=2026-10-27\n");

        $this->assertSame('00917cda', ReleaseMarker::sha());
    }

    public function test_a_file_without_a_sha_line_is_null(): void
    {
        file_put_contents($this->dir.'/RELEASE', "ref=main\nsha=\n");

        $this->assertNull(ReleaseMarker::sha());
    }
}
