<?php

namespace Tests\Unit\Files;

use App\Files\UploadLimit;
use App\Providers\FilesServiceProvider;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Tests\TestCase;

class UploadLimitTest extends TestCase
{
    public function test_the_cap_is_the_configured_kilobytes(): void
    {
        config()->set('openpne.images.max_upload_kilobytes', 300);

        $this->assertSame(300, UploadLimit::kilobytes());
        $this->assertSame(300 * 1024, UploadLimit::bytes());
    }

    public function test_the_shipped_default_is_five_megabytes(): void
    {
        $this->assertSame(5120, UploadLimit::kilobytes());
    }

    public function test_a_blank_or_non_positive_setting_is_the_default_rather_than_a_cap_of_zero(): void
    {
        // `(int) env()` turns a blank variable into 0; the config default and the constant must agree.
        foreach ([0, -1, (int) ''] as $misconfigured) {
            config()->set('openpne.images.max_upload_kilobytes', $misconfigured);

            $this->assertSame(UploadLimit::DEFAULT_KILOBYTES, UploadLimit::kilobytes());
        }
    }

    public function test_the_config_fallback_and_the_constant_are_one_number(): void
    {
        // An absent env reads the config fallback and a blank one the constant, so both must be one cap.
        $this->assertSame(UploadLimit::DEFAULT_KILOBYTES, (int) config('openpne.images.max_upload_kilobytes'));
        $this->assertContains('max:'.UploadLimit::DEFAULT_KILOBYTES, FileUploadConfiguration::rules());
    }

    public function test_the_livewire_temporary_upload_rule_follows_the_cap(): void
    {
        // Above Livewire's own 12288 KB, so the shipped rule cannot be what the assertion sees.
        config()->set('openpne.images.max_upload_kilobytes', 20480);

        (new FilesServiceProvider($this->app))->register();

        $this->assertContains('max:20480', FileUploadConfiguration::rules());
    }
}
