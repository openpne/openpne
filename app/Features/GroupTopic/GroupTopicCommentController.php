<?php

namespace App\Features\GroupTopic;

use App\Compat\RouteParityRegistry;
use App\Features\GroupTopic\Actions\CreateTopicComment;
use App\Features\GroupTopic\Actions\DeleteTopicComment;
use App\Features\GroupTopic\Exceptions\GroupTopicActionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\GroupTopic\StoreTopicCommentRequest;
use App\Models\GroupTopic;
use App\Models\GroupTopicComment;
use App\Support\SurfaceResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** A refusal from GroupTopicAccess is mapped to 404, so a topic's existence is not disclosed. */
class GroupTopicCommentController extends Controller
{
    public function store(StoreTopicCommentRequest $request, int $topic, CreateTopicComment $action): RedirectResponse
    {
        $found = GroupTopic::findOrFail($topic);

        try {
            $action($this->viewer(), $found, $request->validated('body'), $request->file('images', []));
        } catch (GroupTopicActionException) {
            abort(404);
        }

        return $this->redirectToTopic($request, $found)->with('status', __('Comment posted.'));
    }

    public function showDelete(Request $request, GroupTopicComment $comment): View|RedirectResponse
    {
        abort_unless(GroupTopicAccess::canDeleteComment($comment, $this->viewer()), 404);

        if (SurfaceResolver::resolve($request, 'group') === SurfaceResolver::MODERN) {
            return redirect()->route('group.topics.show', $comment->topic);
        }

        $this->markLocalNavGroup($comment->topic->group);

        return view('group-topic.comment-delete', [
            'comment' => $comment,
            'pageId' => RouteParityRegistry::bodyId('group.topics.comment.delete.show'),
        ]);
    }

    public function delete(Request $request, GroupTopicComment $comment, DeleteTopicComment $action): RedirectResponse
    {
        $topic = $comment->topic;

        try {
            $action($this->viewer(), $comment);
        } catch (GroupTopicActionException) {
            abort(404);
        }

        return $this->redirectToTopic($request, $topic)->with('status', __('The comment was deleted.'));
    }

    /** Both surfaces key off {topic}, so $request selects nothing here. */
    private function redirectToTopic(Request $request, GroupTopic $topic): RedirectResponse
    {
        return redirect()->route('group.topics.show', $topic);
    }
}
