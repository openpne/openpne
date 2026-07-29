<?php

namespace App\Features\Friend;

use App\Compat\RouteParityRegistry;
use App\Features\Block\BlockLookup;
use App\Features\Friend\Actions\AcceptFriendRequest;
use App\Features\Friend\Actions\RejectFriendRequest;
use App\Features\Friend\Actions\SendFriendRequest;
use App\Features\Friend\Actions\Unfriend;
use App\Features\Friend\Exceptions\FriendActionException;
use App\Features\Friend\Exceptions\FriendActionFailure;
use App\Features\Friend\Queries\ListFriends;
use App\Features\Friend\Queries\ListPendingRequests;
use App\Features\Friend\Queries\PendingRequestDirection;
use App\Features\Friend\Serializers\FriendSerializer;
use App\Http\Controllers\Concerns\RespondsWithSurface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Friend\AcceptRequest;
use App\Http\Requests\Friend\LinkRequest;
use App\Http\Requests\Friend\RejectRequest;
use App\Models\Member;
use App\Support\SurfaceResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class FriendController extends Controller
{
    use RespondsWithSurface;

    private const SURFACE_CLASSIC = 'classic';

    private const SURFACE_MODERN = 'modern';

    public function list(Request $request, ListFriends $query): View|InertiaResponse
    {
        $viewer = $this->viewer();
        $owner = $this->memberSubject($request->has('id')
            ? Member::findOrFail((int) $request->query('id'))
            : null);
        $friends = $query($viewer, $owner);

        return $this->respondWith($request, 'friend', [
            self::SURFACE_CLASSIC => fn () => view('friend.list', [
                'owner' => $owner,
                'friends' => $friends,
            ]),
            self::SURFACE_MODERN => fn () => Inertia::render('friend/list', [
                'owner' => FriendSerializer::member($owner),
                'isOwner' => $viewer->is($owner),
                'friends' => FriendSerializer::paginator($friends),
            ]),
        ]);
    }

    /**
     * OpenPNE 3's friend/manage: the member's own roster with an unlink column. Modern folded
     * roster management into friend/list, so a Modern viewer is sent there — before the query,
     * which a redirect would only throw away.
     */
    public function manage(Request $request, ListFriends $query): View|RedirectResponse
    {
        if (SurfaceResolver::resolve($request, 'friend') === SurfaceResolver::MODERN) {
            return redirect()->route('friend.list');
        }

        $viewer = $this->viewer();

        return $this->classic('friend.manage', [
            'friends' => $query($viewer, $viewer),
        ]);
    }

    public function requests(Request $request, ListPendingRequests $query): View|InertiaResponse
    {
        $viewer = $this->viewer();
        $received = $query($viewer, PendingRequestDirection::Received, pageName: 'received_page');
        $sent = $query($viewer, PendingRequestDirection::Sent, pageName: 'sent_page');

        return $this->respondWith($request, 'friend', [
            self::SURFACE_CLASSIC => fn () => view('friend.requests', [
                'received' => $received,
                'sent' => $sent,
            ]),
            self::SURFACE_MODERN => fn () => Inertia::render('friend/requests', [
                'received' => FriendSerializer::paginator($received),
                'sent' => FriendSerializer::paginator($sent),
            ]),
        ]);
    }

    public function showLink(Request $request): View|InertiaResponse|RedirectResponse
    {
        $viewer = $this->viewer();
        $target = Member::findOrFail((int) $request->query('id'));

        if ($viewer->is($target) || BlockLookup::hasAnyBlockBetween($viewer, $target)) {
            abort(404);
        }
        $this->markLocalNavSubject($target); // the target's friend localNav
        if ($viewer->isFriendsWith($target)) {
            return redirect()->route('friend.list');
        }
        if ($target->hasPendingRequestFrom($viewer)) {
            return redirect()->route('friend.requests');
        }

        return $this->respondWith($request, 'friend', [
            self::SURFACE_CLASSIC => fn () => view('friend.link', [
                'target' => $target,
            ]),
            self::SURFACE_MODERN => fn () => Inertia::render('friend/link', [
                'target' => FriendSerializer::member($target),
            ]),
        ]);
    }

    public function submitLink(LinkRequest $request, SendFriendRequest $action): RedirectResponse
    {
        try {
            $action($this->viewer(), $request->target());
        } catch (FriendActionException $e) {
            return $this->redirectAfterSubmit('friend.list', error: $this->messageFor($e->reason));
        }

        return $this->redirectAfterSubmit('friend.list', status: __('%Friend% request sent.'));
    }

    public function submitAccept(AcceptRequest $request, AcceptFriendRequest $action): RedirectResponse
    {
        try {
            $action($this->viewer(), $request->requester());
        } catch (FriendActionException $e) {
            return $this->redirectAfterSubmit('friend.requests', error: $this->messageFor($e->reason));
        }

        return $this->redirectAfterSubmit('friend.list', status: __('%Friend% request accepted.'));
    }

    public function submitReject(RejectRequest $request, RejectFriendRequest $action): RedirectResponse
    {
        try {
            $action($this->viewer(), $request->requester());
        } catch (FriendActionException $e) {
            return $this->redirectAfterSubmit('friend.requests', error: $this->messageFor($e->reason));
        }

        return $this->redirectAfterSubmit('friend.requests', status: __('%Friend% request rejected.'));
    }

    public function showUnlink(Request $request, string $member): View|RedirectResponse
    {
        $target = $this->unlinkTarget($request, (int) $member);
        if ($target instanceof RedirectResponse) {
            return $target;
        }

        // Modern confirms unfriend inline (Radix AlertDialog) — send a Modern viewer to the profile.
        if (SurfaceResolver::resolve($request, 'friend') === SurfaceResolver::MODERN) {
            return redirect()->route('member.profile.show', $target);
        }

        $this->markLocalNavSubject($target); // the target's friend localNav

        return $this->classic('friend.unlink', ['target' => $target]);
    }

    public function submitUnlink(Request $request, string $member, Unfriend $action): RedirectResponse
    {
        $target = $this->unlinkTarget($request, (int) $member);
        if ($target instanceof RedirectResponse) {
            return $target;
        }

        try {
            $action($this->viewer(), $target);
        } catch (FriendActionException $e) {
            return $this->redirectAfterSubmit($this->unlinkReturnRoute($request), error: $this->messageFor($e->reason));
        }

        return $this->redirectAfterSubmit($this->unlinkReturnRoute($request), status: __('%Friend% removed.'));
    }

    /**
     * OpenPNE 3's executeUnlink gate: a missing or self id goes home, and a member who is not a
     * %friend% — including one who no longer exists — is told so where the roster lives. Nothing
     * here 404s: the answer to "you cannot unlink this" is a notice, not an error page.
     */
    private function unlinkTarget(Request $request, int $id): Member|RedirectResponse
    {
        $viewer = $this->viewer();
        if ($id === 0 || $viewer->getKey() === $id) {
            return redirect()->route('home');
        }

        $target = Member::find($id);
        if ($target === null || ! $viewer->isFriendsWith($target)) {
            return redirect()->route($this->unlinkReturnRoute($request))
                ->with('error', $this->messageFor(FriendActionFailure::NotFriends));
        }

        return $target;
    }

    /**
     * Where an unlink outcome lands: OpenPNE 3 always returned to friend/manage, which Classic
     * keeps; Modern's roster lives on friend/list, and routing it through manage would only add
     * a second redirect.
     */
    private function unlinkReturnRoute(Request $request): string
    {
        return SurfaceResolver::resolve($request, 'friend') === SurfaceResolver::MODERN
            ? 'friend.list'
            : 'friend.manage';
    }

    /** Render a Classic-only confirm view with the OpenPNE 3 page_{module}_{action} body id. */
    private function classic(string $view, array $data = []): View
    {
        return view($view, $data)->with('pageId', RouteParityRegistry::bodyId($this->routeName()));
    }

    private function routeName(): string
    {
        $route = request()->route();

        return $route !== null ? (string) $route->getName() : '';
    }

    private function messageFor(FriendActionFailure $reason): string
    {
        return FriendActionMessage::for($reason);
    }
}
