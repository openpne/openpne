<?php

declare(strict_types=1);

namespace Tests\Unit\Views;

use PHPUnit\Framework\TestCase;

class StaticAssetVersionGuardTest extends TestCase
{
    /** Matched on the source text, so only a literal path is seen: the calls passing a variable are held by the Classic stylesheet feature tests. */
    public function test_no_script_or_stylesheet_is_linked_through_plain_asset(): void
    {
        $root = dirname(__DIR__, 3);
        $found = [];

        foreach (['resources/views', 'app'] as $dir) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root.'/'.$dir, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $source = (string) file_get_contents($file->getPathname());
                if (preg_match_all('/(?<![\w>:$])asset\(\s*[\'"][^\'"]+\.(?:js|css)[\'"]/', $source, $matches) > 0) {
                    $found[str_replace($root.'/', '', $file->getPathname())] = $matches[0];
                }
            }
        }

        $this->assertSame([], $found, 'link scripts and stylesheets under public/ through versioned_asset()');
    }
}
