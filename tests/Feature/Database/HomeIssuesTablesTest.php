<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Features\Home\HomeIssueSection;
use App\Models\Diary;
use App\Models\HomeIssue;
use App\Models\HomeIssueItem;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The ledger's schema-level guarantees, which are the ones no code can restore once they are gone:
 * one issue per date, one rank per section, and a reference that outlives what it points at.
 */
class HomeIssuesTablesTest extends TestCase
{
    use RefreshDatabase;

    public function test_both_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('home_issues'));
        $this->assertTrue(Schema::hasTable('home_issue_items'));
    }

    public function test_a_date_may_only_be_published_once(): void
    {
        // The idempotency guarantee: a second run for the same day is refused by the database, so a
        // retry or an overlapping trigger cannot publish twice.
        $first = HomeIssue::factory()->create();

        $this->expectException(QueryException::class);
        HomeIssue::factory()->create(['issue_date' => $first->issue_date]);
    }

    public function test_a_number_may_only_be_used_once(): void
    {
        $first = HomeIssue::factory()->create();

        $this->expectException(QueryException::class);
        HomeIssue::factory()->create(['number' => $first->number]);
    }

    public function test_a_rank_is_held_by_one_item_per_section(): void
    {
        $issue = HomeIssue::factory()->create();
        HomeIssueItem::factory()->for($issue, 'issue')->create([
            'section' => HomeIssueSection::Stories,
            'rank' => 1,
        ]);

        // The same rank in a different section is fine — the sections are ranked independently.
        HomeIssueItem::factory()->for($issue, 'issue')->create([
            'section' => HomeIssueSection::Newcomers,
            'rank' => 1,
        ]);

        $this->expectException(QueryException::class);
        HomeIssueItem::factory()->for($issue, 'issue')->create([
            'section' => HomeIssueSection::Stories,
            'rank' => 1,
        ]);
    }

    public function test_one_issue_features_a_source_once_per_section(): void
    {
        $issue = HomeIssue::factory()->create();
        $diary = Diary::factory()->create();

        HomeIssueItem::factory()->for($issue, 'issue')->forSource($diary)->create([
            'section' => HomeIssueSection::Stories,
            'rank' => 1,
        ]);

        $this->expectException(QueryException::class);
        HomeIssueItem::factory()->for($issue, 'issue')->forSource($diary)->create([
            'section' => HomeIssueSection::Stories,
            'rank' => 2,
        ]);
    }

    public function test_the_source_reference_is_indexed(): void
    {
        // The never-again lookup asks about one source across every issue there has ever been, so
        // neither unique key reaches it: both lead with the issue.
        $this->assertTrue(
            Schema::hasIndex('home_issue_items', ['source_type', 'source_id']),
            'home_issue_items has no (source_type, source_id) index; the never-again lookup is a full scan.',
        );
    }

    public function test_the_issue_key_needs_no_index_of_its_own(): void
    {
        // It leads the (home_issue_id, section, rank) unique and both engines use a leftmost prefix,
        // so a redundant index added later has to argue with this.
        $names = array_map(
            fn (array $index): array => $index['columns'],
            Schema::getIndexes('home_issue_items'),
        );

        $this->assertContains(['home_issue_id', 'section', 'rank'], $names);
        $this->assertNotContains(['home_issue_id'], $names);
    }

    public function test_every_index_name_fits_mysql(): void
    {
        // MySQL rejects an identifier past 64 characters (errno 1059) and SQLite enforces no limit, so
        // the four-column unique's 67-character generated name would fail only on the MySQL lane.
        foreach (['home_issues', 'home_issue_items'] as $table) {
            foreach (Schema::getIndexes($table) as $index) {
                $name = (string) $index['name'];
                $this->assertLessThanOrEqual(64, strlen($name), "Index name [{$name}] is too long for MySQL.");
            }
        }
    }

    public function test_deleting_an_issue_takes_its_ledger_with_it(): void
    {
        $issue = HomeIssue::factory()->create();
        $item = HomeIssueItem::factory()->for($issue, 'issue')->create();

        $issue->delete();

        $this->assertDatabaseMissing('home_issue_items', ['id' => $item->id]);
    }

    public function test_deleting_a_featured_source_leaves_the_ledger_row(): void
    {
        // The contract the whole design rests on: the row is the memory that this diary was once
        // featured, and it has to survive the diary for the never-again rule to mean anything.
        $diary = Diary::factory()->create();
        $item = HomeIssueItem::factory()->forSource($diary)->create();

        $diary->delete();

        $this->assertDatabaseHas('home_issue_items', [
            'id' => $item->id,
            'source_type' => 'diary',
            'source_id' => $diary->id,
        ]);
    }

    public function test_the_tables_round_trip(): void
    {
        $issues = require database_path('migrations/2026_08_27_000001_create_home_issues_table.php');
        $items = require database_path('migrations/2026_08_27_000002_create_home_issue_items_table.php');

        // Newest first, the only order that works: MySQL refuses to drop a table another still holds
        // a foreign key into (errno 3730), which SQLite would let pass.
        $items->down();
        $this->assertFalse(Schema::hasTable('home_issue_items'));

        $issues->down();
        $this->assertFalse(Schema::hasTable('home_issues'));

        $issues->up();
        $items->up();

        $this->assertTrue(Schema::hasTable('home_issues'));
        $this->assertTrue(Schema::hasTable('home_issue_items'));
        $this->assertTrue(Schema::hasIndex('home_issue_items', ['source_type', 'source_id']));
    }
}
