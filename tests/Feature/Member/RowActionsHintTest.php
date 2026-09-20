<?php

namespace Tests\Feature\Member;

use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class RowActionsHintTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_is_redirected_to_login_and_writes_nothing(): void
    {
        $this->post('/member/config/row-actions-hint')->assertRedirect('/login');

        $this->assertDatabaseCount('member_preferences', 0);
    }

    public function test_dismissing_answers_204_and_writes_the_viewers_row_only(): void
    {
        $member = Member::factory()->create();
        $other = Member::factory()->create();

        // A member id in the body names nobody: the viewer's own hint is the only one this can touch.
        $this->actingAs($member)->postJson('/member/config/row-actions-hint', ['member_id' => $other->id])->assertNoContent();

        $this->assertDatabaseHas('member_preferences', ['member_id' => $member->id, 'key' => 'row_actions_hint', 'value' => 'dismissed']);
        $this->assertDatabaseMissing('member_preferences', ['member_id' => $other->id, 'key' => 'row_actions_hint']);
    }

    public function test_the_page_carries_the_hint_state_and_a_second_dismissal_keeps_one_row(): void
    {
        config(['openpne.surface_mode' => 'modern_default']);
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page->where('rowActionsHint', 'shown'));

        $this->actingAs($member)->postJson('/member/config/row-actions-hint')->assertNoContent();
        $this->actingAs($member)->postJson('/member/config/row-actions-hint')->assertNoContent();

        $this->assertSame(1, $member->preferences()->where('key', 'row_actions_hint')->count());
        $this->actingAs($member)->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page->where('rowActionsHint', 'dismissed'));
    }
}
