<?php

namespace App\Database;

use Illuminate\Database\SQLiteConnection as BaseSQLiteConnection;

/** See docs/internals/runtime.md, "Row ids". */
class SQLiteConnection extends BaseSQLiteConnection
{
    public function getSchemaBuilder()
    {
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }

        return new SQLiteSchemaBuilder($this);
    }
}
