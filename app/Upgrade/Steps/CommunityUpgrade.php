<?php

namespace App\Upgrade\Steps;

use App\Features\Community\JoinPolicy;
use App\Features\CommunityTopic\TopicPostAuthority;
use App\Features\CommunityTopic\TopicReadAccess;
use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `community` → OpenPNE 4 `communities`.
 *
 * `register_policy` and `description` are not community-table columns in OpenPNE 3 — they live in
 * the `community_config` KV table — so they are pulled in with correlated subqueries (the
 * member_config → members treatment):
 *
 *  - register_policy: community_config[register_policy] ('open' | 'close') → JoinPolicy. The CASE
 *    reads the runtime enum so it cannot drift; a missing/empty/unknown value falls to Open, which
 *    is OpenPNE 3's own config default ("open").
 *  - topic_read_access / topic_post_authority: community_config[public_flag] / [topic_authority]
 *    → TopicReadAccess / TopicPostAuthority (the topic board's read/post gates), the same
 *    KV→typed-column flatten, defaulting to the OpenPNE 3 config default ("public").
 *  - description: community_config[description], or NULL when absent.
 *  - pending_admin_member_id: the single community_member_position[name=admin_confirm] member (the
 *    pending target of an admin transfer); NULL when none. The transfer handshake itself is deferred.
 *  - community_category_id: nulled when it points at a category that was not migrated — the
 *    OpenPNE 3 root (lft=1) is dropped by CommunityCategoryUpgrade — so the target FK holds.
 *
 * The binary top image is deferred to the file step: communities.file_id relies on its null default
 * (targetDefaults), and the source community.file_id is recorded as a gap. file_id is NOT a pending
 * target — a pending target would make InsertSelectCompiler refuse the whole community body.
 *
 * The subqueries name community_config / community_member_position / community_category unqualified,
 * so (like MemberUpgrade's member_config subqueries) they are not rewritten for a source prefix or
 * separate source database — acceptable for the fleet (empty prefix, same database). They use the
 * latest row per name where the KV table has no uniqueness, so duplicates resolve deterministically.
 */
class CommunityUpgrade extends UpgradeStep
{
    protected string $source = 'community';

    protected string $target = 'communities';

    public function columns(): array
    {
        return [
            'id' => Column::source('id'),
            'name' => Column::source('name'),
            'description' => Column::expr($this->configValueLatest('description'), uses: ['id']),
            'register_policy' => Column::expr($this->registerPolicyExpr(), uses: ['id']),
            'topic_read_access' => Column::expr($this->topicReadAccessExpr(), uses: ['id']),
            'topic_post_authority' => Column::expr($this->topicPostAuthorityExpr(), uses: ['id']),
            'community_category_id' => Column::expr($this->categoryIdExpr(), uses: ['community_category_id']),
            'pending_admin_member_id' => Column::expr($this->pendingAdminExpr(), uses: ['id']),
            'created_at' => Column::source('created_at'),
            'updated_at' => Column::source('updated_at'),
        ];
    }

    public function targetDefaults(): array
    {
        // Top-image binary is migrated by the (pending) file step; rely on the null default for now.
        return ['file_id'];
    }

    public function gaps(): array
    {
        return [
            'file_id' => 'Top-image file reference; the binary migration is deferred to the file step, so communities.file_id relies on its null default for now.',
        ];
    }

    /** The latest `community_config` value for a name (the KV table has no uniqueness), else NULL. */
    private function configValueLatest(string $name): string
    {
        return "(SELECT `value` FROM `community_config` WHERE `community_id` = `community`.`id` AND `name` = '{$name}' ORDER BY `id` DESC LIMIT 1)";
    }

    /**
     * community_config[register_policy] → JoinPolicy. 'close' = approval; 'open'/missing/empty/
     * unknown = open (OpenPNE 3's config default). Each branch reads the runtime enum so it cannot
     * drift.
     */
    private function registerPolicyExpr(): string
    {
        return sprintf(
            "CASE %s WHEN 'close' THEN %d WHEN 'open' THEN %d ELSE %d END",
            $this->configValueLatest('register_policy'),
            JoinPolicy::Approval->value,
            JoinPolicy::Open->value,
            JoinPolicy::Open->value,
        );
    }

    /**
     * community_config[public_flag] → TopicReadAccess. 'auth_commu_member' = members-only;
     * 'public'/missing/empty/unknown = everyone (OpenPNE 3's config default). Runtime enum so it
     * cannot drift.
     */
    private function topicReadAccessExpr(): string
    {
        return sprintf(
            "CASE %s WHEN 'auth_commu_member' THEN %d WHEN 'public' THEN %d ELSE %d END",
            $this->configValueLatest('public_flag'),
            TopicReadAccess::MembersOnly->value,
            TopicReadAccess::Everyone->value,
            TopicReadAccess::Everyone->value,
        );
    }

    /**
     * community_config[topic_authority] → TopicPostAuthority. 'admin_only' = admins-only;
     * 'public'/missing/empty/unknown = members (OpenPNE 3's config default). Runtime enum so it
     * cannot drift.
     */
    private function topicPostAuthorityExpr(): string
    {
        return sprintf(
            "CASE %s WHEN 'admin_only' THEN %d WHEN 'public' THEN %d ELSE %d END",
            $this->configValueLatest('topic_authority'),
            TopicPostAuthority::AdminsOnly->value,
            TopicPostAuthority::Members->value,
            TopicPostAuthority::Members->value,
        );
    }

    /** The pending admin-transfer target (community_member_position[name=admin_confirm]), else NULL. */
    private function pendingAdminExpr(): string
    {
        return "(SELECT `member_id` FROM `community_member_position` WHERE `community_id` = `community`.`id` AND `name` = 'admin_confirm' ORDER BY `id` DESC LIMIT 1)";
    }

    /**
     * Keep community_category_id only when it references a migrated category (lft>1 in the source);
     * the dropped root (lft=1) and any dangling reference become NULL so the target FK holds.
     */
    private function categoryIdExpr(): string
    {
        return 'CASE WHEN EXISTS ('
            .'SELECT 1 FROM `community_category` `c` '
            .'WHERE `c`.`id` = `community`.`community_category_id` AND `c`.`lft` > 1'
            .') THEN `community_category_id` ELSE NULL END';
    }
}
