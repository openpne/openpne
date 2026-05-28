<?php

namespace App\Features\Friend\Classic;

use App\Features\Friend\Actions\AcceptFriendRequest;
use App\Features\Friend\Actions\RejectFriendRequest;
use App\Features\Friend\Actions\SendFriendRequest;
use App\Features\Friend\Actions\Unfriend;
use App\Features\Friend\Exceptions\FriendActionException;
use App\Features\Friend\Exceptions\FriendActionFailure;
use App\Features\Friend\Internal\BlockLookup;
use App\Features\Friend\Queries\ListFriends;
use App\Features\Friend\Queries\ListPendingRequests;
use App\Features\Friend\Queries\PendingRequestDirection;
use App\Http\Controllers\Controller;
use App\Http\Requests\Friend\AcceptRequest;
use App\Http\Requests\Friend\LinkRequest;
use App\Http\Requests\Friend\RejectRequest;
use App\Models\Member;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FriendController extends Controller
{
    public function list(Request $request, ListFriends $query): View
    {
        $viewer = $this->viewer();
        $owner = $request->has('id')
            ? Member::findOrFail((int) $request->query('id'))
            : $viewer;

        return view('friend.list', [
            'pageId' => 'page_friend_list',
            'owner' => $owner,
            'friends' => $query($viewer, $owner),
        ]);
    }

    public function manage(ListPendingRequests $query): View
    {
        $viewer = $this->viewer();

        return view('friend.manage', [
            'pageId' => 'page_friend_manage',
            'received' => $query($viewer, PendingRequestDirection::Received, pageName: 'received_page'),
            'sent' => $query($viewer, PendingRequestDirection::Sent, pageName: 'sent_page'),
        ]);
    }

    public function showLink(Request $request): View|RedirectResponse
    {
        $viewer = $this->viewer();
        $target = Member::findOrFail((int) $request->query('id'));

        if ($viewer->is($target) || BlockLookup::hasAnyBetween($viewer, $target)) {
            abort(404);
        }
        if ($viewer->isFriendsWith($target)) {
            return redirect()->route('friend.list');
        }
        if ($target->hasPendingRequestFrom($viewer)) {
            return redirect()->route('friend.manage');
        }

        return view('friend.link', [
            'pageId' => 'page_friend_link',
            'target' => $target,
        ]);
    }

    public function submitLink(LinkRequest $request, SendFriendRequest $action): RedirectResponse
    {
        try {
            $action($this->viewer(), $request->target());
        } catch (FriendActionException $e) {
            return redirect()->route('friend.list')->with('error', $this->messageFor($e->reason));
        }

        return redirect()->route('friend.list')->with('status', 'Friend request sent.');
    }

    public function submitAccept(AcceptRequest $request, AcceptFriendRequest $action): RedirectResponse
    {
        try {
            $action($this->viewer(), $request->requester());
        } catch (FriendActionException $e) {
            return redirect()->route('friend.manage')->with('error', $this->messageFor($e->reason));
        }

        return redirect()->route('friend.list')->with('status', 'Friend request accepted.');
    }

    public function submitReject(RejectRequest $request, RejectFriendRequest $action): RedirectResponse
    {
        try {
            $action($this->viewer(), $request->requester());
        } catch (FriendActionException $e) {
            return redirect()->route('friend.manage')->with('error', $this->messageFor($e->reason));
        }

        return redirect()->route('friend.manage')->with('status', 'Friend request rejected.');
    }

    public function showUnlink(Member $member): View
    {
        $viewer = $this->viewer();
        if ($viewer->is($member) || ! $viewer->isFriendsWith($member)) {
            abort(404);
        }

        return view('friend.unlink', [
            'pageId' => 'page_friend_unlink',
            'target' => $member,
        ]);
    }

    public function submitUnlink(Member $member, Unfriend $action): RedirectResponse
    {
        try {
            $action($this->viewer(), $member);
        } catch (FriendActionException $e) {
            return redirect()->route('friend.list')->with('error', $this->messageFor($e->reason));
        }

        return redirect()->route('friend.list')->with('status', 'Unfriended.');
    }

    private function viewer(): Member
    {
        $viewer = auth()->user();
        assert($viewer instanceof Member);

        return $viewer;
    }

    private function messageFor(FriendActionFailure $reason): string
    {
        return match ($reason) {
            FriendActionFailure::SelfFriendship => 'You cannot send a friend request to yourself.',
            FriendActionFailure::AlreadyFriends => 'You are already friends.',
            FriendActionFailure::DuplicateRequest => 'A pending request already exists.',
            FriendActionFailure::Blocked => 'This member is unavailable.',
            FriendActionFailure::RequestNotFound => 'No pending friend request found.',
            FriendActionFailure::NotFriends => 'You are not friends with this member.',
        };
    }
}
