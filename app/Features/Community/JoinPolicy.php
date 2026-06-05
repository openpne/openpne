<?php

namespace App\Features\Community;

/**
 * How a member joins a community. Successor of OpenPNE 3's community_config
 * `register_policy` ('open' | 'close'), flattened onto a typed communities column.
 *
 * Values start at 1: OpenPNE 3 stored this as a string, so there is no numeric to
 * preserve, and a 0 case invites PHP falsy-comparison bugs. (Support\Visibility keeps a
 * 0 case only because OpenPNE 3's 1-3 scale needs an extra below-members rung for sort.)
 */
enum JoinPolicy: int
{
    case Open = 1;

    case Approval = 2;

    /** String slug for serialization. Never pass the raw int to the frontend. */
    public function slug(): string
    {
        return match ($this) {
            self::Open => 'open',
            self::Approval => 'approval',
        };
    }

    /** Human-readable label key, translated via __() on either surface. */
    public function label(): string
    {
        return match ($this) {
            self::Open => 'Anyone can join',
            self::Approval => 'Approval required',
        };
    }
}
