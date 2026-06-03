<?php

namespace Tests\Feature\Diary;

use App\Features\Diary\DiaryVisibility;
use App\Support\Visibility;
use Tests\TestCase;

class DiaryVisibilityTest extends TestCase
{
    public function test_options_lead_with_web_public_when_enabled(): void
    {
        config(['openpne.diary.allow_web_public' => true]);

        $this->assertSame(
            [Visibility::Open, Visibility::Members, Visibility::Friends, Visibility::Private],
            DiaryVisibility::options(),
        );
    }

    public function test_options_drop_web_public_when_disabled(): void
    {
        config(['openpne.diary.allow_web_public' => false]);

        $this->assertSame(
            [Visibility::Members, Visibility::Friends, Visibility::Private],
            DiaryVisibility::options(),
        );
    }
}
