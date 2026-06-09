<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            // OpenPNE 3 member.is_login_rejected: an admin ban that refuses login. Carried by the
            // upgrade so a banned member stays banned; AuthenticateMember enforces it.
            $table->boolean('is_login_rejected')->default(false)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn('is_login_rejected');
        });
    }
};
