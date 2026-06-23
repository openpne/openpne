<?php

namespace Tests\Feature\Timeline;

use App\Features\Timeline\TimelineVisibility;
use App\Support\Visibility;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class TimelineVisibilityTest extends TestCase
{
    public function test_open_is_excluded_by_default(): void
    {
        // OpenPNE 3 op_activity_is_open defaults off, so the form drops the Open option.
        config()->set('openpne.timeline.allow_web_public', false);

        $this->assertNotContains(Visibility::Open, TimelineVisibility::options());
        $this->assertContains(Visibility::Members, TimelineVisibility::options());
    }

    public function test_open_is_offered_when_web_public_is_enabled(): void
    {
        config()->set('openpne.timeline.allow_web_public', true);

        $this->assertContains(Visibility::Open, TimelineVisibility::options());
    }

    public function test_rule_rejects_open_when_web_public_disabled(): void
    {
        config()->set('openpne.timeline.allow_web_public', false);

        $validator = Validator::make(
            ['visibility' => (string) Visibility::Open->value],
            ['visibility' => TimelineVisibility::rule()],
        );

        $this->assertTrue($validator->fails());
    }

    public function test_rule_allows_open_when_web_public_enabled(): void
    {
        config()->set('openpne.timeline.allow_web_public', true);

        $validator = Validator::make(
            ['visibility' => (string) Visibility::Open->value],
            ['visibility' => TimelineVisibility::rule()],
        );

        $this->assertFalse($validator->fails());
    }
}
