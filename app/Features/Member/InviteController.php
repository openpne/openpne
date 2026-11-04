<?php

namespace App\Features\Member;

use App\Compat\RouteParityRegistry;
use App\Features\Auth\Actions\IssueRegistrationToken;
use App\Features\Auth\Actions\IssueResult;
use App\Features\Auth\MemberCapacity;
use App\Features\Auth\RegistrationTokenSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\InviteRequest;
use App\Support\SurfaceResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * The mode gate is EnsureMemberInviteAllowed, not this controller. Telling an authenticated inviter
 * that the address is taken is not the enumeration leak the anonymous entry must avoid.
 */
class InviteController extends Controller
{
    public function __construct(private readonly MemberCapacity $capacity) {}

    public function show(Request $request): View|InertiaResponse
    {
        $paused = $this->capacity->isFull();

        if (SurfaceResolver::resolve($request, 'member') === SurfaceResolver::CLASSIC) {
            return view('member.invite', ['paused' => $paused])
                ->with('pageId', RouteParityRegistry::bodyId('member.invite'));
        }

        return Inertia::render('member/invite', ['paused' => $paused]);
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

        $status = match ($result) {
            IssueResult::AlreadyMember => __('That address already has an account, so no invitation was sent.'),
            IssueResult::Issued => __('An invitation has been sent to :email.', ['email' => $email]),
            IssueResult::CapacityFull => null, // the screen itself now says why
        };

        return redirect()->route('member.invite')->with('status', $status);
    }
}
