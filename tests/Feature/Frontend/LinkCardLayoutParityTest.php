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

    public function test_the_classic_banner_is_held_to_the_box_the_image_grid_declares(): void
    {
        // The box is named once, in boxedPictureMaxWidth, and Modern calls it; Classic writes the
        // formula out by hand in Blade. Two string assertions that do not know each other held them
        // together before, which is how one surface once shipped without the other's cap.
        $grid = (string) file_get_contents(resource_path('js/components/image-grid.tsx'));
        $this->assertSame(1, preg_match('/export function boxedPictureMaxWidth\([^)]*\)[^{]*\{(?<body>.*?)\n\}/s', $grid, $fn));
        preg_match_all('/\d+rem/', $fn['body'], $box);

        $blade = (string) file_get_contents(resource_path('views/components/link-card.blade.php'));
        $this->assertSame(1, preg_match('/class="linkCardBanner"[^>]*style="max-width: (?<cap>[^"]+)"/s', $blade, $banner));
        preg_match_all('/\d+rem/', $banner['cap'], $classic);

        $this->assertNotEmpty($box[0], 'boxedPictureMaxWidth no longer names its box in rems.');
        $this->assertSame(
            $box[0],
            $classic[0],
            'The Classic banner is held to a different box than image-grid\'s boxedPictureMaxWidth declares.',
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
