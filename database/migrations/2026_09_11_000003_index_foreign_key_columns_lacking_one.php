<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Introspection rather than a driver gate, so InnoDB's own foreign-key indexes are never duplicated
 * (docs/internals/ordering.md, "SQLite foreign-key indexes").
 */
return new class extends Migration
{
    /** @var list<array{string, string}> every single-column foreign key no index led when this ran */
    private const COLUMNS = [
        ['banner_images', 'file_id'],
        ['banner_use_images', 'banner_image_id'],
        ['banner_use_images', 'banner_id'],
        ['diary_comment_images', 'file_id'],
        ['diary_images', 'file_id'],
        ['direct_message_files', 'file_id'],
        ['direct_message_recipients', 'direct_message_id'],
        ['direct_messages', 'draft_recipient_id'],
        ['gadget_configs', 'gadget_id'],
        ['group_categories', 'parent_id'],
        ['group_event_comment_images', 'file_id'],
        ['group_event_comments', 'member_id'],
        ['group_event_images', 'file_id'],
        ['group_event_members', 'member_id'],
        ['group_events', 'member_id'],
        ['group_members', 'member_id'],
        ['group_message_images', 'file_id'],
        ['group_message_mentions', 'member_id'],
        ['group_messages', 'member_id'],
        ['group_topic_comment_images', 'file_id'],
        ['group_topic_comments', 'member_id'],
        ['group_topic_images', 'file_id'],
        ['group_topics', 'member_id'],
        ['groups', 'file_id'],
        ['groups', 'pending_admin_member_id'],
        ['groups', 'group_category_id'],
        ['link_cards', 'image_file_id'],
        ['member_images', 'file_id'],
        ['member_profiles', 'profile_id'],
        ['registration_tokens', 'inviter_id'],
        ['timeline_post_images', 'file_id'],
        ['timeline_post_mentions', 'member_id'],
        ['timeline_posts', 'in_reply_to_id'],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as [$table, $column]) {
            if ($this->hasLeadingIndex($table, $column)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($column) {
                $t->index($column);
            });
        }
    }

    public function down(): void
    {
        // The default index name is the mark of an index this migration created; InnoDB's are *_foreign.
        foreach (self::COLUMNS as [$table, $column]) {
            if (Schema::hasIndex($table, "{$table}_{$column}_index")) {
                Schema::table($table, function (Blueprint $t) use ($column) {
                    $t->dropIndex([$column]);
                });
            }
        }
    }

    private function hasLeadingIndex(string $table, string $column): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['columns'][0] ?? null) === $column) {
                return true;
            }
        }

        return false;
    }
};
