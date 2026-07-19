<?php

namespace App\Features\Community;

use App\Compat\RouteParityRegistry;
use App\Features\Community\Actions\AcceptAdminTransfer;
use App\Features\Community\Actions\AppointSubAdmin;
use App\Features\Community\Actions\DemoteSubAdmin;
use App\Features\Community\Actions\DropMember;
use App\Features\Community\Actions\RejectAdminTransfer;
use App\Features\Community\Actions\RequestAdminTransfer;
use App\Features\Community\Exceptions\CommunityActionException;
use App\Features\Community\Exceptions\CommunityActionFailure;
use App\Features\Community\Queries\ListCommunityMembers;
use App\Features\Community\Serializers\CommunitySerializer;
use App\Http\Controllers\Concerns\RespondsWithSurface;
use App\Http\Controllers\Controller;
use App\Models\Community;
use App\Models\Member;
use App\Support\SurfaceResolver;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * The community member-management screen and its immediate operations — appoint / demote a
 * sub-admin, drop a plain member — kept off CommunityController (already large; join/quit/pending
 * stay there). Dual-surface: Classic renders the roster and a shared GET confirm page per action;
 * Modern renders the roster and confirms inline, so a Modern GET confirm redirects to the roster
 * after the same state guards run — a crafted GET can never render a confirm for an invalid target.
 */
class CommunityMemberManageController extends Controller
{
    use RespondsWithSurface;

    public function manage(Request $request, int $community, ListCommunityMembers $query): View|InertiaResponse
    {
        $found = Community::findOrFail($community);
        abort_unless(Gate::allows('moderateMembers', $found), 404);
        $role = CommunityMembership::roleOf($found, $this->viewer());
        $members = $query($found);

        return $this->respondWith($request, 'community', [
            SurfaceResolver::CLASSIC => function () use ($found, $members, $role) {
                $this->markLocalNavCommunity($found);

                return view('community.manage', [
                    'community' => $found,
                    'members' => $members,
                    'viewerRole' => $role,
                    'pendingAdminId' => $found->pending_admin_member_id,
                ]);
            },
            SurfaceResolver::MODERN => fn () => Inertia::render('community/manage', [
                'community' => CommunitySerializer::summary($found),
                'members' => CommunitySerializer::memberPaginator($members),
                'viewerRole' => $role?->slug(),
                'pendingAdminId' => $found->pending_admin_member_id,
            ]),
        ]);
    }

    public function showAppoint(Request $request): View|RedirectResponse
    {
        return $this->confirm(
            $request,
            'manageMembers',
            fn (Community $c, Member $t) => $this->targetRole($c, $t) === CommunityRole::Member
                && (int) $c->pending_admin_member_id !== (int) $t->getKey(),
            title: __("Appoint this member as this %community%'s sub-administrator"),
            messageKey: 'Appoint :name as a sub-administrator of this %community%?',
            submitLabel: __("Appoint this member as this %community%'s sub-administrator"),
            actionRoute: 'community.members.appoint',
        );
    }

    public function appoint(Request $request, AppointSubAdmin $action): RedirectResponse
    {
        return $this->operate($request, 'manageMembers',
            fn (Community $c, Member $t) => $action($this->viewer(), $c, $t),
            __('Sub-administrator appointed.'));
    }

    public function showDemote(Request $request): View|RedirectResponse
    {
        return $this->confirm(
            $request,
            'manageMembers',
            fn (Community $c, Member $t) => $this->targetRole($c, $t) === CommunityRole::SubAdmin,
            title: __("Demote this member from this %community%'s sub-administrator"),
            messageKey: "Demote :name from this %community%'s sub-administrator?",
            submitLabel: __("Demote this member from this %community%'s sub-administrator"),
            actionRoute: 'community.members.demote',
        );
    }

    public function demote(Request $request, DemoteSubAdmin $action): RedirectResponse
    {
        return $this->operate($request, 'manageMembers',
            fn (Community $c, Member $t) => $action($this->viewer(), $c, $t),
            __('Sub-administrator demoted.'));
    }

    public function showDrop(Request $request): View|RedirectResponse
    {
        return $this->confirm(
            $request,
            'moderateMembers',
            fn (Community $c, Member $t) => $this->targetRole($c, $t) === CommunityRole::Member,
            title: __('Drop this member'),
            messageKey: 'Drop :name from this %community%?',
            submitLabel: __('Drop this member'),
            actionRoute: 'community.members.drop',
        );
    }

    public function drop(Request $request, DropMember $action): RedirectResponse
    {
        return $this->operate($request, 'moderateMembers',
            fn (Community $c, Member $t) => $action($this->viewer(), $c, $t),
            __('Member dropped.'));
    }

    public function showTransfer(Request $request): View|RedirectResponse
    {
        return $this->confirm(
            $request,
            'manageMembers',
            // A sub-admin nominee is allowed (OpenPNE 3 parity); only the current admin and the
            // member already nominated are refused.
            fn (Community $c, Member $t) => ($role = $this->targetRole($c, $t)) !== null
                && $role !== CommunityRole::Admin
                && (int) $c->pending_admin_member_id !== (int) $t->getKey(),
            title: __("Take over this %community%'s administrator to this member"),
            messageKey: "Ask :name to take over this %community%'s administration?",
            submitLabel: __("Take over this %community%'s administrator to this member"),
            actionRoute: 'community.members.transfer',
        );
    }

    public function transfer(Request $request, RequestAdminTransfer $action): RedirectResponse
    {
        return $this->operate($request, 'manageMembers',
            fn (Community $c, Member $t) => $action($this->viewer(), $c, $t),
            __('Admin transfer requested.'));
    }

    public function acceptTransfer(Request $request, AcceptAdminTransfer $action): RedirectResponse
    {
        return $this->respondToTransfer($request,
            fn (Community $c) => $action($this->viewer(), $c),
            __("You are now this %community%'s administrator."));
    }

    public function rejectTransfer(Request $request, RejectAdminTransfer $action): RedirectResponse
    {
        return $this->respondToTransfer($request,
            fn (Community $c) => $action($this->viewer(), $c),
            __('Admin transfer declined.'));
    }

    /**
     * The nominee's accept/reject: no policy ability — being the nominee is state, not role, so the
     * action's NoTransferPending check is authoritative. Resolve the community, run, redirect to the
     * community home (where the banner lives), surfacing a failure as an error flash.
     */
    private function respondToTransfer(Request $request, Closure $run, string $status): RedirectResponse
    {
        $community = $this->communityFrom($request);

        try {
            $run($community);
        } catch (CommunityActionException $e) {
            return redirect()->route('community.show', $community)->with('error', $this->messageFor($e->reason));
        }

        return redirect()->route('community.show', $community)->with('status', $status);
    }

    /**
     * Shared GET confirm: resolve community + target, gate the viewer, then state-guard the target
     * so an invalid confirm is never rendered. Modern confirms inline, so it redirects to the
     * roster once the guards pass (showJoin pattern); Classic renders the shared confirm blade.
     */
    private function confirm(Request $request, string $ability, Closure $targetOk, string $title, string $messageKey, string $submitLabel, string $actionRoute): View|RedirectResponse
    {
        $community = $this->communityFrom($request);
        $target = Member::findOrFail($request->integer('member_id'));
        abort_unless(Gate::allows($ability, $community), 404);
        abort_unless($targetOk($community, $target), 404);

        if (SurfaceResolver::resolve($request, 'community') === SurfaceResolver::MODERN) {
            return $this->redirectToManage($community);
        }

        return $this->classic('community.member-action', [
            'community' => $community,
            'target' => $target,
            'title' => $title,
            'message' => __($messageKey, ['name' => $target->name]),
            'submitLabel' => $submitLabel,
            'actionUrl' => route($actionRoute),
        ]);
    }

    /** Shared POST body: gate the viewer, resolve the target, run the action, redirect to the roster. */
    private function operate(Request $request, string $ability, Closure $run, string $status): RedirectResponse
    {
        $community = $this->communityFrom($request);
        abort_unless(Gate::allows($ability, $community), 404);
        $target = Member::findOrFail($request->integer('member_id'));

        try {
            $run($community, $target);
        } catch (CommunityActionException $e) {
            return $this->redirectToManage($community)->with('error', $this->messageFor($e->reason));
        }

        return $this->redirectToManage($community)->with('status', $status);
    }

    private function targetRole(Community $community, Member $target): ?CommunityRole
    {
        return CommunityMembership::roleOf($community, $target);
    }

    private function redirectToManage(Community $community): RedirectResponse
    {
        return redirect()->route('community.members.manage', $community);
    }

    /** community via ?id= / hidden id (the operation endpoints carry no path {community}). */
    private function communityFrom(Request $request): Community
    {
        return Community::findOrFail($request->integer('id'));
    }

    /** Render a Classic view with the OpenPNE 3 page_{module}_{action} body id and community localNav. */
    private function classic(string $view, array $data): View
    {
        $this->markLocalNavCommunity($data['community']);

        return view($view, $data)->with('pageId', RouteParityRegistry::bodyId($this->routeName()));
    }

    private function routeName(): string
    {
        $route = request()->route();

        return $route !== null ? (string) $route->getName() : '';
    }

    private function messageFor(CommunityActionFailure $reason): string
    {
        return match ($reason) {
            CommunityActionFailure::NotAdmin, CommunityActionFailure::NotManager => __('You are not allowed to manage this %community%.'),
            CommunityActionFailure::NotMember => __('That member is no longer in this %community%.'),
            CommunityActionFailure::TargetNotPlainMember => __("That member's role has changed."),
            CommunityActionFailure::NotSubAdmin => __('That member is not a sub-administrator.'),
            CommunityActionFailure::TargetIsPendingAdmin => __('That member is awaiting an administrator handover.'),
            CommunityActionFailure::TransferAlreadyRequested => __('An admin transfer to this member is already pending.'),
            CommunityActionFailure::NoTransferPending => __('No admin transfer is pending for you.'),
            default => __('You are not allowed to manage this %community%.'),
        };
    }
}
