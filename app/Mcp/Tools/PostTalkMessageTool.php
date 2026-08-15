<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Features\GroupTalk\Actions\CreateGroupMessage;
use App\Features\GroupTalk\Exceptions\GroupTalkActionException;
use App\Features\GroupTalk\Serializers\McpTalkSerializer;
use App\Features\GroupTalk\TalkBody;
use App\Features\Timeline\Actions\ResolveMentions;
use App\Mcp\McpAbilities;
use App\Models\Group;
use App\Models\GroupMessage;
use App\Models\Member;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('post-talk-message')]
#[Title('Post a talk message')]
#[Description('Say something in a group talk room you belong to. The message is posted as you, is visible to the room immediately, and cannot be edited afterwards. Answering a message by its id addresses its author, who is then notified.')]
class PostTalkMessageTool extends TalkTool
{
    public function handle(Request $request, CreateGroupMessage $create, ResolveMentions $mentions): Response|ResponseFactory
    {
        $member = $this->member($request);

        // The endpoint only ever asked for mcp:read, so writing is checked again here — that is what
        // makes a read-only token a read-only token.
        if (! $member->tokenCan(McpAbilities::WRITE)) {
            return Response::error(self::MISSING_WRITE);
        }

        $body = $request->get('body');
        // Normalized here, not left to middleware: the direct tool path (Server::tool, and any
        // future non-HTTP transport) never meets TrimStrings, so the contract has to live where the
        // body is read. Anything that is not a string is left as it came for the `string` rule to
        // refuse, rather than coerced into a body nobody wrote. Trimming happens before any handle
        // is prefixed, so the space between the two survives.
        $body = is_string($body) ? trim(TalkBody::normalize($body)) : $body;

        /** @var array{group_id: int, body: string, reply_to_message_id?: int} $validated */
        $validated = Validator::make(
            [
                'group_id' => $request->get('group_id'),
                'body' => $body,
                // Only when it was actually sent: `sometimes` treats a key holding null as an
                // argument to check, and null is not an integer.
                ...$request->only('reply_to_message_id'),
            ],
            [
                'group_id' => ['required', 'integer', 'min:1'],
                // Measured after normalization, so the cap counts the code points that will be stored.
                'body' => ['required', 'string', 'max:'.TalkBody::MAX],
                'reply_to_message_id' => ['sometimes', 'integer', 'min:1'],
            ],
        )->validate();

        $group = $this->readableRoom($member, (int) $validated['group_id']);
        if ($group === null) {
            return $this->refused();
        }

        $body = $validated['body'];
        $mentionRows = [];

        if (isset($validated['reply_to_message_id'])) {
            // A live row of THIS room, resolved as MarkTalkRead resolves one: another room's message
            // is not distinguishable here from an id that names nothing.
            $replyTo = GroupMessage::query()
                ->where('group_id', $group->getKey())
                ->whereKey((int) $validated['reply_to_message_id'])
                ->first();

            if ($replyTo === null) {
                return $this->refused();
            }

            [$body, $mentionRows] = $this->addressing($replyTo, $member, $group, $body, $mentions);
        }

        try {
            // The only mentions here are the ones this tool composed above: no body is ever parsed
            // for `@` (docs/internals/group-talk.md), and this wire has no picker.
            $message = $create($member, $group, $body, mentions: $mentionRows);
        } catch (GroupTalkActionException) {
            // A room the caller may read but not write to. Same answer as a room that is not there.
            return $this->refused();
        }

        $message->setRelation('author', $member);
        $message->loadMissing('images');

        return Response::structured(['message' => McpTalkSerializer::message($message)]);
    }

    /**
     * The body with the answered message's author addressed at its head, and the single mention row
     * that names them — or both left as they were when there is nobody to address.
     *
     * The handle is the stored name, never the display one: ResolveMentions matches the range
     * against `'@'.$name` character for character, so an "(AI)" suffix would leave the row silently
     * dropped. The separating space sits outside the range, exactly where the composer's picker
     * leaves it.
     *
     * @param  string  $body  already trimmed: trimming after the prefix would eat the separating
     *                        space and shift every range that follows it
     * @return array{0: string, 1: list<array{member_id: int, offset: int, length: int}>}
     */
    private function addressing(GroupMessage $replyTo, Member $member, Group $group, string $body, ResolveMentions $mentions): array
    {
        $author = $replyTo->author;

        // A withdrawn author leaves nobody to address. Everyone else goes through the gate the write
        // will apply anyway — one source, so a handle is never written for a mention that resolution
        // would drop — and that gate excludes the caller, so answering your own message adds nothing.
        if ($author === null || ! $mentions->isMentionable($member, (int) $author->getKey(), $group)) {
            return [$body, []];
        }

        $name = $author->name;
        $addressed = '@'.$name.' '.$body;

        // Measured again because the handle is the server's addition, not the caller's: nothing
        // downstream re-checks the body, so a message that fitted until it was an answer would
        // otherwise be stored over the cap.
        if (mb_strlen($addressed) > TalkBody::MAX) {
            throw ValidationException::withMessages([
                'body' => __('The reply body, with the @mention this adds to it, is longer than :max characters.', ['max' => TalkBody::MAX]),
            ]);
        }

        return [
            $addressed,
            [['member_id' => (int) $author->getKey(), 'offset' => 0, 'length' => 1 + mb_strlen($name)]],
        ];
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
                ->description('The message text, at most '.TalkBody::MAX.' characters. Plain text: writing "@name" into it addresses nobody, since only reply_to_message_id does that.'),
            'reply_to_message_id' => $schema->integer()->min(1)
                ->description('A message in the same room to answer, as read-talk-messages reports it in id. Its author is addressed at the head of your text and notified. No one is addressed when the message is your own or its author has withdrawn or left the room; the mentions of the posted message say who was.'),
        ];
    }
}
