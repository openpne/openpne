<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * A setup link sets an administrator's password for whoever holds it, so only the operator's command
 * may print one (docs/internals/security.md, "Administrator password links").
 */
class AdminPasswordLinkPrintersTest extends TestCase
{
    private const ALLOWED = [
        'app/Auth/AdminPasswords.php',
        'app/Console/Commands/AdminPasswordLinkCommand.php',
    ];

    private const NAME = 'issueSetupLink';

    public function test_only_the_operator_command_issues_a_setup_link(): void
    {
        $offending = [];
        foreach (['app', 'routes', 'resources/views'] as $dir) {
            $root = base_path($dir);
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                $path = $dir.'/'.substr($file->getPathname(), strlen($root) + 1);
                if ($file->getExtension() !== 'php' || in_array($path, self::ALLOWED, true)) {
                    continue;
                }
                if (str_contains((string) file_get_contents($file->getPathname()), self::NAME)) {
                    $offending[] = $path;
                }
            }
        }

        $this->assertSame([], $offending, self::NAME.'() named outside the operator command');
        foreach (self::ALLOWED as $path) {
            $this->assertStringContainsString(self::NAME, (string) file_get_contents(base_path($path)), $path.' no longer names what this test pins');
        }
    }
}
