<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How the bcrypt in `password` was produced: null = bcrypt of the plaintext,
        // 'md5_bcrypt' = bcrypt of the OpenPNE 3 MD5 hex (App\Auth\PasswordScheme, set by
        // the upgrade's wrap pass). Both look identical as hashes, so login needs the
        // column to know whether to pre-hash the attempt with md5().
        Schema::table('members', function (Blueprint $table) {
            $table->string('password_scheme', 32)->nullable()->after('password');
        });
        Schema::table('admin_users', function (Blueprint $table) {
            $table->string('password_scheme', 32)->nullable()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn('password_scheme');
        });
        Schema::table('admin_users', function (Blueprint $table) {
            $table->dropColumn('password_scheme');
        });
    }
};
