<?php

namespace App\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\SQLiteBuilder;

class SQLiteSchemaBuilder extends SQLiteBuilder
{
    /**
     * Laravel's table rebuild runs inside one Blueprint and resets sqlite_sequence to the highest id left, so the mark is restored here, before the caller's next insert (docs/internals/runtime.md, "Row ids").
     * Restored in `finally`: a rebuild that commits before a later statement of the same Blueprint fails has already lowered it.
     */
    protected function build(Blueprint $blueprint)
    {
        if ($this->connection->pretending()) {
            parent::build($blueprint);

            return;
        }

        $table = $blueprint->getTable();
        $before = $this->highWaterMark($table);

        try {
            parent::build($blueprint);
        } finally {
            if ($before !== null) {
                $this->restore($this->currentName($blueprint, $table), $before);
            }
        }
    }

    /** A failure can stop the Blueprint before or at its rename, leaving the table under its old name. */
    private function currentName(Blueprint $blueprint, string $table): string
    {
        if ($this->tableSql($table) !== null) {
            return $table;
        }

        foreach ($blueprint->getCommands() as $command) {
            if ($command->name === 'rename') {
                $table = $command->to;
            }
        }

        return $table;
    }

    private function tableSql(string $table): ?string
    {
        $sql = $this->connection->scalar("select sql from sqlite_master where type = 'table' and name = ?", [$table]);

        return is_string($sql) ? $sql : null;
    }

    private function restore(string $table, int $before): void
    {
        $sql = $this->tableSql($table);

        // A table that no longer numbers its rows must not gain a sqlite_sequence row.
        if ($sql === null || stripos($sql, 'autoincrement') === false) {
            return;
        }

        $now = $this->highWaterMark($table);
        if ($now !== null && $now >= $before) {
            return;
        }

        // sqlite_sequence has no unique key on name, so no upsert.
        $this->connection->delete('delete from sqlite_sequence where name = ?', [$table]);
        $this->connection->insert('insert into sqlite_sequence (name, seq) values (?, ?)', [$table, $before]);
    }

    private function highWaterMark(string $table): ?int
    {
        $exists = $this->connection->scalar("select count(*) from sqlite_master where type = 'table' and name = 'sqlite_sequence'");
        if (! $exists) {
            return null;
        }

        $seq = $this->connection->scalar('select seq from sqlite_sequence where name = ?', [$table]);

        return $seq === null ? null : (int) $seq;
    }
}
