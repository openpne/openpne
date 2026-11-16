<?php

namespace Tests\Unit\Support;

use Tests\TestCase;

class VersionedAssetTest extends TestCase
{
    private string $public;

    protected function setUp(): void
    {
        parent::setUp();

        $this->public = sys_get_temp_dir().'/openpne-versioned-asset-'.uniqid();
        mkdir($this->public.'/js', 0777, true);
        file_put_contents($this->public.'/js/a.js', 'one');
        $this->app->usePublicPath($this->public);
    }

    protected function tearDown(): void
    {
        @unlink($this->public.'/js/a.js');
        rmdir($this->public.'/js');
        rmdir($this->public);

        parent::tearDown();
    }

    public function test_the_url_is_the_asset_url_with_sixteen_hex_digits_as_v(): void
    {
        $this->assertMatchesRegularExpression(
            '/\A'.preg_quote(asset('js/a.js'), '/').'\?v=[0-9a-f]{16}\z/',
            versioned_asset('js/a.js'),
        );
    }

    public function test_the_version_follows_the_bytes_and_nothing_else(): void
    {
        $first = versioned_asset('js/a.js');

        touch($this->public.'/js/a.js', time() + 60);
        clearstatcache();
        $this->assertSame($first, versioned_asset('js/a.js'));

        file_put_contents($this->public.'/js/a.js', 'two');
        $second = versioned_asset('js/a.js');
        $this->assertNotSame($first, $second);

        file_put_contents($this->public.'/js/a.js', 'one');
        $this->assertSame($first, versioned_asset('js/a.js'));
    }

    public function test_another_application_key_gives_another_version(): void
    {
        $first = versioned_asset('js/a.js');

        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);

        $this->assertNotSame($first, versioned_asset('js/a.js'));
    }

    public function test_a_file_that_cannot_be_read_keeps_its_plain_url(): void
    {
        $this->assertSame(asset('js/missing.js'), versioned_asset('js/missing.js'));
        $this->assertSame(asset('js'), versioned_asset('js'));
    }
}
