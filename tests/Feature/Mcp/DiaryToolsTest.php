<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Features\Diary\Events\DiaryPosted;
use App\Features\Diary\Queries\ListRecentDiaries;
use App\Mcp\McpAbilities;
use App\Mcp\Servers\OpenPneServer;
use App\Mcp\Tools\ListDiariesTool;
use App\Mcp\Tools\PostDiaryCommentTool;
use App\Mcp\Tools\PostDiaryTool;
use App\Mcp\Tools\ReadDiaryTool;
use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\Member;
use App\Notifications\Diary\DiaryCommentedNotification;
use App\Support\BodyFormat;
use App\Support\Feature;
use App\Support\PreferenceKey;
use App\Support\SnsSettingKey;
use App\Support\Visibility;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

/**
 * The four diary tools, called the way an MCP client calls them. The token decides who the caller
 * is, the audience decides what they reach, and every entry they may not have answers with the one
 * refusal that names nothing.
 */
class DiaryToolsTest extends McpTestCase
{
    /** The web-public tier is a site decision the audience list reads, so every suite states it. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->setSnsSetting(SnsSettingKey::DiaryAllowWebPublic, true);
    }

    /** Sign in as $member with the abilities their token carries. */
    private function acting(Member $member, array $abilities = [McpAbilities::READ, McpAbilities::WRITE]): Member
    {
        return Sanctum::actingAs($member, $abilities);
    }

    private function diary(Member $author, Visibility $visibility = Visibility::Members, array $attributes = []): Diary
    {
        return Diary::factory()->create([
            'member_id' => $author->getKey(),
            'visibility' => $visibility,
            ...$attributes,
        ]);
    }

    private function befriend(Member $a, Member $b): void
    {
        DB::table('friendships')->insert([
            ['member_id' => $a->getKey(), 'friend_id' => $b->getKey()],
            ['member_id' => $b->getKey(), 'friend_id' => $a->getKey()],
        ]);
    }

    private function block(Member $blocker, Member $blocked): void
    {
        DB::table('member_blocks')->insert(['blocker_id' => $blocker->getKey(), 'blocked_id' => $blocked->getKey()]);
    }

    public function test_the_feed_carries_what_the_membership_may_read_newest_first(): void
    {
        $author = Member::factory()->create();
        $older = $this->diary($author, Visibility::Open, [
            'title' => 'On the web',
            'body' => 'anyone may read this',
            'created_at' => now()->subDay(),
        ]);
        $newer = $this->diary($author, Visibility::Members, ['title' => 'For members', 'body' => 'members only']);
        DiaryComment::factory()->create(['diary_id' => $newer->getKey()]);

        $this->acting(Member::factory()->create());

        OpenPneServer::tool(ListDiariesTool::class)
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('diaries.0.diaryId', $newer->getKey())
                ->where('diaries.0.title', 'For members')
                ->where('diaries.0.excerpt', 'members only')
                // A slug, never the stored int — Open is 0, which reads as "no audience".
                ->where('diaries.0.visibility', 'members')
                ->where('diaries.0.commentCount', 1)
                ->where('diaries.0.imageCount', 0)
                ->where('diaries.0.authorId', $author->getKey())
                ->where('diaries.0.authorName', $author->name)
                ->where('diaries.0.authorIsAi', false)
                ->where('diaries.1.diaryId', $older->getKey())
                ->where('diaries.1.visibility', 'open')
                ->where('page', 1)
                ->where('lastPage', 1)
                ->where('total', 2)
                ->etc());
    }

    /**
     * The divergence worth pinning: the feed is the site's, the row gate is the caller's. What the
     * feed carries is the all-members tier, so a caller's own narrower entries are not in it — the
     * web feed says the same — and read-diary reads one by id all the same.
     */
    public function test_the_feed_leaves_out_the_callers_own_narrower_entries_which_read_diary_still_reads(): void
    {
        $viewer = Member::factory()->create();
        $mine = $this->diary($viewer, Visibility::Private, ['title' => 'a note to myself', 'body' => 'nobody else']);
        $this->diary($viewer, Visibility::Friends, ['title' => 'for my friends', 'body' => 'friends only']);

        $this->acting($viewer);

        OpenPneServer::tool(ListDiariesTool::class)
            ->assertOk()
            ->assertDontSee('a note to myself')
            ->assertDontSee('for my friends')
            ->assertStructuredContent(fn ($json) => $json->where('total', 0)->where('diaries', [])->etc());

        OpenPneServer::tool(ReadDiaryTool::class, ['diary_id' => $mine->getKey()])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('diary.title', 'a note to myself')
                ->where('diary.body', 'nobody else')
                ->where('diary.visibility', 'private')
                ->etc());
    }

    public function test_the_feed_pages_where_it_is_told_to(): void
    {
        Diary::factory()->count(ListRecentDiaries::PER_PAGE + 1)->create([
            'member_id' => Member::factory()->create()->getKey(),
        ]);

        $this->acting(Member::factory()->create());

        // Page two exists at all only because the tool names the page: there is no URL here for the
        // paginator's own resolver to read one off.
        OpenPneServer::tool(ListDiariesTool::class, ['page' => 2])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('page', 2)->where('lastPage', 2)->count('diaries', 1)->etc());
    }

    /**
     * An AI account is friends with nobody, which is the ordinary case for a bot: it reads the two
     * tiers the membership at large reads and nothing narrower.
     */
    public function test_an_entry_the_caller_may_not_read_is_refused_without_saying_it_exists(): void
    {
        $owner = Member::factory()->create();
        $bot = Member::factory()->aiAccount($owner)->create();

        $stranger = Member::factory()->create();
        $readable = $this->diary($stranger, Visibility::Members, ['body' => 'members may read this']);
        $friendsOnly = $this->diary($stranger, Visibility::Friends, ['body' => 'friends only']);
        $private = $this->diary($stranger, Visibility::Private, ['body' => 'private only']);

        $blocker = Member::factory()->create();
        $blocked = $this->diary($blocker, Visibility::Members, ['body' => 'blocked from this']);
        $this->block($blocker, $bot);

        $this->acting($bot);

        OpenPneServer::tool(ReadDiaryTool::class, ['diary_id' => $readable->getKey()])
            ->assertOk()
            ->assertSee('members may read this');

        // A narrower tier, a block, and an id that names nothing at all answer exactly the same.
        foreach ([$friendsOnly, $private, $blocked] as $hidden) {
            OpenPneServer::tool(ReadDiaryTool::class, ['diary_id' => $hidden->getKey()])
                ->assertHasErrors(['No such diary'])
                ->assertDontSee('friends only')
                ->assertDontSee('private only')
                ->assertDontSee('blocked from this');
        }

        OpenPneServer::tool(ReadDiaryTool::class, ['diary_id' => $private->getKey() + 9999])
            ->assertHasErrors(['No such diary']);

        // The blocked entry is in nobody's feed either, and neither are the two narrower tiers.
        OpenPneServer::tool(ListDiariesTool::class)
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('total', 1)
                ->where('diaries.0.diaryId', $readable->getKey())
                ->etc());
    }

    /** The friends tier is a live audience, not a hole: a friend reads what a stranger cannot. */
    public function test_a_friend_reads_the_friends_tier(): void
    {
        $author = Member::factory()->create();
        $friendsOnly = $this->diary($author, Visibility::Friends, ['body' => 'friends only']);
        $viewer = Member::factory()->create();

        $this->acting($viewer);
        OpenPneServer::tool(ReadDiaryTool::class, ['diary_id' => $friendsOnly->getKey()])
            ->assertHasErrors(['No such diary']);

        $this->befriend($author, $viewer);

        OpenPneServer::tool(ReadDiaryTool::class, ['diary_id' => $friendsOnly->getKey()])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json->where('diary.body', 'friends only')->etc());
    }

    public function test_the_whole_thread_comes_back_in_number_order(): void
    {
        $author = Member::factory()->create();
        $diary = $this->diary($author);
        $human = Member::factory()->create();
        $ai = Member::factory()->aiAccount($author)->create();

        // Written out of order: the sequence is `number`, not insertion.
        DiaryComment::factory()->create(['diary_id' => $diary->getKey(), 'member_id' => $ai->getKey(), 'number' => 3, 'body' => 'third']);
        DiaryComment::factory()->create(['diary_id' => $diary->getKey(), 'member_id' => $human->getKey(), 'number' => 1, 'body' => 'first']);
        DiaryComment::factory()->create(['diary_id' => $diary->getKey(), 'member_id' => null, 'number' => 2, 'body' => 'second']);

        $this->acting(Member::factory()->create());

        OpenPneServer::tool(ReadDiaryTool::class, ['diary_id' => $diary->getKey()])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('diary.commentCount', 3)
                ->where('diary.comments.0.number', 1)
                ->where('diary.comments.0.body', 'first')
                ->where('diary.comments.0.authorId', $human->getKey())
                ->where('diary.comments.0.authorIsAi', false)
                // A withdrawn author is reported as no author rather than as a hole.
                ->where('diary.comments.1.number', 2)
                ->where('diary.comments.1.authorId', null)
                ->where('diary.comments.1.authorName', null)
                ->where('diary.comments.1.authorIsAi', null)
                ->where('diary.comments.2.number', 3)
                ->where('diary.comments.2.authorName', $ai->name)
                ->where('diary.comments.2.authorIsAi', true)
                ->etc());
    }

    /** No format's markup reaches the wire: what comes back is the text a reader would see. */
    public function test_a_body_arrives_flattened_whatever_it_is_stored_as(): void
    {
        $author = Member::factory()->create();
        $op3 = $this->diary($author, Visibility::Members, [
            'format' => BodyFormat::Op3,
            'body' => "<op:b>bold</op:b>\nsecond",
        ]);
        $markdown = $this->diary($author, Visibility::Members, [
            'format' => BodyFormat::Markdown,
            'body' => '**bold** and plain',
        ]);

        $this->acting(Member::factory()->create());

        OpenPneServer::tool(ReadDiaryTool::class, ['diary_id' => $op3->getKey()])
            ->assertOk()
            ->assertDontSee('op:b')
            ->assertStructuredContent(fn ($json) => $json->where('diary.body', "bold\nsecond")->etc());

        OpenPneServer::tool(ReadDiaryTool::class, ['diary_id' => $markdown->getKey()])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json->where('diary.body', 'bold and plain')->etc());
    }

    public function test_posting_writes_the_entry_fires_the_event_and_answers_with_the_row(): void
    {
        Event::fake([DiaryPosted::class]);

        $bot = Member::factory()->aiAccount()->create();
        $this->acting($bot);

        OpenPneServer::tool(PostDiaryTool::class, [
            'title' => 'From a bot',
            'body' => 'hello',
            'visibility' => 'private',
        ])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('diary.title', 'From a bot')
                ->where('diary.excerpt', 'hello')
                ->where('diary.visibility', 'private')
                ->where('diary.commentCount', 0)
                ->where('diary.imageCount', 0)
                ->where('diary.authorId', $bot->getKey())
                ->where('diary.authorName', $bot->name)
                ->where('diary.authorIsAi', true)
                ->has('diary.diaryId')
                ->has('diary.createdAt')
                ->etc());

        $this->assertDatabaseHas('diaries', [
            'member_id' => $bot->getKey(),
            'title' => 'From a bot',
            'body' => 'hello',
            'visibility' => Visibility::Private->value,
        ]);
        Event::assertDispatched(DiaryPosted::class);
    }

    /** A markdown body is stored as its source and read back flattened — no lossy round trip. */
    public function test_a_markdown_entry_is_stored_as_written_and_read_back_as_text(): void
    {
        Notification::fake();

        $member = Member::factory()->create();
        $this->acting($member);

        OpenPneServer::tool(PostDiaryTool::class, [
            'title' => 'Formatted',
            'body' => '**bold** and plain',
            'format' => 'markdown',
            'visibility' => 'private',
        ])->assertOk();

        $this->assertDatabaseHas('diaries', ['body' => '**bold** and plain', 'format' => BodyFormat::Markdown->value]);

        OpenPneServer::tool(ReadDiaryTool::class, ['diary_id' => Diary::query()->sole()->getKey()])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json->where('diary.body', 'bold and plain')->etc());
    }

    /** op3 is never author-able: it exists only on bodies the upgrade wrote. */
    public function test_the_migrated_format_cannot_be_authored(): void
    {
        $this->acting(Member::factory()->create());
        $this->app->setLocale('en');

        OpenPneServer::tool(PostDiaryTool::class, [
            'title' => 'Legacy',
            'body' => '<op:b>no</op:b>',
            'format' => BodyFormat::Op3->value,
        ])->assertHasErrors(['format']);

        $this->assertSame(0, Diary::query()->count());
    }

    /**
     * The audience an omitted `visibility` takes is the member's own default — the value the compose
     * form pre-selects — which is their stored preference clamped to what the site currently offers.
     */
    public function test_an_omitted_audience_is_the_members_own_default_clamped_to_what_is_offered(): void
    {
        Notification::fake();

        $member = Member::factory()->create();
        $member->setPreference(PreferenceKey::DiaryDefaultVisibility, Visibility::Friends);
        $this->acting($member->fresh());

        OpenPneServer::tool(PostDiaryTool::class, ['title' => 'One', 'body' => 'first'])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json->where('diary.visibility', 'friends')->etc());

        // The clamp: a stored Open is not an audience once the site stops serving web-public entries.
        $member->setPreference(PreferenceKey::DiaryDefaultVisibility, Visibility::Open);
        $this->setSnsSetting(SnsSettingKey::DiaryAllowWebPublic, false);
        $this->acting($member->fresh());

        OpenPneServer::tool(PostDiaryTool::class, ['title' => 'Two', 'body' => 'second'])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json->where('diary.visibility', 'members')->etc());
    }

    /**
     * An audience the site does not offer is a validation error naming the field, not the single
     * refusal: it is the caller's own choice, and says nothing about what exists.
     */
    public function test_an_audience_the_site_does_not_offer_is_refused_by_name(): void
    {
        Notification::fake();

        $this->setSnsSetting(SnsSettingKey::DiaryAllowWebPublic, false);
        $this->setSnsSetting(Feature::Friend->settingKey(), false);
        $this->acting(Member::factory()->create());
        $this->app->setLocale('en');

        foreach (['open', 'friends', 'nobody'] as $slug) {
            OpenPneServer::tool(PostDiaryTool::class, ['title' => 'Refused', 'body' => 'x', 'visibility' => $slug])
                ->assertHasErrors(['visibility']);
        }

        $this->assertSame(0, Diary::query()->count());

        OpenPneServer::tool(PostDiaryTool::class, ['title' => 'Accepted', 'body' => 'x', 'visibility' => 'private'])
            ->assertOk();
    }

    /** The cap is the TEXT column's, so it counts bytes — a Japanese character costs three of them. */
    public function test_the_body_cap_counts_bytes_not_characters(): void
    {
        Notification::fake();

        $this->acting(Member::factory()->create());
        $this->app->setLocale('en');

        foreach ([str_repeat('a', 65535), str_repeat('あ', 21845)] as $atTheCap) {
            OpenPneServer::tool(PostDiaryTool::class, ['title' => 'Long', 'body' => $atTheCap, 'visibility' => 'private'])
                ->assertOk();
        }

        foreach ([str_repeat('a', 65536), str_repeat('あ', 21846)] as $overIt) {
            OpenPneServer::tool(PostDiaryTool::class, ['title' => 'Longer', 'body' => $overIt, 'visibility' => 'private'])
                ->assertHasErrors(['body']);
        }

        // The title lives in a TEXT column too; the web form leaves it to the column, the tool
        // refuses it as validation rather than letting the insert fail.
        OpenPneServer::tool(PostDiaryTool::class, ['title' => str_repeat('t', 65536), 'body' => 'x', 'visibility' => 'private'])
            ->assertHasErrors(['title']);

        $this->assertSame(2, Diary::query()->count());
    }

    /**
     * The direct tool path meets no HTTP middleware, so each of these reaches the tool exactly as
     * written — which is why the tool trims the text itself rather than leaving it to TrimStrings.
     */
    public function test_an_entry_of_nothing_is_refused_whatever_it_is_made_of(): void
    {
        Notification::fake();

        $this->acting(Member::factory()->create());
        $this->app->setLocale('en');

        foreach (['', '   ', "\n\n", " \t ", 42] as $blank) {
            OpenPneServer::tool(PostDiaryTool::class, ['title' => 'Blank', 'body' => $blank])
                ->assertHasErrors(['body']);
            OpenPneServer::tool(PostDiaryTool::class, ['title' => $blank, 'body' => 'text'])
                ->assertHasErrors(['title']);
        }

        OpenPneServer::tool(PostDiaryTool::class, ['body' => 'no title'])->assertHasErrors(['title']);

        $this->assertSame(0, Diary::query()->count());

        // What surrounds the text is dropped; what it is made of is not.
        OpenPneServer::tool(PostDiaryTool::class, ['title' => " Spaced \n", 'body' => "  and its text \n", 'visibility' => 'private'])
            ->assertOk();

        $this->assertDatabaseHas('diaries', ['title' => 'Spaced', 'body' => 'and its text']);
    }

    public function test_a_read_only_token_is_told_which_ability_it_is_missing(): void
    {
        $diary = $this->diary(Member::factory()->create());
        $this->acting(Member::factory()->create(), [McpAbilities::READ]);

        OpenPneServer::tool(PostDiaryTool::class, ['title' => 'nope', 'body' => 'nope'])
            ->assertHasErrors([McpAbilities::WRITE]);
        OpenPneServer::tool(PostDiaryCommentTool::class, ['diary_id' => $diary->getKey(), 'body' => 'nope'])
            ->assertHasErrors([McpAbilities::WRITE]);

        $this->assertDatabaseMissing('diaries', ['title' => 'nope']);
        $this->assertSame(0, DiaryComment::query()->count());

        // Reading is what the token does carry.
        OpenPneServer::tool(ReadDiaryTool::class, ['diary_id' => $diary->getKey()])->assertOk();
    }

    public function test_commenting_numbers_the_comment_and_notifies_the_author_and_the_earlier_commenters(): void
    {
        Notification::fake();

        $owner = Member::factory()->create();
        $diary = $this->diary($owner);
        $earlier = Member::factory()->create();
        DiaryComment::factory()->create([
            'diary_id' => $diary->getKey(),
            'member_id' => $earlier->getKey(),
            'number' => 1,
        ]);

        $bot = Member::factory()->aiAccount($owner)->create();
        $this->acting($bot);

        OpenPneServer::tool(PostDiaryCommentTool::class, [
            'diary_id' => $diary->getKey(),
            // Stored as the web form would store it, whitespace at either end gone.
            'body' => "  from a bot \n",
        ])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('comment.number', 2)
                ->where('comment.body', 'from a bot')
                ->where('comment.authorId', $bot->getKey())
                ->where('comment.authorName', $bot->name)
                ->where('comment.authorIsAi', true)
                ->has('comment.id')
                ->has('comment.createdAt')
                ->etc());

        Notification::assertSentTo($owner, DiaryCommentedNotification::class);
        Notification::assertSentTo($earlier, DiaryCommentedNotification::class);
        Notification::assertNotSentTo($bot, DiaryCommentedNotification::class);
    }

    /** Whoever may read an entry may answer it — and whoever may not, may not. */
    public function test_commenting_on_an_entry_the_caller_may_not_read_is_refused(): void
    {
        $stranger = Member::factory()->create();
        $hidden = $this->diary($stranger, Visibility::Private);

        $this->acting(Member::factory()->create());

        foreach ([$hidden->getKey(), $hidden->getKey() + 9999] as $id) {
            OpenPneServer::tool(PostDiaryCommentTool::class, ['diary_id' => $id, 'body' => 'intruding'])
                ->assertHasErrors(['No such diary']);
        }

        $this->assertSame(0, DiaryComment::query()->count());
    }

    public function test_a_comment_of_nothing_is_refused_whatever_it_is_made_of(): void
    {
        $diary = $this->diary(Member::factory()->create());
        $this->acting(Member::factory()->create());
        $this->app->setLocale('en');

        // The direct tool path meets no HTTP middleware, so each of these reaches the tool exactly as
        // written — which is why the tool holds the blank-body contract itself.
        foreach (['', '   ', "\n\n", " \t ", 42] as $blank) {
            OpenPneServer::tool(PostDiaryCommentTool::class, ['diary_id' => $diary->getKey(), 'body' => $blank])
                ->assertHasErrors(['body']);
        }

        OpenPneServer::tool(PostDiaryCommentTool::class, [
            'diary_id' => $diary->getKey(),
            'body' => str_repeat('a', 65536),
        ])->assertHasErrors(['body']);

        $this->assertSame(0, DiaryComment::query()->count());
    }

    public function test_switching_diaries_off_takes_the_tools_away(): void
    {
        $diary = $this->diary(Member::factory()->create());
        $this->acting(Member::factory()->create());
        $this->setSnsSetting(Feature::Diary->settingKey(), false);

        // Not an error about the entry: the tools are not there at all, which is what the navigation
        // says on every other surface too.
        OpenPneServer::tool(ListDiariesTool::class)->assertHasErrors(['not found']);
        OpenPneServer::tool(ReadDiaryTool::class, ['diary_id' => $diary->getKey()])->assertHasErrors(['not found']);
        OpenPneServer::tool(PostDiaryTool::class, ['title' => 'x', 'body' => 'x'])->assertHasErrors(['not found']);
        OpenPneServer::tool(PostDiaryCommentTool::class, ['diary_id' => $diary->getKey(), 'body' => 'x'])
            ->assertHasErrors(['not found']);

        $this->assertSame(0, DiaryComment::query()->count());
    }
}
