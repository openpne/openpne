<?php

namespace Tests\Feature\Upgrade\File;

use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\FileUpgrade;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MigratesUpgradeTargetsOnce;
use Tests\Concerns\SeedsSourceMembers;
use Tests\TestCase;

/**
 * Runs the compiled `file` step against the real OpenPNE 3 DDL, with every owning table the owner CASE
 * reads created from the dump; MySQL only.
 */
class FileUpgradeSqlTest extends TestCase
{
    use MigratesUpgradeTargetsOnce, SeedsSourceMembers;

    /** FileUpgrade's FROM table plus every table its owner CASE reads, created from the real dump. */
    private array $sourceTables = [
        'member',
        'file',
        'member_image',
        'community',
        'diary_image',
        'diary_comment_image',
        'community_topic_image',
        'community_topic_comment_image',
        'community_event_image',
        'community_event_comment_image',
        'message_file',
        'message',
        'message_type',
        'banner_image',
        'activity_image',
        'activity_data',
    ];

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

        // Personal-message type (owns its attachment) and a notification type (does not).
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

    public function test_copies_metadata_with_id_and_name_verbatim(): void
    {
        $this->seedFile(10, [
            'name' => 'm_5_abcdef0123456789',
            'type' => 'image/jpeg',
            'filesize' => 4096,
            'original_filename' => 'photo.jpg',
            'created_at' => '2017-01-02 03:04:05',
            'updated_at' => '2018-02-03 04:05:06',
        ]);

        $this->runUpgrade();

        $this->assertDatabaseHas('files', [
            'id' => 10,
            'name' => 'm_5_abcdef0123456789',
            'type' => 'image/jpeg',
            'original_filename' => 'photo.jpg',
            'byte_size' => 4096,
            'explicit_visibility' => null,
            'related_entity_type' => null,
            'related_entity_id' => null,
            'created_at' => '2017-01-02 03:04:05',
            'updated_at' => '2018-02-03 04:05:06',
        ]);
    }

    public function test_browser_declared_image_types_become_the_types_this_version_shows(): void
    {
        // OpenPNE 3 fell back to the browser's type when its guesser failed; these rows were shown
        // as attachments, never as pictures.
        $this->seedFile(20, ['type' => 'image/pjpeg']);
        $this->seedFile(21, ['type' => 'IMAGE/X-PNG']);
        $this->seedFile(22, ['type' => 'image/gif']);
        $this->seedFile(23, ['type' => 'application/pdf']);

        $this->runUpgrade();

        $this->assertDatabaseHas('files', ['id' => 20, 'type' => 'image/jpeg']);
        $this->assertDatabaseHas('files', ['id' => 21, 'type' => 'image/png']);
        $this->assertDatabaseHas('files', ['id' => 22, 'type' => 'image/gif']);
        $this->assertDatabaseHas('files', ['id' => 23, 'type' => 'application/pdf']);
    }

    public function test_resolves_member_avatar_owner(): void
    {
        $this->seedFile(11);
        $this->seedSourceMember(77, isActive: 1);
        DB::table('member_image')->insert(['id' => 1, 'member_id' => 77, 'file_id' => 11, 'is_primary' => 1, 'created_at' => '2017-01-01 00:00:00', 'updated_at' => '2017-01-01 00:00:00']);

        $this->runUpgrade();

        $this->assertDatabaseHas('files', ['id' => 11, 'related_entity_type' => 'member', 'related_entity_id' => 77]);
    }

    public function test_an_inactive_members_avatar_is_migrated_ownerless(): void
    {
        // MemberImageUpgrade drops the join row for a member the upgrade skips, so claiming the
        // owner here would point related_entity_id at a member that never lands.
        $this->seedFile(12);
        $this->inactiveSourceMember(78);
        DB::table('member_image')->insert(['id' => 2, 'member_id' => 78, 'file_id' => 12, 'is_primary' => 1, 'created_at' => '2017-01-01 00:00:00', 'updated_at' => '2017-01-01 00:00:00']);

        $this->runUpgrade();

        $this->assertDatabaseHas('files', ['id' => 12, 'related_entity_type' => null, 'related_entity_id' => null]);
    }

    public function test_resolves_group_top_image_owner(): void
    {
        $this->seedFile(14);
        // The group top image is a direct column (community.file_id), so the owner is the group
        // itself — related_entity_id is the group id, not a join-row id.
        DB::table('community')->insert(['id' => 55, 'name' => 'Photo Club', 'file_id' => 14, 'created_at' => '2017-01-01 00:00:00', 'updated_at' => '2017-01-01 00:00:00']);

        $this->runUpgrade();

        $this->assertDatabaseHas('files', ['id' => 14, 'related_entity_type' => 'group', 'related_entity_id' => 55]);
    }

    public function test_resolves_diary_image_owner(): void
    {
        $this->seedFile(12);
        DB::table('diary_image')->insert(['id' => 1, 'diary_id' => 88, 'file_id' => 12, 'number' => 1]);

        $this->runUpgrade();

        $this->assertDatabaseHas('files', ['id' => 12, 'related_entity_type' => 'diary', 'related_entity_id' => 88]);
    }

    public function test_resolves_diary_comment_image_owner(): void
    {
        $this->seedFile(13);
        // diary_comment_image has no `number` column (unlike the other image tables).
        DB::table('diary_comment_image')->insert(['id' => 1, 'diary_comment_id' => 99, 'file_id' => 13]);

        $this->runUpgrade();

        $this->assertDatabaseHas('files', ['id' => 13, 'related_entity_type' => 'diaryComment', 'related_entity_id' => 99]);
    }

    public function test_resolves_group_topic_and_event_owners(): void
    {
        $this->seedFile(20);
        $this->seedFile(21);
        $this->seedFile(22);
        $this->seedFile(23);
        DB::table('community_topic_image')->insert(['id' => 1, 'post_id' => 200, 'file_id' => 20, 'number' => 1]);
        DB::table('community_topic_comment_image')->insert(['id' => 1, 'post_id' => 201, 'file_id' => 21, 'number' => 1]);
        DB::table('community_event_image')->insert(['id' => 1, 'post_id' => 202, 'file_id' => 22, 'number' => 1]);
        DB::table('community_event_comment_image')->insert(['id' => 1, 'post_id' => 203, 'file_id' => 23, 'number' => 1]);

        $this->runUpgrade();

        $this->assertDatabaseHas('files', ['id' => 20, 'related_entity_type' => 'groupTopic', 'related_entity_id' => 200]);
        $this->assertDatabaseHas('files', ['id' => 21, 'related_entity_type' => 'groupTopicComment', 'related_entity_id' => 201]);
        $this->assertDatabaseHas('files', ['id' => 22, 'related_entity_type' => 'groupEvent', 'related_entity_id' => 202]);
        $this->assertDatabaseHas('files', ['id' => 23, 'related_entity_type' => 'groupEventComment', 'related_entity_id' => 203]);
    }

    public function test_resolves_banner_image_owner_as_the_image_row(): void
    {
        $this->seedFile(30);
        // The banner image row itself is the owner (related_entity_id = banner_image.id, not a banner).
        DB::table('banner_image')->insert(['id' => 9, 'file_id' => 30, 'url' => null, 'name' => 'promo', 'created_at' => '2017-01-01 00:00:00', 'updated_at' => '2017-01-01 00:00:00']);

        $this->runUpgrade();

        $this->assertDatabaseHas('files', ['id' => 30, 'related_entity_type' => 'bannerImage', 'related_entity_id' => 9]);
    }

    public function test_owns_a_personal_message_attachment(): void
    {
        $this->seedFile(40);
        $this->seedMessage(500, messageTypeId: 1);
        DB::table('message_file')->insert(['id' => 1, 'message_id' => 500, 'file_id' => 40, 'created_at' => '2017-01-01 00:00:00', 'updated_at' => '2017-01-01 00:00:00']);

        $this->runUpgrade();

        $this->assertDatabaseHas('files', ['id' => 40, 'related_entity_type' => 'directMessage', 'related_entity_id' => 500]);
    }

    public function test_a_non_personal_message_attachment_is_migrated_ownerless(): void
    {
        $this->seedFile(41);
        $this->seedMessage(501, messageTypeId: 2); // friend_link notification, not migrated
        DB::table('message_file')->insert(['id' => 1, 'message_id' => 501, 'file_id' => 41, 'created_at' => '2017-01-01 00:00:00', 'updated_at' => '2017-01-01 00:00:00']);

        $this->runUpgrade();

        $this->assertDatabaseHas('files', ['id' => 41, 'related_entity_type' => null, 'related_entity_id' => null]);
    }

    public function test_an_activity_image_is_owned_by_where_its_thread_lands(): void
    {
        DB::table('community')->insert(['id' => 5, 'name' => 'c', 'file_id' => null, 'community_category_id' => null, 'created_at' => '2016-01-01 00:00:00', 'updated_at' => '2016-01-01 00:00:00']);
        // 7: a timeline thread; 8: a reply in a community thread; 9: a thread scoped to a diary (not migrated).
        $this->seedActivity(7, null);
        $this->seedActivity(70, 'community', 5);
        $this->seedActivity(8, null, replyTo: 70);
        $this->seedActivity(9, 'diary', 1);
        $this->seedActivity(10, 'community', 5, publicFlag: 2); // a friends-only community thread has no talk landing
        foreach ([[1, 7, 50], [2, 8, 51], [3, 9, 52], [4, 10, 53]] as [$id, $activityId, $fileId]) {
            $this->seedFile($fileId);
            DB::table('activity_image')->insert(['id' => $id, 'activity_data_id' => $activityId, 'mime_type' => 'image/png', 'uri' => null, 'file_id' => $fileId, 'created_at' => '2016-01-01 00:00:00', 'updated_at' => '2016-01-01 00:00:00']);
        }

        $this->runUpgrade();

        $this->assertDatabaseHas('files', ['id' => 50, 'related_entity_type' => 'timelinePost', 'related_entity_id' => 7]);
        $this->assertDatabaseHas('files', ['id' => 51, 'related_entity_type' => 'groupMessage', 'related_entity_id' => 8]);
        // No arm claims it, so the FileUpgrade fail-closed default stays.
        $this->assertDatabaseHas('files', ['id' => 52, 'related_entity_type' => null, 'related_entity_id' => null]);
        $this->assertDatabaseHas('files', ['id' => 53, 'related_entity_type' => null, 'related_entity_id' => null]);
    }

    private function seedActivity(int $id, ?string $foreignTable, ?int $foreignId = null, ?int $replyTo = null, int $publicFlag = 1): void
    {
        DB::table('activity_data')->insert([
            'id' => $id, 'member_id' => 1, 'in_reply_to_activity_id' => $replyTo, 'body' => 'b', 'uri' => null, 'public_flag' => $publicFlag,
            'is_pc' => 1, 'is_mobile' => 1, 'source' => null, 'source_uri' => null, 'foreign_table' => $foreignTable, 'foreign_id' => $foreignId,
            'template' => null, 'template_param' => null, 'created_at' => '2016-01-01 00:00:00', 'updated_at' => '2016-01-01 00:00:00',
        ]);
    }

    private function runUpgrade(): void
    {
        DB::statement((new InsertSelectCompiler)->compile(new FileUpgrade));
    }

    private function seedFile(int $id, array $overrides = []): void
    {
        DB::table('file')->insert(array_merge([
            'id' => $id,
            'name' => "tok_{$id}",
            'type' => 'image/png',
            'filesize' => 128,
            'original_filename' => null,
            'created_at' => '2016-01-01 00:00:00',
            'updated_at' => '2016-01-01 00:00:00',
        ], $overrides));
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

    private function seedMessage(int $id, int $messageTypeId): void
    {
        DB::table('message')->insert([
            'id' => $id,
            'member_id' => 1,
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
}
