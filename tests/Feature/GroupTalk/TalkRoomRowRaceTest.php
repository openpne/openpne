<?php

namespace Tests\Feature\GroupTalk;

use App\Features\GroupTalk\GroupTalkRoomNotificationRows;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupMessage;
use App\Models\Member;
use App\Notifications\GroupTalk\GroupTalkMessagePostedNotification;
use App\Support\SnsSettingKey;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\MigratesUpgradeTargetsOnce;
use Tests\Concerns\OpensSecondConnection;
use Tests\TestCase;

/**
 * Both deliveries are the real queued notification; the inner one runs whole on a second connection,
 * as another worker would, between the outer one's insert and its replace.
 */
class TalkRoomRowRaceTest extends TestCase
{
    use MigratesUpgradeTargetsOnce, OpensSecondConnection;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Two connections need a server; sqlite :memory: has one.');
        }

        $this->openSecondConnection();
        $this->setSnsSetting(SnsSettingKey::FeatureGroupTalkEnabled, true);
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->closeSecondConnection();
        }

        parent::tearDown();
    }

    /** @return array<string, array{bool}> */
    public static function orders(): array
    {
        return [
            'the newer message lands inside the older one' => [true],
            'the older message lands inside the newer one' => [false],
        ];
    }

    #[DataProvider('orders')]
    public function test_two_deliveries_racing_for_one_room_leave_the_newer_messages_row(bool $newerInside): void
    {
        $group = Group::factory()->create();
        $author = $this->memberOf($group);
        $reader = $this->memberOf($group);
        $older = GroupMessage::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);
        $newer = GroupMessage::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);
        [$outer, $inner] = $newerInside ? [$older, $newer] : [$newer, $older];

        $inserted = 0;
        DatabaseNotification::created(function () use (&$inserted, $reader, $author, $inner): void {
            if (++$inserted === 1) {
                $this->onSecondConnection(fn () => $this->deliver($reader, $author, $inner));
            }
        });

        $this->deliver($reader, $author, $outer);

        $this->assertSame(2, $inserted, 'both deliveries must have written their row');
        $this->assertSame(
            [(int) $newer->getKey()],
            app(GroupTalkRoomNotificationRows::class)
                ->rowsFor((int) $reader->getKey(), (int) $group->getKey())
                ->map(fn (DatabaseNotification $row): int => (int) $row->data['message_id'])
                ->all(),
        );
    }

    private function memberOf(Group $group): Member
    {
        $member = Member::factory()->create();
        GroupMember::factory()->create(['group_id' => $group->getKey(), 'member_id' => $member->getKey()]);

        return $member;
    }

    private function deliver(Member $reader, Member $author, GroupMessage $message): void
    {
        $reader->notify(new GroupTalkMessagePostedNotification($author, $message, ['database']));
    }
}
