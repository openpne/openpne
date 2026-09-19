<?php

namespace App\Features\GroupTopic;

use App\Features\Group\BoardCommentReactionSurface;
use App\Features\Reactions\Actions\AddReaction;
use App\Features\Reactions\Actions\RemoveReaction;
use App\Features\Reactions\Exceptions\ReactionRefused;
use App\Features\Reactions\Queries\ReactionAggregates;
use App\Features\Reactions\Queries\Reactors;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reactions\StoreReactionRequest;
use App\Models\GroupTopicComment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Reacting takes the board's write permission, as commenting does; the reactor list its read permission. */
class GroupTopicReactionController extends Controller
{
    public function store(Request $request, GroupTopicComment $comment, AddReaction $action, ReactionAggregates $reactions): JsonResponse
    {
        return $this->write($request, $comment, $action, (new StoreReactionRequest)->rules(), $reactions);
    }

    public function delete(Request $request, GroupTopicComment $comment, RemoveReaction $action, ReactionAggregates $reactions): JsonResponse
    {
        return $this->write($request, $comment, $action, StoreReactionRequest::removeRules(), $reactions);
    }

    public function index(GroupTopicComment $comment, Reactors $reactors): JsonResponse
    {
        abort_unless(GroupTopicAccess::canViewTopic($comment->topic, $this->viewer()), 404);

        return response()->json(['groups' => $reactors($comment)]);
    }

    /** @param  array<string, mixed>  $rules  validated only after the gate, so a refused request reads the same for any payload */
    private function write(Request $request, GroupTopicComment $comment, AddReaction|RemoveReaction $action, array $rules, ReactionAggregates $reactions): JsonResponse
    {
        abort_unless(GroupTopicAccess::canComment($comment->topic, $this->viewer()), 404);
        $emoji = (string) $request->validate($rules)['emoji'];

        try {
            $action($this->viewer(), $comment, $emoji, new BoardCommentReactionSurface);
        } catch (ReactionRefused) {
            abort(404);
        }

        return response()->json(['reactions' => $reactions->of($this->viewer(), $comment)]);
    }
}
