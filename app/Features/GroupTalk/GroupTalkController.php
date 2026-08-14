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
use App\Features\GroupTalk\Queries\TalkUnreadSnapshot;
use App\Features\GroupTalk\Queries\TouchedGroupMessages;
use App\Features\GroupTalk\Serializers\GroupMessageSerializer;
use App\Features\Member\Serializers\MemberRefSerializer;
use App\Features\Reactions\ReactionVocabulary;
use App\Http\Controllers\Controller;
use App\Http\Requests\GroupTalk\MarkTalkReadRequest;
use App\Http\Requests\GroupTalk\StoreGroupMessageRequest;
use App\Models\Group;
use App\Models\GroupMessage;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * A group's talk: one linear conversation, Modern only (talk has no OpenPNE 3 counterpart, so there
 * is no Classic screen to be compatible with — /groups/recent takes the same shape).
 *
 * The page ships the newest slice, or the one a `?m=` deep link lands in; everything after it moves
 * over JSON — "load older" walks back by keyset and a poll asks what has arrived since. Every action
 * resolves the read gate first and answers 404 when it refuses, hiding whether the group has a
 * conversation at all.
 */
class GroupTalkController extends Controller
{
    public function show(Request $request, Group $group, GroupTalkMessages $query, TalkUnreadSnapshot $unread): InertiaResponse
    {
        $viewer = $this->viewer();
        abort_unless(GroupTalkAccess::canView($group, $viewer), 404);
        $permissions = GroupTalkPermissions::for($group, $viewer);
        // Resolved after the gate, so a link into a conversation the viewer may not read changes
        // nothing about the refusal.
        $anchor = $this->anchor($group, $request->query('m'));
        // Before the page is read, never after: a reaction landing between the two would otherwise
        // sit below the watermark the client starts polling from and never be asked for again.
        $reactionsVersion = TalkReactionVersion::of($group);

        return Inertia::render('group/talk/index', [
            'group' => GroupSerializer::summary($group),
            'page' => GroupMessageSerializer::page(
                $anchor === null ? $query->latest($group) : $query->around($group, GroupTalkCursor::of($anchor)),
                $permissions,
            ),
            'anchor' => $anchor === null ? null : ['messageId' => $anchor->getKey()],
            'canPost' => $permissions->canPost,
            // Only a member holds a cursor or a mute, so only a member is offered either.
            'isMember' => $permissions->canPost,
            'isMuted' => $permissions->canPost && GroupTalkPermissions::isMuted($group, $viewer),
            // Where the unread boundary stood at render time, and nothing later moves it: the page's
            // "from here" divider has to keep naming the line the reader opened on, while the shared
            // `unread` badge prop next to it goes on tracking the live count.
            'talkUnreadSnapshot' => GroupMessageSerializer::unreadSnapshot($unread($group, $viewer)),
            'reactionsVersion' => $reactionsVersion,
            // The vocabulary travels with the page rather than being duplicated in the bundle, so
            // App\Features\Reactions\ReactionVocabulary stays the one place it is written down and a
            // tab open across a deploy cannot offer an emoji the server has stopped accepting.
            'reactionVocabulary' => ReactionVocabulary::all(),
        ]);
    }

    /**
     * The message `?m=` asked to open on — a mention notification's deep link — or null for the
     * ordinary newest page.
     *
     * Best-effort by contract: a link naming a deleted message, another group's, or nothing that
     * parses opens the conversation as usual rather than refusing it. A stale link is a link to a
     * conversation that has moved on, and the notification feed's own click-time re-check does not
     * cover mail or a pasted URL.
     */
    private function anchor(Group $group, mixed $id): ?GroupMessage
    {
        if (! is_string($id) || ! ctype_digit($id)) {
            return null;
        }

        return GroupMessage::query()->where('group_id', $group->getKey())->find((int) $id);
    }

    /**
     * One page around a cursor the client was handed: `after` for the poll, `before` for "load
     * older", `context` for the page a position sits in (the unread boundary; a deep link's landing
     * is the same page, resolved by `show` above), none of them for the newest page. A cursor that
     * does not parse is simply no cursor — pagination is a position, not a permission, and the gate
     * above already decided the audience.
     */
    public function messages(Request $request, Group $group, GroupTalkMessages $query, TouchedGroupMessages $touched): JsonResponse
    {
        $viewer = $this->viewer();
        abort_unless(GroupTalkAccess::canView($group, $viewer), 404);

        $context = GroupTalkCursor::tryParse($request->query('context'));
        $after = GroupTalkCursor::tryParse($request->query('after'));
        $before = GroupTalkCursor::tryParse($request->query('before'));
        $reactionsAfter = $this->reactionsAfter($request->query('reactionsAfter'));

        // Read before the page, for the reason show() reads it before its own — and only when a
        // client asked, so the ordinary poll costs what it always did.
        $snapshot = $reactionsAfter === null ? null : TalkReactionVersion::of($group);

        $page = match (true) {
            $context !== null => $query->around($group, $context),
            $after !== null => $query->after($group, $after),
            $before !== null => $query->before($group, $before),
            default => $query->latest($group),
        };

        $permissions = GroupTalkPermissions::for($group, $viewer);
        $payload = GroupMessageSerializer::page($page, $permissions);

        if ($reactionsAfter !== null) {
            $payload += $this->touched($touched($group, $reactionsAfter), $permissions, $snapshot ?? 0);
        }

        return response()->json($payload);
    }

    /**
     * The reaction watermark a client is polling from, or null for one that does not speak the
     * protocol — a tab loaded before this shipped, or a surface (direct messages) that shares the
     * poll and has no reactions. Its answer keeps the shape it has always had, down to the absence
     * of these two keys.
     *
     * Unparseable is read as absent rather than refused, exactly as a malformed cursor is: a poll
     * that 422s would take the whole conversation off the screen over a watermark.
     */
    private function reactionsAfter(mixed $value): ?int
    {
        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }

    /**
     * The messages whose reactions changed since the client's watermark, and the watermark to come
     * back with.
     *
     * A full page means the catch-up is not finished, so the watermark is the last row returned
     * rather than the snapshot: moving it to the snapshot would step over everything the cap left
     * behind. Only an exhausted read may take the snapshot, which was read before the page and so
     * cannot be ahead of anything this answer omits.
     *
     * @param  Collection<int, GroupMessage>  $rows  one over the cap, as the query returns them
     * @return array{touched: list<array>, reactionsVersion: int}
     */
    private function touched(Collection $rows, GroupTalkPermissions $permissions, int $snapshot): array
    {
        $capped = $rows->count() > GroupTalkMessages::PER_PAGE;
        $rows = $rows->take(GroupTalkMessages::PER_PAGE);

        return [
            'touched' => $rows->map(fn (GroupMessage $message): array => GroupMessageSerializer::message($message, $permissions))->values()->all(),
            'reactionsVersion' => $capped ? (int) $rows->last()->reactions_version : $snapshot,
        ];
    }

    /**
     * What the composer's @mention picker reads. Gated on posting, not reading: only someone who can
     * write here has a mention to make, and the roster is not a non-member's to browse.
     */
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

    /** Returns the message it wrote, so the composer appends it rather than re-reading the page. */
    public function store(StoreGroupMessageRequest $request, Group $group, CreateGroupMessage $action): JsonResponse
    {
        $viewer = $this->viewer();

        try {
            $message = $action($viewer, $group, $request->validated('body'), $request->mentions(), $request->pickedImages());
        } catch (GroupTalkActionException) {
            abort(404);
        }

        // The author is the viewer; hand the model over rather than re-reading the row just written.
        $message->setRelation('author', $viewer->loadMissing('avatar.file'));
        // PostImages creates the join rows through the relation, which does not populate it — load
        // them explicitly rather than letting the serializer lazy-load one query per attachment.
        $message->loadMissing('images.file');
        // A message a moment old has no reactions by construction; say so rather than pay a query to
        // be told.
        $message->setRelation('reactions', new EloquentCollection);

        return response()->json(
            GroupMessageSerializer::message($message, GroupTalkPermissions::for($group, $viewer)),
            201,
        );
    }

    /**
     * "I have read as far as this message." Fire-and-forget from the reader's side: it carries no
     * body back, and the shell's own refresh is what moves the badge.
     */
    public function read(MarkTalkReadRequest $request, Group $group, MarkTalkRead $action): Response
    {
        abort_unless(GroupTalkAccess::canView($group, $this->viewer()), 404);

        try {
            $action($this->viewer(), $group, (int) $request->validated('messageId'));
        } catch (GroupTalkActionException) {
            // A message deleted between rendering and this call is an ordinary race, and so is a
            // non-member trying to hold a cursor; neither is worth a distinct answer.
            abort(404);
        }

        return response()->noContent();
    }

    /** Per-group quiet, on the membership row. Explicit state rather than a blind flip, so a double tap settles. */
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
        // The message id names its group already; the path carries both, so a mismatch is a
        // malformed URL rather than a message to act on.
        abort_unless($message->group_id === $group->getKey(), 404);

        try {
            $action($this->viewer(), $message);
        } catch (GroupTalkActionException) {
            abort(404);
        }

        return response()->noContent();
    }
}
