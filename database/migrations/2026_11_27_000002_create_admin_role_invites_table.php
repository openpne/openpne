<?php

use App\Support\Database\IdentifierCollation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_role_invites', function (Blueprint $table) {
            $table->string('email')->collation(IdentifierCollation::for(DB::connection()))->primary();
            // Unix seconds, so an invite lasts its minutes across a daylight-saving change in APP_TIMEZONE.
            $table->unsignedBigInteger('expires_at');
            $table->dateTime('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_role_invites');
    }
};
