<?php

declare(strict_types=1);

namespace Tests\Feature\Look;

use App\Models\Member;
use App\Services\SnsSettingService;
use App\Support\Look;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * What the look tells the shell rather than the page: the one shared prop the chrome reads, who
 * never gets it, and what an unreadable stored value resolves to.
 */
class LookSharedPropTest extends TestCase
{
    use RefreshDatabase;

    private function unifiedOn(): void
    {
        $this->setSnsSetting(SnsSettingKey::DefaultLook, Look::Unified);
        $this->freshRequestState();
    }

    /**
     * The look reaches the shell as well as the page: the mobile bars read one shared prop rather
     * than resolving it again per bar. Switching the site adds no key to the response — what it must
     * not change is what the chrome renders while the look is standard.
     */
    public function test_the_shell_learns_the_look_from_a_shared_prop(): void
    {
        $viewer = Member::factory()->create();

        $this->actingAs($viewer)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('look', 'standard'));

        $this->unifiedOn();

        $this->actingAs($viewer)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('look', 'unified'));
    }

    public function test_a_guest_is_never_given_the_unified_chrome(): void
    {
        // A look is a member's way around their own pages, and a signed-out visitor reaches none
        // of them — so the site default does not answer for a guest.
        config()->set('openpne.surface_mode', 'modern_only');
        $this->unifiedOn();

        $this->get('/login')->assertInertia(fn ($page) => $page->where('look', 'standard'));
    }

    public function test_a_stored_value_no_look_answers_to_reads_as_standard(): void
    {
        // No row at all: the shipped layout, without an operator having said anything.
        $this->assertSame(Look::Standard, app(SnsSettingService::class)->get(SnsSettingKey::DefaultLook));

        foreach (['0', '', '2', 'Unified'] as $stored) {
            DB::table('sns_settings')->updateOrInsert(
                ['key' => SnsSettingKey::DefaultLook->value],
                ['value' => $stored],
            );
            app(SnsSettingService::class)->clearCache();

            $this->assertSame(
                Look::Standard,
                app(SnsSettingService::class)->get(SnsSettingKey::DefaultLook),
                "stored value '{$stored}' resolved to something other than the shipped layout",
            );
        }

        DB::table('sns_settings')->updateOrInsert(
            ['key' => SnsSettingKey::DefaultLook->value],
            ['value' => 'unified'],
        );
        app(SnsSettingService::class)->clearCache();

        $this->assertSame(Look::Unified, app(SnsSettingService::class)->get(SnsSettingKey::DefaultLook));
    }
}
