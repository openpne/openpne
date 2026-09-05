<?php

namespace App\Features\Group;

/**
 * Values start at 1: OpenPNE 3 stored this as a string, so there is no numeric to preserve, and a
 * 0 case invites PHP falsy-comparison bugs.
 */
enum JoinPolicy: int
{
    case Open = 1;

    case Approval = 2;

    /**
     * OpenPNE 3's stored community_config value, the seam the Classic edit form's radio ids keep
     * (`community_config_{field}_{value}`) so a site's CSS and scripts still find them.
     */
    public function op3Value(): string
    {
        return match ($this) {
            self::Open => 'open',
            self::Approval => 'close',
        };
    }

    /** Never pass the raw int to the frontend. */
    public function slug(): string
    {
        return match ($this) {
            self::Open => 'open',
            self::Approval => 'approval',
        };
    }

    public static function fromSlug(string $slug): self
    {
        foreach (self::cases() as $case) {
            if ($case->slug() === $slug) {
                return $case;
            }
        }

        throw new \ValueError("Unknown JoinPolicy slug [{$slug}].");
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
