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
        mkdir($this->dir.'/acme/widgets/src', 0777, true);
        mkdir($this->dir.'/laravel-lang/lang', 0777, true);
        file_put_contents($this->dir.'/acme/widgets/src/View.php', <<<'PHP'
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
            PHP);
        file_put_contents($this->dir.'/acme/widgets/src/escaped.blade.php', "@@lang('Shown verbatim')");
        file_put_contents($this->dir.'/acme/widgets/src/mail.blade.php', "@lang('Regards,')");
        file_put_contents($this->dir.'/laravel-lang/lang/catalog.php', "<?php __('Not Found');");
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    public function test_only_calls_into_the_laravel_translator_count(): void
    {
        $keys = ['Whoops!', 'Go to page :page', 'results', 'Location', 'Forbidden', 'Regards,', 'Not Found', 'Unrelated', "It's gone", 'Shared key', 'Other key', 'Line\\nbreak', "Line\nbreak", "Tab\there", 'Qualified', 'Facade', 'Shown verbatim'];

        $hits = CheckTranslationsCommand::vendorReferencedKeys($this->dir, $keys);
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
            'results' => ['acme/widgets'],
        ], $hits);
    }

    public function test_missing_vendor_dir_yields_nothing(): void
    {
        $this->assertSame([], CheckTranslationsCommand::vendorReferencedKeys($this->dir.'/nope', ['Whoops!']));
    }
}
