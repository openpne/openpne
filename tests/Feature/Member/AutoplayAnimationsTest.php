<?php

namespace Tests\Feature\Member;

use App\Files\GdImageProcessor;
use App\Files\ImageProcessor;
use App\Models\Member;
use App\Support\Autoplay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\ImageManager;
use Tests\Support\FrameKeepingProcessor;
use Tests\TestCase;

class AutoplayAnimationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_switching_it_off_writes_the_preference_without_a_modern_flash(): void
    {
        config(['openpne.surface_mode' => 'modern_default']);
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/member/config/autoplay', ['autoplay_animations' => 'off'])
            ->assertRedirect(route('member.config'))
            ->assertSessionMissing('status');

        $this->assertDatabaseHas('member_preferences', ['member_id' => $member->id, 'key' => 'autoplay_animations', 'value' => 'off']);
    }

    public function test_switching_it_back_on_stores_an_explicit_row(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/member/config/autoplay', ['autoplay_animations' => 'off']);
        $this->actingAs($member)->post('/member/config/autoplay', ['autoplay_animations' => 'on']);

        $this->assertSame(1, $member->preferences()->where('key', 'autoplay_animations')->count());
        $this->assertDatabaseHas('member_preferences', ['member_id' => $member->id, 'key' => 'autoplay_animations', 'value' => 'on']);
    }

    public function test_a_crafted_value_is_rejected_and_nothing_is_written(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->from('/member/config')->post('/member/config/autoplay', ['autoplay_animations' => 'sometimes'])
            ->assertRedirect('/member/config')
            ->assertSessionHasErrors('autoplay_animations');

        $this->assertDatabaseMissing('member_preferences', ['member_id' => $member->id, 'key' => 'autoplay_animations']);
    }

    public function test_a_guest_cannot_post_it(): void
    {
        $this->post('/member/config/autoplay', ['autoplay_animations' => 'off'])->assertRedirect(route('login'));
    }

    public function test_the_config_page_offers_the_switch_only_where_the_processor_keeps_frames(): void
    {
        config(['openpne.surface_mode' => 'modern_default']);
        $member = Member::factory()->create();

        // Under GD nothing animates, so there is nothing for the switch to stop.
        $this->app->instance(ImageProcessor::class, new GdImageProcessor(new ImageManager(GdDriver::class, decodeAnimation: false)));
        $this->actingAs($member)->get('/member/config')
            ->assertInertia(fn (Assert $page) => $page->missing('form.autoplayAnimations'));

        $this->app->instance(ImageProcessor::class, new FrameKeepingProcessor);
        $member->setAutoplayAnimations(Autoplay::Off);

        $this->actingAs($member)->get('/member/config')
            ->assertInertia(fn (Assert $page) => $page
                ->where('form.autoplayAnimations.value', 'off')
                ->where('form.autoplayAnimations.options', fn ($options) => count($options) === 2)
            );
    }

    public function test_the_shell_learns_the_switch_from_a_shared_prop(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('autoplayAnimations', true));

        $member->setAutoplayAnimations(Autoplay::Off);

        $this->actingAs($member)->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('autoplayAnimations', false));
    }

    public function test_a_guest_is_never_started_on_motion(): void
    {
        // The switch is behind login, so a reader who cannot reach it gets stills whatever the default.
        config()->set('openpne.surface_mode', 'modern_only');

        $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('autoplayAnimations', false));
    }
}
