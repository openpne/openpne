<?php

namespace Tests\Feature\Member\Modern;

use App\Features\Member\Actions\SetAvatar;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AvatarRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/m/member/avatar')->assertRedirect('/login');
        $this->post('/m/member/avatar')->assertRedirect('/login');
        $this->delete('/m/member/avatar')->assertRedirect('/login');
    }

    public function test_modern_edit_renders_inertia_with_null_avatar_when_unset(): void
    {
        $this->actingAs(Member::factory()->create())
            ->get('/m/member/avatar')
            ->assertInertia(fn ($page) => $page->component('member/avatar')->where('avatar', null));
    }

    public function test_modern_edit_renders_the_avatar_image_shape_when_set(): void
    {
        $member = Member::factory()->create();
        app(SetAvatar::class)($member, UploadedFile::fake()->image('me.png', 100, 100));

        $this->actingAs($member)
            ->get('/m/member/avatar')
            ->assertInertia(fn ($page) => $page
                ->component('member/avatar')
                ->has('avatar.url')
                ->has('avatar.thumbnailUrl')
            );
    }

    public function test_modern_upload_stores_the_avatar_and_redirects_to_the_modern_editor(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)
            ->post('/m/member/avatar', ['image' => UploadedFile::fake()->image('me.png', 20, 20)])
            ->assertRedirect(route('member.modern.avatar.edit'));

        $this->assertNotNull($member->fresh()->avatar);
    }

    public function test_modern_remove_clears_the_avatar_and_redirects_to_the_modern_editor(): void
    {
        $member = Member::factory()->create();
        app(SetAvatar::class)($member, UploadedFile::fake()->image('me.png', 20, 20));

        $this->actingAs($member)
            ->delete('/m/member/avatar')
            ->assertRedirect(route('member.modern.avatar.edit'));

        $this->assertSame(0, $member->fresh()->avatar()->count());
    }

    public function test_canonical_editor_renders_inertia_under_modern_only(): void
    {
        config()->set('openpne.tenant_mode', 'modern_only');

        $this->actingAs(Member::factory()->create())
            ->get(route('member.avatar.edit'))
            ->assertInertia(fn ($page) => $page->component('member/avatar')->where('avatar', null));
    }
}
