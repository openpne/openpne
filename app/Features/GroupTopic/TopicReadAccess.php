<?php

namespace App\Features\GroupTopic;

/**
 * Values start at 1, the JoinPolicy convention. The column is the one read gate the board, events
 * and talk all share (docs/internals/group-talk.md, "Access").
 */
enum TopicReadAccess: int
{
    /** Any signed-in member may read. */
    case Everyone = 1;

    case MembersOnly = 2;

    /**
     * OpenPNE 3's stored community_config value, the seam the Classic edit form's radio ids keep
     * (`community_config_{field}_{value}`) so a site's CSS and scripts still find them.
     */
    public function op3Value(): string
    {
        return match ($this) {
            self::Everyone => 'public',
            self::MembersOnly => 'auth_commu_member',
        };
    }

    /** Never pass the raw int to the frontend. */
    public function slug(): string
    {
        return match ($this) {
            self::Everyone => 'everyone',
            self::MembersOnly => 'members_only',
        };
    }

    public static function fromSlug(string $slug): self
    {
        foreach (self::cases() as $case) {
            if ($case->slug() === $slug) {
                return $case;
            }
        }

        throw new \ValueError("Unknown TopicReadAccess slug [{$slug}].");
    }

    /**
     * OpenPNE 3's own choice captions (community_config.yml public_flag), translated via __() on
     * either surface.
     */
    public function label(): string
    {
        return match ($this) {
            self::Everyone => 'Everyone can read',
            self::MembersOnly => "Only %community%'s members can read",
        };
    }
}
