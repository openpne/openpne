<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A named set of deviations from the standard Modern layout; a screen a look says nothing about
 * renders standard (docs/internals/looks.md).
 */
enum Look: string
{
    /** The Modern layout as shipped. */
    case Standard = 'standard';

    /** The experiment that renders member pages and group tops in one shared shape. */
    case Unified = 'unified';

    /** The experiment that moves finding your way into one breadcrumb header, with no back button. */
    case Tabbed = 'tabbed';

    /**
     * A bool rather than a page-format id: every non-standard look so far renders the same unified
     * page components and differs only in its chrome.
     */
    public function usesUnifiedPages(): bool
    {
        return match ($this) {
            self::Standard => false,
            self::Unified, self::Tabbed => true,
        };
    }

    /** Label as a translation key, like Surface: the consumer translates in the reader's locale. */
    public function label(): string
    {
        return match ($this) {
            self::Standard => 'Standard',
            self::Unified => 'Unified (experimental)',
            self::Tabbed => 'Tabbed (experimental)',
        };
    }

    /** One-line description for the look picker, as a translation key like Surface. */
    public function description(): string
    {
        return match ($this) {
            self::Standard => 'The Modern layout as it has always been.',
            self::Unified => 'The experimental layout that renders profiles and %communities% in one shared shape.',
            self::Tabbed => 'The experimental layout where the site mark and a breadcrumb up top say where you are.',
        };
    }
}
