<?php

namespace Tests\Feature\Http;

use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The asymmetry between the two shells is deliberate and easy to "tidy" away, so it is pinned
 * here: Modern declares a viewport (app.blade.php carries the viewport-fit reasoning), Classic
 * declares none, like the OpenPNE 3 PC layout.
 */
class ViewportMetaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_modern_shell_opts_into_viewport_fit_cover(): void
    {
        $this->actingAs(Member::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('content="width=device-width, initial-scale=1, viewport-fit=cover"', false);
    }

    public function test_the_classic_shell_emits_no_viewport_meta(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('id="page_member_login"', false)
            ->assertDontSee('name="viewport"', false);
    }
}
