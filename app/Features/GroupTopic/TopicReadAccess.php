<?php

namespace App\Features\GroupTopic;

/**
 * Who may read a group's topics.
 *
 * Values start at 1 (the JoinPolicy convention): OpenPNE 3 stored this as a string, so there is
 * no numeric to preserve, and a 0 case invites PHP falsy-comparison bugs.
 */
enum TopicReadAccess: int
{
    /** Any signed-in member may read. */
    case Everyone = 1;

    /** Only group members may read. */
    case MembersOnly = 2;

    /** String slug for serialization. Never pass the raw int to the frontend. */
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
     * Label key, translated via __() on either surface. OpenPNE 3's own choice captions
     * (community_config.yml public_flag), which its edit form and group home both printed.
     */
    public function label(): string
    {
        return match ($this) {
            self::Everyone => 'Everyone can read',
            self::MembersOnly => "Only %community%'s members can read",
        };
    }
}
