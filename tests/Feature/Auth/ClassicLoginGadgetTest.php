<?php

namespace Tests\Feature\Auth;

use App\Models\Gadget;
use App\Models\GadgetConfig;
use App\Services\GadgetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassicLoginGadgetTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, string> $config */
    private function makeGadget(string $zone, string $name, array $config = []): Gadget
    {
        $gadget = Gadget::create(['context' => 'login', 'zone' => $zone, 'name' => $name, 'sort_order' => 0]);
        foreach ($config as $key => $value) {
            GadgetConfig::create(['gadget_id' => $gadget->id, 'name' => $key, 'value' => $value]);
        }
        app(GadgetService::class)->clearCache();

        return $gadget;
    }

    public function test_login_form_gadget_renders_the_reused_form(): void
    {
        $this->makeGadget('contents', 'loginForm');

        // The gadget reuses the shared partial: same box id, fields, and (baseline) register link.
        $this->get('/login')
            ->assertOk()
            ->assertSee('id="loginForm"', false)
            ->assertSee('name="email"', false)
            ->assertSee('name="password"', false)
            ->assertSee(route('register'), false); // registrationOpen flows through LoginFormData
    }

    public function test_free_area_gadget_is_public_on_the_login_page(): void
    {
        $this->makeGadget('top', 'freeArea', ['value' => '<p>Welcome notice</p>']);
        $this->makeGadget('contents', 'loginForm');

        $this->get('/login') // guest
            ->assertOk()
            ->assertSee('<p>Welcome notice</p>', false)
            ->assertSee('id="loginForm"', false);
    }

    public function test_fixed_login_form_is_the_empty_state(): void
    {
        // No login gadgets configured: the fixed single-column form still renders.
        $this->get('/login')
            ->assertOk()
            ->assertSee('id="loginForm"', false)
            ->assertSee('name="email"', false);
    }
}
