<?php

declare(strict_types=1);

namespace App\Outbound;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamInterface;

/**
 * A response sink that stops accepting bytes past a cap, aborting the transfer.
 *
 * The cap is on DECODED bytes. libcurl inflates a gzip response before handing it to the write
 * callback, so a Content-Length check would let a small compressed body expand without bound; this
 * counts what actually lands in memory.
 *
 * Past the cap write() returns a short count rather than throwing. That is libcurl's documented
 * signal for "the writer refused the data": it aborts the transfer with CURLE_WRITE_ERROR instead of
 * downloading the rest. Throwing out of a curl callback is not reliably propagated, and would also
 * lose the bytes already collected — which for HTML are the useful ones, since <head> comes first.
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
