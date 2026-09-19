<?php

namespace App\Features\Diary;

use App\Features\Reactions\Actions\AddReaction;
use App\Features\Reactions\Actions\RemoveReaction;
use App\Features\Reactions\Exceptions\ReactionRefused;
use App\Features\Reactions\Queries\ReactionAggregates;
use App\Features\Reactions\Queries\Reactors;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reactions\StoreReactionRequest;
use App\Models\Diary;
use App\Models\DiaryComment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A comment is gated at its diary, as commenting is. The gate runs before the emoji is validated,
 * so an invalid payload gets the same 404 as a valid one and the diary id space is not an oracle.
 */
class DiaryReactionController extends Controller
{
    public function store(Request $request, Diary $diary, AddReaction $action, ReactionAggregates $reactions): JsonResponse
    {
        return $this->add($request, $diary, $diary, $action, $reactions);
    }

    public function delete(Request $request, Diary $diary, RemoveReaction $action, ReactionAggregates $reactions): JsonResponse
    {
        return $this->remove($request, $diary, $diary, $action, $reactions);
    }

    public function index(Diary $diary, Reactors $reactors): JsonResponse
    {
        $this->authorizeDiary($diary);

        return response()->json(['groups' => $reactors($diary)]);
    }

    public function storeComment(Request $request, DiaryComment $comment, AddReaction $action, ReactionAggregates $reactions): JsonResponse
    {
        return $this->add($request, $comment->diary, $comment, $action, $reactions);
    }

    public function deleteComment(Request $request, DiaryComment $comment, RemoveReaction $action, ReactionAggregates $reactions): JsonResponse
    {
        return $this->remove($request, $comment->diary, $comment, $action, $reactions);
    }

    public function indexComment(DiaryComment $comment, Reactors $reactors): JsonResponse
    {
        $this->authorizeDiary($comment->diary);

        return response()->json(['groups' => $reactors($comment)]);
    }

    private function add(Request $request, ?Diary $diary, Diary|DiaryComment $reactable, AddReaction $action, ReactionAggregates $reactions): JsonResponse
    {
        $this->authorizeDiary($diary);
        $emoji = (string) $request->validate((new StoreReactionRequest)->rules())['emoji'];

        return $this->answer(fn () => $action($this->viewer(), $reactable, $emoji, new DiaryReactionSurface), $reactable, $reactions);
    }

    private function remove(Request $request, ?Diary $diary, Diary|DiaryComment $reactable, RemoveReaction $action, ReactionAggregates $reactions): JsonResponse
    {
        $this->authorizeDiary($diary);
        $emoji = (string) $request->validate(StoreReactionRequest::removeRules())['emoji'];

        return $this->answer(fn () => $action($this->viewer(), $reactable, $emoji, new DiaryReactionSurface), $reactable, $reactions);
    }

    private function answer(callable $write, Diary|DiaryComment $reactable, ReactionAggregates $reactions): JsonResponse
    {
        try {
            $write();
        } catch (ReactionRefused) {
            abort(404);
        }

        return response()->json(['reactions' => $reactions->of($this->viewer(), $reactable)]);
    }

    /** Null is a comment whose diary went between the route binding and this read: gone, so not found. */
    private function authorizeDiary(?Diary $diary): void
    {
        abort_unless($diary !== null && DiaryAccess::canView($this->viewer(), $diary), 404);
    }
}
