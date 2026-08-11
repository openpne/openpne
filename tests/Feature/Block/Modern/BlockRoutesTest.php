<?php

namespace Tests\Feature\Block\Modern;

use App\Models\Member;
use App\Models\MemberImage;
use App\Support\Surface;
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
            'openpne.surface_mode' => 'modern_default',
        ]);
    }

    public function test_modern_list_returns_inertia_component_with_serialized_blocks(): void
    {
        $member = Member::factory()->create();
        $blocked = Member::factory()->create(['name' => 'Mallory']);
        $this->block($member, $blocked);

        $response = $this->actingAs($member)->get('/block/list');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('block/list')
            ->where('blocks.meta.total', 1)
            ->where('blocks.data.0.name', 'Mallory')
        );
    }

    public function test_modern_list_serializes_the_blocked_member_avatar_url(): void
    {
        $member = Member::factory()->create();
        $blocked = Member::factory()->create();
        MemberImage::factory()->create(['member_id' => $blocked->getKey()]);
        $this->block($member, $blocked);
        $expected = $blocked->load('avatar.file')->avatar->file->thumbnailUrl(120, 120, square: true);

        $this->actingAs($member)->get('/block/list')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('blocks.data.0.id', $blocked->getKey())
                ->where('blocks.data.0.imageUrl', $expected)
            );
    }

    public function test_modern_add_show_returns_inertia_component_with_target(): void
    {
        $member = Member::factory()->create();
        $target = Member::factory()->create(['name' => 'Trent']);

        $response = $this->actingAs($member)->get('/block/add?id='.$target->getKey());

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

        $this->actingAs($member)->get('/block/add?id='.$member->getKey())->assertNotFound();
    }

    public function test_modern_add_show_returns_404_when_already_blocked(): void
    {
        $member = Member::factory()->create();
        $target = Member::factory()->create();
        $this->block($member, $target);

        $this->actingAs($member)->get('/block/add?id='.$target->getKey())->assertNotFound();
    }

    public function test_modern_remove_show_returns_inertia_component(): void
    {
        $member = Member::factory()->create();
        $target = Member::factory()->create(['name' => 'Oscar']);
        $this->block($member, $target);

        $response = $this->actingAs($member)->get('/block/remove/'.$target->getKey());

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

        $this->actingAs($member)->get('/block/remove/'.$target->getKey())->assertNotFound();
    }

    public function test_modern_add_post_redirects_to_list(): void
    {
        $member = Member::factory()->create();
        $target = Member::factory()->create();

        $response = $this->actingAs($member)->post('/block/add', ['target_id' => $target->getKey()]);

        $response->assertRedirect(route('block.list'));
        $response->assertSessionHas('status');
        $this->assertDatabaseHas('member_blocks', [
            'blocker_id' => $member->getKey(),
            'blocked_id' => $target->getKey(),
        ]);
    }

    public function test_modern_add_post_redirects_to_list_on_error(): void
    {
        $member = Member::factory()->create();
        $target = Member::factory()->create();
        $this->block($member, $target);

        $response = $this->actingAs($member)->post('/block/add', ['target_id' => $target->getKey()]);

        $response->assertRedirect(route('block.list'));
        $response->assertSessionHas('error');
    }

    public function test_modern_add_post_self_redirects_to_list_with_error(): void
    {
        $member = Member::factory()->create();

        $response = $this->actingAs($member)->post('/block/add', ['target_id' => $member->getKey()]);

        $response->assertRedirect(route('block.list'));
        $response->assertSessionHas('error');
        $this->assertDatabaseCount('member_blocks', 0);
    }

    public function test_modern_remove_post_redirects_to_list(): void
    {
        $member = Member::factory()->create();
        $target = Member::factory()->create();
        $this->block($member, $target);

        $response = $this->actingAs($member)->post('/block/remove/'.$target->getKey());

        $response->assertRedirect(route('block.list'));
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

        $response = $this->actingAs($member)->get('/block/list?page=2');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('block/list')
            ->where('blocks.meta.currentPage', 2)
            ->where('blocks.meta.total', 25)
        );
    }

    public function test_canonical_block_list_defaults_to_classic(): void
    {
        config(['openpne.surface_mode' => 'classic_default']);
        $member = Member::factory()->create();

        $response = $this->actingAs($member)->get('/block/list');

        $response->assertOk();
        $response->assertSee('id="page_block_list"', false);
    }

    public function test_canonical_block_list_returns_modern_when_member_prefers_modern(): void
    {
        config(['openpne.surface_mode' => 'classic_default']);
        $member = Member::factory()->create();
        $member->setPreferredSurface(Surface::Modern);

        $response = $this->actingAs($member)->get('/block/list');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('block/list'));
    }

    public function test_canonical_block_list_returns_modern_when_surface_mode_is_modern_default(): void
    {
        config(['openpne.surface_mode' => 'modern_default']);
        $member = Member::factory()->create();

        $response = $this->actingAs($member)->get('/block/list');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('block/list'));
    }

    public function test_classic_preference_is_ignored_when_surface_mode_is_modern_only(): void
    {
        config(['openpne.surface_mode' => 'modern_only']);
        $member = Member::factory()->create();
        $member->setPreferredSurface(Surface::Classic);

        $response = $this->actingAs($member)->get('/block/list');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('block/list'));
    }

    public function test_canonical_route_falls_back_to_classic_when_feature_status_is_not_native(): void
    {
        config(['features.block.modern_status' => 'fallback']);
        $member = Member::factory()->create();

        $response = $this->actingAs($member)->get('/block/list');

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
