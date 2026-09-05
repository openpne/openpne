<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('openpne4_upgrade_state', function (Blueprint $table) {
            $table->id();
            $table->string('step_key')->unique();
            $table->string('status');
            $table->unsignedBigInteger('rows_affected')->nullable();
            $table->json('metadata')->nullable();
            $table->text('error')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('openpne4_upgrade_state');
    }
};
