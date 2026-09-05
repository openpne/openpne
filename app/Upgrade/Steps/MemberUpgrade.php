<?php

namespace App\Upgrade\Steps;

use App\Support\Visibility;
use App\Upgrade\ActiveMember;
use App\Upgrade\Column;
use App\Upgrade\SourceRef;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `member` → OpenPNE 4 `members`, activated members only (ActiveMember). The login email,
 * password hash, profile-page visibility and locale live in the `member_config` KV table, so they
 * are pulled in by correlated subquery, latest row per name (docs/internals/upgrade.md); the password
 * lands as the bare MD5 for PasswordWrap, since INSERT...SELECT bypasses the `hashed` cast.
 */
class MemberUpgrade extends UpgradeStep
{
    protected string $source = 'member';

    protected string $target = 'members';

    public function columns(): array
    {
        return [
            'id' => Column::source('id'),
            'name' => Column::source('name'),
            'email' => Column::expr($this->memberConfigCoalesce('pc_address', 'mobile_address'), uses: ['id']),
            'password' => Column::expr($this->memberConfigValueLatest('password'), uses: ['id']),
            'is_login_rejected' => Column::source('is_login_rejected'),
            'profile_visibility' => Column::expr($this->profileVisibilityExpr(), uses: ['id']),
            'locale' => Column::expr($this->localeExpr(), uses: ['id']),
            'created_at' => Column::source('created_at'),
            'updated_at' => Column::source('updated_at'),
        ];
    }

    public function targetDefaults(): array
    {
        // No OpenPNE 3 source: password_scheme is set by PasswordWrap, the two_factor_* columns are
        // MFA state a member sets up after the move, and avatar_color and owner_member_id are
        // OpenPNE 4-native choices (every migrated member is a person).
        return ['email_verified_at', 'password_scheme', 'remember_token',
            'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at',
            'avatar_color', 'owner_member_id'];
    }

    /** Only activated members are carried; ActiveMember explains what the inactive rows are. */
    public function filter(): ?string
    {
        return ActiveMember::predicate();
    }

    public function filterColumns(): array
    {
        return ['is_active'];
    }

    public function gaps(): array
    {
        return [
            'invite_member_id' => 'Inviter reference; no corresponding column in the current members schema. A pending invite is not carried either — the invitee is an inactive member the filter drops.',
        ];
    }

    /** First non-empty `member_config` value across the given names, else NULL. */
    private function memberConfigCoalesce(string ...$names): string
    {
        $parts = array_map(
            fn (string $name): string => "NULLIF({$this->memberConfigValueLatest($name)}, '')",
            $names,
        );

        return 'COALESCE('.implode(', ', $parts).')';
    }

    /** The latest `member_config` value for a name (no (member_id, name) unique exists), else NULL. */
    private function memberConfigValueLatest(string $name): string
    {
        return '(SELECT `value` FROM '.SourceRef::table('member_config')." WHERE `member_id` = `member`.`id` AND `name` = '{$name}' ORDER BY `id` DESC LIMIT 1)";
    }

    /**
     * member_config[language] (e.g. ja_JP, en_US) → a SUPPORTED_LOCALES slug, or NULL for an
     * unrecognised value so the request-time chain (session/Accept-Language) decides instead.
     */
    private function localeExpr(): string
    {
        $lang = $this->memberConfigValueLatest('language');

        return "CASE WHEN {$lang} LIKE 'ja%' THEN 'ja' WHEN {$lang} LIKE 'en%' THEN 'en' ELSE NULL END";
    }

    /**
     * The member's own profile_page_public_flag alone: the SNS-wide override travels as
     * SnsSettingKey::ProfileVisibilityPolicy and is applied on read (docs/internals/member-profile.md, "Profile page audience").
     */
    private function profileVisibilityExpr(): string
    {
        return sprintf(
            "CASE %s WHEN '4' THEN %d ELSE %d END",
            $this->memberConfigValueLatest('profile_page_public_flag'),
            Visibility::Open->value,
            Visibility::Members->value,
        );
    }
}
