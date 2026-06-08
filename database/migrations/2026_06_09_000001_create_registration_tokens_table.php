<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pending email-confirmation registrations. The Member is not created until the token is
        // consumed at completion (members has no inactive state and a unique email), so this table
        // is the whole pending state and is disposable. The token is stored hashed (a DB read must
        // not yield a usable registration URL); expiry is computed from created_at against a config
        // TTL, mirroring password_reset_tokens.
        Schema::create('registration_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('email')->index();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_tokens');
    }
};
