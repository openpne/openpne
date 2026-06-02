<?php

namespace App\Upgrade;

use App\Upgrade\Steps\DiaryUpgrade;
use App\Upgrade\Steps\FriendRequestUpgrade;
use App\Upgrade\Steps\FriendshipUpgrade;
use App\Upgrade\Steps\MemberBlockUpgrade;
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
            FriendshipUpgrade::class,
            FriendRequestUpgrade::class,
            MemberBlockUpgrade::class,
            DiaryUpgrade::class,
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
}
