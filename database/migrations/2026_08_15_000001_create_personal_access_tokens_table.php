<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Laravel Sanctum's personal access tokens, published from the package so the schema lives with
 * every other table this app owns. `token` holds a SHA-256 of the credential, never the credential
 * itself; the plaintext exists only in the output of `openpne:mcp:token`.
 *
 * `tokenable` is polymorphic and so carries no foreign key: deleting a member does not cascade
 * here, and every path that removes or freezes one has to sweep the rows itself
 * (RejectMemberLogin for the ban, MemberObserver::deleting for withdrawal).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
