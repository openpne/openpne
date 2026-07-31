<?php

namespace Tests\Feature\Http;

use App\Features\Member\Actions\SetAvatar;
use App\Models\Member;
use App\Support\AvatarColor;
use App\Support\Feature;
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
                ->where('auth.user.avatarColor', null)
                ->where('snsLogo.color', '#2563eb')
                ->where('snsLogo.url', null));
    }

    public function test_shared_props_carry_the_chosen_badge_color_as_hex(): void
    {
        $member = Member::factory()->create();
        $member->forceFill(['avatar_color' => AvatarColor::Blue])->save();

        $this->actingAs($member)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('auth.user.avatarColor', '#2563eb'));
    }

    public function test_shared_props_carry_every_feature_unit_on_a_fresh_install(): void
    {
        $this->actingAs(Member::factory()->create())
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('enabledFeatures', array_fill_keys(
                array_column(Feature::cases(), 'value'),
                true,
            )));
    }

    public function test_the_shared_feature_map_resolves_dependencies(): void
    {
        $this->setSnsSetting(Feature::Community->settingKey(), false);

        $this->actingAs(Member::factory()->create())
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('enabledFeatures.community', false)
                // Contained units follow their container, whatever their own rows say.
                ->where('enabledFeatures.communityTopic', false)
                ->where('enabledFeatures.communityEvent', false)
                ->where('enabledFeatures.diary', true));
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
