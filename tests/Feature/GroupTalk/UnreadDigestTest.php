<?php

namespace Tests\Feature\GroupTalk;

use App\Features\GroupTalk\Queries\TalkAbsenceDigest;
use App\Models\File;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupMessage;
use App\Models\GroupMessageImage;
use App\Models\Member;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The absence digest: what the talk page says a member missed, and what it costs to say it.
 *
 * The whole shape is bounded on purpose — a sample rather than the backlog — so most of what is
 * pinned here is what the page does NOT read.
 */
class UnreadDigestTest extends TalkTestCase
{
    private Carbon $start;

    /** @var list<string> every statement of the request under {@see recordQueries} */
    private array $sql = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->start = Carbon::parse('2026-08-14 09:00:00');
    }

    /**
     * $count messages by $author, one per minute from the fixture's start plus $from minutes.
     *
     * @return list<GroupMessage>
     */
    private function say(Group $group, Member $author, int $count, int $from = 0): array
    {
        $messages = [];

        for ($i = 0; $i < $count; $i++) {
            $at = $this->start->copy()->addMinutes($from + $i);
            $messages[] = GroupMessage::factory()->create([
                'group_id' => $group->getKey(),
                'member_id' => $author->getKey(),
                'body' => "message {$from}-{$i}",
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        }

        return $messages;
    }

    /**
     * Pin every membership in the room to a cursor before the fixture, so none of it has been read
     * whatever the wall clock says (the factory's talk_read_at is a DB default no time mock reaches).
     */
    private function rewindCursors(Group $group): void
    {
        GroupMember::query()
            ->where('group_id', $group->getKey())
            ->update(['talk_read_at' => $this->start->copy()->subHour(), 'talk_read_message_id' => 0]);
    }

    /** @return array<string, mixed>|null the digest as the page shipped it */
    private function digestOf(Member $viewer, Group $group, string $query = ''): ?array
    {
        $props = $this->actingAs($viewer)->get("/groups/{$group->getKey()}/talk{$query}")->viewData('page')['props'];

        return $props['unreadDigest'] ?? null;
    }

    /** Attach a picture to $message, owned by $owner as far as the `files` row is concerned. */
    private function attach(GroupMessage $message, int $number = 1, ?GroupMessage $owner = null): File
    {
        $file = File::factory()->create([
            'type' => 'image/png',
            'related_entity_type' => 'groupMessage',
            'related_entity_id' => ($owner ?? $message)->getKey(),
        ]);

        GroupMessageImage::query()->create([
            'group_message_id' => $message->getKey(),
            'file_id' => $file->getKey(),
            'number' => $number,
        ]);

        return $file;
    }

    // --- the threshold, and what it costs below it ---

    public function test_a_backlog_under_the_threshold_ships_no_digest_at_all(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $this->say($group, $this->memberOf($group), TalkAbsenceDigest::THRESHOLD - 1);
        $this->rewindCursors($group);

        $props = $this->actingAs($viewer)->get("/groups/{$group->getKey()}/talk")->viewData('page')['props'];

        // Absent, not null: the client has nothing to branch on, and no query was paid to say so.
        $this->assertArrayNotHasKey('unreadDigest', $props);
        $this->assertSame(TalkAbsenceDigest::THRESHOLD - 1, $props['talkUnreadSnapshot']['count']);
    }

    /** The gate is the count, so one more message is the whole difference. */
    public function test_the_threshold_itself_ships_one(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $this->say($group, $this->memberOf($group), TalkAbsenceDigest::THRESHOLD);
        $this->rewindCursors($group);

        $this->assertNotNull($this->digestOf($viewer, $group));
    }

    public function test_a_room_with_nothing_waiting_ships_no_digest(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $this->say($group, $this->memberOf($group), 20);

        // Read to the foot: the cursor the membership was created with already covers the fixture.
        $props = $this->actingAs($viewer)->get("/groups/{$group->getKey()}/talk")->viewData('page')['props'];

        $this->assertSame(0, $props['talkUnreadSnapshot']['count']);
        $this->assertArrayNotHasKey('unreadDigest', $props);
    }

    public function test_below_the_threshold_the_sample_query_never_runs(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $this->say($group, $this->memberOf($group), TalkAbsenceDigest::THRESHOLD - 1);
        $this->rewindCursors($group);

        $this->actingAs($viewer);
        $this->recordQueries();
        $this->get("/groups/{$group->getKey()}/talk")->assertOk();

        $this->assertSame([], $this->sampleReads(), 'a page under the threshold paid for a digest');
    }

    /** The bound is the contract: a backlog many times the sample still costs one capped read. */
    public function test_a_huge_backlog_runs_exactly_one_bounded_sample(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $this->say($group, $this->memberOf($group), TalkAbsenceDigest::SAMPLE * 3);
        $this->rewindCursors($group);

        $this->actingAs($viewer);
        $this->recordQueries();
        $this->get("/groups/{$group->getKey()}/talk")->assertOk();

        $this->assertCount(1, $this->sampleReads(), 'the digest is one bounded read, no more and no less');
        foreach ($this->conversationReads() as $statement) {
            $this->assertDoesNotMatchRegularExpression(
                '/group by/i',
                $statement,
                "the digest groups authors in PHP, over the sample it already holds: {$statement}",
            );
        }
    }

    private function recordQueries(): void
    {
        $this->sql = [];
        DB::listen(function (QueryExecuted $query): void {
            $this->sql[] = $query->sql;
        });
    }

    /**
     * The digest's own read, told from the page's by its cap: every other read of the conversation is
     * a page (PER_PAGE + 1) or a context slice (CONTEXT + 1).
     *
     * @return list<string>
     */
    private function sampleReads(): array
    {
        return array_values(array_filter(
            $this->conversationReads(),
            fn (string $statement): bool => preg_match('/limit\s+'.TalkAbsenceDigest::SAMPLE.'\b/', $statement) === 1,
        ));
    }

    /** @return list<string> every statement that read the conversation itself */
    private function conversationReads(): array
    {
        return array_values(array_filter(
            $this->sql,
            fn (string $statement): bool => preg_match('/from\s+[`"]?group_messages[`"]?/i', $statement) === 1,
        ));
    }

    // --- what the card says ---

    /** The snapshot's number, never a recount: the card and the divider name the same backlog. */
    public function test_the_count_is_the_whole_backlog_even_though_the_sample_is_bounded(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $waiting = TalkAbsenceDigest::SAMPLE + 17;
        $this->say($group, $this->memberOf($group), $waiting);
        $this->rewindCursors($group);

        $props = $this->actingAs($viewer)->get("/groups/{$group->getKey()}/talk")->viewData('page')['props'];

        $this->assertSame($waiting, $props['unreadDigest']['count']);
        $this->assertSame($props['talkUnreadSnapshot']['count'], $props['unreadDigest']['count']);
    }

    /** The period starts where the reader left off — the same instant the boundary is spelled with. */
    public function test_the_period_starts_at_the_boundary(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $messages = $this->say($group, $this->memberOf($group), 15);
        $this->rewindCursors($group);
        $this->actingAs($viewer)
            ->postJson("/groups/{$group->getKey()}/talk/read", ['messageId' => $messages[1]->getKey()])
            ->assertNoContent();

        $props = $this->actingAs($viewer)->get("/groups/{$group->getKey()}/talk")->viewData('page')['props'];

        $this->assertSame(13, $props['unreadDigest']['count']);
        $this->assertSame($props['talkUnreadSnapshot']['readThrough']['at'], $props['unreadDigest']['since']);
    }

    /** Busiest first, and the viewer's own messages are not in the backlog to begin with. */
    public function test_the_faces_are_the_authors_of_the_sample_busiest_first(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $quiet = $this->memberOf($group);
        $loud = $this->memberOf($group);
        $this->say($group, $quiet, 4);
        $this->say($group, $loud, 8, from: 10);
        $this->rewindCursors($group);
        // The viewer's own words are read by construction, so they are nobody's absence.
        $this->say($group, $viewer, 3, from: 30);

        $digest = $this->digestOf($viewer, $group);

        $this->assertSame(
            [$loud->getKey(), $quiet->getKey()],
            array_column($digest['participants'], 'id'),
        );
        $this->assertSame(12, $digest['count']);
    }

    /** Equal counts keep the order they were met in, so the row is stable between two renders. */
    public function test_authors_who_said_as_much_are_ordered_by_who_spoke_first(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $first = $this->memberOf($group);
        $second = $this->memberOf($group);
        $this->say($group, $first, 6);
        $this->say($group, $second, 6, from: 10);
        $this->rewindCursors($group);

        $this->assertSame(
            [$first->getKey(), $second->getKey()],
            array_column($this->digestOf($viewer, $group)['participants'], 'id'),
        );
    }

    public function test_the_faces_stop_at_the_cap(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        for ($i = 0; $i < TalkAbsenceDigest::PARTICIPANTS + 3; $i++) {
            $this->say($group, $this->memberOf($group), 2, from: $i * 5);
        }
        $this->rewindCursors($group);

        $this->assertCount(TalkAbsenceDigest::PARTICIPANTS, $this->digestOf($viewer, $group)['participants']);
    }

    /** No face for somebody who is no longer there, and no blank one standing in for them either. */
    public function test_a_withdrawn_author_brings_no_face(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $author = $this->memberOf($group);
        $this->say($group, $author, 12);
        $this->rewindCursors($group);
        GroupMessage::query()->where('group_id', $group->getKey())->update(['member_id' => null]);

        $digest = $this->digestOf($viewer, $group);

        $this->assertSame([], $digest['participants']);
        $this->assertSame(12, $digest['count'], 'the messages are still waiting; only the author is gone');
    }

    // --- pictures ---

    public function test_the_pictures_are_the_oldest_readable_ones_in_slot_order(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $messages = $this->say($group, $this->memberOf($group), 12);
        $this->rewindCursors($group);
        $wanted = [
            $this->attach($messages[0], 1),
            $this->attach($messages[0], 2),
            $this->attach($messages[3], 1),
        ];
        // Past the cap: read only if the three above were not taken first.
        $this->attach($messages[5], 1);
        $this->attach($messages[9], 1);

        $digest = $this->digestOf($viewer, $group);

        $this->assertCount(TalkAbsenceDigest::THUMBNAILS, $digest['thumbnails']);
        $this->assertSame(
            array_map(fn (File $file): string => $file->url(), $wanted),
            array_column($digest['thumbnails'], 'url'),
        );
    }

    /**
     * A join row names a file, but only the file names its owner. One pointing at another message's
     * picture is not this message's, whatever the policy would say about it on its own terms.
     */
    public function test_a_join_row_borrowing_another_messages_file_is_left_out(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $messages = $this->say($group, $this->memberOf($group), 12);
        $this->rewindCursors($group);
        $this->attach($messages[0], 1, owner: $messages[7]);
        $mine = $this->attach($messages[2], 1);

        $digest = $this->digestOf($viewer, $group);

        $this->assertSame([$mine->url()], array_column($digest['thumbnails'], 'url'));
    }

    /** The policy that guards the bytes is asked per file, and a refusal leaves no trace on the card. */
    public function test_a_refused_file_is_skipped_in_silence(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $messages = $this->say($group, $this->memberOf($group), 12);
        $this->rewindCursors($group);
        $refused = $this->attach($messages[0], 1);
        $served = $this->attach($messages[4], 1);

        Gate::before(function (?Member $user, string $ability, array $arguments) use ($refused): ?bool {
            $subject = $arguments[0] ?? null;

            return $ability === 'view' && $subject instanceof File && $subject->is($refused) ? false : null;
        });

        $digest = $this->digestOf($viewer, $group);

        $this->assertSame([$served->url()], array_column($digest['thumbnails'], 'url'));
    }

    /** Nothing readable attached is an empty list, not a missing key: the card simply shows no strip. */
    public function test_a_backlog_with_no_pictures_ships_an_empty_strip(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $this->say($group, $this->memberOf($group), 12);
        $this->rewindCursors($group);

        $this->assertSame([], $this->digestOf($viewer, $group)['thumbnails']);
    }

    /** Never past the sample: a picture on the 51st unread message is not the card's to show. */
    public function test_a_picture_beyond_the_sample_is_not_read(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $messages = $this->say($group, $this->memberOf($group), TalkAbsenceDigest::SAMPLE + 5);
        $this->rewindCursors($group);
        $this->attach($messages[TalkAbsenceDigest::SAMPLE + 1], 1);

        $this->assertSame([], $this->digestOf($viewer, $group)['thumbnails']);
    }

    public function test_a_message_hoarding_attachments_cannot_widen_the_read(): void
    {
        // The sample bounds parents, not attachments: one migrated message may carry any number of
        // pictures, and the strip's read has to stay capped whatever that number is — even when
        // every early candidate is refused, the refill stops at the candidate cap.
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $messages = $this->say($group, $this->memberOf($group), TalkAbsenceDigest::THRESHOLD);
        $this->rewindCursors($group);

        // More rows than the candidate cap on one message, all owned elsewhere (refused)…
        $foreign = $messages[1];
        foreach (range(1, TalkAbsenceDigest::THUMBNAIL_CANDIDATES + 3) as $number) {
            $this->attach($messages[0], $number, owner: $foreign);
        }
        // …and a readable picture on a later message, beyond the candidate window.
        $readable = $this->attach($messages[2], 1);

        DB::enableQueryLog();
        $digest = $this->digestOf($viewer, $group);
        $reads = array_values(array_filter(
            DB::getQueryLog(),
            fn (array $q): bool => str_contains($q['query'], 'group_message_images') && ! str_contains($q['query'], 'exists'),
        ));
        DB::disableQueryLog();

        // Two picture reads on the page: the message list's own eager-load (pre-existing, bounded by
        // the visible page) and the digest's candidate query. The digest's is the capped one; the
        // refused pile fills its window, so nothing is refilled from past it — fewer pictures, never
        // a wider read.
        $this->assertCount(2, $reads);
        $capped = array_values(array_filter(
            $reads,
            fn (array $q): bool => str_contains($q['query'], 'limit '.TalkAbsenceDigest::THUMBNAIL_CANDIDATES),
        ));
        $this->assertCount(1, $capped);
        $this->assertSame([], $digest['thumbnails']);
        $this->assertStringNotContainsString($readable->name, json_encode($digest));
    }

    // --- the boundaries the digest does NOT follow ---

    /**
     * Quiet is about being interrupted, not about being told. Mute silences the nav badge and the
     * notifications; opening the room still says what was missed.
     */
    public function test_a_muted_room_still_shows_its_digest(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $this->say($group, $this->memberOf($group), 14);
        $this->rewindCursors($group);
        $this->actingAs($viewer)
            ->postJson("/groups/{$group->getKey()}/talk/mute", ['muted' => true])
            ->assertNoContent();

        $props = $this->actingAs($viewer)->get("/groups/{$group->getKey()}/talk")->viewData('page')['props'];

        $this->assertTrue($props['isMuted']);
        $this->assertSame(14, $props['unreadDigest']['count']);
    }

    /**
     * The card is drawn at the separator or in the banner's place, and which of the two depends on
     * where the boundary fell in the rendered slice — so the payload must not. Same fixture, the two
     * renders that produce the two placements, one digest.
     */
    public function test_both_placements_ship_the_same_payload(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        // Past a page, so the newest slice does not reach back to the boundary: the banner's case.
        $messages = $this->say($group, $this->memberOf($group), 80);
        $this->rewindCursors($group);
        $this->attach($messages[0], 1);

        $offPage = $this->digestOf($viewer, $group);
        // A deep link into the boundary's own slice: the separator's case.
        $onPage = $this->digestOf($viewer, $group, '?m='.$messages[0]->getKey());

        $this->assertNotNull($offPage);
        $this->assertSame($offPage, $onPage);
    }

    /** A reader with no membership row holds no cursor, so there is no absence to describe. */
    public function test_a_non_member_reader_gets_no_digest(): void
    {
        $group = $this->group();
        $this->say($group, $this->memberOf($group), 20);

        $props = $this->actingAs(Member::factory()->create())
            ->get("/groups/{$group->getKey()}/talk")
            ->viewData('page')['props'];

        $this->assertNull($props['talkUnreadSnapshot']);
        $this->assertArrayNotHasKey('unreadDigest', $props);
    }
}
