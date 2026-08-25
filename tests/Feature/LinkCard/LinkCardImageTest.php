<?php

declare(strict_types=1);

namespace Tests\Feature\LinkCard;

use App\Files\FileUploader;
use App\Files\ImageMetadataStripper;
use App\LinkCard\LinkCardImage;
use App\Models\File;
use App\Models\LinkCard;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\DecoderInterface;
use Intervention\Image\Interfaces\ImageInterface;
use Tests\Concerns\FakesOutboundTransport;
use Tests\TestCase;

/**
 * The image import runs against the real SafeHttpFetcher and the real FileUploader; only the socket
 * and the resolver are fake. What is asserted here is mostly *order*, because the ordering is the
 * security property: a decoder allocates width × height × 4 bytes, so an oversized image has to be
 * refused from its header before anything decodes it.
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
        // Marking these public would serve them from the login-free route to anyone holding the
        // token, and a link card attaches to friends-only diaries and private messages as readily as
        // to open ones. The source URL is no evidence to the contrary: normalisation keeps the query,
        // so the image behind a signed or expiring link is copied too, and a permanent public copy
        // outlives both that expiry and the body's own visibility rule.
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
        // The contract that matters. A PNG header can claim 40000x40000 in a handful of bytes; if the
        // size check ran after decoding, that claim would already have cost 6.4GB of allocation. The
        // assertion is that no decode happened at all, not merely that the result was null.
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

    public function test_an_animated_image_never_reaches_the_decoder(): void
    {
        // Frame count is bounded by neither the wire size nor the dimensions, and Intervention
        // decodes animations by default — so a few-kilobyte GIF can hold hundreds of full-size
        // allocations. A card shows one still picture, so these are refused from the header.
        $card = $this->card();
        $this->resolvesTo('cdn.example.com', ['93.184.216.34']);
        $this->queueBinary($this->animatedGif(), 'image/gif');

        $decoder = $this->spyDecoder();

        $this->assertNull($this->importer($decoder)->import('https://cdn.example.com/anim.gif', $card->id));
        $this->assertSame(0, $decoder->calls, 'An animated image reached the decoder.');
        $this->assertSame(0, File::count());
    }

    public function test_an_animation_without_a_loop_extension_never_reaches_the_decoder(): void
    {
        // The shape a marker search misses: two frames, no NETSCAPE extension, and a colour-table
        // byte that shifts the pattern a naive scan keys on. A decoder expands both frames.
        $card = $this->card();
        $this->resolvesTo('cdn.example.com', ['93.184.216.34']);
        $this->queueBinary($this->animatedGif(loopExtension: false), 'image/gif');

        $decoder = $this->spyDecoder();

        $this->assertNull($this->importer($decoder)->import('https://cdn.example.com/anim.gif', $card->id));
        $this->assertSame(0, $decoder->calls, 'A loop-extension-free animation reached the decoder.');
    }

    public function test_an_animated_webp_behind_padding_never_reaches_the_decoder(): void
    {
        // A RIFF file may legally carry a JUNK chunk before ANIM, pushing it past any fixed window.
        $card = $this->card();
        $this->resolvesTo('cdn.example.com', ['93.184.216.34']);
        $this->queueBinary($this->paddedAnimatedWebp(), 'image/webp');

        $decoder = $this->spyDecoder();

        $this->assertNull($this->importer($decoder)->import('https://cdn.example.com/anim.webp', $card->id));
        $this->assertSame(0, $decoder->calls, 'An animated WebP behind padding reached the decoder.');
    }

    public function test_an_animation_hidden_behind_the_parser_budget_never_reaches_the_decoder(): void
    {
        // ~20 KB, well inside the read cap, and structurally valid: two frames separated by enough
        // legal comment blocks to exhaust the container walk's budget. If running out of budget were
        // treated as "still", the walk's own limit would become the fixed window it exists to avoid.
        $card = $this->card();
        $this->resolvesTo('cdn.example.com', ['93.184.216.34']);
        $this->queueBinary($this->paddedAnimatedGif(), 'image/gif');

        $decoder = $this->spyDecoder();

        $this->assertNull($this->importer($decoder)->import('https://cdn.example.com/padded.gif', $card->id));
        $this->assertSame(0, $decoder->calls, 'An animation padded past the parser budget reached the decoder.');
        $this->assertSame(0, File::count());
    }

    public function test_a_still_gif_is_still_accepted(): void
    {
        // The animation check must not cost the format entirely.
        $card = $this->card();
        $this->resolvesTo('cdn.example.com', ['93.184.216.34']);
        $this->queueBinary($this->stillGif(), 'image/gif');

        $this->assertNotNull($this->importer()->import('https://cdn.example.com/still.gif', $card->id));
    }

    public function test_a_header_only_forgery_is_refused(): void
    {
        // Passes finfo and getimagesizefromstring — there is simply nothing behind the header. Storing
        // it would give the card a picture that never renders.
        $card = $this->card();
        $this->resolvesTo('cdn.example.com', ['93.184.216.34']);
        $this->queueBinary($this->pngHeaderClaiming(10, 10), 'image/png');

        $this->assertNull($this->importer()->import('https://cdn.example.com/hollow.png', $card->id));
        $this->assertSame(0, File::count());
    }

    public function test_metadata_is_stripped_exactly_once(): void
    {
        // One strip in the pipeline, inside FileUploader. Handing raw bytes around and stripping here
        // as well would re-encode twice and put a second, divergent copy of the rule in this class.
        $stripper = new class extends ImageMetadataStripper
        {
            public int $calls = 0;

            public function strip(string $bytes, string $mime): string
            {
                $this->calls++;

                return parent::strip($bytes, $mime);
            }
        };
        $this->app->instance(ImageMetadataStripper::class, $stripper);

        $card = $this->card();
        $this->resolvesTo('cdn.example.com', ['93.184.216.34']);
        $this->queueBinary($this->jpeg(20, 20), 'image/jpeg');

        $this->assertNotNull($this->importer()->import('https://cdn.example.com/p.jpg', $card->id));
        $this->assertSame(1, $stripper->calls);
    }

    public function test_the_declared_content_type_is_not_believed(): void
    {
        // Content-Type is the far end's claim; finfo reads the actual signature. A script served as
        // image/png must not become a stored image.
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
        $this->queueBinary('not an image at all', 'image/png');
        $staging = $this->stagingDirectory();
        $uploader = $this->recordingUploader($staged);

        $stored = $this->importer(staging: $staging, uploader: $uploader)->import('https://cdn.example.com/ok.png', $card->id);
        $refused = $this->importer(staging: $staging, uploader: $uploader)->import('https://cdn.example.com/bad.png', $card->id);

        // Before the outcome, that the stimulus landed. An empty directory is also what two imports
        // that never ran leave behind, and what an importer staging somewhere else leaves behind, and
        // this test reads emptiness as success.
        $this->assertNotNull($stored, 'The storing path did not store.');
        $this->assertNull($refused, 'The refusing path did not refuse.');
        $this->assertNotSame([], $staged, 'Nothing reached the uploader, so nothing was staged.');
        foreach ($staged as $path) {
            $this->assertSame($staging, dirname($path), 'The importer staged outside the directory it was given.');
        }

        // Emptiness, not a count that came back to where it started: nobody else writes here, so
        // anything left is a file one of the two paths failed to clean up.
        $this->assertSame([], $this->stagedFiles($staging), 'A temp file survived one of the two paths.');
    }

    /**
     * An uploader that notes where the bytes it is handed are sitting.
     *
     * The staged file is gone by the time the assertions run — cleaning it up is what is under test —
     * so the only moment its path can be seen is as it is passed on.
     *
     * @param  list<string>|null  $staged
     */
    private function recordingUploader(?array &$staged): FileUploader
    {
        $staged = [];
        $real = $this->app->make(FileUploader::class);

        return new class($real, $staged) extends FileUploader
        {
            /** @param  list<string>  $staged */
            public function __construct(private readonly FileUploader $inner, private array &$staged) {}

            public function store(UploadedFile $upload, ?string $relatedType = null, ?int $relatedId = null, ?string $explicitVisibility = null): File
            {
                $this->staged[] = $upload->getPathname();

                return $this->inner->store($upload, $relatedType, $relatedId, $explicitVisibility);
            }
        };
    }

    /**
     * A staging directory this test alone writes to, removed when it ends.
     *
     * The importer stages under the system temp directory by default, which every ParaTest worker
     * shares, and a staged name says nothing about which process wrote it. Watching that directory
     * watches the other workers' imports too — one of them finishing mid-window moves the count
     * without this test doing anything, which is the failure this test used to have.
     */
    private function stagingDirectory(): string
    {
        $dir = sys_get_temp_dir().'/linkcard-staging-'.getmypid().'-'.bin2hex(random_bytes(6));
        // Said by the test rather than left to the framework: tempnam falls back to the shared
        // directory when the one it is given is missing, and scandir on a missing directory answers
        // with the empty list this test reads as success. Today an E_NOTICE-to-exception conversion
        // catches that; this does not depend on it staying.
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

    private function importer(?ImageManager $images = null, ?string $staging = null, ?FileUploader $uploader = null): LinkCardImage
    {
        return new LinkCardImage(
            $this->fakeFetcher(),
            $uploader ?? $this->app->make(FileUploader::class),
            $images ?? $this->app->make(ImageManager::class),
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

    /** A structurally valid two-frame GIF, optionally without the (purely decorative) loop extension. */
    private function animatedGif(bool $loopExtension = true): string
    {
        // Non-zero final colour-table byte, which shifts the byte pattern a naive scan keys on.
        $gif = "GIF89a\x08\x00\x08\x00\x80\x00\x00".pack('C*', 0xFF, 0xFF, 0xFF, 0x00, 0x00, 0x01);

        if ($loopExtension) {
            $gif .= "\x21\xFF\x0BNETSCAPE2.0\x03\x01\x00\x00\x00";
        }

        $frame = "\x21\xF9\x04\x00\x00\x00\x00\x00\x2C\x00\x00\x00\x00\x08\x00\x08\x00\x00\x02\x02\x44\x01\x00";

        return $gif.$frame.$frame."\x3B";
    }

    /** Two frames separated by enough legal comment blocks to exhaust the container walk's budget. */
    private function paddedAnimatedGif(): string
    {
        $gif = "GIF89a\x08\x00\x08\x00\x80\x00\x00".pack('C*', 0xFF, 0xFF, 0xFF, 0x00, 0x00, 0x01);
        $frame = "\x21\xF9\x04\x00\x00\x00\x00\x00\x2C\x00\x00\x00\x00\x08\x00\x08\x00\x00\x02\x02\x44\x01\x00";

        return $gif.$frame.str_repeat("\x21\xFE\x01\x41\x00", 4095).$frame."\x3B";
    }

    /** An animated WebP whose ANIM chunk sits past any fixed-size prefix scan. */
    private function paddedAnimatedWebp(): string
    {
        $chunk = fn (string $fourcc, string $data): string => $fourcc.pack('V', strlen($data)).$data
            .(strlen($data) % 2 === 1 ? "\x00" : '');

        $chunks = $chunk('JUNK', str_repeat("\x00", 5000)).$chunk('ANIM', str_repeat("\x00", 6));

        return 'RIFF'.pack('V', 4 + strlen($chunks)).'WEBP'.$chunks;
    }

    /**
     * A PNG whose IHDR claims a huge size while the file stays tiny — the decompression-bomb shape.
     * The CRC is left wrong on purpose: a decoder would reject it, which is the point, since nothing
     * should get far enough to try.
     */
    private function pngHeaderClaiming(int $width, int $height): string
    {
        return "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.pack('NN', $width, $height)."\x08\x02\x00\x00\x00".pack('N', 0);
    }

    /**
     * An ImageManager that records every decode.
     *
     * Reading the header (getimagesizefromstring) is expected and cheap; going through the decoder is
     * what allocates width × height × 4 bytes, and is what must not happen for an oversized image.
     */
    private function spyDecoder(): ImageManager
    {
        return new class(new Driver) extends ImageManager
        {
            public int $calls = 0;

            public function decode(mixed $source, string|array|DecoderInterface|null $decoders = null): ImageInterface
            {
                $this->calls++;

                return parent::decode($source, $decoders);
            }
        };
    }
}
