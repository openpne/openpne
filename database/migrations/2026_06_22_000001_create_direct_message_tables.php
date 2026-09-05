<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('direct_messages', function (Blueprint $table) {
            $table->id();
            // Keep the message when its author is deleted (OpenPNE 3 Member onDelete: set null), so
            // the recipient's copy still renders with a withdrawn sender.
            $table->foreignId('sender_id')->nullable()->constrained('members')->nullOnDelete();
            // OpenPNE 3 subject/body are Doctrine `type: string` with no length and no notnull =
            // nullable MySQL TEXT, so the upgrade copies legacy rows verbatim.
            $table->text('subject')->nullable();
            $table->text('body')->nullable();
            // No self-FK: a purge may remove a referenced row, and the upgrade null-normalizes any
            // dangling reference (OpenPNE 3 return_message_id = parent, thread_message_id = root).
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->unsignedBigInteger('thread_id')->nullable();
            // OpenPNE 3 is_send inverted: a draft is authored but not delivered to the recipient.
            $table->boolean('is_draft')->default(false);
            $table->timestamp('sender_deleted_at')->nullable();
            $table->timestamp('sender_purged_at')->nullable();
            $table->timestamps();

            $table->index(['sender_id', 'is_draft', 'sender_deleted_at']);
            $table->index('thread_id');
        });

        Schema::create('direct_message_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('direct_message_id')->constrained('direct_messages')->cascadeOnDelete();
            // Keep the receipt when the recipient is deleted (OpenPNE 3 Member onDelete: set null).
            $table->foreignId('recipient_id')->nullable()->constrained('members')->nullOnDelete();
            // null = unread (OpenPNE 3 is_read=0).
            $table->timestamp('read_at')->nullable();
            $table->timestamp('recipient_deleted_at')->nullable();
            $table->timestamp('recipient_purged_at')->nullable();
            $table->timestamps();

            // Named: the conventional name exceeds MySQL's 64-character identifier limit.
            $table->index(['recipient_id', 'recipient_deleted_at'], 'direct_message_recipients_recipient_id_deleted_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('direct_message_recipients');
        Schema::dropIfExists('direct_messages');
    }
};
