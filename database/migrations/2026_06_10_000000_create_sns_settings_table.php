<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Global, site-wide SNS settings as a key/value store (OpenPNE 3 sns_config).
        // Only rows that diverge from the App\Support\SnsSettingKey defaults are kept,
        // so an absent row means "follow the default". `value` is text because future
        // keys (e.g. footer HTML) can be long; the scalar settings fit fine.
        Schema::create('sns_settings', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->text('value');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sns_settings');
    }
};
