<?php

declare(strict_types=1);

namespace Tests\Unit\LinkCard;

use App\LinkCard\InternalCardTarget;
use App\LinkCard\InternalUrl;
use App\LinkCard\LinkUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Two answers, not one, and the tests are mostly about the gap between them: an address of this site
 * that resolves to nothing is still ours, and must never be handed to the fetcher.
 */
class InternalUrlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://sns.example.com']);
    }

    /**
     * @return list<array{string, InternalCardTarget, int}>
     */
    public static function canonicalRoutes(): array
    {
        return [
            'diary' => ['/diary/7', InternalCardTarget::Diary, 7],
            'topic' => ['/topics/7', InternalCardTarget::Topic, 7],
            'event' => ['/events/7', InternalCardTarget::Event, 7],
            'timeline post' => ['/timeline/7', InternalCardTarget::TimelinePost, 7],
            'group' => ['/groups/7', InternalCardTarget::Group, 7],
            'member' => ['/member/7', InternalCardTarget::Member, 7],
            'talk message' => ['/groups/3/talk?m=7', InternalCardTarget::TalkMessage, 7],
        ];
    }

    #[DataProvider('canonicalRoutes')]
    public function test_it_resolves_each_canonical_route(string $path, InternalCardTarget $expected, int $id): void
    {
        $link = $this->read('https://sns.example.com'.$path);

        $this->assertTrue($link->isSelfHosted);
        $this->assertSame($expected, $link->target);
        $this->assertSame($id, $link->recordId);
    }

    public function test_another_host_is_not_ours_however_it_is_spelled(): void
    {
        foreach (['https://example.com/diary/7', 'https://sns.example.com.evil.test/diary/7', 'https://evil.test/diary/7'] as $url) {
            $link = $this->read($url);

            $this->assertFalse($link->isSelfHosted, "{$url} was read as one of ours.");
            $this->assertNull($link->target);
        }
    }

    public function test_the_scheme_does_not_decide_whose_host_it_is(): void
    {
        // http and https of this site are the same server, and requesting one because the other is
        // configured is exactly the self-fetch this exists to stop.
        $link = $this->read('http://sns.example.com/diary/7');

        $this->assertTrue($link->isSelfHosted);
        $this->assertSame(InternalCardTarget::Diary, $link->target);
    }

    public function test_a_port_this_app_would_never_fetch_never_reaches_the_question(): void
    {
        // normalize() refuses it, so the body's next URL takes its place and nothing is ever stored
        // under this address — internal or otherwise.
        $this->assertNull(LinkUrl::normalize('https://sns.example.com:8080/diary/7'));
    }

    public function test_a_site_served_on_such_a_port_has_no_internal_links_at_all(): void
    {
        // The other side of the same rule: the configured URL is refused too, so nothing matches it.
        config(['app.url' => 'http://localhost:8080']);

        $this->assertFalse($this->read('http://localhost/diary/7')->isSelfHosted);
    }

    public function test_our_host_is_ours_even_where_no_card_can_be_built(): void
    {
        // The OpenPNE 3 spellings, the list pages, and the sibling routes whose segment is a word.
        foreach (['/diary', '/diary/edit/7', '/timeline/new', '/groups/mine', '/member/search', '/', '/groups/3/talk'] as $path) {
            $link = $this->read('https://sns.example.com'.$path);

            $this->assertTrue($link->isSelfHosted, "{$path} was read as somewhere else.");
            $this->assertNull($link->target, "{$path} resolved to a card.");
            $this->assertNull($link->recordId);
        }
    }

    public function test_a_segment_that_is_not_a_positive_number_names_no_record(): void
    {
        foreach (['/diary/abc', '/diary/7a', '/diary/0', '/diary/-1', '/diary/7.5', '/diary/9999999999999999999999'] as $path) {
            $link = $this->read('https://sns.example.com'.$path);

            $this->assertTrue($link->isSelfHosted);
            $this->assertNull($link->target, "{$path} resolved to a card.");
        }
    }

    public function test_a_trailing_slash_names_the_same_page(): void
    {
        $link = $this->read('https://sns.example.com/diary/7/');

        $this->assertSame(InternalCardTarget::Diary, $link->target);
        $this->assertSame(7, $link->recordId);
    }

    public function test_a_deeper_path_under_a_known_prefix_resolves_to_nothing(): void
    {
        foreach (['/diary/7/comments', '/groups/3/topics', '/member/7/timeline'] as $path) {
            $this->assertNull($this->read('https://sns.example.com'.$path)->target, "{$path} resolved to a card.");
        }
    }

    public function test_a_conversation_needs_the_message_in_its_query(): void
    {
        // The page is the record's address, so what identifies the message is the deep link's `m`.
        $this->assertNull($this->read('https://sns.example.com/groups/3/talk')->target);
        $this->assertNull($this->read('https://sns.example.com/groups/3/talk?m=abc')->target);
        $this->assertSame(7, $this->read('https://sns.example.com/groups/3/talk?m=7&x=1')->recordId);
        // The path's own group rides along for talk, and only for talk — the render refuses a
        // message reached through another room's path.
        $this->assertSame(3, $this->read('https://sns.example.com/groups/3/talk?m=7')->groupId);
        $this->assertNull($this->read('https://sns.example.com/diary/7')->groupId);
    }

    public function test_a_query_on_another_kind_does_not_stop_it_resolving(): void
    {
        // normalize() keeps the query in full, so a link copied with tracking parameters is a
        // different row from the bare one — and both name the same page.
        $link = $this->read('https://sns.example.com/diary/7?utm_source=x');

        $this->assertSame(InternalCardTarget::Diary, $link->target);
        $this->assertSame(7, $link->recordId);
    }

    public function test_an_app_served_from_a_sub_directory_carries_its_prefix(): void
    {
        config(['app.url' => 'https://example.com/sns']);

        $this->assertSame(InternalCardTarget::Diary, $this->read('https://example.com/sns/diary/7')->target);
        // Same host, but not one of this app's pages.
        $this->assertNull($this->read('https://example.com/diary/7')->target);
        $this->assertTrue($this->read('https://example.com/diary/7')->isSelfHosted);
    }

    /** As the app sees a URL: normalised first, exactly as a body's is before it is stored. */
    private function read(string $url): InternalUrl
    {
        $normalized = LinkUrl::normalize($url);

        $this->assertNotNull($normalized, "{$url} is not a URL this app would store at all.");

        return InternalUrl::of($normalized);
    }
}
