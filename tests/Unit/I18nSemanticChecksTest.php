<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\CheckTranslationsCommand as Cmd;
use Tests\TestCase;

/**
 * Pins the semantic-collision helpers behind `i18n:check`: the closed
 * homograph-marker vocabulary, the marker-en hard gate, and the near-fold
 * stem (Str::singular based, so irregulars stay stable).
 */
class I18nSemanticChecksTest extends TestCase
{
    public function test_marker_key_matches_only_the_closed_pos_vocabulary(): void
    {
        foreach (['Post (noun)', 'Post (verb)', 'Light (adjective)', 'Fast (adverb)'] as $key) {
            $this->assertTrue(Cmd::isMarkerKey($key), "{$key} should be a marker key");
        }

        // Display parentheticals are content, not markers — they must NOT trip the gate.
        foreach (['Caption (English)', 'Message (optional)', 'Text (input)', 'Appearance (Classic)', 'Cancel'] as $key) {
            $this->assertFalse(Cmd::isMarkerKey($key), "{$key} should not be a marker key");
        }
    }

    public function test_marker_keys_missing_en_flags_only_unmatched_markers(): void
    {
        $ja = ['Post (noun)', 'Post (verb)', 'Cancel', 'Sender'];

        // 'Post (verb)' has no en entry → flagged; non-marker gaps are ignored.
        $this->assertSame(['Post (verb)'], Cmd::markerKeysMissingEn($ja, ['Post (noun)']));

        // Every marker key has en → clean.
        $this->assertSame([], Cmd::markerKeysMissingEn($ja, ['Post (noun)', 'Post (verb)']));
    }

    public function test_near_fold_stem_collapses_plurals_but_keeps_irregulars_stable(): void
    {
        $this->assertSame(Cmd::nearFoldStem('Member'), Cmd::nearFoldStem('Members'));
        $this->assertSame(Cmd::nearFoldStem('Link'), Cmd::nearFoldStem('Links'));
        $this->assertSame(Cmd::nearFoldStem('Placement'), Cmd::nearFoldStem('Placements'));

        // Naive s/es stripping would mangle these; Str::singular keeps them whole.
        $this->assertSame('status', Cmd::nearFoldStem('Status'));
        $this->assertSame('address', Cmd::nearFoldStem('Address'));
        $this->assertSame('news', Cmd::nearFoldStem('News'));
    }

    public function test_near_fold_candidate_excludes_non_label_keys(): void
    {
        foreach (['Member', 'Banner placements', 'Sender/Recipient'] as $key) {
            $this->assertTrue(Cmd::isNearFoldCandidate($key), "{$key} should be a near-fold candidate");
        }

        // Placeholders, interpolation strings, sentences, and markers are excluded.
        foreach (['%Friend%', ':count members', 'Comment posted.', 'Post (noun)', 'Page 10'] as $key) {
            $this->assertFalse(Cmd::isNearFoldCandidate($key), "{$key} should not be a near-fold candidate");
        }
    }
}
