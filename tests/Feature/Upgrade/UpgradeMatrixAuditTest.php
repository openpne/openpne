<?php

namespace Tests\Feature\Upgrade;

use App\Mail\Template\MailTemplate;
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

    public function test_unstepped_source_tables_exist_in_the_fixture(): void
    {
        // Well-formedness of the unstepped-table declaration: each named table must be a
        // real OpenPNE 3 source table (catches typos).
        $schema = SourceSchema::default();

        foreach (StepRegistry::unsteppedSourceTables() as $table => $reason) {
            $this->assertNotEmpty($schema->columns($table),
                "unstepped source table `{$table}` is declared but absent from the source schema fixture");
            $this->assertNotEmpty($reason, "unstepped source table `{$table}` must carry a reason");
        }
    }

    public function test_no_stepped_source_table_is_declared_unstepped(): void
    {
        // unsteppedSourceTables() is the ledger of tables no standalone step drives; once a step
        // lands for a table, its entry must go — otherwise the matrix reports the table twice and
        // the ledger reason silently lies.
        $unstepped = array_keys(StepRegistry::unsteppedSourceTables());

        foreach (StepRegistry::all() as $step) {
            $this->assertNotContains($step->sourceTable(), $unstepped,
                "`{$step->sourceTable()}` is driven by a standalone step but still declared in unsteppedSourceTables()");
        }
    }

    public function test_steps_sharing_a_target_table_declare_what_they_own(): void
    {
        // Verify compares a step's source rows against the rows it owns in the target; with several
        // steps writing one table, a null targetFilter() would count the siblings' rows as drift.
        // That the declared filters do not overlap is raw SQL, not checkable here — the mixed
        // sns_settings table in VerifierSharedTargetTest pins it instead.
        $stepsByTarget = [];
        foreach (StepRegistry::all() as $step) {
            $stepsByTarget[$step->targetTable()][] = $step;
        }

        foreach ($stepsByTarget as $target => $steps) {
            if (count($steps) === 1) {
                continue;
            }

            foreach ($steps as $step) {
                $this->assertNotNull($step->targetFilter(),
                    class_basename($step)." shares target `{$target}` with another step but does not declare targetFilter()");
            }
        }
    }

    public function test_every_file_referencing_column_is_owned_or_accounted_for(): void
    {
        // A file's binary is preserved (FileUpgrade keeps every `file` row), but its owner must be
        // explicitly accounted for so no upload silently loses its owning entity. An owner can sit on
        // a join table (member_image) or a plain column (community.file_id), so this is checked per
        // file_id column, not per table: each is owned by FileUpgrade, on an unstepped source table, or
        // declared in unownedFileColumns() — anything else (e.g. a file column on a migrated table
        // that nothing owns) is a silent drop.
        $references = SourceSchema::default()->fileReferencingColumns();

        $owned = array_keys((new FileUpgrade)->ownedFileReferences());
        $unsteppedTables = array_keys(StepRegistry::unsteppedSourceTables());
        $unowned = array_keys(StepRegistry::unownedFileColumns());

        foreach ($references as $reference) {
            [$table] = explode('.', $reference);

            $accounted = in_array($reference, $owned, true)
                || in_array($table, $unsteppedTables, true)
                || in_array($reference, $unowned, true);

            $this->assertTrue($accounted,
                "{$reference} references `file` but is neither owned by FileUpgrade, on an unstepped source table, nor declared in unownedFileColumns() — its file's owner would be silently dropped");
        }

        // No stale declaration: a declared owner/unowned reference must be a real fixture file FK.
        foreach (array_merge($owned, $unowned) as $reference) {
            $this->assertContains($reference, $references,
                "{$reference} is declared as a file reference but is not a `file` foreign key in the source schema");
        }
    }

    public function test_every_imported_mail_template_has_a_disposition(): void
    {
        // notificationMailDispositions() is hand-written, but its migrated entries must track the registry:
        // adding an import origin to a MailTemplate case (which the SQL filter follows automatically) must
        // not silently leave the matrix disposition behind.
        $documented = array_keys(StepRegistry::notificationMailDispositions());

        foreach (MailTemplate::importable() as $template) {
            $this->assertContains($template->op3SourceName(), $documented,
                "notification_mail name '{$template->op3SourceName()}' is imported but has no disposition entry");
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

    public function test_every_read_source_table_exists_in_the_fixture(): void
    {
        // The preflight materialises an absent optional source table from the fixture, so every table a
        // step reads (FROM + subquery) must be a real fixture table — else a new step's SourceRef token
        // would throw at run time instead of failing here.
        $schema = SourceSchema::default();

        foreach (StepRegistry::all() as $step) {
            foreach ($step->readSourceTables() as $table) {
                $this->assertNotEmpty($schema->columns($table),
                    "{$step->sourceTable()} reads source table `{$table}`, which is absent from the source schema fixture");
            }
        }
    }

    public function test_optional_plugin_tables_are_read_tables_and_disjoint(): void
    {
        $readTables = [];
        foreach (StepRegistry::all() as $step) {
            foreach ($step->readSourceTables() as $table) {
                $readTables[$table] = true;
            }
        }

        $seen = [];
        foreach (StepRegistry::optionalPluginSources() as $plugin => $meta) {
            foreach ($meta['tables'] as $table) {
                $this->assertArrayHasKey($table, $readTables,
                    "{$plugin} lists optional source table `{$table}`, which no step reads");
                $this->assertArrayNotHasKey($table, $seen,
                    "optional source table `{$table}` is listed by two plugins");
                $seen[$table] = true;
            }
        }
    }
}
