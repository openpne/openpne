<?php

declare(strict_types=1);

namespace Tests\Feature\AiAccount;

use App\Features\Notifications\Serializers\NotificationFeedSerializer;
use App\Models\DirectMessage;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupMessage;
use App\Models\Member;
use App\Notifications\DirectMessage\DirectMessageReceivedNotification;
use App\Notifications\GroupTalk\GroupTalkMentionedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\FakesWebPushTransport;
use Tests\TestCase;

/**
 * Where an AI account is named in a sink that can hold nothing but a string — a mail template
 * variable, a push body, a notification sentence — the marker travels inside the name. These are the
 * surfaces no AiChip can reach, and they are the ones that carry a message out of the site.
 */
class AiAccountDisplayNameTest extends TestCase
{
    use FakesWebPushTransport;
    use RefreshDatabase;

    private const ENDPOINT = 'https://push.example.com/subscription/abc';

    protected function setUp(): void
    {
        parent::setUp();

        config(['openpne.surface_mode' => 'modern_default']);
    }

    /** An AI account named $name, joined to $group alongside its owner. */
    private function aiAccountIn(Group $group, string $name): Member
    {
        $aiAccount = Member::factory()->aiAccount()->create(['name' => $name]);
        GroupMember::factory()->create(['group_id' => $group->getKey(), 'member_id' => $aiAccount->getKey()]);

        return $aiAccount;
    }

    private function memberOf(Group $group, string $name): Member
    {
        $member = Member::factory()->create(['name' => $name]);
        GroupMember::factory()->create(['group_id' => $group->getKey(), 'member_id' => $member->getKey()]);

        return $member;
    }

    /** A message from $sender that $recipient really holds: the notification checks the receipt. */
    private function deliver(Member $sender, Member $recipient): void
    {
        $message = DirectMessage::factory()->create(['sender_id' => $sender->getKey()]);
        $message->recipients()->create(['recipient_id' => $recipient->getKey()]);

        $recipient->notify(new DirectMessageReceivedNotification($sender, $message));
    }

    public function test_a_mention_mail_names_the_ai_account_as_one(): void
    {
        $group = Group::factory()->create();
        $aiAccount = $this->aiAccountIn($group, 'Shirabe');
        $target = $this->memberOf($group, 'Bob');
        $message = GroupMessage::factory()->create([
            'group_id' => $group->getKey(), 'member_id' => $aiAccount->getKey(), 'body' => 'come and look',
        ]);

        $text = $this->renderMailText((new GroupTalkMentionedNotification($aiAccount, $message))->toMail($target));

        $this->assertStringContainsString(__(':name (AI)', ['name' => 'Shirabe']), $text);
    }

    public function test_a_human_authors_mention_mail_is_untouched(): void
    {
        $group = Group::factory()->create();
        $author = $this->memberOf($group, 'Kaoru');
        $target = $this->memberOf($group, 'Bob');
        $message = GroupMessage::factory()->create([
            'group_id' => $group->getKey(), 'member_id' => $author->getKey(), 'body' => 'come and look',
        ]);

        $text = $this->renderMailText((new GroupTalkMentionedNotification($author, $message))->toMail($target));

        $this->assertStringContainsString('Kaoru', $text);
        $this->assertStringNotContainsString(__(':name (AI)', ['name' => '']), $text);
    }

    public function test_a_push_body_names_the_ai_account_as_one(): void
    {
        $this->configureVapid();
        $this->fakeWebPushTransport();

        $aiAccount = Member::factory()->aiAccount()->create(['name' => 'Shirabe']);
        $recipient = Member::factory()->create();
        $recipient->updatePushSubscription(self::ENDPOINT, str_repeat('k', 87), str_repeat('a', 22), 'aes128gcm');

        $this->deliver($aiAccount, $recipient);

        $pushes = $this->pushesTo(self::ENDPOINT);
        $this->assertCount(1, $pushes);
        $this->assertSame(__(':name sent you a message.', ['name' => __(':name (AI)', ['name' => 'Shirabe'])]), $pushes[0]['body']);
    }

    public function test_a_feed_sentence_names_the_ai_account_as_one(): void
    {
        $aiAccount = Member::factory()->aiAccount()->create(['name' => 'Shirabe']);
        $reader = Member::factory()->create();
        $this->deliver($aiAccount, $reader);

        $rows = $reader->notifications()->paginate();
        $feed = NotificationFeedSerializer::paginator($rows);

        $this->assertSame(__(':name sent you a message.', ['name' => __(':name (AI)', ['name' => 'Shirabe'])]), $feed['data'][0]['label']);
        // The row's own actor object keeps the bare name: the client draws the chip from `isAi`, and
        // a name marked twice is the thing this split exists to prevent.
        $this->assertSame('Shirabe', $feed['data'][0]['actor']['name']);
        $this->assertTrue($feed['data'][0]['actor']['isAi']);
    }

    public function test_the_classic_notification_centre_marks_the_actor_it_names(): void
    {
        $aiAccount = Member::factory()->aiAccount()->create(['name' => 'Shirabe']);
        $reader = Member::factory()->create();
        $this->deliver($aiAccount, $reader);

        // Classic draws the actor's name as text beside the avatar, with no chip to carry the fact.
        $rows = NotificationFeedSerializer::centerRows($reader->notifications()->get(), []);

        $this->assertSame(__(':name (AI)', ['name' => 'Shirabe']), $rows->first()->actorName);
    }

    public function test_the_withdrawn_member_fallback_is_left_alone(): void
    {
        $reader = Member::factory()->create();
        $sender = Member::factory()->create();
        $this->deliver($sender, $reader);
        $sender->delete();

        /** @var LengthAwarePaginator<int, DatabaseNotification> $rows */
        $rows = $reader->notifications()->paginate();
        $feed = NotificationFeedSerializer::paginator($rows);

        $this->assertSame(__(':name sent you a message.', ['name' => __('Withdrawn member')]), $feed['data'][0]['label']);
        $this->assertNull($feed['data'][0]['actor']);
    }

    public function test_every_notification_mail_that_names_an_actor_marks_an_ai_one(): void
    {
        // A guard rather than a suite per notification: the marker is applied at each actor-name
        // context, and what breaks that is a new mail pasting a bare `->name` in. Deny by default —
        // a property not on the list of things that are not members counts as one — so an actor
        // named something nobody thought of fails here instead of shipping unmarked.
        $notMembers = ['group', 'topic', 'event', 'post', 'diary', 'message', 'comment'];

        $unmarked = [];
        foreach (glob(app_path('Notifications/*/*.php')) ?: [] as $path) {
            $source = (string) file_get_contents($path);
            if (! str_contains($source, 'mailFromTemplate(')) {
                continue;
            }

            preg_match_all('/=> \$this->(\w+)->name\b/', $source, $matches);
            foreach ($matches[1] as $property) {
                if (! in_array($property, $notMembers, true)) {
                    $unmarked[] = basename($path).': $this->'.$property.'->name';
                }
            }
        }

        $this->assertSame([], $unmarked);
    }

    public function test_the_room_list_preview_carries_the_fact_beside_the_name(): void
    {
        $group = Group::factory()->create();
        $aiAccount = $this->aiAccountIn($group, 'Shirabe');
        $reader = $this->memberOf($group, 'Bob');
        GroupMessage::factory()->create([
            'group_id' => $group->getKey(), 'member_id' => $aiAccount->getKey(), 'body' => 'done',
        ]);

        $this->actingAs($reader)
            ->get('/groups/mine')
            ->assertInertia(fn (AssertableInertia $page) => $page
                // The name stays bare: the row draws the chip from the fact beside it.
                ->where('rooms.data.0.latest.authorName', 'Shirabe')
                ->where('rooms.data.0.latest.authorIsAi', true));
    }

    public function test_a_human_speaker_leaves_the_preview_unmarked(): void
    {
        $group = Group::factory()->create();
        $speaker = $this->memberOf($group, 'Kaoru');
        $reader = $this->memberOf($group, 'Bob');
        GroupMessage::factory()->create([
            'group_id' => $group->getKey(), 'member_id' => $speaker->getKey(), 'body' => 'done',
        ]);

        $this->actingAs($reader)
            ->get('/groups/mine')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('rooms.data.0.latest.authorIsAi', false));
    }
}
