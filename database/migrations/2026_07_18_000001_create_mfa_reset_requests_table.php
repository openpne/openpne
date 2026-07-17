<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pending admin-issued two-factor reset links. An admin can mail the member's registered address
        // a time-limited link; the member (locked out, so a guest) opens it and clears their factor by
        // entering their account password. This table is the whole pending state and is disposable — the
        // member's two_factor_* columns are not touched until the link is consumed. The token is stored
        // hashed (a DB read must not yield a usable reset URL); expiry is computed from created_at against
        // a config TTL, mirroring email_change_requests. member_id is unique (one live link per member —
        // a re-send replaces it, killing the old link) and cascades on the member's deletion; token is
        // unique (a hash, and the lookup key).
        //
        // No cancel token (the deliberate difference from email_change_requests): consuming an email-change
        // link is password-free, so that flow ships a cancel link. Consuming a reset link requires the
        // account password, so a link that reached the wrong hands cannot be acted on — the after-the-fact
        // MfaDisabledNotification is the detection line, and re-sending replaces a link the member fears.
        Schema::create('mfa_reset_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('token')->unique();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mfa_reset_requests');
    }
};
