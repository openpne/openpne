<?php

namespace Tests\Unit\Features\Reactions;

use App\Features\Reactions\ReactionVocabulary;
use PHPUnit\Framework\TestCase;

/**
 * The vocabulary's exact bytes. Stored rows carry them, the picker is handed them and the add rule
 * compares against them, so an editor that helpfully drops a variation selector or swaps a lookalike
 * would strand every reaction already written with the old sequence — invisibly, since both spellings
 * draw the same glyph.
 */
class ReactionVocabularyTest extends TestCase
{
    public function test_the_vocabulary_is_the_pinned_code_points(): void
    {
        $this->assertSame(
            [
                "\u{1F44D}",
                "\u{2764}\u{FE0F}",
                "\u{1F602}",
                "\u{1F62E}",
                "\u{1F622}",
                "\u{1F64F}",
                "\u{1F389}",
                "\u{2705}",
            ],
            ReactionVocabulary::all(),
        );
    }

    /** The column takes 32 characters and the unique key is sized for it. */
    public function test_every_entry_fits_the_column(): void
    {
        foreach (ReactionVocabulary::all() as $emoji) {
            $this->assertLessThanOrEqual(32, mb_strlen($emoji), "[{$emoji}] is wider than the emoji column");
        }
    }

    public function test_no_emoji_is_offered_twice(): void
    {
        $this->assertSame(ReactionVocabulary::all(), array_values(array_unique(ReactionVocabulary::all())));
    }
}
