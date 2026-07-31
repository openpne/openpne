<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Validation\Rules\Enum as EnumRule;

/**
 * The audiences a picker offers, and the rule accepting exactly those. Every picker (diaries,
 * timeline posts, profile values, the age gate) builds its option list here and derives its rule
 * from that list, so the two cannot drift.
 *
 * Two tiers are conditional. Open is offered where the caller serves web-public content. Friends is
 * a dead choice while the friend unit is off — no new friendship can form — so it goes for new
 * content, but a value already stored at Friends keeps its own tier offered ($current): that is what
 * lets an edit form round-trip instead of silently widening the row to Members. Nothing else clamps
 * a stored audience; see docs/internals/feature-toggles.md.
 */
final class VisibilityChoices
{
    /**
     * @param  Visibility|null  $current  audience already stored for the value being edited
     * @return list<Visibility>
     */
    public static function offered(bool $allowsWebPublic, ?Visibility $current = null): array
    {
        return array_values(array_filter(
            Visibility::cases(),
            fn (Visibility $tier): bool => match ($tier) {
                Visibility::Open => $allowsWebPublic,
                Visibility::Friends => Feature::Friend->enabled() || $current === Visibility::Friends,
                default => true,
            },
        ));
    }

    /** @param  list<Visibility>  $offered */
    public static function rule(array $offered): EnumRule
    {
        return (new EnumRule(Visibility::class))->except(
            array_values(array_filter(Visibility::cases(), fn (Visibility $tier): bool => ! in_array($tier, $offered, true))),
        );
    }
}
