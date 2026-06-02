<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Profile field definitions (OpenPNE 3 `profile`/`profile_option` + their i18n tables).
 *
 * A `profile` row defines one member-profile field (its form type, validation, default
 * visibility, and where it is shown); `profile_options` holds the choices for custom
 * select/radio/checkbox fields. Captions/labels live in the `*_translations` tables keyed
 * by (id, lang), mirroring OpenPNE 3's Doctrine I18n behaviour. Preset fields
 * (`op_preset_*`) source their choices from config/preset_profile.php, not profile_options.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64)->unique();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_unique')->default(false);
            $table->boolean('is_edit_public_flag')->default(false);
            // Visibility fallback when a value has no per-value flag. Always 1-4
            // (1=SNS, 2=Friends, 3=Private, 4=Web); OpenPNE 3's stray 0 is normalised away.
            $table->unsignedTinyInteger('default_public_flag')->default(1);
            $table->string('form_type', 32);
            $table->string('value_type', 32)->default('');
            $table->boolean('is_disp_regist')->default(false);
            $table->boolean('is_disp_config')->default(false);
            $table->boolean('is_disp_search')->default(false);
            $table->boolean('is_public_web')->default(false);
            $table->text('value_regexp')->nullable();
            $table->string('value_min', 32)->nullable();
            $table->string('value_max', 32)->nullable();
            $table->integer('sort_order')->nullable();
            $table->timestamps();
        });

        // (id, lang) composite PK, no own id/timestamps — the OpenPNE 3 I18n table shape.
        Schema::create('profile_translations', function (Blueprint $table) {
            $table->unsignedBigInteger('id');
            $table->text('caption');
            $table->text('info')->nullable();
            $table->string('lang', 5);

            $table->primary(['id', 'lang']);
            $table->foreign('id')->references('id')->on('profiles')->cascadeOnDelete()->cascadeOnUpdate();
        });

        Schema::create('profile_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->integer('sort_order')->nullable();
            $table->timestamps();

            $table->index('profile_id');
        });

        Schema::create('profile_option_translations', function (Blueprint $table) {
            $table->unsignedBigInteger('id');
            $table->text('value')->nullable();
            $table->string('lang', 5);

            $table->primary(['id', 'lang']);
            $table->foreign('id')->references('id')->on('profile_options')->cascadeOnDelete()->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_option_translations');
        Schema::dropIfExists('profile_options');
        Schema::dropIfExists('profile_translations');
        Schema::dropIfExists('profiles');
    }
};
