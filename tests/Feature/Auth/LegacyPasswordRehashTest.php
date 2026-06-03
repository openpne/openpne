<?php

namespace Tests\Feature\Auth;

use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A member upgraded from OpenPNE 3 carries a bare MD5 password; logging in must verify it
 * and transparently rehash to bcrypt, while bcrypt members keep working unchanged.
 */
class LegacyPasswordRehashTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_md5_member_authenticates_and_password_is_upgraded_to_bcrypt(): void
    {
        $member = $this->memberWithRawPassword('legacy@example.com', md5('secret'));

        $response = $this->post('/login', ['email' => 'legacy@example.com', 'password' => 'secret']);

        $this->assertAuthenticatedAs($member);
        $response->assertRedirect('/');

        $stored = $this->storedPassword($member);
        $this->assertTrue(Str::startsWith($stored, '$2y$'), 'password should be bcrypt after login');
        $this->assertTrue(Hash::check('secret', $stored));

        // The upgraded hash is now verified through the standard bcrypt path on the next login.
        $this->app['auth']->guard()->logout();
        $this->post('/login', ['email' => 'legacy@example.com', 'password' => 'secret']);
        $this->assertAuthenticatedAs($member->fresh());
    }

    public function test_wrong_password_against_a_legacy_hash_is_rejected_and_left_untouched(): void
    {
        $member = $this->memberWithRawPassword('legacy@example.com', md5('secret'));

        $this->post('/login', ['email' => 'legacy@example.com', 'password' => 'wrong']);

        $this->assertGuest();
        $this->assertSame(md5('secret'), $this->storedPassword($member));
    }

    public function test_bcrypt_member_still_authenticates(): void
    {
        $member = Member::factory()->create(); // factory password is bcrypt('password')

        $this->post('/login', ['email' => $member->email, 'password' => 'password']);

        $this->assertAuthenticatedAs($member);
    }

    public function test_bcrypt_member_is_rehashed_when_the_work_factor_changes(): void
    {
        // A bcrypt hash above the configured test cost (4) must be re-hashed on login down
        // to the configured cost, and the stored value must be a real hash of the password
        // (not the plaintext leaking through the cast).
        $member = $this->memberWithRawPassword('rehash@example.com', password_hash('secret', PASSWORD_BCRYPT, ['cost' => 6]));

        $this->post('/login', ['email' => 'rehash@example.com', 'password' => 'secret']);

        $this->assertAuthenticatedAs($member);
        $stored = $this->storedPassword($member);
        $this->assertTrue(Hash::check('secret', $stored));
        $this->assertFalse(Hash::needsRehash($stored));
    }

    public function test_member_without_a_password_cannot_authenticate(): void
    {
        $this->memberWithRawPassword('nologin@example.com', null);

        $this->post('/login', ['email' => 'nologin@example.com', 'password' => 'whatever']);

        $this->assertGuest();
    }

    /** Persist a password verbatim, bypassing the model's `hashed` cast. */
    private function memberWithRawPassword(string $email, ?string $rawPassword): Member
    {
        $member = Member::factory()->create(['email' => $email]);
        DB::table('members')->where('id', $member->getKey())->update(['password' => $rawPassword]);

        return $member;
    }

    private function storedPassword(Member $member): ?string
    {
        return DB::table('members')->where('id', $member->getKey())->value('password');
    }
}
