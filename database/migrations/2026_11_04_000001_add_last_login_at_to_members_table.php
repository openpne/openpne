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

    /** Below SQLite 3.35 the column drop rebuilds members, which drops its triggers, so they are read first and put back (docs/internals/runtime.md, "Row ids"). */
    public function down(): void
    {
        $triggers = DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', 'members')->orderBy('name')->get(['name', 'sql'])
            : collect();

        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn('last_login_at');
        });

        foreach ($triggers as $trigger) {
            DB::statement('DROP TRIGGER IF EXISTS '.DB::getQueryGrammar()->wrap($trigger->name));
            DB::unprepared($trigger->sql);
        }
    }
};
