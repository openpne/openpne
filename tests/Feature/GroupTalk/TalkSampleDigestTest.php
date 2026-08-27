<?php

declare(strict_types=1);

namespace Tests\Feature\GroupTalk;

use App\Features\GroupTalk\Queries\TalkSampleDigest;
use App\Models\File;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupMessage;
use App\Models\GroupMessageImage;
use App\Models\Member;
use App\Support\SnsSettingKey;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * The reusable half of a talk digest: what a window of a conversation is, and what is said about the
 * rows it returns. A window is named by two instants and by nothing else — no cursor, no reader — so
 * most of what is pinned here is which messages fall inside one, in what order, and how far a read
 * of one is allowed to go.
 */
class TalkSampleDigestTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $start;

    private CarbonImmutable $until;

    private Group $group;

    private TalkSampleDigest $digest;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setSnsSetting(SnsSettingKey::FeatureGroupTalkEnabled, true);
        // Well past the window, so anything the fixtures time for themselves lands outside it.
        Carbon::setTestNow('2026-08-14 12:00:00');
        $this->start = CarbonImmutable::parse('2026-08-14 09:00:00');
        $this->until = $this->start->addHour();
        $this->group = Group::factory()->create();
        $this->digest = new TalkSampleDigest;
    }

    private function member(): Member
    {
        $member = Member::factory()->create();
        GroupMember::factory()->member()->create([
            'group_id' => $this->group->getKey(),
            'member_id' => $member->getKey(),
        ]);

        return $member;
    }

    private function said(Member $author, CarbonImmutable $at): GroupMessage
    {
        return GroupMessage::factory()->create([
            'group_id' => $this->group->getKey(),
            'member_id' => $author->getKey(),
            'body' => 'said at '.$at->toDateTimeString(),
            'created_at' => $at,
            'updated_at' => $at,
        ]);
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

    /**
     * @param  Collection<int, GroupMessage>  $messages
     * @return list<int>
     */
    private function ids(Collection $messages): array
    {
        return array_values($messages->pluck('id')->all());
    }

    // --- what a window is ---

    /**
     * (since, until]: the start instant belongs to the window that ended on it, the end instant to
     * this one — so two consecutive windows never count the same message twice.
     */
    public function test_the_window_is_open_at_its_start_and_closed_at_its_end(): void
    {
        $author = $this->member();
        $this->said($author, $this->start);
        $justAfter = $this->said($author, $this->start->addSecond());
        $inside = $this->said($author, $this->start->addMinutes(30));
        $onUntil = $this->said($author, $this->until);
        $this->said($author, $this->until->addSecond());

        $this->assertSame(
            [$justAfter->getKey(), $inside->getKey(), $onUntil->getKey()],
            $this->ids($this->digest->sampleBetween($this->group, $this->start, $this->until)),
        );
    }

    /**
     * Talk's total order is (created_at, id), and a migrated room's ids do not follow its clock: the
     * id only breaks a tie between two messages written in the same second.
     */
    public function test_the_order_is_the_clock_and_then_the_id(): void
    {
        $author = $this->member();
        // Written first, so the lowest id — and last in the window all the same.
        $late = $this->said($author, $this->start->addMinutes(20));
        $tied = $this->said($author, $this->start->addMinutes(10));
        $tiedLater = $this->said($author, $this->start->addMinutes(10));

        $this->assertSame(
            [$tied->getKey(), $tiedLater->getKey(), $late->getKey()],
            $this->ids($this->digest->sampleBetween($this->group, $this->start, $this->until)),
        );
    }

    /**
     * Two messages written in the same second come back in id order on any driver only because the
     * query says so — a fixture cannot show the tiebreak on SQLite, whose rowid order already is id
     * order, so the ORDER BY itself is what is pinned.
     */
    public function test_the_order_is_asked_of_the_database_not_left_to_it(): void
    {
        DB::enableQueryLog();
        $this->digest->sampleBetween($this->group, $this->start, $this->until);
        $this->digest->firstBetween($this->group, $this->start, $this->until);
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        foreach ($log as $entry) {
            // Identifier quoting differs per driver; the order does not.
            $this->assertStringContainsString('order by created_at asc, id asc', preg_replace('/[`"]/', '', $entry['query']));
        }
    }

    public function test_the_sample_stops_at_its_limit_and_brings_its_authors_with_it(): void
    {
        $author = $this->member();
        foreach (range(0, 4) as $minute) {
            $this->said($author, $this->start->addMinutes($minute));
        }

        DB::enableQueryLog();
        $sample = $this->digest->sampleBetween($this->group, $this->start, $this->until, limit: 3);
        $read = DB::getQueryLog();
        DB::flushQueryLog();
        foreach ($sample as $message) {
            $this->assertNotNull($message->author);
            $message->author->avatar;
        }
        $afterwards = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(3, $sample);
        $this->assertStringContainsString('limit 3', $read[0]['query']);
        $this->assertSame([], $afterwards, 'the sample costs a query per row it summarizes');
    }

    /** The sample is bounded and the count is not: the number is what the sample is a summary OF. */
    public function test_the_count_is_the_whole_window_however_bounded_the_sample_is(): void
    {
        $author = $this->member();
        foreach (range(1, TalkSampleDigest::SAMPLE + 10) as $second) {
            $this->said($author, $this->start->addSeconds($second));
        }
        $this->said($author, $this->start);
        $this->said($author, $this->until->addSecond());

        $this->assertSame(TalkSampleDigest::SAMPLE + 10, $this->digest->countBetween($this->group, $this->start, $this->until));
        $this->assertCount(TalkSampleDigest::SAMPLE, $this->digest->sampleBetween($this->group, $this->start, $this->until));
    }

    // --- the anchor ---

    public function test_the_anchor_is_the_first_message_in_total_order(): void
    {
        $author = $this->member();
        $later = $this->said($author, $this->start->addMinutes(20));
        $first = $this->said($author, $this->start->addMinutes(5));

        $this->assertTrue($this->digest->firstBetween($this->group, $this->start, $this->until)?->is($first));

        // Deleting the anchor moves it on rather than emptying the window: what a window says is
        // whatever survives in it.
        $first->delete();

        $this->assertTrue($this->digest->firstBetween($this->group, $this->start, $this->until)?->is($later));
        $this->assertSame(1, $this->digest->countBetween($this->group, $this->start, $this->until));
    }

    public function test_an_emptied_window_has_no_anchor_and_nothing_to_count(): void
    {
        $author = $this->member();
        $inside = $this->said($author, $this->start->addMinutes(5));
        $outside = $this->said($author, $this->until->addHour());

        $inside->delete();

        $this->assertNull($this->digest->firstBetween($this->group, $this->start, $this->until));
        $this->assertSame(0, $this->digest->countBetween($this->group, $this->start, $this->until));
        // The room is not empty — only this stretch of it is.
        $this->assertTrue($outside->exists());
        $this->assertSame(1, GroupMessage::query()->where('group_id', $this->group->getKey())->count());
    }

    // --- who did the talking ---

    public function test_the_faces_are_the_authors_of_the_window_busiest_first(): void
    {
        $quiet = $this->member();
        $loud = $this->member();
        $this->said($quiet, $this->start->addSecond());
        foreach (range(1, 3) as $minute) {
            $this->said($loud, $this->start->addMinutes($minute));
        }

        $sample = $this->digest->sampleBetween($this->group, $this->start, $this->until);

        $this->assertSame(
            [$loud->getKey(), $quiet->getKey()],
            array_column($this->digest->participants($sample), 'id'),
        );
    }

    /** Equal counts keep the order they were met in, so the row is stable between two renders. */
    public function test_authors_who_said_as_much_are_ordered_by_who_spoke_first(): void
    {
        $first = $this->member();
        $second = $this->member();
        $this->said($first, $this->start->addSecond());
        $this->said($second, $this->start->addMinutes(1));
        $this->said($second, $this->start->addMinutes(2));
        $this->said($first, $this->start->addMinutes(3));

        $sample = $this->digest->sampleBetween($this->group, $this->start, $this->until);

        $this->assertSame(
            [$first->getKey(), $second->getKey()],
            array_column($this->digest->participants($sample), 'id'),
        );
    }

    public function test_the_faces_stop_at_the_cap(): void
    {
        foreach (range(0, TalkSampleDigest::PARTICIPANTS + 2) as $minute) {
            $this->said($this->member(), $this->start->addMinutes($minute));
        }

        $sample = $this->digest->sampleBetween($this->group, $this->start, $this->until);

        $this->assertCount(TalkSampleDigest::PARTICIPANTS, $this->digest->participants($sample));
    }

    /** No face for somebody who is no longer there, and no blank one standing in for them either. */
    public function test_a_withdrawn_author_brings_no_face_however_much_they_said(): void
    {
        $author = $this->member();
        $this->said($author, $this->start->addSecond());
        foreach (range(1, 3) as $minute) {
            $at = $this->start->addMinutes($minute);
            GroupMessage::factory()->withdrawnAuthor()->create([
                'group_id' => $this->group->getKey(),
                'body' => 'gone',
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        }

        $sample = $this->digest->sampleBetween($this->group, $this->start, $this->until);

        $this->assertCount(4, $sample);
        $this->assertSame([$author->getKey()], array_column($this->digest->participants($sample), 'id'));
    }

    // --- pictures ---

    /** The policy that guards the bytes is asked per file, and a refusal leaves no trace. */
    public function test_a_refused_file_is_skipped_in_silence(): void
    {
        $viewer = $this->member();
        $author = $this->member();
        $refused = $this->attach($this->said($author, $this->start->addSecond()));
        $served = $this->attach($this->said($author, $this->start->addMinutes(1)));

        Gate::before(function (?Member $user, string $ability, array $arguments) use ($refused): ?bool {
            $subject = $arguments[0] ?? null;

            return $ability === 'view' && $subject instanceof File && $subject->is($refused) ? false : null;
        });

        $sample = $this->digest->sampleBetween($this->group, $this->start, $this->until);

        $this->assertSame([$served->url()], array_column($this->digest->thumbnails($viewer, $sample), 'url'));
    }

    /**
     * A join row names a file, but only the file names its owner. One pointing at another message's
     * picture is not this message's, whatever the policy would say about it on its own terms.
     */
    public function test_a_join_row_borrowing_another_messages_file_is_left_out(): void
    {
        $viewer = $this->member();
        $author = $this->member();
        $borrower = $this->said($author, $this->start->addSecond());
        $lender = $this->said($author, $this->start->addMinutes(1));
        $this->attach($borrower, owner: $lender);
        $mine = $this->attach($this->said($author, $this->start->addMinutes(2)));

        $sample = $this->digest->sampleBetween($this->group, $this->start, $this->until);

        $this->assertSame([$mine->url()], array_column($this->digest->thumbnails($viewer, $sample), 'url'));
    }

    /** A file filed under some other kind of parent is not this message's picture, whatever id it names. */
    public function test_a_file_owned_by_another_kind_of_parent_is_left_out(): void
    {
        $viewer = $this->member();
        $author = $this->member();
        $message = $this->said($author, $this->start->addSecond());
        $foreign = File::factory()->create([
            'type' => 'image/png',
            'related_entity_type' => 'diary',
            'related_entity_id' => $message->getKey(),
        ]);
        GroupMessageImage::query()->create(['group_message_id' => $message->getKey(), 'file_id' => $foreign->getKey(), 'number' => 1]);
        $mine = $this->attach($message, number: 2);

        // The policy would serve it — the ownership check has to be the one that refuses.
        Gate::before(function (?Member $user, string $ability, array $arguments) use ($foreign): ?bool {
            $subject = $arguments[0] ?? null;

            return $ability === 'view' && $subject instanceof File && $subject->is($foreign) ? true : null;
        });

        $sample = $this->digest->sampleBetween($this->group, $this->start, $this->until);

        $this->assertSame([$mine->url()], array_column($this->digest->thumbnails($viewer, $sample), 'url'));
    }

    /** The pictures are bounded twice: the rows read as candidates, and the pictures shown from them. */
    public function test_the_pictures_are_bounded_by_contract(): void
    {
        $viewer = $this->member();
        $author = $this->member();
        $message = $this->said($author, $this->start->addSecond());
        foreach (range(1, TalkSampleDigest::THUMBNAIL_CANDIDATES + 3) as $number) {
            $this->attach($message, number: $number);
        }

        $sample = $this->digest->sampleBetween($this->group, $this->start, $this->until);
        DB::enableQueryLog();
        $shown = $this->digest->thumbnails($viewer, $sample);
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(TalkSampleDigest::THUMBNAILS, $shown);
        $this->assertStringContainsString('limit '.TalkSampleDigest::THUMBNAIL_CANDIDATES, $log[0]['query']);
    }
}
