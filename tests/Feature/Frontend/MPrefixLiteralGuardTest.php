<?php

namespace Tests\Feature\Frontend;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Guards against /m/ URL literals re-entering the codebase: the retired Modern URL space is served
 * only by the compat redirects, every emitted URL is canonical, and a persisted or outbound URL
 * must be canonical (docs/internals/classic-compatibility.md, key invariant #2 — structural here,
 * not a review item).
 */
class MPrefixLiteralGuardTest extends TestCase
{
    /** A quoted string literal starting a /m/ path (or the bare two-character prefix). */
    private const M_PREFIX_LITERAL_TS = '~[\'"`]/m(?:/|[\'"`])~';

    /** PHP variant: no template literals, and a backtick in a docblock is markdown, not a string. */
    private const M_PREFIX_LITERAL_PHP = '~[\'"]/m(?:/|[\'"])~';

    /**
     * The two homes of the permanent /m/ compat surface. Only shrink this list.
     * - routes/web.php: the compat redirects themselves (reshaped GETs + the catch-all).
     * - MPrefixRedirectTest: drives those redirects.
     */
    private const ALLOWLIST = [
        'routes/web.php',
        'tests/Feature/Compat/MPrefixRedirectTest.php',
    ];

    public function test_no_m_prefix_literal_outside_the_allowlist(): void
    {
        $violations = [];

        foreach ([resource_path('js'), app_path(), base_path('routes'), base_path('tests')] as $base) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (! $file->isFile() || ! preg_match('/\.(?:tsx?|php)$/', $file->getFilename())) {
                    continue;
                }
                $relative = str_replace(base_path().'/', '', $file->getPathname());
                if (in_array($relative, self::ALLOWLIST, true)) {
                    continue;
                }
                $pattern = str_ends_with($file->getFilename(), '.php') ? self::M_PREFIX_LITERAL_PHP : self::M_PREFIX_LITERAL_TS;
                if (preg_match($pattern, (string) file_get_contents($file->getPathname())) === 1) {
                    $violations[] = $relative;
                }
            }
        }

        $this->assertSame([], $violations, 'Files with /m/ URL literals (emit the canonical URL instead): '.implode(', ', $violations));
    }

    public function test_the_allowlist_only_names_existing_files(): void
    {
        foreach (self::ALLOWLIST as $relative) {
            $this->assertFileExists(base_path($relative), "Allowlisted file [{$relative}] is gone — remove the entry.");
        }
    }
}
