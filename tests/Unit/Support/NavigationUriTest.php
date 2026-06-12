<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\NavigationUri;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NavigationUriTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function uris(): iterable
    {
        yield 'internal path' => ['/member/search', true];
        yield 'root' => ['/', true];
        yield 'path with :id placeholder' => ['/member/:id', true];
        yield 'path with query' => ['/friend/list?id=5', true];
        yield 'https url' => ['https://example.com/page', true];
        yield 'http url' => ['http://example.com', true];

        yield 'unconverted route token' => ['@homepage', false];
        yield 'unconverted module/action' => ['diary/index', false];
        yield 'protocol-relative' => ['//example.com', false];
        yield 'non-http scheme' => ['ftp://example.com', false];
        yield 'javascript scheme' => ['javascript:alert(1)', false];
        yield 'leading whitespace' => [' /member/search', false];
        yield 'embedded newline' => ["/member\n/search", false];
        yield 'empty' => ['', false];
    }

    #[DataProvider('uris')]
    public function test_is_renderable(string $uri, bool $expected): void
    {
        $this->assertSame($expected, NavigationUri::isRenderable($uri));
    }

    public function test_is_external_only_matches_http_schemes(): void
    {
        $this->assertTrue(NavigationUri::isExternal('https://example.com'));
        $this->assertTrue(NavigationUri::isExternal('http://example.com'));
        $this->assertFalse(NavigationUri::isExternal('/member/search'));
        $this->assertFalse(NavigationUri::isExternal('ftp://example.com'));
    }
}
