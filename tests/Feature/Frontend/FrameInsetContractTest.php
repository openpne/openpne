<?php

namespace Tests\Feature\Frontend;

use Tests\TestCase;

/**
 * Guards the three roles of `--frame-inset`, the one horizontal inset the Modern member layout is
 * measured against. MemberFrame declares and spends it; a bleeding Card cancels it with a negative
 * margin; content that took the inset over from its card re-spends it. Each role lives in a different
 * file, so a change to one side alone silently breaks the geometry — text at 0px, or a card inset
 * twice. The trip wire is per role rather than a bare "the string appears somewhere".
 */
class FrameInsetContractTest extends TestCase
{
    private function js(string $path): string
    {
        return file_get_contents(resource_path('js/'.$path));
    }

    public function test_the_frame_declares_the_inset_and_spends_it_as_padding(): void
    {
        $frame = $this->js('components/member-frame.tsx');

        $this->assertMatchesRegularExpression(
            '/\[--frame-inset:[^\]]+\]/',
            $frame,
            'MemberFrame must declare --frame-inset — every other role reads it from here.',
        );
        $this->assertStringContainsString(
            'px-(--frame-inset)',
            $frame,
            'MemberFrame must spend the inset as its own horizontal padding.',
        );
    }

    public function test_a_bleeding_card_cancels_the_inset_with_a_negative_margin(): void
    {
        $card = $this->js('components/card.tsx');

        $this->assertStringContainsString(
            '-mx-(--frame-inset)',
            $card,
            'A bleeding Card must cancel exactly the frame inset, not a hard-coded margin.',
        );
        $this->assertStringNotContainsString(
            '-mx-4',
            $card,
            'A literal -mx-4 re-introduces the magic number the token replaced.',
        );
    }

    /**
     * The parts that take the inset over when their card stops paying it (`Panel bleed="full"`). Each
     * must re-spend it, or its text lands on the screen edge.
     */
    public function test_inset_owning_content_respends_it(): void
    {
        $owners = [
            'components/ui/field.tsx',
            'components/compose/editor-extensions.ts',
            'components/compose/body-field.tsx',
        ];

        foreach ($owners as $owner) {
            $this->assertStringContainsString(
                'px-(--frame-inset)',
                $this->js($owner),
                $owner.' owns the inset below sm and must re-spend it.',
            );
        }
    }
}
