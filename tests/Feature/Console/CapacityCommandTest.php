<?php

namespace Tests\Feature\Console;

use App\Features\AiAccount\AiAccountSettings;
use App\Features\Auth\MemberCapacity;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CapacityCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_shows_both_caps_as_unlimited_on_a_fresh_site(): void
    {
        $this->artisan('openpne:capacity')
            ->expectsOutputToContain('Member capacity: unlimited (0 members, 0 invited)')
            ->expectsOutputToContain('AI account capacity: unlimited (0 in use)')
            ->assertSuccessful();
    }

    public function test_it_sets_and_clears_each_cap(): void
    {
        $this->artisan('openpne:capacity', ['which' => 'member', 'value' => '50'])
            ->expectsOutputToContain('Member capacity set to 50.')
            ->assertSuccessful();
        $this->assertSame(50, app(MemberCapacity::class)->limit());

        $this->artisan('openpne:capacity', ['which' => 'ai', 'value' => '0'])->assertSuccessful();
        $this->assertSame(0, app(AiAccountSettings::class)->capacity());

        $this->artisan('openpne:capacity', ['which' => 'member', 'value' => 'unlimited'])
            ->expectsOutputToContain('Member capacity set to unlimited.')
            ->assertSuccessful();
        $this->assertNull(app(MemberCapacity::class)->limit());
        $this->assertDatabaseHas('sns_settings', ['key' => SnsSettingKey::MemberCapacity->value, 'value' => '']);
    }

    public function test_it_refuses_an_unknown_cap_or_a_bad_value(): void
    {
        $this->artisan('openpne:capacity', ['which' => 'members', 'value' => '5'])->assertFailed();
        $this->artisan('openpne:capacity', ['which' => 'member', 'value' => '-5'])->assertFailed();
        $this->artisan('openpne:capacity', ['which' => 'member', 'value' => 'ten'])->assertFailed();
        $this->artisan('openpne:capacity', ['which' => 'member'])->assertFailed();

        $this->assertDatabaseMissing('sns_settings', ['key' => SnsSettingKey::MemberCapacity->value]);
    }
}
