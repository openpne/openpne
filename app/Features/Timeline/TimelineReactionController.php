<?php

namespace App\Features\Timeline;

use App\Features\Reactions\Actions\AddReaction;
use App\Features\Reactions\Actions\RemoveReaction;
use App\Features\Reactions\Exceptions\ReactionRefused;
use App\Features\Reactions\Queries\ReactionAggregates;
use App\Features\Reactions\Queries\Reactors;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reactions\StoreReactionRequest;
use App\Models\TimelinePost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reacting takes the thread's clearance, not the posting switch: the switch stops authoring, and
 * a site with it off still receives the automatic lines a reaction is the one answer to.
 */
class TimelineReactionController extends Controller
{
    public function store(StoreReactionRequest $request, TimelinePost $timelinePost, AddReaction $action, ReactionAggregates $reactions): JsonResponse
    {
        $this->authorizeThread($timelinePost);

        try {
            $action($this->viewer(), $timelinePost, $request->validated('emoji'), new TimelineReactionSurface);
        } catch (ReactionRefused) {
            abort(404);
        }

        return $this->state($timelinePost, $reactions);
    }

    public function delete(Request $request, TimelinePost $timelinePost, RemoveReaction $action, ReactionAggregates $reactions): JsonResponse
    {
        $this->authorizeThread($timelinePost);
        $emoji = (string) $request->validate(['emoji' => ['required', 'string', 'max:32']])['emoji'];

        try {
            $action($this->viewer(), $timelinePost, $emoji, new TimelineReactionSurface);
        } catch (ReactionRefused) {
            abort(404);
        }

        return $this->state($timelinePost, $reactions);
    }

    public function index(TimelinePost $timelinePost, Reactors $reactors): JsonResponse
    {
        $this->authorizeThread($timelinePost);

        return response()->json(['groups' => $reactors($timelinePost)]);
    }

    private function authorizeThread(TimelinePost $post): void
    {
        abort_unless(TimelineAccess::canViewThread($this->viewer(), $post), 404);
    }

    private function state(TimelinePost $post, ReactionAggregates $reactions): JsonResponse
    {
        return response()->json(['reactions' => $reactions->of($this->viewer(), $post)]);
    }
}
