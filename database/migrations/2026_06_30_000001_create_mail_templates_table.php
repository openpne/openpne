<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Admin-editable system-mail templates (OpenPNE 3 "NotificationMail"). One row per OpenPNE 4
        // notification key. No seed rows: App\Mail\Template\MailTemplate carries the built-in default
        // wording, so a fresh install behaves as before and an absent row simply means "use the default".
        // `id` is a surrogate the OpenPNE 3 import carries verbatim so the per-locale child can FK by it.
        Schema::create('mail_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            // Honored only for configurable templates; the service forces required mails (registration,
            // email change) on regardless, so a migrated `is_enabled=0` cannot break those flows.
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });

        // Per-locale subject/body. `body` keeps the OpenPNE 3 template text verbatim (rendered by the
        // dialect-subset MailTemplateRenderer), so a migrated template's wording is preserved byte-for-byte.
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
