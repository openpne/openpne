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
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Classic-only adapter for topic comments. The action chokepoint (CommunityTopicAccess) enforces
 * who may comment and who may delete; the controller maps its refusals to 404 and redirects back to
 * the topic. Confirm pages render under page_communityTopicComment_* (the op3Module override).
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

        return redirect()->route('communityTopic.show', $found)->with('status', __('Comment posted.'));
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

        return redirect()->route('communityTopic.show', $topic)->with('status', __('The comment was deleted.'));
    }

    private function viewer(): Member
    {
        $viewer = auth()->user();
        assert($viewer instanceof Member);

        return $viewer;
    }
}
