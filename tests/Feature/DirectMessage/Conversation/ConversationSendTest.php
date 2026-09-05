<?php

namespace Tests\Feature\DirectMessage\Conversation;

use App\Http\Requests\DirectMessage\StoreChatMessageRequest;
use App\Models\DirectMessage;
use App\Models\Member;
use App\Support\SnsSettingKey;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The write itself is the mailbox's SendDirectMessage; this pins the shape the chat screen sends and
 * gets back.
 */
class ConversationSendTest extends ConversationTestCase
{
    private function upload(string $name = 'shot.png'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 40, 40);
    }

    public function test_a_send_writes_one_delivered_message_with_no_subject_and_no_lineage(): void
    {
        Notification::fake();
        [$viewer, $counterpart] = Member::factory()->count(2)->create();

        $id = $this->actingAs($viewer)
            ->postJson("/messages/{$counterpart->getKey()}", ['body' => 'on my way'])
            ->assertCreated()
            ->json('id');

        $this->assertDatabaseHas('direct_messages', [
            'id' => $id,
            'sender_id' => $viewer->getKey(),
            'body' => 'on my way',
            'subject' => null,
            'is_draft' => false,
            'draft_recipient_id' => null,
            'parent_id' => null,
            'thread_id' => null,
        ]);
        $this->assertSame(
            [['recipient_id' => $counterpart->getKey(), 'read_at' => null]],
            DB::table('direct_message_recipients')->where('direct_message_id', $id)
                ->get(['recipient_id', 'read_at'])->map(fn ($row) => (array) $row)->all(),
        );
    }

    /** The composer appends the reply, so it has to be the row shape the paging endpoint serves. */
    public function test_the_reply_is_the_same_shape_the_paging_endpoint_serves(): void
    {
        Notification::fake();
        [$viewer, $counterpart] = Member::factory()->count(2)->create();

        $written = $this->actingAs($viewer)
            ->postJson("/messages/{$counterpart->getKey()}", ['body' => 'on my way'])
            ->assertCreated()
            ->json();
        $paged = $this->actingAs($viewer)
            ->getJson("/messages/{$counterpart->getKey()}/messages")
            ->assertOk()
            ->json('messages.0');

        $this->assertSame($paged, $written);
        $this->assertNull($written['subject']);
        $this->assertTrue($written['isOwn']);
        $this->assertFalse($written['read']);
    }

    /** Nothing to say and nothing to show: the one thing the bar cannot send. */
    public function test_an_empty_body_with_no_picture_is_rejected(): void
    {
        [$viewer, $counterpart] = Member::factory()->count(2)->create();

        $this->actingAs($viewer)
            ->postJson("/messages/{$counterpart->getKey()}", ['body' => ''])
            ->assertJsonValidationErrorFor('body');

        $this->assertDatabaseCount('direct_messages', 0);
    }

    /** The body is what the attachment stands in for; it is stored empty, not null. */
    public function test_a_message_can_be_pictures_alone(): void
    {
        Notification::fake();
        [$viewer, $counterpart] = Member::factory()->count(2)->create();

        $id = $this->actingAs($viewer)
            ->post("/messages/{$counterpart->getKey()}", ['images' => [$this->upload()]])
            ->assertCreated()
            ->json('id');

        $this->assertSame('', DirectMessage::findOrFail($id)->body);
        $this->assertDatabaseHas('direct_message_files', ['direct_message_id' => $id, 'number' => 1]);
    }

    /** TrimStrings then ConvertEmptyStringsToNull, so a bar of spaces reaches the write as the empty body it is. */
    public function test_a_body_of_only_whitespace_is_the_same_as_none(): void
    {
        Notification::fake();
        [$viewer, $counterpart] = Member::factory()->count(2)->create();

        $id = $this->actingAs($viewer)
            ->post("/messages/{$counterpart->getKey()}", ['body' => "   \n  ", 'images' => [$this->upload()]])
            ->assertCreated()
            ->json('id');

        $this->assertSame('', DirectMessage::findOrFail($id)->body);
    }

    /**
     * The empty body is normalized back to a string for the write; a body that is not a string is
     * left for the `string` rule to refuse, so an attachment cannot carry one past validation.
     *
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('bodiesThatAreNotStrings')]
    public function test_a_body_of_the_wrong_type_is_refused(array $payload): void
    {
        [$viewer, $counterpart] = Member::factory()->count(2)->create();

        $response = $this->actingAs($viewer)
            ->postJson("/messages/{$counterpart->getKey()}", $payload)
            ->assertJsonValidationErrorFor('body');

        // The type rule, not "say something": a value of the wrong shape is present, and coercing it
        // to a string would send it as words the member never wrote.
        $this->assertNotSame(__('Enter a message or attach an image.'), $response->json('errors.body.0'));
        $this->assertDatabaseCount('direct_messages', 0);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function bodiesThatAreNotStrings(): array
    {
        return [
            'a number' => [['body' => 123]],
            'an array' => [['body' => ['nope']]],
        ];
    }

    public function test_a_body_of_exactly_the_cap_is_accepted_and_one_point_over_is_not(): void
    {
        Notification::fake();
        [$viewer, $counterpart] = Member::factory()->count(2)->create();
        $max = StoreChatMessageRequest::MAX_BODY;

        $this->actingAs($viewer)
            ->postJson("/messages/{$counterpart->getKey()}", ['body' => str_repeat('a', $max)])
            ->assertCreated();

        $this->actingAs($viewer)
            ->postJson("/messages/{$counterpart->getKey()}", ['body' => str_repeat('a', $max + 1)])
            ->assertJsonValidationErrorFor('body');

        $this->assertDatabaseCount('direct_messages', 1);
    }

    /** Code points, not bytes and not UTF-16 units: an astral body is as long as an ASCII one. */
    public function test_the_cap_counts_code_points(): void
    {
        Notification::fake();
        [$viewer, $counterpart] = Member::factory()->count(2)->create();
        $emoji = str_repeat('🙂', StoreChatMessageRequest::MAX_BODY);

        $this->actingAs($viewer)
            ->postJson("/messages/{$counterpart->getKey()}", ['body' => $emoji])
            ->assertCreated();

        $this->actingAs($viewer)
            ->postJson("/messages/{$counterpart->getKey()}", ['body' => $emoji.'🙂'])
            ->assertJsonValidationErrorFor('body');
    }

    /**
     * Multipart encodes the textarea's LF newlines as CRLF in transit; the body is stored with LF,
     * and the normalization runs before the length check so nothing is measured a break too long.
     */
    public function test_crlf_is_normalized_to_lf_before_it_is_measured_and_stored(): void
    {
        Notification::fake();
        [$viewer, $counterpart] = Member::factory()->count(2)->create();

        $id = $this->actingAs($viewer)
            ->post("/messages/{$counterpart->getKey()}", ['body' => "one\r\ntwo\rthree"])
            ->assertCreated()
            ->json('id');

        $this->assertSame("one\ntwo\nthree", DirectMessage::findOrFail($id)->body);

        $max = StoreChatMessageRequest::MAX_BODY;
        $this->actingAs($viewer)
            ->post("/messages/{$counterpart->getKey()}", ['body' => str_repeat("a\r\n", intdiv($max, 2))])
            ->assertCreated();
    }

    public function test_images_are_stored_in_pick_order_and_come_back_with_the_message(): void
    {
        Notification::fake();
        [$viewer, $counterpart] = Member::factory()->count(2)->create();

        $response = $this->actingAs($viewer)
            ->post("/messages/{$counterpart->getKey()}", [
                'body' => 'look at these',
                'images' => [$this->upload('first.png'), $this->upload('second.png'), $this->upload('third.png')],
            ])
            ->assertCreated();

        $message = DirectMessage::findOrFail($response->json('id'));
        $this->assertSame([1, 2, 3], $message->files()->orderBy('number')->pluck('number')->all());
        $this->assertSame(
            ['first.png', 'second.png', 'third.png'],
            $message->files()->with('file')->orderBy('number')->get()->map(fn ($image) => $image->file?->original_filename)->all(),
        );
        $response->assertJsonCount(3, 'images');
    }

    /** Over the cap the whole message is refused — nothing half-sends and the composer keeps its draft. */
    public function test_a_fourth_image_takes_the_whole_message_down(): void
    {
        [$viewer, $counterpart] = Member::factory()->count(2)->create();

        $this->actingAs($viewer)
            ->postJson("/messages/{$counterpart->getKey()}", [
                'body' => 'look',
                'images' => [$this->upload(), $this->upload(), $this->upload(), $this->upload()],
            ])
            ->assertJsonValidationErrorFor('images');

        $this->assertDatabaseCount('direct_messages', 0);
        $this->assertDatabaseCount('direct_message_files', 0);
        $this->assertDatabaseCount('files', 0);
    }

    /** @return array<string, array{0: string}> */
    public static function refusedPairs(): array
    {
        return ['blocked by the counterpart' => ['blockedBy'], 'blocking the counterpart' => ['blocking'], 'a banned counterpart' => ['banned']];
    }

    /**
     * The gate the mailbox's compose uses, reported against `body` so the composer can show it over
     * the message it is still holding.
     */
    #[DataProvider('refusedPairs')]
    public function test_a_refused_pair_is_a_422_that_writes_nothing(string $situation): void
    {
        [$viewer, $counterpart] = Member::factory()->count(2)->create();
        match ($situation) {
            'blockedBy' => DB::table('member_blocks')->insert(['blocker_id' => $counterpart->getKey(), 'blocked_id' => $viewer->getKey(), 'created_at' => now()]),
            'blocking' => DB::table('member_blocks')->insert(['blocker_id' => $viewer->getKey(), 'blocked_id' => $counterpart->getKey(), 'created_at' => now()]),
            'banned' => $counterpart->forceFill(['is_login_rejected' => true])->save(),
        };

        $this->actingAs($viewer)
            ->postJson("/messages/{$counterpart->getKey()}", ['body' => 'hello'])
            ->assertStatus(422)
            ->assertJsonPath('errors.body.0', 'Cannot send the message.');

        $this->assertDatabaseCount('direct_messages', 0);
    }

    /** A refused pair is offered no composer at all, rather than a dead one. */
    public function test_the_page_says_whether_the_conversation_can_be_written_to(): void
    {
        [$viewer, $counterpart] = Member::factory()->count(2)->create();

        $this->actingAs($viewer)->get("/messages/{$counterpart->getKey()}")
            ->assertInertia(fn ($page) => $page->where('canSend', true));

        DB::table('member_blocks')->insert(['blocker_id' => $counterpart->getKey(), 'blocked_id' => $viewer->getKey(), 'created_at' => now()]);

        $this->actingAs($viewer)->get("/messages/{$counterpart->getKey()}")
            ->assertInertia(fn ($page) => $page->where('canSend', false));
    }

    /** The bucket names no member to deliver to, so it is read-only by construction. */
    public function test_the_withdrawn_bucket_has_no_composer_and_no_store_route(): void
    {
        $viewer = Member::factory()->create();

        $this->actingAs($viewer)->get('/messages/withdrawn')
            ->assertInertia(fn ($page) => $page->where('canSend', false));
        // Not a refusal the controller makes: the path has no POST at all.
        $this->actingAs($viewer)->postJson('/messages/withdrawn', ['body' => 'hello'])->assertMethodNotAllowed();
    }

    public function test_a_message_to_yourself_is_refused(): void
    {
        $viewer = Member::factory()->create();

        $this->actingAs($viewer)->postJson("/messages/{$viewer->getKey()}", ['body' => 'hello'])->assertNotFound();
        $this->assertDatabaseCount('direct_messages', 0);
    }

    public function test_the_unit_switched_off_takes_the_send_with_it(): void
    {
        [$viewer, $counterpart] = Member::factory()->count(2)->create();
        $this->setSnsSetting(SnsSettingKey::FeatureDirectMessageEnabled, false);

        $this->actingAs($viewer)->postJson("/messages/{$counterpart->getKey()}", ['body' => 'hello'])->assertNotFound();
        $this->assertDatabaseCount('direct_messages', 0);
    }

    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        $counterpart = Member::factory()->create();

        $this->post("/messages/{$counterpart->getKey()}", ['body' => 'hello'])->assertRedirect('/login');
        $this->assertDatabaseCount('direct_messages', 0);
    }

    /** One member's sending budget, whichever screen spends it. */
    public function test_the_send_carries_the_same_throttle_the_mailbox_compose_does(): void
    {
        $route = Route::getRoutes()->getByName('message.chat.store');

        $this->assertNotNull($route);
        $this->assertContains('throttle:direct-message-send', $route->gatherMiddleware());
    }
}
