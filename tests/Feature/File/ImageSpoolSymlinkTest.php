<?php

declare(strict_types=1);

namespace Tests\Feature\File;

use App\Files\Imgproxy\Spool;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The spool is a symlink into the tree one sidecar reads for several sites (docs/internals/images.md, "Processing").
 */
class ImageSpoolSymlinkTest extends TestCase
{
    private string $target;

    private string $link;

    protected function setUp(): void
    {
        parent::setUp();
        $base = sys_get_temp_dir().'/op-spool-'.bin2hex(random_bytes(4));
        $this->target = $base.'/tree/site_a';
        $this->link = $base.'/storage/image-spool';
        mkdir($this->target, 0755, true);
        mkdir(dirname($this->link), 0755, true);
        symlink($this->target, $this->link);
        config()->set('filesystems.disks.image_spool.root', $this->link);
        Storage::forgetDisk('image_spool');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->target.'/*') ?: [] as $file) {
            unlink($file);
        }
        unlink($this->link);
        rmdir($this->target);
        rmdir(dirname($this->target));
        rmdir(dirname($this->link));
        rmdir(dirname($this->link, 2));
        Storage::forgetDisk('image_spool');
        parent::tearDown();
    }

    public function test_a_spool_behind_a_symlink_is_written_swept_and_deleted_through_the_link(): void
    {
        $stale = $this->target.'/'.str_repeat('a', 32).'.jpg';
        file_put_contents($stale, 'old');
        touch($stale, time() - Spool::STALE_AFTER - 60);
        $spool = new Spool('image_spool');

        $name = $spool->put('bytes', 'jpg');

        $this->assertFileExists($this->target.'/'.$name);
        $this->assertSame('bytes', file_get_contents($this->target.'/'.$name));
        $this->assertFileDoesNotExist($stale);

        $spool->delete($name);

        $this->assertFileDoesNotExist($this->target.'/'.$name);
        $this->assertTrue(is_link($this->link));
    }
}
