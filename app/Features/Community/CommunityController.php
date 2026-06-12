<?php

namespace App\Features\Community;

use App\Compat\RouteParityRegistry;
use App\Features\Community\Actions\ApproveMember;
use App\Features\Community\Actions\CreateCommunity;
use App\Features\Community\Actions\DeclinePendingMember;
use App\Features\Community\Actions\DeleteCommunity;
use App\Features\Community\Actions\JoinCommunity;
use App\Features\Community\Actions\QuitCommunity;
use App\Features\Community\Actions\UpdateCommunity;
use App\Features\Community\Exceptions\CommunityActionException;
use App\Features\Community\Exceptions\CommunityActionFailure;
use App\Features\Community\Queries\ListCommunityMembers;
use App\Features\Community\Queries\ListMemberCommunities;
use App\Features\Community\Queries\ListPendingMembers;
use App\Features\Community\Queries\SearchCommunities;
use App\Features\Community\Queries\ShowCommunity;
use App\Features\CommunityEvent\CommunityEventAccess;
use App\Features\CommunityEvent\Queries\RecentCommunityEvents;
use App\Features\CommunityTopic\CommunityTopicAccess;
use App\Features\CommunityTopic\Queries\RecentCommunityTopics;
use App\Http\Controllers\Controller;
use App\Http\Requests\Community\CommunityRequest;
use App\Models\Community;
use App\Models\CommunityCategory;
use App\Models\Member;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Classic-only adapter for the community core. Modern is status `none` in Phase A — no /m/*
 * routes, no Inertia — so this renders Blade directly and injects the OpenPNE 3 body id, rather
 * than carrying the dual-surface respondWith() the Diary/Friend controllers use.
 */
class CommunityController extends Controller
{
    public function show(Request $request, int $community, ShowCommunity $query, RecentCommunityTopics $recentTopics, RecentCommunityEvents $recentEvents): View
    {
        $found = $query($community);
        abort_if($found === null, 404);
        $found->loadMissing('category');
        $viewer = $this->viewer();

        // The recent-topics / recent-events boxes (OpenPNE 3 community home) only show when the viewer
        // may read that board; the "post" link only when they may post. Events share the topic read
        // gate, so one canViewBoard check covers both.
        $canViewBoard = CommunityTopicAccess::canViewBoard($found, $viewer);

        return $this->classic('community.show', [
            'community' => $found,
            'role' => CommunityMembership::roleOf($found, $viewer),
            'isPending' => CommunityMembership::isPending($found, $viewer),
            'recentTopics' => $canViewBoard ? $recentTopics($found) : null,
            'canPostTopic' => CommunityTopicAccess::canPostTopic($found, $viewer),
            'recentEvents' => $canViewBoard ? $recentEvents($found) : null,
            'canPostEvent' => CommunityEventAccess::canPostEvent($found, $viewer),
        ]);
    }

    public function search(Request $request, SearchCommunities $query): View
    {
        // OpenPNE 3 query shape: community[name] / community[community_category_id], with a
        // search_query alias for the name (preserved so a bookmarked OpenPNE 3 search URL works).
        $params = $request->query('community');
        $params = is_array($params) ? $params : [];

        $keyword = $this->stringValue($params['name'] ?? null);
        if ($keyword === '' && $request->filled('search_query')) {
            $keyword = $this->stringValue($request->query('search_query'));
        }

        $categoryRaw = $params['community_category_id'] ?? null;
        $categoryId = is_numeric($categoryRaw) ? (int) $categoryRaw : null;

        return $this->classic('community.search', [
            'keyword' => $keyword,
            'categoryId' => $categoryId,
            // Search spans every category (OpenPNE 3 CommunityFormFilter), not just the
            // member-creatable set the create form offers.
            'categories' => $this->allCategories(),
            'communities' => $query($keyword, $categoryId),
        ]);
    }

    public function listMine(Request $request, ListMemberCommunities $query): View
    {
        $owner = $this->memberSubject($request->filled('id')
            ? Member::findOrFail($request->integer('id'))
            : null);

        return $this->classic('community.list', [
            'owner' => $owner,
            'communities' => $query($owner),
        ]);
    }

    public function members(Request $request, ListCommunityMembers $query): View
    {
        $community = $this->communityFromQuery($request);

        return $this->classic('community.members', [
            'community' => $community,
            'members' => $query($community),
        ]);
    }

    public function edit(Request $request): View
    {
        $community = $request->filled('id') ? Community::findOrFail($request->integer('id')) : null;
        if ($community !== null) {
            abort_unless(Gate::allows('update', $community), 404);
            $community->loadMissing('category');
        }

        return $this->classic('community.edit', [
            'community' => $community,
            'categories' => $this->editableCategories($community),
            'policies' => JoinPolicy::cases(),
        ]);
    }

    public function save(CommunityRequest $request, CreateCommunity $create, UpdateCommunity $update): RedirectResponse
    {
        $community = $request->filled('id') ? Community::findOrFail($request->integer('id')) : null;

        try {
            if ($community === null) {
                $community = $create($this->viewer(), $request->toData());
            } else {
                abort_unless(Gate::allows('update', $community), 404);
                $update($this->viewer(), $community, $request->toData());
            }
        } catch (CommunityActionException $e) {
            return back()->withInput()->with('error', $this->messageFor($e->reason));
        }

        return redirect()->route('community.show', $community)
            ->with('status', __('%Community% settings saved.'));
    }

    public function showJoin(Request $request): View|RedirectResponse
    {
        $community = $this->communityFromQuery($request);
        $viewer = $this->viewer();
        // Already in the community or awaiting approval: nothing to confirm.
        if (CommunityMembership::isMember($community, $viewer) || CommunityMembership::isPending($community, $viewer)) {
            return redirect()->route('community.show', $community);
        }

        return $this->classic('community.join', ['community' => $community]);
    }

    public function join(Request $request, JoinCommunity $action): RedirectResponse
    {
        $community = $this->communityFromQuery($request);

        try {
            $action($this->viewer(), $community);
        } catch (CommunityActionException $e) {
            return redirect()->route('community.show', $community)->with('error', $this->messageFor($e->reason));
        }

        $status = $community->register_policy === JoinPolicy::Approval
            ? __('Your join request has been sent.')
            : __('You have joined this %community%.');

        return redirect()->route('community.show', $community)->with('status', $status);
    }

    public function showQuit(Request $request): View|RedirectResponse
    {
        $community = $this->communityFromQuery($request);
        $viewer = $this->viewer();
        // Only a non-admin member can quit (the sole admin must hand off first).
        if (! CommunityMembership::isMember($community, $viewer) || CommunityMembership::isAdmin($community, $viewer)) {
            return redirect()->route('community.show', $community);
        }

        return $this->classic('community.quit', ['community' => $community]);
    }

    public function quit(Request $request, QuitCommunity $action): RedirectResponse
    {
        $community = $this->communityFromQuery($request);

        try {
            $action($this->viewer(), $community);
        } catch (CommunityActionException $e) {
            return redirect()->route('community.show', $community)->with('error', $this->messageFor($e->reason));
        }

        return redirect()->route('community.show', $community)->with('status', __('You have left this %community%.'));
    }

    public function showDelete(Request $request, Community $community): View
    {
        abort_unless(Gate::allows('delete', $community), 404);

        return $this->classic('community.delete', ['community' => $community]);
    }

    public function delete(Request $request, Community $community, DeleteCommunity $action): RedirectResponse
    {
        abort_unless(Gate::allows('delete', $community), 404);
        $action($this->viewer(), $community);

        return redirect()->route('community.search')->with('status', __('%Community% deleted.'));
    }

    public function pendingMembers(Request $request, ListPendingMembers $query): View
    {
        $community = $this->communityFromQuery($request);
        abort_unless(Gate::allows('manageMembers', $community), 404);

        return $this->classic('community.pending', [
            'community' => $community,
            'applicants' => $query($community),
        ]);
    }

    public function approve(Request $request, ApproveMember $action): RedirectResponse
    {
        return $this->moderate($request, fn (Community $c, Member $applicant) => $action($this->viewer(), $c, $applicant), __('Member approved.'));
    }

    public function decline(Request $request, DeclinePendingMember $action): RedirectResponse
    {
        return $this->moderate($request, fn (Community $c, Member $applicant) => $action($this->viewer(), $c, $applicant), __('Request declined.'));
    }

    /** Shared approve/decline body: gate on admin, resolve the applicant, run, redirect to pending. */
    private function moderate(Request $request, callable $run, string $status): RedirectResponse
    {
        $community = $this->communityFromQuery($request);
        abort_unless(Gate::allows('manageMembers', $community), 404);
        $applicant = Member::findOrFail($request->integer('member_id'));

        try {
            $run($community, $applicant);
        } catch (CommunityActionException $e) {
            return $this->redirectToPending($community)->with('error', $this->messageFor($e->reason));
        }

        return $this->redirectToPending($community)->with('status', $status);
    }

    private function redirectToPending(Community $community): RedirectResponse
    {
        return redirect()->route('community.members.pending', ['id' => $community->getKey()]);
    }

    /** Resolve the community a `?id=`-scoped page is about (join/quit/members/pending), or 404. */
    private function communityFromQuery(Request $request): Community
    {
        return Community::findOrFail($request->integer('id'));
    }

    /** Categories an ordinary member may create in — the OpenPNE 3 create-form set. */
    private function selectableCategories()
    {
        return CommunityCategory::query()
            ->where('is_allow_member_community', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /** Every category, for the search filter (OpenPNE 3 CommunityFormFilter::getAllChildren()). */
    private function allCategories()
    {
        return CommunityCategory::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * The edit form's category options: the member-creatable set plus the community's current
     * category if it is not in it, so an admin editing a community in an admin-only category can
     * keep it instead of having it silently dropped (OpenPNE 3 CommunityForm).
     */
    private function editableCategories(?Community $community)
    {
        $categories = $this->selectableCategories();
        $current = $community?->category;

        if ($current !== null && ! $categories->contains(fn (CommunityCategory $c): bool => $c->is($current))) {
            $categories = $categories->push($current)->sortBy('sort_order')->sortBy('name')->values();
        }

        return $categories;
    }

    private function stringValue(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /** Render a Classic view with the OpenPNE 3 page_{module}_{action} body id from the parity. */
    private function classic(string $view, array $data = []): View
    {
        // A page about one concrete community renders the community localNav; search and the
        // member-community list (plural `communities`) keep the default nav, as OpenPNE 3 does.
        if (($data['community'] ?? null) instanceof Community) {
            $this->markLocalNavCommunity($data['community']);
        }

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

    private function messageFor(CommunityActionFailure $reason): string
    {
        return match ($reason) {
            CommunityActionFailure::AlreadyMember => __('You are already a member of this %community%.'),
            CommunityActionFailure::AlreadyRequested => __('Your join request is already pending.'),
            CommunityActionFailure::NotMember => __('You are not a member of this %community%.'),
            CommunityActionFailure::NotPending => __('No pending request found.'),
            CommunityActionFailure::AdminCannotQuit => __('The admin must transfer the role before leaving.'),
            CommunityActionFailure::NotManager, CommunityActionFailure::NotAdmin => __('You are not allowed to manage this %community%.'),
            CommunityActionFailure::CategoryNotAllowed => __('You cannot create a %community% in this category.'),
        };
    }
}
