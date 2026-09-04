<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Validation\Rules\Enum as EnumRule;

/**
 * Every picker builds its options here and derives its rule from that list, so the two cannot drift.
 * A value already stored at Friends keeps that tier offered while the friend unit is off, so an edit
 * form round-trips instead of silently widening the row (docs/internals/feature-toggles.md).
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
