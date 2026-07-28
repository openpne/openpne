<?php

declare(strict_types=1);

namespace Tests\Feature\Classic;

use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The Classic shell's two flash slots, as OpenPNE 3's `alertBox` parts, and the notice OpenPNE 3
 * sent a guest to the login form with.
 */
class ClassicFlashMessageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setSnsSetting(SnsSettingKey::SurfaceMode, 'classic_default');
    }

    public function test_a_guest_reaches_login_carrying_the_openpne3_notice(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');

        $this->followingRedirects()->get('/dashboard')
            ->assertOk()
            // The OpenPNE 3 alertBox parts markup, ids included: skins and customer CSS target them.
            ->assertSee('<div class="dparts alertBox" id="flashNotice">', false)
            ->assertSee('<div class="parts">', false)
            ->assertSee('images/icon_alert.gif', false)
            ->assertSee('Please login to visit this page');
    }

    public function test_reaching_the_login_form_directly_shows_no_notice(): void
    {
        // OpenPNE 3 excluded its own login/homepage actions from the notice; here the callback is
        // simply never reached, because nothing redirected the guest.
        $this->get('/login')
            ->assertOk()
            ->assertDontSee('flashNotice')
            ->assertDontSee('Please login to visit this page');
    }

    public function test_an_error_flash_renders_as_the_alert_box_with_its_own_id(): void
    {
        Route::middleware('web')->get('/__flash_probe', fn () => redirect('/login')->with('error', 'Boom'));

        $this->followingRedirects()->get('/__flash_probe')
            ->assertOk()
            ->assertSee('<div class="dparts alertBox" id="flashError">', false)
            ->assertSee('images/icon_alert.gif', false)
            ->assertSee('Boom');
    }
}
