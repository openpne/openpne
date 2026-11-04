<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Every registration link is issued through IssueRegistrationToken, where the member capacity is
 * checked (docs/internals/capacity.md, "Where a seat is taken"); a second writer would hand out
 * seats the cap never saw.
 */
class RegistrationTokenWritersTest extends TestCase
{
    private const WRITER = 'app/Features/Auth/Actions/IssueRegistrationToken.php';

    private const WRITE = '/RegistrationToken::(?:upsert|create|insert|forceCreate|firstOrCreate|updateOrCreate)\(|DB::table\(\s*[\'"]registration_tokens[\'"]\s*\)\s*->\s*(?:insert|upsert|updateOrInsert)/';

    public function test_only_the_issuance_action_writes_registration_tokens(): void
    {
        $offending = [];
        $root = base_path('app');
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $path = 'app/'.substr($file->getPathname(), strlen($root) + 1);
            if ($path === self::WRITER) {
                continue;
            }
            foreach (file($file->getPathname()) ?: [] as $number => $line) {
                if (preg_match(self::WRITE, $line) === 1) {
                    $offending[] = $path.':'.($number + 1);
                }
            }
        }

        $this->assertSame([], $offending, 'registration tokens written outside '.self::WRITER);
        $this->assertMatchesRegularExpression(self::WRITE, (string) file_get_contents(base_path(self::WRITER)), 'the writer no longer matches the pattern this test pins');
    }
}
