<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * A registration link handed to its caller creates a member whose address nobody proved, which only
 * the operator's commands may do (docs/internals/security.md, "Registration links").
 */
class RegistrationLinkPrintersTest extends TestCase
{
    private const ALLOWED = [
        'app/Console/Commands/InviteAdministratorCommand.php',
        'app/Console/Commands/InviteMemberCommand.php',
        'app/Features/Auth/Actions/IssueRegistrationToken.php',
    ];

    private const NAME = 'unmailedLink';

    public function test_only_the_operator_commands_take_a_link_instead_of_a_mail(): void
    {
        $offending = [];
        foreach (['app', 'routes'] as $dir) {
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

        $this->assertSame([], $offending, self::NAME.'() named outside the operator commands');
        foreach (self::ALLOWED as $path) {
            $this->assertStringContainsString(self::NAME, (string) file_get_contents(base_path($path)), $path.' no longer names what this test pins');
        }
    }
}
