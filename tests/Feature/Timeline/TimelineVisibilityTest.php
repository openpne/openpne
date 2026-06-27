<?php

namespace Tests\Feature\Timeline;

use App\Features\Timeline\TimelineVisibility;
use App\Support\SnsSettingKey;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class TimelineVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_is_excluded_by_default(): void
    {
        // OpenPNE 3 op_activity_is_open defaults off, so the form drops the Open option.
        $this->setSnsSetting(SnsSettingKey::TimelineAllowWebPublic, false);

        $this->assertNotContains(Visibility::Open, TimelineVisibility::options());
        $this->assertContains(Visibility::Members, TimelineVisibility::options());
    }

    public function test_open_is_offered_when_web_public_is_enabled(): void
    {
        $this->setSnsSetting(SnsSettingKey::TimelineAllowWebPublic, true);

        $this->assertContains(Visibility::Open, TimelineVisibility::options());
    }

    public function test_rule_rejects_open_when_web_public_disabled(): void
    {
        $this->setSnsSetting(SnsSettingKey::TimelineAllowWebPublic, false);

        $validator = Validator::make(
            ['visibility' => (string) Visibility::Open->value],
            ['visibility' => TimelineVisibility::rule()],
        );

        $this->assertTrue($validator->fails());
    }

    public function test_rule_allows_open_when_web_public_enabled(): void
    {
        $this->setSnsSetting(SnsSettingKey::TimelineAllowWebPublic, true);

        $validator = Validator::make(
            ['visibility' => (string) Visibility::Open->value],
            ['visibility' => TimelineVisibility::rule()],
        );

        $this->assertFalse($validator->fails());
    }
}
