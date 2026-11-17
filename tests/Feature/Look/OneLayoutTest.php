<?php

declare(strict_types=1);

namespace Tests\Feature\Look;

use App\Models\Group;
use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class OneLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['openpne.surface_mode' => 'modern_default']);
    }

    /** Rows a site may still hold from when a layout could be chosen. */
    private function storeOldChoices(Member $member): void
    {
        DB::table('sns_settings')->insert([
            ['key' => 'default_look', 'value' => 'standard'],
            ['key' => 'selectable_looks', 'value' => 'standard,unified,tabbed'],
        ]);
        DB::table('member_preferences')->insert(['member_id' => $member->getKey(), 'key' => 'preferred_look', 'value' => 'standard']);
        $this->freshRequestState();
    }

    public function test_a_member_gets_the_tabbed_layout_whatever_a_site_or_a_member_once_chose(): void
    {
        $viewer = Member::factory()->create();
        $owner = Member::factory()->create();
        $group = Group::factory()->create();
        $this->storeOldChoices($viewer);

        $this->actingAs($viewer)->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('look', 'tabbed'));
        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page->component('unified/member')->where('look', 'tabbed'));
        $this->actingAs($viewer)->get("/groups/{$group->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page->component('unified/group')->where('look', 'tabbed'));
    }

    public function test_a_signed_out_visitor_gets_the_standard_frame(): void
    {
        $owner = Member::factory()->create(['profile_visibility' => Visibility::Open]);

        $this->get("/member/{$owner->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page->component('member/show')->where('look', 'standard'));
    }

    public function test_the_settings_page_offers_no_layout(): void
    {
        $viewer = Member::factory()->create();
        $this->storeOldChoices($viewer);

        $this->actingAs($viewer)->get('/member/config')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('form')->missing('form.look'));
    }

    public function test_the_picker_is_gone(): void
    {
        $viewer = Member::factory()->create();

        $this->actingAs($viewer)->get('/member/config/look')->assertNotFound();
        $this->assertContains($this->actingAs($viewer)->post('/member/config/look', ['choice' => 'standard'])->status(), [404, 405]);
        $this->assertSame(0, DB::table('member_preferences')->where('key', 'preferred_look')->count());
    }

    public function test_a_page_response_carries_no_right_rail(): void
    {
        $viewer = Member::factory()->create();

        $this->actingAs($viewer)->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page->missing('rightRail'));
    }
}
