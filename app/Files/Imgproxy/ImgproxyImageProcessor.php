<?php

declare(strict_types=1);

namespace App\Files\Imgproxy;

use App\Files\ImageProcessingException;
use App\Files\ImageProcessor;
use App\Files\ImageProcessorUnavailableException;
use App\Files\ImageSourceLimit;
use App\Files\ImageSpec;
use App\Files\ProcessedImage;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Illuminate\Support\Facades\Log;
use Intervention\Gif\Decoder as GifDecoder;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * The bytes reach the sidecar through the spool directory it reads as `local://`, never over a route
 * of this app. A 422, or a 500 while `/health` still answers, is imgproxy's verdict on the bytes;
 * every other failure is an outage or the operator's, logged and retried on the next read
 * (docs/internals/images.md, "Processing").
 */
final class ImgproxyImageProcessor implements ImageProcessor
{
    /** The answer can only be a re-encode of what was spooled, so this is headroom, not a budget. */
    private const RESPONSE_CAP_FACTOR = 4;

    public function __construct(
        private readonly ClientInterface $client,
        private readonly ImgproxyUrl $url,
        private readonly Spool $spool,
    ) {}

    public static function fromConfig(): self
    {
        $config = (array) config('openpne.images.imgproxy');

        return new self(
            new Client([
                RequestOptions::TIMEOUT => (float) $config['timeout'],
                RequestOptions::CONNECT_TIMEOUT => 5.0,
                RequestOptions::HTTP_ERRORS => false,
                RequestOptions::ALLOW_REDIRECTS => false,
                RequestOptions::PROXY => '',
                RequestOptions::STREAM => true,
            ]),
            ImgproxyUrl::fromConfig($config),
            new Spool((string) $config['spool_disk']),
        );
    }

    public function preservesAnimation(): bool
    {
        return true;
    }

    public function process(string $bytes, string $mime, ImageSpec $spec): ProcessedImage
    {
        ImageSourceLimit::preflight($bytes);

        $name = $this->spool->put($bytes, ImageSpec::formatFor($mime) ?? 'bin');

        try {
            $response = $this->send($this->url->signed($this->options($spec, still: ! $spec->isCanonical()), $name, $spec->format));

            // An animation over the sidecar's frame budget is kept as a still rather than refused.
            if ($response->getStatusCode() === 422 && $spec->isCanonical() && in_array($spec->format, ['gif', 'webp'], true)) {
                $response = $this->send($this->url->signed($this->options($spec, still: true), $name, $spec->format));
            }

            return $this->read($response, $spec->format);
        } finally {
            $this->spool->delete($name);
        }
    }

    private function options(ImageSpec $spec, bool $still): string
    {
        $options = ['sm:1', 'scp:1', 'ar:1', 'kcr:0', 'q:'.(int) config('openpne.images.quality')];

        if ($still) {
            $options[] = 'maf:1';
        }

        if ($spec->cover) {
            $options[] = "rt:fill/w:{$spec->width}/h:{$spec->height}/el:1/g:ce";
        } elseif (! $spec->isCanonical()) {
            $options[] = "rt:fit/w:{$spec->width}/h:{$spec->height}/el:0";
        }

        if ($spec->background !== null) {
            $options[] = "bg:{$spec->background}";
        }

        return implode('/', $options);
    }

    /**
     * @throws ImageProcessorUnavailableException
     */
    private function send(string $url): ResponseInterface
    {
        try {
            return $this->client->sendRequest(new Request('GET', $url));
        } catch (ClientExceptionInterface $e) {
            return $this->outage('imgproxy could not be reached: '.$e->getMessage());
        }
    }

    /**
     * @throws ImageProcessingException
     * @throws ImageProcessorUnavailableException
     */
    private function read(ResponseInterface $response, string $format): ProcessedImage
    {
        $status = $response->getStatusCode();

        if ($status === 200) {
            $bytes = $this->body($response, ImageSourceLimit::bytes() * self::RESPONSE_CAP_FACTOR);
            $size = @getimagesizefromstring($bytes);

            if ($size === false) {
                return $this->outage('imgproxy answered 200 with bytes that are not an image.');
            }

            return new ProcessedImage($bytes, ImageSpec::mimeFor($format), (int) $size[0], (int) $size[1], $this->animated($bytes, $format));
        }

        $reason = trim($this->body($response, 1024));

        if ($status === 422) {
            throw new ImageProcessingException("imgproxy refused the image: {$reason}");
        }

        if ($status === 500 && $this->alive()) {
            throw new ImageProcessingException("imgproxy could not decode the image: {$reason}");
        }

        return $this->outage("imgproxy answered {$status}: {$reason}");
    }

    /**
     * @throws ImageProcessorUnavailableException
     */
    private function body(ResponseInterface $response, int $cap): string
    {
        $stream = $response->getBody();
        $bytes = '';

        try {
            while (! $stream->eof() && strlen($bytes) <= $cap) {
                $bytes .= $stream->read(65536);
            }
        } catch (Throwable $e) {
            return $this->outage('imgproxy stopped answering: '.$e->getMessage());
        } finally {
            $stream->close();
        }

        if (strlen($bytes) > $cap) {
            return $this->outage("imgproxy answered more than the {$cap} byte cap.");
        }

        return $bytes;
    }

    private function alive(): bool
    {
        try {
            return $this->client->sendRequest(new Request('GET', $this->url->health()))->getStatusCode() === 200;
        } catch (ClientExceptionInterface) {
            return false;
        }
    }

    private function animated(string $bytes, string $format): bool
    {
        return match ($format) {
            'gif' => count(GifDecoder::decode($bytes)->frames()) > 1,
            'webp' => str_contains(substr($bytes, 0, 64), 'ANIM'),
            default => false,
        };
    }

    private function outage(string $message): never
    {
        Log::error($message);

        throw new ImageProcessorUnavailableException($message);
    }
}
