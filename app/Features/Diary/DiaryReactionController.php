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

/** A comment is gated at its diary, as commenting is. */
class DiaryReactionController extends Controller
{
    public function store(StoreReactionRequest $request, Diary $diary, AddReaction $action, ReactionAggregates $reactions): JsonResponse
    {
        return $this->write($diary, $diary, $action, $request->validated('emoji'), $reactions);
    }

    public function delete(Request $request, Diary $diary, RemoveReaction $action, ReactionAggregates $reactions): JsonResponse
    {
        return $this->write($diary, $diary, $action, $this->emoji($request), $reactions);
    }

    public function index(Diary $diary, Reactors $reactors): JsonResponse
    {
        $this->authorizeDiary($diary);

        return response()->json(['groups' => $reactors($diary)]);
    }

    public function storeComment(StoreReactionRequest $request, DiaryComment $comment, AddReaction $action, ReactionAggregates $reactions): JsonResponse
    {
        return $this->write($comment->diary, $comment, $action, $request->validated('emoji'), $reactions);
    }

    public function deleteComment(Request $request, DiaryComment $comment, RemoveReaction $action, ReactionAggregates $reactions): JsonResponse
    {
        return $this->write($comment->diary, $comment, $action, $this->emoji($request), $reactions);
    }

    public function indexComment(DiaryComment $comment, Reactors $reactors): JsonResponse
    {
        $this->authorizeDiary($comment->diary);

        return response()->json(['groups' => $reactors($comment)]);
    }

    private function write(Diary $diary, Diary|DiaryComment $reactable, AddReaction|RemoveReaction $action, string $emoji, ReactionAggregates $reactions): JsonResponse
    {
        $this->authorizeDiary($diary);

        try {
            $action($this->viewer(), $reactable, $emoji, new DiaryReactionSurface);
        } catch (ReactionRefused) {
            abort(404);
        }

        return response()->json(['reactions' => $reactions->of($this->viewer(), $reactable)]);
    }

    private function emoji(Request $request): string
    {
        return (string) $request->validate(['emoji' => ['required', 'string', 'max:32']])['emoji'];
    }

    private function authorizeDiary(Diary $diary): void
    {
        abort_unless(DiaryAccess::canView($this->viewer(), $diary), 404);
    }
}
