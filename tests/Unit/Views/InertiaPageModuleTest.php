<?php

declare(strict_types=1);

namespace Tests\Unit\Views;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

class InertiaPageModuleTest extends TestCase
{
    /**
     * The root view hands the component name to @vite, which throws on a name the manifest lacks;
     * the suite runs without Vite, so only this scan sees a controller naming a missing page.
     */
    public function test_every_rendered_component_has_a_page_module(): void
    {
        $root = dirname(__DIR__, 3);
        $components = [];
        foreach (Finder::create()->files()->in($root.'/app')->name('*.php') as $file) {
            $source = $file->getContents();
            preg_match_all('/(?:Inertia::render|\binertia)\(\s*\'([^\']+)\'/', $source, $direct);
            preg_match_all('/->screen\(\s*\$request,\s*\'[^\']+\',\s*\'([^\']+)\'/', $source, $viaScreen);
            foreach ([...$direct[1], ...$viaScreen[1]] as $component) {
                $components[$component] = $file->getRelativePathname();
            }
        }

        $this->assertGreaterThan(50, count($components));

        $missing = array_filter(
            $components,
            fn (string $component): bool => ! is_file("{$root}/resources/js/pages/{$component}.tsx"),
            ARRAY_FILTER_USE_KEY,
        );
        $this->assertSame([], $missing);
    }
}
