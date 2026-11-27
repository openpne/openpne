<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationGroup;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionProperty;
use Tests\TestCase;

/**
 * The administrator gate alone authorizes the panel, so no screen consults the member policies,
 * and nothing names a guard of its own for administrators (docs/internals/administrators.md, "The admin panel").
 */
class AdminPanelAuthorizationTest extends TestCase
{
    public function test_every_resource_and_relation_manager_skips_the_policies(): void
    {
        $screens = [];
        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            $screens[] = $resource;
            foreach ($resource::getRelations() as $relation) {
                array_push($screens, ...($relation instanceof RelationGroup ? $relation->getManagers() : [$relation]));
            }
        }

        $consulting = array_values(array_filter(
            $screens,
            fn (string $screen): bool => (new ReflectionProperty($screen, 'shouldSkipAuthorization'))->getDefaultValue() !== true,
        ));

        $this->assertGreaterThan(10, count($screens));
        $this->assertSame([], $consulting, 'set `protected static bool $shouldSkipAuthorization = true;` on these');
    }

    public function test_no_code_names_an_admin_guard(): void
    {
        $offending = [];
        foreach (['app', 'config', 'routes', 'resources/views'] as $dir) {
            $root = base_path($dir);
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                if (preg_match('/\b(auth|guard|guest|user|check)\(\s*[\'"]admin[\'"]\s*\)|[\'"]guard[\'"]\s*=>\s*[\'"]admin[\'"]|actingAs\([^)]*[\'"]admin[\'"]/', (string) file_get_contents($file->getPathname())) === 1) {
                    $offending[] = $dir.'/'.substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }

        $this->assertSame([], $offending);
        $this->assertArrayNotHasKey('admin', config('auth.guards'));
    }
}
