<?php

namespace Tests\Feature\Upgrade;

use App\Upgrade\SourceSchema;
use App\Upgrade\StepRegistry;
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
}
