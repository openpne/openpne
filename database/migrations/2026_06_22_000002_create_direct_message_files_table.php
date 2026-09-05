<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('direct_message_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('direct_message_id')->constrained('direct_messages')->cascadeOnDelete();
            // Signed INT to match files.id.
            $table->integer('file_id');
            $table->unsignedTinyInteger('number');
            $table->foreign('file_id')->references('id')->on('files')->cascadeOnDelete();

            $table->index(['direct_message_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('direct_message_files');
    }
};
