<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pending email-address changes. The members.email column is not touched until the token is
        // consumed at confirmation, so this table is the whole pending state and is disposable. The
        // token is stored hashed (a DB read must not yield a usable confirmation URL); expiry is
        // computed from created_at against a config TTL, mirroring registration_tokens. member_id is
        // unique (one live pending change per member) and cascades on the member's deletion; token is
        // unique (a hash, and the lookup key).
        Schema::create('email_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('new_email');
            $table->string('token')->unique();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_change_requests');
    }
};
