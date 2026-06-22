<?php

namespace Tests\Feature\Upgrade\Message;

use App\Models\Member;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\MessageFileUpgrade;
use App\Upgrade\Steps\MessageUpgrade;
use App\Upgrade\UpgradeStep;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Runs the compiled `message_file` → `message_files` INSERT...SELECT against the real OpenPNE 3 DDL,
 * after MessageUpgrade has populated the parent messages. Checks the synthesized 1..N slot (OpenPNE 3
 * has no slot column) and that an attachment on a non-personal message type is dropped (those messages
 * are not migrated).
 *
 * MySQL only: the set-based copy, the source DDL and the correlated subqueries are MySQL features.
 */
class MessageFileUpgradeSqlTest extends TestCase
{
    use DatabaseMigrations;

    private array $sourceTables = ['message_file', 'message', 'message_send_list', 'deleted_message', 'message_type'];

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

    public function test_synthesizes_slot_numbers_and_drops_non_personal_attachments(): void
    {
        $member = Member::factory()->create();
        $this->seedMessage(500, $member->id, messageTypeId: 1); // personal, migrated
        $this->seedMessage(501, $member->id, messageTypeId: 2); // friend_link, not migrated
        $this->runUpgrade(new MessageUpgrade);

        $this->seedFile(10);
        $this->seedFile(11);
        $this->seedFile(12);
        $this->seedMessageFile(1, 500, 10);
        $this->seedMessageFile(2, 500, 11);
        $this->seedMessageFile(3, 501, 12); // parent not personal → dropped

        $this->runUpgrade(new MessageFileUpgrade);

        $this->assertDatabaseCount('message_files', 2);
        $this->assertDatabaseHas('message_files', ['id' => 1, 'message_id' => 500, 'file_id' => 10, 'number' => 1]);
        $this->assertDatabaseHas('message_files', ['id' => 2, 'message_id' => 500, 'file_id' => 11, 'number' => 2]);
        $this->assertDatabaseMissing('message_files', ['file_id' => 12]);
    }

    private function runUpgrade(UpgradeStep $step): void
    {
        DB::statement((new InsertSelectCompiler)->compile($step));
    }

    private function seedFile(int $id): void
    {
        DB::table('files')->insert([
            'id' => $id,
            'name' => "tok_{$id}",
            'type' => 'image/png',
            'byte_size' => 128,
            'created_at' => '2016-01-01 00:00:00',
            'updated_at' => '2016-01-01 00:00:00',
        ]);
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

    private function seedMessage(int $id, int $memberId, int $messageTypeId): void
    {
        DB::table('message')->insert([
            'id' => $id,
            'member_id' => $memberId,
            'subject' => 'Subject',
            'body' => 'Body',
            'is_deleted' => 0,
            'is_send' => 1,
            'thread_message_id' => 0,
            'return_message_id' => 0,
            'message_type_id' => $messageTypeId,
            'foreign_id' => 0,
            'created_at' => '2016-01-01 00:00:00',
            'updated_at' => '2016-01-01 00:00:00',
        ]);
    }

    private function seedMessageFile(int $id, int $messageId, int $fileId): void
    {
        DB::table('message_file')->insert([
            'id' => $id,
            'message_id' => $messageId,
            'file_id' => $fileId,
            'created_at' => '2016-01-01 00:00:00',
            'updated_at' => '2016-01-01 00:00:00',
        ]);
    }
}
