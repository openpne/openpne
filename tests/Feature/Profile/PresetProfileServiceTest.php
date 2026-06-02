<?php

namespace Tests\Feature\Profile;

use App\Models\Profile;
use App\Services\PresetProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PresetProfileServiceTest extends TestCase
{
    use RefreshDatabase;

    private PresetProfileService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PresetProfileService::class);
    }

    public function test_normalizes_invalid_default_public_flag_to_sns(): void
    {
        $this->assertSame(1, PresetProfileService::normalizeDefaultPublicFlag(0));
        $this->assertSame(1, PresetProfileService::normalizeDefaultPublicFlag(null));
        $this->assertSame(1, PresetProfileService::normalizeDefaultPublicFlag(9));
        $this->assertSame(3, PresetProfileService::normalizeDefaultPublicFlag(3));
    }

    public function test_uses_value_column_only_for_preset_choice_fields(): void
    {
        $this->assertTrue($this->service->usesValueColumnForChoice($this->profile('op_preset_sex', 'select')));
        $this->assertFalse($this->service->usesValueColumnForChoice($this->profile('custom_sel', 'select')));
        $this->assertFalse($this->service->usesValueColumnForChoice($this->profile('op_preset_birthday', 'date')));
    }

    public function test_choices_for_preset_select_are_localised(): void
    {
        $choices = $this->service->choicesFor($this->profile('op_preset_sex', 'select'), 'ja');

        $this->assertSame([
            ['id' => 'F', 'caption' => '女性'],
            ['id' => 'M', 'caption' => '男性'],
        ], $choices);

        $en = $this->service->choicesFor($this->profile('op_preset_sex', 'select'), 'en');
        $this->assertSame('Male', $en[1]['caption']);
    }

    public function test_region_variants_collapse_to_the_shared_name(): void
    {
        $this->assertSame(['name' => 'op_preset_region', 'value_type' => 'JP'], $this->service->nameForKey('region_JP'));
        $this->assertSame(['name' => 'op_preset_sex', 'value_type' => 'string'], $this->service->nameForKey('sex'));
    }

    public function test_unregistered_options_hides_taken_and_region_variants(): void
    {
        $all = $this->service->unregisteredOptions();
        $this->assertArrayHasKey('sex', $all);
        $this->assertArrayHasKey('region_JP', $all);

        Profile::factory()->create(['name' => 'op_preset_sex']);
        Profile::factory()->create(['name' => 'op_preset_region', 'form_type' => 'region_select']);

        $after = $this->service->unregisteredOptions();
        $this->assertArrayNotHasKey('sex', $after);          // already registered
        $this->assertArrayNotHasKey('region', $after);       // region name taken
        $this->assertArrayNotHasKey('region_JP', $after);    // shares the region name
    }

    private function profile(string $name, string $formType): Profile
    {
        return (new Profile)->forceFill(['name' => $name, 'form_type' => $formType, 'value_type' => 'string']);
    }
}
