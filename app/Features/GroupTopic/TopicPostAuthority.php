<?php

namespace App\Features\GroupTopic;

/**
 * Values start at 1, the JoinPolicy convention. It gates posting topics only: commenting is open to
 * any member, and talk deliberately does not consult it (docs/internals/group-talk.md, "Access").
 */
enum TopicPostAuthority: int
{
    case Members = 1;

    case AdminsOnly = 2;

    /**
     * OpenPNE 3's stored community_config value, the seam the Classic edit form's radio ids keep
     * (`community_config_{field}_{value}`) so a site's CSS and scripts still find them.
     */
    public function op3Value(): string
    {
        return match ($this) {
            self::Members => 'public',
            self::AdminsOnly => 'admin_only',
        };
    }

    /** Never pass the raw int to the frontend. */
    public function slug(): string
    {
        return match ($this) {
            self::Members => 'members',
            self::AdminsOnly => 'admins_only',
        };
    }

    public static function fromSlug(string $slug): self
    {
        foreach (self::cases() as $case) {
            if ($case->slug() === $slug) {
                return $case;
            }
        }

        throw new \ValueError("Unknown TopicPostAuthority slug [{$slug}].");
    }

    /**
     * OpenPNE 3's own choice captions (community_config.yml topic_authority), translated via __()
     * on either surface.
     */
    public function label(): string
    {
        return match ($this) {
            self::Members => "%Community%'s members can create",
            self::AdminsOnly => "Only %community%'s admin can create",
        };
    }
}
