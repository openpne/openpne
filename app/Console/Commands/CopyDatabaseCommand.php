<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;

/**
 * See docs/changing-database-engine.md "Copying the database".
 */
class CopyDatabaseCommand extends Command
{
    use ConfirmableTrait;

    /** SQLite's SQLITE_MAX_VARIABLE_NUMBER was 999 before 3.32, and this stays under it rather than probe. */
    private const PLACEHOLDER_BUDGET = 900;

    /** A batch holds every BLOB in it in PHP memory at once, so binary tables get a much smaller one. */
    private const BINARY_BATCH_ROWS = 25;

    protected $signature = 'openpne:copy-database
        {--from= : Source connection (default: the app\'s own)}
        {--to= : Target connection}
        {--from-database= : Override the source connection\'s database name / SQLite path}
        {--to-database= : Override the target connection\'s database name / SQLite path}
        {--chunk=500 : Rows per INSERT (clamped for tables holding BLOBs)}
        {--force : Skip the production confirmation}';

    protected $description = 'Copy this site\'s database onto another connection (MySQL ↔ SQLite)';

    public function handle(): int
    {
        $from = (string) ($this->option('from') ?: config('database.default'));
        $to = (string) ($this->option('to') ?? '');

        if ($to === '') {
            $this->error('--to is required: the connection to copy into.');

            return self::FAILURE;
        }

        foreach ([$from, $to] as $name) {
            if (config("database.connections.{$name}") === null) {
                $this->error("Unknown database connection [{$name}].");

                return self::FAILURE;
            }
        }

        $this->overrideDatabase($from, $this->option('from-database'));
        $this->overrideDatabase($to, $this->option('to-database'));

        if ($this->sameDatabase($from, $to)) {
            $this->error("[{$from}] and [{$to}] resolve to the same database.");

            return self::FAILURE;
        }

        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        if (! Schema::connection($from)->hasTable('migrations')) {
            $this->error("[{$from}] has no migrations table — it is not an OpenPNE 4 database.");

            return self::FAILURE;
        }

        if (! $this->prepareTarget($from, $to)) {
            return self::FAILURE;
        }

        if (! $this->requireMatchingTables($from, $to) || ! $this->requireEmptyTarget($to)) {
            return self::FAILURE;
        }

        $plans = $this->plan($from, $to, $this->tablesToCopy($from, $to));

        if ($plans === null) {
            return self::FAILURE;
        }

        $this->copy($from, $to, $plans);

        return $this->verify($from, $to, array_keys($plans)) ? self::SUCCESS : self::FAILURE;
    }

    private function overrideDatabase(string $connection, mixed $database): void
    {
        if ($database === null || $database === '') {
            return;
        }

        config(["database.connections.{$connection}.database" => (string) $database]);
        DB::purge($connection);
    }

    private function sameDatabase(string $a, string $b): bool
    {
        $address = fn (string $name) => array_map(
            fn (string $key) => config("database.connections.{$name}.{$key}"),
            ['driver', 'host', 'port', 'database'],
        );

        return $address($a) === $address($b);
    }

    private function prepareTarget(string $from, string $to): bool
    {
        if (! Schema::connection($to)->hasTable('migrations')) {
            $this->line("Creating the schema on [{$to}].");

            if ($this->call('migrate', ['--database' => $to, '--force' => true]) !== self::SUCCESS) {
                return false;
            }
        }

        $missing = array_diff($this->migrationNames($from), $this->migrationNames($to));
        $extra = array_diff($this->migrationNames($to), $this->migrationNames($from));

        if ($missing === [] && $extra === []) {
            return true;
        }

        $this->error("[{$to}] is at a different schema version than [{$from}].");

        foreach ($missing as $migration) {
            $this->line("  only on {$from}: {$migration}");
        }

        foreach ($extra as $migration) {
            $this->line("  only on {$to}: {$migration}");
        }

        return false;
    }

    /**
     * Every read here decides something about the copy, so all of them are pinned to the write
     * connection rather than a replica that lags the database being copied.
     */
    private function authoritative(Connection $connection, string $table): QueryBuilder
    {
        return $connection->table($table)->useWritePdo();
    }

    /** @return list<string> */
    private function migrationNames(string $connection): array
    {
        return $this->authoritative(DB::connection($connection), 'migrations')
            ->orderBy('migration')->pluck('migration')->all();
    }

    /**
     * `migrations` is excluded: the target wrote its own when it migrated, and that record — not the
     * source's — describes the schema the rows land in.
     *
     * @return list<string>
     */
    private function tablesToCopy(string $from, string $to): array
    {
        $tables = array_intersect($this->tableNames($from), $this->tableNames($to));

        return array_values(array_diff($tables, ['migrations']));
    }

    /** @return list<string> */
    private function tableNames(string $connection): array
    {
        $tables = Schema::connection($connection)->getTableListing(schemaQualified: false);
        sort($tables);

        return $tables;
    }

    /**
     * The source may hold extra tables — after an OpenPNE 3 upgrade the restored OpenPNE 3 ones —
     * which are named rather than silently left out.
     */
    private function requireMatchingTables(string $from, string $to): bool
    {
        $sourceOnly = array_diff($this->tableNames($from), $this->tableNames($to));
        $targetOnly = array_diff($this->tableNames($to), $this->tableNames($from));

        if ($sourceOnly !== []) {
            $this->warn(count($sourceOnly).' table(s) exist only on ['.$from.'] and are no part of the OpenPNE 4 schema — not copied:');

            foreach ($sourceOnly as $table) {
                $this->line("  {$table}");
            }
        }

        if ($targetOnly === []) {
            return true;
        }

        $this->error("[{$to}] defines table(s) [{$from}] does not have: ".implode(', ', $targetOnly));

        return false;
    }

    /**
     * Every target table, not only the ones about to be written: a table the copy never touches still
     * ends up in the result, and the result is meant to be the source and nothing else.
     */
    private function requireEmptyTarget(string $to): bool
    {
        $occupied = array_values(array_filter(
            array_diff($this->tableNames($to), ['migrations']),
            fn (string $table) => $this->authoritative(DB::connection($to), $table)->exists(),
        ));

        if ($occupied === []) {
            return true;
        }

        $this->error("[{$to}] already holds rows — copy into an empty database so the result is the source and nothing else.");

        foreach ($occupied as $table) {
            $this->line("  {$table}");
        }

        return false;
    }

    /**
     * Resolve each table's columns before anything is written, so a schema the copy cannot represent
     * faithfully aborts while the target is still empty.
     *
     * @param  list<string>  $tables
     * @return array<string, array{columns: list<string>, binary: array<string, true>, batch: int}>|null
     */
    private function plan(string $from, string $to, array $tables): ?array
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $plans = [];

        foreach ($tables as $table) {
            $columns = Schema::connection($from)->getColumns($table);
            $names = array_column($columns, 'name');
            $targetNames = array_column(Schema::connection($to)->getColumns($table), 'name');

            // Drift on a hand-altered site: a source-only column would be dropped and a target-only one
            // filled with a default the source never held.
            $sourceOnly = array_diff($names, $targetNames);
            $targetOnly = array_diff($targetNames, $names);

            if ($sourceOnly !== [] || $targetOnly !== []) {
                $this->error("{$table} does not have the same columns on both sides.");

                foreach (['only on '.$from => $sourceOnly, 'only on '.$to => $targetOnly] as $side => $missing) {
                    $missing === [] || $this->line("  {$side}: ".implode(', ', $missing));
                }

                $this->line('  Reconcile them on a copy of the source, or extend the schema on both sides, then run this again.');

                return null;
            }

            $binary = array_column(array_filter($columns, $this->isBinary(...)), 'name');

            $plans[$table] = [
                'columns' => array_values($names),
                'binary' => array_fill_keys($binary, true),
                'batch' => $this->batchRows($chunk, count($names), $binary !== []),
            ];
        }

        return $plans;
    }

    private function batchRows(int $chunk, int $columns, bool $holdsBlobs): int
    {
        $rows = $holdsBlobs ? min($chunk, self::BINARY_BATCH_ROWS) : $chunk;

        return max(1, min($rows, intdiv(self::PLACEHOLDER_BUDGET, max(1, $columns))));
    }

    /** @param  array{type_name: string}  $column */
    private function isBinary(array $column): bool
    {
        return str_contains($column['type_name'], 'blob') || str_contains($column['type_name'], 'binary');
    }

    /** @param  array<string, array{columns: list<string>, binary: array<string, true>, batch: int}>  $plans */
    private function copy(string $from, string $to, array $plans): void
    {
        $source = DB::connection($from);
        $target = DB::connection($to);

        $this->line('Copying '.count($plans).' table(s) into ['.$to.'].');

        $restore = $this->streamSource($source);

        try {
            // The PRAGMA a SQLite target needs is a no-op inside a transaction, so constraints go down
            // first and the transaction opens within that.
            Schema::connection($to)->withoutForeignKeyConstraints(function () use ($source, $target, $plans) {
                $target->transaction(function () use ($source, $target, $plans) {
                    foreach ($plans as $table => $plan) {
                        $rows = $this->copyTable($source, $target, $table, $plan);
                        $this->line(sprintf('  %-44s %10s rows', $table, number_format($rows)));
                    }
                });
            });
        } finally {
            $restore();
        }
    }

    /**
     * A buffered MySQL result holds the whole table in PHP memory before the first row is read, which
     * file_bin alone can outgrow. It is the write PDO that is unbuffered, that being the one the
     * reads are pinned to.
     *
     * @return callable(): void puts the attribute back the way it was found
     */
    private function streamSource(Connection $source): callable
    {
        if ($source->getDriverName() !== 'mysql') {
            return static fn () => null;
        }

        // PDO::MYSQL_ATTR_USE_BUFFERED_QUERY is deprecated from PHP 8.5; Pdo\Mysql arrived in 8.4 and
        // the package supports 8.3, so take whichever this runtime has.
        $attribute = defined('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY')
            ? constant('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY')
            : PDO::MYSQL_ATTR_USE_BUFFERED_QUERY;

        $pdo = $source->getPdo();
        $buffered = $pdo->getAttribute($attribute);
        $pdo->setAttribute($attribute, false);

        return static fn () => $pdo->setAttribute($attribute, $buffered);
    }

    /** @param  array{columns: list<string>, binary: array<string, true>, batch: int}  $plan */
    private function copyTable(Connection $source, Connection $target, string $table, array $plan): int
    {
        $grammar = $target->getQueryGrammar();
        $sql = 'insert into '.$grammar->wrapTable($table)
            .' ('.implode(', ', array_map($grammar->wrap(...), $plan['columns'])).') values ';
        $row = '('.implode(', ', array_fill(0, count($plan['columns']), '?')).')';

        $written = 0;
        $batch = [];

        foreach ($this->authoritative($source, $table)->cursor() as $record) {
            $batch[] = (array) $record;

            if (count($batch) >= $plan['batch']) {
                $written += $this->insertBatch($target, $sql, $row, $plan, $batch);
                $batch = [];
            }
        }

        return $written + ($batch === [] ? 0 : $this->insertBatch($target, $sql, $row, $plan, $batch));
    }

    /**
     * @param  array{columns: list<string>, binary: array<string, true>, batch: int}  $plan
     * @param  list<array<string, mixed>>  $batch
     */
    private function insertBatch(Connection $target, string $sql, string $row, array $plan, array $batch): int
    {
        $statement = $target->getPdo()->prepare($sql.implode(', ', array_fill(0, count($batch), $row)));
        $position = 1;

        foreach ($batch as $record) {
            foreach ($plan['columns'] as $column) {
                $value = $record[$column] ?? null;

                match (true) {
                    $value === null => $statement->bindValue($position, null, PDO::PARAM_NULL),
                    // PARAM_STR can corrupt binary data on either driver (text binding, emulated-prepare
                    // quoting) — the reason App\Files\DbBlobFileStorage binds file_bin.bin as a LOB too.
                    isset($plan['binary'][$column]) => $statement->bindValue($position, $value, PDO::PARAM_LOB),
                    is_int($value), is_bool($value) => $statement->bindValue($position, (int) $value, PDO::PARAM_INT),
                    default => $statement->bindValue($position, (string) $value, PDO::PARAM_STR),
                };

                $position++;
            }
        }

        $statement->execute();

        return count($batch);
    }

    /** @param  list<string>  $tables */
    private function verify(string $from, string $to, array $tables): bool
    {
        $mismatched = [];

        foreach ($tables as $table) {
            $expected = $this->authoritative(DB::connection($from), $table)->count();
            $actual = $this->authoritative(DB::connection($to), $table)->count();

            if ($expected !== $actual) {
                $mismatched[] = "  {$table}: {$from} has {$expected}, {$to} has {$actual}";
            }
        }

        if ($mismatched !== []) {
            $this->error('Row counts do not match after the copy:');
            $this->line(implode(PHP_EOL, $mismatched));

            return false;
        }

        $this->info(count($tables).' table(s) copied, row counts match.');

        return true;
    }
}
