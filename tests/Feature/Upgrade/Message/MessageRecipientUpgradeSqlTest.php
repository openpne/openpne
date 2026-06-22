<?php

namespace Tests\Feature\Upgrade\Message;

use App\Models\Member;
use App\Models\MessageRecipient;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\MessageRecipientUpgrade;
use App\Upgrade\Steps\MessageUpgrade;
use App\Upgrade\UpgradeStep;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Runs the compiled `message_send_list` → `message_recipients` INSERT...SELECT against the real
 * OpenPNE 3 DDL. MessageUpgrade runs first to populate the `messages` parent (the message_id FK) and
 * to fold draft recipients away. Checks that a receipt is created only for a delivered personal
 * parent (the "receipt == delivered" invariant), the is_read→read_at and deleted_message folds, and
 * that draft / orphan / non-personal send-list rows are quarantined.
 *
 * MySQL only: the set-based copy, the source DDL and the correlated subqueries are MySQL features.
 */
class MessageRecipientUpgradeSqlTest extends TestCase
{
    use DatabaseMigrations;

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

    public function test_migrates_a_delivered_receipt_preserving_id_and_timestamps(): void
    {
        $sender = Member::factory()->create();
        $recipient = Member::factory()->create();
        $this->seedMessage(500, $sender->id, ['is_send' => 1]);
        $this->seedSendList(900, $recipient->id, 500, [
            'created_at' => '2018-03-04 12:34:56',
            'updated_at' => '2019-06-07 01:02:03',
        ]);

        $this->runUpgrade();

        $this->assertDatabaseHas('message_recipients', [
            'id' => 900,
            'message_id' => 500,
            'recipient_id' => $recipient->id,
            'read_at' => null,
            'recipient_deleted_at' => null,
            'recipient_purged_at' => null,
            'created_at' => '2018-03-04 12:34:56',
            'updated_at' => '2019-06-07 01:02:03',
        ]);
    }

    public function test_a_read_receipt_sets_read_at(): void
    {
        $sender = Member::factory()->create();
        $recipient = Member::factory()->create();
        $this->seedMessage(501, $sender->id, ['is_send' => 1]);
        $this->seedSendList(901, $recipient->id, 501, ['is_read' => 1, 'updated_at' => '2019-09-09 09:09:09']);

        $this->runUpgrade();

        $this->assertDatabaseHas('message_recipients', ['id' => 901, 'read_at' => '2019-09-09 09:09:09']);
    }

    public function test_a_draft_send_list_row_creates_no_receipt(): void
    {
        // The draft's recipient is folded onto messages.draft_recipient_id; no receipt exists (a
        // receipt means delivered). The recipient-side query never reaches a draft.
        $sender = Member::factory()->create();
        $recipient = Member::factory()->create();
        $this->seedMessage(600, $sender->id, ['is_send' => 0]);
        $this->seedSendList(910, $recipient->id, 600);

        $this->runUpgrade();

        $this->assertDatabaseMissing('message_recipients', ['id' => 910]);
    }

    public function test_an_orphan_send_list_row_is_quarantined(): void
    {
        $recipient = Member::factory()->create();
        // No parent message id=99999, so the message_id FK could not hold; the filter drops it.
        $this->seedSendList(920, $recipient->id, 99999);

        $this->runUpgrade();

        $this->assertDatabaseMissing('message_recipients', ['id' => 920]);
    }

    public function test_a_non_personal_parent_send_list_is_skipped(): void
    {
        $sender = Member::factory()->create();
        $recipient = Member::factory()->create();
        $this->seedMessage(700, $sender->id, ['is_send' => 1, 'message_type_id' => 2]); // friend_link
        $this->seedSendList(930, $recipient->id, 700);

        $this->runUpgrade();

        $this->assertDatabaseMissing('message_recipients', ['id' => 930]);
    }

    public function test_a_trashed_recipient_sets_deleted_at_from_the_pointer(): void
    {
        $sender = Member::factory()->create();
        $recipient = Member::factory()->create();
        $this->seedMessage(510, $sender->id, ['is_send' => 1]);
        $this->seedSendList(940, $recipient->id, 510, ['is_deleted' => 1, 'updated_at' => '2020-01-01 00:00:00']);
        $this->seedDeletedMessage(1, $recipient->id, messageSendListId: 940, isDeleted: 0, createdAt: '2020-02-02 02:02:02', updatedAt: '2020-02-02 02:02:02');

        $this->runUpgrade();

        $this->assertDatabaseHas('message_recipients', [
            'id' => 940,
            'recipient_deleted_at' => '2020-02-02 02:02:02',
            'recipient_purged_at' => null,
        ]);
    }

    public function test_a_purged_recipient_sets_deleted_and_purged_at(): void
    {
        $sender = Member::factory()->create();
        $recipient = Member::factory()->create();
        $this->seedMessage(511, $sender->id, ['is_send' => 1]);
        $this->seedSendList(941, $recipient->id, 511, ['is_deleted' => 1]);
        $this->seedDeletedMessage(2, $recipient->id, messageSendListId: 941, isDeleted: 1, createdAt: '2020-03-03 03:03:03', updatedAt: '2020-04-04 04:04:04');

        $this->runUpgrade();

        $this->assertDatabaseHas('message_recipients', [
            'id' => 941,
            'recipient_deleted_at' => '2020-03-03 03:03:03',
            'recipient_purged_at' => '2020-04-04 04:04:04',
        ]);
    }

    public function test_a_withdrawn_recipient_keeps_the_receipt_with_null_member(): void
    {
        // OpenPNE 3 sets member_id NULL when the recipient withdraws but keeps the row.
        $sender = Member::factory()->create();
        $this->seedMessage(520, $sender->id, ['is_send' => 1]);
        $this->seedSendList(950, null, 520);

        $this->runUpgrade();

        $this->assertNull(MessageRecipient::findOrFail(950)->recipient_id);
    }

    private function runUpgrade(): void
    {
        $this->runStep(new MessageUpgrade);
        $this->runStep(new MessageRecipientUpgrade);
    }

    private function runStep(UpgradeStep $step): void
    {
        DB::statement((new InsertSelectCompiler)->compile($step));
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
