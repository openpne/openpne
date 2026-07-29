<?php

namespace App\Features\CommunityTopic;

/**
 * Who may post topics to a community.
 *
 * Values start at 1 (the JoinPolicy convention): OpenPNE 3 stored this as a string, so there is
 * no numeric to preserve, and a 0 case invites PHP falsy-comparison bugs.
 *
 * Note this gates posting topics only. Commenting on a topic is open to any member regardless of
 * this setting, enforced in CommunityTopicAccess.
 */
enum TopicPostAuthority: int
{
    /** Any community member may post. */
    case Members = 1;

    /** Only community admins may post. */
    case AdminsOnly = 2;

    /** String slug for serialization. Never pass the raw int to the frontend. */
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
     * (community_config.yml topic_authority), which its edit form and community home both printed.
     */
    public function label(): string
    {
        return match ($this) {
            self::Members => "%Community%'s members can create",
            self::AdminsOnly => "Only %community%'s admin can create",
        };
    }
}
