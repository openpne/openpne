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
            ['bootstrap', 'cerulean', 'cerulean-light'],
            array_keys($themes),
        );
        $this->assertSame('cerulean of Bootswatch', $themes['cerulean']->description);
        $this->assertSame('sonobe', $themes['cerulean']->author);
        $this->assertSame(
            ['opSkinThemePlugin/cerulean/css/bootstrap.css', 'opSkinThemePlugin/cerulean/css/font-awesome.css', 'opSkinThemePlugin/cerulean/css/foundation.css', 'opSkinThemePlugin/cerulean/css/main.css'],
            $themes['cerulean']->stylesheets,
        );
    }

    public function test_the_header_not_the_directory_names_a_theme(): void
    {
        $public = sys_get_temp_dir().'/openpne-themes-'.uniqid();
        mkdir($public.'/opSkinThemePlugin/my-dir/css', 0777, true);
        file_put_contents($public.'/opSkinThemePlugin/my-dir/css/main.css', "/*\nTheme Name:ocean\nDescription:blue\nAuthor:someone\n*/\n");
        $this->app->usePublicPath($public);
        ClassicThemes::forget();

        try {
            $this->assertSame(['my-dir'], array_keys(ClassicThemes::all()));
            $this->assertSame('ocean', ClassicThemes::find('my-dir')?->name);
        } finally {
            unlink($public.'/opSkinThemePlugin/my-dir/css/main.css');
            rmdir($public.'/opSkinThemePlugin/my-dir/css');
            rmdir($public.'/opSkinThemePlugin/my-dir');
            rmdir($public.'/opSkinThemePlugin');
            rmdir($public);
        }
    }

    public function test_a_name_outside_the_directory_rule_or_not_on_disk_finds_nothing(): void
    {
        foreach (['', '../opSkinBasicPlugin', 'cerulean/..', 'no-such-theme', 'cerulean ', "cerulean\0", "cerulean\n"] as $dir) {
            $this->assertNull(ClassicThemes::find($dir), json_encode($dir));
        }
        $this->assertNotNull(ClassicThemes::find('cerulean'));
    }
}
