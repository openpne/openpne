<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Filament\Pages\Auth\Login;
use App\Models\AdminUser;
use App\Models\Member;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class LoginSessionFixationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_session_id_planted_before_a_members_login_holds_no_login_after_it(): void
    {
        config(['session.driver' => 'database']);
        $cookie = config('session.cookie');
        $member = Member::factory()->create();

        $planted = $this->get('/login')->assertOk()->getCookie($cookie)->getValue();

        $this->freshRequestState();
        $issued = $this->withCookie($cookie, $planted)
            ->post('/login', ['email' => $member->email, 'password' => 'password'])
            ->assertRedirect('/')
            ->getCookie($cookie)->getValue();

        $this->assertNotSame($planted, $issued);
        $this->assertFalse(DB::table('sessions')->where('id', $planted)->exists());

        $this->freshRequestState();
        $this->withCookie($cookie, $planted)->get('/dashboard')->assertRedirect('/login');
        $this->freshRequestState();
        $this->withCookie($cookie, $issued)->get('/dashboard')->assertOk();
    }

    public function test_a_members_failed_login_keeps_the_guest_out_under_either_id(): void
    {
        config(['session.driver' => 'database']);
        $cookie = config('session.cookie');
        $member = Member::factory()->create();

        $planted = $this->get('/login')->assertOk()->getCookie($cookie)->getValue();

        $this->freshRequestState();
        $this->withCookie($cookie, $planted)
            ->post('/login', ['email' => $member->email, 'password' => 'wrong-password'])
            ->assertRedirect();

        $this->assertSame(0, DB::table('sessions')->whereNotNull('user_id')->count());
    }

    /**
     * Filament's login is a Livewire component whose test harness emits no cookie, so the id is read
     * off the store the component ran against.
     */
    public function test_an_administrators_login_leaves_the_session_id_it_started_under(): void
    {
        Filament::setCurrentPanel('admin');
        $admin = AdminUser::factory()->create(['username' => 'opene']);
        $before = session()->getId();

        Livewire::test(Login::class)
            ->fillForm(['email' => 'opene', 'password' => 'password'])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($admin, 'admin');
        $this->assertNotSame($before, session()->getId());
    }
}
