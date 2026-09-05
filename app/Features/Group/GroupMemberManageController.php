<?php

namespace App\Features\Group;

use App\Compat\RouteParityRegistry;
use App\Features\Group\Actions\AcceptAdminTransfer;
use App\Features\Group\Actions\AppointSubAdmin;
use App\Features\Group\Actions\DemoteSubAdmin;
use App\Features\Group\Actions\DropMember;
use App\Features\Group\Actions\RejectAdminTransfer;
use App\Features\Group\Actions\RequestAdminTransfer;
use App\Features\Group\Exceptions\GroupActionException;
use App\Features\Group\Exceptions\GroupActionFailure;
use App\Features\Group\Queries\ListGroupMembers;
use App\Features\Group\Serializers\GroupSerializer;
use App\Http\Controllers\Concerns\RespondsWithSurface;
use App\Http\Controllers\Controller;
use App\Models\Group;
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
 * A Modern GET confirm redirects to the roster only after the same state guards Classic runs, so a
 * crafted GET can never render a confirm for an invalid target.
 */
class GroupMemberManageController extends Controller
{
    use RespondsWithSurface;

    public function manage(Request $request, int $group, ListGroupMembers $query): View|InertiaResponse
    {
        $found = Group::findOrFail($group);
        abort_unless(Gate::allows('moderateMembers', $found), 404);
        $role = GroupMembership::roleOf($found, $this->viewer());
        $members = $query($found);

        return $this->respondWith($request, 'group', [
            SurfaceResolver::CLASSIC => function () use ($found, $members, $role) {
                $this->markLocalNavGroup($found);

                return view('group.manage', [
                    'group' => $found,
                    'members' => $members,
                    'viewerRole' => $role,
                    'pendingAdminId' => $found->pending_admin_member_id,
                ]);
            },
            SurfaceResolver::MODERN => fn () => Inertia::render('community/manage', [
                'group' => GroupSerializer::summary($found),
                'members' => GroupSerializer::memberPaginator($members),
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
            fn (Group $c, Member $t) => $this->targetRole($c, $t) === GroupRole::Member
                && (int) $c->pending_admin_member_id !== (int) $t->getKey(),
            title: __("Appoint this member as this %community%'s sub-administrator"),
            messageKey: 'Appoint :name as a sub-administrator of this %community%?',
            submitLabel: __("Appoint this member as this %community%'s sub-administrator"),
            actionRoute: 'group.members.appoint',
            boxKind: 'form',
            boxId: 'communitySubAdminRequest',
        );
    }

    public function appoint(Request $request, AppointSubAdmin $action): RedirectResponse
    {
        return $this->operate($request, 'manageMembers',
            fn (Group $c, Member $t) => $action($this->viewer(), $c, $t),
            __('Sub-administrator appointed.'));
    }

    public function showDemote(Request $request): View|RedirectResponse
    {
        return $this->confirm(
            $request,
            'manageMembers',
            fn (Group $c, Member $t) => $this->targetRole($c, $t) === GroupRole::SubAdmin,
            title: __("Demote this member from this %community%'s sub-administrator"),
            messageKey: "Demote :name from this %community%'s sub-administrator?",
            submitLabel: __('Yes'),
            actionRoute: 'group.members.demote',
            boxKind: 'yesNo',
            boxId: 'removeSubAdminConfirmForm',
        );
    }

    public function demote(Request $request, DemoteSubAdmin $action): RedirectResponse
    {
        return $this->operate($request, 'manageMembers',
            fn (Group $c, Member $t) => $action($this->viewer(), $c, $t),
            __('Sub-administrator demoted.'));
    }

    public function showDrop(Request $request): View|RedirectResponse
    {
        return $this->confirm(
            $request,
            'moderateMembers',
            fn (Group $c, Member $t) => $this->targetRole($c, $t) === GroupRole::Member,
            title: __('Drop this member'),
            messageKey: 'Drop :name from this %community%?',
            submitLabel: __('Yes'),
            actionRoute: 'group.members.drop',
            boxKind: 'yesNo',
            boxId: 'dropMemberConfirmForm',
        );
    }

    public function drop(Request $request, DropMember $action): RedirectResponse
    {
        return $this->operate($request, 'moderateMembers',
            fn (Group $c, Member $t) => $action($this->viewer(), $c, $t),
            __('Member dropped.'));
    }

    public function showTransfer(Request $request): View|RedirectResponse
    {
        return $this->confirm(
            $request,
            'manageMembers',
            // A sub-admin nominee is allowed (OpenPNE 3 parity); only the current admin and the
            // member already nominated are refused.
            fn (Group $c, Member $t) => ($role = $this->targetRole($c, $t)) !== null
                && $role !== GroupRole::Admin
                && (int) $c->pending_admin_member_id !== (int) $t->getKey(),
            title: __("Take over this %community%'s administrator to this member"),
            messageKey: "Ask :name to take over this %community%'s administration?",
            submitLabel: __("Take over this %community%'s administrator to this member"),
            actionRoute: 'group.members.transfer',
            boxKind: 'form',
            boxId: 'communityAdminRequest',
        );
    }

    public function transfer(Request $request, RequestAdminTransfer $action): RedirectResponse
    {
        return $this->operate($request, 'manageMembers',
            fn (Group $c, Member $t) => $action($this->viewer(), $c, $t),
            __('Admin transfer requested.'));
    }

    public function acceptTransfer(Request $request, AcceptAdminTransfer $action): RedirectResponse
    {
        return $this->respondToTransfer($request,
            fn (Group $c) => $action($this->viewer(), $c),
            __("You are now this %community%'s administrator."));
    }

    public function rejectTransfer(Request $request, RejectAdminTransfer $action): RedirectResponse
    {
        return $this->respondToTransfer($request,
            fn (Group $c) => $action($this->viewer(), $c),
            __('Admin transfer declined.'));
    }

    /**
     * No policy ability: being the nominee is state, not a role, so the action's NoTransferPending
     * check is authoritative.
     */
    private function respondToTransfer(Request $request, Closure $run, string $status): RedirectResponse
    {
        $group = $this->groupFrom($request);

        try {
            $run($group);
        } catch (GroupActionException $e) {
            return redirect()->route('group.show', $group)->with('error', $this->messageFor($e->reason));
        }

        return redirect()->route('group.show', $group)->with('status', $status);
    }

    /**
     * The target is state-guarded before anything renders, so an invalid confirm never is.
     * $boxKind / $boxId are the Classic parts kind and id of the OpenPNE 3 input page this confirm
     * replaces, which a site's skin targets.
     */
    private function confirm(Request $request, string $ability, Closure $targetOk, string $title, string $messageKey, string $submitLabel, string $actionRoute, string $boxKind, string $boxId): View|RedirectResponse
    {
        $group = $this->groupFrom($request);
        $target = Member::findOrFail($request->integer('member_id'));
        abort_unless(Gate::allows($ability, $group), 404);
        abort_unless($targetOk($group, $target), 404);

        if (SurfaceResolver::resolve($request, 'group') === SurfaceResolver::MODERN) {
            return $this->redirectToManage($group);
        }

        return $this->classic('group.member-action', [
            'group' => $group,
            'target' => $target,
            'title' => $title,
            'message' => __($messageKey, ['name' => $target->name]),
            'submitLabel' => $submitLabel,
            'actionUrl' => route($actionRoute, ['group' => $group]),
            'boxKind' => $boxKind,
            'boxId' => $boxId,
        ]);
    }

    private function operate(Request $request, string $ability, Closure $run, string $status): RedirectResponse
    {
        $group = $this->groupFrom($request);
        abort_unless(Gate::allows($ability, $group), 404);
        $target = Member::findOrFail($request->integer('member_id'));

        try {
            $run($group, $target);
        } catch (GroupActionException $e) {
            return $this->redirectToManage($group)->with('error', $this->messageFor($e->reason));
        }

        return $this->redirectToManage($group)->with('status', $status);
    }

    private function targetRole(Group $group, Member $target): ?GroupRole
    {
        return GroupMembership::roleOf($group, $target);
    }

    private function redirectToManage(Group $group): RedirectResponse
    {
        return redirect()->route('group.members.manage', $group);
    }

    /** The path {group}; the Classic forms still carry the same id in a hidden field. */
    private function groupFrom(Request $request): Group
    {
        $routeId = $request->route('group');

        return Group::findOrFail($routeId !== null ? (int) $routeId : $request->integer('id'));
    }

    private function classic(string $view, array $data): View
    {
        $this->markLocalNavGroup($data['group']);

        return view($view, $data)->with('pageId', RouteParityRegistry::bodyId($this->routeName()));
    }

    private function routeName(): string
    {
        $route = request()->route();

        return $route !== null ? (string) $route->getName() : '';
    }

    private function messageFor(GroupActionFailure $reason): string
    {
        return match ($reason) {
            GroupActionFailure::NotAdmin, GroupActionFailure::NotManager => __('You are not allowed to manage this %community%.'),
            GroupActionFailure::NotMember => __('That member is no longer in this %community%.'),
            GroupActionFailure::TargetNotPlainMember => __("That member's role has changed."),
            GroupActionFailure::NotSubAdmin => __('That member is not a sub-administrator.'),
            GroupActionFailure::TargetIsPendingAdmin => __('That member is awaiting an administrator handover.'),
            GroupActionFailure::TransferAlreadyRequested => __('An admin transfer to this member is already pending.'),
            GroupActionFailure::NoTransferPending => __('No admin transfer is pending for you.'),
            default => __('You are not allowed to manage this %community%.'),
        };
    }
}
