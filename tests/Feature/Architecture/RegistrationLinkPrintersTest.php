<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * A registration link handed to its caller creates a member whose address nobody proved, which only
 * the operator's command may do (docs/internals/security.md, "Registration links").
 */
class RegistrationLinkPrintersTest extends TestCase
{
    private const PRINTER = 'app/Console/Commands/InviteMemberCommand.php';

    private const DEFINITION = 'app/Features/Auth/Actions/IssueRegistrationToken.php';

    private const CALL = '/->link\(/';

    public function test_only_the_operator_command_takes_a_link_instead_of_a_mail(): void
    {
        $offending = [];
        $root = base_path('app');
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $path = 'app/'.substr($file->getPathname(), strlen($root) + 1);
            $source = (string) file_get_contents($file->getPathname());
            if ($path === self::PRINTER || $path === self::DEFINITION || ! str_contains($source, 'IssueRegistrationToken')) {
                continue;
            }
            if (preg_match(self::CALL, $source) === 1) {
                $offending[] = $path;
            }
        }

        $this->assertSame([], $offending, 'IssueRegistrationToken::link() called outside '.self::PRINTER);
        $this->assertMatchesRegularExpression(self::CALL, (string) file_get_contents(base_path(self::PRINTER)), 'the command no longer matches the pattern this test pins');
    }
}
