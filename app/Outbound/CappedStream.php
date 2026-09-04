<?php

declare(strict_types=1);

namespace App\Outbound;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamInterface;

/**
 * The cap is on decoded bytes: libcurl inflates gzip before the write callback sees it, so a
 * Content-Length check would let a small compressed body expand without bound. Past the cap
 * `write()` returns a short count rather than throwing, which is libcurl's signal to abort the
 * transfer; a throw out of a curl callback is not reliably propagated.
 */
final class CappedStream implements StreamInterface
{
    use StreamDecoratorTrait;

    private int $written = 0;

    private bool $capped = false;

    public function __construct(private StreamInterface $stream, private readonly int $maxBytes) {}

    public function write(string $string): int
    {
        $remaining = $this->maxBytes - $this->written;

        if ($remaining <= 0) {
            $this->capped = true;

            return 0;
        }

        if (strlen($string) > $remaining) {
            $this->capped = true;
            $string = substr($string, 0, $remaining);
        }

        $written = $this->stream->write($string);
        $this->written += $written;

        return $written;
    }

    /** Whether the body was longer than the cap, so the collected bytes are a prefix of it. */
    public function wasCapped(): bool
    {
        return $this->capped;
    }
}
