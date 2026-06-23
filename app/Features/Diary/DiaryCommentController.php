<?php

namespace App\Features\Diary;

use App\Compat\RouteParityRegistry;
use App\Features\Diary\Actions\CreateComment;
use App\Features\Diary\Actions\DeleteComment;
use App\Features\Diary\Exceptions\DiaryActionException;
use App\Features\Diary\Queries\ShowDiary;
use App\Features\Diary\Serializers\DiarySerializer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Diary\StoreCommentRequest;
use App\Models\DiaryComment;
use App\Models\Member;
use App\Support\SurfaceResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class DiaryCommentController extends Controller
{
    public function store(StoreCommentRequest $request, int $diary, ShowDiary $query, CreateComment $action): RedirectResponse
    {
        $viewer = $this->viewer();

        // Commenting requires viewing the diary, so reuse the visibility/block gate.
        $found = $query($viewer, $diary);
        abort_if($found === null, 404);

        $action($viewer, $found, $request->validated('body'), $request->file('images', []));

        return redirect()
            ->route(SurfaceResolver::redirectName($request, 'diary.show'), $found)
            ->with('status', __('Comment posted.'));
    }

    public function showDelete(Request $request, DiaryComment $comment): View|InertiaResponse
    {
        $viewer = $this->viewer();
        abort_unless($comment->isDeletableBy($viewer), 404);

        if (SurfaceResolver::resolve($request, 'diary') === SurfaceResolver::MODERN) {
            return Inertia::render('diary/comment/delete', [
                'comment' => DiarySerializer::comment($comment, $viewer),
                'diaryId' => $comment->diary_id,
            ]);
        }

        return view('diary.comment.delete', [
            'comment' => $comment,
            'pageId' => RouteParityRegistry::bodyId('diary.comment.delete.show'),
        ]);
    }

    public function delete(Request $request, DiaryComment $comment, DeleteComment $action): RedirectResponse
    {
        $diary = $comment->diary;

        try {
            $action($this->viewer(), $comment);
        } catch (DiaryActionException) {
            abort(404);
        }

        return redirect()
            ->route(SurfaceResolver::redirectName($request, 'diary.show'), $diary)
            ->with('status', __('The comment was deleted.'));
    }

    private function viewer(): Member
    {
        $viewer = auth()->user();
        assert($viewer instanceof Member);

        return $viewer;
    }
}
