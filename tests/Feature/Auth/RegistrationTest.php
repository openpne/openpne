<?php

namespace Tests\Feature\Auth;

use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_members_can_register(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test Member',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect('/dashboard');

        $member = Member::where('email', 'test@example.com')->first();
        $this->assertNotNull($member);
        $this->assertSame('Test Member', $member->name);
        $this->assertTrue(Hash::check('password', $member->password));
    }

    public function test_registration_requires_unique_email(): void
    {
        Member::factory()->create(['email' => 'existing@example.com']);

        $response = $this->from('/register')->post('/register', [
            'name' => 'Duplicate',
            'email' => 'existing@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertRedirect('/register');
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_registration_requires_password_confirmation(): void
    {
        $response = $this->from('/register')->post('/register', [
            'name' => 'No Confirm',
            'email' => 'noconfirm@example.com',
            'password' => 'password',
            'password_confirmation' => 'different',
        ]);

        $response->assertRedirect('/register');
        $response->assertSessionHasErrors('password');
        $this->assertGuest();
    }
}
