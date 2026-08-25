<?php

declare(strict_types=1);

namespace Tests\Feature\LinkCard;

use App\LinkCard\LinkUrl;
use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupMessage;
use App\Models\LinkCard;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\LinkCardStatus;
use App\Support\SnsSettingKey;
use App\Support\Visibility;
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

    private Member $author;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://sns.example.com']);
        // Off: an internal card is drawn without it, and leaving it on would let the read trigger
        // queue work mid-measurement (the queue is sync under test).
        $this->setSnsSetting(SnsSettingKey::LinkCardEnabled, false);
        $this->author = Member::factory()->create();
    }

    public function test_the_conversation_poll_costs_the_same_whatever_its_cards_name(): void
    {
        $group = Group::factory()->create();
        GroupMember::factory()->create(['group_id' => $group->id, 'member_id' => $this->author->id]);
        $poll = "/groups/{$group->id}/talk/messages";

        $this->messages($group, 4);
        $few = $this->queriesFor($poll, cardsDrawn: 4);

        $this->messages($group, 16);
        $many = $this->queriesFor($poll, cardsDrawn: 20);

        $this->assertSame($many, $few, "The poll cost {$few} queries for 4 cards and {$many} for 20.");
    }

    public function test_the_timeline_feed_costs_the_same_whatever_its_cards_name(): void
    {
        // The Classic row partial is shared by the feed, the profile and three gadgets, so a read
        // per row would multiply across every one of them.
        $this->posts(4);
        $few = $this->queriesFor('/timeline', cardsDrawn: 4);

        $this->posts(16);
        $many = $this->queriesFor('/timeline', cardsDrawn: 20);

        $this->assertSame($many, $few, "The feed cost {$few} queries for 4 cards and {$many} for 20.");
    }

    public function test_a_comment_thread_costs_the_same_whatever_its_cards_name(): void
    {
        $diary = Diary::factory()->for($this->author)->create(['visibility' => Visibility::Members]);
        $page = "/diary/{$diary->id}";

        $this->comments($diary, 4);
        $few = $this->queriesFor($page, cardsDrawn: 4);

        $this->comments($diary, 16);
        $many = $this->queriesFor($page, cardsDrawn: 20);

        $this->assertSame($many, $few, "The thread cost {$few} queries for 4 cards and {$many} for 20.");
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
        // actually drew the cards it claims to measure. Each internal card carries its target's URL
        // exactly once; both the raw and the JSON-escaped spelling are counted so the pin holds on
        // an HTML surface and a JSON payload alike.
        $content = $response->getContent();
        $drawn = substr_count($content, 'sns.example.com/diary/') + substr_count($content, 'sns.example.com\/diary\/');
        $this->assertSame($cardsDrawn, $drawn, "Expected {$cardsDrawn} cards in the measured response, found {$drawn}.");

        return $count;
    }

    private function messages(Group $group, int $count): void
    {
        foreach (range(1, $count) as $ignored) {
            GroupMessage::factory()->for($group)->for($this->author, 'author')->create(
                $this->linkTo($this->linkedDiary()) + ['body' => 'See the diary'],
            );
        }
    }

    private function posts(int $count): void
    {
        foreach (range(1, $count) as $ignored) {
            TimelinePost::factory()->for($this->author)->create(
                $this->linkTo($this->linkedDiary()) + ['visibility' => Visibility::Open],
            );
        }
    }

    private function comments(Diary $diary, int $count): void
    {
        $number = (int) $diary->comments()->max('number');

        foreach (range(1, $count) as $ignored) {
            DiaryComment::factory()->for($diary)->for($this->author, 'member')->create(
                $this->linkTo($this->linkedDiary()) + ['number' => ++$number],
            );
        }
    }

    /** A fresh diary for a card to name, so no two rows on a page point at the same record. */
    private function linkedDiary(): Diary
    {
        return Diary::factory()->for($this->author)->create([
            'title' => 'A linked diary',
            'visibility' => Visibility::Members,
        ]);
    }

    /** @return array<string, mixed> */
    private function linkTo(Diary $target): array
    {
        $url = "https://sns.example.com/diary/{$target->id}";

        $card = LinkCard::create([
            'url' => $url,
            'url_hash' => LinkUrl::hash($url),
            'status' => LinkCardStatus::Internal,
            'internal_context' => 'diary',
            'internal_record_id' => $target->id,
        ]);

        return ['link_card_id' => $card->id, 'link_card_synced_at' => now()];
    }
}
