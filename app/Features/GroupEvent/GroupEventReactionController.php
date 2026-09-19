<?php

namespace App\Features\GroupEvent;

use App\Features\Group\BoardReactionSurface;
use App\Features\Reactions\Actions\AddReaction;
use App\Features\Reactions\Actions\RemoveReaction;
use App\Features\Reactions\Exceptions\ReactionRefused;
use App\Features\Reactions\Queries\ReactionAggregates;
use App\Features\Reactions\Queries\Reactors;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reactions\StoreReactionRequest;
use App\Models\GroupEvent;
use App\Models\GroupEventComment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reacting takes the board's write permission, as commenting does; the reactor list its read permission.
 * The gate runs before the emoji is validated, so an invalid payload gets the same 404 as a valid one.
 */
class GroupEventReactionController extends Controller
{
    public function store(Request $request, GroupEvent $event, AddReaction $action, ReactionAggregates $reactions): JsonResponse
    {
        return $this->add($request, $event, $event, $action, $reactions);
    }

    public function delete(Request $request, GroupEvent $event, RemoveReaction $action, ReactionAggregates $reactions): JsonResponse
    {
        return $this->remove($request, $event, $event, $action, $reactions);
    }

    public function index(GroupEvent $event, Reactors $reactors): JsonResponse
    {
        abort_unless(GroupEventAccess::canViewEvent($event, $this->viewer()), 404);

        return response()->json(['groups' => $reactors($event)]);
    }

    public function storeComment(Request $request, GroupEventComment $comment, AddReaction $action, ReactionAggregates $reactions): JsonResponse
    {
        return $this->add($request, $comment->event, $comment, $action, $reactions);
    }

    public function deleteComment(Request $request, GroupEventComment $comment, RemoveReaction $action, ReactionAggregates $reactions): JsonResponse
    {
        return $this->remove($request, $comment->event, $comment, $action, $reactions);
    }

    public function indexComment(GroupEventComment $comment, Reactors $reactors): JsonResponse
    {
        abort_unless(GroupEventAccess::canViewEvent($comment->event, $this->viewer()), 404);

        return response()->json(['groups' => $reactors($comment)]);
    }

    private function add(Request $request, GroupEvent $event, GroupEvent|GroupEventComment $reactable, AddReaction $action, ReactionAggregates $reactions): JsonResponse
    {
        abort_unless(GroupEventAccess::canComment($event, $this->viewer()), 404);
        $emoji = (string) $request->validate((new StoreReactionRequest)->rules())['emoji'];

        return $this->answer(fn () => $action($this->viewer(), $reactable, $emoji, new BoardReactionSurface), $reactable, $reactions);
    }

    private function remove(Request $request, GroupEvent $event, GroupEvent|GroupEventComment $reactable, RemoveReaction $action, ReactionAggregates $reactions): JsonResponse
    {
        abort_unless(GroupEventAccess::canComment($event, $this->viewer()), 404);
        $emoji = (string) $request->validate(StoreReactionRequest::removeRules())['emoji'];

        return $this->answer(fn () => $action($this->viewer(), $reactable, $emoji, new BoardReactionSurface), $reactable, $reactions);
    }

    private function answer(callable $write, GroupEvent|GroupEventComment $reactable, ReactionAggregates $reactions): JsonResponse
    {
        try {
            $write();
        } catch (ReactionRefused) {
            abort(404);
        }

        return response()->json(['reactions' => $reactions->of($this->viewer(), $reactable)]);
    }
}
