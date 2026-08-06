<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\File;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Pins the two schema facts that are load-bearing rather than stylistic: `image_file_id` matches
 * `files.id`'s signed INT, and the table can be dropped again.
 *
 * The type is not a preference. `files.id` is a signed INT so the upgrade tool can rewire `file_bin`
 * by metadata alone instead of copying gigabytes of BLOBs (see create_files_table), and `foreignId()`
 * would emit BIGINT UNSIGNED, which MySQL refuses to constrain against it. SQLite accepts either, so
 * without this the mismatch would only appear on a real deployment.
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

    public function test_the_table_round_trips(): void
    {
        // down() drops the foreign key before the table so InnoDB does not refuse the index it
        // adopted for the constraint (errno 1553). SQLite cannot show that, but this pins that
        // up/down/up does not error on either lane.
        $migration = require database_path('migrations/2026_08_06_000001_create_link_cards_table.php');

        $this->assertTrue(Schema::hasTable('link_cards'));

        $migration->down();
        $this->assertFalse(Schema::hasTable('link_cards'));

        $migration->up();
        $this->assertTrue(Schema::hasTable('link_cards'));
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
