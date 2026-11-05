<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * A run owns nothing outside its own schema and session (docs/internals/upgrade.md, "Checkpoints and resume").
 */
class UpgradeServerStateTest extends TestCase
{
    private const SERVER_GLOBAL = [
        '/\bGET_LOCK\b/i', '/\bRELEASE_LOCK\b/i', '/\bSET\s+GLOBAL\b/i', '/\bSET\s+PERSIST\b/i', '/@@(GLOBAL|PERSIST)\b/i',
        '/\bLOCK\s+TABLES\b/i', '/\bFLUSH\s+(TABLES|PRIVILEGES|LOGS|STATUS)\b/i', '/\bCREATE\s+TEMPORARY\b/i',
    ];

    public function test_the_upgrade_tool_issues_nothing_server_global(): void
    {
        foreach ($this->sources() as $path => $source) {
            $code = preg_replace('~^\s*(//|\*|/\*\*).*$~m', '', $source) ?? $source;
            foreach (self::SERVER_GLOBAL as $pattern) {
                $this->assertDoesNotMatchRegularExpression($pattern, $code, "{$path} matches {$pattern}, which another site's run on the same server would see");
            }
        }
    }

    public function test_every_information_schema_read_is_qualified_by_schema(): void
    {
        $reads = 0;
        foreach ($this->sources() as $path => $source) {
            preg_match_all('/information_schema\.\w+/i', $source, $hits, PREG_OFFSET_CAPTURE);
            foreach ($hits[0] as [$hit, $offset]) {
                $reads++;
                $this->assertMatchesRegularExpression(
                    '/(table_schema|constraint_schema)\s*=\s*\?/i',
                    $this->literalAround($source, $offset),
                    "{$path}: an information_schema read binds no schema, so it would see another site's tables",
                );
            }
        }

        $this->assertGreaterThan(3, $reads, 'Found too few information_schema reads — the scan is broken.');
    }

    /** The PHP string literal the offset sits in: the SQL of one call, never the next call's. */
    private function literalAround(string $source, int $offset): string
    {
        $before = substr($source, 0, $offset);
        $quote = max(strrpos($before, "'"), strrpos($before, '"'));
        $this->assertNotFalse($quote, 'an information_schema read outside a string literal');
        $char = $source[$quote];
        $end = strpos($source, $char, $offset);

        return substr($source, $quote + 1, ($end === false ? strlen($source) : $end) - $quote - 1);
    }

    /** @return array<string, string> relative path => source */
    private function sources(): array
    {
        $files = File::allFiles(app_path('Upgrade'));
        foreach (File::files(app_path('Console/Commands')) as $file) {
            if (str_contains($file->getFilename(), 'Upgrade')) {
                $files[] = $file;
            }
        }
        $out = [];
        foreach ($files as $file) {
            if ($file->getExtension() === 'php') {
                $out[substr($file->getPathname(), strlen(base_path()) + 1)] = $file->getContents();
            }
        }

        return $out;
    }
}
