<?php

declare(strict_types=1);

namespace Tests\Unit\Views;

use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * <x-classic.parts> is the single source of the OpenPNE 3 _partsLayout.php frame every Classic box
 * shares, so a theme's `div.dparts div.parts` / `div.parts table` selectors keep matching. The
 * nesting, the kinds OpenPNE 3 forced single, and the partsHeading rules are locked here rather
 * than rediscovered per screen.
 */
class ClassicPartsComponentTest extends TestCase
{
    public function test_a_plain_kind_nests_parts_inside_dparts(): void
    {
        $this->assertSame(
            '<div class="dparts box" id="b"> <div class="parts"> <div class="partsHeading"><h3>T</h3></div> BODY </div> </div>',
            $this->render('<x-classic.parts id="b" name="box" title="T">BODY</x-classic.parts>'),
        );
    }

    public function test_the_frame_degrades_to_bare_classes_without_an_id_or_kind(): void
    {
        $this->assertSame(
            '<div class="dparts"> <div class="parts"> BODY </div> </div>',
            $this->render('<x-classic.parts>BODY</x-classic.parts>'),
        );
    }

    /**
     * The kinds whose OpenPNE 3 body partial called setDefault('single', true).
     *
     * @return array<string, array{string}>
     */
    public static function singleKinds(): array
    {
        return [
            'informationBox' => ['informationBox'],
            'line' => ['line'],
            'memberImageBox' => ['memberImageBox'],
            'searchFormLine' => ['searchFormLine'],
        ];
    }

    #[DataProvider('singleKinds')]
    public function test_a_single_kind_drops_the_inner_parts_by_default(string $kind): void
    {
        $this->assertSame(
            '<div class="parts '.$kind.'" id="b"> BODY </div>',
            $this->render('<x-classic.parts id="b" name="'.$kind.'">BODY</x-classic.parts>'),
        );
    }

    public function test_an_explicit_single_overrides_the_kind_default_both_ways(): void
    {
        // A caller that knows better than the kind wins: OpenPNE 3's setDefault is a default, and a
        // hand-written template (diary/_sidemenu.php) may single a kind the helper would not.
        $this->assertStringStartsWith(
            '<div class="dparts informationBox">',
            $this->render('<x-classic.parts name="informationBox" :single="false">BODY</x-classic.parts>'),
        );
        $this->assertSame(
            '<div class="parts calendar"> BODY </div>',
            $this->render('<x-classic.parts name="calendar" :single="true">BODY</x-classic.parts>'),
        );
    }

    public function test_no_partsheading_is_emitted_without_a_title(): void
    {
        $this->assertSame(
            '<div class="dparts box"> <div class="parts"> BODY </div> </div>',
            $this->render('<x-classic.parts name="box">BODY</x-classic.parts>'),
        );
        $this->assertStringNotContainsString(
            'partsHeading',
            $this->render('<x-classic.parts name="box" title="">BODY</x-classic.parts>'),
        );
    }

    public function test_the_title_is_escaped(): void
    {
        $this->assertStringContainsString(
            '<div class="partsHeading"><h3>A &lt; B</h3></div>',
            $this->render('<x-classic.parts name="box" :title="\'A < B\'">BODY</x-classic.parts>'),
        );
    }

    public function test_the_heading_slot_replaces_the_partsheading_inner_html_and_beats_the_title(): void
    {
        // OpenPNE 3 showSuccess.php puts a <p class="public"> next to the h3 inside partsHeading.
        $rendered = $this->render(<<<'BLADE'
            <x-classic.parts name="diaryDetailBox" title="Ignored">
                <x-slot:heading>
                    <h3>H</h3>
                    <p class="public">(P)</p>
                </x-slot:heading>
                BODY
            </x-classic.parts>
            BLADE);

        $this->assertSame(
            '<div class="dparts diaryDetailBox"> <div class="parts"> <div class="partsHeading"><h3>H</h3> <p class="public">(P)</p></div> BODY </div> </div>',
            $rendered,
        );
    }

    /** Render with insignificant whitespace collapsed, so the assertions read as structure. */
    private function render(string $template): string
    {
        return trim((string) preg_replace('/\s+/', ' ', Blade::render($template)));
    }
}
