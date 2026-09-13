<?php

declare(strict_types=1);

namespace Tests\Feature\LinkCard;

use App\Files\FileStorage;
use App\Files\FileUploader;
use App\Files\GdImageProcessor;
use App\Files\ImageCache;
use App\Files\ImageIntake;
use App\Files\ImageProcessor;
use App\Files\ImageProcessorUnavailableException;
use App\Files\ImageSpec;
use App\Files\ProcessedImage;
use App\LinkCard\LinkCardImage;
use App\Models\File;
use App\Models\LinkCard;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Intervention\Gif\Builder;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\ImageManager;
use RuntimeException;
use Tests\Concerns\FakesOutboundTransport;
use Tests\TestCase;

/**
 * The import runs against the real SafeHttpFetcher and FileUploader with only the socket and the
 * resolver fake, and what is asserted is mostly order: the size checks must run before anything
 * decodes.
 */
class LinkCardImageTest extends TestCase
{
    use FakesOutboundTransport;
    use RefreshDatabase;

    public function test_it_stores_a_fetched_image_as_a_local_file(): void
    {
        $card = $this->card();
        $this->resolvesTo('cdn.example.com', ['93.184.216.34']);
        $this->queueBinary($this->png(40, 30), 'image/png');

        $result = $this->importer()->import('https://cdn.example.com/hero.png', $card->id);

        $this->assertNotNull($result);
        $this->assertSame(40, $result['width']);
        $this->assertSame(30, $result['height']);
        $this->assertSame('image/png', $result['file']->type);
        $this->assertSame('link_card', $result['file']->related_entity_type);
        $this->assertSame($card->id, $result['file']->related_entity_id);
    }

    public function test_a_card_image_is_not_stored_as_a_public_asset(): void
    {
        $card = $this->card();
        $this->resolvesTo('cdn.example.com', ['93.184.216.34']);
        $this->queueBinary($this->png(10, 10), 'image/png');

        $result = $this->importer()->import('https://cdn.example.com/hero.png', $card->id);

        $this->assertNotNull($result);
        $this->assertNull(
            $result['file']->explicit_visibility,
            'Card images must stay fail-closed until delivery is designed against the referencing body.',
        );
        $this->assertNotSame(File::VISIBILITY_PUBLIC, $result['file']->explicit_visibility);
    }

    public function test_an_oversized_image_is_refused_before_it_is_decoded(): void
    {
        // A PNG header can claim 40000x40000 in a handful of bytes, so the assertion is that no
        // decode happened at all, not merely that the result was null.
        config()->set('openpne.images.max_upload_dimension', 100);

        $card = $this->card();
        $this->resolvesTo('cdn.example.com', ['93.184.216.34']);
        $this->queueBinary($this->pngHeaderClaiming(40000, 40000), 'image/png');

        $decoder = $this->spyDecoder();

        $this->assertNull($this->importer($decoder)->import('https://cdn.example.com/bomb.png', $card->id));
        $this->assertSame(0, $decoder->calls, 'The image was decoded despite its header exceeding the limit.');
        $this->assertSame(0, File::count());
    }

    public function test_an_image_within_the_limit_is_decoded_before_being_stored(): void
    {
        // The other half of the ordering contract: the decode is skipped only because the header was
        // rejected, not because nothing ever decodes.
        $card = $this->card();
        $this->resolvesTo('cdn.example.com', ['93.184.216.34']);
        $this->queueBinary($this->png(20, 20), 'image/png');

        $decoder = $this->spyDecoder();

        $this->assertNotNull($this->importer($decoder)->import('https://cdn.example.com/ok.png', $card->id));
        $this->assertSame(1, $decoder->calls);
    }

    public function test_an_image_within_each_side_but_over_the_pixel_budget_is_refused(): void
    {
        // The per-side limit alone is not a memory bound: at the 5000 default, 5000 x 5000 x 4 is
        // 100 MB decoded — enough to end a 128 MB worker on its own.
        config()->set('openpne.images.max_upload_dimension', 5000);
        config()->set('openpne.outbound.max_image_pixels', 4_000_000);

        $card = $this->card();
        $this->resolvesTo('cdn.example.com', ['93.184.216.34']);
        $this->queueBinary($this->pngHeaderClaiming(4000, 4000), 'image/png');

        $decoder = $this->spyDecoder();

        $this->assertNull($this->importer($decoder)->import('https://cdn.example.com/wide.png', $card->id));
        $this->assertSame(0, $decoder->calls, '16 MP was decoded despite the pixel budget.');
    }

    public function test_an_animated_image_is_imported_like_any_other(): void
    {
        // The frame count is nobody's concern before the decode: GD reads one frame and the sidecar
        // decodes out of process, so the two-frame GIF is decoded once and stored.
        $card = $this->card();
        $this->resolvesTo('cdn.example.com', ['93.184.216.34']);
        $this->queueBinary($this->animatedGif(), 'image/gif');

        $decoder = $this->spyDecoder();

        $result = $this->importer($decoder)->import('https://cdn.example.com/anim.gif', $card->id);

        $this->assertNotNull($result);
        $this->assertSame(1, $decoder->calls);
        $this->assertSame(1, File::count());
        $this->assertSame($decoder->preservesAnimation(), $result['file']->animated);
    }

    public function test_a_processor_outage_is_let_through_with_nothing_stored(): void
    {
        // The one failure that is not "no picture": the job decides when to ask again.
        $card = $this->card();
        $this->resolvesTo('cdn.example.com', ['93.184.216.34']);
        $this->queueBinary($this->png(10, 10), 'image/png');
        $staging = $this->stagingDirectory();

        try {
            $this->importer($this->outageProcessor(), $staging)->import('https://cdn.example.com/hero.png', $card->id);
            $this->fail('The outage was swallowed.');
        } catch (ImageProcessorUnavailableException) {
            $this->assertSame(0, File::count());
            $this->assertSame([], $this->stagedFiles($staging));
        }
    }

    public function test_an_animated_webp_is_refused_by_the_gd_decoder(): void
    {
        // libgd reads no animated WebP (tests/Fixtures/images/README.md), so under GD the decode is what
        // refuses it; the sidecar would import it like any other animation.
        $card = $this->card();
        $this->resolvesTo('cdn.example.com', ['93.184.216.34']);
        $this->queueBinary((string) file_get_contents(base_path('tests/Fixtures/images/webp-animated-3frames.webp')), 'image/webp');
        $gd = new GdImageProcessor(new ImageManager(GdDriver::class, decodeAnimation: false));

        $this->assertNull($this->importer($gd)->import('https://cdn.example.com/anim.webp', $card->id));
        $this->assertSame(0, File::count());
    }

    public function test_a_still_gif_is_still_accepted(): void
    {
        $card = $this->card();
        $this->resolvesTo('cdn.example.com', ['93.184.216.34']);
        $this->queueBinary($this->stillGif(), 'image/gif');

        $this->assertNotNull($this->importer()->import('https://cdn.example.com/still.gif', $card->id));
    }

    public function test_a_header_only_forgery_is_refused_by_the_gd_decoder(): void
    {
        // Passes finfo and getimagesizefromstring with nothing behind the header; GD refuses the pixel
        // data, where libvips decodes garbage to something (docs/internals/images.md), so GD is bound by name.
        $card = $this->card();
        $this->resolvesTo('cdn.example.com', ['93.184.216.34']);
        $this->queueBinary($this->pngHeaderClaiming(10, 10), 'image/png');
        $gd = new GdImageProcessor(new ImageManager(GdDriver::class, decodeAnimation: false));

        $this->assertNull($this->importer($gd)->import('https://cdn.example.com/hollow.png', $card->id));
        $this->assertSame(0, File::count());
    }

    public function test_the_canonical_re_encode_happens_exactly_once(): void
    {
        // One decode in the pipeline, inside FileUploader: probing here as well would decode twice.
        $card = $this->card();
        $this->resolvesTo('cdn.example.com', ['93.184.216.34']);
        $this->queueBinary($this->jpeg(20, 20), 'image/jpeg');
        $decoder = $this->spyDecoder();

        $this->assertNotNull($this->importer($decoder)->import('https://cdn.example.com/p.jpg', $card->id));
        $this->assertSame(1, $decoder->calls);
    }

    public function test_the_declared_content_type_is_not_believed(): void
    {
        // Content-Type is the far end's claim; a script served as image/png must not become a stored
        // image.
        $card = $this->card();
        $this->resolvesTo('cdn.example.com', ['93.184.216.34']);
        $this->queueBinary('<?php echo "not a png";', 'image/png');

        $this->assertNull($this->importer()->import('https://cdn.example.com/fake.png', $card->id));
        $this->assertSame(0, File::count());
    }

    public function test_an_svg_is_refused(): void
    {
        // SVG is a document with scripting, not a picture, and this one would be served from our own
        // origin as a public file.
        $card = $this->card();
        $this->resolvesTo('cdn.example.com', ['93.184.216.34']);
        $this->queueBinary('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'image/svg+xml');

        $this->assertNull($this->importer()->import('https://cdn.example.com/x.svg', $card->id));
    }

    public function test_a_truncated_image_is_refused(): void
    {
        // Unlike HTML, where the useful part comes first, a download cut short by the byte cap
        // decodes to nothing or to garbage.
        config()->set('openpne.outbound.max_image_bytes', 64);

        $card = $this->card();
        $this->resolvesTo('cdn.example.com', ['93.184.216.34']);
        $this->queueBinary($this->png(200, 200), 'image/png');

        $this->assertNull($this->importer()->import('https://cdn.example.com/big.png', $card->id));
        $this->assertSame(0, File::count());
    }

    public function test_an_image_on_a_private_address_is_never_fetched(): void
    {
        $card = $this->card();
        $this->resolvesTo('images.internal', ['10.0.0.5']);
        $this->queueBinary($this->png(10, 10), 'image/png');

        $this->assertNull($this->importer()->import('https://images.internal/x.png', $card->id));
        $this->assertSame([], $this->outboundRequests, 'The guard must refuse before a socket is opened.');
    }

    public function test_a_failed_response_is_refused(): void
    {
        $card = $this->card();
        $this->resolvesTo('cdn.example.com', ['93.184.216.34']);
        $this->queueResponse(new Response(404, ['Content-Type' => 'image/png'], ''));

        $this->assertNull($this->importer()->import('https://cdn.example.com/gone.png', $card->id));
    }

    public function test_it_leaves_no_temporary_file_behind(): void
    {
        $card = $this->card();
        $this->resolvesTo('cdn.example.com', ['93.184.216.34']);
        $this->queueBinary($this->png(10, 10), 'image/png');
        $this->queueBinary($this->png(10, 10), 'image/png');
        $staging = $this->stagingDirectory();
        $staged = [];

        $stored = $this->importer(staging: $staging, uploader: $this->watchingUploader($staged))
            ->import('https://cdn.example.com/ok.png', $card->id);
        $dropped = $this->importer(staging: $staging, uploader: $this->watchingUploader($staged, thenThrow: true))
            ->import('https://cdn.example.com/also-ok.png', $card->id);

        // Before the outcome, that the stimulus landed: an empty directory is equally what two
        // imports that never staged leave behind, and what an importer writing somewhere else leaves
        // behind, and this test reads emptiness as success.
        $this->assertNotNull($stored, 'The storing path did not store.');
        $this->assertNull($dropped, 'A throwing upload should leave import() with nothing to return.');
        $this->assertCount(2, $staged, 'Both paths must reach the uploader, or there was nothing staged to clean up.');
        foreach ($staged as $path) {
            $this->assertSame($staging, dirname($path), 'The importer staged outside the directory it was given.');
        }

        // Emptiness, not a count that came back to where it started: nobody else writes here, so
        // anything left is a file one of the two paths failed to clean up.
        $this->assertSame([], $this->stagedFiles($staging), 'A temp file survived one of the two paths.');
    }

    /**
     * The staged file is gone by the time the assertions run, so the only moment its path can be
     * seen is as it is passed on. Throwing is how the second path through `store()` is reached at
     * all: every refusal `import()` makes is decided above `store()`, so a body that is not an image
     * never stages anything.
     *
     * @param  list<string>  $staged
     */
    private function watchingUploader(array &$staged, bool $thenThrow = false): FileUploader
    {
        return new class($this->app->make(FileStorage::class), $this->app->make(ImageProcessor::class), $this->app->make(ImageCache::class), $staged, $thenThrow) extends FileUploader
        {
            /** @param  list<string>  $staged */
            public function __construct(
                FileStorage $storage,
                ImageProcessor $processor,
                ImageCache $cache,
                private array &$staged,
                private readonly bool $thenThrow,
            ) {
                parent::__construct($storage, $processor, $cache);
            }

            public function store(UploadedFile $upload, ?string $relatedType = null, ?int $relatedId = null, ?string $explicitVisibility = null): File
            {
                $this->staged[] = $upload->getPathname();

                if ($this->thenThrow) {
                    throw new RuntimeException('the upload failed after the bytes were staged');
                }

                return parent::store($upload, $relatedType, $relatedId, $explicitVisibility);
            }
        };
    }

    /**
     * A staging directory this test alone writes to, removed when it ends. The default is the system
     * temp directory every ParaTest worker shares, and a staged name says nothing about which
     * process wrote it, so watching that directory counts other workers' in-flight imports too.
     */
    private function stagingDirectory(): string
    {
        $dir = sys_get_temp_dir().'/linkcard-staging-'.getmypid().'-'.bin2hex(random_bytes(6));
        // Asserted rather than left to the framework: `tempnam` falls back to the shared directory
        // when the one it is given is missing, and `scandir` on a missing directory answers with the
        // empty list this test reads as success.
        $this->assertTrue(mkdir($dir, 0o700), 'Could not create the staging directory.');
        $this->beforeApplicationDestroyed(function () use ($dir) {
            array_map(fn (string $name) => @unlink($dir.'/'.$name), $this->stagedFiles($dir));
            @rmdir($dir);
        });

        return $dir;
    }

    /** @return list<string> */
    private function stagedFiles(string $dir): array
    {
        return array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
    }

    private function importer(?ImageProcessor $images = null, ?string $staging = null, ?FileUploader $uploader = null): LinkCardImage
    {
        // The decode now happens inside FileUploader, so a spy is bound where the uploader resolves it.
        if ($images !== null) {
            $this->app->instance(ImageProcessor::class, $images);
        }

        return new LinkCardImage(
            $this->fakeFetcher(),
            $uploader ?? $this->app->make(FileUploader::class),
            $staging,
        );
    }

    private function card(): LinkCard
    {
        return LinkCard::create(['url_hash' => str_repeat('a', 64), 'url' => 'https://example.com/']);
    }

    private function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private function jpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }

    private function stillGif(): string
    {
        $image = imagecreatetruecolor(10, 10);
        ob_start();
        imagegif($image);

        return (string) ob_get_clean();
    }

    /** Three frames of different shades, encoded by intervention/gif so any decoder reads them all. */
    private function animatedGif(): string
    {
        $builder = Builder::canvas(8, 8);

        for ($i = 0; $i < 3; $i++) {
            $gd = imagecreate(8, 8);
            imagecolorallocate($gd, ($i * 80) % 256, 40, 200);
            ob_start();
            imagegif($gd);
            $builder->addFrame(source: (string) ob_get_clean(), delay: 0.1);
        }

        $builder->setLoops(0);

        return $builder->encode();
    }

    /**
     * A PNG whose IHDR claims a huge size while the file stays tiny — the decompression-bomb shape.
     * Well-formed (CRCs, an IDAT, IEND) so finfo and the header read accept it and the size gate is what refuses it.
     */
    private function pngHeaderClaiming(int $width, int $height): string
    {
        $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NN', $width, $height)."\x08\x02\x00\x00\x00")
            .$chunk('IDAT', str_repeat("\xAB", 32))
            .$chunk('IEND', '');
    }

    private function outageProcessor(): ImageProcessor
    {
        return new class implements ImageProcessor
        {
            public function process(string $bytes, string $mime, ImageSpec $spec): ProcessedImage
            {
                throw new ImageProcessorUnavailableException('the sidecar is down');
            }

            public function preservesAnimation(): bool
            {
                return true;
            }

            public function intake(): ImageIntake
            {
                return ImageIntake::gd();
            }
        };
    }

    /**
     * An ImageProcessor that records every process() call, the only route to a decode.
     *
     * Reading the header (getimagesizefromstring) is expected and cheap; going through the decoder is
     * what allocates width × height × 4 bytes, and is what must not happen for an oversized image.
     */
    private function spyDecoder(): ImageProcessor
    {
        return new class($this->app->make(ImageProcessor::class)) implements ImageProcessor
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

            public function intake(): ImageIntake
            {
                return $this->inner->intake();
            }
        };
    }
}
