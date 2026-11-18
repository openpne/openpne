<?php

declare(strict_types=1);

namespace Tests\Feature\Home;

use App\Features\Home\Data\HomeIssueDay;
use App\Models\HomeIssue;
use App\Models\Member;
use App\Support\ModernHome;
use App\Support\SnsSettingKey;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class HomeNoticeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-28 06:00:00');
        config(['openpne.surface_mode' => 'modern_only']);
    }

    public function test_the_issue_home_opens_with_the_notice_and_the_digest_beside_it_does_not(): void
    {
        $this->setSnsSetting(SnsSettingKey::HomeNotice, 'Hello **all**');
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('home/issue')
                ->where('notice.body', 'Hello **all**')
                ->where('notice.bodyHtml', fn (string $html): bool => str_contains($html, '<strong>all</strong>')));

        $this->actingAs($member)->get('/dashboard')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('dashboard')->where('notice', null));
    }

    public function test_the_digest_home_opens_with_the_notice_and_the_issue_beside_it_does_not(): void
    {
        $this->setSnsSetting(SnsSettingKey::ModernHome, ModernHome::WhatsNew);
        $this->setSnsSetting(SnsSettingKey::HomeNotice, 'Hello');
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('dashboard')->where('notice.body', 'Hello'));

        $this->actingAs($member)->get('/home/latest')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('home/issue')->where('notice', null));
    }

    public function test_a_past_day_carries_no_notice(): void
    {
        $this->setSnsSetting(SnsSettingKey::HomeNotice, 'Hello');
        $day = CarbonImmutable::parse('2026-08-26');
        $window = HomeIssueDay::window($day);
        HomeIssue::factory()->create(['issue_date' => $day->toDateString(), 'window_start' => $window->start, 'published_at' => $window->end]);

        $this->actingAs(Member::factory()->create())->get('/home/2026/08/26')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('home/archive')->missing('notice'));
    }

    public function test_a_blank_notice_or_one_that_renders_to_nothing_is_no_notice(): void
    {
        $member = Member::factory()->create();

        foreach (['', "  \n ", '[rules]: /rules'] as $body) {
            $this->setSnsSetting(SnsSettingKey::HomeNotice, $body);
            $this->freshRequestState();

            $this->actingAs($member)->get('/')->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page->where('notice', null));
        }
    }

    /** With the raw switch on, a call through OperatorContent::render() would print the script. */
    public function test_the_notice_is_sanitized_markdown_whatever_the_raw_html_switch_says(): void
    {
        config(['openpne.security.operator_raw_html' => true]);
        $this->setSnsSetting(SnsSettingKey::HomeNotice, "<script>alert(1)</script><style>p{}</style><form action=\"/x\"><input name=\"a\"></form>\n\n![logo](/images/logo.png) [rules](/rules) [mail](mailto:admin@example.com)");

        $this->actingAs(Member::factory()->create())->get('/')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('notice.bodyHtml', function (string $html): bool {
                foreach (['<script', '<style', '<form', '<input'] as $tag) {
                    $this->assertStringNotContainsString($tag, $html);
                }
                $this->assertStringContainsString('src="/images/logo.png"', $html);
                $this->assertStringContainsString('href="/rules"', $html);
                $this->assertStringContainsString('href="mailto:admin', $html);

                return true;
            }));
    }
}
