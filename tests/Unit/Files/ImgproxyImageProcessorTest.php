<?php

declare(strict_types=1);

namespace Tests\Unit\Files;

use App\Files\ImageProcessingException;
use App\Files\ImageProcessorUnavailableException;
use App\Files\ImageSpec;
use App\Files\Imgproxy\ImgproxyImageProcessor;
use App\Files\Imgproxy\ImgproxyUrl;
use App\Files\Imgproxy\Spool;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Gif\Builder;
use Intervention\Gif\Decoder;
use League\Flysystem\UnableToRetrieveMetadata;
use Mockery;
use Tests\TestCase;
use Throwable;

/** The sidecar is a MockHandler here; the live answers it is modelled on are pinned by the contract test. */
class ImgproxyImageProcessorTest extends TestCase
{
    private const KEY = '943b421c9eb07c830af81030552c86009268de4e532ba2ee2eab8247c6da0881';

    private const SALT = '520f986b998545b4785e0defbc4f3c1203f22de2374a3d53cb7a7fe9fea309c5';

    /** @var list<array{request: Request}> */
    private array $history = [];

    private int $spooledDuringRequest = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('image_spool');
        Log::spy();
        config(['openpne.images.imgproxy' => [
            'url' => 'http://imgproxy.test/',
            'key' => self::KEY,
            'salt' => self::SALT,
            'source_prefix' => 'spool/',
            'timeout' => 1,
            'spool_disk' => 'image_spool',
        ]]);
    }

    public function test_the_signature_is_hmac_sha256_over_salt_and_path_in_base64url(): void
    {
        // Pinned against what a real imgproxy accepted for this key and salt.
        $url = ImgproxyUrl::fromConfig(config('openpne.images.imgproxy'));

        $this->assertSame(
            'http://imgproxy.test/nqZWzvqIujQEO5kZAgLXzvn5Pm8uScydH7Dm_qrDDZw/q:85/plain/local:///spool/abc.png@png',
            $url->signed('q:85', 'abc.png', 'png'),
        );
        $this->assertSame('http://imgproxy.test/health', $url->health());
    }

    public function test_a_variant_asks_for_one_frame_of_the_spooled_file_and_a_canonical_for_them_all(): void
    {
        $processor = $this->processor(new Response(200, ['Content-Type' => 'image/png'], $this->png(4, 4)), new Response(200, ['Content-Type' => 'image/png'], $this->png(4, 4)));

        $processor->process($this->png(8, 8), 'image/png', ImageSpec::fit(120, 120, 'png'));
        $processor->process($this->png(8, 8), 'image/png', ImageSpec::canonical('png'));

        $this->assertMatchesRegularExpression(
            '#^/[A-Za-z0-9_-]{43}/sm:1/scp:1/ar:1/kcr:0/q:85/maf:1/rt:fit/w:120/h:120/el:0/plain/local:///spool/[A-Za-z0-9]{32}\.png@png$#',
            $this->history[0]['request']->getUri()->getPath(),
        );
        $this->assertMatchesRegularExpression('#/kcr:0/q:85/maf:200/plain/local:///spool/[A-Za-z0-9]{32}\.png@png$#', $this->history[1]['request']->getUri()->getPath());
    }

    public function test_a_cover_asks_for_the_exact_box_and_a_background_flattens(): void
    {
        $processor = $this->processor(new Response(200, ['Content-Type' => 'image/png'], $this->png(4, 4)));

        $processor->process($this->png(8, 8), 'image/png', ImageSpec::cover(16, 16, 'png')->withBackground('ffffff'));

        $this->assertStringContainsString('/maf:1/rt:fill/w:16/h:16/el:1/g:ce/bg:ffffff/plain/', $this->history[0]['request']->getUri()->getPath());
    }

    public function test_the_result_carries_the_output_size_mime_and_animation(): void
    {
        $gif = $this->animatedGif(3);
        $processor = $this->processor(new Response(200, ['Content-Type' => 'image/gif'], $gif));

        $processed = $processor->process($gif, 'image/gif', ImageSpec::canonical('gif'));

        $this->assertSame([60, 60, 'image/gif', true], [$processed->width, $processed->height, $processed->mime, $processed->animated]);
        $this->assertSame(3, count(Decoder::decode($processed->bytes)->frames()));
    }

    public function test_a_422_is_the_verdict_on_the_bytes_and_a_gif_canonical_is_asked_again_as_a_still(): void
    {
        $gif = $this->animatedGif(3);
        $processor = $this->processor(new Response(422, [], 'Invalid source image'), new Response(200, ['Content-Type' => 'image/gif'], $this->animatedGif(1)));

        $processed = $processor->process($gif, 'image/gif', ImageSpec::canonical('gif'));

        $this->assertFalse($processed->animated);
        $this->assertCount(2, $this->history);
        $this->assertStringContainsString('/maf:1/', $this->history[1]['request']->getUri()->getPath());

        // A JPEG has no frames to give up: one request, one verdict.
        $processor = $this->processor(new Response(422, [], 'Invalid source image'));
        try {
            $processor->process($this->png(8, 8), 'image/jpeg', ImageSpec::canonical('jpg'));
            $this->fail('A 422 was not a refusal.');
        } catch (ImageProcessingException $e) {
            $this->assertStringContainsString('Invalid source image', $e->getMessage());
        }
        $this->assertCount(1, $this->history);
    }

    public function test_a_long_refusal_is_still_a_refusal(): void
    {
        $processor = $this->processor(new Response(422, [], str_repeat('Invalid source image. ', 200)));

        $this->assertThrows(fn () => $processor->process($this->png(8, 8), 'image/png', ImageSpec::canonical('png')), ImageProcessingException::class);
    }

    public function test_a_variant_of_an_animation_is_reported_still_without_a_walk(): void
    {
        $processor = $this->processor(new Response(200, ['Content-Type' => 'image/gif'], $this->animatedGif(3)));

        $this->assertFalse($processor->process($this->animatedGif(3), 'image/gif', ImageSpec::fit(30, 30, 'gif'))->animated);
    }

    public function test_every_other_answer_is_an_outage_that_is_logged(): void
    {
        // 500 included: the sidecar's health endpoint cannot tell bytes it cannot load from a moment it could not.
        foreach ([500 => 'Internal error', 429 => 'Too many requests', 503 => 'Timeout', 502 => 'Bad Gateway', 403 => 'Forbidden', 404 => 'Source is unreachable', 400 => 'Bad request'] as $status => $body) {
            $processor = $this->processor(new Response($status, [], $body));

            try {
                $processor->process($this->png(8, 8), 'image/png', ImageSpec::canonical('png'));
                $this->fail("A {$status} was not an outage.");
            } catch (ImageProcessorUnavailableException $e) {
                $this->assertStringContainsString((string) $status, $e->getMessage());
            }
        }

        Log::shouldHaveReceived('error')->times(7);
    }

    public function test_no_connection_an_answer_over_the_cap_and_bytes_of_another_format_are_outages_too(): void
    {
        $processor = $this->processor(new ConnectException('timed out', new Request('GET', 'http://imgproxy.test/')));
        $this->assertThrows(fn () => $processor->process($this->png(8, 8), 'image/png', ImageSpec::canonical('png')), ImageProcessorUnavailableException::class);

        // A JPEG was asked for; a PNG under the cache key's .jpg would be served with the wrong type.
        $processor = $this->processor(new Response(200, ['Content-Type' => 'image/png'], $this->png(4, 4)));
        $this->assertThrows(fn () => $processor->process($this->png(8, 8), 'image/jpeg', ImageSpec::canonical('jpg')), ImageProcessorUnavailableException::class);

        // A sound picture over the cap, so the cap alone is what refuses it.
        config(['openpne.images.max_source_kilobytes' => 1]);
        $processor = $this->processor(new Response(200, ['Content-Type' => 'image/png'], $this->noisyPng(80, 80)));
        $this->assertGreaterThan(4096, strlen($this->noisyPng(80, 80)));
        $this->assertThrows(fn () => $processor->process($this->png(8, 8), 'image/png', ImageSpec::canonical('png')), ImageProcessorUnavailableException::class);

        Log::shouldHaveReceived('error')->times(3);
    }

    public function test_a_stale_file_another_worker_swept_first_does_not_fail_the_request(): void
    {
        // Listed, then gone before its age is read: the adapter throws here whatever the disk's `throw` says.
        $disk = Storage::disk('image_spool');
        $disk->put('dead.png', 'x');
        $racing = Mockery::mock($disk)->makePartial();
        $racing->shouldReceive('lastModified')->with('dead.png')->andThrow(UnableToRetrieveMetadata::lastModified('dead.png'));
        Storage::set('image_spool', $racing);
        $processor = $this->processor(new Response(200, ['Content-Type' => 'image/png'], $this->png(4, 4)));

        $processed = $processor->process($this->png(8, 8), 'image/png', ImageSpec::canonical('png'));

        $this->assertSame([4, 4], [$processed->width, $processed->height]);
        $this->assertSame(['dead.png'], $disk->files(), 'The request must leave no spool file of its own behind.');
    }

    private function processor(Response|Throwable ...$answers): ImgproxyImageProcessor
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($answers));
        $stack->push(Middleware::history($this->history));
        $stack->push(fn (callable $handler) => function (Request $request, array $options) use ($handler) {
            $this->spooledDuringRequest = count(Storage::disk('image_spool')->files());

            return $handler($request, $options);
        });

        return new ImgproxyImageProcessor(
            new Client(['handler' => $stack]),
            ImgproxyUrl::fromConfig(config('openpne.images.imgproxy')),
            new Spool('image_spool'),
        );
    }

    private function png(int $width, int $height): string
    {
        $gd = imagecreatetruecolor($width, $height);
        imagefill($gd, 0, 0, (int) imagecolorallocate($gd, 200, 30, 30));
        ob_start();
        imagepng($gd);

        return (string) ob_get_clean();
    }

    private function noisyPng(int $width, int $height): string
    {
        mt_srand(7);
        $gd = imagecreatetruecolor($width, $height);
        for ($x = 0; $x < $width; $x++) {
            for ($y = 0; $y < $height; $y++) {
                imagesetpixel($gd, $x, $y, (int) imagecolorallocate($gd, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
            }
        }
        ob_start();
        imagepng($gd);

        return (string) ob_get_clean();
    }

    private function animatedGif(int $frames): string
    {
        $builder = Builder::canvas(60, 60);

        for ($i = 0; $i < $frames; $i++) {
            $gd = imagecreate(60, 60);
            imagecolorallocate($gd, ($i * 37) % 256, 40, 200);
            ob_start();
            imagegif($gd);
            $builder->addFrame(source: (string) ob_get_clean(), delay: 0.1);
        }

        return $builder->encode();
    }
}
