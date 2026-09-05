<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * No unique on (direct_message_id, recipient_id): MySQL adopts it as the direct_message_id foreign
 * key's backing index and then refuses to drop it (errno 1553).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('direct_messages', function (Blueprint $table) {
            $table->foreignId('draft_recipient_id')->nullable()->after('sender_id')->constrained('members')->nullOnDelete();
        });

        // A draft is 1:1, so its single receipt's recipient is the draft recipient.
        DB::table('direct_messages')->where('is_draft', true)->orderBy('id')->each(function (object $draft): void {
            $recipientId = DB::table('direct_message_recipients')->where('direct_message_id', $draft->id)->value('recipient_id');
            if ($recipientId !== null) {
                DB::table('direct_messages')->where('id', $draft->id)->update(['draft_recipient_id' => $recipientId]);
            }
        });
        DB::table('direct_message_recipients')
            ->whereIn('direct_message_id', fn ($q) => $q->select('id')->from('direct_messages')->where('is_draft', true))
            ->delete();
    }

    public function down(): void
    {
        // Restore the receipt each draft carried before up() folded it into the column (old model).
        DB::table('direct_messages')->where('is_draft', true)->whereNotNull('draft_recipient_id')->orderBy('id')->each(function (object $draft): void {
            DB::table('direct_message_recipients')->insert([
                'direct_message_id' => $draft->id,
                'recipient_id' => $draft->draft_recipient_id,
                'created_at' => $draft->created_at,
                'updated_at' => $draft->updated_at,
            ]);
        });

        Schema::table('direct_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('draft_recipient_id');
        });
    }
};
