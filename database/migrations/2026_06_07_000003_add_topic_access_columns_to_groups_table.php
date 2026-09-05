<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            // Frozen literal (not TopicReadAccess::Everyone->value) so a later enum change cannot
            // drift this default.
            $table->unsignedTinyInteger('topic_read_access')->default(1); // TopicReadAccess::Everyone
            // Frozen literal (not TopicPostAuthority::Members->value) so a later enum change cannot
            // drift this default.
            $table->unsignedTinyInteger('topic_post_authority')->default(1); // TopicPostAuthority::Members
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropColumn(['topic_read_access', 'topic_post_authority']);
        });
    }
};
