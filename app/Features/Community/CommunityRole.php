<?php

namespace App\Features\Community;

/**
 * A member's role in a community. OpenPNE 3 modelled roles as separate
 * community_member_position rows (admin / sub_admin / *_confirm); OpenPNE 4 flattens
 * them onto a single community_members.role column.
 *
 * Ascending value = stronger privilege (Member < SubAdmin < Admin). Values start at 1
 * (see JoinPolicy for why 0 is avoided).
 */
enum CommunityRole: int
{
    case Member = 1;

    case SubAdmin = 2;

    case Admin = 3;

    /** Whether the role may edit community settings and moderate (admin or sub-admin). */
    public function canManage(): bool
    {
        return $this === self::Admin || $this === self::SubAdmin;
    }

    /** String slug for serialization. Never pass the raw int to the frontend. */
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
