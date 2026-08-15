<?php

declare(strict_types=1);

namespace Tests\Feature\AiAccount;

use App\Features\AiAccount\Actions\DeleteAiAccount;
use App\Features\AiAccount\Exceptions\AiAccountActionException;
use App\Features\AiAccount\Exceptions\AiAccountActionFailure;
use App\Models\File;
use App\Models\Member;
use App\Models\MemberImage;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Concerns\CapturesSecurityLog;
use Tests\TestCase;

class DeleteAiAccountTest extends TestCase
{
    use CapturesSecurityLog, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Reserve id 1 as the un-withdrawable primary member so factory subjects get id >= 2.
        Member::factory()->create(['id' => 1]);
        $this->setSnsSetting(SnsSettingKey::AiAccountsEnabled, true);
    }

    private function retire(Member $owner, Member $aiAccount): void
    {
        app(DeleteAiAccount::class)($owner, $aiAccount);
    }

    public function test_the_account_is_withdrawn_and_leaves_nothing_behind(): void
    {
        Notification::fake();
        $owner = Member::factory()->create();
        $aiAccount = Member::factory()->aiAccount($owner)->create();
        $aiAccount->createToken('mcp', ['mcp:read']);
        $aiAccount->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'test',
            'data' => ['kind' => 'test'],
        ]);
        // Polymorphic like the tokens and the feed, so no cascade reaches it: a surviving row would
        // push the next holder of this member id's notifications to a stranger's browser.
        $aiAccount->updatePushSubscription('https://push.example.test/ai', str_repeat('k', 87), str_repeat('a', 22), 'aes128gcm');
        $bystander = Member::factory()->aiAccount($owner)->create();
        $bystander->updatePushSubscription('https://push.example.test/bystander', str_repeat('k', 87), str_repeat('a', 22), 'aes128gcm');
        $avatar = MemberImage::factory()->create(['member_id' => $aiAccount->getKey()]);
        $avatarFile = File::findOrFail($avatar->file_id);

        $this->captureSecurityLog();
        $this->retire($owner, $aiAccount);

        $this->assertModelMissing($aiAccount);
        $this->assertModelMissing($avatar);
        $this->assertModelMissing($avatarFile);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $aiAccount->getKey()]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $aiAccount->getKey()]);
        $this->assertSame(0, $aiAccount->pushSubscriptions()->count());
        $this->assertSame(1, $bystander->pushSubscriptions()->count(), "another account's device is untouched");
        $this->assertSame([$bystander->getKey()], $owner->aiAccounts()->pluck('id')->all());

        // Both lines: the withdrawal records the row going away, ai_account.deleted records whose it was.
        $this->assertCount(1, $this->securityRecords('member.withdrawn'));
        $context = $this->assertOneSecurityEvent('ai_account.deleted');
        $this->assertSame((string) $aiAccount->getKey(), $context['member_id']);
        $this->assertSame((string) $owner->getKey(), $context['owner_id']);
    }

    public function test_refuses_an_account_owned_by_someone_else(): void
    {
        $owner = Member::factory()->create();
        $stranger = Member::factory()->create();
        $aiAccount = Member::factory()->aiAccount($owner)->create();

        try {
            $this->retire($stranger, $aiAccount);
            $this->fail('expected a stranger to be refused');
        } catch (AiAccountActionException $e) {
            $this->assertSame(AiAccountActionFailure::NotOwned, $e->reason);
        }

        $this->assertModelExists($aiAccount);
    }

    public function test_refuses_an_ordinary_member(): void
    {
        $owner = Member::factory()->create();
        $human = Member::factory()->create();

        try {
            $this->retire($owner, $human);
            $this->fail('expected an unowned member to be refused');
        } catch (AiAccountActionException $e) {
            $this->assertSame(AiAccountActionFailure::NotOwned, $e->reason);
        }

        $this->assertModelExists($human);
    }

    public function test_deletion_stays_available_once_the_site_stops_offering_ai_accounts(): void
    {
        // Switching the feature off closes the door; it must not lock the owner in with what is
        // already behind it.
        Notification::fake();
        $owner = Member::factory()->create();
        $aiAccount = Member::factory()->aiAccount($owner)->create();
        $this->setSnsSetting(SnsSettingKey::AiAccountsEnabled, false);

        $this->retire($owner, $aiAccount);

        $this->assertModelMissing($aiAccount);
    }
}
