<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Actions\Fortify\ResetMemberPassword;
use App\Auth\PasswordScheme;
use App\Models\AdminUser;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The ClearsPasswordScheme invariant: any password write through the model resets the
 * scheme flag, so a wrapped-MD5 account that changes or resets its password ends up on
 * a plain bcrypt — a stale md5_bcrypt flag would otherwise lock the account out (login
 * would md5() the attempt against a hash of the raw plaintext).
 */
class PasswordSchemeTest extends TestCase
{
    use RefreshDatabase;

    private function flag(Member|AdminUser $user): void
    {
        DB::table($user->getTable())->where('id', $user->getKey())
            ->update(['password_scheme' => PasswordScheme::Md5Bcrypt->value]);
    }

    public function test_a_member_password_write_clears_a_stale_scheme(): void
    {
        $member = Member::factory()->create();
        $this->flag($member);

        $member->fresh()->forceFill(['password' => Hash::make('brand-new-pass-1')])->save();

        $this->assertNull($member->fresh()->password_scheme);
    }

    public function test_an_admin_password_write_clears_a_stale_scheme(): void
    {
        $admin = AdminUser::factory()->create();
        $this->flag($admin);

        $admin->fresh()->update(['password' => 'brand-new-pass-1']);

        $this->assertNull($admin->fresh()->password_scheme);
    }

    public function test_an_explicit_scheme_in_the_same_save_is_preserved(): void
    {
        // A writer that intends a non-default scheme states it in the same save; only
        // an implicit password-only write is reset. (The upgrade's wrap pass writes
        // through the query builder and does not pass here at all.)
        $member = Member::factory()->create();

        $member->forceFill([
            'password' => Hash::make(md5('secret')),
            'password_scheme' => PasswordScheme::Md5Bcrypt->value,
        ])->save();

        $this->assertSame(PasswordScheme::Md5Bcrypt->value, $member->fresh()->password_scheme);
    }

    public function test_a_password_reset_takes_a_wrapped_member_to_a_plain_bcrypt(): void
    {
        $member = Member::factory()->create();
        DB::table('members')->where('id', $member->getKey())->update([
            'password' => Hash::make(md5('old-secret')),
            'password_scheme' => PasswordScheme::Md5Bcrypt->value,
        ]);

        app(ResetMemberPassword::class)->reset($member->fresh(), [
            'password' => 'brand-new-pass-1',
            'password_confirmation' => 'brand-new-pass-1',
        ]);

        $member = $member->fresh();
        $this->assertTrue(Hash::check('brand-new-pass-1', $member->password));
        $this->assertNull($member->password_scheme);

        // And the reset password logs in through the standard bcrypt path.
        $this->post('/login', ['email' => $member->email, 'password' => 'brand-new-pass-1']);
        $this->assertAuthenticatedAs($member);
    }
}
