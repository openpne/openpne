<?php

namespace Tests\Feature\Upgrade\Runner;

use App\Upgrade\Runner\MessageTypePreflight;
use App\Upgrade\Runner\Warning;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Upgrade\UpgradeSqlTestCase;

class MessageTypePreflightTest extends UpgradeSqlTestCase
{
    private const READ = ['member', 'message', 'message_type', 'message_send_list'];

    protected function sourceTables(): array
    {
        return ['message', 'message_type'];
    }

    public function test_the_notification_types_are_counted_per_type_and_the_mailbox_types_are_not(): void
    {
        $this->seedType(1, 'message');
        $this->seedType(2, 'friend_link');
        $this->seedType(3, 'community_message');
        $this->seedType(4, 'community_joining_request');
        $this->seedMessage(10, 1);
        $this->seedMessage(11, 3);
        $this->seedMessage(12, 2);
        $this->seedMessage(13, 2);
        $this->seedMessage(14, 4);

        $report = (new MessageTypePreflight)->inspect('', null, self::READ);

        $this->assertSame([], $report->errors);
        $this->assertSame(
            [['W-MESSAGE-NOTIFICATION-TYPE', 'community_joining_request', 1], ['W-MESSAGE-NOTIFICATION-TYPE', 'friend_link', 2]],
            array_map(fn (Warning $w): array => [$w->code, $this->typeOf($w), $w->count], $report->warnings),
        );
        $this->assertSame(MessageTypePreflight::notificationTypeMessage('friend_link', 2, [12, 13]), $report->warnings[1]->message);
        $this->assertSame(Warning::DROPPED, $report->warnings[1]->category());
    }

    public function test_a_type_the_plugin_does_not_write_and_a_missing_type_row_are_unknown(): void
    {
        $this->seedType(1, 'message');
        $this->seedType(9, 'op_vendor_alert');
        $this->seedMessage(20, 9);
        $this->seedMessage(21, 77); // no message_type row

        $report = (new MessageTypePreflight)->inspect('', null, self::READ);

        $this->assertSame(
            [['W-MESSAGE-UNKNOWN-TYPE', 1], ['W-MESSAGE-UNKNOWN-TYPE', 1]],
            array_map(fn (Warning $w): array => [$w->code, $w->count], $report->warnings),
        );
        $this->assertSame(MessageTypePreflight::unknownTypeMessage('', 1, [21]), $report->warnings[0]->message);
        $this->assertSame(MessageTypePreflight::unknownTypeMessage('op_vendor_alert', 1, [20]), $report->warnings[1]->message);
        $this->assertSame(Warning::UNKNOWN, $report->warnings[0]->category());
    }

    public function test_a_source_without_the_message_tables_is_not_queried(): void
    {
        $this->seedType(2, 'friend_link');
        $this->seedMessage(30, 2);

        $report = (new MessageTypePreflight)->inspect('', null, ['member', 'message_type']);

        $this->assertSame([], $report->warnings);
    }

    private function typeOf(Warning $warning): string
    {
        preg_match('/`([a-z_]+)` row|type `([a-z_]+)`/', $warning->message, $m);

        return $m[1] !== '' ? $m[1] : ($m[2] ?? '');
    }

    private function seedType(int $id, string $typeName): void
    {
        DB::table('message_type')->insert(['id' => $id, 'type_name' => $typeName, 'foreign_table' => null, 'is_deleted' => 0,
            'created_at' => '2016-01-01 00:00:00', 'updated_at' => '2016-01-01 00:00:00']);
    }

    private function seedMessage(int $id, int $typeId): void
    {
        DB::table('message')->insert(['id' => $id, 'member_id' => 1, 'subject' => 'S', 'body' => 'B', 'is_deleted' => 0, 'is_send' => 1,
            'thread_message_id' => 0, 'return_message_id' => 0, 'message_type_id' => $typeId, 'foreign_id' => 0,
            'created_at' => '2018-01-01 00:00:00', 'updated_at' => '2018-01-01 00:00:00']);
    }
}
