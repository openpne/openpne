<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->string('key');
            $table->string('value');
            $table->timestamps();

            // The unique index is member_id-prefixed, so it also backs the member-anchored reads
            // and, on SQLite, the delete cascade.
            $table->unique(['member_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_preferences');
    }
};
