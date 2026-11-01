<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Set when the client first obtains an authorization code and never cleared: the code and token rows
 * are pruned, and a client the other side still holds must outlive them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oauth_clients', function (Blueprint $table) {
            $table->timestamp('used_at')->nullable()->after('revoked');
        });

        // A client that already holds a code or token was used before the column existed.
        DB::connection($this->getConnection())->table('oauth_clients')
            ->where(fn ($q) => $q
                ->whereExists(fn ($s) => $s->selectRaw('1')->from('oauth_access_tokens')->whereColumn('oauth_access_tokens.client_id', 'oauth_clients.id'))
                ->orWhereExists(fn ($s) => $s->selectRaw('1')->from('oauth_auth_codes')->whereColumn('oauth_auth_codes.client_id', 'oauth_clients.id')))
            ->update(['used_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('oauth_clients', function (Blueprint $table) {
            $table->dropColumn('used_at');
        });
    }

    public function getConnection(): ?string
    {
        return $this->connection ?? config('passport.connection');
    }
};
