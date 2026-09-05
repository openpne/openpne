<?php

namespace App\Features\Group;

/**
 * OpenPNE 3 modelled roles as separate community_member_position rows (admin / sub_admin /
 * *_confirm); OpenPNE 4 flattens them onto one group_members.role column. Ascending value is
 * stronger privilege, and values start at 1 for the reason JoinPolicy gives.
 */
enum GroupRole: int
{
    case Member = 1;

    case SubAdmin = 2;

    case Admin = 3;

    public function canManage(): bool
    {
        return $this === self::Admin || $this === self::SubAdmin;
    }

    /** The OpenPNE 3 community_member_position name this role maps from; a plain member had none. */
    public function op3PositionName(): ?string
    {
        return match ($this) {
            self::Admin => 'admin',
            self::SubAdmin => 'sub_admin',
            self::Member => null,
        };
    }

    /** Never pass the raw int to the frontend. */
    public function slug(): string
    {
        return match ($this) {
            self::Member => 'member',
            self::SubAdmin => 'sub_admin',
            self::Admin => 'admin',
        };
    }

    /** Human-readable label key, translated via __() on either surface. */
    public function label(): string
    {
        return match ($this) {
            self::Member => 'Member',
            self::SubAdmin => 'Sub-admin',
            self::Admin => 'Admin',
        };
    }
}
