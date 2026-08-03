<?php

namespace Tests\Feature\Http;

use App\Features\Member\Actions\SetAvatar;
use App\Files\FileUploader;
use App\Models\File;
use App\Models\Member;
use App\Support\AvatarColor;
use App\Support\Feature;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
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

    public function test_shared_props_carry_the_configured_brand_color_and_logo(): void
    {
        $this->setSnsSetting(SnsSettingKey::BrandColor, '#0088aa');
        $logo = app(FileUploader::class)->store(
            UploadedFile::fake()->image('logo.png', 64, 64),
            explicitVisibility: File::VISIBILITY_PUBLIC,
        );
        $this->setSnsSetting(SnsSettingKey::BrandLogoFile, $logo->name);

        $this->actingAs(Member::factory()->create())
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('snsLogo.color', '#0088aa')
                ->where('snsLogo.url', route('file.public', ['file' => $logo->name])));
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

    /**
     * The gate answers a guest auth-first so toggle state is unobservable (EnsureFeatureEnabled);
     * the shared prop must not disclose it either. A guest gets the same all-false map whatever
     * the rows say.
     */
    public function test_a_guest_learns_no_toggle_state_from_the_shared_props(): void
    {
        // The phpunit baseline is classic_default; the login page is Inertia only on Modern.
        config()->set('openpne.surface_mode', 'modern_only');
        $allFalse = array_fill_keys(array_column(Feature::cases(), 'value'), false);

        $this->get('/login')
            ->assertInertia(fn ($page) => $page->where('enabledFeatures', $allFalse));

        $this->setSnsSetting(Feature::Message->settingKey(), false);
        $this->freshRequestState();

        $this->get('/login')
            ->assertInertia(fn ($page) => $page->where('enabledFeatures', $allFalse));
    }

    /**
     * The unread-row count reaches the client on every page: the bottom bar's notifications badge and
     * the dashboard's notices row read it from the shared prop, not from a page payload.
     */
    public function test_shared_props_carry_the_unread_notification_count(): void
    {
        $viewer = Member::factory()->create();
        $viewer->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'test',
            'data' => ['kind' => 'friend_requested'],
        ]);

        $this->actingAs($viewer)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('unread.notifications', 1));
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
