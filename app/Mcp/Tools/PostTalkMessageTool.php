<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Features\GroupTalk\Actions\CreateGroupMessage;
use App\Features\GroupTalk\Exceptions\GroupTalkActionException;
use App\Features\GroupTalk\Serializers\McpTalkSerializer;
use App\Features\GroupTalk\TalkBody;
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

#[Name('post-talk-message')]
#[Title('Post a talk message')]
#[Description('Say something in a group talk room you belong to. The message is posted as you, is visible to the room immediately, and cannot be edited afterwards.')]
class PostTalkMessageTool extends TalkTool
{
    public function handle(Request $request, CreateGroupMessage $create): Response|ResponseFactory
    {
        $member = $this->member($request);

        // The endpoint only ever asked for mcp:read, so writing is checked again here — that is what
        // makes a read-only token a read-only token.
        if (! $member->tokenCan(McpAbilities::WRITE)) {
            return Response::error(self::MISSING_WRITE);
        }

        $body = $request->get('body');
        // The browser's path gets this from the global TrimStrings and a form request, neither of
        // which a token request meets. Anything that is not a string is left as it came for the
        // `string` rule to refuse, rather than coerced into a body nobody wrote.
        $body = is_string($body) ? trim(TalkBody::normalize($body)) : $body;

        /** @var array{group_id: int, body: string} $validated */
        $validated = Validator::make(
            ['group_id' => $request->get('group_id'), 'body' => $body],
            [
                'group_id' => ['required', 'integer', 'min:1'],
                // Measured after normalization, so the cap counts the code points that will be stored.
                'body' => ['required', 'string', 'max:'.TalkBody::MAX],
            ],
        )->validate();

        $group = $this->readableRoom($member, (int) $validated['group_id']);
        if ($group === null) {
            return $this->refused();
        }

        try {
            // No mentions and no images: the picker's ranges are the only thing that becomes a
            // mention (docs/internals/group-talk.md), and this wire has no picker.
            $message = $create($member, $group, $validated['body']);
        } catch (GroupTalkActionException) {
            // A room the caller may read but not write to. Same answer as a room that is not there.
            return $this->refused();
        }

        $message->setRelation('author', $member);
        $message->loadMissing('images');

        return Response::structured(['message' => McpTalkSerializer::message($message)]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'group_id' => $schema->integer()->min(1)->required()
                ->description('The room to post in, as list-talk-rooms reports it in groupId.'),
            'body' => $schema->string()->required()->max(TalkBody::MAX)
                ->description('The message text, at most '.TalkBody::MAX.' characters. Plain text; @mentions are not addressed from here.'),
        ];
    }
}
