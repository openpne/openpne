<?php

declare(strict_types=1);

namespace Tests\Feature\LinkCard;

use App\Features\GroupTopic\TopicReadAccess;
use App\LinkCard\LinkUrl;
use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupMessage;
use App\Models\GroupTopic;
use App\Models\LinkCard;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\LinkCardStatus;
use App\Support\SnsSettingKey;
use App\Support\Visibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * What a page of internal cards costs.
 *
 * A card of one of this site's own pages is read from the record it names, and unlike a fetched one
 * there is nothing on the row to read instead. So the question every list here asks is the same:
 * does the second card cost anything? Read one at a time it is three queries per row on the
 * conversation poll — the app's most frequent request, every few seconds, sixty rows at a time.
 *
 * The guard is **flatness**, not a budget: each list is measured twice, with a few cards and with
 * five times as many, all naming *different* records, and the two must come to the same number.
 * A budget drifts with unrelated work on the page; a count that does not move with the number of
 * rows is the property being defended.
 */
class InternalLinkCardQueryCostTest extends TestCase
{
    use RefreshDatabase;

    /** What every linked record is named, and what a card of it therefore draws as its title. */
    private const MARKER = 'Linked target ';

    private Member $author;

    /**
     * Whoever the cards point at, and never the reader.
     *
     * A card naming the reader's own record short-circuits every access rule at the owner test, so a
     * page of those measures the batching and none of the authorisation the batching has to survive.
     */
    private Member $stranger;

    /** Someone the reader is related to, so the pages here are not all misses (see linkedTarget). */
    private Member $friend;

    /** Someone who has blocked the reader: the card is refused, and the rule still ran. */
    private Member $blocker;

    /** A board only its members may read, so a topic card really asks what the reader is to it. */
    private Group $board;

    /** How many targets have been minted — the round robin, and each one's marker in the page. */
    private int $targets = 0;

    /** How many of them a card is actually drawn for; the refused ones draw nothing. */
    private int $drawn = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://sns.example.com']);
        // Off: an internal card is drawn without it, and leaving it on would let the read trigger
        // queue work mid-measurement (the queue is sync under test).
        $this->setSnsSetting(SnsSettingKey::LinkCardEnabled, false);
        $this->author = Member::factory()->create();
        $this->stranger = Member::factory()->create();
        $this->friend = Member::factory()->create();
        $this->blocker = Member::factory()->create();
        DB::table('friendships')->insert([
            ['member_id' => $this->author->id, 'friend_id' => $this->friend->id],
            ['member_id' => $this->friend->id, 'friend_id' => $this->author->id],
        ]);
        DB::table('member_blocks')->insert([
            'blocker_id' => $this->blocker->id, 'blocked_id' => $this->author->id, 'created_at' => now(),
        ]);
        $this->board = Group::factory()->create(['topic_read_access' => TopicReadAccess::MembersOnly]);
        GroupMember::factory()->create(['group_id' => $this->board->id, 'member_id' => $this->author->id]);
    }

    public function test_the_conversation_poll_costs_the_same_whatever_its_cards_name(): void
    {
        $group = Group::factory()->create();
        GroupMember::factory()->create(['group_id' => $group->id, 'member_id' => $this->author->id]);
        $poll = "/groups/{$group->id}/talk/messages";

        $this->messages($group, 4);
        $few = $this->queriesFor($poll, cardsDrawn: $this->drawn);

        $this->messages($group, 16);
        $many = $this->queriesFor($poll, cardsDrawn: $this->drawn);

        $this->assertSame($many, $few, "The poll cost {$few} queries for 4 rows and {$many} for 20.");
    }

    public function test_the_timeline_feed_costs_the_same_whatever_its_cards_name(): void
    {
        // The Classic row partial is shared by the feed, the profile and three gadgets, so a read
        // per row would multiply across every one of them.
        $this->posts(4);
        $few = $this->queriesFor('/timeline', cardsDrawn: $this->drawn);

        $this->posts(16);
        $many = $this->queriesFor('/timeline', cardsDrawn: $this->drawn);

        $this->assertSame($many, $few, "The feed cost {$few} queries for 4 rows and {$many} for 20.");
    }

    public function test_a_comment_thread_costs_the_same_whatever_its_cards_name(): void
    {
        $diary = Diary::factory()->for($this->author)->create(['visibility' => Visibility::Members]);
        $page = "/diary/{$diary->id}";

        $this->comments($diary, 4);
        $few = $this->queriesFor($page, cardsDrawn: $this->drawn);

        $this->comments($diary, 16);
        $many = $this->queriesFor($page, cardsDrawn: $this->drawn);

        $this->assertSame($many, $few, "The thread cost {$few} queries for 4 rows and {$many} for 20.");
    }

    /**
     * The queries one request to $url costs, warmed up first.
     *
     * The scoped services are forgotten between the two, because a real request starts with an empty
     * set of them and the container under test does not: left in place, the warm-up's records answer
     * the measured run and every guard here passes without the batching it exists to defend.
     */
    private function queriesFor(string $url, int $cardsDrawn): int
    {
        $this->actingAs($this->author)->get($url)->assertOk();
        $this->app->forgetScopedInstances();

        DB::enableQueryLog();
        $response = $this->actingAs($this->author)->get($url);
        $response->assertOk();
        $count = count(DB::getQueryLog());
        DB::flushQueryLog();
        DB::disableQueryLog();

        // Flatness can hold vacuously — a page size falling to the smaller run's count would make
        // the two runs equal with nothing batched — so the guard also pins that the measured run
        // actually drew the cards it claims to measure. Every target is named for the card that
        // draws it and by nothing else on the page, and a card's title is its target's name, so the
        // marker appears once per card drawn — on an HTML surface and a JSON payload alike.
        $content = $response->getContent();
        $drawn = substr_count($content, self::MARKER);
        $this->assertSame($cardsDrawn, $drawn, "Expected {$cardsDrawn} cards in the measured response, found {$drawn}.");

        return $count;
    }

    private function messages(Group $group, int $count): void
    {
        foreach (range(1, $count) as $ignored) {
            GroupMessage::factory()->for($group)->for($this->author, 'author')->create(
                $this->linkTo($this->linkedTarget()) + ['body' => 'See the diary'],
            );
        }
    }

    private function posts(int $count): void
    {
        foreach (range(1, $count) as $ignored) {
            TimelinePost::factory()->for($this->author)->create(
                $this->linkTo($this->linkedTarget()) + ['visibility' => Visibility::Open],
            );
        }
    }

    private function comments(Diary $diary, int $count): void
    {
        $number = (int) $diary->comments()->max('number');

        foreach (range(1, $count) as $ignored) {
            DiaryComment::factory()->for($diary)->for($this->author, 'member')->create(
                $this->linkTo($this->linkedTarget()) + ['number' => ++$number],
            );
        }
    }

    /**
     * A fresh record for a card to name, so no two rows on a page point at the same one.
     *
     * The kinds are cycled because the access rules they run are what a per-row read costs: a
     * diary asks the block table and the friend table, a members-only board's topic asks what the
     * reader is to the group, and a member page asks the block the policy owns. One kind alone would
     * leave the other seams unmeasured.
     *
     * **Related and unrelated are both here**, deliberately. A page of strangers is answered
     * entirely by the pairs a bulk read found *nothing* for, so a batch that recorded only what it
     * found would look flat on a friend's page and cost per row on everyone else's — and the other
     * way round.
     */
    private function linkedTarget(): Model
    {
        $name = self::MARKER.++$this->targets;
        $drawn = $this->targets % 5 !== 0;
        $this->drawn += $drawn ? 1 : 0;

        return match ($this->targets % 5) {
            1 => Diary::factory()->for($this->stranger)->create(['title' => $name, 'visibility' => Visibility::Members]),
            2 => GroupTopic::factory()->for($this->board)->for($this->stranger)->create(['name' => $name]),
            3 => Member::factory()->create(['name' => $name]),
            4 => Diary::factory()->for($this->friend)->create(['title' => $name, 'visibility' => Visibility::Friends]),
            // Refused, and that is the point: the rule runs in full for a card nobody sees.
            default => Diary::factory()->for($this->blocker)->create(['title' => $name, 'visibility' => Visibility::Members]),
        };
    }

    /** @return array<string, mixed> */
    private function linkTo(Model $target): array
    {
        [$path, $context] = match (true) {
            $target instanceof Diary => ["diary/{$target->id}", 'diary'],
            $target instanceof GroupTopic => ["topics/{$target->id}", 'topic'],
            default => ["member/{$target->getKey()}", 'member'],
        };

        $url = "https://sns.example.com/{$path}";

        $card = LinkCard::create([
            'url' => $url,
            'url_hash' => LinkUrl::hash($url),
            'status' => LinkCardStatus::Internal,
            'internal_context' => $context,
            'internal_record_id' => $target->getKey(),
        ]);

        return ['link_card_id' => $card->id, 'link_card_synced_at' => now()];
    }
}
