<?php

declare(strict_types=1);

namespace Tests\Feature\AiAccount;

use App\Features\AiAccount\Actions\CreateAiAccount;
use App\Features\AiAccount\Exceptions\AiAccountActionException;
use App\Features\AiAccount\Exceptions\AiAccountActionFailure;
use App\Models\Member;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CapturesSecurityLog;
use Tests\TestCase;

class CreateAiAccountTest extends TestCase
{
    use CapturesSecurityLog, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setSnsSetting(SnsSettingKey::AiAccountsEnabled, true);
    }

    private function create(Member $owner, string $name = 'Helper'): Member
    {
        return app(CreateAiAccount::class)($owner, $name);
    }

    /** The reason carried by the refusal, or null when the call succeeded. */
    private function refusal(callable $call): ?AiAccountActionFailure
    {
        try {
            $call();
        } catch (AiAccountActionException $e) {
            return $e->reason;
        }

        return null;
    }

    public function test_creates_a_credential_less_member_owned_by_the_creator(): void
    {
        $owner = Member::factory()->create();
        $this->captureSecurityLog();

        $aiAccount = $this->create($owner, '  Research helper  ');

        $this->assertSame('Research helper', $aiAccount->name); // trimmed at the boundary
        $this->assertNull($aiAccount->email);
        $this->assertNull($aiAccount->password);
        // Ordinary standing: an AI account is not created banned, it is created unable to log in.
        $this->assertFalse($aiAccount->fresh()->is_login_rejected);
        $this->assertTrue($aiAccount->isAiAccount());
        $this->assertSame((int) $owner->getKey(), (int) $aiAccount->owner_member_id);
        $this->assertSame([$aiAccount->getKey()], $owner->aiAccounts()->pluck('id')->all());

        $context = $this->assertOneSecurityEvent('ai_account.created');
        $this->assertSame((string) $aiAccount->getKey(), $context['member_id']);
        $this->assertSame((string) $owner->getKey(), $context['owner_id']);
    }

    public function test_refuses_while_the_site_does_not_offer_ai_accounts(): void
    {
        $this->setSnsSetting(SnsSettingKey::AiAccountsEnabled, false);
        $owner = Member::factory()->create();

        $reason = $this->refusal(fn () => $this->create($owner));

        $this->assertSame(AiAccountActionFailure::Disabled, $reason);
        $this->assertSame(0, $owner->aiAccounts()->count());
    }

    public function test_refuses_once_the_owner_holds_the_configured_number(): void
    {
        $this->setSnsSetting(SnsSettingKey::AiAccountLimit, 2);
        $owner = Member::factory()->create();

        $this->create($owner, 'One');
        $this->create($owner, 'Two');
        $reason = $this->refusal(fn () => $this->create($owner, 'Three'));

        $this->assertSame(AiAccountActionFailure::LimitReached, $reason);
        $this->assertSame(2, $owner->aiAccounts()->count());
    }

    public function test_a_limit_of_zero_forbids_creation_outright(): void
    {
        // The setting is enabled but the cap is nothing: the pair has to read as "no", not as an
        // off-by-one that lets the first one through.
        $this->setSnsSetting(SnsSettingKey::AiAccountLimit, 0);
        $owner = Member::factory()->create();

        $reason = $this->refusal(fn () => $this->create($owner));

        $this->assertSame(AiAccountActionFailure::LimitReached, $reason);
        $this->assertSame(0, $owner->aiAccounts()->count());
    }

    public function test_a_frozen_member_creates_nothing(): void
    {
        $owner = Member::factory()->create();
        $owner->forceFill(['is_login_rejected' => true])->save();

        $reason = $this->refusal(fn () => $this->create($owner));

        $this->assertSame(AiAccountActionFailure::OwnerFrozen, $reason);
        $this->assertSame(0, $owner->aiAccounts()->count());
    }

    public function test_the_freeze_is_re_read_under_the_lock_not_taken_from_the_callers_snapshot(): void
    {
        $owner = Member::factory()->create();
        // The caller's instance still says "not frozen"; the row says otherwise, as it would after a
        // ban committed while the form was open.
        Member::whereKey($owner->getKey())->update(['is_login_rejected' => true]);

        $reason = $this->refusal(fn () => $this->create($owner));

        $this->assertSame(AiAccountActionFailure::OwnerFrozen, $reason);
    }

    public function test_an_ai_account_cannot_own_one(): void
    {
        $aiAccount = Member::factory()->aiAccount()->create();

        $reason = $this->refusal(fn () => $this->create($aiAccount));

        $this->assertSame(AiAccountActionFailure::OwnerIsAiAccount, $reason);
        $this->assertSame(0, $aiAccount->aiAccounts()->count());
    }

    public function test_a_nameless_account_is_refused(): void
    {
        $owner = Member::factory()->create();

        $this->expectException(ValidationException::class);
        $this->create($owner, '   ');
    }

    public function test_a_name_is_held_to_the_length_an_ordinary_members_name_is(): void
    {
        // The column is 255 wide and registration says so; the action has to say it too, or a caller
        // that is not the form truncates (MySQL, non-strict) or aborts at the write. Counted in
        // characters, as the column is, so a multibyte name is not refused for its byte length.
        $owner = Member::factory()->create();

        $atTheLimit = $this->create($owner, str_repeat('あ', 255));
        $this->assertSame(255, mb_strlen((string) $atTheLimit->fresh()->name));

        try {
            $this->create($owner, str_repeat('あ', 256));
            $this->fail('expected an over-long name to be refused');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('name', $e->errors());
        }

        $this->assertSame([$atTheLimit->getKey()], $owner->aiAccounts()->pluck('id')->all());
    }

    public function test_the_owner_link_is_not_mass_assignable(): void
    {
        $owner = Member::factory()->create();

        // Ordinary member creation must not be able to declare itself owned — the link is set by
        // CreateAiAccount alone, and never changed afterwards.
        $member = Member::create([
            'name' => 'Impostor',
            'email' => 'impostor@example.test',
            'password' => 'irrelevant',
            'owner_member_id' => $owner->getKey(),
        ]);

        $this->assertNull($member->fresh()->owner_member_id);
        $this->assertFalse($member->fresh()->isAiAccount());
    }

    public function test_the_owner_link_cannot_be_reassigned_by_mass_assignment(): void
    {
        $owner = Member::factory()->create();
        $poacher = Member::factory()->create();
        $aiAccount = $this->create($owner);

        $aiAccount->update(['owner_member_id' => $poacher->getKey()]);

        $this->assertSame((int) $owner->getKey(), (int) $aiAccount->fresh()->owner_member_id);
    }
}
