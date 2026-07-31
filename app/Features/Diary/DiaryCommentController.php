<?php

namespace App\Features\Diary;

use App\Compat\RouteParityRegistry;
use App\Features\Diary\Actions\CreateComment;
use App\Features\Diary\Actions\DeleteComment;
use App\Features\Diary\Exceptions\DiaryActionException;
use App\Features\Diary\Queries\DiaryCommentHistory;
use App\Features\Diary\Queries\ShowDiary;
use App\Http\Controllers\Controller;
use App\Http\Requests\Diary\StoreCommentRequest;
use App\Models\DiaryComment;
use App\Support\SurfaceResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

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
            ->route('diary.show', $found)
            ->with('status', __('Comment posted.'));
    }

    /**
     * OpenPNE 3 diaryComment/history: the diaries the viewer commented on, ordered by their last
     * comment. Modern has no twin — its nearest existing destination is the notification feed,
     * whose Reply/Related rows serve the "something moved in a thread I joined" need — so a
     * Modern viewer is sent there.
     */
    public function history(Request $request, DiaryCommentHistory $query): View|RedirectResponse
    {
        if (SurfaceResolver::resolve($request, 'diary') === SurfaceResolver::MODERN) {
            return redirect()->route('notifications.index');
        }

        return view('diary.comment.history', [
            'diaries' => $query->paginate($this->viewer()),
            'pageId' => RouteParityRegistry::bodyId('diary.comment.history'),
        ]);
    }

    public function showDelete(Request $request, DiaryComment $comment): View|RedirectResponse
    {
        $viewer = $this->viewer();
        abort_unless($comment->isDeletableBy($viewer), 404);

        // Modern confirms delete inline (Radix AlertDialog) — send a Modern viewer back to the diary.
        if (SurfaceResolver::resolve($request, 'diary') === SurfaceResolver::MODERN) {
            return redirect()->route('diary.show', $comment->diary);
        }

        // The confirm keeps the diary owner's context, as the diary pages it sits between do —
        // OpenPNE 3 rendered it with the friend localNav, not the default set.
        $this->markLocalNavSubject($comment->diary->member);

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
            ->route('diary.show', $diary)
            ->with('status', __('The comment was deleted.'));
    }
}
