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
use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;
use UnexpectedValueException;

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

    public function test_a_delete_that_lands_just_ahead_of_the_write_is_still_seen_by_the_re_read(): void
    {
        $file = $this->picture();
        $real = $this->disk();
        $transform = ImageTransform::fromGeometry('w120_h120_sq');

        // The row goes just ahead of the variant's write: a re-read made before the publish would still see it.
        $disk = Mockery::mock($real);
        $disk->shouldReceive('put')->andReturnUsing(function (string $path, string $contents) use ($real, $file): bool {
            File::query()->whereKey($file->getKey())->first()?->delete();

            return $real->put($path, $contents);
        });
        Storage::set('image_cache', $disk);

        app(ImageCache::class)->bytes($file, $transform, 'png');

        $this->assertDatabaseMissing('files', ['id' => $file->getKey()]);
        $this->assertSame([], $real->allFiles(ImageTransform::directory($file->name)));
    }

    public function test_a_refused_purge_neither_stops_the_delete_nor_goes_unreported(): void
    {
        Exceptions::fake();
        $file = $this->picture();
        $attempts = $this->refusePurges();

        $file->delete();

        $this->assertDatabaseMissing('files', ['id' => $file->getKey()]);
        $this->assertFalse(app(FileStorage::class)->exists($file));
        // One before the DELETE, three after the commit.
        $this->assertSame(4, $attempts());
        Exceptions::assertReported(ImageCachePurgeException::class);
    }

    public function test_a_purge_that_overlapped_a_publish_once_is_retried_and_not_reported(): void
    {
        Exceptions::fake();
        $file = $this->picture();
        $attempts = $this->refusePurges(failures: 2);

        $file->delete();

        $this->assertSame(3, $attempts());
        Exceptions::assertNotReported(ImageCachePurgeException::class);
        $this->assertSame([], $this->disk()->allFiles(ImageTransform::directory($file->name)));
    }

    public function test_a_purge_the_disk_throws_from_is_a_refusal_not_an_exception(): void
    {
        Exceptions::fake();
        $file = $this->picture();
        $disk = Mockery::mock($this->disk());
        // What SPL throws when an entry vanishes under the adapter's walk, which the adapter does not catch.
        $disk->shouldReceive('deleteDirectory')->andThrow(new UnexpectedValueException('failed to open directory'));
        Storage::set('image_cache', $disk);

        $file->delete();

        $this->assertDatabaseMissing('files', ['id' => $file->getKey()]);
        Exceptions::assertReported(ImageCachePurgeException::class);
    }

    /**
     * The first $failures purges are refused, as an overlapping publish makes them; null refuses every one.
     *
     * @return Closure(): int the number of purges attempted so far
     */
    private function refusePurges(?int $failures = null): Closure
    {
        $real = $this->disk();
        $calls = 0;
        $disk = Mockery::mock($real);
        $disk->shouldReceive('deleteDirectory')->andReturnUsing(function (string $directory) use (&$calls, $real, $failures): bool {
            $calls++;

            return $failures !== null && $calls > $failures ? $real->deleteDirectory($directory) : false;
        });
        Storage::set('image_cache', $disk);

        return function () use (&$calls): int {
            return $calls;
        };
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
