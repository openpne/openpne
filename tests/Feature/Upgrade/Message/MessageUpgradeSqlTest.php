<?php

namespace Tests\Feature\Upgrade\Message;

use App\Models\Member;
use App\Models\Message;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\MessageUpgrade;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Runs the compiled `message` → `messages` INSERT...SELECT against the real OpenPNE 3 DDL, checking
 * the type filter, the is_send→is_draft inversion, self-reference null-normalization, the draft
 * recipient fold, and the deleted_message trash/purge fold onto the sender-side soft-delete columns.
 *
 * MySQL only: the set-based copy, the source DDL (TEXT, DATETIME, utf8mb3) and the correlated
 * subqueries are MySQL features.
 */
class MessageUpgradeSqlTest extends TestCase
{
    use DatabaseMigrations;

    /** Source tables MessageUpgrade reads (its FROM table plus the subquery tables), FKs stripped. */
    private array $sourceTables = ['message', 'message_send_list', 'deleted_message', 'message_type'];

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Upgrade INSERT...SELECT runs on MySQL (source DDL + set-based copy).');
        }

        foreach ($this->sourceTables as $table) {
            DB::statement("DROP TABLE IF EXISTS `{$table}`");
            DB::statement(SourceSchema::default()->createStatement($table, withoutForeignKeys: true));
        }

        // Personal-message type (migrated) and a notification type (skipped by the filter).
        $this->seedType(1, 'message');
        $this->seedType(2, 'friend_link');
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            foreach (array_reverse($this->sourceTables) as $table) {
                DB::statement("DROP TABLE IF EXISTS `{$table}`");
            }
        }

        parent::tearDown();
    }

    public function test_migrates_a_sent_personal_message_preserving_id_and_timestamps(): void
    {
        $sender = Member::factory()->create();
        $this->seedMessage(500, $sender->id, [
            'subject' => 'Hello',
            'body' => 'Body text',
            'is_send' => 1,
            'created_at' => '2018-03-04 12:34:56',
            'updated_at' => '2019-06-07 01:02:03',
        ]);

        $this->runUpgrade();

        $this->assertDatabaseHas('messages', [
            'id' => 500,
            'sender_id' => $sender->id,
            'subject' => 'Hello',
            'body' => 'Body text',
            'is_draft' => 0,
            'draft_recipient_id' => null,
            'parent_id' => null,
            'thread_id' => null,
            'sender_deleted_at' => null,
            'sender_purged_at' => null,
            'created_at' => '2018-03-04 12:34:56',
            'updated_at' => '2019-06-07 01:02:03',
        ]);
    }

    public function test_only_personal_messages_migrate(): void
    {
        $sender = Member::factory()->create();
        $this->seedMessage(600, $sender->id, ['is_send' => 1, 'message_type_id' => 1]);
        $this->seedMessage(601, $sender->id, ['is_send' => 1, 'message_type_id' => 2]); // friend_link

        $this->runUpgrade();

        $this->assertDatabaseHas('messages', ['id' => 600]);
        $this->assertDatabaseMissing('messages', ['id' => 601]);
    }

    public function test_preserves_long_text_body(): void
    {
        $sender = Member::factory()->create();
        $longBody = str_repeat('本文', 5000);
        $this->seedMessage(700, $sender->id, ['is_send' => 1, 'body' => $longBody]);

        $this->runUpgrade();

        $this->assertSame($longBody, Message::findOrFail(700)->body);
    }

    public function test_a_draft_folds_its_recipient_onto_the_column(): void
    {
        $sender = Member::factory()->create();
        $recipient = Member::factory()->create();
        // OpenPNE 3 wrote a send-list row for a draft too; OpenPNE 4 holds it on the message instead.
        $this->seedMessage(800, $sender->id, ['is_send' => 0]);
        $this->seedSendList(9000, $recipient->id, 800);

        $this->runUpgrade();

        $this->assertDatabaseHas('messages', [
            'id' => 800,
            'is_draft' => 1,
            'draft_recipient_id' => $recipient->id,
        ]);
    }

    public function test_a_multi_recipient_draft_keeps_the_lowest_id_recipient(): void
    {
        $sender = Member::factory()->create();
        $first = Member::factory()->create();
        $second = Member::factory()->create();
        $this->seedMessage(810, $sender->id, ['is_send' => 0]);
        $this->seedSendList(9011, $second->id, 810); // higher id, dropped
        $this->seedSendList(9010, $first->id, 810);  // lowest id, kept

        $this->runUpgrade();

        $this->assertSame($first->id, Message::findOrFail(810)->draft_recipient_id);
    }

    public function test_self_references_are_kept_when_in_set_and_nulled_otherwise(): void
    {
        $sender = Member::factory()->create();
        $this->seedMessage(900, $sender->id, ['is_send' => 1]); // root
        $this->seedMessage(901, $sender->id, ['is_send' => 1, 'return_message_id' => 900, 'thread_message_id' => 900]);
        // return points outside the migrated set (dangling); thread is the OpenPNE 3 default 0.
        $this->seedMessage(902, $sender->id, ['is_send' => 1, 'return_message_id' => 99999, 'thread_message_id' => 0]);
        // a reply whose parent is a non-personal (friend_link) message → null, not a broken self-FK.
        $this->seedMessage(903, $sender->id, ['is_send' => 1, 'message_type_id' => 2]);
        $this->seedMessage(904, $sender->id, ['is_send' => 1, 'return_message_id' => 903, 'thread_message_id' => 903]);

        $this->runUpgrade();

        $this->assertDatabaseHas('messages', ['id' => 901, 'parent_id' => 900, 'thread_id' => 900]);
        $this->assertDatabaseHas('messages', ['id' => 902, 'parent_id' => null, 'thread_id' => null]);
        $this->assertDatabaseHas('messages', ['id' => 904, 'parent_id' => null, 'thread_id' => null]);
    }

    public function test_a_trashed_sender_message_sets_deleted_at_from_the_pointer(): void
    {
        $sender = Member::factory()->create();
        $this->seedMessage(1000, $sender->id, ['is_send' => 1, 'is_deleted' => 1, 'updated_at' => '2020-01-01 00:00:00']);
        // Trash pointer, not yet purged: created_at = when it was moved to trash.
        $this->seedDeletedMessage(1, $sender->id, messageId: 1000, isDeleted: 0, createdAt: '2020-02-02 02:02:02', updatedAt: '2020-02-02 02:02:02');

        $this->runUpgrade();

        $this->assertDatabaseHas('messages', [
            'id' => 1000,
            'sender_deleted_at' => '2020-02-02 02:02:02',
            'sender_purged_at' => null,
        ]);
    }

    public function test_a_purged_sender_message_sets_deleted_and_purged_at(): void
    {
        $sender = Member::factory()->create();
        $this->seedMessage(1001, $sender->id, ['is_send' => 1, 'is_deleted' => 1]);
        // Pointer purged: created_at = trashed, updated_at = purged.
        $this->seedDeletedMessage(2, $sender->id, messageId: 1001, isDeleted: 1, createdAt: '2020-03-03 03:03:03', updatedAt: '2020-04-04 04:04:04');

        $this->runUpgrade();

        $this->assertDatabaseHas('messages', [
            'id' => 1001,
            'sender_deleted_at' => '2020-03-03 03:03:03',
            'sender_purged_at' => '2020-04-04 04:04:04',
        ]);
    }

    public function test_is_deleted_without_a_pointer_falls_back_to_updated_at(): void
    {
        $sender = Member::factory()->create();
        // Anomalous: is_deleted=1 with no trash pointer. Keep it out of the active boxes (deleted_at
        // set) without inventing a purge.
        $this->seedMessage(1002, $sender->id, ['is_send' => 1, 'is_deleted' => 1, 'updated_at' => '2021-05-05 05:05:05']);

        $this->runUpgrade();

        $this->assertDatabaseHas('messages', [
            'id' => 1002,
            'sender_deleted_at' => '2021-05-05 05:05:05',
            'sender_purged_at' => null,
        ]);
    }

    private function runUpgrade(): void
    {
        DB::statement((new InsertSelectCompiler)->compile(new MessageUpgrade));
    }

    private function seedType(int $id, string $typeName): void
    {
        DB::table('message_type')->insert([
            'id' => $id,
            'type_name' => $typeName,
            'foreign_table' => null,
            'is_deleted' => 0,
            'created_at' => '2016-01-01 00:00:00',
            'updated_at' => '2016-01-01 00:00:00',
        ]);
    }

    private function seedMessage(int $id, ?int $memberId, array $overrides = []): void
    {
        DB::table('message')->insert(array_merge([
            'id' => $id,
            'member_id' => $memberId,
            'subject' => 'Subject',
            'body' => 'Body',
            'is_deleted' => 0,
            'is_send' => 0,
            'thread_message_id' => 0,
            'return_message_id' => 0,
            'message_type_id' => 1,
            'foreign_id' => 0,
            'created_at' => '2018-01-01 00:00:00',
            'updated_at' => '2018-01-01 00:00:00',
        ], $overrides));
    }

    private function seedSendList(int $id, ?int $memberId, int $messageId, array $overrides = []): void
    {
        DB::table('message_send_list')->insert(array_merge([
            'id' => $id,
            'member_id' => $memberId,
            'message_id' => $messageId,
            'is_read' => 0,
            'is_deleted' => 0,
            'created_at' => '2018-01-01 00:00:00',
            'updated_at' => '2018-01-01 00:00:00',
        ], $overrides));
    }

    private function seedDeletedMessage(int $id, ?int $memberId, int $messageId = 0, int $messageSendListId = 0, int $isDeleted = 0, string $createdAt = '2020-01-01 00:00:00', string $updatedAt = '2020-01-01 00:00:00'): void
    {
        DB::table('deleted_message')->insert([
            'id' => $id,
            'member_id' => $memberId,
            'message_id' => $messageId,
            'message_send_list_id' => $messageSendListId,
            'is_deleted' => $isDeleted,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ]);
    }
}
