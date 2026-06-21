<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Private messages between members (successor of OpenPNE 3 opMessagePlugin). Two tables, each a
 * faithful source for one OpenPNE 3 table so the upgrade copies them without a cross-table JOIN:
 *
 *   messages           <- SendMessageData (the sender's authored record)
 *   message_recipients <- MessageSendList (+ DeletedMessage folded into per-side soft-delete cols)
 *
 * OpenPNE 3's DeletedMessage (a trash index) collapses into the *_deleted_at / *_purged_at columns:
 * a side moves a message to trash (deleted_at) and later removes it for good (purged_at). Rows are
 * never hard-deleted on purge, so the other party's copy stays intact. Only personal messages
 * (OpenPNE 3 MessageType `message`) are modelled here; friend/community message-types were OpenPNE 3's
 * notification mechanism, carried by the OpenPNE 4 notification system instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            // Keep the message when its author is deleted (OpenPNE 3 Member onDelete: set null), so
            // the recipient's copy still renders with a withdrawn sender.
            $table->foreignId('sender_id')->nullable()->constrained('members')->nullOnDelete();
            // OpenPNE 3 subject/body are Doctrine `type: string` (no length) = MySQL TEXT; TEXT (not
            // VARCHAR) so migrated content is never truncated.
            $table->text('subject');
            $table->text('body');
            // Reply links (OpenPNE 3 return_message_id = direct parent, thread_message_id = root).
            // Nullable, no FK cascade: a GC purge may remove a referenced row, and the upgrade
            // null-normalizes any dangling reference rather than carry a broken self-FK.
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->unsignedBigInteger('thread_id')->nullable();
            // OpenPNE 3 is_send inverted: a draft is authored but not delivered to the recipient.
            $table->boolean('is_draft')->default(false);
            // Sender-side trash: moved to trash (deleted_at), then removed for good (purged_at).
            // Invariant: purged_at set => deleted_at set.
            $table->timestamp('sender_deleted_at')->nullable();
            $table->timestamp('sender_purged_at')->nullable();
            $table->timestamps();

            $table->index(['sender_id', 'is_draft', 'sender_deleted_at']);
            $table->index('thread_id');
        });

        Schema::create('message_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('messages')->cascadeOnDelete();
            // Keep the receipt when the recipient is deleted (OpenPNE 3 Member onDelete: set null).
            $table->foreignId('recipient_id')->nullable()->constrained('members')->nullOnDelete();
            // null = unread (OpenPNE 3 is_read=0).
            $table->timestamp('read_at')->nullable();
            // Recipient-side trash, mirroring the sender-side columns above.
            $table->timestamp('recipient_deleted_at')->nullable();
            $table->timestamp('recipient_purged_at')->nullable();
            $table->timestamps();

            $table->index(['recipient_id', 'recipient_deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_recipients');
        Schema::dropIfExists('messages');
    }
};
