<?php

namespace Tests\Unit\Profile;

use App\Models\Profile;
use App\Support\Visibility;
use PHPUnit\Framework\TestCase;

class ProfileTest extends TestCase
{
    public function test_visibility_options_offer_open_only_when_web_public(): void
    {
        $web = (new Profile)->forceFill(['is_public_web' => true]);
        $this->assertSame(
            [Visibility::Open, Visibility::Members, Visibility::Friends, Visibility::Private],
            $web->visibilityOptions(),
        );

        $closed = (new Profile)->forceFill(['is_public_web' => false]);
        $this->assertSame(
            [Visibility::Members, Visibility::Friends, Visibility::Private],
            $closed->visibilityOptions(),
        );
    }

    public function test_is_preset_detects_op_preset_prefix(): void
    {
        $this->assertTrue($this->profile('op_preset_sex', 'select')->isPreset());
        $this->assertFalse($this->profile('custom_field', 'select')->isPreset());
    }

    public function test_is_multiple_select_is_checkbox_or_custom_date(): void
    {
        // checkbox is always multi-select.
        $this->assertTrue($this->profile('hobbies', 'checkbox')->isMultipleSelect());
        // A custom (non-preset) date is multi-select (year/month/day); preset date is not.
        $this->assertTrue($this->profile('anniversary', 'date')->isMultipleSelect());
        $this->assertFalse($this->profile('op_preset_birthday', 'date')->isMultipleSelect());
        // Single-value types are not.
        $this->assertFalse($this->profile('op_preset_sex', 'select')->isMultipleSelect());
        $this->assertFalse($this->profile('bio', 'textarea')->isMultipleSelect());
    }

    private function profile(string $name, string $formType): Profile
    {
        return (new Profile)->forceFill(['name' => $name, 'form_type' => $formType]);
    }
}
