<?php

namespace Tests\Unit\Support;

use App\Support\ClassicThemes;
use Tests\TestCase;

class ClassicThemesTest extends TestCase
{
    protected function tearDown(): void
    {
        ClassicThemes::forget();
        parent::tearDown();
    }

    public function test_the_bundled_packages_are_read_off_their_main_stylesheet_headers(): void
    {
        $themes = ClassicThemes::all();

        $this->assertSame(
            ['bamboo', 'bootstrap', 'cat', 'cerulean', 'cerulean-dark', 'cerulean-light', 'cerulean-ocean', 'cerulean-sakura', 'cerulean-tree', 'sunflower', 'winter'],
            array_keys($themes),
        );
        // The header, not the directory, names a theme: cerulean-ocean calls itself "ocean".
        $this->assertSame('ocean', $themes['cerulean-ocean']->name);
        $this->assertSame('cerulean of Bootswatch', $themes['cerulean']->description);
        $this->assertSame('sonobe', $themes['cerulean']->author);
        $this->assertSame(
            ['opSkinThemePlugin/cerulean/css/bootstrap.css', 'opSkinThemePlugin/cerulean/css/font-awesome.css', 'opSkinThemePlugin/cerulean/css/foundation.css', 'opSkinThemePlugin/cerulean/css/main.css'],
            $themes['cerulean']->stylesheets,
        );
    }

    public function test_only_winter_owes_a_footer_credit(): void
    {
        $credited = array_keys(array_filter(ClassicThemes::all(), fn ($theme) => $theme->credit() !== null));

        $this->assertSame(['winter'], $credited);
    }

    public function test_a_name_outside_the_directory_rule_or_not_on_disk_finds_nothing(): void
    {
        foreach (['', '../opSkinBasicPlugin', 'cerulean/..', 'no-such-theme', 'cerulean ', "cerulean\0", "cerulean\n"] as $dir) {
            $this->assertNull(ClassicThemes::find($dir), json_encode($dir));
        }
        $this->assertNotNull(ClassicThemes::find('cerulean'));
    }
}
