<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });

        // `subject` is nullable: the appended signature template has a body only.
        Schema::create('mail_template_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_template_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('subject')->nullable();
            $table->text('body');
            $table->timestamps();
            $table->unique(['mail_template_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_template_translations');
        Schema::dropIfExists('mail_templates');
    }
};
