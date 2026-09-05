<?php

namespace App\Features\Reactions;

/**
 * Every entry is Unicode 6.1 (2012) or older: the bytes are stored and the glyph is the reader's own
 * font, so anything newer arrives as tofu on a device that stopped receiving font updates. The only
 * place the set and its size are written down (docs/internals/group-talk.md, "Reactions").
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
