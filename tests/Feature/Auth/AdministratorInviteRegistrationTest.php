<?php

namespace Tests\Feature\Auth;

use App\Features\Auth\RegistrationTokenSource;
use App\Features\Member\Actions\ConfirmEmailChange;
use App\Models\AdminRoleInvite;
use App\Models\EmailChangeRequest;
use App\Models\Member;
use App\Models\RegistrationToken;
use App\Support\SnsSettingKey;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CapturesSecurityLog;
use Tests\TestCase;

class AdministratorInviteRegistrationTest extends TestCase
{
    use CapturesSecurityLog, RefreshDatabase;

    private const PASSWORD = 'sufficiently-long-pw';

    private const EMAIL = 'admin@example.com';

    protected function setUp(): void
    {
        parent::setUp();
        $this->captureSecurityLog();
    }

    /** @return array<string, string> */
    private function form(): array
    {
        return ['name' => 'Newcomer', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD];
    }

    private function link(RegistrationTokenSource $source, string $email = self::EMAIL): string
    {
        $raw = Str::random(40);
        RegistrationToken::updateOrCreate(['email' => $email], ['token' => hash('sha256', $raw), 'source' => $source, 'created_at' => now()]);

        return $raw;
    }

    private function invite(int $expiresInSeconds = 86400, string $email = self::EMAIL): void
    {
        AdminRoleInvite::create(['email' => $email, 'expires_at' => now()->getTimestamp() + $expiresInSeconds, 'created_at' => now()]);
    }

    private function mode(string $mode): void
    {
        $this->setSnsSetting(SnsSettingKey::RegistrationMode, $mode);
    }

    private function registered(): ?Member
    {
        return Member::where('email', self::EMAIL)->first();
    }

    public function test_registering_through_the_invite_makes_an_administrator_and_spends_the_invite(): void
    {
        $this->mode('invite');
        $this->invite();
        $raw = $this->link(RegistrationTokenSource::AdminRoleInvite);

        $this->get("/register/{$raw}")->assertOk();
        $this->post("/register/{$raw}", $this->form())->assertRedirect('/');

        $member = $this->registered();
        $this->assertTrue($member->isAdministrator());
        $this->assertSame(1, $member->admin_generation);
        $this->assertDatabaseCount('admin_role_invites', 0);
        $this->assertDatabaseCount('registration_tokens', 0);
        $context = $this->assertOneSecurityEvent('admin.granted');
        $this->assertSame([(string) $member->id, 'cli', 'invite'], [$context['member_id'], $context['actor'], $context['via']]);
    }

    public function test_an_ordinary_link_that_replaced_the_invites_link_still_makes_an_administrator(): void
    {
        $this->mode('open');
        $this->invite();
        $this->link(RegistrationTokenSource::AdminRoleInvite);
        $raw = $this->link(RegistrationTokenSource::Selfservice);

        $this->post("/register/{$raw}", $this->form())->assertRedirect('/');

        $this->assertTrue($this->registered()->isAdministrator());
    }

    public function test_an_expired_invite_grants_nothing_and_stays_for_the_list(): void
    {
        $this->mode('invite');
        $this->invite(-1);
        $raw = $this->link(RegistrationTokenSource::MemberInvite);

        $this->post("/register/{$raw}", $this->form())->assertRedirect('/');

        $this->assertFalse($this->registered()->isAdministrator());
        $this->assertSame(0, $this->registered()->admin_generation);
        $this->assertDatabaseCount('admin_role_invites', 1);
        $this->assertCount(0, $this->securityRecords('admin.granted'));
    }

    public function test_an_other_address_registering_leaves_the_invite_alone(): void
    {
        $this->mode('invite');
        $this->invite();
        $raw = $this->link(RegistrationTokenSource::MemberInvite, 'someone@example.com');

        $this->post("/register/{$raw}", $this->form())->assertRedirect('/');

        $this->assertFalse(Member::where('email', 'someone@example.com')->first()->isAdministrator());
        $this->assertDatabaseCount('admin_role_invites', 1);
    }

    public function test_closed_completes_the_invites_own_link(): void
    {
        $this->mode('closed');
        $this->invite();
        $raw = $this->link(RegistrationTokenSource::AdminRoleInvite);

        $this->get("/register/{$raw}")->assertOk();
        $this->post("/register/{$raw}", $this->form())->assertRedirect('/');

        $this->assertTrue($this->registered()->isAdministrator());
    }

    /** The first condition alone: the invite is live, so only the link's origin refuses. */
    public function test_closed_refuses_any_other_link_to_an_invited_address(): void
    {
        $this->mode('closed');
        $this->invite();

        foreach ([RegistrationTokenSource::Selfservice, RegistrationTokenSource::MemberInvite, RegistrationTokenSource::AdminInvite] as $source) {
            $raw = $this->link($source);
            $this->get("/register/{$raw}")->assertNotFound();
            $this->post("/register/{$raw}", $this->form())->assertNotFound();
        }

        $this->assertNull($this->registered());
        $this->assertDatabaseCount('admin_role_invites', 1);
    }

    /** The second condition alone: the link is the invite's own, so only the invite refuses. */
    public function test_closed_refuses_the_invites_link_once_the_invite_lapsed_or_is_gone(): void
    {
        $this->mode('closed');
        $raw = $this->link(RegistrationTokenSource::AdminRoleInvite);

        $this->get("/register/{$raw}")->assertNotFound();
        $this->post("/register/{$raw}", $this->form())->assertNotFound();

        $this->invite(-1);
        $this->get("/register/{$raw}")->assertNotFound();
        $this->post("/register/{$raw}", $this->form())->assertNotFound();

        $this->assertNull($this->registered());
    }

    public function test_the_invites_link_needs_the_invite_in_every_mode(): void
    {
        $raw = $this->link(RegistrationTokenSource::AdminRoleInvite);

        foreach (['open', 'invite', 'admin_only'] as $mode) {
            $this->mode($mode);
            $this->get("/register/{$raw}")->assertNotFound();
            $this->post("/register/{$raw}", $this->form())->assertNotFound();
        }

        $this->assertNull($this->registered());
    }

    public function test_closed_answers_an_expired_link_like_an_unknown_one(): void
    {
        $this->mode('closed');
        $this->invite();
        $raw = $this->link(RegistrationTokenSource::AdminRoleInvite);
        $this->travel((int) config('openpne.registration.token_ttl_minutes') + 1)->minutes();

        $this->get("/register/{$raw}")->assertNotFound();
        $this->post("/register/{$raw}", $this->form())->assertNotFound();
    }

    /** Runs $meanwhile after the controller resolved the link, just before the capacity lock opens the transaction's reads. */
    private function between(Closure $meanwhile): void
    {
        $fired = false;
        DB::beforeExecuting(function (string $sql, array $bindings) use (&$fired, $meanwhile): void {
            if ($fired || ! str_contains($sql, 'sns_settings') || ! in_array(SnsSettingKey::MemberCapacity->value, $bindings, true)) {
                return;
            }
            $fired = true;
            $meanwhile();
        });
    }

    public function test_an_invite_spent_before_the_insert_grants_nothing(): void
    {
        $this->mode('invite');
        $this->invite();
        $raw = $this->link(RegistrationTokenSource::MemberInvite);
        $this->between(fn () => AdminRoleInvite::query()->delete());

        $this->post("/register/{$raw}", $this->form())->assertRedirect('/');

        $this->assertFalse($this->registered()->isAdministrator());
    }

    public function test_closed_refuses_when_the_invite_is_spent_before_the_insert(): void
    {
        $this->mode('closed');
        $this->invite();
        $raw = $this->link(RegistrationTokenSource::AdminRoleInvite);
        $this->between(fn () => AdminRoleInvite::query()->update(['expires_at' => now()->getTimestamp() - 1]));

        $this->post("/register/{$raw}", $this->form())->assertNotFound();

        $this->assertNull($this->registered());
        $this->assertDatabaseCount('registration_tokens', 1);
    }

    public function test_a_link_replaced_before_the_insert_creates_no_one(): void
    {
        $this->mode('invite');
        $this->invite();
        $raw = $this->link(RegistrationTokenSource::AdminRoleInvite);
        $this->between(fn () => $this->link(RegistrationTokenSource::MemberInvite));

        $this->post("/register/{$raw}", $this->form())->assertRedirect(route('login'));

        $this->assertNull($this->registered());
        $this->assertDatabaseCount('registration_tokens', 1);
        $this->assertDatabaseCount('admin_role_invites', 1);
    }

    public function test_closed_answers_a_link_replaced_before_the_insert_with_a_404(): void
    {
        $this->mode('closed');
        $this->invite();
        $raw = $this->link(RegistrationTokenSource::AdminRoleInvite);
        $this->between(fn () => $this->link(RegistrationTokenSource::AdminRoleInvite));

        $this->post("/register/{$raw}", $this->form())->assertNotFound();

        $this->assertNull($this->registered());
        $this->assertDatabaseCount('admin_role_invites', 1);
    }

    public function test_moving_a_members_address_to_an_invited_one_grants_nothing(): void
    {
        $member = Member::factory()->create(['email' => 'before@example.com']);
        $this->invite();
        $raw = Str::random(40);
        $pending = EmailChangeRequest::create(['member_id' => $member->id, 'new_email' => self::EMAIL, 'token' => hash('sha256', $raw), 'created_at' => now()]);

        app(ConfirmEmailChange::class)($pending);

        $this->assertSame(self::EMAIL, $member->fresh()->email);
        $this->assertFalse($member->fresh()->isAdministrator());
    }
}
