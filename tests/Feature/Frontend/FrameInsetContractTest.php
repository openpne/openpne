<?php

namespace Tests\Feature\Frontend;

use Tests\TestCase;

/**
 * Guards the three roles of `--frame-inset`, the one horizontal inset the Modern member layout is
 * measured against. MemberFrame declares and spends it; a bleeding Card cancels it with a negative
 * margin; content that took the inset over from its card re-spends it. Each role lives in a different
 * file, so a change to one side alone silently breaks the geometry — text at 0px, or a card inset
 * twice.
 *
 * Every assertion targets the specific declaration that carries the role, never the whole file: the
 * token is named in doc comments throughout, and the tiers sit side by side, so a whole-file
 * `str_contains` would stay green with the token deleted from the tier under test.
 */
class FrameInsetContractTest extends TestCase
{
    private const INSET = 'px-(--frame-inset)';

    private const NEGATIVE_INSET = '-mx-(--frame-inset)';

    private function js(string $path): string
    {
        return file_get_contents(resource_path('js/'.$path));
    }

    /**
     * The body of a `const NAME = ...;` declaration, comments stripped. Anchored on the declaration so
     * a doc comment above it (which names the token by design) cannot satisfy an assertion.
     */
    private function declaration(string $source, string $name): string
    {
        $pattern = '/^(?:export )?const '.preg_quote($name, '/').'\b[^=]*=\s*(.+?);$/ms';
        $this->assertMatchesRegularExpression($pattern, $source, "Could not find the declaration of {$name}.");
        preg_match($pattern, $source, $m);

        return $this->stripComments($m[1]);
    }

    /** The body of a `function NAME(...)` declaration, comments stripped. */
    private function fn(string $source, string $name): string
    {
        $start = strpos($source, 'function '.$name.'(');
        $this->assertNotFalse($start, "Could not find function {$name}.");
        $next = strpos($source, "\nfunction ", $start + 1);
        $nextExport = strpos($source, "\nexport function ", $start + 1);
        $end = min(array_filter([$next, $nextExport, strlen($source)], fn ($v) => $v !== false));

        return $this->stripComments(substr($source, $start, $end - $start));
    }

    private function stripComments(string $code): string
    {
        return preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $code);
    }

    public function test_the_frame_declares_the_inset_and_spends_it_as_padding(): void
    {
        $main = $this->fn($this->js('components/member-frame.tsx'), 'MemberFrame');

        $this->assertMatchesRegularExpression(
            '/\[--frame-inset:[^\]]+\]/',
            $main,
            'MemberFrame must declare --frame-inset — every other role reads it from here.',
        );
        $this->assertStringContainsString(
            self::INSET,
            $main,
            'MemberFrame must spend the inset as its own horizontal padding.',
        );
    }

    /** Both bleeding tiers cancel the frame's padding; the plain inset card must not. */
    public function test_each_bleeding_card_tier_cancels_the_inset(): void
    {
        $chrome = $this->declaration($this->js('components/card.tsx'), 'CHROME');

        foreach (['bleed', 'full'] as $tier) {
            $this->assertMatchesRegularExpression(
                '/\b'.$tier.':\s*\'[^\']*'.preg_quote(self::NEGATIVE_INSET, '/').'/',
                $chrome,
                "Card's '{$tier}' tier must cancel exactly the frame inset, not a hard-coded margin.",
            );
        }

        $this->assertMatchesRegularExpression(
            '/\binset:\s*\'(?:(?!-mx-)[^\'])*\'/',
            $chrome,
            "Card's 'inset' tier must not cancel the frame padding — it is the non-bleeding tier.",
        );
        $this->assertStringNotContainsString('-mx-4', $chrome, 'A literal -mx-4 re-introduces the magic number the token replaced.');
    }

    /**
     * The `full` tier hands its horizontal inset to the body's children, so the body itself must pay
     * none below sm — and must restore the normal padding from sm up.
     */
    public function test_the_full_tier_body_pays_no_horizontal_padding_below_sm(): void
    {
        $padding = $this->declaration($this->js('components/ui/surface.tsx'), 'BODY_PADDING');

        $this->assertMatchesRegularExpression('/\bfull:\s*\'([^\']*)\'/', $padding, "BODY_PADDING must define a 'full' tier.");
        preg_match('/\bfull:\s*\'([^\']*)\'/', $padding, $m);
        $full = $m[1];

        $this->assertDoesNotMatchRegularExpression(
            '/(?<![a-z:])px-/',
            $full,
            "The 'full' tier body must leave the horizontal inset to its children below sm.",
        );
        $this->assertStringContainsString('sm:px-', $full, "The 'full' tier body must restore its padding from sm up.");
    }

    /**
     * The parts that take the inset over when their card stops paying it. Each is asserted on its own
     * declaration, so deleting the inset from one cannot be masked by another still having it.
     */
    public function test_inset_owning_content_respends_it(): void
    {
        $field = $this->js('components/ui/field.tsx');
        $this->assertStringContainsString(self::INSET, $this->fn($field, 'FormRow'), 'FormRow owns the inset and must re-spend it.');
        $this->assertStringContainsString(
            self::INSET,
            $this->fn($field, 'FormActions'),
            'FormActions in row mode owns the inset and must re-spend it.',
        );

        $this->assertStringContainsString(
            self::INSET,
            $this->declaration($this->js('components/compose/editor-extensions.ts'), 'EDITOR_CONTENT_CLASS_ROW'),
            'The row editable is the writing surface and must re-spend the inset on its own text.',
        );

        $body = $this->js('components/compose/body-field.tsx');
        foreach (['ROW_INSET', 'ROW_SURFACE'] as $name) {
            $this->assertStringContainsString(
                self::INSET,
                $this->declaration($body, $name),
                "BodyField's {$name} owns the inset and must re-spend it.",
            );
        }
    }

    /**
     * BodyField's op3 branch returns before the layout-aware markup below it, so it needs its own row
     * handling. Two things would break a `Panel bleed="full"` form if it fell through to the stack
     * markup: the boxed Textarea would sit at x=0 (the panel pays no side padding), and the Fragment's
     * two children would land as separate children of the `divide-y` form, splitting one field across
     * two hairline rows. Not reachable from diary/new — a new entry is never op3 — but the edit forms
     * allow it, so the contract is pinned here rather than at the first page that would expose it.
     */
    public function test_the_op3_body_branch_honours_the_row_layout(): void
    {
        $source = $this->js('components/compose/body-field.tsx');
        $start = strpos($source, 'if (format === undefined) {');
        $this->assertNotFalse($start, 'Could not find the op3 branch.');
        $end = strpos($source, 'const method =', $start);
        $this->assertNotFalse($end, 'Could not find the end of the op3 branch.');
        $branch = $this->stripComments(substr($source, $start, $end - $start));

        $this->assertStringContainsString("layout === 'row'", $branch, 'The op3 branch must not ignore the row layout.');
        $this->assertMatchesRegularExpression(
            '/if \(layout === \'row\'\) \{\s*return \(\s*<div/',
            $branch,
            'The op3 row layout must return one element — a Fragment would split the field across two rows.',
        );
        $this->assertStringContainsString('ROW_SURFACE', $branch, 'The op3 row textarea must be the inset-owning surface.');
    }
}
