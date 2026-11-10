<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Which of its two home pages the Modern surface serves at `/`; the other keeps its own URL
 * (docs/internals/home-issues.md, "Routes").
 */
enum ModernHome: string
{
    /** The latest home issue. */
    case Issue = 'issue';

    /** The "What's new" digest, the page nearest the OpenPNE 3 home. */
    case WhatsNew = 'whats_new';

    /** Label as a translation key, like Look: the consumer translates in the reader's locale. */
    public function label(): string
    {
        return match ($this) {
            self::Issue => 'What happened',
            self::WhatsNew => "What's new",
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Issue => 'A page a day of what happened on the site, published each morning.',
            self::WhatsNew => 'The latest %diaries% and %community% activity as lists, the way the OpenPNE 3 home showed them.',
        };
    }
}
