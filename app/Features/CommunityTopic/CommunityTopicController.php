<?php

namespace App\Features\CommunityTopic;

use App\Compat\RouteParityRegistry;
use App\Features\CommunityTopic\Actions\CreateTopic;
use App\Features\CommunityTopic\Actions\DeleteTopic;
use App\Features\CommunityTopic\Actions\UpdateTopic;
use App\Features\CommunityTopic\Exceptions\CommunityTopicActionException;
use App\Features\CommunityTopic\Queries\ListCommunityTopics;
use App\Features\CommunityTopic\Queries\ShowTopic;
use App\Http\Controllers\Controller;
use App\Http\Requests\CommunityTopic\StoreTopicRequest;
use App\Models\Community;
use App\Models\CommunityTopic;
use App\Models\Member;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Classic-only adapter for the community topic board (Modern is none, like the community core). The
 * board-level gates (view a community's board, post a topic) key on Community, so they call
 * CommunityTopicAccess directly; the topic-level gates (edit/delete) go through the auto-discovered
 * CommunityTopicPolicy via Gate. Every failure is a 404, hiding the topic's existence.
 */
class CommunityTopicController extends Controller
{
    public function index(Request $request, Community $community, ListCommunityTopics $query): View
    {
        $viewer = $this->viewer();
        abort_unless(CommunityTopicAccess::canViewBoard($community, $viewer), 404);

        return $this->classic('community-topic.index', [
            'community' => $community,
            'topics' => $query($community),
            'canPost' => CommunityTopicAccess::canPostTopic($community, $viewer),
        ]);
    }

    public function show(Request $request, int $topic, ShowTopic $query): View
    {
        $found = $query($topic);
        abort_if($found === null, 404);
        $viewer = $this->viewer();
        abort_unless(CommunityTopicAccess::canViewTopic($found, $viewer), 404);

        return $this->classic('community-topic.show', [
            'topic' => $found,
            'thread' => CommunityTopicCommentThread::paginate($found, $request->query('order'), $request->query('page')),
            'canComment' => CommunityTopicAccess::canComment($found, $viewer),
            'canEdit' => CommunityTopicAccess::canEditTopic($found, $viewer),
        ]);
    }

    public function new(Request $request, Community $community): View
    {
        abort_unless(CommunityTopicAccess::canPostTopic($community, $this->viewer()), 404);

        return $this->classic('community-topic.new', ['community' => $community]);
    }

    public function store(StoreTopicRequest $request, Community $community, CreateTopic $action): RedirectResponse
    {
        try {
            $topic = $action($this->viewer(), $community, $request->toData());
        } catch (CommunityTopicActionException) {
            abort(404);
        }

        return redirect()->route('communityTopic.show', $topic)->with('status', __('%Topic% posted.'));
    }

    public function edit(Request $request, CommunityTopic $topic): View
    {
        abort_unless(Gate::allows('update', $topic), 404);

        return $this->classic('community-topic.edit', ['topic' => $topic]);
    }

    public function update(StoreTopicRequest $request, CommunityTopic $topic, UpdateTopic $action): RedirectResponse
    {
        try {
            $action($this->viewer(), $topic, $request->toData());
        } catch (CommunityTopicActionException) {
            abort(404);
        }

        return redirect()->route('communityTopic.show', $topic)->with('status', __('%Topic% updated.'));
    }

    public function showDelete(Request $request, CommunityTopic $topic): View
    {
        abort_unless(Gate::allows('delete', $topic), 404);

        return $this->classic('community-topic.delete', ['topic' => $topic]);
    }

    public function delete(Request $request, CommunityTopic $topic, DeleteTopic $action): RedirectResponse
    {
        $community = $topic->community;

        try {
            $action($this->viewer(), $topic);
        } catch (CommunityTopicActionException) {
            abort(404);
        }

        // OpenPNE 3 returns to the community home after deleting a topic.
        return redirect()->route('community.show', $community)->with('status', __('%Topic% deleted.'));
    }

    /** Render a Classic view with the OpenPNE 3 page_{module}_{action} body id from the parity. */
    private function classic(string $view, array $data = []): View
    {
        return view($view, $data)->with('pageId', RouteParityRegistry::bodyId($this->routeName()));
    }

    private function routeName(): string
    {
        $route = request()->route();

        return $route !== null ? (string) $route->getName() : '';
    }

    private function viewer(): Member
    {
        $viewer = auth()->user();
        assert($viewer instanceof Member);

        return $viewer;
    }
}
