<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Member;
use App\Rules\MaxBytes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Tests\TestCase;

/**
 * The single password policy (Password::defaults in AppServiceProvider): minimum 8
 * characters, maximum 72 BYTES — bcrypt reads nothing past its 72nd input byte, so a
 * longer secret would silently collide with any other sharing its 72-byte prefix, and
 * a character-counting max cannot express that boundary for multibyte input.
 */
class PasswordPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function passes(string $password): bool
    {
        return Validator::make(['password' => $password], ['password' => Password::default()])->passes();
    }

    public function test_the_default_policy_bounds_length_in_bytes(): void
    {
        $this->assertFalse($this->passes('seven77'));
        $this->assertTrue($this->passes('ztr9kqwm')); // 8 chars, not on the common-password blocklist
        $this->assertTrue($this->passes(str_repeat('a', 72)));
        $this->assertFalse($this->passes(str_repeat('a', 73)));

        // 25 characters — would pass a character-based max — but 75 bytes.
        $multibyte = str_repeat('あ', 25);
        $this->assertSame(75, strlen($multibyte));
        $this->assertFalse($this->passes($multibyte));
        $this->assertTrue($this->passes(str_repeat('あ', 24))); // 72 bytes exactly
    }

    public function test_max_bytes_is_boundary_exact(): void
    {
        $fails = function (string $value, int $bytes): bool {
            $failed = false;
            (new MaxBytes($bytes))->validate('password', $value, function () use (&$failed): void {
                $failed = true;
            });

            return $failed;
        };

        $this->assertFalse($fails(str_repeat('x', 72), 72));
        $this->assertTrue($fails(str_repeat('x', 73), 72));
    }

    public function test_an_over_long_password_is_rejected_over_http(): void
    {
        $member = Member::factory()->create();

        $response = $this->actingAs($member, 'member')
            ->from('/member/config')
            ->post('/member/config/password', [
                'current_password' => 'password',
                'password' => str_repeat('あ', 25),
                'password_confirmation' => str_repeat('あ', 25),
            ]);

        $response->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check('password', $member->fresh()->password), 'password must be unchanged');
    }

    public function test_bcrypt_rounds_env_is_live(): void
    {
        // phpunit.xml pins BCRYPT_ROUNDS=4; config/hashing.php must consume it (the env
        // was dead while the config file did not exist).
        $this->assertStringStartsWith('$2y$04$', Hash::make('anything'));
    }
}
