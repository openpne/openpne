<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * A pending email change is written only where the new address is checked against NewEmailAddress
 * (docs/internals/text-comparison.md, "Identifiers"); a second writer could store one the identifier collation folds.
 */
class EmailChangeRequestWritersTest extends TestCase
{
    private const WRITER = 'app/Features/Member/Actions/RequestEmailChange.php';

    private const WRITE = '/EmailChangeRequest::(?:upsert|create|insert|forceCreate|firstOrCreate|updateOrCreate|updateOrInsert)\(|new EmailChangeRequest\b|DB::table\(\s*[\'"]email_change_requests[\'"]\s*\)\s*->\s*(?:insert|upsert|updateOrInsert|update)|EmailChangeRequest::[^;]*->\s*(?:update|fill|forceFill)\(\s*\[[^\]]*new_email|->\s*new_email\s*=[^=>]/';

    public function test_only_the_request_action_writes_email_change_requests(): void
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

        $this->assertSame([], $offending, 'email change requests written outside '.self::WRITER);
        $this->assertMatchesRegularExpression(self::WRITE, (string) file_get_contents(base_path(self::WRITER)), 'the writer no longer matches the pattern this test pins');
    }
}
