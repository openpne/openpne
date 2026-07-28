<?php

declare(strict_types=1);

namespace Tests\Unit\Views;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * <x-classic.pager> is the OpenPNE 3 _pagerNavigation.php / _pagerTotal.php pair: div.pagerRelative
 * with p.prev / p.number / p.next, which every skin styles by those classes. The two rules a
 * Laravel paginator does not give for free are locked here: the count readout renders even on a
 * single page, and an empty list renders nothing at all.
 */
class ClassicPagerComponentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->setLocale('en');
    }

    public function test_a_single_page_still_renders_the_count_readout(): void
    {
        $this->assertSame(
            '<div class="pagerRelative"> <p class="number">Showing 1 - 3 of 3</p> </div>',
            $this->render($this->pager(total: 3, page: 1)),
        );
    }

    public function test_an_empty_list_renders_nothing(): void
    {
        $this->assertSame('', $this->render($this->pager(total: 0, page: 1)));
    }

    public function test_a_middle_page_renders_previous_readout_and_next_in_order(): void
    {
        $this->assertSame(
            '<div class="pagerRelative">'
                .' <p class="prev"><a href="/friend/list?page=1">Show previous</a></p>'
                .' <p class="number">Showing 6 - 10 of 12</p>'
                .' <p class="next"><a href="/friend/list?page=3">Show next</a></p> </div>',
            $this->render($this->pager(total: 12, page: 2)),
        );
    }

    public function test_the_first_page_drops_previous_and_the_last_page_drops_next(): void
    {
        $first = $this->render($this->pager(total: 12, page: 1));
        $this->assertStringNotContainsString('class="prev"', $first);
        $this->assertStringContainsString('<a href="/friend/list?page=2">Show next</a>', $first);

        $last = $this->render($this->pager(total: 12, page: 3));
        $this->assertStringContainsString('<a href="/friend/list?page=2">Show previous</a>', $last);
        $this->assertStringNotContainsString('class="next"', $last);
    }

    public function test_the_paginators_query_string_survives_into_both_links(): void
    {
        $rendered = $this->render($this->pager(total: 12, page: 2, options: ['query' => ['keyword' => 'foo']]));

        $this->assertStringContainsString('href="/friend/list?keyword=foo&amp;page=1"', $rendered);
        $this->assertStringContainsString('href="/friend/list?keyword=foo&amp;page=3"', $rendered);
    }

    public function test_a_custom_page_name_is_used_so_two_pagers_on_one_screen_stay_independent(): void
    {
        // friend/manage pages its received and sent lists separately (received_page / sent_page).
        $rendered = $this->render($this->pager(total: 12, page: 2, options: ['pageName' => 'received_page']));

        $this->assertStringContainsString('href="/friend/list?received_page=1"', $rendered);
        $this->assertStringContainsString('href="/friend/list?received_page=3"', $rendered);
    }

    public function test_the_japanese_wording_matches_openpne_3(): void
    {
        $this->app->setLocale('ja');

        $rendered = $this->render($this->pager(total: 12, page: 2));

        $this->assertStringContainsString('>前を表示</a>', $rendered);
        $this->assertStringContainsString('<p class="number">12件中 6～10件目を表示</p>', $rendered);
        $this->assertStringContainsString('>次を表示</a>', $rendered);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return LengthAwarePaginator<int, string>
     */
    private function pager(int $total, int $page, int $perPage = 5, array $options = []): LengthAwarePaginator
    {
        $onThisPage = max(0, min($perPage, $total - ($page - 1) * $perPage));

        return new LengthAwarePaginator(
            array_fill(0, $onThisPage, 'item'),
            $total,
            $perPage,
            $page,
            array_merge(['path' => '/friend/list'], $options),
        );
    }

    /**
     * Render with insignificant whitespace collapsed, so the assertions read as structure.
     *
     * @param  LengthAwarePaginator<int, string>  $paginator
     */
    private function render(LengthAwarePaginator $paginator): string
    {
        return trim((string) preg_replace(
            '/\s+/',
            ' ',
            Blade::render('<x-classic.pager :paginator="$paginator" />', ['paginator' => $paginator]),
        ));
    }
}
