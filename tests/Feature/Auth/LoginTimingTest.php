<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Auth\LegacyEloquentUserProvider;
use App\Models\AdminUser;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Hashing\BcryptHasher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Timing itself is not asserted; the equalizing hash call is, on each fast-fail path. */
class LoginTimingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A real bcrypt hasher that records every make(); swapped in for both the facade
     * and the container `hash` binding, so app code behaves normally around it.
     */
    private function spyHasher(): BcryptHasher
    {
        $spy = new class(['rounds' => 4]) extends BcryptHasher
        {
            /** @var list<string> */
            public array $made = [];

            public function make(#[\SensitiveParameter] $value, array $options = [])
            {
                $this->made[] = (string) $value;

                return parent::make($value, $options);
            }

            /** HashManager-only API the Hash facade exposes; mirror its implementation. */
            public function isHashed(#[\SensitiveParameter] $value): bool
            {
                return password_get_info($value)['algo'] !== null;
            }
        };

        Hash::swap($spy);

        return $spy;
    }

    public function test_an_unknown_member_email_still_costs_a_hash(): void
    {
        $spy = $this->spyHasher();

        $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'whatever-123']);

        $this->assertGuest();
        $this->assertNotEmpty($spy->made);
    }

    public function test_a_passwordless_member_still_costs_a_hash(): void
    {
        $member = Member::factory()->create(['email' => 'nologin@example.com']);
        DB::table('members')->where('id', $member->getKey())->update(['password' => null]);
        $spy = $this->spyHasher();

        $this->post('/login', ['email' => 'nologin@example.com', 'password' => 'whatever-123']);

        $this->assertGuest();
        $this->assertNotEmpty($spy->made);
    }

    public function test_an_unknown_admin_username_still_costs_a_hash(): void
    {
        AdminUser::factory()->create(['username' => 'real-admin']);
        $spy = $this->spyHasher();
        $provider = new LegacyEloquentUserProvider(app('hash'), AdminUser::class);

        $user = $provider->retrieveByCredentials(['username' => 'ghost', 'password' => 'whatever-123']);

        $this->assertNull($user);
        $this->assertNotEmpty($spy->made);
    }

    public function test_an_admin_with_an_unrecognised_hash_still_costs_a_hash(): void
    {
        DB::table('admin_users')->insert([
            'username' => 'bare', 'password' => md5('secret'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $admin = AdminUser::where('username', 'bare')->first();
        $spy = $this->spyHasher();
        $provider = new LegacyEloquentUserProvider(app('hash'), AdminUser::class);

        $this->assertFalse($provider->validateCredentials($admin, ['password' => 'secret']));
        $this->assertNotEmpty($spy->made);
    }
}
