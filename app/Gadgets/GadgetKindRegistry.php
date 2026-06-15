<?php

declare(strict_types=1);

namespace App\Gadgets;

use App\Gadgets\Kinds\CommunityJoinListBoxGadget;
use App\Gadgets\Kinds\FreeAreaGadget;
use App\Gadgets\Kinds\FriendListBoxGadget;
use App\Gadgets\Kinds\InformationBoxGadget;
use App\Gadgets\Kinds\LanguageSelecterBoxGadget;
use App\Gadgets\Kinds\LinkListBoxGadget;
use App\Gadgets\Kinds\LoginFormGadget;
use App\Gadgets\Kinds\MemberImageBoxGadget;
use App\Gadgets\Kinds\ProfileListBoxGadget;
use App\Gadgets\Kinds\SearchBoxGadget;

/**
 * The registered gadget kinds. A `gadgets.name` not found here (an OpenPNE 3 kind not yet ported —
 * rssBox, activityBox, plugin gadgets) is hidden at render and flagged Unsupported in admin;
 * adding a kind is registering its class here.
 */
final class GadgetKindRegistry
{
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
            CommunityJoinListBoxGadget::class,
            ProfileListBoxGadget::class,
            SearchBoxGadget::class,
            LinkListBoxGadget::class,
            LanguageSelecterBoxGadget::class,
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
        return self::all()[$name] ?? null;
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
