<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LoginSessionFixationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_session_id_planted_before_a_members_password_login_holds_no_login_after_it(): void
    {
        config(['session.driver' => 'database']);
        $cookie = config('session.cookie');
        $member = Member::factory()->create();

        $planted = $this->get('/login')->assertOk()->getCookie($cookie)->getValue();
        $this->assertTrue(DB::table('sessions')->where('id', $planted)->exists());

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

    public function test_a_members_failed_login_signs_in_no_session_it_touched(): void
    {
        config(['session.driver' => 'database']);
        $cookie = config('session.cookie');
        $member = Member::factory()->create();

        $planted = $this->get('/login')->assertOk()->getCookie($cookie)->getValue();

        $this->freshRequestState();
        $answered = $this->withCookie($cookie, $planted)
            ->post('/login', ['email' => $member->email, 'password' => 'wrong-password'])
            ->assertRedirect()
            ->getCookie($cookie)->getValue();

        foreach (array_unique([$planted, $answered]) as $id) {
            $this->freshRequestState();
            $this->withCookie($cookie, $id)->get('/dashboard')->assertRedirect('/login');
        }
    }
}
