<?php

namespace Tests\Unit\Profile;

use App\Models\Profile;
use PHPUnit\Framework\TestCase;

class ProfileTest extends TestCase
{
    public function test_is_preset_detects_op_preset_prefix(): void
    {
        $this->assertTrue($this->profile('op_preset_sex', 'select')->isPreset());
        $this->assertFalse($this->profile('custom_field', 'select')->isPreset());
    }

    public function test_is_multiple_select_is_checkbox_or_custom_date(): void
    {
        $this->assertTrue($this->profile('hobbies', 'checkbox')->isMultipleSelect());
        $this->assertTrue($this->profile('anniversary', 'date')->isMultipleSelect());
        $this->assertFalse($this->profile('op_preset_birthday', 'date')->isMultipleSelect());
        $this->assertFalse($this->profile('op_preset_sex', 'select')->isMultipleSelect());
        $this->assertFalse($this->profile('bio', 'textarea')->isMultipleSelect());
    }

    private function profile(string $name, string $formType): Profile
    {
        return (new Profile)->forceFill(['name' => $name, 'form_type' => $formType]);
    }
}
