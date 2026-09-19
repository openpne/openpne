<?php

namespace App\Features\GroupTopic;

use App\Features\Group\BoardReactionSurface;
use App\Features\Reactions\Actions\AddReaction;
use App\Features\Reactions\Actions\RemoveReaction;
use App\Features\Reactions\Exceptions\ReactionRefused;
use App\Features\Reactions\Queries\ReactionAggregates;
use App\Features\Reactions\Queries\Reactors;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reactions\StoreReactionRequest;
use App\Models\GroupTopic;
use App\Models\GroupTopicComment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reacting takes the board's write permission, as commenting does; the reactor list its read permission.
 * The gate runs before the emoji is validated, so an invalid payload gets the same 404 as a valid one.
 */
class GroupTopicReactionController extends Controller
{
    public function store(Request $request, GroupTopic $topic, AddReaction $action, ReactionAggregates $reactions): JsonResponse
    {
        return $this->add($request, $topic, $topic, $action, $reactions);
    }

    public function delete(Request $request, GroupTopic $topic, RemoveReaction $action, ReactionAggregates $reactions): JsonResponse
    {
        return $this->remove($request, $topic, $topic, $action, $reactions);
    }

    public function index(GroupTopic $topic, Reactors $reactors): JsonResponse
    {
        abort_unless(GroupTopicAccess::canViewTopic($topic, $this->viewer()), 404);

        return response()->json(['groups' => $reactors($topic)]);
    }

    public function storeComment(Request $request, GroupTopicComment $comment, AddReaction $action, ReactionAggregates $reactions): JsonResponse
    {
        return $this->add($request, $comment->topic, $comment, $action, $reactions);
    }

    public function deleteComment(Request $request, GroupTopicComment $comment, RemoveReaction $action, ReactionAggregates $reactions): JsonResponse
    {
        return $this->remove($request, $comment->topic, $comment, $action, $reactions);
    }

    public function indexComment(GroupTopicComment $comment, Reactors $reactors): JsonResponse
    {
        abort_unless(GroupTopicAccess::canViewTopic($comment->topic, $this->viewer()), 404);

        return response()->json(['groups' => $reactors($comment)]);
    }

    private function add(Request $request, GroupTopic $topic, GroupTopic|GroupTopicComment $reactable, AddReaction $action, ReactionAggregates $reactions): JsonResponse
    {
        abort_unless(GroupTopicAccess::canComment($topic, $this->viewer()), 404);
        $emoji = (string) $request->validate((new StoreReactionRequest)->rules())['emoji'];

        return $this->answer(fn () => $action($this->viewer(), $reactable, $emoji, new BoardReactionSurface), $reactable, $reactions);
    }

    private function remove(Request $request, GroupTopic $topic, GroupTopic|GroupTopicComment $reactable, RemoveReaction $action, ReactionAggregates $reactions): JsonResponse
    {
        abort_unless(GroupTopicAccess::canComment($topic, $this->viewer()), 404);
        $emoji = (string) $request->validate(StoreReactionRequest::removeRules())['emoji'];

        return $this->answer(fn () => $action($this->viewer(), $reactable, $emoji, new BoardReactionSurface), $reactable, $reactions);
    }

    private function answer(callable $write, GroupTopic|GroupTopicComment $reactable, ReactionAggregates $reactions): JsonResponse
    {
        try {
            $write();
        } catch (ReactionRefused) {
            abort(404);
        }

        return response()->json(['reactions' => $reactions->of($this->viewer(), $reactable)]);
    }
}
