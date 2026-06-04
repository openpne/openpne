<?php

namespace App\Upgrade;

use App\Upgrade\Steps\DiaryCommentUpgrade;
use App\Upgrade\Steps\DiaryUpgrade;
use App\Upgrade\Steps\FriendRequestUpgrade;
use App\Upgrade\Steps\FriendshipUpgrade;
use App\Upgrade\Steps\MemberBlockUpgrade;
use App\Upgrade\Steps\MemberPreferenceUpgrade;
use App\Upgrade\Steps\MemberProfileUpgrade;
use App\Upgrade\Steps\MemberUpgrade;
use App\Upgrade\Steps\ProfileOptionTranslationUpgrade;
use App\Upgrade\Steps\ProfileOptionUpgrade;
use App\Upgrade\Steps\ProfileTranslationUpgrade;
use App\Upgrade\Steps\ProfileUpgrade;

/** The upgrade steps in run order. Adding a feature = adding its step here. */
final class StepRegistry
{
    /** @return list<class-string<UpgradeStep>> */
    public static function classes(): array
    {
        return [
            MemberUpgrade::class,
            // member_preferences references members; only the member step must precede it.
            MemberPreferenceUpgrade::class,
            FriendshipUpgrade::class,
            FriendRequestUpgrade::class,
            MemberBlockUpgrade::class,
            DiaryUpgrade::class,
            // diary_comments.diary_id references diaries.id, so comments run after diaries.
            DiaryCommentUpgrade::class,
            // Profile definitions before member values (FK order: a member_profile row
            // references profiles and profile_options).
            ProfileUpgrade::class,
            ProfileOptionUpgrade::class,
            ProfileTranslationUpgrade::class,
            ProfileOptionTranslationUpgrade::class,
            MemberProfileUpgrade::class,
        ];
    }

    /** @return list<UpgradeStep> */
    public static function all(): array
    {
        return array_map(static fn (string $class): UpgradeStep => new $class, self::classes());
    }

    /**
     * OpenPNE 3 source tables that already have an OpenPNE 4 successor table but no
     * upgrade step yet, each with the reason. Recorded so the un-migrated data shows
     * up in the matrix instead of being an invisible omission (the per-step audit
     * only sees source tables a step reads).
     *
     * @return array<string, string> source table => reason
     */
    public static function deferredSourceTables(): array
    {
        return [
            'file' => 'OpenPNE 3 file metadata. The upgrade maps file ownership onto the related_entity columns, which needs the OpenPNE 4 tables that own files (avatars, attachments) to exist first; no step yet.',
            'file_bin' => 'OpenPNE 3 file bytes. Migrated by a metadata-only FK rewire onto `files` (the file_bin schema is frozen for exactly that), not a BLOB copy; pending the `file` step.',
        ];
    }

    /**
     * Disposition of each known OpenPNE 3 `member_config` name. member_config is a KV table read
     * by several steps via subquery (not one source→target step), so the per-step column audit
     * cannot show which names migrate and which are dropped; this is that per-name coverage.
     * A name absent from this map is an unrecognised custom config (third-party plugin or source
     * customisation) the upgrade does not migrate — a data-driven count of such names at run time
     * is a follow-up once the upgrade has a source-connected runner.
     *
     * @return array<string, string> member_config name => where it goes / why it is dropped
     */
    public static function memberConfigDispositions(): array
    {
        return [
            // Migrated to typed members columns / the preference store.
            'pc_address' => 'members.email (PC address preferred), MemberUpgrade.',
            'mobile_address' => 'members.email fallback when no PC address, MemberUpgrade.',
            'password' => 'members.password (legacy hash, rehashed on first login), MemberUpgrade.',
            'profile_page_public_flag' => 'members.profile_visibility, MemberUpgrade.',
            'language' => 'members.locale (ja_JP→ja, …), MemberUpgrade.',
            'diary_public_flag' => 'member_preferences[diary_default_visibility], MemberPreferenceUpgrade.',
            'age_public_flag' => 'member_preferences[age_visibility], MemberPreferenceUpgrade.',
            // Owned by another OpenPNE 4 surface, migrated with that feature (not here).
            'is_send_*_mail / is_send_*_web' => 'Per-member notification opt-in/out — the notification centre store, not the scalar preference store.',
            'op_screen_name' => 'members.screen_name (unique handle) — lands with the timeline feature.',
            // Intentionally dropped: no OpenPNE 4 consumer.
            'time_zone' => 'Dropped: no per-member timezone rendering in OpenPNE 4.',
            'daily_news' => 'Dropped: daily-news digest is not in scope.',
            'secret_question' => 'Dropped: secret-question recovery is not in scope.',
            'secret_answer' => 'Dropped: secret-question recovery is not in scope.',
            'mobile_uid' => 'Dropped: mobile (feature-phone) frontend is not in scope.',
            'mobile_cookie_uid' => 'Dropped: mobile (feature-phone) frontend is not in scope.',
            'lastLogin' => 'Dropped: login state, not a preference; not tracked in member_config.',
            'api_key' => 'Dropped: API authentication is handled by the framework, not member_config.',
            'mail_address_hash' => 'Dropped: derived lookup hash, recomputable when needed.',
            'is_admin_invited' => 'Dropped: registration-flow flag with no OpenPNE 4 successor.',
            'remember_key' => 'Dropped: superseded by the framework remember_token column.',
            'register_token' => 'Dropped: registration/confirmation-flow token, transient.',
            'register_auth_mode' => 'Dropped: registration-flow state, transient.',
            'pc_address_pre' => 'Dropped: pending email-change confirmation, handled by Laravel verification.',
            'pc_address_token' => 'Dropped: pending email-change confirmation token.',
            'mobile_address_pre' => 'Dropped: mobile frontend not in scope.',
            'mobile_address_token' => 'Dropped: mobile frontend not in scope.',
        ];
    }
}
