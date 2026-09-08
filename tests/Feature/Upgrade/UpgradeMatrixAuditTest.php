<?php

namespace Tests\Feature\Upgrade;

use App\Mail\Template\MailTemplate;
use App\Upgrade\ActiveMember;
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
        // With several steps writing one table, a null targetFilter() would make verify count the
        // siblings' rows as drift; that the filters do not overlap is raw SQL, pinned on the
        // verifier itself.
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
        // Per file_id column, not per table (an owner can be a join table or a plain column): each is
        // owned by FileUpgrade, on an unstepped source table, or declared unowned, else its owner
        // would be silently dropped.
        $references = SourceSchema::default()->fileReferencingColumns();

        // From the spec, not the key: one column owned by two types is keyed "table.column#type".
        $owned = array_values(array_unique(array_map(
            static fn (array $spec): string => "{$spec['table']}.{$spec['file']}",
            (new FileUpgrade)->ownedFileReferences(),
        )));
        // The key form is pinned, since a PHP literal keeps the last of two equal keys without a word.
        $arms = [];
        foreach ((new FileUpgrade)->ownedFileReferences() as $key => $spec) {
            $column = "{$spec['table']}.{$spec['file']}";
            $this->assertContains($key, [$column, "{$column}#{$spec['type']}"], "owner key {$key} is not table.column or table.column#type");
            $arms[$column][] = $spec['type'];
        }
        $this->assertSame(['timelinePost', 'groupMessage'], $arms['activity_image.file_id']);
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

    public function test_every_member_referencing_column_has_a_treatment(): void
    {
        // Every source reference to a member must have one of the three treatments (ActiveMember),
        // else a skipped member's id could land in a target row; checked per column, since a table can
        // carry two.
        $references = SourceSchema::default()->memberReferencingColumns();
        $ledger = ActiveMember::references();

        // Per step, not per reference: two steps can share a FROM table (community_member feeds both
        // the membership and the join request), and checking the reference alone would let one drop
        // the guard and pass on the other's.
        $fromColumns = [];
        $declaredGuards = [];
        foreach (StepRegistry::all() as $step) {
            foreach ($references as $reference) {
                [$table, $column] = explode('.', $reference);
                if ($table !== $step->sourceTable()) {
                    continue;
                }

                $fromColumns[$reference] = true;
                $declares = in_array($column, $step->memberRefs(), true);
                $declaredGuards[$reference] = ($declaredGuards[$reference] ?? false) || $declares;

                $this->assertTrue($declares || isset($ledger[$reference]),
                    class_basename($step)." selects FROM `{$table}`, whose `{$column}` references `member`, but neither declares it in memberRefs() nor is it accounted for in ActiveMember::references() — a skipped member's id could reach a target row");
            }
        }

        // And the references no step selects FROM: reached only by correlated subquery, so no
        // memberRefs() can cover them and the ledger is the only place they can be handled.
        foreach ($references as $reference) {
            $this->assertTrue(isset($fromColumns[$reference]) || isset($ledger[$reference]),
                "{$reference} references `member` but is no step's FROM column and ActiveMember::references() does not account for it");
        }

        // No reference is both dropped and declared: the two would disagree about what happens to it.
        foreach (array_keys(array_filter($declaredGuards)) as $reference) {
            $this->assertArrayNotHasKey($reference, $ledger,
                "{$reference} is dropped by a step's memberRefs() and also declared in ActiveMember::references()");
        }

        // No stale declaration: a ledger entry must be a real fixture member FK, and carry what its
        // treatment needs (a reason for UNUSED, so a silent non-migration is always justified).
        foreach ($ledger as $reference => $meta) {
            $this->assertContains($reference, $references,
                "{$reference} is declared in ActiveMember::references() but is not a `member` foreign key in the source schema");
            $this->assertContains($meta['treatment'], [ActiveMember::REFUSE, ActiveMember::UNUSED],
                "{$reference} declares an unknown treatment '{$meta['treatment']}'");
            if ($meta['treatment'] === ActiveMember::UNUSED) {
                $this->assertNotEmpty($meta['reason'] ?? '', "{$reference} is declared UNUSED but carries no reason");
            }
        }
    }

    public function test_member_references_resolved_by_subquery_are_in_the_ledger(): void
    {
        // A member id reaching a target column through a table that is no step's FROM is invisible to
        // memberRefs(), so only the ledger can cover groups.pending_admin_member_id and
        // direct_messages.draft_recipient_id.
        $fromTables = array_map(static fn ($step): string => $step->sourceTable(), StepRegistry::all());
        $ledger = ActiveMember::references();

        foreach (['community_member_position.member_id', 'message_send_list.member_id'] as $reference) {
            $this->assertSame(ActiveMember::REFUSE, $ledger[$reference]['treatment'] ?? null,
                "{$reference} feeds a target member column through a correlated subquery and must be REFUSE-checked");
            $this->assertNotEmpty($ledger[$reference]['scope'] ?? '',
                "{$reference} is read by subquery with its own predicate, so its check needs a scope — the FROM step's filter is the wrong set");
        }

        // community_member_position drives a target member column but is no step's FROM; if that ever
        // changes, the scope above stops being the right one to count.
        $this->assertNotContains('community_member_position', $fromTables,
            'community_member_position now has a standalone step — revisit its ledger scope');
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
        // FileUpgrade writes the morph alias as a string literal, and an alias absent from the map
        // resolves to no model, so FilePolicy would deny the file forever.
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

    public function test_every_nullable_from_table_column_feeding_a_not_null_target_is_guarded(): void
    {
        // The INSERT fails mid-run on the first NULL row; the guards the audit can read are a filter
        // conjunct that pins the column and an outermost COALESCE, and nullGuards() names any other.
        $schema = SourceSchema::default();

        foreach (StepRegistry::all() as $step) {
            $name = class_basename($step);
            $nullable = $schema->nullableColumns($step->sourceTable());
            $required = [];
            foreach (Schema::getColumns($step->targetTable()) as $column) {
                if (! $column['nullable']) {
                    $required[$column['name']] = true;
                }
            }
            $conjuncts = $this->conjuncts($step->effectiveFilter() ?? '');
            $declared = $step->nullGuards();

            foreach ($declared as $target => $reason) {
                $this->assertArrayHasKey($target, $step->columns(), "{$name} declares a null guard for `{$target}`, which it does not map");
                $this->assertNotEmpty($reason, "{$name} declares a null guard for `{$target}` without a reason");
            }

            foreach ($step->columns() as $target => $mapping) {
                $exposed = isset($required[$target]) && ! $this->fallsBackToALiteral($mapping->selectSql())
                    ? array_values(array_filter(
                        array_intersect($mapping->uses, $nullable),
                        fn (string $column): bool => ! $this->filterPins($conjuncts, $step->sourceTable(), $column),
                    ))
                    : [];

                if ($exposed === []) {
                    // No stale declaration: beside a guard the audit can read, the reason is a second, unchecked story.
                    $this->assertArrayNotHasKey($target, $declared,
                        "{$name} declares a null guard for `{$target}`, but no nullable source column reaches it unguarded");

                    continue;
                }

                $this->assertArrayHasKey($target, $declared,
                    "{$name} copies nullable `{$step->sourceTable()}`.`".implode('` / `', $exposed)."` into NOT NULL `{$step->targetTable()}`.`{$target}` with no guard the audit can read: keep the row out in filter(), give the value a literal fallback with an outermost COALESCE where one is right, or name the guard in nullGuards()");
            }
        }
    }

    /** @return list<string> the top-level AND conjuncts of a SQL boolean, each stripped of wrapping parentheses */
    private function conjuncts(string $sql): array
    {
        $sql = trim($sql);
        while ($sql !== '' && $this->isParenthesised($sql)) {
            $sql = trim(substr($sql, 1, -1));
        }
        if ($sql === '') {
            return [];
        }

        // AND binds tighter than OR, so a disjunction outside parentheses guarantees no conjunct at all.
        if (count($this->splitOutsideParentheses($sql, ' OR ')) > 1) {
            return [$sql];
        }

        $parts = $this->splitOutsideParentheses($sql, ' AND ');

        return count($parts) === 1
            ? [$sql]
            : array_merge(...array_map(fn (string $part): array => $this->conjuncts($part), $parts));
    }

    /** @param  list<string>  $conjuncts */
    private function filterPins(array $conjuncts, string $table, string $column): bool
    {
        $qualified = '`'.preg_quote($table, '/').'`\.`'.preg_quote($column, '/').'`';
        $reference = '(?:`'.preg_quote($table, '/').'`\.)?`'.preg_quote($column, '/').'`';
        $other = '`[a-z0-9_]+`(?:\.`[a-z0-9_]+`)?';

        foreach ($conjuncts as $conjunct) {
            if (preg_match("/^{$reference} IS NOT NULL$/", $conjunct) || preg_match("/^{$reference} IN \(SELECT /", $conjunct)) {
                return true;
            }

            // A correlated EXISTS whose WHERE equates the column: NULL matches no row, so the row is left out.
            if (preg_match('/^EXISTS \((SELECT .*)\)$/s', $conjunct, $m)) {
                $where = $this->splitOutsideParentheses($m[1], ' WHERE ');
                foreach ($this->conjuncts(implode(' WHERE ', array_slice($where, 1))) as $predicate) {
                    if (preg_match("/^{$other} = {$qualified}$/", $predicate) || preg_match("/^{$qualified} = {$other}$/", $predicate)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /** An outermost COALESCE / IFNULL whose last argument is a literal, so the value cannot be NULL. */
    private function fallsBackToALiteral(string $select): bool
    {
        $select = trim($select);

        if (! preg_match('/^(?:COALESCE|IFNULL)\((.*)\)$/is', $select, $m)
            || ! $this->isParenthesised(substr($select, (int) strpos($select, '(')))) {
            return false;
        }

        $arguments = $this->splitOutsideParentheses($m[1], ',');

        return preg_match("/^(?:'[^']*'|-?\d+)$/", trim((string) end($arguments))) === 1;
    }

    private function isParenthesised(string $sql): bool
    {
        if (! str_starts_with($sql, '(') || ! str_ends_with($sql, ')')) {
            return false;
        }

        $depth = 0;
        $quoted = false;
        $last = strlen($sql) - 1;
        for ($i = 0; $i <= $last; $i++) {
            $char = $sql[$i];
            if ($char === "'") {
                $quoted = ! $quoted;
            } elseif ($quoted) {
                continue;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')' && --$depth === 0) {
                return $i === $last;
            }
        }

        return false;
    }

    /** @return list<string> $sql split at every $separator outside parentheses and string literals */
    private function splitOutsideParentheses(string $sql, string $separator): array
    {
        $parts = [];
        $depth = 0;
        $quoted = false;
        $start = 0;
        $length = strlen($separator);
        for ($i = 0, $n = strlen($sql); $i < $n; $i++) {
            $char = $sql[$i];
            if ($char === "'") {
                $quoted = ! $quoted;
            } elseif ($quoted) {
                continue;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            } elseif ($depth === 0 && substr($sql, $i, $length) === $separator) {
                $parts[] = trim(substr($sql, $start, $i - $start));
                $start = $i + $length;
                $i += $length - 1;
            }
        }
        $parts[] = trim(substr($sql, $start));

        return $parts;
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
