<?php

namespace Tests\Feature\File;

use App\Features\Timeline\Actions\CreateTimelinePost;
use App\Features\Timeline\Data\TimelinePostFormData;
use App\Files\CanonicalUnavailableException;
use App\Files\FileStorage;
use App\Files\FileUploader;
use App\Files\ImageCache;
use App\Files\ImageIntake;
use App\Files\ImageLadder;
use App\Files\ImageProcessingException;
use App\Files\ImageProcessor;
use App\Files\ImageProcessorUnavailableException;
use App\Files\ImageSpec;
use App\Files\ImageTransform;
use App\Files\PostImages;
use App\Files\ProcessedImage;
use App\Jobs\DrawImageVariants;
use App\Models\File;
use App\Models\Member;
use App\Support\Visibility;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class DrawImageVariantsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('image_cache');
    }

    public function test_it_draws_every_still_rung_of_the_ladder(): void
    {
        $file = $this->picture();
        $stills = ImageLadder::stills($file);

        $this->draw($file);

        $this->assertCount(8, $stills);
        foreach ($stills as [$transform, $format]) {
            $this->assertTrue(Storage::disk('image_cache')->exists($transform->cacheKey($file->name, $format)), $transform->cacheKey($file->name, $format));
        }
    }

    public function test_a_file_that_is_gone_draws_nothing(): void
    {
        $file = $this->picture();
        $file->delete();

        $this->draw($file);

        $this->assertSame([], Storage::disk('image_cache')->allFiles(ImageTransform::directory($file->name)));
    }

    public function test_a_file_deleted_while_a_rung_is_drawn_ends_the_run_and_leaves_nothing(): void
    {
        $file = $this->picture();
        $drawn = 0;
        $this->process(function (ImageProcessor $inner, string $bytes, string $mime, ImageSpec $spec) use ($file, &$drawn): ProcessedImage {
            $drawn++;
            $processed = $inner->process($bytes, $mime, $spec);
            File::query()->whereKey($file->getKey())->first()?->delete();

            return $processed;
        });

        $this->draw($file);

        $this->assertSame(1, $drawn);
        $this->assertSame([], Storage::disk('image_cache')->allFiles(ImageTransform::directory($file->name)));
    }

    public function test_a_processor_that_is_down_ends_the_run_without_failing_the_job(): void
    {
        $file = $this->picture();
        $asked = 0;
        $this->process(function () use (&$asked): never {
            $asked++;

            throw new ImageProcessorUnavailableException('down');
        });

        $this->draw($file);

        $this->assertSame(1, $asked);
    }

    /** @return array<string, array{Throwable}> */
    public static function refusals(): array
    {
        return [
            'canonical refused' => [new CanonicalUnavailableException('refused')],
            'rung refused' => [new ImageProcessingException('refused')],
        ];
    }

    #[DataProvider('refusals')]
    public function test_a_refusal_ends_the_run_without_failing_the_job(Throwable $refusal): void
    {
        $file = $this->picture();
        $cache = Mockery::mock(ImageCache::class);
        $cache->shouldReceive('bytes')->once()->andThrow($refusal);

        (new DrawImageVariants((int) $file->getKey()))->handle($cache);
    }

    public function test_a_read_that_fails_because_the_file_was_deleted_ends_the_run_and_any_other_failure_is_thrown(): void
    {
        $file = $this->picture();
        $cache = Mockery::mock(ImageCache::class);
        $cache->shouldReceive('bytes')->once()->andReturnUsing(function () use ($file): never {
            File::query()->whereKey($file->getKey())->delete();

            throw new RuntimeException('No stored bytes');
        });

        (new DrawImageVariants((int) $file->getKey()))->handle($cache);

        $kept = $this->picture();
        $cache = Mockery::mock(ImageCache::class);
        $cache->shouldReceive('bytes')->once()->andThrow(new RuntimeException('the disk failed'));

        $this->expectExceptionMessage('the disk failed');
        (new DrawImageVariants((int) $kept->getKey()))->handle($cache);
    }

    /** @return array<string, array{string}> */
    public static function ladderTypes(): array
    {
        return array_map(fn (string $type): array => [$type], array_combine($types = [
            'timelinePost', 'diary', 'diaryComment', 'groupTopic', 'groupTopicComment',
            'groupEvent', 'groupEventComment', 'directMessage', 'groupMessage',
        ], $types));
    }

    #[DataProvider('ladderTypes')]
    public function test_a_picture_stored_for_a_post_is_queued_for_after_the_commit(string $type): void
    {
        config(['queue.default' => 'database']);
        Queue::fake();

        $file = app(PostImages::class)->compensating(fn (callable $store): File => $store(UploadedFile::fake()->image('a.png', 40, 20), $type, 1));

        Queue::assertPushed(DrawImageVariants::class, 1);
        Queue::assertPushed(fn (DrawImageVariants $job): bool => $job->fileId === $file->getKey() && $job->delay === 10);
    }

    public function test_posting_with_a_picture_queues_its_drawing(): void
    {
        config(['queue.default' => 'database']);
        Queue::fake([DrawImageVariants::class]);

        $post = app(CreateTimelinePost::class)(Member::factory()->create(), new TimelinePostFormData('pic', Visibility::Members), UploadedFile::fake()->image('p.png', 20, 20));

        Queue::assertPushed(fn (DrawImageVariants $job): bool => $job->fileId === $post->fresh()->images->first()->file_id);
    }

    public function test_the_job_reaches_the_queue_only_once_the_post_has_committed(): void
    {
        // The real connection: the queue fake records a push at once, whatever afterCommit says.
        config(['queue.default' => 'database']);

        app(PostImages::class)->compensating(function (callable $store): void {
            $store(UploadedFile::fake()->image('a.png', 40, 20), 'diary', 1);

            $this->assertDatabaseCount('jobs', 0);
        });

        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_a_queue_that_refuses_the_job_leaves_the_committed_post_and_its_bytes(): void
    {
        config(['queue.default' => 'database', 'queue.connections.database.table' => 'no_such_table']);
        Exceptions::fake();

        $file = app(PostImages::class)->compensating(fn (callable $store): File => $store(UploadedFile::fake()->image('a.png', 40, 20), 'diary', 1));

        Exceptions::assertReported(QueryException::class);
        $this->assertDatabaseHas('files', ['id' => $file->getKey()]);
        $this->assertNotSame('', stream_get_contents(app(FileStorage::class)->readStream($file)));
        $this->assertTrue(app(ImageCache::class)->hasCanonical($file));
    }

    public function test_a_post_that_rolls_back_queues_nothing(): void
    {
        config(['queue.default' => 'database']);

        try {
            app(PostImages::class)->compensating(function (callable $store): never {
                $store(UploadedFile::fake()->image('a.png', 40, 20), 'diary', 1);

                throw new RuntimeException('the post failed');
            });
        } catch (RuntimeException) {
        }

        $this->assertDatabaseCount('jobs', 0);
    }

    #[DataProvider('driversWithoutAWorker')]
    public function test_nothing_is_queued_where_the_job_would_run_in_the_posting_request(string $connection): void
    {
        config(['queue.default' => $connection]);
        Queue::fake();

        app(PostImages::class)->compensating(fn (callable $store): File => $store(UploadedFile::fake()->image('a.png', 40, 20), 'diary', 1));

        Queue::assertNothingPushed();
    }

    /** @return array<string, array{string}> */
    public static function driversWithoutAWorker(): array
    {
        return ['sync' => ['sync'], 'deferred' => ['deferred'], 'background' => ['background'], 'failover' => ['failover']];
    }

    public function test_a_picture_that_is_not_a_posts_is_not_queued(): void
    {
        config(['queue.default' => 'database']);
        Queue::fake();

        app(PostImages::class)->compensating(fn (callable $store): File => $store(UploadedFile::fake()->image('a.png', 40, 20), 'member', 1));

        Queue::assertNothingPushed();
    }

    private function draw(File $file): void
    {
        (new DrawImageVariants((int) $file->getKey()))->handle(app(ImageCache::class));
    }

    private function picture(): File
    {
        return app(FileUploader::class)->store(UploadedFile::fake()->image('a.png', 240, 120), 'diary', 1);
    }

    /** @param  Closure(ImageProcessor, string, string, ImageSpec): ProcessedImage  $process */
    private function process(Closure $process): void
    {
        $inner = app(ImageProcessor::class);

        $this->app->instance(ImageProcessor::class, new class($inner, $process) implements ImageProcessor
        {
            public function __construct(private readonly ImageProcessor $inner, private readonly Closure $process) {}

            public function process(string $bytes, string $mime, ImageSpec $spec): ProcessedImage
            {
                return ($this->process)($this->inner, $bytes, $mime, $spec);
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
    }
}
