<?php

namespace App\Features\CommunityTopic;

use App\Compat\RouteParityRegistry;
use App\Features\CommunityTopic\Actions\CreateTopicComment;
use App\Features\CommunityTopic\Actions\DeleteTopicComment;
use App\Features\CommunityTopic\Exceptions\CommunityTopicActionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CommunityTopic\StoreTopicCommentRequest;
use App\Models\CommunityTopic;
use App\Models\CommunityTopicComment;
use App\Models\Member;
use App\Support\SurfaceResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Topic comments, dual-surface for the write path. The action chokepoint (CommunityTopicAccess)
 * enforces who may comment and who may delete; the controller maps its refusals to 404 and redirects
 * back to the topic on the surface the request came from. showDelete stays a Classic-only GET confirm
 * page (page_communityTopicComment_*) — Modern confirms delete inline (Radix AlertDialog).
 */
class CommunityTopicCommentController extends Controller
{
    public function store(StoreTopicCommentRequest $request, int $topic, CreateTopicComment $action): RedirectResponse
    {
        $found = CommunityTopic::findOrFail($topic);

        try {
            $action($this->viewer(), $found, $request->validated('body'), $request->file('images', []));
        } catch (CommunityTopicActionException) {
            abort(404);
        }

        return $this->redirectToTopic($request, $found)->with('status', __('Comment posted.'));
    }

    public function showDelete(Request $request, CommunityTopicComment $comment): View
    {
        abort_unless(CommunityTopicAccess::canDeleteComment($comment, $this->viewer()), 404);

        return view('community-topic.comment-delete', [
            'comment' => $comment,
            'pageId' => RouteParityRegistry::bodyId('communityTopic.comment.delete.show'),
        ]);
    }

    public function delete(Request $request, CommunityTopicComment $comment, DeleteTopicComment $action): RedirectResponse
    {
        $topic = $comment->topic;

        try {
            $action($this->viewer(), $comment);
        } catch (CommunityTopicActionException) {
            abort(404);
        }

        return $this->redirectToTopic($request, $topic)->with('status', __('The comment was deleted.'));
    }

    /** Redirect to the topic show page on the surface the request came from (both key off {topic}). */
    private function redirectToTopic(Request $request, CommunityTopic $topic): RedirectResponse
    {
        return redirect()->route(SurfaceResolver::redirectName($request, 'communityTopic.show'), $topic);
    }

    private function viewer(): Member
    {
        $viewer = auth()->user();
        assert($viewer instanceof Member);

        return $viewer;
    }
}
