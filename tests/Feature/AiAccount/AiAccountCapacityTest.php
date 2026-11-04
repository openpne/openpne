<?php

declare(strict_types=1);

namespace Tests\Feature\AiAccount;

use App\Features\AiAccount\Actions\CreateAiAccount;
use App\Features\AiAccount\AiAccountSettings;
use App\Features\AiAccount\Exceptions\AiAccountActionException;
use App\Features\AiAccount\Exceptions\AiAccountActionFailure;
use App\Features\AiAccount\Serializers\AiAccountSerializer;
use App\Models\Member;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** The site-wide cap the host sets, as distinct from the per-member limit an operator sets. */
class AiAccountCapacityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Member::factory()->create(['id' => 1]);
        $this->setSnsSetting(SnsSettingKey::AiAccountsEnabled, true);
        $this->setSnsSetting(SnsSettingKey::AiAccountCapacity, 1);
    }

    public function test_creation_stops_at_the_site_cap_whoever_owns_the_rest(): void
    {
        $first = Member::factory()->create();
        $second = Member::factory()->create();

        app(CreateAiAccount::class)($first, 'One');

        try {
            app(CreateAiAccount::class)($second, 'Two');
            $this->fail('the site cap did not refuse');
        } catch (AiAccountActionException $e) {
            $this->assertSame(AiAccountActionFailure::SiteCapacityReached, $e->reason);
        }

        $this->assertSame(1, Member::query()->whereNotNull('owner_member_id')->count());
    }

    public function test_the_per_member_limit_is_answered_before_the_site_cap(): void
    {
        $owner = Member::factory()->create();
        Member::factory()->aiAccount($owner)->count(3)->create();
        $this->setSnsSetting(SnsSettingKey::AiAccountCapacity, 1);

        try {
            app(CreateAiAccount::class)($owner, 'Four');
            $this->fail('the limit did not refuse');
        } catch (AiAccountActionException $e) {
            $this->assertSame(AiAccountActionFailure::LimitReached, $e->reason);
        }
    }

    public function test_every_create_affordance_reads_the_one_answer(): void
    {
        $owner = Member::factory()->create();
        Member::factory()->aiAccount()->create(); // someone else's, filling the site

        $settings = app(AiAccountSettings::class);
        $this->assertTrue($settings->capacityReached());
        $this->assertFalse($settings->canCreate(0));

        $list = AiAccountSerializer::list($owner, $settings);
        $this->assertFalse($list['canCreate']);
        $this->assertTrue($list['siteFull']);

        config()->set('openpne.surface_mode', 'modern_default');
        $this->actingAs($owner)->get('/member/config/ai')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canCreate', false)->where('siteFull', true));

        $this->actingAs($owner)->post('/member/config/ai', ['name' => 'Late'])
            ->assertRedirect()
            ->assertSessionHas('error', __('This site has as many AI accounts as it can hold.'));
        $this->assertDatabaseMissing('members', ['name' => 'Late']);
    }

    public function test_the_classic_category_says_the_site_is_full(): void
    {
        $owner = Member::factory()->create();
        Member::factory()->aiAccount()->create();

        $this->actingAs($owner)->get('/member/config?category=ai')
            ->assertOk()
            ->assertSee('id="member_config_ai_site_full"', false)
            ->assertDontSee('id="member_config_ai_create"', false);
    }

    public function test_the_widget_and_the_command_read_the_same_count(): void
    {
        Member::factory()->aiAccount()->create();

        $this->artisan('openpne:capacity')
            ->expectsOutputToContain('AI account capacity: 1 (1 in use)')
            ->assertSuccessful();
    }
}
