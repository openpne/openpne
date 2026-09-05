<?php

namespace App\Features\GroupTalk;

use App\Features\Group\Serializers\GroupSerializer;
use App\Features\GroupTalk\Actions\CreateGroupMessage;
use App\Features\GroupTalk\Actions\DeleteGroupMessage;
use App\Features\GroupTalk\Actions\MarkTalkRead;
use App\Features\GroupTalk\Actions\SetTalkMute;
use App\Features\GroupTalk\Exceptions\GroupTalkActionException;
use App\Features\GroupTalk\Queries\GroupTalkMentionCandidates;
use App\Features\GroupTalk\Queries\GroupTalkMessages;
use App\Features\GroupTalk\Queries\MessageReactionAggregates;
use App\Features\GroupTalk\Queries\ReplyReferences;
use App\Features\GroupTalk\Queries\TalkAbsenceDigest;
use App\Features\GroupTalk\Queries\TalkUnreadSnapshot;
use App\Features\GroupTalk\Queries\TouchedGroupMessages;
use App\Features\GroupTalk\Serializers\GroupMessageSerializer;
use App\Features\Member\Serializers\MemberRefSerializer;
use App\Features\Reactions\ReactionVocabulary;
use App\Http\Controllers\Controller;
use App\Http\Requests\GroupTalk\MarkTalkReadRequest;
use App\Http\Requests\GroupTalk\StoreGroupMessageRequest;
use App\LinkCard\LinkCardSync;
use App\Models\Group;
use App\Models\GroupMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class GroupTalkController extends Controller
{
    public function show(Request $request, Group $group, GroupTalkMessages $query, TalkUnreadSnapshot $unread, MessageReactionAggregates $reactions, TalkAbsenceDigest $digest, ReplyReferences $replies, LinkCardSync $linkCards): InertiaResponse
    {
        $viewer = $this->viewer();
        abort_unless(GroupTalkAccess::canView($group, $viewer), 404);
        $permissions = GroupTalkPermissions::for($group, $viewer);
        // Resolved after the gate, so a link into a conversation the viewer may not read changes
        // nothing about the refusal.
        $anchor = $this->anchor($group, $request->query('m'));
        $reactionsVersion = TalkReactionVersion::of($group);
        $page = $anchor === null ? $query->latest($group) : $query->around($group, GroupTalkCursor::of($anchor));
        // Talk has no detail page, so its reads are where a card is asked for, and only for the rows
        // they render (docs/internals/link-cards.md, "The conversation page is talk's detail page").
        $linkCards->ensureAll($page->messages);
        $snapshot = $unread($group, $viewer);

        $props = [
            'group' => GroupSerializer::summary($group),
            'page' => GroupMessageSerializer::page($page, $permissions, $reactions($viewer, $page->messages), $replies($group, $page->messages)),
            'anchor' => $anchor === null ? null : ['messageId' => $anchor->getKey()],
            'canPost' => $permissions->canPost,
            // Only a member holds a cursor or a mute, so only a member is offered either.
            'isMember' => $permissions->canPost,
            'isMuted' => $permissions->canPost && GroupTalkPermissions::isMuted($group, $viewer),
            // Fixed at render time: the divider names the line the reader opened on, while the
            // shared `unread` badge prop goes on tracking the live count.
            'talkUnreadSnapshot' => GroupMessageSerializer::unreadSnapshot($snapshot),
            'reactionsVersion' => $reactionsVersion,
            // The set travels with the page so that nothing in the bundle holds a copy of it.
            'reactionVocabulary' => ReactionVocabulary::all(),
        ];

        // Absent rather than null below the threshold, where no digest query runs at all.
        $absence = $digest($group, $viewer, $snapshot);
        if ($absence !== null) {
            $props['unreadDigest'] = $absence;
        }

        return Inertia::render('group/talk/index', $props);
    }

    /**
     * Best-effort by contract: a deleted message, another group's id, or anything that does not parse
     * opens the newest page rather than refusing the conversation.
     */
    private function anchor(Group $group, mixed $id): ?GroupMessage
    {
        if (! is_string($id) || ! ctype_digit($id)) {
            return null;
        }

        return GroupMessage::query()->where('group_id', $group->getKey())->find((int) $id);
    }

    /**
     * A cursor that does not parse is simply no cursor: a position is not a permission, and the gate
     * has already decided the audience.
     */
    public function messages(Request $request, Group $group, GroupTalkMessages $query, TouchedGroupMessages $touched, MessageReactionAggregates $reactions, ReplyReferences $replies, LinkCardSync $linkCards): JsonResponse
    {
        $viewer = $this->viewer();
        abort_unless(GroupTalkAccess::canView($group, $viewer), 404);

        $context = GroupTalkCursor::tryParse($request->query('context'));
        $after = GroupTalkCursor::tryParse($request->query('after'));
        $before = GroupTalkCursor::tryParse($request->query('before'));
        $reactionsAfter = $this->reactionsAfter($request->query('reactionsAfter'));

        $snapshot = $reactionsAfter === null ? null : TalkReactionVersion::of($group);

        $page = match (true) {
            $context !== null => $query->around($group, $context),
            $after !== null => $query->after($group, $after),
            $before !== null => $query->before($group, $before),
            default => $query->latest($group),
        };

        $linkCards->ensureAll($page->messages);

        $permissions = GroupTalkPermissions::for($group, $viewer);
        $payload = GroupMessageSerializer::page($page, $permissions, $reactions($viewer, $page->messages), $replies($group, $page->messages));

        if ($reactionsAfter !== null) {
            $payload += $this->touched($touched($group, $reactionsAfter), $group, $permissions, $snapshot ?? 0, $reactions, $replies);
        }

        return response()->json($payload);
    }

    /**
     * Unparseable is read as absent rather than refused, exactly as a malformed cursor is: a poll
     * that 422s would take the whole conversation off the screen over a watermark.
     */
    private function reactionsAfter(mixed $value): ?int
    {
        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }

    /**
     * A capped page reports the last row it returned rather than the snapshot, so nothing the cap
     * left behind is stepped over.
     *
     * @param  Collection<int, GroupMessage>  $rows  one over the cap, as the query returns them
     * @return array{touched: list<array>, reactionsVersion: int}
     */
    private function touched(Collection $rows, Group $group, GroupTalkPermissions $permissions, int $snapshot, MessageReactionAggregates $reactions, ReplyReferences $replies): array
    {
        $capped = $rows->count() > GroupTalkMessages::PER_PAGE;
        $rows = $rows->take(GroupTalkMessages::PER_PAGE);
        $chips = $reactions($permissions->member, $rows);
        $parents = $replies($group, $rows);

        return [
            'touched' => $rows->map(fn (GroupMessage $message): array => GroupMessageSerializer::message($message, $permissions, $chips[$message->getKey()] ?? [], $parents))->values()->all(),
            'reactionsVersion' => $capped ? (int) $rows->last()->reactions_version : $snapshot,
        ];
    }

    /** Gated on posting rather than reading: the roster is not a non-member's to browse. */
    public function mentionCandidates(Request $request, Group $group, GroupTalkMentionCandidates $query): JsonResponse
    {
        $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        $viewer = $this->viewer();
        abort_unless(GroupTalkAccess::canPost($group, $viewer), 404);

        return response()->json([
            'candidates' => array_map(
                [MemberRefSerializer::class, 'ref'],
                $query($viewer, $group, $request->string('q')->value())->all(),
            ),
        ]);
    }

    public function store(StoreGroupMessageRequest $request, Group $group, CreateGroupMessage $action, ReplyReferences $replies): JsonResponse
    {
        $viewer = $this->viewer();
        // Gated before the reply id is resolved: the resolve's 422 would otherwise be an existence
        // oracle over a group the viewer may not post to.
        abort_unless(GroupTalkAccess::canPost($group, $viewer), 404);
        $inReplyTo = $this->replyTo($group, $request->replyToMessageId());

        try {
            $message = $action($viewer, $group, $request->validated('body'), $request->mentions(), $request->pickedImages(), inReplyTo: $inReplyTo);
        } catch (GroupTalkActionException) {
            abort(404);
        }

        $message->setRelation('author', $viewer->loadMissing('avatar.file'));
        // PostImages writes the join rows through the relation without populating it, so the
        // serializer would otherwise lazy-load one query per attachment.
        $message->loadMissing('images.file');

        return response()->json(
            // A message a moment old has no reactions, so the empty chip row is passed rather than
            // queried for.
            GroupMessageSerializer::message($message, GroupTalkPermissions::for($group, $viewer), [], $replies->of($group, $message)),
            201,
        );
    }

    /**
     * Nothing is locked between this resolve and the insert: a parent deleted in that window leaves
     * a dangling reference, which is the state deleting one produces anyway.
     */
    private function replyTo(Group $group, ?int $id): ?GroupMessage
    {
        if ($id === null) {
            return null;
        }

        $message = GroupMessage::query()->where('group_id', $group->getKey())->whereKey($id)->first();

        if ($message === null) {
            throw ValidationException::withMessages([
                'reply_to_message_id' => __('The message you are replying to has been deleted.'),
            ]);
        }

        return $message;
    }

    public function read(MarkTalkReadRequest $request, Group $group, MarkTalkRead $action): Response
    {
        abort_unless(GroupTalkAccess::canView($group, $this->viewer()), 404);

        try {
            $action($this->viewer(), $group, $request->messageId());
        } catch (GroupTalkActionException) {
            abort(404);
        }

        return response()->noContent();
    }

    public function mute(Request $request, Group $group, SetTalkMute $action): Response
    {
        abort_unless(GroupTalkAccess::canView($group, $this->viewer()), 404);
        $muted = (bool) $request->validate(['muted' => ['required', 'boolean']])['muted'];

        try {
            $action($this->viewer(), $group, $muted);
        } catch (GroupTalkActionException) {
            abort(404);
        }

        return response()->noContent();
    }

    public function delete(Group $group, GroupMessage $message, DeleteGroupMessage $action): Response
    {
        abort_unless($message->group_id === $group->getKey(), 404);

        try {
            $action($this->viewer(), $message);
        } catch (GroupTalkActionException) {
            abort(404);
        }

        return response()->noContent();
    }
}
