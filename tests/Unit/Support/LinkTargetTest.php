<?php

namespace Tests\Unit\Support;

use App\Support\LinkTarget;
use Tests\TestCase;

/** The host cases are mirrored in resources/js/lib/link-target.test.ts; the refusals are server-only. */
class LinkTargetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://sns.example.test']);
        app()->setLocale('en');
    }

    public function test_a_link_to_another_site_opens_a_new_tab_and_says_so(): void
    {
        $target = LinkTarget::of('https://example.org/x');

        $this->assertTrue($target->external);
        $this->assertSame(' target="_blank" rel="noopener noreferrer nofollow"', $target->attributes());
        $this->assertSame(' target="_blank" rel="noopener"', $target->attributes('noopener'));
        $this->assertSame('<span class="sr-only"> Opens in a new tab</span>', $target->notice());
    }

    public function test_a_link_to_this_site_opens_in_place(): void
    {
        $target = LinkTarget::of('https://sns.example.test/diary/1?page=2');

        $this->assertFalse($target->external);
        $this->assertSame('', $target->attributes());
        $this->assertSame('', $target->notice());
    }

    public function test_the_scheme_the_host_case_and_the_schemes_default_port_do_not_decide(): void
    {
        $this->assertFalse(LinkTarget::of('http://sns.example.test/x')->external);
        $this->assertFalse(LinkTarget::of('https://SNS.Example.test/x')->external);
        $this->assertFalse(LinkTarget::of('https://sns.example.test:443/x')->external);
        // The browser keeps a port that is not its scheme's default, so this is another authority.
        $this->assertTrue(LinkTarget::of('http://sns.example.test:443/x')->external);
    }

    public function test_another_host_or_port_leaves_this_site(): void
    {
        $this->assertTrue(LinkTarget::of('https://sns.example.test.evil.example/x')->external);
        $this->assertTrue(LinkTarget::of('https://sns.example.test:8443/x')->external);
        // A trailing dot is another host to a browser, and another origin for its cookies.
        $this->assertTrue(LinkTarget::of('https://sns.example.test./x')->external);
    }

    public function test_a_site_on_another_port_still_knows_its_own_links(): void
    {
        // Unlike a card, which LinkUrl refuses to mint for a port the fetcher will not dial.
        config(['app.url' => 'http://localhost:8080']);

        $this->assertFalse(LinkTarget::of('http://localhost:8080/diary/1')->external);
        $this->assertTrue(LinkTarget::of('http://localhost/diary/1')->external);
    }

    public function test_a_host_is_read_as_the_browser_reads_it(): void
    {
        // UTS 46 maps full-width letters to ASCII and a Unicode label to punycode, as `new URL()` does.
        $this->assertFalse(LinkTarget::of('https://ｓｎｓ.example.test/x')->external);

        config(['app.url' => 'https://xn--r8jz45g.jp']);

        $this->assertFalse(LinkTarget::of('https://例え.jp/diary/1')->external);
    }

    public function test_a_url_the_browser_would_send_elsewhere_is_never_ours(): void
    {
        // parse_url reads sns.example.test after the `\@` where a browser cuts the authority at the
        // backslash and goes to evil.example, so the link must stay hardened.
        $this->assertTrue(LinkTarget::of('https://evil.example\\@sns.example.test/')->external);
        $this->assertTrue(LinkTarget::of('https://user:pw@sns.example.test/x')->external);
        $this->assertTrue(LinkTarget::of('https://sns.example.test\\evil.example/')->external);
    }

    public function test_a_url_of_another_scheme_or_no_host_opens_a_new_tab(): void
    {
        $this->assertTrue(LinkTarget::of('ftp://sns.example.test/x')->external);
        $this->assertTrue(LinkTarget::of('/diary/1')->external);
    }

    public function test_a_site_whose_url_is_not_configured_treats_every_link_as_leaving(): void
    {
        config(['app.url' => '']);

        $this->assertTrue(LinkTarget::of('https://sns.example.test/x')->external);
    }

    public function test_the_notice_is_translated(): void
    {
        app()->setLocale('ja');

        $this->assertSame('<span class="sr-only"> 新しいタブで開く</span>', LinkTarget::of('https://example.org/x')->notice());
    }
}
