<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Support\LayoutHook;
use App\Support\LayoutHooks;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Pins docs/internals/layout-hooks.md, "Key invariants" 3: a case nobody emits is a position a package
 * registers for and never sees.
 */
class LayoutHookCoverageTest extends TestCase
{
    public function test_every_hook_is_emitted_by_a_shell(): void
    {
        $blade = $this->concat(resource_path('views'), 'blade.php');
        $tsx = $this->concat(resource_path('js'), 'tsx', exclude: '.test.tsx');

        // str_contains + assertTrue: the string assertions would print both source trees on failure.
        foreach (LayoutHook::cases() as $hook) {
            if ($hook->isClientRendered()) {
                $this->assertTrue(str_contains($tsx, 'name="'.$hook->value.'"'), "{$hook->value} is drawn by no React shell component");
                $this->assertFalse(str_contains($blade, 'LayoutHook::'.$hook->name), "{$hook->value} is client-rendered and must not also be a Blade position");
            } else {
                $this->assertTrue(str_contains($blade, 'LayoutHook::'.$hook->name), "{$hook->value} is emitted by no Blade layout");
                $this->assertFalse(str_contains($tsx, 'name="'.$hook->value.'"'), "{$hook->value} is a Blade position and must not also be drawn by React");
            }
        }
    }

    public function test_every_hook_name_the_client_draws_is_a_case(): void
    {
        $tsx = $this->concat(resource_path('js'), 'tsx', exclude: '.test.tsx');
        preg_match_all('/<LayoutHook\b[^>]*\bname="([^"]+)"/', $tsx, $matches);

        $this->assertNotEmpty($matches[1]);
        foreach ($matches[1] as $name) {
            $this->assertNotNull(LayoutHook::tryFrom($name), "<LayoutHook name=\"{$name}\"> names no case");
            $this->assertTrue(LayoutHook::from($name)->isClientRendered(), "{$name} is a Blade position");
        }
    }

    public function test_the_client_map_covers_exactly_the_client_rendered_cases(): void
    {
        $hooks = app(LayoutHooks::class);
        foreach (LayoutHook::cases() as $hook) {
            $hooks->register($hook, fn (): string => $hook->value);
        }

        $expected = array_map(fn (LayoutHook $hook): string => $hook->value, array_filter(LayoutHook::cases(), fn (LayoutHook $hook): bool => $hook->isClientRendered()));

        $this->assertSame(array_values($expected), array_keys($hooks->clientMap()));
        $this->assertSame(['modern.left_nav_end', 'modern.main_start', 'modern.main_end', 'modern.right_rail_end', 'modern.auth_start', 'modern.auth_end'], array_keys($hooks->clientMap()));
    }

    private function concat(string $root, string $extension, ?string $exclude = null): string
    {
        $out = '';
        foreach (File::allFiles($root) as $file) {
            $path = $file->getPathname();
            if (! str_ends_with($path, '.'.$extension) || ($exclude !== null && str_ends_with($path, $exclude))) {
                continue;
            }
            $out .= $file->getContents()."\n";
        }

        return $out;
    }
}
