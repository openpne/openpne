<?php

namespace App\Upgrade;

use App\Upgrade\Steps\CommunityUpgrade;
use App\Upgrade\Steps\DirectMessageUpgrade;

/**
 * OpenPNE 3's `member.is_active`, which the upgrade reads as "is this a member at all".
 *
 * It is not an ordinary column: `opActivateBehavior` puts a `preDqlSelect` listener on the Member
 * model that appends `is_active = 1 OR is_active IS NULL` to every DQL SELECT, so an inactive row
 * is absent from every listing, search, and member page in OpenPNE 3 — and `isSNSMember()` is that
 * same flag, so the account cannot use the site either. The row is a registration that never
 * completed: `MemberTable::createPre()` writes it when someone requests a signup link or an admin
 * sends an invite, and `opAuthAdapter::activate()` flips it only on the final step.
 *
 * OpenPNE 4 has no such state — `CompleteRegistration` creates the member row at completion and
 * holds the pending signup in `registration_tokens` — so `members` means "a real member", full
 * stop. Carrying an inactive row over would not just add a ghost to the member list: the OpenPNE 3
 * form saves the nickname, password, and address one request *before* activation, so an abandoned
 * signup arrives with working credentials and OpenPNE 4 (which gates login on the password and
 * `is_login_rejected` alone) would let it in. The upgrade therefore skips those rows, and no target
 * row may point at one.
 *
 * The predicate is the listener's condition verbatim, NULL included: the column is NOT NULL in the
 * 3.6+ DDL the upgrade targets, but OpenPNE 3 itself reads a NULL as active and a source restored
 * from an older schema should be read the same way.
 *
 * `references()` is the ledger of what that costs each source reference; see its docblock.
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
     * SQL boolean: `$table`.`$column` does not point at a skipped member. A NULL reference passes —
     * it names no one, and the OpenPNE 3 columns that allow it are the ON DELETE SET NULL ones whose
     * OpenPNE 4 counterpart is likewise nullable ("the member is gone").
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
     * Every OpenPNE 3 `table.column` that is a foreign key onto `member`(id), and what the upgrade
     * owes it. This ledger holds the two treatments a step cannot express on its own; the third,
     * dropping the row, is declared by the step itself (UpgradeStep::memberRefs()) so the guard sits
     * next to the mapping it applies to. UpgradeMatrixAuditTest checks the three sets are disjoint
     * and together cover the fixture's member FKs exactly, so neither a new step nor a new source
     * table can leave a reference unhandled.
     *
     * REFUSE is for the content tables. An inactive account holds no SNSMember credential, so in
     * stock OpenPNE 3 it cannot write a diary, a topic, an event, or a message — a row here breaks an
     * assumption rather than exercising a case, and dropping it would mean dropping its comments and
     * attachments too, silently, on a customised source no test covers. The preflight counts them
     * before the first write and names what it found instead.
     *
     * `scope` narrows the count to the rows that actually reach a target member column, and replaces
     * (not extends) the FROM step's own filter — so it must describe the complete set. It is needed
     * wherever the rows the upgrade reads are not simply "the FROM step's rows": a member reference
     * resolved by correlated subquery has its own predicate, and counting the rest would abort on data
     * the upgrade never looks at. Where that predicate picks one row out of several, the scope calls
     * the step's own selector rather than restating it, so the two cannot disagree about which row
     * counts. `scopeColumns` are the outer columns a scope reads beyond the reference itself, so the
     * structural check can require them (nothing else attributes them to a table).
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
            // Both paths out of this table at once — every receipt of a sent message, and the one
            // send-list row folded onto a draft's draft_recipient_id — since either can put the id in
            // a target column. The FROM step's filter would see only the sent half; counting every
            // row of a draft would refuse over the duplicates DirectMessageUpgrade itself discards.
            'message_send_list.member_id' => ['treatment' => self::REFUSE,
                'scope' => self::migratedSendListRow(), 'scopeColumns' => ['id', 'message_id']],
            // Only the latest admin_confirm row per community becomes pending_admin_member_id; the
            // other position names are read for the community role, which carries no member id, and an
            // older admin_confirm duplicate is never read at all.
            'community_member_position.member_id' => ['treatment' => self::REFUSE,
                'scope' => CommunityUpgrade::pendingAdminRowSelector(), 'scopeColumns' => ['id', 'name', 'community_id']],

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
     * The send-list rows of a migrated personal message whose member id reaches a target column: every
     * row of a sent one (DirectMessageRecipientUpgrade writes a receipt per row), and only the selected row
     * of a draft (DirectMessageUpgrade folds one onto draft_recipient_id and drops the rest).
     *
     * Both `is_send` values are tested, not one inferred from the other: the column is a bare
     * tinyint(1) with no CHECK, and the two steps read `= 1` and `= 0`, so a third value reaches
     * neither target column. This is a preflight, which exists to diagnose sources that are already
     * strange — inferring the range here would refuse a migration over a row it then ignores.
     */
    private static function migratedSendListRow(): string
    {
        return 'EXISTS (SELECT 1 FROM '.SourceRef::table('message').' `parent` '
            .'WHERE `parent`.`id` = `message_send_list`.`message_id` '
            .'AND '.DirectMessageUpgrade::isPersonalMessage('parent')
            .' AND (`parent`.`is_send` = 1 OR (`parent`.`is_send` = 0 AND '.DirectMessageUpgrade::draftRecipientRowSelector().')))';
    }
}
