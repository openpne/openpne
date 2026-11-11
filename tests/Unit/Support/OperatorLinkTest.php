<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\OperatorLink;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OperatorLinkTest extends TestCase
{
    /** @return iterable<string, array{?string, ?string}> */
    public static function urls(): iterable
    {
        yield 'https' => ['https://example.com/a?b=1#c', 'https://example.com/a?b=1#c'];
        yield 'http, upper case' => ['HTTP://example.com', 'HTTP://example.com'];
        yield 'mailto' => ['mailto:info@example.com', 'mailto:info@example.com'];
        yield 'site path' => ['/member/search', '/member/search'];
        yield 'surrounding blanks' => ["  https://example.com \n", 'https://example.com'];
        yield 'inner space' => ['https://example.com/a b', 'https://example.com/a b'];

        yield 'null' => [null, null];
        yield 'empty' => ['', null];
        yield 'javascript' => ['javascript:alert(1)', null];
        yield 'javascript, mixed case' => ['JaVaScRiPt:alert(1)', null];
        yield 'javascript behind a tab' => ["java\tscript:alert(1)", null];
        yield 'javascript with an authority' => ['javascript://example.com/%0aalert(1)', null];
        yield 'data' => ['data:text/html,<script>alert(1)</script>', null];
        yield 'vbscript' => ['vbscript:msgbox(1)', null];
        yield 'protocol-relative' => ['//example.com', null];
        yield 'protocol-relative behind a tab' => ["/\t/example.com", null];
        yield 'backslash read as a slash' => ['/\\example.com', null];
        yield 'backslash in an http url' => ['https://example.com\\@evil.example', null];
        yield 'relative path' => ['diary/list', null];
        yield 'other scheme' => ['ftp://example.com', null];
    }

    #[DataProvider('urls')]
    public function test_href(?string $url, ?string $expected): void
    {
        $this->assertSame($expected, OperatorLink::href($url));
    }
}
