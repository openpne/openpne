<?php

namespace Tests\Feature\Schema;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Runs the board migration against a populated pre-migration schema. No RefreshDatabase: the
 * migration's own DDL is the subject, and a wrapping transaction would hide how the engine treats it.
 */
class BoardBumpedAtMigrationTest extends TestCase
{
    private const MIGRATION = '2026_09_11_000001_replace_board_activity_key_with_bumped_at';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('The rebuild and its sequence are SQLite behaviour; MySQL modifies the column in place.');
        }

        Artisan::call('migrate:fresh', ['--force' => true]);
        Artisan::call('migrate:rollback', ['--force' => true, '--step' => 1]);
        DB::table('members')->insert(['id' => 1, 'name' => 'm', 'created_at' => '2018-01-01 00:00:00', 'updated_at' => '2018-01-01 00:00:00']);
        DB::table('groups')->insert(['id' => 1, 'name' => 'g', 'created_at' => '2018-01-01 00:00:00', 'updated_at' => '2018-01-01 00:00:00']);
    }

    protected function tearDown(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);

        parent::tearDown();
    }

    public function test_the_backfill_lands_on_the_last_comment_and_a_deleted_id_is_never_reissued(): void
    {
        foreach ([1, 2, 3] as $id) {
            $this->topic($id, '2018-01-0'.$id.' 09:00:00');
        }
        DB::table('group_topic_comments')->insert(['group_topic_id' => 1, 'member_id' => 1, 'number' => 1, 'body' => 'x', 'created_at' => '2018-03-01 09:00:00', 'updated_at' => '2018-03-01 09:00:00']);
        DB::table('group_topics')->where('id', 3)->delete();

        Artisan::call('migrate', ['--force' => true]);

        $this->assertSame(['1' => '2018-03-01 09:00:00', '2' => '2018-01-02 09:00:00'], DB::table('group_topics')->orderBy('id')->pluck('bumped_at', 'id')->all());
        $this->assertSame(4, DB::table('group_topics')->insertGetId(['group_id' => 1, 'member_id' => 1, 'name' => 'n', 'body' => 'b', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00', 'bumped_at' => '2026-01-01 00:00:00']));
        $this->assertSame([['bumped_at', 'id'], ['group_id', 'bumped_at']], array_values(array_filter(array_map(fn (array $i) => $i['columns'], Schema::getIndexes('group_topics')), fn (array $c) => in_array('bumped_at', $c, true))));
        $this->assertFalse(Schema::hasColumn('group_topics', 'topic_updated_at'));
    }

    public function test_rolling_back_folds_a_later_bump_into_updated_at_even_when_it_was_null(): void
    {
        $this->topic(1, '2018-01-01 09:00:00');
        Artisan::call('migrate', ['--force' => true]);
        DB::table('group_topics')->where('id', 1)->update(['bumped_at' => '2026-09-11 09:00:00', 'updated_at' => null]);

        Artisan::call('migrate:rollback', ['--force' => true, '--step' => 1]);

        $this->assertSame('2026-09-11 09:00:00', DB::table('group_topics')->where('id', 1)->value('updated_at'));
        $this->assertTrue(Schema::hasColumn('group_topics', 'topic_updated_at'));
    }

    private function topic(int $id, string $createdAt): void
    {
        DB::table('group_topics')->insert(['id' => $id, 'group_id' => 1, 'member_id' => 1, 'name' => 'a', 'body' => 'b', 'created_at' => $createdAt, 'updated_at' => $createdAt, 'topic_updated_at' => null]);
    }
}
