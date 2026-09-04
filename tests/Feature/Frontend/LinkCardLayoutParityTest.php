<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend;

use App\LinkCard\CardLayout;
use Tests\TestCase;

/**
 * A card's layout is named once in PHP (CardLayout) and read by the React and Blade renderers, which
 * share no code, so all three must mean the same strings. This guards the vocabulary, not the drawing:
 * the renderers agreeing on "wide" says nothing about the shape they draw.
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
        // boxedPictureMaxWidth names the box once and Modern calls it, while Classic writes the formula
        // by hand in Blade, so the two are pinned to each other here.
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
        // Blade names only the shape it branches on, so this is not a set comparison; it catches a
        // value PHP no longer emits, which would send every card down the else.
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
