<?php

namespace App\Features\GroupTalk;

use App\Features\GroupTalk\Actions\AddMessageReaction;
use App\Features\GroupTalk\Actions\RemoveMessageReaction;
use App\Features\GroupTalk\Exceptions\GroupTalkActionException;
use App\Features\GroupTalk\Queries\MessageReactionAggregates;
use App\Features\GroupTalk\Queries\MessageReactors;
use App\Http\Controllers\Controller;
use App\Http\Requests\GroupTalk\StoreReactionRequest;
use App\Models\Group;
use App\Models\GroupMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Adding and removing are two URLs rather than one toggle, so a tap that is retried, doubled or
 * racing the poll settles where the member pointed.
 */
class GroupTalkReactionController extends Controller
{
    public function store(StoreReactionRequest $request, Group $group, GroupMessage $message, AddMessageReaction $action, MessageReactionAggregates $reactions): JsonResponse
    {
        $this->authorizeWrite($group, $message);

        try {
            $action($this->viewer(), $message, $request->validated('emoji'));
        } catch (GroupTalkActionException) {
            abort(404);
        }

        return $this->state($message, $reactions);
    }

    /**
     * The emoji is checked for shape only, never against the vocabulary: a member holds reactions
     * from whatever the site offered at the time, and narrowing it must not strand them.
     */
    public function delete(Request $request, Group $group, GroupMessage $message, RemoveMessageReaction $action, MessageReactionAggregates $reactions): JsonResponse
    {
        $this->authorizeWrite($group, $message);
        $emoji = (string) $request->validate(['emoji' => ['required', 'string', 'max:32']])['emoji'];

        try {
            $action($this->viewer(), $message, $emoji);
        } catch (GroupTalkActionException) {
            abort(404);
        }

        return $this->state($message, $reactions);
    }

    public function index(Group $group, GroupMessage $message, MessageReactors $reactors): JsonResponse
    {
        abort_unless($message->group_id === $group->getKey(), 404);
        abort_unless(GroupTalkAccess::canView($group, $this->viewer()), 404);

        return response()->json(['groups' => $reactors($message)]);
    }

    private function authorizeWrite(Group $group, GroupMessage $message): void
    {
        abort_unless($message->group_id === $group->getKey(), 404);
        abort_unless(GroupTalkAccess::canPost($group, $this->viewer()), 404);
    }

    private function state(GroupMessage $message, MessageReactionAggregates $reactions): JsonResponse
    {
        return response()->json(['reactions' => $reactions->of($this->viewer(), $message)]);
    }
}
