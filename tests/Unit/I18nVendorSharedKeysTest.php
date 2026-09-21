<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\CheckTranslationsCommand;
use PHPUnit\Framework\TestCase;

class I18nVendorSharedKeysTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/i18n-vendor-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/vendor/acme/widgets/src', 0777, true);
        mkdir($this->dir.'/vendor/acme/widgets/stubs', 0777, true);
        mkdir($this->dir.'/vendor/acme/widgets/src/fixtures', 0777, true);
        mkdir($this->dir.'/vendor/acme/devtool/src', 0777, true);
        file_put_contents($this->dir.'/vendor/acme/widgets/src/View.php', <<<'PHP'
            <?php
            echo __('Whoops!');
            echo trans("Go to page :page", ['page' => 1]);
            echo trans_choice('results', 2);
            $headers->get('Location');
            $label = 'Forbidden';
            echo __('It\'s gone');
            echo $translator->trans('Shared key');
            echo OtherLang::get('Other key');
            echo __('Line\nbreak');
            echo __("Tab\there");
            echo \__('Qualified');
            echo Facades\Lang::get('Facade');
            echo Str::trans('Static method');
            echo __('filament-actions::x.label');
            echo __('validation.required');
            echo __('status.ok');
            PHP);
        file_put_contents($this->dir.'/vendor/acme/widgets/src/mail.blade.php', "@lang('Regards,')");
        file_put_contents($this->dir.'/vendor/acme/widgets/src/escaped.blade.php', "@@lang('Shown verbatim')");
        file_put_contents($this->dir.'/vendor/acme/widgets/stubs/Stub.php', "<?php __('Stub only');");
        file_put_contents($this->dir.'/vendor/acme/widgets/src/fixtures/F.php', "<?php __('Fixture only');");
        file_put_contents($this->dir.'/vendor/acme/devtool/src/Tool.php', "<?php __('Dev only');");
        file_put_contents($this->dir.'/composer.lock', json_encode([
            'packages' => [['name' => 'acme/widgets'], ['name' => 'acme/gone'], ['name' => 'acme/meta', 'type' => 'metapackage']],
            'packages-dev' => [['name' => 'acme/devtool']],
        ]));
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    public function test_only_calls_into_the_laravel_translator_in_runtime_code_count(): void
    {
        $hits = CheckTranslationsCommand::vendorTranslatorLiterals($this->dir.'/vendor', ['acme/widgets', 'acme/gone']);
        ksort($hits);

        $this->assertSame([
            'Facade' => ['acme/widgets'],
            'Go to page :page' => ['acme/widgets'],
            "It's gone" => ['acme/widgets'],
            'Line\\nbreak' => ['acme/widgets'],
            'Qualified' => ['acme/widgets'],
            'Regards,' => ['acme/widgets'],
            "Tab\there" => ['acme/widgets'],
            'Whoops!' => ['acme/widgets'],
            'filament-actions::x.label' => ['acme/widgets'],
            'results' => ['acme/widgets'],
            'status.ok' => ['acme/widgets'],
            'validation.required' => ['acme/widgets'],
        ], $hits);
    }

    public function test_missing_vendor_dir_yields_nothing(): void
    {
        $this->assertSame([], CheckTranslationsCommand::vendorTranslatorLiterals($this->dir.'/nope', ['acme/widgets']));
    }

    public function test_scannable_packages_are_the_production_ones(): void
    {
        $this->assertSame(['acme/widgets', 'acme/gone'], CheckTranslationsCommand::scannablePackages($this->dir.'/composer.lock'));
        $this->assertSame([], CheckTranslationsCommand::scannablePackages($this->dir.'/missing.lock'));

        file_put_contents($this->dir.'/broken.lock', '{not json');
        $this->assertSame([], CheckTranslationsCommand::scannablePackages($this->dir.'/broken.lock'));

        file_put_contents($this->dir.'/empty.lock', json_encode(['packages' => [], 'packages-dev' => [['name' => 'acme/devtool']]]));
        $this->assertSame([], CheckTranslationsCommand::scannablePackages($this->dir.'/empty.lock'));
    }

    public function test_an_empty_scan_is_an_error(): void
    {
        $this->assertNotNull(CheckTranslationsCommand::scanFloorError([], [], ['Whoops!' => ['acme/widgets']]));
        $this->assertNotNull(CheckTranslationsCommand::scanFloorError(['acme/widgets', 'acme/gone'], ['acme/gone'], ['Whoops!' => ['acme/widgets']]));
        $this->assertNotNull(CheckTranslationsCommand::scanFloorError(['acme/widgets'], [], []));
        $this->assertNull(CheckTranslationsCommand::scanFloorError(['acme/widgets'], [], ['Whoops!' => ['acme/widgets']]));
    }

    public function test_gaps_are_plain_text_keys_without_a_japanese_value(): void
    {
        $literals = array_fill_keys([
            'Whoops!', 'Regards,', 'All rights reserved.', 'Empty one', 'Missing one',
            'filament-actions::x.label', 'validation.required', 'status.ok',
        ], ['acme/widgets']);
        $ja = [
            'Whoops!' => 'おっと！',
            'Regards,' => 'Regards,',
            'All rights reserved.' => 'All rights reserved.',
            'Empty one' => '',
            'validation.required' => 'unexpected but ignored',
        ];

        $gaps = CheckTranslationsCommand::vendorGaps($literals, $ja, ['validation', 'auth'], ['All rights reserved.' => true]);

        $this->assertSame([
            'Empty one' => 'empty in',
            'Missing one' => 'missing from',
            'Regards,' => 'still English in',
            'status.ok' => 'missing from',
        ], $gaps);
    }
}
