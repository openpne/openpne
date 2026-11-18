<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Features\DirectMessage\Actions\SendDirectMessage;
use App\Features\DirectMessage\DirectMessageAccess;
use App\Features\DirectMessage\DirectMessageComposeData;
use App\Features\DirectMessage\Exceptions\DirectMessageActionException;
use App\Features\DirectMessage\Serializers\McpConversationSerializer;
use App\Http\Requests\Concerns\MentionRules;
use App\Http\Requests\DirectMessage\StoreChatMessageRequest;
use App\Mcp\McpAbilities;
use App\Models\Member;
use App\Support\SecurityLog;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('send-direct-message')]
#[Title('Send a direct message')]
#[Description('Write to a member one-to-one, as you. The message lands in your conversation with them, they are notified, and it cannot be edited afterwards. An AI account may write only to its owner and to members who have written to it first.')]
class SendDirectMessageTool extends DirectMessageTool
{
    private const DECAY_SECONDS = 60;

    public function handle(Request $request, SendDirectMessage $send): Response|ResponseFactory
    {
        $sender = $this->member($request);

        if (! $sender->tokenCan(McpAbilities::WRITE)) {
            return Response::error(self::MISSING_WRITE);
        }

        if (($throttled = $this->throttled($sender)) !== null) {
            return $throttled;
        }

        $body = $request->get('body');
        // This path meets no TrimStrings middleware, and newlines are normalized before the cap is
        // measured, as the chat screen's request does.
        $body = is_string($body) ? trim(MentionRules::normalizeNewlines($body)) : $body;

        /** @var array{member_id: int, body: string} $validated */
        $validated = Validator::make(
            ['member_id' => $request->get('member_id'), 'body' => $body],
            [
                'member_id' => ['required', 'integer', 'min:1'],
                // No attachments on this wire, so words are what a message has to carry.
                'body' => ['required', 'string', 'max:'.StoreChatMessageRequest::MAX_BODY],
            ],
        )->validate();

        $recipient = $this->counterpart($sender, (int) $validated['member_id']);
        // A block, a login-rejected recipient and someone an AI account may not write to answer as an
        // id naming nobody does, so the refusal does not say which of them it was.
        if ($recipient === null || ! DirectMessageAccess::canSend($sender, $recipient) || ! self::mayWrite($sender, $recipient)) {
            return $this->refused();
        }

        try {
            $message = $send(
                $sender,
                new DirectMessageComposeData((int) $recipient->getKey(), subject: null, body: $validated['body']),
                asDraft: false,
            );
        } catch (DirectMessageActionException) {
            // The pair became refused between the check above and the write's own.
            return $this->refused();
        }

        $message->setRelation('sender', $sender);
        $message->load('files');

        return Response::structured([
            'memberId' => (int) $recipient->getKey(),
            'message' => McpConversationSerializer::message($message, $sender),
        ]);
    }

    /**
     * Counted per member rather than per token, before the recipient is looked at, so a second token
     * buys no more sends and the answer says nothing about whom the call named
     * (docs/internals/security.md, "Rate limits on member requests").
     */
    private function throttled(Member $sender): ?Response
    {
        $perMinute = max(0, (int) config('openpne.throttle.direct_message'));
        if ($perMinute === 0) {
            return null;
        }

        $key = 'mcp-direct-message|'.$sender->getKey();

        if (RateLimiter::tooManyAttempts($key, $perMinute)) {
            SecurityLog::event('throttle.hit', [
                'route' => request()->route()?->getName(),
                'member_id' => $sender->getKey(),
                'tool' => $this->name(),
            ]);

            return Response::error('Too many direct messages. Try again in '.RateLimiter::availableIn($key).' seconds.');
        }

        RateLimiter::hit($key, self::DECAY_SECONDS);

        return null;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'member_id' => $schema->integer()->min(1)->required()
                ->description('The member to write to, as list-conversations reports it in memberId (or a message reports in authorId).'),
            'body' => $schema->string()->required()->max(StoreChatMessageRequest::MAX_BODY)
                ->description('The message. Plain text; line breaks are kept.'),
        ];
    }
}
