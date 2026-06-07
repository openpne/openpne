<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Topic-board access settings on the community aggregate, flattened from OpenPNE 3
 * community_config (the same treatment register_policy / description get). OpenPNE 3 keeps these
 * in the opCommunityTopicPlugin config namespace; here they live on communities because they
 * gate a community-scoped board, as register_policy gates joining.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communities', function (Blueprint $table) {
            // Who may READ this community's topics: Everyone=1 (any signed-in member) /
            // MembersOnly=2. From community_config[public_flag] ('public' | 'auth_commu_member').
            // Default Everyone matches OpenPNE 3's config default ("public"). Frozen literal (not
            // TopicReadAccess::Everyone->value) so a later enum change cannot drift this default.
            $table->unsignedTinyInteger('topic_read_access')->default(1); // TopicReadAccess::Everyone
            // Who may POST topics: Members=1 / AdminsOnly=2. From community_config[topic_authority]
            // ('public' | 'admin_only'). Default Members matches OpenPNE 3's config default
            // ("public"). Frozen literal.
            $table->unsignedTinyInteger('topic_post_authority')->default(1); // TopicPostAuthority::Members
        });
    }

    public function down(): void
    {
        Schema::table('communities', function (Blueprint $table) {
            $table->dropColumn(['topic_read_access', 'topic_post_authority']);
        });
    }
};
