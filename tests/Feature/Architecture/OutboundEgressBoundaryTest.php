<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Pins the single-seam rule from docs/internals/outbound-http.md: App\Outbound is the only part of
 * this app that may open an outbound connection.
 *
 * The rule exists because SSRF defence is not a property of any one call site — it is the property
 * that every fetch of a member-supplied URL went through the guard. One `Http::get($url)` added
 * later, anywhere, is a hole that no amount of care inside SafeHttpFetcher covers, and it is exactly
 * the kind of line that looks unremarkable in review.
 *
 * The forbidden set is deliberately wider than "the HTTP client". A URL can be dereferenced through
 * a stream wrapper or a raw socket just as well, and those are the forms someone reaches for when
 * the obvious one is unavailable.
 */
class OutboundEgressBoundaryTest extends TestCase
{
    /**
     * Directories allowed to speak to the network. Everything else in app/ must go through them.
     *
     * @var list<string>
     */
    private const EGRESS_ALLOWLIST = [
        'Outbound',
    ];

    /**
     * Patterns that open, or can open, a connection this app did not validate.
     *
     * @return array<string, array{string, string}>
     */
    public static function forbiddenEgress(): array
    {
        return [
            'Guzzle client' => ['/\bnew\s+(?:\\\\)?(?:GuzzleHttp\\\\)?Client\s*\(/', 'construct a Guzzle client'],
            'Guzzle namespace' => ['/\bGuzzleHttp\\\\(?!Psr7\b|Promise\b)/', 'reach into GuzzleHttp'],
            'Http facade' => ['/(?:^|[^\w\\\\])Http::(?:get|post|put|patch|delete|head|send|withHeaders|withOptions|baseUrl|timeout|pool|retry)\s*\(/m', 'use the Http facade'],
            'curl' => ['/\bcurl_(?:init|exec|setopt|setopt_array|multi_init)\s*\(/', 'call curl directly'],
            'file_get_contents on a URL' => ['/\bfile_get_contents\s*\(\s*[\'"]https?:/i', 'fetch through file_get_contents'],
            'fopen on a URL' => ['/\bfopen\s*\(\s*[\'"]https?:/i', 'fetch through fopen'],
            'raw sockets' => ['/\b(?:fsockopen|pfsockopen|stream_socket_client|socket_create|socket_connect)\s*\(/', 'open a raw socket'],
            'Symfony HttpClient' => ['/\bSymfony\\\\Component\\\\HttpClient\b|\bHttpClient::create\s*\(/', 'use Symfony HttpClient'],
        ];
    }

    #[DataProvider('forbiddenEgress')]
    public function test_only_the_outbound_seam_may_reach_the_network(string $pattern, string $what): void
    {
        $offenders = [];

        foreach ($this->appFiles() as $file) {
            if ($this->isAllowlisted($file)) {
                continue;
            }

            if (preg_match($pattern, (string) file_get_contents($file)) === 1) {
                $offenders[] = str_replace(app_path().'/', 'app/', $file);
            }
        }

        $this->assertSame([], $offenders, sprintf(
            "These files %s directly. Outbound requests must go through App\\Outbound\\SafeHttpFetcher, which validates the destination and pins the connection.\n%s",
            $what,
            implode("\n", $offenders),
        ));
    }

    public function test_the_allowlisted_directories_exist(): void
    {
        // A renamed directory would silently turn the allowlist into a no-op that still passes.
        foreach (self::EGRESS_ALLOWLIST as $directory) {
            $this->assertDirectoryExists(app_path($directory));
        }
    }

    public function test_the_guard_catches_a_violation(): void
    {
        // The patterns are only worth their runtime if they actually match. Checking them against a
        // sample keeps a typo'd regex from passing everything forever.
        $sample = <<<'PHP'
            <?php
            $body = file_get_contents('https://example.com/');
            $handle = fopen("http://example.com/", 'rb');
            $response = Http::get($url);
            $client = new \GuzzleHttp\Client;
            curl_init($url);
            $socket = fsockopen('example.com', 80);
            PHP;

        $matched = array_filter(
            self::forbiddenEgress(),
            fn (array $case): bool => preg_match($case[0], $sample) === 1,
        );

        $this->assertCount(6, $matched, 'Every pattern with a sample line above must match it.');
    }

    /** @return list<string> */
    private function appFiles(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private function isAllowlisted(string $file): bool
    {
        foreach (self::EGRESS_ALLOWLIST as $directory) {
            if (str_starts_with($file, app_path($directory).'/')) {
                return true;
            }
        }

        return false;
    }
}
