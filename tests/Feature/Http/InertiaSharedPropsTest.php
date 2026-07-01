<?php

namespace Tests\Feature\Http;

use App\Features\Member\Actions\SetAvatar;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class InertiaSharedPropsTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_props_expose_the_sns_logo_seam_and_a_null_avatar(): void
    {
        $this->actingAs(Member::factory()->create())
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('auth.user.imageUrl', null)
                ->where('snsLogo.color', '#2563eb')
                ->where('snsLogo.url', null));
    }

    public function test_shared_props_carry_the_member_avatar_thumbnail(): void
    {
        $member = Member::factory()->create();
        app(SetAvatar::class)($member, UploadedFile::fake()->image('me.png', 100, 100));
        $expected = $member->fresh()->avatar->file->thumbnailUrl(76, 76, square: true);

        $this->actingAs($member)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('auth.user.imageUrl', $expected));
    }
}
