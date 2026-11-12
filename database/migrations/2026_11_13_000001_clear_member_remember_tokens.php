<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Member no longer names a remember-token column, so what earlier logins stored is dead data.
    public function up(): void
    {
        DB::table('members')->whereNotNull('remember_token')->update(['remember_token' => null]);
    }

    public function down(): void {}
};
