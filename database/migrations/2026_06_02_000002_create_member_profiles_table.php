<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->foreignId('profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->foreignId('profile_option_id')->nullable()->constrained('profile_options')->cascadeOnDelete();
            $table->text('value')->nullable();
            $table->dateTime('value_datetime')->nullable();
            $table->unsignedTinyInteger('visibility')->nullable();
            $table->timestamps();

            // SQLite does not auto-index FK columns; MySQL/InnoDB does.
            $table->index('member_id');
            $table->index('profile_option_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_profiles');
    }
};
