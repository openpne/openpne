<?php

namespace App\Features\GroupTopic;

/**
 * Who may post topics to a group.
 *
 * Values start at 1 (the JoinPolicy convention): OpenPNE 3 stored this as a string, so there is
 * no numeric to preserve, and a 0 case invites PHP falsy-comparison bugs.
 *
 * Note this gates posting topics only. Commenting on a topic is open to any member regardless of
 * this setting, enforced in GroupTopicAccess.
 */
enum TopicPostAuthority: int
{
    /** Any group member may post. */
    case Members = 1;

    /** Only group admins may post. */
    case AdminsOnly = 2;

    /** String slug for serialization. Never pass the raw int to the frontend. */
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
     * Label key, translated via __() on either surface. OpenPNE 3's own choice captions
     * (community_config.yml topic_authority), which its edit form and group home both printed.
     */
    public function label(): string
    {
        return match ($this) {
            self::Members => "%Community%'s members can create",
            self::AdminsOnly => "Only %community%'s admin can create",
        };
    }
}
