<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Features\GroupTalk\Actions\CreateGroupMessage;
use App\Features\GroupTalk\Exceptions\GroupTalkActionException;
use App\Features\GroupTalk\Exceptions\GroupTalkActionFailure;
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

        $replyTo = null;

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
        }

        try {
            $message = $this->post($create, $mentions, $member, $group, $validated['body'], $replyTo);
        } catch (GroupTalkActionException) {
            // A room the caller may read but not write to. Same answer as a room that is not there.
            return $this->refused();
        }

        $message->setRelation('author', $member);
        $message->loadMissing('images');

        return Response::structured(['message' => McpTalkSerializer::message($message)]);
    }

    /**
     * The posted message, with the answered author addressed at its head when there is one to
     * address.
     *
     * The handle is written optimistically and verified where it is resolved: `mentionsRequired`
     * makes the write roll back rather than store a body whose handle names nobody, so the check and
     * the write cannot disagree however long they are apart. Asking `isMentionable` up front instead
     * would only narrow the window — talk has no edit, so a body that got through it is permanent.
     * The ordinary answer therefore costs one mentionability query, the one inside the transaction.
     *
     * @param  string  $body  already trimmed: trimming after the prefix would eat the separating
     *                        space and shift every range that follows it
     *
     * @throws GroupTalkActionException from the write itself, never for a dropped mention
     */
    private function post(
        CreateGroupMessage $create,
        ResolveMentions $mentions,
        Member $member,
        Group $group,
        string $body,
        ?GroupMessage $replyTo,
    ): GroupMessage {
        // The only mentions here are the ones this tool composes: no body is ever parsed for `@`
        // (docs/internals/group-talk.md), and this wire has no picker.
        $plain = fn (): GroupMessage => $create($member, $group, $body);
        $addressing = function (Member $author) use ($create, $member, $group, $body): GroupMessage {
            [$addressed, $rows] = $this->addressed($author, $body);

            return $create($member, $group, $addressed, mentions: $rows, mentionsRequired: true);
        };

        $author = $replyTo?->author;

        // A withdrawn author leaves nobody to address, and mentioning yourself is what resolution
        // drops anyway — neither needs to be asked.
        if ($author === null || $author->is($member)) {
            return $plain();
        }

        try {
            return $addressing($author);
        } catch (GroupTalkActionException $e) {
            if ($e->reason !== GroupTalkActionFailure::MentionDropped) {
                throw $e;
            }
        }

        // The handle went stale while it was being written. Read the author again — now that there
        // is a reason to — and ask the gate: gone, blocked or frozen leaves nobody to address; a
        // rename leaves a new handle, which the write verifies in turn.
        $replyTo->unsetRelation('author');
        $author = $replyTo->author;

        if ($author === null || ! $mentions->isMentionable($member, (int) $author->getKey(), $group)) {
            return $plain();
        }

        try {
            return $addressing($author);
        } catch (GroupTalkActionException $e) {
            if ($e->reason !== GroupTalkActionFailure::MentionDropped) {
                throw $e;
            }
        }

        // One retry is where this stops: a caller racing every attempt is answered as one with
        // nobody to address, which is a message posted rather than a message lost.
        return $plain();
    }

    /**
     * The body with $author addressed at its head, and the single mention row that names them.
     *
     * The handle is the stored name, never the display one: ResolveMentions matches the range
     * against `'@'.$name` character for character, so an "(AI)" suffix would leave the row silently
     * dropped. The separating space sits outside the range, exactly where the composer's picker
     * leaves it.
     *
     * @return array{0: string, 1: list<array{member_id: int, offset: int, length: int}>}
     */
    private function addressed(Member $author, string $body): array
    {
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
