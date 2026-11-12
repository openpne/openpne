<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // The member guard no longer issues or reads a remember-me cookie; a stored token would still
    // make every logout write the row.
    public function up(): void
    {
        DB::table('members')->whereNotNull('remember_token')->update(['remember_token' => null]);
    }

    public function down(): void {}
};
