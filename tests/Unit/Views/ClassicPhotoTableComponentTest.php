<?php

declare(strict_types=1);

namespace Tests\Unit\Views;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * <x-classic.photo-table> is the OpenPNE 3 _partsPhotoTable.php grid, and skins reach into it by
 * shape: tr.photo / tr.text bands, one td per column including the empty tail cells, p.crown ahead
 * of the thumbnail link. Those are locked here rather than re-derived on each of the four screens
 * that draw it.
 */
class ClassicPhotoTableComponentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->setLocale('en');
    }

    public function test_items_wrap_at_col_and_the_tail_is_padded_with_empty_cells(): void
    {
        $rendered = $this->render($this->items(7));

        $this->assertSame(2, substr_count($rendered, '<tr class="photo">'));
        $this->assertSame(2, substr_count($rendered, '<tr class="text">'));
        // 5 columns × 2 bands = 10 cells per row kind, 7 filled, so 3 empty tails in each.
        $this->assertStringContainsString('<td></td> <td></td> <td></td> </tr>', $rendered);
        $this->assertStringNotContainsString('&nbsp;', $rendered);
    }

    public function test_col_controls_the_wrap_width(): void
    {
        $rendered = $this->render($this->items(4), ['col' => 2]);

        $this->assertSame(2, substr_count($rendered, '<tr class="photo">'));
        $this->assertStringNotContainsString('<td></td>', $rendered);
    }

    public function test_a_name_carries_its_count_in_parentheses_and_drops_it_when_null(): void
    {
        $rendered = $this->render([
            ['url' => '/member/1', 'name' => 'Alice', 'count' => 3],
            ['url' => '/member/2', 'name' => 'Bob', 'count' => null],
        ]);

        $this->assertStringContainsString('<a href="/member/1">Alice (3)</a>', $rendered);
        $this->assertStringContainsString('<a href="/member/2">Bob</a>', $rendered);
    }

    public function test_the_crown_precedes_the_thumbnail_link_and_only_for_crowned_items(): void
    {
        $rendered = $this->render([
            ['url' => '/community/1', 'name' => 'Crowned', 'crown' => true],
            ['url' => '/community/2', 'name' => 'Plain', 'crown' => false],
        ]);

        $this->assertStringContainsString(
            '<td><p class="crown"><img src="'.asset('images/icon_crown.gif').'" alt="admin"></p><a href="/community/1">',
            $rendered,
        );
        $this->assertSame(1, substr_count($rendered, 'class="crown"'));
    }

    public function test_a_missing_file_falls_back_to_the_vendored_no_image(): void
    {
        $rendered = $this->render([['url' => '/member/1', 'name' => 'Alice']]);

        $this->assertStringContainsString(
            '<img src="'.asset('images/no_image.gif').'" width="76" height="76" alt="Alice">',
            $rendered,
        );
    }

    public function test_an_action_is_appended_bare_after_the_name_link(): void
    {
        $rendered = $this->render([[
            'url' => '/member/1',
            'name' => 'Alice',
            'action' => ['url' => '/friend/unlink?id=1', 'label' => 'Remove friend'],
        ]]);

        $this->assertStringContainsString(
            '<a href="/member/1">Alice</a> <a href="/friend/unlink?id=1">Remove friend</a></td>',
            $rendered,
        );
    }

    public function test_the_pager_brackets_the_table_above_and_below(): void
    {
        $rendered = $this->render($this->items(3), ['paginator' => $this->pager()]);

        $this->assertSame(2, substr_count($rendered, 'class="pagerRelative"'));
        $this->assertStringContainsString('</div> <table>', $rendered);
        $this->assertStringContainsString('</table> <div class="pagerRelative">', $rendered);
    }

    public function test_without_a_paginator_the_grid_renders_alone(): void
    {
        $this->assertStringNotContainsString('pagerRelative', $this->render($this->items(3)));
    }

    public function test_an_empty_item_list_renders_nothing_not_even_the_pager(): void
    {
        $this->assertSame('', $this->render([], ['paginator' => $this->pager()]));
    }

    /** @return list<array<string, mixed>> */
    private function items(int $count): array
    {
        return array_map(
            fn (int $i) => ['url' => '/member/'.$i, 'name' => 'Member '.$i],
            range(1, $count),
        );
    }

    /** @return LengthAwarePaginator<int, string> */
    private function pager(): LengthAwarePaginator
    {
        return new LengthAwarePaginator(['item'], 1, 20, 1, ['path' => '/friend/list']);
    }

    /**
     * Render with insignificant whitespace collapsed, so the assertions read as structure.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $props
     */
    private function render(array $items, array $props = []): string
    {
        return trim((string) preg_replace(
            '/\s+/',
            ' ',
            Blade::render(
                '<x-classic.photo-table :items="$items" :col="$col" :paginator="$paginator" />',
                ['items' => $items, 'col' => $props['col'] ?? 5, 'paginator' => $props['paginator'] ?? null],
            ),
        ));
    }
}
