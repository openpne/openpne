<?php

declare(strict_types=1);

namespace Tests\Unit\Outbound;

use App\Outbound\CappedStream;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;

class CappedStreamTest extends TestCase
{
    public function test_it_passes_a_body_under_the_cap_through_untouched(): void
    {
        $stream = $this->stream(100);

        $this->assertSame(5, $stream->write('hello'));
        $this->assertFalse($stream->wasCapped());
        $this->assertSame('hello', (string) $stream);
    }

    public function test_it_keeps_the_prefix_and_reports_the_cap(): void
    {
        $stream = $this->stream(4);

        // A short return is libcurl's "the writer refused these bytes" signal: it aborts the transfer
        // rather than downloading the rest. That is what bounds a decompression bomb, which a
        // Content-Length check cannot see.
        $this->assertSame(4, $stream->write('hello world'));
        $this->assertTrue($stream->wasCapped());
        $this->assertSame('hell', (string) $stream);
    }

    public function test_it_refuses_every_write_once_capped(): void
    {
        $stream = $this->stream(4);
        $stream->write('hello');

        $this->assertSame(0, $stream->write('more'));
        $this->assertSame('hell', (string) $stream);
    }

    public function test_it_caps_across_several_writes(): void
    {
        // The decoded body arrives in chunks, so the cap has to hold over the running total rather
        // than over any single write.
        $stream = $this->stream(10);

        $this->assertSame(4, $stream->write('aaaa'));
        $this->assertSame(4, $stream->write('bbbb'));
        $this->assertFalse($stream->wasCapped());
        $this->assertSame(2, $stream->write('cccc'));
        $this->assertTrue($stream->wasCapped());
        $this->assertSame('aaaabbbbcc', (string) $stream);
    }

    public function test_a_body_exactly_at_the_cap_is_not_reported_as_capped(): void
    {
        $stream = $this->stream(5);

        $this->assertSame(5, $stream->write('hello'));
        $this->assertFalse($stream->wasCapped());
    }

    private function stream(int $maxBytes): CappedStream
    {
        return new CappedStream(Utils::streamFor(fopen('php://temp', 'r+')), $maxBytes);
    }
}
