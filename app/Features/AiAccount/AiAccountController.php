<?php

declare(strict_types=1);

namespace App\Features\AiAccount;

use App\Features\AiAccount\Actions\CreateAiAccount;
use App\Features\AiAccount\Actions\DeleteAiAccount;
use App\Features\AiAccount\Exceptions\AiAccountActionException;
use App\Features\AiAccount\Exceptions\AiAccountActionFailure;
use App\Features\AiAccount\Serializers\AiAccountSerializer;
use App\Features\Group\Actions\CancelGroupJoinRequest;
use App\Features\Group\Actions\JoinGroup;
use App\Features\Group\Actions\QuitGroup;
use App\Features\Group\Exceptions\GroupActionException;
use App\Features\Group\Exceptions\GroupActionFailure;
use App\Features\Group\JoinPolicy;
use App\Features\Group\Queries\ListMemberGroups;
use App\Features\Group\Queries\SearchGroups;
use App\Features\Group\Serializers\GroupSerializer;
use App\Features\Member\MemberConfigCategory;
use App\Features\Member\Serializers\MemberRefSerializer;
use App\Http\Controllers\Concerns\RespondsWithSurface;
use App\Http\Controllers\Controller;
use App\Http\Requests\AiAccount\CreateAiAccountRequest;
use App\Http\Requests\AiAccount\DeleteAiAccountRequest;
use App\Models\Group;
use App\Models\Member;
use App\Support\Feature;
use App\Support\SurfaceResolver;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * A member's own AI accounts: the list they may create into, and one account's page — its groups
 * and its delete button.
 *
 * Only creation asks the site setting. Everything else here answers to ownership alone, so an
 * operator who switches AI accounts off closes the door without stranding what is behind it: the
 * owner can still take an account out of every group and delete it.
 */
class AiAccountController extends Controller
{
    use RespondsWithSurface;

    public function __construct(private readonly AiAccountSettings $settings) {}

    /**
     * Modern-only, like the email/password/two-factor detail pages: Classic reaches the same list
     * through /member/config?category=ai, which MemberConfigController renders.
     */
    public function index(): InertiaResponse
    {
        $viewer = $this->viewer();

        return Inertia::render('member/config/ai/index', AiAccountSerializer::list($viewer, $this->settings));
    }

    /**
     * One account's management page. Dual-surface: unlike the other config detail pages this is the
     * only way to give an account's group seats up, so Classic gets it too rather than being left
     * with a list it cannot act on.
     */
    public function show(Request $request, Member $member, ListMemberGroups $joinedGroups, SearchGroups $searchGroups): View|InertiaResponse
    {
        Gate::authorize('manageAiAccount', $member);

        // Not a cast: `?keyword[]=` hands us an array, and casting one is a fatal, not a search.
        $raw = $request->query('keyword');
        $keyword = is_string($raw) ? $raw : '';
        // The group panels are the group unit's, so they go with it: a switched-off unit leaves the
        // page as identity plus delete, and the POSTs behind them 404 on their own gate.
        $groupsOn = Feature::Group->enabled();
        /** @var EloquentCollection<int, Group> $joined */
        $joined = $groupsOn ? $joinedGroups->all($member) : new EloquentCollection;
        /** @var EloquentCollection<int, Group> $pending */
        $pending = $groupsOn
            ? $member->groupJoinRequests()->with(['category', 'image'])->withCount('members')->orderByDesc('groups.id')->get()
            : new EloquentCollection;
        $browse = $groupsOn ? $searchGroups($keyword) : null;

        return $this->respondWith($request, 'member', [
            SurfaceResolver::CLASSIC => fn () => view('member.ai-account', [
                'aiAccount' => $member,
                'groupsOn' => $groupsOn,
                'joined' => $joined,
                'pending' => $pending,
                'browse' => $browse,
                'keyword' => $keyword,
                'joinedIds' => $joined->modelKeys(),
                'pendingIds' => $pending->modelKeys(),
            ]),
            SurfaceResolver::MODERN => function () use ($member, $groupsOn, $joined, $pending, $browse, $keyword) {
                $props = ['account' => MemberRefSerializer::ref($member)];

                if ($groupsOn) {
                    $props['groups'] = [
                        'joined' => array_map([GroupSerializer::class, 'summary'], $joined->all()),
                        'pending' => array_map([GroupSerializer::class, 'summary'], $pending->all()),
                        // Every group, not only the joinable ones: the client marks a row it is
                        // already in or waiting on, so the browse list stays the whole catalogue
                        // and its pager keeps telling the truth about how big that is.
                        'browse' => GroupSerializer::detailPaginator($browse),
                        'joinedIds' => $joined->modelKeys(),
                        'pendingIds' => $pending->modelKeys(),
                        'keyword' => $keyword,
                    ];
                }

                return Inertia::render('member/config/ai/show', $props);
            },
        ]);
    }

    public function store(CreateAiAccountRequest $request, CreateAiAccount $create): RedirectResponse
    {
        try {
            $aiAccount = $create($this->viewer(), (string) $request->validated('name'));
        } catch (AiAccountActionException $e) {
            return $this->listRedirect($request)->with('error', $this->messageFor($e->reason));
        }

        return redirect()->route('member.config.ai.show', ['member' => $aiAccount->getKey()])
            ->with('status', __('AI account created.'));
    }

    /**
     * Re-authenticated by DeleteAiAccountRequest, behind the route's ownership gate — which is where
     * the 404 for someone else's account has to come from, ahead of the password check.
     */
    public function destroy(DeleteAiAccountRequest $request, Member $member, DeleteAiAccount $delete): RedirectResponse
    {
        Gate::authorize('manageAiAccount', $member);

        try {
            $delete($this->viewer(), $member);
        } catch (AiAccountActionException $e) {
            return $this->listRedirect($request)->with('error', $this->messageFor($e->reason));
        }

        return $this->listRedirect($request)->with('status', __('AI account deleted.'));
    }

    public function joinGroup(Request $request, Member $member, Group $group, JoinGroup $join): RedirectResponse
    {
        return $this->groupAction($request, $member, fn () => $join($member, $group), $group->register_policy === JoinPolicy::Approval
            ? __('Join request sent for this AI account.')
            : __('This AI account has joined the %community%.'));
    }

    public function quitGroup(Request $request, Member $member, Group $group, QuitGroup $quit): RedirectResponse
    {
        return $this->groupAction($request, $member, fn () => $quit($member, $group), __('This AI account has left the %community%.'));
    }

    public function cancelGroupRequest(Request $request, Member $member, Group $group, CancelGroupJoinRequest $cancel): RedirectResponse
    {
        return $this->groupAction($request, $member, fn () => $cancel($member, $group), __('Join request cancelled.'));
    }

    /**
     * The three group POSTs differ only in which action runs and what to say afterwards. Each lands
     * back on the account's page, whose panels are the record of what just happened.
     */
    private function groupAction(Request $request, Member $member, callable $action, string $status): RedirectResponse
    {
        Gate::authorize('manageAiAccount', $member);

        $back = redirect()->route('member.config.ai.show', ['member' => $member->getKey()]);

        try {
            $action();
        } catch (GroupActionException $e) {
            return $back->with('error', $this->groupMessageFor($e->reason));
        }

        return $back->with('status', $status);
    }

    /**
     * The list every create/delete lands back on, which is a different page per surface: Modern has
     * its own, Classic reads the same list inside the member-config category page.
     */
    private function listRedirect(Request $request): RedirectResponse
    {
        return SurfaceResolver::resolve($request, 'member') === SurfaceResolver::CLASSIC
            ? redirect()->route('member.config', ['category' => MemberConfigCategory::Ai->value])
            : redirect()->route('member.config.ai');
    }

    private function messageFor(AiAccountActionFailure $reason): string
    {
        return match ($reason) {
            AiAccountActionFailure::Disabled => __('This site is not offering AI accounts right now.'),
            AiAccountActionFailure::OwnerFrozen, AiAccountActionFailure::OwnerIsAiAccount => __('You cannot create an AI account.'),
            AiAccountActionFailure::LimitReached => __('You already have as many AI accounts as this site allows.'),
            AiAccountActionFailure::NotOwned => __('That is not one of your AI accounts.'),
        };
    }

    private function groupMessageFor(GroupActionFailure $reason): string
    {
        return match ($reason) {
            GroupActionFailure::AlreadyMember => __('This AI account is already in that %community%.'),
            // Reachable without a visible pending panel: a policy flipped to Anyone-can-join leaves
            // the old request standing, and joining is refused until it is cancelled.
            GroupActionFailure::AlreadyRequested => __('This AI account has already applied to that %community%. Cancel the request first.'),
            GroupActionFailure::NotMember => __('This AI account is not in that %community%.'),
            GroupActionFailure::NotPending => __('No pending request found.'),
            GroupActionFailure::AdminCannotQuit => __('This AI account administers that %community%. Transfer the role before leaving.'),
            default => __('That could not be done.'),
        };
    }
}
