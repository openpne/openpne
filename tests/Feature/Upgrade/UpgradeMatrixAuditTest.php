<?php

namespace Tests\Feature\Upgrade;

use App\Upgrade\SourceSchema;
use App\Upgrade\StepRegistry;
use App\Upgrade\Steps\FileUpgrade;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Cross-checks every upgrade step against the migrations (target) and the source
 * schema fixture (source) so a mapping cannot silently drift from either. Runs on
 * both DB lanes — it inspects schema, it does not execute the copy.
 */
class UpgradeMatrixAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_mapped_target_columns_exist_in_the_migrated_schema(): void
    {
        foreach (StepRegistry::all() as $step) {
            $existing = Schema::getColumnListing($step->targetTable());

            foreach (array_keys($step->columns()) as $column) {
                $this->assertContains($column, $existing,
                    "{$step->targetTable()}.{$column} is mapped but missing from the migrated schema");
            }
        }
    }

    public function test_no_target_column_is_left_unmapped(): void
    {
        foreach (StepRegistry::all() as $step) {
            $accounted = array_merge(
                array_keys($step->columns()),
                $step->targetDefaults(),
                array_keys($step->pendingTargets()),
            );

            foreach (Schema::getColumnListing($step->targetTable()) as $column) {
                $this->assertContains($column, $accounted,
                    "{$step->targetTable()}.{$column} exists but no mapping, targetDefaults() or pendingTargets() covers it");
            }
        }
    }

    public function test_every_source_column_is_mapped_or_gapped(): void
    {
        $schema = SourceSchema::default();

        // One source table may feed several steps (member_relationship → friendships /
        // friend_requests / member_blocks), so coverage is checked across every step reading it.
        $stepsBySource = [];
        foreach (StepRegistry::all() as $step) {
            $stepsBySource[$step->sourceTable()][] = $step;
        }

        foreach ($stepsBySource as $sourceTable => $steps) {
            $accounted = [];
            foreach ($steps as $step) {
                $accounted = array_merge($accounted, $step->consumedSourceColumns(), array_keys($step->gaps()));
            }

            foreach ($schema->columns($sourceTable) as $column) {
                $this->assertContains($column, $accounted,
                    "{$sourceTable}.{$column} is neither mapped nor declared in gaps() by any step (silent drop)");
            }
        }
    }

    public function test_referenced_source_columns_exist_in_the_fixture(): void
    {
        $schema = SourceSchema::default();

        foreach (StepRegistry::all() as $step) {
            $sourceColumns = $schema->columns($step->sourceTable());

            foreach ($step->consumedSourceColumns() as $column) {
                $this->assertContains($column, $sourceColumns,
                    "{$step->sourceTable()}.{$column} is referenced by the mapping but absent from the source schema");
            }
        }
    }

    public function test_deferred_source_tables_exist_in_the_fixture(): void
    {
        // Well-formedness of the deferred-table declaration: each named table must be a
        // real OpenPNE 3 source table (catches typos). Whether every source table is
        // either stepped or deferred is a separate, fixture-wide coverage audit.
        $schema = SourceSchema::default();

        foreach (StepRegistry::deferredSourceTables() as $table => $reason) {
            $this->assertNotEmpty($schema->columns($table),
                "deferred source table `{$table}` is declared but absent from the source schema fixture");
            $this->assertNotEmpty($reason, "deferred source table `{$table}` must carry a reason");
        }
    }

    public function test_every_file_referencing_column_is_owned_or_deferred(): void
    {
        // A file's binary is preserved (FileUpgrade keeps every `file` row), but its owner must be
        // explicitly accounted for so no upload silently loses its owning entity. An owner can sit on
        // a join table (member_image) or a plain column (community.file_id), so this is checked per
        // file_id column, not per table: each is owned by FileUpgrade, on a deferred source table, or
        // declared in unownedFileColumns() — anything else (e.g. a file column on a migrated table
        // that nothing owns) is a silent drop.
        $references = SourceSchema::default()->fileReferencingColumns();

        $owned = array_keys((new FileUpgrade)->ownedFileReferences());
        $deferredTables = array_keys(StepRegistry::deferredSourceTables());
        $unowned = array_keys(StepRegistry::unownedFileColumns());

        foreach ($references as $reference) {
            [$table] = explode('.', $reference);

            $accounted = in_array($reference, $owned, true)
                || in_array($table, $deferredTables, true)
                || in_array($reference, $unowned, true);

            $this->assertTrue($accounted,
                "{$reference} references `file` but is neither owned by FileUpgrade, on a deferred source table, nor declared in unownedFileColumns() — its file's owner would be silently dropped");
        }

        // No stale declaration: a declared owner/unowned reference must be a real fixture file FK.
        foreach (array_merge($owned, $unowned) as $reference) {
            $this->assertContains($reference, $references,
                "{$reference} is declared as a file reference but is not a `file` foreign key in the source schema");
        }
    }

    public function test_file_owner_morph_aliases_are_registered(): void
    {
        // FileUpgrade writes the morph alias into files.related_entity_type as a string literal; an
        // alias absent from the map resolves to no model, so the FilePolicy would deny the file
        // forever (a silent, invisible loss). Pin the aliases to the registered map.
        $morphMap = Relation::morphMap();

        foreach ((new FileUpgrade)->ownedFileReferences() as $reference => $spec) {
            $this->assertArrayHasKey($spec['type'], $morphMap,
                "FileUpgrade owns {$reference} as morph alias '{$spec['type']}', which is not in the morph map");
        }
    }
}
