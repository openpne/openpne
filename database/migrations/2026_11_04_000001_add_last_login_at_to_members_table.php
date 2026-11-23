<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dateTime('last_login_at')->nullable()->after('two_factor_confirmed_at');
        });
    }

    /**
     * Below SQLite 3.35 the column drop rebuilds members, which drops its triggers, so they are read first and put back in the same transaction (docs/internals/runtime.md, "Row ids").
     * Foreign keys go off before the transaction opens, since SQLite ignores the pragma inside one and the rebuild's DROP TABLE would cascade.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->dropLastLoginAt();

            return;
        }

        $triggers = DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', 'members')->orderBy('name')->get(['name', 'sql']);
        $foreignKeys = (bool) DB::scalar('PRAGMA foreign_keys');

        Schema::disableForeignKeyConstraints();

        try {
            DB::transaction(function () use ($triggers) {
                $this->dropLastLoginAt();

                foreach ($triggers as $trigger) {
                    DB::statement('DROP TRIGGER IF EXISTS '.DB::getQueryGrammar()->wrap($trigger->name));
                    DB::unprepared($trigger->sql);
                }
            });
        } finally {
            if ($foreignKeys) {
                Schema::enableForeignKeyConstraints();
            }
        }
    }

    private function dropLastLoginAt(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn('last_login_at');
        });
    }
};
