<?php

namespace App\Features\Member;

use App\Compat\RouteParityRegistry;
use App\Features\Auth\Actions\IssueRegistrationToken;
use App\Features\Auth\Actions\IssueResult;
use App\Features\Auth\RegistrationTokenSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\InviteRequest;
use App\Models\Member;
use App\Support\SurfaceResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Member invitation (OpenPNE 3 member/invite). A logged-in member enters an address; this issues a
 * member-invite registration token (recording the inviter for the auto-friend on completion) and
 * mails the link. The mode gate lives in EnsureMemberInviteAllowed; the inviter is told whether the
 * address was already taken, which is fine — the caller is authenticated, so this is not the
 * enumeration leak that the anonymous self-service entry must avoid.
 */
class InviteController extends Controller
{
    public function show(Request $request): View|InertiaResponse
    {
        if (SurfaceResolver::resolve($request, 'member') === SurfaceResolver::CLASSIC) {
            return view('member.invite')
                ->with('pageId', RouteParityRegistry::bodyId('member.invite'));
        }

        return Inertia::render('member/invite');
    }

    public function submit(InviteRequest $request, IssueRegistrationToken $issue): RedirectResponse
    {
        $email = $request->validated('email');

        $result = $issue(
            $email,
            RegistrationTokenSource::MemberInvite,
            $this->viewer(),
            $request->validated('message'),
        );

        $status = $result === IssueResult::AlreadyMember
            ? __('That address already has an account, so no invitation was sent.')
            : __('An invitation has been sent to :email.', ['email' => $email]);

        return redirect()->route('member.invite')->with('status', $status);
    }

    private function viewer(): Member
    {
        $viewer = auth()->user();
        assert($viewer instanceof Member);

        return $viewer;
    }
}
