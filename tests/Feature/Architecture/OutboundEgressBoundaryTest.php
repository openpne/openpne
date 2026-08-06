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
     * Files allowed to call file_get_contents / fopen.
     *
     * PHP's stream wrappers make those two the shortest path from a member-supplied URL to an
     * outbound request, and matching only a literal `'https://…'` first argument catches none of it:
     * `file_get_contents($url)` is what the code would actually say. So the functions are banned
     * outright and the handful of existing local reads are named here instead. Adding a file to this
     * list means asserting it only ever reads a local path.
     *
     * The lookbehind keeps `$request->file(...)` and similar method calls out; PHP's own `file()` is
     * left out of the alternation entirely because `\bfile\s*\(` also matches English prose in
     * comments ("an unlinked file (no related entity)"), which would make the rule noise.
     */
    private const STREAM_WRAPPER = '/(?<![\w>:$])(?:file_get_contents|readfile|fopen)\s*\(/';

    /**
     * @var list<string>
     */
    private const LOCAL_FILE_READERS = [
        'Console/Commands/CheckTranslationsCommand.php',
        'Files/AppIcon.php',
        'Files/DbBlobFileStorage.php',
        'Files/FileUploader.php',
        'Mail/Template/MailTemplateDefaults.php',
        'Upgrade/SourceSchema.php',
    ];

    /**
     * Patterns that open, or can open, a connection this app did not validate.
     *
     * Namespaces rather than method names wherever possible: `Http::get($url)` is one import alias
     * away from unrecognisable, while a reference to the namespace has to be there for the call to
     * exist at all.
     *
     * @return array<string, array{string, string}>
     */
    public static function forbiddenEgress(): array
    {
        return [
            'Guzzle client' => ['/\bnew\s+(?:\\\\)?(?:GuzzleHttp\\\\)?Client\s*[\(;]/', 'construct a Guzzle client'],
            'Guzzle namespace' => ['/\bGuzzleHttp\\\\(?!Psr7\b|Promise\b)/', 'reach into GuzzleHttp'],
            'Http facade' => ['/\bIlluminate\\\\Support\\\\Facades\\\\Http\b/', 'import the Http facade'],
            'Laravel HTTP client' => ['/\bIlluminate\\\\Http\\\\Client\\\\/', 'reach into the Laravel HTTP client'],
            'curl' => ['/\bcurl_(?:init|exec|setopt|setopt_array|multi_init)\s*\(/', 'call curl directly'],
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

    public function test_stream_wrappers_are_confined_to_the_files_that_read_local_paths(): void
    {
        $offenders = [];

        foreach ($this->appFiles() as $file) {
            $relative = str_replace(app_path().'/', '', $file);

            if ($this->isAllowlisted($file) || in_array($relative, self::LOCAL_FILE_READERS, true)) {
                continue;
            }

            if (preg_match(self::STREAM_WRAPPER, (string) file_get_contents($file)) === 1) {
                $offenders[] = 'app/'.$relative;
            }
        }

        $this->assertSame([], $offenders, sprintf(
            "These files call a stream-wrapper function without being listed as local-path readers.\nEither read the path through an existing helper, or add the file to LOCAL_FILE_READERS having checked it can never receive a URL.\n%s",
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

    public function test_the_local_file_reader_list_has_no_stale_entries(): void
    {
        // An entry left behind after a file is deleted or stops reading files quietly widens the
        // exemption for whatever takes that path next.
        foreach (self::LOCAL_FILE_READERS as $relative) {
            $this->assertFileExists(app_path($relative));
            $this->assertMatchesRegularExpression(
                self::STREAM_WRAPPER,
                (string) file_get_contents(app_path($relative)),
                "app/{$relative} no longer reads files; remove it from LOCAL_FILE_READERS.",
            );
        }
    }

    public function test_the_guard_catches_a_violation(): void
    {
        // The patterns are only worth their runtime if they actually match. Checking them against a
        // sample keeps a typo'd regex from passing everything forever.
        $sample = <<<'PHP'
            <?php
            use Illuminate\Support\Facades\Http;
            use Illuminate\Http\Client\PendingRequest;
            use Symfony\Component\HttpClient\HttpClient;
            $response = Http::get($url);
            $client = new \GuzzleHttp\Client;
            curl_init($url);
            $socket = fsockopen('example.com', 80);
            PHP;

        $matched = array_filter(
            self::forbiddenEgress(),
            fn (array $case): bool => preg_match($case[0], $sample) === 1,
        );

        $this->assertSame(
            ['Guzzle client', 'Guzzle namespace', 'Http facade', 'Laravel HTTP client', 'curl', 'raw sockets', 'Symfony HttpClient'],
            array_keys($matched),
        );
    }

    public function test_the_stream_wrapper_guard_catches_a_variable_url(): void
    {
        // The case the earlier literal-only patterns missed, and the one real code would contain.
        $this->assertMatchesRegularExpression(self::STREAM_WRAPPER, '<?php $body = file_get_contents($url);');
        $this->assertMatchesRegularExpression(self::STREAM_WRAPPER, '<?php $handle = fopen($url, "rb");');
        $this->assertDoesNotMatchRegularExpression(self::STREAM_WRAPPER, '<?php $upload = $request->file("images");');
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
