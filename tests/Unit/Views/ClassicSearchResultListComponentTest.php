<?php

declare(strict_types=1);

namespace Tests\Unit\Views;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * <x-classic.search-result-list> is the OpenPNE 3 _partsSearchResultList.php band, and skins reach
 * into it by shape: div.ditem > div.item > table, a rowspan photo cell holding two links, th/td
 * caption rows. The rowspan tracks a row count that varies per result, and every row but the first
 * is cut to the OpenPNE 3 cell width — locked here rather than re-derived on each search screen.
 */
class ClassicSearchResultListComponentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->setLocale('en');
    }

    public function test_rowspan_follows_each_results_own_row_count(): void
    {
        $rendered = $this->render([
            $this->item('/member/1', [['caption' => 'Nickname', 'value' => 'Alice']]),
            $this->item('/member/2', [
                ['caption' => 'Nickname', 'value' => 'Bob'],
                ['caption' => 'Self Introduction', 'value' => 'Hi'],
            ]),
        ]);

        $this->assertStringContainsString('<td rowspan="1" class="photo">', $rendered);
        $this->assertStringContainsString('<td rowspan="2" class="photo">', $rendered);
        $this->assertSame(2, substr_count($rendered, '<div class="ditem">'));
    }

    public function test_the_photo_cell_links_the_thumbnail_and_a_details_link_to_the_same_page(): void
    {
        $rendered = $this->render([$this->item('/member/1', [['caption' => 'Nickname', 'value' => 'Alice']])]);

        $this->assertStringContainsString(
            '<a href="/member/1"><img src="'.asset('images/no_image.gif').'" width="76" height="76" alt="Alice"> </a><br /> <a href="/member/1">Details</a>',
            $rendered,
        );
    }

    public function test_the_first_value_prints_whole_and_later_values_are_cut_to_the_cell_width(): void
    {
        $long = str_repeat('a', 200);
        $rendered = $this->render([$this->item('/member/1', [
            ['caption' => 'Nickname', 'value' => $long],
            ['caption' => 'Self Introduction', 'value' => $long],
        ])]);

        // Three OpenPNE 3 rows of display width 36; ASCII counts one per character, so 108 survive.
        $this->assertStringContainsString('<td>'.$long.'</td>', $rendered);
        $this->assertStringContainsString('<td>'.str_repeat('a', 108).'</td>', $rendered);
    }

    public function test_a_full_width_value_is_cut_by_display_width_not_character_count(): void
    {
        $rendered = $this->render([$this->item('/member/1', [
            ['caption' => 'Nickname', 'value' => 'Alice'],
            ['caption' => 'Self Introduction', 'value' => str_repeat('あ', 80)],
        ])]);

        // Full-width characters count two, so 54 of them fill the same 108.
        $this->assertStringContainsString('<td>'.str_repeat('あ', 54).'</td>', $rendered);
    }

    public function test_newlines_in_a_later_value_collapse_to_spaces(): void
    {
        $rendered = $this->render([$this->item('/member/1', [
            ['caption' => 'Nickname', 'value' => 'Alice'],
            ['caption' => 'Self Introduction', 'value' => "one\r\ntwo\nthree"],
        ])]);

        $this->assertStringContainsString('<td>one two three</td>', $rendered);
    }

    public function test_values_are_escaped(): void
    {
        $rendered = $this->render([$this->item('/member/1', [
            ['caption' => 'Nickname', 'value' => '<b>Alice</b>'],
            ['caption' => 'Self Introduction', 'value' => '<script>alert(1)</script>'],
        ])]);

        $this->assertStringContainsString('&lt;b&gt;Alice&lt;/b&gt;', $rendered);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $rendered);
        $this->assertStringNotContainsString('<script>', $rendered);
    }

    public function test_the_pager_brackets_the_list_above_and_below(): void
    {
        $rendered = $this->render(
            [$this->item('/member/1', [['caption' => 'Nickname', 'value' => 'Alice']])],
            $this->pager(),
        );

        $this->assertSame(2, substr_count($rendered, 'class="pagerRelative"'));
        $this->assertStringContainsString('</div> <div class="block">', $rendered);
        $this->assertStringContainsString('</div> <div class="pagerRelative">', $rendered);
    }

    public function test_an_empty_result_set_renders_nothing_not_even_the_pager(): void
    {
        $this->assertSame('', $this->render([], $this->pager()));
    }

    /**
     * @param  list<array{caption: string, value: string}>  $rows
     * @return array<string, mixed>
     */
    private function item(string $url, array $rows): array
    {
        return ['url' => $url, 'file' => null, 'name' => $rows[0]['value'], 'rows' => $rows];
    }

    /** @return LengthAwarePaginator<int, string> */
    private function pager(): LengthAwarePaginator
    {
        return new LengthAwarePaginator(['item'], 1, 20, 1, ['path' => '/member/search']);
    }

    /**
     * Render with insignificant whitespace collapsed, so the assertions read as structure.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  LengthAwarePaginator<int, string>|null  $paginator
     */
    private function render(array $items, ?LengthAwarePaginator $paginator = null): string
    {
        return trim((string) preg_replace(
            '/\s+/',
            ' ',
            Blade::render(
                '<x-classic.search-result-list :items="$items" :paginator="$paginator" />',
                ['items' => $items, 'paginator' => $paginator],
            ),
        ));
    }
}
