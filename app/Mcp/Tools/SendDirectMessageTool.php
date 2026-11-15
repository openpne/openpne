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
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
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
    public function handle(Request $request, SendDirectMessage $send): Response|ResponseFactory
    {
        $sender = $this->member($request);

        if (! $sender->tokenCan(McpAbilities::WRITE)) {
            return Response::error(self::MISSING_WRITE);
        }

        $body = $request->get('body');
        // This path meets no TrimStrings middleware; a non-string is left as it came for the `string`
        // rule to refuse rather than coerced. Newlines are normalized before the cap is measured, as
        // the chat screen's request does.
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
        // A refused pair — a block, a login-rejected recipient, or someone an AI account may not open
        // a conversation with — answers as a member who is not there, as reading their profile would.
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
        $message->load(['files', 'recipients']);

        return Response::structured([
            'memberId' => (int) $recipient->getKey(),
            'message' => McpConversationSerializer::message($message, $sender, $recipient),
        ]);
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
