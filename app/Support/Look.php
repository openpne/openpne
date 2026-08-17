<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A named set of deviations from the standard Modern layout. LookResolver decides which one a
 * request renders in; SnsSettingKey::DefaultLook stores the site's. A screen a look says nothing
 * about renders standard, so a look is never a second copy of the UI
 * (docs/internals/looks.md). Its client half is the LOOKS registry in
 * resources/js/lib/member-chrome.ts, held in step by LookRegistryParityTest.
 */
enum Look: string
{
    /** The Modern layout as shipped. */
    case Standard = 'standard';

    /** The experiment that renders home, member pages and group tops in one shared shape. */
    case Unified = 'unified';

    /** Whether the three swapped routes render the unified page components instead of the shipped ones. */
    public function usesUnifiedPages(): bool
    {
        return match ($this) {
            self::Standard => false,
            self::Unified => true,
        };
    }

    /** Label as a translation key, like Surface: the consumer translates in the reader's locale. */
    public function label(): string
    {
        return match ($this) {
            self::Standard => 'Standard',
            self::Unified => 'Unified (experimental)',
        };
    }

    /** One-line description for the look picker, as a translation key like Surface. */
    public function description(): string
    {
        return match ($this) {
            self::Standard => 'The Modern layout as it has always been.',
            self::Unified => 'The experimental layout that renders home, profiles and %communities% in one shared shape.',
        };
    }
}
