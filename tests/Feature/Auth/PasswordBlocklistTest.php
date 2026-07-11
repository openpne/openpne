<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Actions\Fortify\ResetMemberPassword;
use App\Models\Member;
use App\Services\SnsSettingService;
use App\Support\CommonPasswordList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

/**
 * The guessability half of the password policy (App\Providers\AppServiceProvider): the common-password
 * blocklist (App\Rules\NotCommonPassword) and the context-word check (App\Rules\NotContextWord),
 * gated by OPENPNE_PASSWORD_BLOCKLIST. Most tests swap the blocklist for a small fixture via the
 * useList seam; the real shipped file is exercised once, end to end.
 */
class PasswordBlocklistTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CommonPasswordList::useList(base_path('tests/Fixtures/common-passwords.txt'));
    }

    protected function tearDown(): void
    {
        CommonPasswordList::useList(null);

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function passwordErrors(string $password, array $data = []): array
    {
        return Validator::make(
            ['password' => $password] + $data,
            ['password' => Password::default()],
        )->errors()->get('password');
    }

    /** @param array<string, mixed> $data */
    private function passes(string $password, array $data = []): bool
    {
        return $this->passwordErrors($password, $data) === [];
    }

    private function commonMessage(): string
    {
        return __('This password is very commonly used, so it would be easily guessed. Choose a longer password, such as several unrelated words.');
    }

    private function contextMessage(): string
    {
        return __('The password must not contain your name, your email address, or the name of this site.');
    }

    public function test_a_common_password_is_rejected(): void
    {
        $this->assertContains($this->commonMessage(), $this->passwordErrors('blocklisted-entry'));
    }

    public function test_the_blocklist_is_case_insensitive(): void
    {
        // 'password123' is in the fixture; the candidate differs only in case.
        $this->assertContains($this->commonMessage(), $this->passwordErrors('PassWord123'));
    }

    public function test_a_strong_uncommon_password_passes(): void
    {
        $this->assertTrue($this->passes('vixen-turret-cobalt-9'));
    }

    public function test_the_toggle_disables_the_guessability_checks(): void
    {
        config(['openpne.password.blocklist' => false]);

        $this->assertTrue($this->passes('blocklisted-entry'));
        // The length policy still applies with the toggle off.
        $this->assertFalse($this->passes('short'));
    }

    public function test_registration_data_context_is_rejected(): void
    {
        // Allowlisted keys in the validation data (email local part, name) become context words.
        $this->assertContains($this->contextMessage(), $this->passwordErrors('kobayashi-2024', ['email' => 'kobayashi@example.com']));
        $this->assertContains($this->contextMessage(), $this->passwordErrors('yamada-secret-9', ['name' => 'Yamada']));
    }

    public function test_a_dot_nested_username_from_a_filament_form_is_rejected(): void
    {
        // Filament nests form state under `data.*`; the allowlist matches on the key's last segment.
        $this->assertContains($this->contextMessage(), $this->passwordErrors('adminbob-pass-1', ['data' => ['username' => 'adminbob']]));
    }

    public function test_the_reset_path_enriches_the_data_with_the_member_name(): void
    {
        // The email local part and the guard are absent here, so a rejection proves the name that
        // ResetMemberPassword injects reaches the rule.
        $member = Member::factory()->create(['name' => 'Takahashi', 'email' => 'member@example.com']);

        try {
            app(ResetMemberPassword::class)->reset($member, [
                'password' => 'takahashi-99',
                'password_confirmation' => 'takahashi-99',
            ]);
            $this->fail('Expected a validation failure for a context word.');
        } catch (ValidationException $e) {
            $this->assertContains($this->contextMessage(), $e->validator->errors()->get('password'));
        }

        $this->assertTrue(Hash::check('password', $member->fresh()->password), 'password must be unchanged');
    }

    public function test_an_authenticated_member_name_is_used_as_context(): void
    {
        // No data and a sub-threshold email local part, so the guard's name is the only context source.
        $member = Member::factory()->create(['name' => 'Nakamura', 'email' => 'n@example.com']);
        $this->actingAs($member, 'member');

        $this->assertContains($this->contextMessage(), $this->passwordErrors('nakamura-strong-9'));
    }

    public function test_a_cli_username_is_used_as_context(): void
    {
        $this->artisan('openpne:admin:create', ['username' => 'takeshi'])
            ->expectsQuestion('Password', 'takeshi-secret-1')
            ->expectsQuestion('Confirm password', 'takeshi-secret-1')
            ->expectsOutputToContain($this->contextMessage())
            ->assertFailed();

        $this->assertDatabaseMissing('admin_users', ['username' => 'takeshi']);
    }

    public function test_context_resolution_failure_does_not_block(): void
    {
        // sns_name() throwing (e.g. no schema mid-install) must skip context checking, not reject: the
        // whole gathering is guarded, so even a data context word passes.
        $this->mock(SnsSettingService::class)
            ->shouldReceive('get')
            ->andThrow(new RuntimeException('no schema'));

        $this->assertTrue($this->passes('kobayashi-99', ['name' => 'Kobayashi']));
    }

    public function test_the_real_blocklist_rejects_a_known_password_end_to_end(): void
    {
        CommonPasswordList::useList(null);

        $path = resource_path('data/common-passwords.txt');
        $this->assertFileExists($path);
        $this->assertGreaterThanOrEqual(50000, count((array) file($path, FILE_IGNORE_NEW_LINES)));

        $this->assertContains($this->commonMessage(), $this->passwordErrors('password123'));
    }

    public function test_the_blocklist_message_is_shown_over_http(): void
    {
        CommonPasswordList::useList(null);
        $member = Member::factory()->create();

        $this->actingAs($member, 'member')
            ->from('/member/config/password')
            ->post('/member/config/password', [
                'current_password' => 'password',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ])
            ->assertSessionHasErrors(['password' => $this->commonMessage()]);

        $this->assertTrue(Hash::check('password', $member->fresh()->password), 'password must be unchanged');
    }
}
