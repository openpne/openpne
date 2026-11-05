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
    private const SERVER_GLOBAL = ['GET_LOCK', 'RELEASE_LOCK', 'SET GLOBAL', 'SET PERSIST', 'LOCK TABLES', 'FLUSH ', 'CREATE TEMPORARY'];

    public function test_the_upgrade_tool_issues_nothing_server_global(): void
    {
        foreach ($this->sources() as $path => $source) {
            $code = preg_replace('~^\s*(//|\*|/\*\*).*$~m', '', $source) ?? $source;
            foreach (self::SERVER_GLOBAL as $needle) {
                $this->assertFalse(stripos($code, $needle) !== false, "{$path} uses {$needle}, which another site's run on the same server would see");
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
                $statement = substr($source, $offset, 600);
                $this->assertMatchesRegularExpression('/(table_schema|constraint_schema)\s*=\s*\?/i', $statement, "{$path}: an information_schema read binds no schema, so it would see another site's tables");
            }
        }

        $this->assertGreaterThan(3, $reads, 'Found too few information_schema reads — the scan is broken.');
    }

    /** @return array<string, string> relative path => source */
    private function sources(): array
    {
        $out = [];
        foreach (File::allFiles(app_path('Upgrade')) as $file) {
            if ($file->getExtension() === 'php') {
                $out[substr($file->getPathname(), strlen(base_path()) + 1)] = $file->getContents();
            }
        }

        return $out;
    }
}
