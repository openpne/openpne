<?php

namespace App\Features\Reactions;

/**
 * Which emoji may be reacted with. The one source: validation, the page prop the picker draws, and
 * the tests all read all(), and neither the set nor its size is written down anywhere else — so a
 * per-site vocabulary (this class reading an sns_settings key, an absent row meaning these) is a
 * change to this class and nothing beside it.
 *
 * The bytes are stored and the glyph is the reader's own font, so the selection rule is age rather
 * than taste: every entry is Unicode 6.1 (2012) or older, which is what keeps it from arriving as
 * tofu on a device that stopped receiving font updates. Removing an entry is safe — a reaction can
 * always be taken back, whether or not its emoji is still offered.
 */
final class ReactionVocabulary
{
    /** @return list<string> */
    public static function all(): array
    {
        return [
            "\u{1F44D}",        // thumbs up
            "\u{2764}\u{FE0F}", // red heart, VS16-qualified so it is not drawn as a text dingbat
            "\u{1F602}",        // face with tears of joy
            "\u{1F62E}",        // face with open mouth
            "\u{1F622}",        // crying face
            "\u{1F64F}",        // folded hands
            "\u{1F389}",        // party popper
            "\u{2705}",         // check mark button
        ];
    }
}
