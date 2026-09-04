<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Pins the single-seam rule from docs/internals/outbound-http.md: App\Outbound alone may open an
 * outbound connection, since SSRF defence is only the property that every fetch of a member-supplied
 * URL went through the guard. Stream wrappers and raw sockets are forbidden too, as they dereference
 * a URL just as well.
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
     * PHP functions that take a path and will just as happily take a URL. Banned outright, since
     * matching only a literal URL argument would miss `file_get_contents($url)`, and the local-path
     * readers are named instead.
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
        'Files/ImageDimensions.php',
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
     * Tokenised rather than matched by regex: a bare-name regex hits `$request->file(...)` and prose
     * in comments, and requiring a literal URL argument misses the form real code takes. A call and a
     * `use function` import both count; a dynamic callable is invisible to this, as to any check of
     * this kind.
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
        $importing = false;

        foreach ($tokens as $i => $token) {
            // Tracked as a statement up to the semicolon, since a fixed lookbehind sees only the first
            // name of a comma-separated import.
            if (is_array($token) && $token[0] === T_USE) {
                $importing = is_array($tokens[$i + 1] ?? null) && $tokens[$i + 1][0] === T_FUNCTION;

                continue;
            }

            if ($token === ';') {
                $importing = false;

                continue;
            }

            $name = $this->bannedNameAt($token);

            if ($name === null) {
                continue;
            }

            if ($importing || $this->isCall($tokens, $i)) {
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
        // A typo'd regex would pass everything forever.
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

        // A harmless first name in a comma-separated import must not hide the rest.
        $this->assertSame(['file_get_contents'], $this->streamWrapperCalls('<?php use function strlen, file_get_contents as fetch;'));
        $this->assertSame(['copy', 'fopen'], $this->streamWrapperCalls('<?php use function strlen, fopen as open, copy;'));

        // The statement ends at the semicolon: a later mention must be judged as a call, not carried
        // along by the import.
        $this->assertSame([], $this->streamWrapperCalls('<?php use function strlen; $x->copy();'));

        // A closure's `use (` and a trait's `use Foo;` are a different `use` and must not arm it.
        $this->assertSame([], $this->streamWrapperCalls('<?php $f = function () use ($copy) { return $copy; };'));
        $this->assertSame([], $this->streamWrapperCalls('<?php class A { use CopyTrait; }'));

        // A qualified name resolves inside its namespace and does not fall back to the global
        // function, so it is not a way to reach this one.
        $this->assertSame([], $this->streamWrapperCalls('<?php Foo\file($url);'));
    }

    /**
     * PushClientFactory lives in the allowlisted directory, so the directory allowlist would say
     * nothing about another file picking up a client that validates no address and pins no connection.
     */
    public function test_the_push_client_is_reachable_only_from_the_push_seam(): void
    {
        $allowed = [
            'Outbound/PushClientFactory.php',
            'Providers/WebPushServiceProvider.php',
        ];

        $offenders = [];

        foreach ($this->appFiles() as $file) {
            $relative = str_replace(app_path().'/', '', $file);

            if (in_array($relative, $allowed, true)) {
                continue;
            }

            if (str_contains((string) file_get_contents($file), 'PushClientFactory')) {
                $offenders[] = 'app/'.$relative;
            }
        }

        $this->assertSame([], $offenders, sprintf(
            "These files reach the push client. It validates nothing and pins nothing — send through App\\Outbound\\SafeHttpFetcher instead.\n%s",
            implode("\n", $offenders),
        ));
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
