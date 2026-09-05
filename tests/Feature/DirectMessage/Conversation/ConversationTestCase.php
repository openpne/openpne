<?php

namespace Tests\Feature\DirectMessage\Conversation;

use App\Models\DirectMessage;
use App\Models\DirectMessageRecipient;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The chat screens render Inertia whatever the site's surface, but the shared Modern props they
 * assert on come from a Modern session.
 */
abstract class ConversationTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['openpne.surface_mode' => 'modern_default']);
    }

    /**
     * One delivered message: the authored row plus one receipt.
     *
     * @param  array<string, mixed>  $message  overrides on direct_messages
     * @param  array<string, mixed>  $receipt  overrides on the receipt
     */
    protected function deliver(?Member $sender, ?Member $recipient, array $message = [], array $receipt = []): DirectMessage
    {
        $row = DirectMessage::factory()->create(['sender_id' => $sender?->getKey(), ...$message]);
        DirectMessageRecipient::factory()->create([
            'direct_message_id' => $row->getKey(),
            'recipient_id' => $recipient?->getKey(),
            ...$receipt,
        ]);

        return $row;
    }

    /**
     * $count messages between the two members, one per minute, oldest first — alternating direction so
     * every page mixes both arms.
     *
     * @return list<DirectMessage>
     */
    protected function conversation(Member $viewer, Member $counterpart, int $count): array
    {
        $messages = [];
        for ($i = 0; $i < $count; $i++) {
            $at = Carbon::parse('2026-08-14 09:00:00')->addMinutes($i);
            $mine = $i % 2 === 0;
            $messages[] = $this->deliver(
                $mine ? $viewer : $counterpart,
                $mine ? $counterpart : $viewer,
                ['body' => "message {$i}", 'created_at' => $at, 'updated_at' => $at],
            );
        }

        return $messages;
    }

    /** @return list<string> the bodies of a serialized page, in order */
    protected function bodies(array $page): array
    {
        return array_column($page['messages'], 'body');
    }
}
