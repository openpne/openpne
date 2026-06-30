<?php

namespace App\Upgrade\Steps;

use App\Support\Visibility;
use App\Upgrade\Column;
use App\Upgrade\SourceRef;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `member` → OpenPNE 4 `members`.
 *
 * The login email, password hash, and profile-page visibility are not member-table columns
 * in OpenPNE 3 — they live in the `member_config` KV table — so they are pulled in with
 * correlated subqueries:
 *
 *  - email: PC address, falling back to the mobile address; neither present (e.g. an
 *    inactive pre-registration that only has `pc_address_pre`) yields NULL, i.e. a
 *    login-impossible member whose row is still preserved.
 *  - password: the bare 32-char MD5. INSERT...SELECT bypasses Eloquent, so the model's
 *    `hashed` cast does not fire and the legacy hash lands verbatim, to be rehashed to
 *    bcrypt on the member's first login.
 *  - profile_visibility: the SNS-wide sns_config[is_allow_config_public_flag_profile_page]
 *    when truthy (it overrides the per-member flag in OpenPNE 3's MemberTable::appendRules,
 *    so a stale member_config must not over-expose), else member_config[profile_page_public_flag],
 *    mapped onto Visibility (web=4→Open i.e. guest-viewable, friend=2→Friends, private=3→Private,
 *    SNS=1/unset→Members).
 *  - locale: member_config[language] (e.g. ja_JP) folded to a SUPPORTED_LOCALES slug, NULL for
 *    an unrecognised value (falls back to the session/Accept-Language chain at request time).
 *
 * The subqueries use the latest row per name (member_config has no (member_id, name) unique), so a
 * duplicate resolves deterministically rather than by storage order.
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
        // No OpenPNE 3 source; rely on the schema default (null).
        return ['email_verified_at', 'remember_token'];
    }

    public function gaps(): array
    {
        return [
            'invite_member_id' => 'Inviter reference; no corresponding column in the current members schema.',
            'is_active' => 'Account status flag; no corresponding column in the current members schema.',
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

    private function snsConfigValue(string $name): string
    {
        return '(SELECT `value` FROM '.SourceRef::table('sns_config')." WHERE `name` = '{$name}' LIMIT 1)";
    }

    /**
     * Effective profile-page public flag → Visibility. The SNS-wide
     * is_allow_config_public_flag_profile_page overrides the per-member flag when truthy
     * (OpenPNE 3 MemberTable::appendRules); only when empty/0 does the member's own flag apply.
     */
    private function profileVisibilityExpr(): string
    {
        $global = $this->snsConfigValue('is_allow_config_public_flag_profile_page');
        $member = $this->memberConfigValueLatest('profile_page_public_flag');
        $effective = "CASE WHEN {$global} IS NOT NULL AND {$global} NOT IN ('', '0') THEN {$global} ELSE {$member} END";

        return sprintf(
            "CASE (%s) WHEN '4' THEN %d WHEN '2' THEN %d WHEN '3' THEN %d ELSE %d END",
            $effective,
            Visibility::Open->value,
            Visibility::Friends->value,
            Visibility::Private->value,
            Visibility::Members->value,
        );
    }
}
