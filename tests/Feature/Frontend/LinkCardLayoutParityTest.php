<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend;

use App\LinkCard\CardLayout;
use Tests\TestCase;

/**
 * A card's shape is named once in PHP and read by two renderers that share no code — the React
 * component and the Blade one — so all three have to mean the same strings by it. Drift ships a card
 * that falls through to the wrong shape on one surface only, which is invisible from either side:
 * both keep rendering, and neither is obviously wrong on its own.
 *
 * **This guards the vocabulary, not the drawing.** Both renderers agreeing on the word "wide" says
 * nothing about their agreeing on what a wide card looks like — one of them shipped without the
 * no-enlarging cap the other had, and this test was green throughout. What holds the drawing to the
 * same rules is LinkCardRenderingTest for Classic and link-card.test.tsx for Modern, side by side.
 */
class LinkCardLayoutParityTest extends TestCase
{
    public function test_the_react_component_declares_exactly_the_layouts_php_does(): void
    {
        $expected = array_column(CardLayout::cases(), 'value');
        sort($expected);

        $source = (string) file_get_contents(resource_path('js/components/link-card.tsx'));

        $this->assertMatchesRegularExpression("/\n    layout: (?<union>[^;]+);/", $source);
        preg_match("/\n    layout: (?<union>[^;]+);/", $source, $m);
        preg_match_all("/'([a-z]+)'/", $m['union'], $values);

        $actual = $values[1];
        sort($actual);

        $this->assertSame(
            $expected,
            $actual,
            'LinkCardData.layout in resources/js/components/link-card.tsx and App\LinkCard\CardLayout '
            .'disagree on which shapes exist.',
        );
    }

    public function test_the_classic_component_compares_against_a_layout_that_exists(): void
    {
        // Blade names only the shape it branches on — the other is the fallthrough — so this is not a
        // set comparison. What it catches is the typo, and the rename that leaves Classic testing for
        // a string PHP no longer emits: every card would quietly take the else.
        $blade = (string) file_get_contents(resource_path('views/components/link-card.blade.php'));

        preg_match_all("/\\\$card\['layout'\]\s*===\s*'(?<value>[a-z]+)'/", $blade, $m);

        $this->assertNotEmpty($m['value'], 'The Classic card no longer reads the layout at all.');

        foreach ($m['value'] as $value) {
            $this->assertNotNull(
                CardLayout::tryFrom($value),
                "The Classic card compares the layout against '{$value}', which App\LinkCard\CardLayout does not have.",
            );
        }
    }
}
