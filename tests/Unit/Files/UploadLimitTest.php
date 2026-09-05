<?php

namespace Tests\Unit\Files;

use App\Files\UploadLimit;
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

    public function test_a_negative_setting_reads_as_zero_rather_than_inverting_the_rule(): void
    {
        config()->set('openpne.images.max_upload_kilobytes', -1);

        $this->assertSame(0, UploadLimit::kilobytes());
    }
}
