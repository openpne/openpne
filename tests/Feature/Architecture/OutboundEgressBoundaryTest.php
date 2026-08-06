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
     * PHP functions that take a path and will just as happily take a URL.
     *
     * These are the shortest route from a member-supplied URL to an outbound request, and matching
     * only a literal `'https://…'` first argument catches none of it — `file_get_contents($url)` is
     * what the offending code would actually say. So they are banned outright and the existing
     * local-path readers are named below instead.
     *
     * @var list<string>
     */
    private const STREAM_WRAPPER_FUNCTIONS = [
        'file',
        'file_get_contents',
        'readfile',
        'fopen',
        'get_headers',
        'copy',
    ];

    /**
     * Files allowed to call one of the above.
     *
     * Adding a file here means asserting it only ever receives a local path.
     *
     * @var list<string>
     */
    private const LOCAL_FILE_READERS = [
        'Console/Commands/CheckTranslationsCommand.php',
        'Files/AppIcon.php',
        'Files/DbBlobFileStorage.php',
        'Files/FileUploader.php',
        'Mail/Template/MailTemplateDefaults.php',
        'Support/CommonPasswordList.php',
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

            $called = $this->streamWrapperCalls((string) file_get_contents($file));

            if ($called !== []) {
                $offenders[] = 'app/'.$relative.' ('.implode(', ', $called).')';
            }
        }

        $this->assertSame([], $offenders, sprintf(
            "These files call a stream-wrapper function without being listed as local-path readers.\nEither read the path through an existing helper, or add the file to LOCAL_FILE_READERS having checked it can never receive a URL.\n%s",
            implode("\n", $offenders),
        ));
    }

    /**
     * The stream-wrapper functions $source reaches, found by tokenising rather than by regex.
     *
     * A regex cannot do this job. Requiring a literal `'https://…'` argument misses the form real
     * code takes; matching the bare name instead hits `$request->file(...)` and even English prose in
     * comments ("an unlinked file (no related entity)"), and dropping `file` to silence that leaves a
     * URL-aware function unguarded — which is exactly how the one real caller in this repository went
     * unnoticed. The tokeniser has already separated comments and strings from code, so the rule can
     * be stated on what it actually means.
     *
     * Two spellings both count as reaching the function:
     *
     *  - a call, `file_get_contents(…)` or `\file_get_contents(…)`. The leading backslash matters:
     *    PHP 8 lexes that as one T_NAME_FULLY_QUALIFIED token, so a scan that only looks at T_STRING
     *    silently passes every fully-qualified call.
     *  - an import, `use function file_get_contents as fetch;`. Renaming defeats any name-based check
     *    at the call site, so the import is what gets flagged.
     *
     * Known limit: a dynamic callable (`$fn = 'file_get_contents'; $fn($url);`) is invisible to this,
     * as it is to any check of this kind. The guard is a guard rail, not a proof.
     *
     * @return list<string>
     */
    private function streamWrapperCalls(string $source): array
    {
        $tokens = array_values(array_filter(
            token_get_all($source),
            fn ($token): bool => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));

        $found = [];

        foreach ($tokens as $i => $token) {
            $name = $this->bannedNameAt($token);

            if ($name === null) {
                continue;
            }

            if ($this->isImport($tokens, $i) || $this->isCall($tokens, $i)) {
                $found[$name] = true;
            }
        }

        ksort($found);

        return array_keys($found);
    }

    /**
     * The banned function this token names, or null.
     *
     * T_NAME_RELATIVE (`namespace\file`) is included because in the global namespace it means the
     * global function; T_NAME_QUALIFIED (`Foo\file`) is not, because a qualified name resolves inside
     * its namespace with no fallback to the global function.
     */
    private function bannedNameAt(mixed $token): ?string
    {
        if (! is_array($token) || ! in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
            return null;
        }

        $name = strtolower($token[1]);
        $name = str_starts_with($name, 'namespace\\') ? substr($name, strlen('namespace\\')) : ltrim($name, '\\');

        return in_array($name, self::STREAM_WRAPPER_FUNCTIONS, true) ? $name : null;
    }

    /** Whether the name at $i is `use function <name>` — an import that any alias would then hide. */
    private function isImport(array $tokens, int $i): bool
    {
        $two = is_array($tokens[$i - 2] ?? null) ? $tokens[$i - 2][0] : null;
        $one = is_array($tokens[$i - 1] ?? null) ? $tokens[$i - 1][0] : null;

        return $two === T_USE && $one === T_FUNCTION;
    }

    /** Whether the name at $i is being called, rather than being a method, a property or a declaration. */
    private function isCall(array $tokens, int $i): bool
    {
        if (($tokens[$i + 1] ?? null) !== '(') {
            return false;
        }

        $previous = $tokens[$i - 1] ?? null;
        $previousType = is_array($previous) ? $previous[0] : null;

        // `$x->file(` / `X::file(` are calls on something else; `function file(` declares one.
        return ! in_array($previousType, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true);
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
            $this->assertNotSame(
                [],
                $this->streamWrapperCalls((string) file_get_contents(app_path($relative))),
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

    public function test_the_stream_wrapper_guard_sees_calls_and_only_calls(): void
    {
        // Each line here is a form that a regex-based version of this guard got wrong at some point.
        $this->assertSame(['file_get_contents'], $this->streamWrapperCalls('<?php $body = file_get_contents($url);'));
        $this->assertSame(['fopen'], $this->streamWrapperCalls('<?php $h = fopen($url, "rb");'));
        $this->assertSame(['file'], $this->streamWrapperCalls('<?php $lines = @file($url, FILE_IGNORE_NEW_LINES);'));
        $this->assertSame(['get_headers'], $this->streamWrapperCalls('<?php $headers = get_headers($url);'));
        $this->assertSame(['copy'], $this->streamWrapperCalls('<?php copy($url, $local);'));

        $this->assertSame([], $this->streamWrapperCalls('<?php $upload = $request->file("images");'));
        $this->assertSame([], $this->streamWrapperCalls('<?php $upload = Request::file("images");'));
        $this->assertSame([], $this->streamWrapperCalls('<?php $upload = $request?->file("images");'));
        $this->assertSame([], $this->streamWrapperCalls('<?php private function copy(string $from): void {}'));
        $this->assertSame([], $this->streamWrapperCalls('<?php // an unlinked file (no related entity)'));
        $this->assertSame([], $this->streamWrapperCalls('<?php /** photo upload, a file (with delete) */'));
        $this->assertSame([], $this->streamWrapperCalls('<?php $sql = "SELECT file (x)";'));
    }

    public function test_the_stream_wrapper_guard_sees_qualified_and_renamed_spellings(): void
    {
        // PHP 8 lexes a leading backslash into the name, so `\file_get_contents(…)` is one
        // T_NAME_FULLY_QUALIFIED token rather than a T_STRING — a scan looking only at T_STRING lets
        // every fully-qualified call through, and writing the backslash is entirely ordinary style.
        $this->assertSame(['file_get_contents'], $this->streamWrapperCalls('<?php $b = \file_get_contents($url);'));
        $this->assertSame(['fopen'], $this->streamWrapperCalls('<?php $h = \fopen($url, "rb");'));
        $this->assertSame(['file'], $this->streamWrapperCalls('<?php namespace\file($url);'));

        // Renaming on import defeats any check made at the call site, so the import is what counts.
        $this->assertSame(['file_get_contents'], $this->streamWrapperCalls('<?php use function file_get_contents as fetch;'));
        $this->assertSame(['file_get_contents'], $this->streamWrapperCalls('<?php use function \file_get_contents;'));

        // A qualified name resolves inside its namespace and does not fall back to the global
        // function, so it is not a way to reach this one.
        $this->assertSame([], $this->streamWrapperCalls('<?php Foo\file($url);'));
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
