<?php

namespace Tests\Feature\Block\Modern;

use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class BlockRoutesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'features.block.modern_status' => 'native',
            'openpne.tenant_mode' => 'mixed',
            'openpne.tenant_default_surface' => 'classic',
        ]);
    }

    public function test_modern_list_returns_inertia_component_with_serialized_blocks(): void
    {
        $member = Member::factory()->create();
        $blocked = Member::factory()->create(['name' => 'Mallory']);
        $this->block($member, $blocked);

        $response = $this->actingAs($member)->get('/m/block/list');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('block/list')
            ->where('blocks.meta.total', 1)
            ->where('blocks.data.0.name', 'Mallory')
        );
    }

    public function test_modern_add_show_returns_inertia_component_with_target(): void
    {
        $member = Member::factory()->create();
        $target = Member::factory()->create(['name' => 'Trent']);

        $response = $this->actingAs($member)->get('/m/block/add?id='.$target->getKey());

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('block/add')
            ->where('target.id', $target->getKey())
            ->where('target.name', 'Trent')
        );
    }

    public function test_modern_add_show_returns_404_for_self(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/m/block/add?id='.$member->getKey())->assertNotFound();
    }

    public function test_modern_add_show_returns_404_when_already_blocked(): void
    {
        $member = Member::factory()->create();
        $target = Member::factory()->create();
        $this->block($member, $target);

        $this->actingAs($member)->get('/m/block/add?id='.$target->getKey())->assertNotFound();
    }

    public function test_modern_remove_show_returns_inertia_component(): void
    {
        $member = Member::factory()->create();
        $target = Member::factory()->create(['name' => 'Oscar']);
        $this->block($member, $target);

        $response = $this->actingAs($member)->get('/m/block/remove/'.$target->getKey());

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('block/remove')
            ->where('target.id', $target->getKey())
        );
    }

    public function test_modern_remove_show_returns_404_when_not_blocked(): void
    {
        $member = Member::factory()->create();
        $target = Member::factory()->create();

        $this->actingAs($member)->get('/m/block/remove/'.$target->getKey())->assertNotFound();
    }

    public function test_modern_add_post_redirects_to_modern_list(): void
    {
        $member = Member::factory()->create();
        $target = Member::factory()->create();

        $response = $this->actingAs($member)->post('/m/block/add', ['target_id' => $target->getKey()]);

        $response->assertRedirect(route('block.modern.list'));
        $response->assertSessionHas('status');
        $this->assertDatabaseHas('member_blocks', [
            'blocker_id' => $member->getKey(),
            'blocked_id' => $target->getKey(),
        ]);
    }

    public function test_modern_add_post_redirects_to_modern_list_on_error(): void
    {
        $member = Member::factory()->create();
        $target = Member::factory()->create();
        $this->block($member, $target);

        $response = $this->actingAs($member)->post('/m/block/add', ['target_id' => $target->getKey()]);

        $response->assertRedirect(route('block.modern.list'));
        $response->assertSessionHas('error');
    }

    public function test_modern_add_post_self_redirects_to_modern_list_with_error(): void
    {
        $member = Member::factory()->create();

        $response = $this->actingAs($member)->post('/m/block/add', ['target_id' => $member->getKey()]);

        $response->assertRedirect(route('block.modern.list'));
        $response->assertSessionHas('error');
        $this->assertDatabaseCount('member_blocks', 0);
    }

    public function test_modern_remove_post_redirects_to_modern_list(): void
    {
        $member = Member::factory()->create();
        $target = Member::factory()->create();
        $this->block($member, $target);

        $response = $this->actingAs($member)->post('/m/block/remove/'.$target->getKey());

        $response->assertRedirect(route('block.modern.list'));
        $response->assertSessionHas('status');
        $this->assertDatabaseMissing('member_blocks', [
            'blocker_id' => $member->getKey(),
            'blocked_id' => $target->getKey(),
        ]);
    }

    public function test_modern_list_paginates_via_page_query(): void
    {
        $member = Member::factory()->create();
        for ($i = 0; $i < 25; $i++) {
            $this->block($member, Member::factory()->create());
        }

        $response = $this->actingAs($member)->get('/m/block/list?page=2');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('block/list')
            ->where('blocks.meta.currentPage', 2)
            ->where('blocks.meta.total', 25)
        );
    }

    public function test_canonical_block_list_defaults_to_classic(): void
    {
        $member = Member::factory()->create();

        $response = $this->actingAs($member)->get('/block/list');

        $response->assertOk();
        $response->assertSee('id="page_block_list"', false);
    }

    public function test_canonical_block_list_returns_modern_when_session_override_is_modern(): void
    {
        $member = Member::factory()->create();

        $response = $this->actingAs($member)
            ->withSession(['migration_ui_override' => 'modern'])
            ->get('/block/list');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('block/list'));
    }

    public function test_canonical_block_list_returns_modern_when_tenant_default_is_modern(): void
    {
        config(['openpne.tenant_default_surface' => 'modern']);
        $member = Member::factory()->create();

        $response = $this->actingAs($member)->get('/block/list');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('block/list'));
    }

    public function test_session_override_is_ignored_when_tenant_mode_is_modern_only(): void
    {
        config(['openpne.tenant_mode' => 'modern_only']);
        $member = Member::factory()->create();

        $response = $this->actingAs($member)
            ->withSession(['migration_ui_override' => 'classic'])
            ->get('/block/list');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('block/list'));
    }

    public function test_modern_route_falls_back_to_classic_when_feature_status_is_not_native(): void
    {
        config(['features.block.modern_status' => 'fallback']);
        $member = Member::factory()->create();

        $response = $this->actingAs($member)->get('/m/block/list');

        $response->assertOk();
        $response->assertSee('id="page_block_list"', false);
    }

    private function block(Member $blocker, Member $blocked): void
    {
        DB::table('member_blocks')->insert([
            'blocker_id' => $blocker->getKey(),
            'blocked_id' => $blocked->getKey(),
        ]);
    }
}
