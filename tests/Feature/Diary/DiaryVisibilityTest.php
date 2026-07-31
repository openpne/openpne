<?php

namespace Tests\Feature\Diary;

use App\Features\Diary\DiaryVisibility;
use App\Support\Feature;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\Rules\Enum;
use Tests\TestCase;

class DiaryVisibilityTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_options_drop_friends_while_the_unit_is_off(): void
    {
        config(['openpne.diary.allow_web_public' => false]);
        $this->setSnsSetting(Feature::Friend->settingKey(), false);

        $this->assertSame([Visibility::Members, Visibility::Private], DiaryVisibility::options());
    }

    public function test_options_keep_friends_for_an_entry_already_stored_there(): void
    {
        config(['openpne.diary.allow_web_public' => false]);
        $this->setSnsSetting(Feature::Friend->settingKey(), false);

        $this->assertSame(
            [Visibility::Members, Visibility::Friends, Visibility::Private],
            DiaryVisibility::options(Visibility::Friends),
        );
        // An entry stored at another tier gains nothing: editing may not move it to a dead audience.
        $this->assertSame([Visibility::Members, Visibility::Private], DiaryVisibility::options(Visibility::Private));
    }

    public function test_both_gates_off_leave_members_and_private(): void
    {
        config(['openpne.diary.allow_web_public' => false]);
        $this->setSnsSetting(Feature::Friend->settingKey(), false);

        $this->assertSame([Visibility::Members, Visibility::Private], DiaryVisibility::options());
        $this->assertFalse($this->passes(DiaryVisibility::rule(), Visibility::Open));
        $this->assertFalse($this->passes(DiaryVisibility::rule(), Visibility::Friends));
        $this->assertTrue($this->passes(DiaryVisibility::rule(), Visibility::Members));
        $this->assertTrue($this->passes(DiaryVisibility::rule(), Visibility::Private));
    }

    public function test_the_rule_accepts_friends_only_for_an_entry_stored_there(): void
    {
        config(['openpne.diary.allow_web_public' => false]);
        $this->setSnsSetting(Feature::Friend->settingKey(), false);

        $this->assertFalse($this->passes(DiaryVisibility::rule(), Visibility::Friends));
        $this->assertTrue($this->passes(DiaryVisibility::rule(Visibility::Friends), Visibility::Friends));
        // The sticky current widens nothing else: web-public stays gated by its own setting.
        $this->assertFalse($this->passes(DiaryVisibility::rule(Visibility::Friends), Visibility::Open));
    }

    public function test_friends_stay_offered_while_the_unit_is_on(): void
    {
        config(['openpne.diary.allow_web_public' => false]);

        $this->assertSame(
            [Visibility::Members, Visibility::Friends, Visibility::Private],
            DiaryVisibility::options(),
        );
        $this->assertTrue($this->passes(DiaryVisibility::rule(), Visibility::Friends));
    }

    private function passes(Enum $rule, Visibility $value): bool
    {
        return validator(['visibility' => (string) $value->value], ['visibility' => ['required', $rule]])->passes();
    }
}
