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
use App\Features\GroupTalk\Queries\ReplyReferences;
use App\Features\GroupTalk\Queries\TalkAbsenceDigest;
use App\Features\GroupTalk\Queries\TalkUnreadSnapshot;
use App\Features\GroupTalk\Queries\TouchedGroupMessages;
use App\Features\GroupTalk\Serializers\GroupMessageSerializer;
use App\Features\Member\Serializers\MemberRefSerializer;
use App\Features\Reactions\Queries\ReactionAggregates;
use App\Features\Reactions\ReactionVocabulary;
use App\Http\Controllers\Concerns\RespondsWithSurface;
use App\Http\Controllers\Controller;
use App\Http\Requests\GroupTalk\MarkTalkReadRequest;
use App\Http\Requests\GroupTalk\StoreGroupMessageRequest;
use App\LinkCard\LinkCardSync;
use App\Models\Group;
use App\Models\GroupMessage;
use App\Models\Member;
use App\Support\SurfaceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class GroupTalkController extends Controller
{
    use RespondsWithSurface;

    /** The Classic page, OpenPNE 3's community timeline count; Modern keeps GroupTalkMessages::PER_PAGE. */
    public const CLASSIC_PAGE = 20;

    public function show(Request $request, Group $group, GroupTalkMessages $query, TalkUnreadSnapshot $unread, ReactionAggregates $reactions, TalkAbsenceDigest $digest, ReplyReferences $replies, LinkCardSync $linkCards, MarkTalkRead $markRead): View|InertiaResponse
    {
        $viewer = $this->viewer();
        abort_unless(GroupTalkAccess::canView($group, $viewer), 404);
        $permissions = GroupTalkPermissions::for($group, $viewer);
        // Resolved after the gate, so a link into a conversation the viewer may not read changes
        // nothing about the refusal.
        $anchor = $this->liveMessage($group, $request->query('m'));

        return $this->respondWith($request, 'group', [
            SurfaceResolver::CLASSIC => fn (): View => $this->classicScreen($request, $group, $viewer, $permissions, $anchor, $query, $replies, $linkCards, $markRead),
            SurfaceResolver::MODERN => fn (): InertiaResponse => $this->modernScreen($group, $viewer, $permissions, $anchor, $query, $unread, $reactions, $digest, $replies, $linkCards),
        ]);
    }

    /**
     * OpenPNE 3's community timeline, newest first: an anchor opens at the top of its page, and the
     * head page is what the member has now seen, so it is marked read as far as it rendered
     * (docs/internals/group-talk.md, "The Classic screen").
     */
    private function classicScreen(Request $request, Group $group, Member $viewer, GroupTalkPermissions $permissions, ?GroupMessage $anchor, GroupTalkMessages $query, ReplyReferences $replies, LinkCardSync $linkCards, MarkTalkRead $markRead): View
    {
        $this->markLocalNavGroup($group);

        $before = GroupTalkCursor::tryParse($request->query('before'));
        $page = match (true) {
            $anchor !== null => $query->upTo($group, GroupTalkCursor::of($anchor), self::CLASSIC_PAGE),
            $before !== null => $query->before($group, $before, self::CLASSIC_PAGE),
            default => $query->latest($group, self::CLASSIC_PAGE),
        };
        $linkCards->ensureAll($page->messages);
        $messages = $page->messages->reverse()->values();

        $isHead = $before === null && ! $page->hasNewer;
        if ($isHead && $permissions->canPost && $messages->isNotEmpty()) {
            try {
                $markRead($viewer, $group, (int) $messages->first()->getKey());
            } catch (GroupTalkActionException) {
                // A membership that ended between the gate and here: nothing to mark.
            }
        }

        $oldest = $page->hasOlder ? $page->messages->first() : null;
        $olderCursor = $oldest === null ? null : (string) GroupTalkCursor::of($oldest);

        return view('group-talk.index', [
            'group' => $group,
            'messages' => $messages,
            'parents' => $replies($group, $page->messages),
            'permissions' => $permissions,
            'canPost' => $permissions->canPost,
            'isMuted' => $permissions->canPost && GroupTalkPermissions::isMuted($group, $viewer),
            'replyTo' => $permissions->canPost ? $this->liveMessage($group, $request->query('reply') ?? old('reply_to_message_id')) : null,
            'olderUrl' => $olderCursor === null ? null : route('group.talk.show', ['group' => $group, 'before' => $olderCursor]),
            'newerUrl' => $isHead ? null : route('group.talk.show', $group),
            'loadMoreUrl' => $olderCursor === null ? null : route('group.talk.rows', ['group' => $group, 'before' => $olderCursor]),
        ]);
    }

    private function modernScreen(Group $group, Member $viewer, GroupTalkPermissions $permissions, ?GroupMessage $anchor, GroupTalkMessages $query, TalkUnreadSnapshot $unread, ReactionAggregates $reactions, TalkAbsenceDigest $digest, ReplyReferences $replies, LinkCardSync $linkCards): InertiaResponse
    {
        $reactionsVersion = TalkReactionVersion::of($group);
        $page = $anchor === null ? $query->latest($group) : $query->around($group, GroupTalkCursor::of($anchor));
        // Talk has no detail page, so its reads are where a card is asked for, and only for the rows
        // they render (docs/internals/link-cards.md, "The conversation page is talk's detail page").
        $linkCards->ensureAll($page->messages);
        $snapshot = $unread($group, $viewer);

        $props = [
            'group' => GroupSerializer::summary($group),
            'page' => GroupMessageSerializer::page($page, $permissions, $reactions($viewer, GroupMessage::class, $page->messages->map(fn (GroupMessage $message): int => (int) $message->getKey())->all()), $replies($group, $page->messages)),
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
     * is simply no message — the newest page opens, and no reply is offered — rather than a refusal.
     */
    private function liveMessage(Group $group, mixed $id): ?GroupMessage
    {
        if (is_int($id)) {
            $id = (string) $id;
        }
        if (! is_string($id) || ! ctype_digit($id)) {
            return null;
        }

        return GroupMessage::query()->where('group_id', $group->getKey())->with('author')->find((int) $id);
    }

    /** A page of Classic rows, the next page named in a Link header. */
    public function rows(Request $request, Group $group, GroupTalkMessages $query, ReplyReferences $replies, LinkCardSync $linkCards): Response
    {
        $viewer = $this->viewer();
        abort_unless(GroupTalkAccess::canView($group, $viewer), 404);

        $before = GroupTalkCursor::tryParse($request->query('before'));
        $page = $before === null ? $query->latest($group, self::CLASSIC_PAGE) : $query->before($group, $before, self::CLASSIC_PAGE);
        $linkCards->ensureAll($page->messages);

        $response = response()
            ->view('group-talk._rows', [
                'messages' => $page->messages->reverse()->values(),
                'parents' => $replies($group, $page->messages),
                'permissions' => GroupTalkPermissions::for($group, $viewer),
            ])
            ->header('Cache-Control', 'private, no-store');

        if ($page->hasOlder && ($oldest = $page->messages->first()) !== null) {
            $next = route('group.talk.rows', ['group' => $group, 'before' => (string) GroupTalkCursor::of($oldest)]);
            $response->header('Link', "<{$next}>; rel=\"next\"");
        }

        return $response;
    }

    /**
     * A cursor that does not parse is simply no cursor: a position is not a permission, and the gate
     * has already decided the audience.
     */
    public function messages(Request $request, Group $group, GroupTalkMessages $query, TouchedGroupMessages $touched, ReactionAggregates $reactions, ReplyReferences $replies, LinkCardSync $linkCards): JsonResponse
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
        $payload = GroupMessageSerializer::page($page, $permissions, $reactions($viewer, GroupMessage::class, $page->messages->map(fn (GroupMessage $message): int => (int) $message->getKey())->all()), $replies($group, $page->messages));

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
    private function touched(Collection $rows, Group $group, GroupTalkPermissions $permissions, int $snapshot, ReactionAggregates $reactions, ReplyReferences $replies): array
    {
        $capped = $rows->count() > GroupTalkMessages::PER_PAGE;
        $rows = $rows->take(GroupTalkMessages::PER_PAGE);
        $chips = $reactions($permissions->member, GroupMessage::class, $rows->map(fn (GroupMessage $message): int => (int) $message->getKey())->all());
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

    public function store(StoreGroupMessageRequest $request, Group $group, CreateGroupMessage $action, ReplyReferences $replies): JsonResponse|RedirectResponse
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

        // The Classic form: back to the head of the conversation, where the message now leads.
        if (! $request->wantsJson()) {
            return redirect()->route('group.talk.show', $group)->with('status', __('Posted.'));
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
            ])->redirectTo(route('group.talk.show', $group));
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

    public function mute(Request $request, Group $group, SetTalkMute $action): Response|RedirectResponse
    {
        abort_unless(GroupTalkAccess::canView($group, $this->viewer()), 404);
        $muted = (bool) $request->validate(['muted' => ['required', 'boolean']])['muted'];

        try {
            $action($this->viewer(), $group, $muted);
        } catch (GroupTalkActionException) {
            abort(404);
        }

        if (! $request->wantsJson()) {
            return redirect()->route('group.talk.show', $group)->with('status', $muted ? __('Muted.') : __('Unmuted.'));
        }

        return response()->noContent();
    }

    public function showDelete(Request $request, Group $group, GroupMessage $message): View|RedirectResponse
    {
        abort_unless($message->group_id === $group->getKey(), 404);
        abort_unless(GroupTalkPermissions::for($group, $this->viewer())->canDelete($message), 404);

        // Modern confirms in its own action bar; the Classic viewer without the dialog script lands here.
        if (SurfaceResolver::resolve($request, 'group') === SurfaceResolver::MODERN) {
            return redirect()->route('group.talk.show', ['group' => $group, 'm' => $message->getKey()]);
        }

        $this->markLocalNavGroup($group);

        return view('group-talk.delete', ['group' => $group, 'message' => $message->load('author.avatar.file', 'mentions')]);
    }

    public function delete(Request $request, Group $group, GroupMessage $message, DeleteGroupMessage $action): JsonResponse|RedirectResponse
    {
        abort_unless($message->group_id === $group->getKey(), 404);

        try {
            $action($this->viewer(), $message);
        } catch (GroupTalkActionException) {
            abort(404);
        }

        // The Classic dialog takes the row off the page itself; it has nowhere to be sent.
        if ($request->wantsJson()) {
            return response()->json(['ok' => true])->header('Cache-Control', 'private, no-store');
        }

        return redirect()->route('group.talk.show', $group)->with('status', __('Message deleted.'));
    }
}
