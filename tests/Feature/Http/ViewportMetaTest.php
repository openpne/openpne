<?php

namespace Tests\Feature\Http;

use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The viewport-fit asymmetry between the two shells is deliberate and easy to "tidy" away, so it is
 * pinned here; app.blade.php carries the reasoning.
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

    public function test_the_classic_shell_keeps_the_windowed_viewport(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('name="viewport"', false)
            ->assertDontSee('viewport-fit', false);
    }
}
