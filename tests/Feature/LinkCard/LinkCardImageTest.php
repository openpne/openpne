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

    public function test_it_stores_a_fetched_image_as_a_public_file(): void
    {
        $card = $this->card();
        $this->resolvesTo('cdn.example.com', ['93.184.216.34']);
        $this->queueBinary($this->png(40, 30), 'image/png');

        $result = $this->importer()->import('https://cdn.example.com/hero.png', $card->id);

        $this->assertNotNull($result);
        $this->assertSame(40, $result['width']);
        $this->assertSame(30, $result['height']);
        $this->assertSame('image/png', $result['file']->type);
        // The card appears on pages a logged-out visitor may see, and the source was a public web
        // image, so visibility cannot be inherited from an owner that does not exist.
        $this->assertSame(File::VISIBILITY_PUBLIC, $result['file']->explicit_visibility);
        $this->assertSame('link_card', $result['file']->related_entity_type);
        $this->assertSame($card->id, $result['file']->related_entity_id);
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

        $before = $this->tempFileCount();
        $this->importer()->import('https://cdn.example.com/ok.png', $card->id);
        $this->importer()->import('https://cdn.example.com/bad.png', $card->id);

        $this->assertSame($before, $this->tempFileCount(), 'A temp file survived one of the two paths.');
    }

    private function importer(?ImageManager $images = null): LinkCardImage
    {
        return new LinkCardImage(
            $this->fakeFetcher(),
            $this->app->make(FileUploader::class),
            $images ?? $this->app->make(ImageManager::class),
        );
    }

    private function card(): LinkCard
    {
        return LinkCard::create(['url_hash' => str_repeat('a', 64), 'url' => 'https://example.com/']);
    }

    /** Counts our own temp files, so an unrelated one in the directory cannot mask a leak. */
    private function tempFileCount(): int
    {
        return count(glob(sys_get_temp_dir().'/linkcard*') ?: []);
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
