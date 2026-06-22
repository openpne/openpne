<?php

namespace Tests\Feature\Upgrade\File;

use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\FileUpgrade;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Runs the compiled `file` → `files` INSERT...SELECT against the real OpenPNE 3 DDL, checking the
 * metadata copy (id/name verbatim, filesize→byte_size) and the owner resolution: each owning table
 * sets the right morph alias + id, a non-personal message attachment and a file only an unmigrated
 * surface points at stay ownerless, and every `file` row migrates regardless so no binary is lost.
 *
 * MySQL only: the set-based copy, the source DDL and the correlated owner subqueries are MySQL features.
 */
class FileUpgradeSqlTest extends TestCase
{
    use DatabaseMigrations;

    /** FileUpgrade's FROM table plus every table its owner CASE reads, created from the real dump. */
    private array $sourceTables = [
        'file',
        'member_image',
        'community_topic_image',
        'community_topic_comment_image',
        'community_event_image',
        'community_event_comment_image',
        'message_file',
        'message',
        'message_type',
        'banner_image',
        // Not read by the owner CASE; seeded to show a file only an unowned surface points at stays
        // ownerless (community.file_id / oauth_consumer.file_id behave the same — see the matrix audit).
        'diary_image',
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

    public function test_resolves_member_avatar_owner(): void
    {
        $this->seedFile(11);
        DB::table('member_image')->insert(['id' => 1, 'member_id' => 77, 'file_id' => 11, 'is_primary' => 1, 'created_at' => '2017-01-01 00:00:00', 'updated_at' => '2017-01-01 00:00:00']);

        $this->runUpgrade();

        $this->assertDatabaseHas('files', ['id' => 11, 'related_entity_type' => 'member', 'related_entity_id' => 77]);
    }

    public function test_resolves_community_topic_and_event_owners(): void
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

        $this->assertDatabaseHas('files', ['id' => 20, 'related_entity_type' => 'communityTopic', 'related_entity_id' => 200]);
        $this->assertDatabaseHas('files', ['id' => 21, 'related_entity_type' => 'communityTopicComment', 'related_entity_id' => 201]);
        $this->assertDatabaseHas('files', ['id' => 22, 'related_entity_type' => 'communityEvent', 'related_entity_id' => 202]);
        $this->assertDatabaseHas('files', ['id' => 23, 'related_entity_type' => 'communityEventComment', 'related_entity_id' => 203]);
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

        $this->assertDatabaseHas('files', ['id' => 40, 'related_entity_type' => 'message', 'related_entity_id' => 500]);
    }

    public function test_a_non_personal_message_attachment_is_migrated_ownerless(): void
    {
        $this->seedFile(41);
        $this->seedMessage(501, messageTypeId: 2); // friend_link notification, not migrated
        DB::table('message_file')->insert(['id' => 1, 'message_id' => 501, 'file_id' => 41, 'created_at' => '2017-01-01 00:00:00', 'updated_at' => '2017-01-01 00:00:00']);

        $this->runUpgrade();

        $this->assertDatabaseHas('files', ['id' => 41, 'related_entity_type' => null, 'related_entity_id' => null]);
    }

    public function test_a_file_only_an_unowned_surface_points_at_is_migrated_ownerless(): void
    {
        $this->seedFile(50);
        DB::table('diary_image')->insert(['id' => 1, 'diary_id' => 7, 'file_id' => 50, 'number' => 1]);

        $this->runUpgrade();

        $this->assertDatabaseHas('files', ['id' => 50, 'related_entity_type' => null, 'related_entity_id' => null]);
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
