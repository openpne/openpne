<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registration_tokens', function (Blueprint $table) {
            $table->string('source')->default('self')->after('token');
            $table->foreignId('inviter_id')->nullable()->after('source')
                ->constrained('members')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('registration_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inviter_id');
            $table->dropColumn('source');
        });
    }
};
