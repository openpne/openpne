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
use App\Features\Community\Serializers\CommunitySerializer;
use App\Features\CommunityEvent\CommunityEventAccess;
use App\Features\CommunityEvent\Queries\RecentCommunityEvents;
use App\Features\CommunityEvent\Serializers\CommunityEventSerializer;
use App\Features\CommunityTopic\CommunityTopicAccess;
use App\Features\CommunityTopic\Queries\RecentCommunityTopics;
use App\Features\CommunityTopic\Serializers\CommunityTopicSerializer;
use App\Http\Controllers\Concerns\RespondsWithSurface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Community\CommunityRequest;
use App\Models\Community;
use App\Models\CommunityCategory;
use App\Models\Member;
use App\Support\SurfaceResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Community core and management, both dual-surface: each action serves Classic Blade or Modern
 * Inertia per SurfaceResolver, preserving the Classic body id and the community localNav side
 * effect in the Classic branch. showJoin/showQuit/showDelete stay Classic-only GET confirm pages —
 * Modern confirms join/quit/delete inline (Radix AlertDialog) and POSTs directly.
 */
class CommunityController extends Controller
{
    use RespondsWithSurface;

    public function show(Request $request, int $community, ShowCommunity $query, RecentCommunityTopics $recentTopics, RecentCommunityEvents $recentEvents): View|InertiaResponse
    {
        $found = $query($community);
        abort_if($found === null, 404);
        $found->loadMissing('category', 'image');
        $viewer = $this->viewer();
        $role = CommunityMembership::roleOf($found, $viewer);
        $isPending = CommunityMembership::isPending($found, $viewer);
        // The sidemenu member grid (OpenPNE 3 nineTable, 3×3), admins first like ListCommunityMembers.
        // Shared by the Classic grid and the Modern member preview.
        $sidebarMembers = $found->members()->with('member.avatar.file')
            ->orderByDesc('role')->orderBy('id')->limit(9)->get();
        // The recent-topics / recent-events boxes (OpenPNE 3 community home) only show when the viewer
        // may read that board; events share the topic read gate, so one check covers both. Modern
        // omits these until the board/event surfaces land (a follow-up), so no unlinkable content.
        $canViewBoard = CommunityTopicAccess::canViewBoard($found, $viewer);

        return $this->respondWith($request, 'community', [
            SurfaceResolver::CLASSIC => function () use ($found, $viewer, $role, $isPending, $sidebarMembers, $canViewBoard, $recentTopics, $recentEvents) {
                $this->markLocalNavCommunity($found);

                return view('community.show', [
                    'community' => $found,
                    'sidebarMembers' => $sidebarMembers,
                    'role' => $role,
                    'isPending' => $isPending,
                    'recentTopics' => $canViewBoard ? $recentTopics($found) : null,
                    'canPostTopic' => CommunityTopicAccess::canPostTopic($found, $viewer),
                    'recentEvents' => $canViewBoard ? $recentEvents($found) : null,
                    'canPostEvent' => CommunityEventAccess::canPostEvent($found, $viewer),
                ]);
            },
            SurfaceResolver::MODERN => fn () => Inertia::render('community/show', [
                'community' => CommunitySerializer::detail($found),
                'viewerRole' => $role?->slug(),
                'isPending' => $isPending,
                'canManage' => $role?->canManage() ?? false,
                'canJoin' => $role === null && ! $isPending,
                // Only a non-admin member may leave (the sole admin must hand off first), matching showQuit.
                'canLeave' => $role !== null && $role !== CommunityRole::Admin,
                'members' => CommunitySerializer::members($sidebarMembers),
                // The recent-topics / recent-events boxes link into their boards; null when the viewer
                // may not read them (events share the topic read gate), so the card is hidden.
                'recentTopics' => $canViewBoard ? CommunityTopicSerializer::summaries($recentTopics($found)) : null,
                'canPostTopic' => CommunityTopicAccess::canPostTopic($found, $viewer),
                'recentEvents' => $canViewBoard ? CommunityEventSerializer::summaries($recentEvents($found)) : null,
                'canPostEvent' => CommunityEventAccess::canPostEvent($found, $viewer),
            ]),
        ]);
    }

    public function search(Request $request, SearchCommunities $query): View|InertiaResponse
    {
        // OpenPNE 3 query shape: community[name] / community[community_category_id], with a
        // search_query alias for the name (preserved so a bookmarked OpenPNE 3 search URL works).
        // The Modern search form uses flat keyword / category_id, accepted here as a fallback.
        $params = $request->query('community');
        $params = is_array($params) ? $params : [];

        $keyword = $this->stringValue($params['name'] ?? null);
        if ($keyword === '') {
            $keyword = $this->stringValue($request->query('search_query') ?? $request->query('keyword'));
        }

        $categoryRaw = $params['community_category_id'] ?? $request->query('category_id');
        // 0 / negative is the Modern form's "all categories" sentinel, not a real category id.
        $categoryId = is_numeric($categoryRaw) && (int) $categoryRaw > 0 ? (int) $categoryRaw : null;

        $communities = $query($keyword, $categoryId);

        return $this->respondWith($request, 'community', [
            SurfaceResolver::CLASSIC => fn () => view('community.search', [
                'keyword' => $keyword,
                'categoryId' => $categoryId,
                // Search spans every category (OpenPNE 3 CommunityFormFilter), not just the
                // member-creatable set the create form offers.
                'categories' => $this->allCategories(),
                'communities' => $communities,
            ]),
            SurfaceResolver::MODERN => fn () => Inertia::render('community/search', [
                'keyword' => $keyword,
                'categoryId' => $categoryId,
                'categories' => $this->categoryOptions(),
                'communities' => CommunitySerializer::paginator($communities),
            ]),
        ]);
    }

    public function listMine(Request $request, ListMemberCommunities $query): View|InertiaResponse
    {
        $owner = $this->memberSubject($request->filled('id')
            ? Member::findOrFail($request->integer('id'))
            : null);
        $communities = $query($owner);

        return $this->respondWith($request, 'community', [
            SurfaceResolver::CLASSIC => fn () => view('community.list', [
                'owner' => $owner,
                'communities' => $communities,
            ]),
            SurfaceResolver::MODERN => fn () => Inertia::render('community/list', [
                'owner' => ['id' => $owner->getKey(), 'name' => $owner->name],
                'isOwner' => $this->viewer()->is($owner),
                'communities' => CommunitySerializer::paginator($communities),
            ]),
        ]);
    }

    public function members(Request $request, ListCommunityMembers $query): View|InertiaResponse
    {
        $community = $this->communityFrom($request);
        $members = $query($community);

        return $this->respondWith($request, 'community', [
            SurfaceResolver::CLASSIC => function () use ($community, $members) {
                $this->markLocalNavCommunity($community);

                return view('community.members', [
                    'community' => $community,
                    'members' => $members,
                ]);
            },
            SurfaceResolver::MODERN => fn () => Inertia::render('community/members', [
                'community' => CommunitySerializer::summary($community),
                'members' => CommunitySerializer::memberPaginator($members),
            ]),
        ]);
    }

    public function edit(Request $request): View|InertiaResponse
    {
        $community = $this->optionalCommunityFrom($request);
        if ($community !== null) {
            abort_unless(Gate::allows('update', $community), 404);
            $community->loadMissing('category', 'image');
        }
        $categories = $this->editableCategories($community);

        return $this->respondWith($request, 'community', [
            SurfaceResolver::CLASSIC => function () use ($community, $categories) {
                if ($community !== null) {
                    $this->markLocalNavCommunity($community);
                }

                return view('community.edit', [
                    'community' => $community,
                    'categories' => $categories,
                    'policies' => JoinPolicy::cases(),
                ]);
            },
            SurfaceResolver::MODERN => fn () => Inertia::render('community/edit', [
                'community' => $community === null ? null : [
                    'id' => $community->getKey(),
                    'name' => $community->name,
                    'description' => $community->description ?? '',
                    'registerPolicy' => $community->register_policy->value,
                    'categoryId' => $community->community_category_id,
                    'imageUrl' => $community->image?->thumbnailUrl(180, 180, square: true),
                ],
                'categories' => $categories->map(fn (CommunityCategory $category): array => [
                    'id' => $category->getKey(),
                    'name' => $category->name,
                ])->values()->all(),
                'policies' => array_map(fn (JoinPolicy $policy): array => [
                    'value' => $policy->value,
                    'label' => $policy->label(),
                ], JoinPolicy::cases()),
                'canDelete' => $community !== null && Gate::allows('delete', $community),
            ]),
        ]);
    }

    public function save(CommunityRequest $request, CreateCommunity $create, UpdateCommunity $update): RedirectResponse
    {
        $community = $this->optionalCommunityFrom($request);

        try {
            if ($community === null) {
                $community = $create($this->viewer(), $request->toData(), $request->file('image'));
            } else {
                abort_unless(Gate::allows('update', $community), 404);
                $update($this->viewer(), $community, $request->toData(), $request->file('image'), $request->boolean('remove_image'));
            }
        } catch (CommunityActionException $e) {
            return back()->withInput()->with('error', $this->messageFor($e->reason));
        }

        return $this->redirectToShow($request, $community)
            ->with('status', __('%Community% settings saved.'));
    }

    public function showJoin(Request $request): View|RedirectResponse
    {
        $community = $this->communityFrom($request);
        $viewer = $this->viewer();
        // Already in the community or awaiting approval: nothing to confirm.
        if (CommunityMembership::isMember($community, $viewer) || CommunityMembership::isPending($community, $viewer)) {
            return redirect()->route('community.show', $community);
        }

        return $this->classic('community.join', ['community' => $community]);
    }

    public function join(Request $request, JoinCommunity $action): RedirectResponse
    {
        $community = $this->communityFrom($request);

        try {
            $action($this->viewer(), $community);
        } catch (CommunityActionException $e) {
            return $this->redirectToShow($request, $community)->with('error', $this->messageFor($e->reason));
        }

        $status = $community->register_policy === JoinPolicy::Approval
            ? __('Your join request has been sent.')
            : __('You have joined this %community%.');

        return $this->redirectToShow($request, $community)->with('status', $status);
    }

    public function showQuit(Request $request): View|RedirectResponse
    {
        $community = $this->communityFrom($request);
        $viewer = $this->viewer();
        // Only a non-admin member can quit (the sole admin must hand off first).
        if (! CommunityMembership::isMember($community, $viewer) || CommunityMembership::isAdmin($community, $viewer)) {
            return redirect()->route('community.show', $community);
        }

        return $this->classic('community.quit', ['community' => $community]);
    }

    public function quit(Request $request, QuitCommunity $action): RedirectResponse
    {
        $community = $this->communityFrom($request);

        try {
            $action($this->viewer(), $community);
        } catch (CommunityActionException $e) {
            return $this->redirectToShow($request, $community)->with('error', $this->messageFor($e->reason));
        }

        return $this->redirectToShow($request, $community)->with('status', __('You have left this %community%.'));
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

        return redirect()->route(SurfaceResolver::redirectName($request, 'community.search'))
            ->with('status', __('%Community% deleted.'));
    }

    public function pendingMembers(Request $request, ListPendingMembers $query): View|InertiaResponse
    {
        $community = $this->communityFrom($request);
        abort_unless(Gate::allows('manageMembers', $community), 404);
        $applicants = $query($community);

        return $this->respondWith($request, 'community', [
            SurfaceResolver::CLASSIC => function () use ($community, $applicants) {
                $this->markLocalNavCommunity($community);

                return view('community.pending', [
                    'community' => $community,
                    'applicants' => $applicants,
                ]);
            },
            SurfaceResolver::MODERN => fn () => Inertia::render('community/pending', [
                'community' => CommunitySerializer::summary($community),
                'applicants' => CommunitySerializer::applicantPaginator($applicants),
            ]),
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
        $community = $this->communityFrom($request);
        abort_unless(Gate::allows('manageMembers', $community), 404);
        $applicant = Member::findOrFail($request->integer('member_id'));

        try {
            $run($community, $applicant);
        } catch (CommunityActionException $e) {
            return $this->redirectToPending($request, $community)->with('error', $this->messageFor($e->reason));
        }

        return $this->redirectToPending($request, $community)->with('status', $status);
    }

    private function redirectToPending(Request $request, Community $community): RedirectResponse
    {
        // Modern keys the community off the path; canonical Classic keys it off ?id=.
        if ($request->route('surface') === SurfaceResolver::MODERN) {
            return redirect()->route('community.modern.members.pending', $community);
        }

        return redirect()->route('community.members.pending', ['id' => $community->getKey()]);
    }

    /** Redirect to the community top page on the surface the request came from (Modern -> /m/*). */
    private function redirectToShow(Request $request, Community $community): RedirectResponse
    {
        return redirect()->route(SurfaceResolver::redirectName($request, 'community.show'), $community);
    }

    /**
     * Resolve the community a page is about. /m/* routes carry it in the path ({community});
     * canonical Classic routes (join/quit/members/pending) use ?id=. 404 when neither resolves.
     */
    private function communityFrom(Request $request): Community
    {
        $routeId = $request->route('community');
        $id = $routeId !== null ? (int) $routeId : $request->integer('id');

        return Community::findOrFail($id);
    }

    /**
     * Like communityFrom, but null when no community is identified — the create form/submit carries
     * neither a path {community} nor ?id=. Used by edit/save, which serve both new and existing.
     */
    private function optionalCommunityFrom(Request $request): ?Community
    {
        $routeId = $request->route('community');
        $id = $routeId !== null ? (int) $routeId : ($request->filled('id') ? $request->integer('id') : null);

        return $id !== null ? Community::findOrFail($id) : null;
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
     * The search filter's category options for the Modern surface: {id, name} for every category.
     *
     * @return list<array{id: int, name: string}>
     */
    private function categoryOptions(): array
    {
        return $this->allCategories()
            ->map(fn (CommunityCategory $category): array => [
                'id' => $category->getKey(),
                'name' => $category->name,
            ])
            ->all();
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
