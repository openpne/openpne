<?php

namespace Tests\Feature\File;

use App\Files\CanonicalUnavailableException;
use App\Files\FileStorage;
use App\Files\FileUploader;
use App\Files\ImageCache;
use App\Files\ImageDrawBusyException;
use App\Files\ImageDrawLock;
use App\Files\ImageIntake;
use App\Files\ImageProcessingException;
use App\Files\ImageProcessor;
use App\Files\ImageSpec;
use App\Files\ImageTransform;
use App\Files\ProcessedImage;
use App\Models\File;
use App\Models\Member;
use Carbon\CarbonInterval;
use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * A lock is "held by another worker" when the test holds it through a handle of its own: flock binds
 * the open file, so the cache's handle in the same process contends with it.
 */
class ImageCacheSingleFlightTest extends TestCase
{
    use RefreshDatabase;

    private const VARIANT = 'w120_h120_sq';

    /** @var list<resource> */
    private array $held = [];

    private int $draws = 0;

    private ?Closure $whileDrawing = null;

    private int $naps = 0;

    private int $slept = 0;

    /** @var array<int, Closure(): mixed> */
    private array $atNap = [];

    protected function setUp(): void
    {
        parent::setUp();

        $inner = app(ImageProcessor::class);
        $this->app->instance(ImageProcessor::class, new class($inner, fn () => $this->drawing()) implements ImageProcessor
        {
            public function __construct(private readonly ImageProcessor $inner, private readonly Closure $onDraw) {}

            public function process(string $bytes, string $mime, ImageSpec $spec): ProcessedImage
            {
                ($this->onDraw)();

                return $this->inner->process($bytes, $mime, $spec);
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

        Sleep::fake();
        Sleep::whenFakingSleep(function (CarbonInterval $duration): void {
            $this->naps++;
            $this->slept += (int) round($duration->totalMicroseconds);
            ($this->atNap[$this->naps] ?? null)?->__invoke();
        });
    }

    protected function tearDown(): void
    {
        foreach ($this->held as $handle) {
            if (is_resource($handle)) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }

        parent::tearDown();
    }

    public function test_a_holder_that_placed_its_variant_leaves_the_file_free_and_unnoted(): void
    {
        $file = $this->picture();
        $key = $this->variantKey($file);

        $this->assertNotSame('', $this->variant($file));
        $this->assertSame(1, $this->draws);
        $this->assertTrue($this->takeable('v', $key));
        $this->assertSame('', (string) file_get_contents($this->lockPath('v', $key)));
    }

    public function test_a_variant_waiter_serves_what_the_holder_placed_without_drawing(): void
    {
        $file = $this->picture();
        $key = $this->variantKey($file);
        $held = $this->hold('v', $key);
        $this->atNap[3] = function () use ($key, $held): void {
            $this->disk()->put($key, 'placed');
            flock($held, LOCK_UN);
        };

        $this->assertSame('placed', $this->variant($file));
        $this->assertSame(0, $this->draws);
    }

    public function test_a_canonical_waiter_serves_what_the_holder_placed_without_drawing(): void
    {
        $file = $this->picture();
        $this->dropCanonical($file);
        $held = $this->hold('c', $this->canonicalKey($file));
        $this->atNap[3] = function () use ($file, $held): void {
            $this->disk()->put($this->canonicalKey($file), 'placed');
            flock($held, LOCK_UN);
        };

        $this->assertSame('placed', app(ImageCache::class)->canonical($file));
        $this->assertSame(0, $this->draws);
    }

    public function test_a_canonical_waiter_answers_the_holders_marker_without_drawing(): void
    {
        $file = $this->picture();
        $this->dropCanonical($file);
        $held = $this->hold('c', $this->canonicalKey($file));
        $this->atNap[3] = function () use ($file, $held): void {
            $this->disk()->put($this->markerKey($file), 'refused');
            flock($held, LOCK_UN);
        };

        $this->assertThrows(fn () => app(ImageCache::class)->canonical($file), CanonicalUnavailableException::class);
        $this->assertSame(0, $this->draws);
    }

    public function test_a_waiter_draws_without_the_lock_once_the_holder_let_go_with_nothing_placed(): void
    {
        $file = $this->picture();
        $key = $this->variantKey($file);
        $held = $this->hold('v', $key);
        $this->atNap[3] = fn () => flock($held, LOCK_UN);
        $free = null;
        $this->whileDrawing = function () use ($key, &$free): void {
            $free ??= $this->takeable('v', $key);
        };

        $this->assertNotSame('', $this->variant($file));
        $this->assertSame(1, $this->draws);
        $this->assertTrue($free);
    }

    public function test_a_waiter_reads_the_cache_without_holding_the_file(): void
    {
        $file = $this->picture();
        $key = $this->variantKey($file);
        $held = $this->hold('v', $key);
        $released = false;
        $free = null;
        $this->atNap[3] = function () use ($held, &$released): void {
            flock($held, LOCK_UN);
            $released = true;
        };
        $this->diskWith(exists: function (string $path, Closure $real) use ($key, &$released, &$free): bool {
            if ($path === $key && $released) {
                $free ??= $this->takeable('v', $key);
            }

            return $real($path);
        });

        $this->variant($file);

        $this->assertTrue($free);
    }

    public function test_a_variant_waiter_draws_from_a_readable_canonical_beside_a_marker(): void
    {
        $file = $this->picture();
        $key = $this->variantKey($file);
        $this->disk()->put($this->markerKey($file), 'refused before a warm --retry-failed');
        $held = $this->hold('v', $key);
        $this->atNap[3] = fn () => flock($held, LOCK_UN);

        $this->assertNotSame('', $this->variant($file));
        $this->assertSame(1, $this->draws);
    }

    public function test_a_note_for_its_own_key_sends_the_waiter_to_draw_while_the_next_holder_holds(): void
    {
        $file = $this->picture();
        $key = $this->variantKey($file);
        $this->hold('v', $key);
        $this->atNap[3] = fn () => $this->write('v', $key, $this->note($key));

        $this->assertNotSame('', $this->variant($file));
        $this->assertSame(1, $this->draws);
        $this->assertSame(3, $this->naps);
    }

    public function test_a_canonical_waiter_sent_by_a_note_still_answers_the_marker(): void
    {
        $file = $this->picture();
        $this->dropCanonical($file);
        $key = $this->canonicalKey($file);
        $this->hold('c', $key);
        $this->atNap[3] = function () use ($file, $key): void {
            $this->disk()->put($this->markerKey($file), 'refused');
            $this->write('c', $key, $this->note($key));
        };

        $this->assertThrows(fn () => app(ImageCache::class)->canonical($file), CanonicalUnavailableException::class);
        $this->assertSame(0, $this->draws);
    }

    /** @return array<string, array{Closure(string): string}> */
    public static function notNotes(): array
    {
        return [
            'another key' => [fn (string $key): string => sha1('another').str_repeat('0', 16)],
            'short' => [fn (string $key): string => substr(sha1($key).str_repeat('0', 16), 0, 55)],
            'not hex' => [fn (string $key): string => sha1($key).str_repeat('z', 16)],
            'upper case' => [fn (string $key): string => strtoupper(sha1($key)).str_repeat('A', 16)],
        ];
    }

    #[DataProvider('notNotes')]
    public function test_content_that_is_not_its_own_note_keeps_the_waiter_waiting(Closure $content): void
    {
        $file = $this->picture();
        $key = $this->variantKey($file);
        $this->hold('v', $key);
        $this->atNap[3] = fn () => $this->write('v', $key, $content($key));

        $this->assertThrows(fn () => $this->variant($file), ImageDrawBusyException::class);
        $this->assertSame(0, $this->draws);
    }

    public function test_a_note_written_before_the_wait_began_is_missed_and_the_deadline_applies(): void
    {
        $file = $this->picture();
        $key = $this->variantKey($file);
        $this->hold('v', $key);
        $this->write('v', $key, $this->note($key));

        $this->assertThrows(fn () => $this->variant($file), ImageDrawBusyException::class);
        $this->assertSame(0, $this->draws);
    }

    public function test_a_note_overwritten_by_another_key_is_missed_and_the_deadline_applies(): void
    {
        $file = $this->picture();
        $key = $this->variantKey($file);
        $this->hold('v', $key);
        $this->atNap[3] = function () use ($key): void {
            $this->write('v', $key, $this->note($key));
            $this->write('v', $key, $this->note('another'));
        };

        $this->assertThrows(fn () => $this->variant($file), ImageDrawBusyException::class);
        $this->assertSame(0, $this->draws);
    }

    public function test_the_wait_ends_ten_seconds_after_the_miss_without_drawing(): void
    {
        $file = $this->picture();
        $this->hold('v', $this->variantKey($file));
        $longest = 0;
        Sleep::whenFakingSleep(function (CarbonInterval $duration) use (&$longest): void {
            $longest = max($longest, (int) round($duration->totalMicroseconds));
        });

        $this->assertThrows(fn () => $this->variant($file), ImageDrawBusyException::class);
        $this->assertSame(10_000_000, $this->slept);
        $this->assertSame(100_000, $longest);
        $this->assertSame(0, $this->draws);
    }

    public function test_what_was_placed_at_the_last_nap_is_served(): void
    {
        $file = $this->picture();
        $key = $this->variantKey($file);
        $this->hold('v', $key);
        $this->atNap[100] = fn () => $this->disk()->put($key, 'late');

        $this->assertSame('late', $this->variant($file));
        $this->assertSame(0, $this->draws);
    }

    public function test_a_variant_and_its_canonical_wait_out_one_deadline_between_them(): void
    {
        $file = $this->picture();
        $this->dropCanonical($file);
        $variant = $this->hold('v', $this->variantKey($file));
        $this->hold('c', $this->canonicalKey($file));
        $this->atNap[50] = fn () => flock($variant, LOCK_UN);

        $this->assertThrows(fn () => $this->variant($file), ImageDrawBusyException::class);
        $this->assertSame(10_000_000, $this->slept);
        $this->assertSame(0, $this->draws);
    }

    public function test_a_waiter_finds_its_variant_within_five_naps_while_the_file_stays_held(): void
    {
        $file = $this->picture();
        $key = $this->variantKey($file);
        $this->hold('v', $key);
        $this->atNap[1] = fn () => $this->disk()->put($key, 'placed');

        $this->assertSame('placed', $this->variant($file));
        $this->assertSame(5, $this->naps);
        $this->assertSame(0, $this->draws);
    }

    public function test_the_waiter_reads_the_cache_once_every_five_naps(): void
    {
        $file = $this->picture();
        $key = $this->variantKey($file);
        $reads = 0;
        $this->diskWith(exists: function (string $path, Closure $real) use ($key, &$reads): bool {
            $reads += $path === $key ? 1 : 0;

            return $real($path);
        });
        $this->hold('v', $key);

        $this->assertThrows(fn () => $this->variant($file), ImageDrawBusyException::class);
        // The miss before the lock, nineteen reads at naps 5 to 95, and the last look at 100.
        $this->assertSame(21, $reads);
    }

    public function test_a_read_that_comes_back_empty_is_a_miss_not_an_empty_picture(): void
    {
        $file = $this->picture();
        $key = $this->variantKey($file);
        $first = true;
        $this->diskWith(
            exists: function (string $path, Closure $real) use ($key, &$first): bool {
                if ($path !== $key) {
                    return $real($path);
                }

                return ! $first || ($first = false);
            },
            get: fn (string $path, Closure $real): ?string => $path === $key ? null : $real($path),
        );
        $held = $this->hold('v', $key);
        $this->atNap[7] = fn () => flock($held, LOCK_UN);

        $this->assertNotSame('', $this->variant($file));
        $this->assertSame(1, $this->draws);
    }

    public function test_a_holder_serves_what_was_placed_between_the_miss_and_the_lock(): void
    {
        $file = $this->picture();
        $key = $this->variantKey($file);
        $first = true;
        $this->diskWith(exists: function (string $path, Closure $real) use ($key, &$first): bool {
            if ($path === $key && $first) {
                $first = false;
                $this->disk()->put($key, 'placed');

                return false;
            }

            return $real($path);
        });

        $this->assertSame('placed', $this->variant($file));
        $this->assertSame(0, $this->draws);
    }

    public function test_a_holder_that_throws_lets_go_and_leaves_its_note(): void
    {
        $file = $this->picture();
        $key = $this->variantKey($file);
        $this->whileDrawing = fn () => throw new ImageProcessingException('refused');

        $this->assertThrows(fn () => $this->variant($file), ImageProcessingException::class);
        $this->assertTrue($this->takeable('v', $key));
        $this->assertMatchesRegularExpression('/\A'.sha1($key).'[0-9a-f]{16}\z/', (string) file_get_contents($this->lockPath('v', $key)));
    }

    public function test_a_variant_holder_takes_its_canonical_when_both_keys_share_a_file_prefix(): void
    {
        $file = $this->sharingPrefix($this->picture());
        $this->assertSame(
            substr(sha1($this->variantKey($file)), 0, 2),
            substr(sha1($this->canonicalKey($file)), 0, 2),
        );

        $this->assertNotSame('', $this->variant($file));
        $this->assertSame(2, $this->draws);
        $this->assertSame(0, $this->naps);
    }

    public function test_a_lock_directory_that_cannot_be_made_draws_as_before_and_is_reported(): void
    {
        Exceptions::fake();
        $this->app->when(ImageCache::class)->needs('$lockDirectory')->give('/dev/null/image-locks');
        $file = $this->picture();

        $this->assertNotSame('', $this->variant($file));
        $this->assertSame(1, $this->draws);
        Exceptions::assertReported(fn (RuntimeException $e): bool => str_contains($e->getMessage(), 'cannot be made'));
    }

    public function test_an_unusable_lock_is_reported_once_for_a_variant_and_its_canonical(): void
    {
        Exceptions::fake();
        $this->app->when(ImageCache::class)->needs('$lockDirectory')->give('/dev/null/image-locks');
        $file = $this->picture();
        $this->dropCanonical($file);

        $this->assertNotSame('', $this->variant($file));
        $this->assertSame(2, $this->draws);
        Exceptions::assertReportedCount(1);
    }

    public function test_a_lock_file_that_cannot_be_opened_draws_as_before_and_is_reported(): void
    {
        Exceptions::fake();
        $file = $this->picture();
        mkdir($this->lockPath('v', $this->variantKey($file)), 0777, true);

        $this->assertNotSame('', $this->variant($file));
        $this->assertSame(1, $this->draws);
        Exceptions::assertReported(fn (RuntimeException $e): bool => str_contains($e->getMessage(), 'cannot be opened'));
    }

    public function test_a_log_that_cannot_be_written_still_draws_without_an_unusable_lock(): void
    {
        $this->app->when(ImageCache::class)->needs('$lockDirectory')->give('/dev/null/image-locks');
        $file = $this->picture();
        $this->breakTheLog();

        $this->assertNotSame('', $this->variant($file));
        $this->assertSame(1, $this->draws);
    }

    public function test_a_log_that_cannot_be_written_still_gives_up_the_wait_as_busy(): void
    {
        $file = $this->picture();
        $this->hold('v', $this->variantKey($file));
        $this->breakTheLog();

        $this->assertThrows(fn () => $this->variant($file), ImageDrawBusyException::class);
    }

    public function test_a_viewer_who_waits_out_the_deadline_is_answered_503_with_a_retry_hint(): void
    {
        $file = $this->picture();
        $this->dropCanonical($file);
        $this->hold('c', $this->canonicalKey($file));

        $this->actingAs(Member::findOrFail($file->related_entity_id))
            ->get(route('image.show', ['format' => 'png', 'geometry' => 'w_h', 'name' => $file->name, 'ext' => 'png']))
            ->assertStatus(503)
            ->assertHeader('Retry-After', '30');
        $this->assertSame(0, $this->draws);
    }

    public function test_a_placed_check_that_throws_keeps_the_draw_and_leaves_the_note(): void
    {
        $drawn = (new ImageDrawLock($this->imageLockDirectory()))
            ->run('v', 'key', fn (): ?string => null, fn (): string => 'drawn', fn (): bool => throw new RuntimeException('unreadable'));

        $this->assertSame('drawn', $drawn);
        $this->assertTrue($this->takeable('v', 'key'));
        $this->assertMatchesRegularExpression('/\A'.sha1('key').'[0-9a-f]{16}\z/', (string) file_get_contents($this->lockPath('v', 'key')));
    }

    private function drawing(): void
    {
        $this->draws++;

        if ($this->whileDrawing !== null) {
            ($this->whileDrawing)();
        }
    }

    private function variant(File $file): string
    {
        return app(ImageCache::class)->bytes($file, ImageTransform::fromGeometry(self::VARIANT), 'png');
    }

    private function picture(): File
    {
        $file = app(FileUploader::class)->store(UploadedFile::fake()->image('a.png', 240, 120), 'member', (int) Member::factory()->create()->getKey());
        $this->draws = 0;

        return $file;
    }

    /** A copy of $file under a name whose variant and canonical keys pick files of the same prefix in their own sets. */
    private function sharingPrefix(File $file): File
    {
        do {
            $name = Str::random(40);
            $variant = ImageTransform::fromGeometry(self::VARIANT)->cacheKey($name, 'png');
            $canonical = ImageTransform::raw()->cacheKey($name, (string) $file->imageFormat());
        } while (substr(sha1($variant), 0, 2) !== substr(sha1($canonical), 0, 2));

        $copy = $file->replicate();
        $copy->name = $name;
        $copy->save();
        app(FileStorage::class)->writeStream($copy, app(FileStorage::class)->readStream($file));

        return $copy;
    }

    private function variantKey(File $file): string
    {
        return ImageTransform::fromGeometry(self::VARIANT)->cacheKey($file->name, 'png');
    }

    private function canonicalKey(File $file): string
    {
        return ImageTransform::raw()->cacheKey($file->name, (string) $file->imageFormat());
    }

    private function markerKey(File $file): string
    {
        return ImageTransform::encoderPrefix($file->name).'/w_h.failed';
    }

    private function dropCanonical(File $file): void
    {
        $this->disk()->delete($this->canonicalKey($file));
    }

    private function lockPath(string $set, string $key): string
    {
        return $this->imageLockDirectory().'/'.$set.'-'.substr(sha1($key), 0, 2).'.lock';
    }

    /** @return resource */
    private function hold(string $set, string $key)
    {
        @mkdir($this->imageLockDirectory(), 0777, true);
        $handle = fopen($this->lockPath($set, $key), 'c+');
        flock($handle, LOCK_EX);

        return $this->held[] = $handle;
    }

    private function takeable(string $set, string $key): bool
    {
        $handle = fopen($this->lockPath($set, $key), 'c+');

        try {
            return flock($handle, LOCK_EX | LOCK_NB);
        } finally {
            fclose($handle);
        }
    }

    private function note(string $key): string
    {
        return sha1($key).bin2hex(random_bytes(8));
    }

    private function write(string $set, string $key, string $content): void
    {
        $handle = fopen($this->lockPath($set, $key), 'c+');
        fwrite($handle, $content);
        fclose($handle);
    }

    /**
     * @param  (Closure(string, Closure(string): bool): bool)|null  $exists
     * @param  (Closure(string, Closure(string): ?string): ?string)|null  $get
     */
    private function diskWith(?Closure $exists = null, ?Closure $get = null): void
    {
        $fake = Storage::disk('image_cache');
        assert($fake instanceof FilesystemAdapter);

        Storage::set('image_cache', new class($fake->getDriver(), $fake->getAdapter(), $fake->getConfig(), $exists, $get) extends FilesystemAdapter
        {
            public function __construct($driver, $adapter, array $config, private readonly ?Closure $onExists, private readonly ?Closure $onGet)
            {
                parent::__construct($driver, $adapter, $config);
            }

            public function exists($path)
            {
                return $this->onExists === null ? parent::exists($path) : ($this->onExists)($path, fn (string $p): bool => parent::exists($p));
            }

            public function get($path)
            {
                return $this->onGet === null ? parent::get($path) : ($this->onGet)($path, fn (string $p): ?string => parent::get($p));
            }
        });
    }

    private function breakTheLog(): void
    {
        config(['logging.default' => 'unwritable', 'logging.channels.unwritable' => ['driver' => 'single', 'path' => '/dev/null/laravel.log']]);
    }

    private function disk(): Filesystem
    {
        return Storage::disk('image_cache');
    }
}
