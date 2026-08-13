<?php

declare(strict_types=1);

namespace App\Gadgets;

use App\Gadgets\Kinds\ActivityBoxGadget;
use App\Gadgets\Kinds\AllMemberActivityBoxGadget;
use App\Gadgets\Kinds\BirthdayBoxGadget;
use App\Gadgets\Kinds\DiaryAllListGadget;
use App\Gadgets\Kinds\DiaryCommentHistoryGadget;
use App\Gadgets\Kinds\DiaryFriendListGadget;
use App\Gadgets\Kinds\DiaryMemberListGadget;
use App\Gadgets\Kinds\DiaryMyListGadget;
use App\Gadgets\Kinds\FreeAreaGadget;
use App\Gadgets\Kinds\FriendListBoxGadget;
use App\Gadgets\Kinds\GroupJoinListBoxGadget;
use App\Gadgets\Kinds\InformationBoxGadget;
use App\Gadgets\Kinds\LanguageSelecterBoxGadget;
use App\Gadgets\Kinds\LinkListBoxGadget;
use App\Gadgets\Kinds\LoginFormGadget;
use App\Gadgets\Kinds\MemberImageBoxGadget;
use App\Gadgets\Kinds\ProfileListBoxGadget;
use App\Gadgets\Kinds\RecentGroupEventCommentGadget;
use App\Gadgets\Kinds\RecentGroupEventCommentSnsGadget;
use App\Gadgets\Kinds\RecentGroupTopicCommentGadget;
use App\Gadgets\Kinds\RecentGroupTopicCommentSnsGadget;
use App\Gadgets\Kinds\SearchBoxGadget;
use App\Gadgets\Kinds\SideBannerGadget;
use App\Gadgets\Kinds\TimelineAllGadget;
use App\Gadgets\Kinds\TimelineFriendGadget;
use App\Gadgets\Kinds\TimelineProfileGadget;

/**
 * The registered gadget kinds. A `gadgets.name` not found here (an unregistered OpenPNE 3 kind,
 * e.g. a plugin gadget) is hidden at render and flagged Unsupported in admin; adding a kind is
 * registering its class here.
 *
 * LEGACY_NAMES resolves a name OpenPNE 4 has since renamed. It is a read-side alias only: all()
 * stays canonical, so admin and the seeder can never write the old spelling back.
 */
final class GadgetKindRegistry
{
    /** Superseded `gadgets.name` => the canonical name of the same kind. */
    private const LEGACY_NAMES = [
        'communityJoinListBox' => 'groupJoinListBox',
        'recentCommunityTopicComment' => 'recentGroupTopicComment',
        'recentCommunityTopicCommentSns' => 'recentGroupTopicCommentSns',
        'recentCommunityEventComment' => 'recentGroupEventComment',
        'recentCommunityEventCommentSns' => 'recentGroupEventCommentSns',
    ];

    /** @var array<string, GadgetKind>|null */
    private static ?array $byName = null;

    /** @return list<class-string<GadgetKind>> */
    public static function classes(): array
    {
        return [
            FreeAreaGadget::class,
            InformationBoxGadget::class,
            MemberImageBoxGadget::class,
            FriendListBoxGadget::class,
            GroupJoinListBoxGadget::class,
            ProfileListBoxGadget::class,
            DiaryFriendListGadget::class,
            DiaryAllListGadget::class,
            DiaryCommentHistoryGadget::class,
            DiaryMyListGadget::class,
            DiaryMemberListGadget::class,
            RecentGroupTopicCommentGadget::class,
            RecentGroupEventCommentGadget::class,
            RecentGroupTopicCommentSnsGadget::class,
            RecentGroupEventCommentSnsGadget::class,
            TimelineAllGadget::class,
            TimelineFriendGadget::class,
            TimelineProfileGadget::class,
            ActivityBoxGadget::class,
            AllMemberActivityBoxGadget::class,
            BirthdayBoxGadget::class,
            SearchBoxGadget::class,
            LinkListBoxGadget::class,
            LanguageSelecterBoxGadget::class,
            SideBannerGadget::class,
            LoginFormGadget::class,
        ];
    }

    /** @return array<string, GadgetKind> name => kind */
    public static function all(): array
    {
        return self::$byName ??= array_reduce(
            self::classes(),
            static function (array $map, string $class): array {
                $kind = new $class;
                $map[$kind->name()] = $kind;

                return $map;
            },
            [],
        );
    }

    public static function find(string $name): ?GadgetKind
    {
        return self::all()[self::LEGACY_NAMES[$name] ?? $name] ?? null;
    }

    /**
     * Kinds offered for a context, in registration order (admin "add gadget" choices).
     *
     * @return list<GadgetKind>
     */
    public static function forContext(string $context): array
    {
        return array_values(array_filter(
            self::all(),
            static fn (GadgetKind $kind): bool => in_array($context, $kind->contexts(), true),
        ));
    }
}
