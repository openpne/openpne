<?php

namespace App\Features\GroupTalk;

use App\Features\Group\Serializers\GroupSerializer;
use App\Features\GroupTalk\Actions\CreateGroupMessage;
use App\Features\GroupTalk\Actions\DeleteGroupMessage;
use App\Features\GroupTalk\Exceptions\GroupTalkActionException;
use App\Features\GroupTalk\Queries\GroupTalkMessages;
use App\Features\GroupTalk\Serializers\GroupMessageSerializer;
use App\Http\Controllers\Controller;
use App\Http\Requests\GroupTalk\StoreGroupMessageRequest;
use App\Models\Group;
use App\Models\GroupMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * A group's talk: one linear conversation, Modern only (talk has no OpenPNE 3 counterpart, so there
 * is no Classic screen to be compatible with — /groups/recent takes the same shape).
 *
 * The page ships the newest slice; everything after it moves over JSON — "load older" walks back by
 * keyset and a poll asks what has arrived since. Every action resolves the read gate first and
 * answers 404 when it refuses, hiding whether the group has a conversation at all.
 */
class GroupTalkController extends Controller
{
    public function show(Group $group, GroupTalkMessages $query): InertiaResponse
    {
        $viewer = $this->viewer();
        abort_unless(GroupTalkAccess::canView($group, $viewer), 404);
        $permissions = GroupTalkPermissions::for($group, $viewer);

        return Inertia::render('group/talk/index', [
            'group' => GroupSerializer::summary($group),
            'page' => GroupMessageSerializer::page($query->latest($group), $permissions),
            'canPost' => $permissions->canPost,
        ]);
    }

    /**
     * One page either side of a cursor the client was handed: `after` for the poll, `before` for
     * "load older", neither for the newest page. A cursor that does not parse is simply no cursor —
     * pagination is a position, not a permission, and the gate above already decided the audience.
     */
    public function messages(Request $request, Group $group, GroupTalkMessages $query): JsonResponse
    {
        $viewer = $this->viewer();
        abort_unless(GroupTalkAccess::canView($group, $viewer), 404);

        $after = GroupTalkCursor::tryParse($request->query('after'));
        $before = GroupTalkCursor::tryParse($request->query('before'));

        $page = match (true) {
            $after !== null => $query->after($group, $after),
            $before !== null => $query->before($group, $before),
            default => $query->latest($group),
        };

        return response()->json(GroupMessageSerializer::page($page, GroupTalkPermissions::for($group, $viewer)));
    }

    /** Returns the message it wrote, so the composer appends it rather than re-reading the page. */
    public function store(StoreGroupMessageRequest $request, Group $group, CreateGroupMessage $action): JsonResponse
    {
        $viewer = $this->viewer();

        try {
            $message = $action($viewer, $group, $request->validated('body'));
        } catch (GroupTalkActionException) {
            abort(404);
        }

        // The author is the viewer; hand the model over rather than re-reading the row just written.
        $message->setRelation('author', $viewer->loadMissing('avatar.file'));

        return response()->json(
            GroupMessageSerializer::message($message, GroupTalkPermissions::for($group, $viewer)),
            201,
        );
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
