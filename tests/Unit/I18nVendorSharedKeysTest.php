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
            PHP);
        file_put_contents($this->dir.'/acme/widgets/src/mail.blade.php', "@lang('Regards,')");
        file_put_contents($this->dir.'/laravel-lang/lang/catalog.php', "<?php __('Not Found');");
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    public function test_only_translation_calls_in_vendor_count(): void
    {
        $keys = ['Whoops!', 'Go to page :page', 'results', 'Location', 'Forbidden', 'Regards,', 'Not Found', 'Unrelated'];

        $hits = CheckTranslationsCommand::vendorReferencedKeys($this->dir, $keys);
        ksort($hits);

        $this->assertSame([
            'Go to page :page' => ['acme/widgets'],
            'Regards,' => ['acme/widgets'],
            'Whoops!' => ['acme/widgets'],
            'results' => ['acme/widgets'],
        ], $hits);
    }

    public function test_missing_vendor_dir_yields_nothing(): void
    {
        $this->assertSame([], CheckTranslationsCommand::vendorReferencedKeys($this->dir.'/nope', ['Whoops!']));
    }
}
