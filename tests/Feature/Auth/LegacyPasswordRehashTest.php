<?php

namespace Tests\Feature\Auth;

use App\Auth\PasswordScheme;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A member upgraded from OpenPNE 3 carries a wrapped password — bcrypt over the legacy
 * MD5 hex, flagged password_scheme=md5_bcrypt by the upgrade's wrap pass. Logging in
 * must verify it (md5 first) and transparently rehash to a plain bcrypt, clearing the
 * flag; a bare unwrapped MD5 must authenticate nobody, and bcrypt members keep working.
 */
class LegacyPasswordRehashTest extends TestCase
{
    use RefreshDatabase;

    public function test_wrapped_member_authenticates_and_is_upgraded_to_a_plain_bcrypt(): void
    {
        $member = $this->wrappedMember('legacy@example.com', 'secret');

        $response = $this->post('/login', ['email' => 'legacy@example.com', 'password' => 'secret']);

        $this->assertAuthenticatedAs($member);
        $response->assertRedirect('/');

        $stored = $this->storedPassword($member);
        $this->assertTrue(Str::startsWith($stored, '$2y$'), 'password should stay bcrypt after login');
        $this->assertTrue(Hash::check('secret', $stored), 'the wrapper should be gone — a plain bcrypt of the plaintext');
        $this->assertNull($member->fresh()->password_scheme);

        // The upgraded hash is now verified through the standard bcrypt path on the next login.
        $this->app['auth']->guard()->logout();
        $this->post('/login', ['email' => 'legacy@example.com', 'password' => 'secret']);
        $this->assertAuthenticatedAs($member->fresh());
    }

    public function test_wrong_password_against_a_wrapped_hash_is_rejected_and_left_untouched(): void
    {
        $member = $this->wrappedMember('legacy@example.com', 'secret');
        $before = $this->storedPassword($member);

        $this->post('/login', ['email' => 'legacy@example.com', 'password' => 'wrong']);

        $this->assertGuest();
        $this->assertSame($before, $this->storedPassword($member));
        $this->assertSame(PasswordScheme::Md5Bcrypt->value, $member->fresh()->password_scheme);
    }

    public function test_a_bare_unwrapped_md5_no_longer_authenticates(): void
    {
        // The wrap pass converts every imported MD5 before an upgrade completes
        // (verify-upgrade holds the cutover on it), so a bare hash at rest is an
        // unconverted anomaly — it must not be accepted, even with the right password.
        $this->memberWithRawPassword('bare@example.com', md5('secret'));

        $this->post('/login', ['email' => 'bare@example.com', 'password' => 'secret']);

        $this->assertGuest();
    }

    public function test_bcrypt_member_still_authenticates(): void
    {
        $member = Member::factory()->create(); // factory password is bcrypt('password')

        $this->post('/login', ['email' => $member->email, 'password' => 'password']);

        $this->assertAuthenticatedAs($member);
    }

    public function test_bcrypt_member_is_rehashed_when_the_work_factor_changes(): void
    {
        // A bcrypt hash below the effective cost must be re-hashed on login, and the
        // stored value must be a real hash of the password (not the plaintext leaking
        // through the cast).
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

    /** A member as the upgrade's wrap pass leaves them: bcrypt(md5hex) + the scheme flag. */
    private function wrappedMember(string $email, string $plaintext): Member
    {
        $member = Member::factory()->create(['email' => $email]);
        DB::table('members')->where('id', $member->getKey())->update([
            'password' => Hash::make(md5($plaintext)),
            'password_scheme' => PasswordScheme::Md5Bcrypt->value,
        ]);

        return $member;
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
