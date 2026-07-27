<?php

namespace Tests\Feature\Frontend;

use Tests\TestCase;

/**
 * Guards the two halves of `--frame-inset`: MemberFrame declares and spends it as padding, and a
 * bleeding Card cancels exactly that much with a negative margin. They live in different files, so
 * changing one alone silently breaks the geometry — content at x=0, or a card inset twice.
 *
 * Assertions target the declaration that carries the role, never the whole file: the token is named in
 * doc comments throughout, and the two tiers sit side by side, so `str_contains` over the file would
 * stay green with the token deleted from the tier under test.
 */
class FrameInsetContractTest extends TestCase
{
    private function js(string $path): string
    {
        return file_get_contents(resource_path('js/'.$path));
    }

    private function stripComments(string $code): string
    {
        return preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $code);
    }

    /** The body of a `const NAME = ...;` declaration, comments stripped. */
    private function declaration(string $source, string $name): string
    {
        $pattern = '/^(?:export )?const '.preg_quote($name, '/').'\b[^=]*=\s*(.+?);$/ms';
        $this->assertMatchesRegularExpression($pattern, $source, "Could not find the declaration of {$name}.");
        preg_match($pattern, $source, $m);

        return $this->stripComments($m[1]);
    }

    public function test_the_frame_declares_the_inset_and_spends_it_as_padding(): void
    {
        $frame = $this->stripComments($this->js('components/member-frame.tsx'));

        $this->assertMatchesRegularExpression(
            '/\[--frame-inset:[^\]]+\]/',
            $frame,
            'MemberFrame must declare --frame-inset — the bleeding card reads it from here.',
        );
        $this->assertStringContainsString(
            'px-(--frame-inset)',
            $frame,
            'MemberFrame must spend the inset as its own horizontal padding.',
        );
    }

    public function test_a_bleeding_card_cancels_exactly_the_frame_inset(): void
    {
        $chrome = $this->declaration($this->js('components/card.tsx'), 'CHROME');

        $this->assertMatchesRegularExpression(
            '/\bbleed:\s*\'[^\']*'.preg_quote('-mx-(--frame-inset)', '/').'/',
            $chrome,
            "Card's bleed tier must cancel the frame inset by token, not by a hard-coded margin.",
        );
        $this->assertMatchesRegularExpression(
            '/\binset:\s*\'(?:(?!-mx-)[^\'])*\'/',
            $chrome,
            "Card's inset tier must not cancel the frame padding — it is the non-bleeding tier.",
        );
        $this->assertStringNotContainsString('-mx-4', $chrome, 'A literal -mx-4 re-introduces the magic number the token replaced.');
    }

    /**
     * Bleeding is the default, so a card only stays inset by opting in. If that flipped back, every
     * screen would quietly return to wasting the frame's padding on both sides.
     */
    public function test_bleeding_is_the_default(): void
    {
        $card = $this->stripComments($this->js('components/card.tsx'));

        $this->assertMatchesRegularExpression(
            '/inset\s*=\s*false/',
            $card,
            'Card must default to bleeding, with `inset` as the opt-out.',
        );
        $this->assertStringNotContainsString('bleed = false', $card, 'The old opt-in `bleed` prop must be gone.');
    }
}
