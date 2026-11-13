<?php

namespace Tests\Feature\File;

use App\Files\FileStorage;
use App\Files\FileUploader;
use App\Files\ImageCache;
use App\Files\ImageCachePurgeException;
use App\Files\ImageIntake;
use App\Files\ImageProcessor;
use App\Files\ImageSpec;
use App\Files\ImageTransform;
use App\Files\ProcessedImage;
use App\Models\File;
use App\Models\Member;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImageCacheDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('image_cache');
    }

    public function test_a_variant_published_after_the_delete_committed_is_removed_by_its_publisher(): void
    {
        $file = $this->picture();
        $inner = app(ImageProcessor::class);

        // The delete lands while the variant is being drawn: its purges are both over before the publish.
        $this->app->instance(ImageProcessor::class, new class($inner, $file) implements ImageProcessor
        {
            public function __construct(private readonly ImageProcessor $inner, private readonly File $file) {}

            public function process(string $bytes, string $mime, ImageSpec $spec): ProcessedImage
            {
                $processed = $this->inner->process($bytes, $mime, $spec);
                File::query()->whereKey($this->file->getKey())->first()?->delete();

                return $processed;
            }

            public function intake(): ImageIntake
            {
                return $this->inner->intake();
            }

            public function preservesAnimation(): bool
            {
                return $this->inner->preservesAnimation();
            }
        });
        $this->app->forgetInstance(ImageCache::class);

        $bytes = app(ImageCache::class)->bytes($file, ImageTransform::fromGeometry('w120_h120_sq'), 'png');

        $this->assertNotSame('', $bytes);
        $this->assertDatabaseMissing('files', ['id' => $file->getKey()]);
        $this->assertSame([], $this->disk()->allFiles(ImageTransform::directory($file->name)));
    }

    public function test_a_variant_published_between_the_purge_and_the_commit_is_removed_after_the_commit(): void
    {
        $file = $this->picture();
        $key = ImageTransform::fromGeometry('w120_h120_sq')->cacheKey($file->name, 'png');

        DB::transaction(function () use ($file, $key): void {
            $file->delete();
            // Another request, still seeing the row, publishes now.
            app(ImageCache::class)->publish($key, 'late');
            $this->assertTrue($this->disk()->exists($key));
        });

        $this->assertSame([], $this->disk()->allFiles(ImageTransform::directory($file->name)));
    }

    public function test_a_surviving_file_keeps_the_variant_it_was_asked_for(): void
    {
        $file = $this->picture();
        $transform = ImageTransform::fromGeometry('w120_h120_sq');

        app(ImageCache::class)->bytes($file, $transform, 'png');

        $this->assertTrue($this->disk()->exists($transform->cacheKey($file->name, 'png')));
    }

    public function test_a_cache_disk_that_refuses_the_purge_stops_the_delete_with_the_bytes_in_place(): void
    {
        $file = $this->picture();
        $this->refusePurges();

        try {
            $file->delete();
            $this->fail('The delete went through.');
        } catch (ImageCachePurgeException) {
        }

        $this->assertDatabaseHas('files', ['id' => $file->getKey()]);
        $this->assertTrue(app(FileStorage::class)->exists($file));
    }

    public function test_a_purge_that_fails_after_the_commit_is_reported_and_the_delete_stands(): void
    {
        Exceptions::fake();
        $file = $this->picture();
        $this->refusePurges(after: 1);

        $file->delete();

        $this->assertDatabaseMissing('files', ['id' => $file->getKey()]);
        Exceptions::assertReported(ImageCachePurgeException::class);
    }

    /** The first $after purges go through; every later one is refused, as a disk that has gone read-only would. */
    private function refusePurges(int $after = 0): void
    {
        $real = $this->disk();
        $disk = $this->partialMock($real::class, function ($mock) use ($real, $after): void {
            $calls = 0;
            $mock->shouldReceive('deleteDirectory')->andReturnUsing(function (string $directory) use (&$calls, $real, $after): bool {
                return ++$calls <= $after ? $real->deleteDirectory($directory) : false;
            });
        });
        Storage::set('image_cache', $disk);
    }

    private function picture(): File
    {
        return app(FileUploader::class)->store(UploadedFile::fake()->image('a.png', 240, 120), 'member', (int) Member::factory()->create()->getKey());
    }

    private function disk(): Filesystem
    {
        return Storage::disk('image_cache');
    }
}
