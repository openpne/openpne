<?php

declare(strict_types=1);

namespace App\Files\Imgproxy;

use App\Files\AnimationProbe;
use App\Files\ImageProcessingException;
use App\Files\ImageProcessor;
use App\Files\ImageProcessorUnavailableException;
use App\Files\ImageSourceLimit;
use App\Files\ImageSpec;
use App\Files\ProcessedImage;
use App\Outbound\CappedStream;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\RequestOptions;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;

/**
 * The bytes reach the sidecar through the spool directory it reads as `local://`, never over a route
 * of this app. Only a 422 is imgproxy's verdict on the bytes; every other failure is an outage or the
 * operator's, logged and retried on the next read (docs/internals/images.md, "Processing").
 */
final class ImgproxyImageProcessor implements ImageProcessor
{
    /** Frames a canonical asks the sidecar to keep, whatever the sidecar's own default. */
    public const MAX_FRAMES = 200;

    private const IMAGE_TYPES = ['jpg' => IMAGETYPE_JPEG, 'png' => IMAGETYPE_PNG, 'gif' => IMAGETYPE_GIF, 'webp' => IMAGETYPE_WEBP];

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
                RequestOptions::PROXY => '',
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
        $keepFrames = $spec->isCanonical() || $spec->animated;

        try {
            [$response, $body] = $this->send($this->url->signed($this->options($spec, $keepFrames), $name, $spec->format), $spec->isCanonical());

            // An animation over the sidecar's resolution budget is kept as a still rather than refused.
            if ($response->getStatusCode() === 422 && $keepFrames && in_array($spec->format, ['gif', 'webp'], true)) {
                $keepFrames = false;
                [$response, $body] = $this->send($this->url->signed($this->options($spec, $keepFrames), $name, $spec->format), $spec->isCanonical());
            }

            return $this->read($response, $body, $spec, $keepFrames);
        } finally {
            $this->spool->delete($name);
        }
    }

    private function options(ImageSpec $spec, bool $keepFrames): string
    {
        $options = ['sm:1', 'scp:1', 'ar:1', 'kcr:0', 'q:'.(int) config('openpne.images.quality'), 'maf:'.($keepFrames ? self::MAX_FRAMES : 1)];

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
     * The body is collected into a sink capped at the source limit, so a transfer past it is aborted
     * rather than held. That limit would refuse a canonical anyway, so passing it is a verdict; a
     * variant is drawn from a canonical within it, so passing it is an outage.
     *
     * @return array{0: ResponseInterface, 1: string}
     *
     * @throws ImageProcessingException
     * @throws ImageProcessorUnavailableException
     */
    private function send(string $url, bool $canonical): array
    {
        $cap = ImageSourceLimit::bytes();
        $sink = new CappedStream(Utils::streamFor(fopen('php://temp', 'r+')), $cap);

        try {
            $response = $this->client->send(new Request('GET', $url), [
                RequestOptions::SINK => $sink,
                RequestOptions::HTTP_ERRORS => false,
                RequestOptions::ALLOW_REDIRECTS => false,
            ]);
        } catch (GuzzleException $e) {
            if ($sink->wasCapped()) {
                return $this->overCap($cap, $canonical);
            }

            return $this->outage('imgproxy could not be reached: '.$e->getMessage());
        }

        if ($sink->wasCapped()) {
            return $this->overCap($cap, $canonical);
        }

        $sink->rewind();

        return [$response, $sink->getContents()];
    }

    private function overCap(int $cap, bool $canonical): never
    {
        if ($canonical) {
            throw new ImageProcessingException("imgproxy answered more than the {$cap} byte source limit.");
        }

        $this->outage("imgproxy answered more than the {$cap} byte source limit for a variant.");
    }

    /**
     * @throws ImageProcessingException
     * @throws ImageProcessorUnavailableException
     */
    private function read(ResponseInterface $response, string $body, ImageSpec $spec, bool $keepFrames): ProcessedImage
    {
        $status = $response->getStatusCode();
        $format = $spec->format;

        if ($status === 200) {
            $size = @getimagesizefromstring($body);

            if ($size === false || $size[2] !== self::IMAGE_TYPES[$format]) {
                return $this->outage("imgproxy answered 200 with bytes that are not a {$format}.");
            }

            // Only a canonical's frames are a fact anyone records, so only it is walked; a still asked
            // for has one frame, and an animated variant is left unjudged.
            $animated = match (true) {
                ! $keepFrames => false,
                $spec->isCanonical() => AnimationProbe::of($body, ImageSpec::mimeFor($format)),
                default => null,
            };

            return new ProcessedImage($body, ImageSpec::mimeFor($format), (int) $size[0], (int) $size[1], $animated);
        }

        $reason = substr(trim($body), 0, 200);

        if ($status === 422) {
            throw new ImageProcessingException("imgproxy refused the image: {$reason}");
        }

        return $this->outage("imgproxy answered {$status}: {$reason}");
    }

    private function outage(string $message): never
    {
        Log::error($message);

        throw new ImageProcessorUnavailableException($message);
    }
}
