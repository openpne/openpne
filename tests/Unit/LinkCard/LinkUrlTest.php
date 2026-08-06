<?php

declare(strict_types=1);

namespace Tests\Unit\LinkCard;

use App\LinkCard\LinkUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LinkUrlTest extends TestCase
{
    #[DataProvider('normalisations')]
    public function test_it_normalises(string $input, string $expected): void
    {
        $this->assertSame($expected, LinkUrl::normalize($input));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function normalisations(): array
    {
        return [
            // None of these can change which resource is addressed.
            'scheme case' => ['HTTPS://example.com/a', 'https://example.com/a'],
            'host case' => ['https://Example.COM/a', 'https://example.com/a'],
            'default https port' => ['https://example.com:443/a', 'https://example.com/a'],
            'default http port' => ['http://example.com:80/a', 'http://example.com/a'],
            'trailing dot host' => ['https://example.com./a', 'https://example.com/a'],
            'fragment' => ['https://example.com/a#section', 'https://example.com/a'],
            'idn host' => ['https://例え.テスト/a', 'https://xn--r8jz45g.xn--zckzah/a'],

            // Kept: these do change it.
            'non-default port' => ['https://example.com:8443/a', 'https://example.com:8443/a'],
            'path case' => ['https://example.com/Article/One', 'https://example.com/Article/One'],
            'query' => ['https://example.com/?id=42', 'https://example.com/?id=42'],
            'query order' => ['https://example.com/?b=2&a=1', 'https://example.com/?b=2&a=1'],
            'tracking parameters' => ['https://example.com/a?utm_source=x', 'https://example.com/a?utm_source=x'],
            'empty path' => ['https://example.com', 'https://example.com'],
        ];
    }

    #[DataProvider('rejected')]
    public function test_it_refuses(string $input): void
    {
        $this->assertNull(LinkUrl::normalize($input));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rejected(): array
    {
        return [
            'empty' => [''],
            'relative' => ['/just/a/path'],
            'scheme relative' => ['//example.com/a'],
            'no host' => ['https:///a'],
            'mailto' => ['mailto:someone@example.com'],
            'javascript' => ['javascript:alert(1)'],
            'data' => ['data:text/html,<h1>x</h1>'],
            'file' => ['file:///etc/passwd'],
            // A URL carrying credentials is not one to store, share a cache entry for, or replay.
            'userinfo' => ['https://user:secret@example.com/a'],
            'user only' => ['https://user@example.com/a'],
            'over long' => ['https://example.com/'.str_repeat('a', 4096)],
        ];
    }

    public function test_http_and_https_are_different_cards(): void
    {
        // Different origins, and the fetcher treats them as such; collapsing them would let a plain
        // http page's metadata be served for an https URL.
        $this->assertNotSame(
            LinkUrl::normalize('http://example.com/a'),
            LinkUrl::normalize('https://example.com/a'),
        );
    }

    public function test_the_hash_is_stable_and_distinguishing(): void
    {
        $one = (string) LinkUrl::normalize('HTTPS://Example.com:443/a#x');
        $two = (string) LinkUrl::normalize('https://example.com/a');

        $this->assertSame(LinkUrl::hash($one), LinkUrl::hash($two));
        $this->assertNotSame(LinkUrl::hash($one), LinkUrl::hash((string) LinkUrl::normalize('https://example.com/b')));
        $this->assertSame(64, strlen(LinkUrl::hash($one)));
    }

    public function test_a_query_bearing_id_keeps_pages_apart(): void
    {
        // Plenty of sites put the article id in the query, so dropping or reordering it would merge
        // unrelated pages into one card — the failure that cannot be undone by re-fetching.
        $this->assertNotSame(
            LinkUrl::normalize('https://example.com/read.php?id=1'),
            LinkUrl::normalize('https://example.com/read.php?id=2'),
        );
    }

    #[DataProvider('references')]
    public function test_it_resolves_references(string $reference, string $base, ?string $expected): void
    {
        $this->assertSame($expected, LinkUrl::resolve($reference, $base));
    }

    /**
     * @return array<string, array{string, string, string|null}>
     */
    public static function references(): array
    {
        $base = 'https://example.com/a/b/page.html';

        return [
            'absolute' => ['https://cdn.example.net/i.png', $base, 'https://cdn.example.net/i.png'],
            'root relative' => ['/i.png', $base, 'https://example.com/i.png'],
            'document relative' => ['i.png', $base, 'https://example.com/a/b/i.png'],
            'parent relative' => ['../i.png', $base, 'https://example.com/a/i.png'],
            'scheme relative' => ['//cdn.example.net/i.png', $base, 'https://cdn.example.net/i.png'],
            'query only' => ['?v=2', $base, 'https://example.com/a/b/page.html?v=2'],
            'surrounding whitespace' => ["  /i.png\n", $base, 'https://example.com/i.png'],
            'data uri' => ['data:image/png;base64,AAAA', $base, null],
            'javascript' => ['javascript:alert(1)', $base, null],
            'empty' => ['', $base, null],
        ];
    }
}
