<?php

namespace App\Upgrade;

use App\Upgrade\Steps\DirectMessageUpgrade;
use App\Upgrade\Steps\GroupUpgrade;

/**
 * OpenPNE 3 `member.is_active` read as "is this a member at all": an inactive row is a registration
 * that never completed, and the upgrade neither carries it nor lets a target row point at it
 * (docs/internals/upgrade.md, "Members who never activated").
 */
final class ActiveMember
{
    /** Read only as a correlation, or by no step at all: contributes no member id to any target row. */
    public const UNUSED = 'unused';

    /** SourcePreflight counts these and refuses to start on a non-zero count. */
    public const REFUSE = 'refuse';

    /** SQL boolean: the `member` row under $alias is one the upgrade carries. */
    public static function predicate(string $alias = 'member'): string
    {
        return "(`{$alias}`.`is_active` = 1 OR `{$alias}`.`is_active` IS NULL)";
    }

    /**
     * SQL boolean: `$table.$column` is NULL or names a member the upgrade carries. A NULL reference
     * passes: the OpenPNE 3 columns that allow it are ON DELETE SET NULL, and their OpenPNE 4
     * counterparts are nullable too.
     */
    public static function referenceGuard(string $table, string $column): string
    {
        return "(`{$table}`.`{$column}` IS NULL OR EXISTS (SELECT 1 FROM ".SourceRef::table('member').' `active_member`'
            ." WHERE `active_member`.`id` = `{$table}`.`{$column}` AND ".self::predicate('active_member').'))';
    }

    /** SQL boolean: `$table`.`$column` names a member row that is not in the source at all. */
    public static function danglingReference(string $table, string $column): string
    {
        return "(`{$table}`.`{$column}` IS NOT NULL AND NOT EXISTS (SELECT 1 FROM ".SourceRef::table('member').' `any_member`'
            ." WHERE `any_member`.`id` = `{$table}`.`{$column}`))";
    }

    /**
     * Every OpenPNE 3 FK onto `member.id` that no step drops through memberRefs(), with its treatment
     * (docs/internals/upgrade.md, "Members who never activated"). A REFUSE `scope` replaces the FROM
     * step's filter and must describe every row whose member id reaches a target column;
     * `scopeColumns` are the extra columns it reads.
     *
     * @return array<string, array{treatment: string, scope?: string, scopeColumns?: list<string>, reason?: string}>
     */
    public static function references(): array
    {
        return [
            // --- REFUSE: content, where an inactive author is an assumption violation ---
            'diary.member_id' => ['treatment' => self::REFUSE],
            'diary_comment.member_id' => ['treatment' => self::REFUSE],
            'community_topic.member_id' => ['treatment' => self::REFUSE],
            'community_topic_comment.member_id' => ['treatment' => self::REFUSE],
            'community_event.member_id' => ['treatment' => self::REFUSE],
            'community_event_comment.member_id' => ['treatment' => self::REFUSE],
            'community_event_member.member_id' => ['treatment' => self::REFUSE],
            // DirectMessageUpgrade's own filter scopes this to the personal-message type it migrates.
            'message.member_id' => ['treatment' => self::REFUSE],
            // Scoped to both paths out of this table (every receipt of a sent message, and the one
            // send-list row folded onto a draft), not the FROM step's sent-only filter.
            'message_send_list.member_id' => ['treatment' => self::REFUSE,
                'scope' => self::migratedSendListRow(), 'scopeColumns' => ['id', 'message_id']],
            // Only the latest admin_confirm row per community becomes pending_admin_member_id; the
            // other position names are read for the community role, which carries no member id, and an
            // older admin_confirm duplicate is never read at all.
            'community_member_position.member_id' => ['treatment' => self::REFUSE,
                'scope' => GroupUpgrade::pendingAdminRowSelector(), 'scopeColumns' => ['id', 'name', 'community_id']],

            // --- UNUSED: no member id reaches a target row through these ---
            'member.invite_member_id' => ['treatment' => self::UNUSED,
                'reason' => 'Gapped by MemberUpgrade: the inviter is not carried, so no target column holds it.'],
            'deleted_message.member_id' => ['treatment' => self::UNUSED,
                'reason' => "Correlates a message's trash/purge state with its own sender; produces a timestamp, not a member id."],
            'activity_data.member_id' => ['treatment' => self::UNUSED, 'reason' => 'No step reads it (the timeline is not migrated).'],
            'diary_comment_unread.member_id' => ['treatment' => self::UNUSED, 'reason' => 'No step reads it (per-member read state is not migrated).'],
            'diary_comment_update.member_id' => ['treatment' => self::UNUSED, 'reason' => 'No step reads it (per-member read state is not migrated).'],
            'nice.member_id' => ['treatment' => self::UNUSED, 'reason' => 'No step reads it (opLikePlugin has no OpenPNE 4 counterpart yet).'],
            'o_auth_member_token.member_id' => ['treatment' => self::UNUSED, 'reason' => 'No step reads it (OAuth is not migrated).'],
            'oauth_consumer.member_id' => ['treatment' => self::UNUSED, 'reason' => 'No step reads it (OAuth is not migrated).'],
            'openid_trust_log.member_id' => ['treatment' => self::UNUSED, 'reason' => 'No step reads it (OpenID is not migrated).'],
        ];
    }

    /**
     * Send-list rows whose member id reaches a target column: every row of a sent personal message,
     * and the selected row of a draft. Both `is_send` values are tested, not one inferred from the
     * other: the column is a bare tinyint with no CHECK, so a third value reaches neither target
     * column.
     */
    private static function migratedSendListRow(): string
    {
        return 'EXISTS (SELECT 1 FROM '.SourceRef::table('message').' `parent` '
            .'WHERE `parent`.`id` = `message_send_list`.`message_id` '
            .'AND '.DirectMessageUpgrade::isPersonalMessage('parent')
            .' AND (`parent`.`is_send` = 1 OR (`parent`.`is_send` = 0 AND '.DirectMessageUpgrade::draftRecipientRowSelector().')))';
    }
}
