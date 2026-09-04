<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\LinkCard\CardContext;
use App\Models\File;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Pins that `image_file_id` matches `files.id`'s signed INT: `files.id` is signed so the upgrade tool
 * can rewire `file_bin` by metadata alone (create_files_table), and `foreignId()` would emit BIGINT
 * UNSIGNED, which MySQL refuses to constrain against it. SQLite accepts either, so the mismatch would
 * only appear on a real deployment.
 */
class LinkCardsTableTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_image_foreign_key_matches_the_files_primary_key(): void
    {
        $card = $this->column('link_cards', 'image_file_id');
        $file = $this->column('files', 'id');

        $this->assertNotNull($card);
        $this->assertNotNull($file);
        $this->assertSame(
            $this->typeOf($file),
            $this->typeOf($card),
            'image_file_id must be declared with the same type and signedness as files.id.',
        );
    }

    public function test_the_foreign_key_constraint_exists_and_nulls_on_delete(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            // SQLite reports foreign keys but the interesting part — that MySQL accepted the
            // constraint across a signed INT — is only observable on the MySQL lane.
            $this->markTestSkipped('Foreign-key acceptance is a MySQL concern.');
        }

        $foreignKeys = collect(Schema::getForeignKeys('link_cards'))
            ->firstWhere(fn (array $key): bool => $key['columns'] === ['image_file_id']);

        $this->assertNotNull($foreignKeys, 'link_cards.image_file_id has no foreign key.');
        $this->assertSame('files', $foreignKeys['foreign_table']);
        $this->assertSame('set null', strtolower((string) $foreignKeys['on_delete']));
    }

    public function test_deleting_a_file_clears_the_reference_rather_than_the_card(): void
    {
        $file = File::factory()->create();
        DB::table('link_cards')->insert([
            'url_hash' => str_repeat('b', 64),
            'url' => 'https://example.com/',
            'status' => 'ok',
            'image_file_id' => $file->id,
        ]);

        $file->delete();

        $this->assertDatabaseHas('link_cards', ['url_hash' => str_repeat('b', 64), 'image_file_id' => null]);
    }

    public function test_the_link_card_key_is_indexed_on_every_body_table(): void
    {
        // InnoDB backs every foreign key with an index and SQLite does not, so the migration adds one
        // only on SQLite; the tables come from CardContext so a kind added there is asserted here too.
        foreach (array_map(fn (CardContext $context): string => $context->table(), CardContext::cases()) as $table) {
            $this->assertTrue(
                Schema::hasIndex($table, ['link_card_id']),
                "{$table}.link_card_id is not indexed; the prune sweep degrades to a full scan.",
            );
        }
    }

    public function test_the_tables_round_trip(): void
    {
        $create = require database_path('migrations/2026_08_06_000001_create_link_cards_table.php');
        $attach = require database_path('migrations/2026_08_06_000002_add_link_card_to_body_tables.php');
        $index = require database_path('migrations/2026_08_07_000001_index_link_card_id_on_sqlite.php');
        $talk = require database_path('migrations/2026_08_21_000001_add_link_card_to_group_messages.php');
        $comments = require database_path('migrations/2026_08_21_000002_add_link_card_to_comment_tables.php');
        $internal = require database_path('migrations/2026_08_25_000001_add_internal_pointer_to_link_cards.php');

        $this->assertTrue(Schema::hasTable('link_cards'));
        $this->assertTrue(Schema::hasColumn('diaries', 'link_card_id'));

        // Newest first and every one of them: MySQL refuses to drop a table another still references
        // (errno 3730), SQLite refuses to drop a column an index still names, and each lane passes
        // the other's failure.
        $internal->down();
        $comments->down();
        $talk->down();
        $index->down();
        $attach->down();
        $this->assertFalse(Schema::hasColumn('diaries', 'link_card_id'));
        $this->assertFalse(Schema::hasColumn('group_messages', 'link_card_id'));
        $this->assertFalse(Schema::hasColumn('diary_comments', 'link_card_id'));
        $this->assertFalse(Schema::hasColumn('link_cards', 'internal_context'));

        $create->down();
        $this->assertFalse(Schema::hasTable('link_cards'));

        $create->up();
        $attach->up();
        $index->up();
        $talk->up();
        $comments->up();
        $internal->up();

        $this->assertTrue(Schema::hasTable('link_cards'));
        $this->assertTrue(Schema::hasColumn('diaries', 'link_card_id'));
        $this->assertTrue(Schema::hasColumn('timeline_posts', 'link_card_synced_at'));
        $this->assertTrue(Schema::hasColumn('group_messages', 'link_card_synced_at'));
        $this->assertTrue(Schema::hasColumn('diary_comments', 'link_card_synced_at'));
        $this->assertTrue(Schema::hasColumn('link_cards', 'internal_record_id'));
    }

    private function column(string $table, string $name): ?array
    {
        return collect(Schema::getColumns($table))->firstWhere('name', $name);
    }

    /** Type and signedness together, since either alone would let a mismatch through. */
    private function typeOf(array $column): string
    {
        return strtolower($column['type']);
    }
}
