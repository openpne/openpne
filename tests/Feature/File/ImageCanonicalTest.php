<?php

declare(strict_types=1);

namespace Tests\Feature\File;

use App\Files\CanonicalUnavailableException;
use App\Files\FileStorage;
use App\Files\FileUploader;
use App\Files\ImageBytesOverLimitException;
use App\Files\ImageCache;
use App\Files\ImageCachePublishException;
use App\Files\ImageProcessingException;
use App\Files\ImageProcessor;
use App\Files\ImageProcessorUnavailableException;
use App\Files\ImageSpec;
use App\Files\ImageTransform;
use App\Files\PostImages;
use App\Files\ProcessedImage;
use App\Models\Diary;
use App\Models\File;
use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * The canonical is the one decode of a stored picture: written through at upload, generated once on
 * a miss, and remembered as refused rather than decoded again.
 */
class ImageCanonicalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('image_cache');
    }

    public function test_an_upload_publishes_its_canonical_at_the_raw_key(): void
    {
        $file = $this->upload(UploadedFile::fake()->image('a.png', 240, 120));

        Storage::disk('image_cache')->assertExists($this->canonicalKey($file));
        $this->assertSame([240, 120], [$file->width, $file->height]);
        $this->assertSame([], $this->tempFiles($file), 'A temp file survived the publish.');
    }

    public function test_a_variant_is_drawn_from_the_canonical_and_never_reads_the_stored_bytes(): void
    {
        $file = $this->upload(UploadedFile::fake()->image('a.png', 240, 120));

        $this->app->instance(FileStorage::class, $this->storageThatRefusesToRead());

        $bytes = app(ImageCache::class)->bytes($file, ImageTransform::fromGeometry('w120_h120'), 'png');

        $this->assertSame([120, 60], $this->dimensions($bytes));
    }

    public function test_a_stored_row_without_a_canonical_gets_one_on_first_read(): void
    {
        // An OpenPNE 3 row: bytes written straight to storage, nothing on the cache disk.
        $file = $this->stored('image/png', $this->png(64, 32));

        $bytes = app(ImageCache::class)->canonical($file);

        $this->assertSame([64, 32], $this->dimensions($bytes));
        Storage::disk('image_cache')->assertExists($this->canonicalKey($file));
    }

    public function test_a_refused_source_leaves_a_marker_and_is_not_decoded_again(): void
    {
        $file = $this->stored('image/png', 'not an image at all');
        $spy = $this->spyProcessor();

        $this->assertThrows(fn () => app(ImageCache::class)->canonical($file), CanonicalUnavailableException::class);
        Storage::disk('image_cache')->assertExists($this->markerKey($file));
        $this->assertSame(1, $spy->calls);

        $this->assertThrows(fn () => app(ImageCache::class)->canonical($file), CanonicalUnavailableException::class);
        $this->assertSame(1, $spy->calls, 'A marked file was handed to the processor again.');
    }

    public function test_a_source_over_the_source_limit_is_refused_with_a_marker(): void
    {
        config(['openpne.images.max_source_kilobytes' => 1]);
        $file = $this->stored('image/png', $this->png(400, 400));

        $this->assertThrows(fn () => app(ImageCache::class)->canonical($file), CanonicalUnavailableException::class);
        Storage::disk('image_cache')->assertExists($this->markerKey($file));
    }

    public function test_a_read_budget_below_the_limit_refuses_this_call_without_a_marker(): void
    {
        $file = $this->stored('image/png', $this->png(400, 400));

        $this->assertThrows(fn () => app(ImageCache::class)->canonical($file, maxBytes: 1024), ImageBytesOverLimitException::class);
        Storage::disk('image_cache')->assertMissing($this->markerKey($file));

        $this->assertSame([400, 400], $this->dimensions(app(ImageCache::class)->canonical($file)));
    }

    public function test_an_upload_whose_canonical_cannot_be_published_is_rolled_back(): void
    {
        $root = Storage::disk('image_cache')->path('');
        chmod($root, 0o500);

        try {
            $this->assertThrows(
                fn () => $this->upload(UploadedFile::fake()->image('a.png', 24, 24)),
                ImageCachePublishException::class,
            );
        } finally {
            chmod($root, 0o755);
        }

        $this->assertSame(0, File::count());
    }

    public function test_a_storage_failure_after_the_canonical_was_published_purges_it(): void
    {
        $this->app->instance(FileStorage::class, $this->storageThatRefusesToWrite());

        try {
            $this->upload(UploadedFile::fake()->image('a.png', 24, 24));
            $this->fail('The storage failure did not propagate.');
        } catch (RuntimeException) {
        }

        $this->assertSame(0, File::count());
        $this->assertSame([], Storage::disk('image_cache')->allFiles(), 'The canonical of a rolled-back upload survived.');
    }

    public function test_a_read_whose_cache_write_fails_still_answers_with_the_bytes(): void
    {
        $file = $this->stored('image/png', $this->png(64, 32));
        $root = Storage::disk('image_cache')->path('');
        chmod($root, 0o500);

        try {
            $bytes = app(ImageCache::class)->canonical($file);
        } finally {
            chmod($root, 0o755);
        }

        $this->assertSame([64, 32], $this->dimensions($bytes));
        Storage::disk('image_cache')->assertMissing($this->canonicalKey($file));
    }

    public function test_a_failed_post_purges_the_canonicals_it_published(): void
    {
        $stored = null;

        try {
            app(PostImages::class)->compensating(function (callable $store) use (&$stored): void {
                $stored = $store(UploadedFile::fake()->image('a.png', 24, 24), 'diary', 1);
                Storage::disk('image_cache')->assertExists($this->canonicalKey($stored));

                throw new RuntimeException('the post failed after its picture was stored');
            });
            $this->fail('The failure did not propagate.');
        } catch (RuntimeException) {
        }

        $this->assertNotNull($stored);
        $this->assertSame([], Storage::disk('image_cache')->allFiles($stored->name));
        $this->assertFalse(app(FileStorage::class)->exists($stored));
    }

    public function test_a_refused_upload_becomes_a_field_error_and_stores_nothing(): void
    {
        $author = Member::factory()->create();

        $response = $this->actingAs($author)->post(
            route('diary.store'),
            [
                'title' => 'Broken',
                'body' => 'b',
                'visibility' => Visibility::Members->value,
                'images' => [UploadedFile::fake()->createWithContent('hollow.png', $this->pngHeaderClaiming(10, 10))],
            ],
            ['Accept' => 'application/json'],
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['images.0' => ImageProcessingException::userMessage()]);
        $this->assertSame(0, File::count());
        $this->assertSame(0, Diary::count());
    }

    public function test_a_name_collision_leaves_the_existing_files_canonical_alone(): void
    {
        // The collision is forced by pinning the random token.
        Str::createRandomStringsUsing(fn (int $length): string => str_repeat('a', $length));

        try {
            $existing = $this->upload(UploadedFile::fake()->image('a.png', 24, 24));
            Storage::disk('image_cache')->assertExists($this->canonicalKey($existing));

            try {
                $this->upload(UploadedFile::fake()->image('b.png', 32, 32));
                $this->fail('expected a name unique-constraint violation on the second upload');
            } catch (QueryException) {
                // expected: the second upload collides on files.name
            }

            Storage::disk('image_cache')->assertExists($this->canonicalKey($existing));
            $this->assertSame([24, 24], $this->dimensions(app(ImageCache::class)->canonical($existing)));
        } finally {
            Str::createRandomStringsNormally();
        }
    }

    public function test_a_variant_miss_on_a_published_canonical_honours_the_read_budget(): void
    {
        $file = $this->upload(UploadedFile::fake()->image('a.png', 240, 120));

        $this->assertThrows(
            fn () => app(ImageCache::class)->bytes($file, ImageTransform::fromGeometry('w120_h120'), 'png', maxBytes: 16),
            ImageBytesOverLimitException::class,
        );
    }

    public function test_a_canonical_over_the_source_limit_is_refused_like_its_source(): void
    {
        config(['openpne.images.max_source_kilobytes' => 1]);
        $this->app->instance(ImageProcessor::class, $this->processorThatInflates(2048));

        $this->assertThrows(fn () => $this->upload(UploadedFile::fake()->image('a.png', 8, 8)), ImageProcessingException::class);
        $this->assertSame(0, File::count());

        $stored = $this->stored('image/png', $this->png(8, 8));
        $this->assertThrows(fn () => app(ImageCache::class)->canonical($stored), CanonicalUnavailableException::class);
        Storage::disk('image_cache')->assertExists($this->markerKey($stored));
    }

    public function test_the_original_geometry_keeps_the_file_token_as_its_validator_while_it_serves_the_stored_bytes(): void
    {
        $owner = Member::factory()->create();
        $file = $this->stored('image/png', $this->png(8, 8), $owner);
        $url = route('image.show', ['format' => 'png', 'geometry' => 'w_h', 'name' => $file->name, 'ext' => 'png']);

        $this->actingAs($owner)->get($url)->assertOk()->assertHeader('ETag', '"'.$file->name.'"');
    }

    public function test_a_processor_outage_at_upload_is_a_field_error_too(): void
    {
        $this->app->instance(ImageProcessor::class, $this->processorThatIsDown());
        $author = Member::factory()->create();

        $response = $this->actingAs($author)->post(
            route('diary.store'),
            ['title' => 'T', 'body' => 'b', 'visibility' => Visibility::Members->value, 'images' => [UploadedFile::fake()->image('a.png', 24, 24)]],
            ['Accept' => 'application/json'],
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['images.0' => ImageProcessorUnavailableException::userMessage()]);
        $this->assertSame(0, File::count());
    }

    public function test_a_thumbnail_of_a_refused_file_is_not_found_and_of_an_outage_is_unavailable(): void
    {
        $owner = Member::factory()->create();
        $file = $this->stored('image/png', 'not an image at all', $owner);

        $this->actingAs($owner)->get($file->thumbnailUrl(120, 120))->assertNotFound();

        $good = $this->stored('image/png', $this->png(64, 32), $owner);
        $this->app->instance(ImageProcessor::class, $this->processorThatIsDown());

        $this->actingAs($owner)->get($good->thumbnailUrl(120, 120))
            ->assertStatus(503)
            ->assertHeader('Retry-After', '30');
    }

    public function test_a_non_raster_file_has_no_canonical(): void
    {
        $file = $this->stored('application/pdf', '%PDF-1.4');

        $this->assertThrows(fn () => app(ImageCache::class)->canonical($file), ImageProcessingException::class);
    }

    private function upload(UploadedFile $upload): File
    {
        return app(FileUploader::class)->store($upload);
    }

    /** A File row whose bytes were written straight to storage, as an upgraded OpenPNE 3 file is. */
    private function stored(string $type, string $bytes, ?Member $owner = null): File
    {
        $file = File::factory()->create([
            'type' => $type,
            'byte_size' => strlen($bytes),
            'related_entity_type' => $owner !== null ? 'member' : null,
            'related_entity_id' => $owner?->getKey(),
        ]);

        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $bytes);
        rewind($stream);
        app(FileStorage::class)->writeStream($file, $stream);
        fclose($stream);

        return $file;
    }

    private function canonicalKey(File $file): string
    {
        return ImageTransform::raw()->cacheKey($file->name, (string) $file->imageFormat());
    }

    private function markerKey(File $file): string
    {
        return ImageTransform::encoderPrefix($file->name).'/w_h.failed';
    }

    /** @return list<string> */
    private function tempFiles(File $file): array
    {
        return array_values(array_filter(
            Storage::disk('image_cache')->allFiles($file->name),
            fn (string $path): bool => str_contains($path, '/.tmp-'),
        ));
    }

    /** @return array{0: int, 1: int} */
    private function dimensions(string $bytes): array
    {
        $size = getimagesizefromstring($bytes);
        $this->assertNotFalse($size);

        return [$size[0], $size[1]];
    }

    private function png(int $width, int $height): string
    {
        $gd = imagecreatetruecolor($width, $height);
        imagefill($gd, 0, 0, (int) imagecolorallocate($gd, 200, 30, 30));
        ob_start();
        imagepng($gd);

        return (string) ob_get_clean();
    }

    /** A complete PNG container whose IHDR declares $width x $height and which carries no pixels. */
    private function pngHeaderClaiming(int $width, int $height): string
    {
        $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NN', $width, $height)."\x08\x06\x00\x00\x00").$chunk('IEND', '');
    }

    private function storageThatRefusesToRead(): FileStorage
    {
        return new class(app(FileStorage::class)) implements FileStorage
        {
            public function __construct(private readonly FileStorage $inner) {}

            public function writeStream(File $file, $stream): void
            {
                $this->inner->writeStream($file, $stream);
            }

            public function readStream(File $file)
            {
                throw new RuntimeException('The stored bytes were read for a variant.');
            }

            public function delete(File $file): void
            {
                $this->inner->delete($file);
            }

            public function exists(File $file): bool
            {
                return $this->inner->exists($file);
            }
        };
    }

    private function storageThatRefusesToWrite(): FileStorage
    {
        return new class(app(FileStorage::class)) implements FileStorage
        {
            public function __construct(private readonly FileStorage $inner) {}

            public function writeStream(File $file, $stream): void
            {
                throw new RuntimeException('the disk is full');
            }

            public function readStream(File $file)
            {
                return $this->inner->readStream($file);
            }

            public function delete(File $file): void
            {
                $this->inner->delete($file);
            }

            public function exists(File $file): bool
            {
                return $this->inner->exists($file);
            }
        };
    }

    private function spyProcessor(): ImageProcessor
    {
        $spy = new class(app(ImageProcessor::class)) implements ImageProcessor
        {
            public int $calls = 0;

            public function __construct(private readonly ImageProcessor $inner) {}

            public function process(string $bytes, string $mime, ImageSpec $spec): ProcessedImage
            {
                $this->calls++;

                return $this->inner->process($bytes, $mime, $spec);
            }

            public function preservesAnimation(): bool
            {
                return $this->inner->preservesAnimation();
            }
        };
        $this->app->instance(ImageProcessor::class, $spy);

        return $spy;
    }

    /** Answers every request with $bytes bytes of PNG-shaped nothing, whatever the input. */
    private function processorThatInflates(int $bytes): ImageProcessor
    {
        return new class($bytes) implements ImageProcessor
        {
            public function __construct(private readonly int $bytes) {}

            public function process(string $bytes, string $mime, ImageSpec $spec): ProcessedImage
            {
                return new ProcessedImage(str_repeat('x', $this->bytes), $mime, 8, 8, false);
            }

            public function preservesAnimation(): bool
            {
                return false;
            }
        };
    }

    private function processorThatIsDown(): ImageProcessor
    {
        return new class implements ImageProcessor
        {
            public function process(string $bytes, string $mime, ImageSpec $spec): ProcessedImage
            {
                throw new ImageProcessorUnavailableException('imgproxy did not answer');
            }

            public function preservesAnimation(): bool
            {
                return false;
            }
        };
    }
}
