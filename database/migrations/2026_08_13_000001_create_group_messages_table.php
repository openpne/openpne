<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('groups')->cascadeOnDelete();
            // Keep the message when its author is deleted (OpenPNE 3 opCommunityTopicPlugin sets its
            // Member relations null).
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->foreignId('in_reply_to_id')->nullable();
            $table->text('body');
            $table->timestamps();

            $table->foreign('in_reply_to_id')->references('id')->on('group_messages')->nullOnDelete();
            // No index leads with in_reply_to_id: InnoDB would adopt it as the FK's backing index
            // and then refuse to drop it (errno 1553).
            $table->index(['group_id', 'created_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_messages');
    }
};
