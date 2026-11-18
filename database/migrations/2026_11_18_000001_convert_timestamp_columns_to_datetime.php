<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * ALTER reads each TIMESTAMP in the session time_zone, so this must run on the application's own
 * connection to keep the wall-clock values the application read (docs/internals/runtime.md, "Site timezone").
 */
return new class extends Migration
{
    /** Named rather than discovered: an upgraded site keeps OpenPNE 3's and its own tables in the same database. */
    public const TABLES = [
        'admin_users', 'banner_images', 'banner_use_images', 'banners', 'diaries', 'diary_comments',
        'direct_message_recipients', 'direct_messages', 'email_change_requests', 'failed_jobs',
        'friend_requests', 'friendships', 'gadgets', 'group_categories', 'group_event_comments',
        'group_event_members', 'group_events', 'group_join_requests', 'group_members', 'group_messages',
        'group_topic_comments', 'group_topics', 'groups', 'home_issue_items', 'home_issues', 'link_cards',
        'mail_template_translations', 'mail_templates', 'member_blocks', 'member_images',
        'member_notification_settings', 'member_preferences', 'member_profiles', 'members',
        'mfa_reset_requests', 'navigations', 'notifications', 'oauth_access_tokens', 'oauth_clients',
        'passkeys', 'password_reset_tokens', 'personal_access_tokens', 'profile_options', 'profiles',
        'push_subscriptions', 'reactions', 'registration_tokens', 'timeline_posts',
    ];

    /**
     * The columns whose migrations declare useCurrent(); a default MySQL added on its own under
     * explicit_defaults_for_timestamp=OFF looks the same in information_schema, so it is dropped by name.
     */
    public const DATABASE_CLOCK_DEFAULTS = ['group_members.talk_read_at', 'failed_jobs.failed_at'];

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $grammar = DB::getQueryGrammar();

        foreach (self::TABLES as $table) {
            $modify = [];

            foreach (Schema::getColumns($table) as $column) {
                if ($column['type_name'] !== 'timestamp') {
                    continue;
                }

                $modify[] = sprintf(
                    'MODIFY %s %s %s%s',
                    $grammar->wrap($column['name']),
                    str_replace('timestamp', 'datetime', $column['type']),
                    $column['nullable'] ? 'NULL' : 'NOT NULL',
                    in_array("{$table}.{$column['name']}", self::DATABASE_CLOCK_DEFAULTS, true) ? ' DEFAULT CURRENT_TIMESTAMP' : '',
                );
            }

            if ($modify !== []) {
                DB::statement(sprintf('ALTER TABLE %s %s', $grammar->wrapTable($table), implode(', ', $modify)));
            }
        }
    }

    /** A DATETIME past 2038 has no TIMESTAMP to go back to. */
    public function down(): void {}
};
