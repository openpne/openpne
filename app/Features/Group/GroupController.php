<?php

namespace App\Features\Group;

use App\Compat\RouteParityRegistry;
use App\Features\Group\Actions\ApproveMember;
use App\Features\Group\Actions\CreateGroup;
use App\Features\Group\Actions\DeclinePendingMember;
use App\Features\Group\Actions\DeleteGroup;
use App\Features\Group\Actions\JoinGroup;
use App\Features\Group\Actions\QuitGroup;
use App\Features\Group\Actions\UpdateGroup;
use App\Features\Group\Exceptions\GroupActionException;
use App\Features\Group\Exceptions\GroupActionFailure;
use App\Features\Group\Queries\ListGroupMembers;
use App\Features\Group\Queries\ListMemberGroups;
use App\Features\Group\Queries\ListPendingMembers;
use App\Features\Group\Queries\SearchGroups;
use App\Features\Group\Queries\ShowGroup;
use App\Features\Group\Serializers\GroupSerializer;
use App\Features\GroupEvent\GroupEventAccess;
use App\Features\GroupEvent\Queries\RecentGroupEvents;
use App\Features\GroupEvent\Serializers\GroupEventSerializer;
use App\Features\GroupTopic\GroupTopicAccess;
use App\Features\GroupTopic\Queries\RecentGroupTopics;
use App\Features\GroupTopic\Serializers\GroupTopicSerializer;
use App\Features\GroupTopic\TopicPostAuthority;
use App\Features\GroupTopic\TopicReadAccess;
use App\Features\Member\Serializers\MemberRefSerializer;
use App\Features\Timeline\CommunityTimelineAccess;
use App\Features\Timeline\Queries\CommunityTimeline;
use App\Features\Timeline\Serializers\TimelinePostSerializer;
use App\Http\Controllers\Concerns\RespondsWithSurface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Group\GroupRequest;
use App\Models\Group;
use App\Models\GroupCategory;
use App\Models\Member;
use App\Support\Feature;
use App\Support\SurfaceResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Group core and management, both dual-surface: each action serves Classic Blade or Modern
 * Inertia per SurfaceResolver, preserving the Classic body id and the group localNav side
 * effect in the Classic branch. showJoin/showQuit/showDelete stay Classic-only GET confirm pages —
 * Modern confirms join/quit/delete inline (Radix AlertDialog) and POSTs directly.
 */
class GroupController extends Controller
{
    use RespondsWithSurface;

    /**
     * Rows in the community home's timeline box. OpenPNE 3's component asked for twenty, but it
     * streamed them behind a load-more; rendered as a static block that many would bury the
     * group's own details. Five, like the topic and event boxes beside it — the box's "show
     * all" link carries the rest.
     */
    private const TIMELINE_BOX = 5;

    public function show(Request $request, int $group, ShowGroup $query, RecentGroupTopics $recentTopics, RecentGroupEvents $recentEvents, CommunityTimeline $communityTimeline): View|InertiaResponse
    {
        $found = $query($group);
        abort_if($found === null, 404);
        $found->loadMissing('category', 'image');
        $viewer = $this->viewer();
        $role = GroupMembership::roleOf($found, $viewer);
        $isPending = GroupMembership::isPending($found, $viewer);
        // The viewer is the pending admin-transfer nominee — the accept/reject banner is theirs.
        $isTransferNominee = $found->pending_admin_member_id !== null
            && (int) $found->pending_admin_member_id === (int) $viewer->getKey();
        // The sidemenu member grid (3×3), admins first like ListGroupMembers.
        // Shared by the Classic grid and the Modern member preview.
        $sidebarMembers = $found->members()->with('member.avatar.file')
            ->orderByDesc('role')->orderBy('id')->limit(9)->get();
        // The recent-topics / recent-events boxes only show when the viewer
        // may read that board; events share the topic read gate, so one check covers both.
        $canViewBoard = GroupTopicAccess::canViewBoard($found, $viewer);
        // A switched-off unit reuses that same null seam — both surfaces already hide the box on
        // null — and its query never runs.
        $showTopics = $canViewBoard && Feature::GroupTopic->enabled();
        $showEvents = $canViewBoard && Feature::GroupEvent->enabled();
        // OpenPNE 3 injected the community timeline box here and gated it on membership: the box
        // leads with a compose form, and a non-member cannot post. Null keeps the box off the page,
        // the same seam the two boards use, so a switched-off unit costs no query either.
        $showTimeline = Feature::Timeline->enabled() && CommunityTimelineAccess::canPost($found, $viewer);

        return $this->respondWith($request, 'group', [
            SurfaceResolver::CLASSIC => function () use ($found, $viewer, $role, $isPending, $isTransferNominee, $sidebarMembers, $showTopics, $showEvents, $showTimeline, $recentTopics, $recentEvents, $communityTimeline) {
                $this->markLocalNavGroup($found);

                // The details listBox names the admin and sub-admins; only Classic needs them, so the
                // query lives here and never runs for Modern.
                $staff = $found->members()
                    ->whereIn('role', [GroupRole::Admin, GroupRole::SubAdmin])
                    ->with('member')->orderByDesc('role')->orderBy('id')->get();

                return view('group.show', [
                    'group' => $found,
                    'sidebarMembers' => $sidebarMembers,
                    'role' => $role,
                    'isPending' => $isPending,
                    'isTransferNominee' => $isTransferNominee,
                    'adminMember' => $staff->firstWhere('role', GroupRole::Admin)?->member,
                    'subAdminMembers' => $staff->where('role', GroupRole::SubAdmin)->pluck('member'),
                    'recentTopics' => $showTopics ? $recentTopics($found) : null,
                    'canPostTopic' => GroupTopicAccess::canPostTopic($found, $viewer),
                    'recentEvents' => $showEvents ? $recentEvents($found) : null,
                    'canPostEvent' => GroupEventAccess::canPostEvent($found, $viewer),
                    'timelinePosts' => $showTimeline ? $communityTimeline->take($viewer, $found, self::TIMELINE_BOX) : null,
                ]);
            },
            SurfaceResolver::MODERN => fn () => Inertia::render('community/show', [
                'group' => GroupSerializer::detail($found),
                'viewerRole' => $role?->slug(),
                'isPending' => $isPending,
                'isTransferNominee' => $isTransferNominee,
                'canManage' => $role?->canManage() ?? false,
                'canJoin' => $role === null && ! $isPending,
                // Only a non-admin member may leave (the sole admin must hand off first), matching showQuit.
                'canLeave' => $role !== null && $role !== GroupRole::Admin,
                'members' => GroupSerializer::members($sidebarMembers),
                // The recent-topics / recent-events boxes link into their boards; null when the viewer
                // may not read them (events share the topic read gate), so the card is hidden.
                'recentTopics' => $showTopics ? GroupTopicSerializer::summaries($recentTopics($found)) : null,
                'canPostTopic' => GroupTopicAccess::canPostTopic($found, $viewer),
                'recentEvents' => $showEvents ? GroupEventSerializer::summaries($recentEvents($found)) : null,
                'canPostEvent' => GroupEventAccess::canPostEvent($found, $viewer),
                // Members only, as the Classic box is: it leads to posting, which a non-member
                // cannot do. Null hides the card, the same seam the two boards use.
                'timelinePosts' => $showTimeline
                    ? array_map([TimelinePostSerializer::class, 'entry'], $communityTimeline->take($viewer, $found, self::TIMELINE_BOX)->all())
                    : null,
            ]),
        ]);
    }

    public function search(Request $request, SearchGroups $query): View|InertiaResponse
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

        $groups = $query($keyword, $categoryId);

        return $this->respondWith($request, 'group', [
            SurfaceResolver::CLASSIC => fn () => view('group.search', [
                'keyword' => $keyword,
                'categoryId' => $categoryId,
                // Search spans every category (OpenPNE 3 CommunityFormFilter), not just the
                // member-creatable set the create form offers.
                'categories' => $this->allCategories(),
                'groups' => $groups,
            ]),
            SurfaceResolver::MODERN => fn () => Inertia::render('community/search', [
                'keyword' => $keyword,
                'categoryId' => $categoryId,
                'categories' => $this->categoryOptions(),
                'groups' => GroupSerializer::paginator($groups),
            ]),
        ]);
    }

    public function listMine(Request $request, ListMemberGroups $query): View|InertiaResponse
    {
        $owner = $this->memberSubject($request->filled('id')
            ? Member::findOrFail($request->integer('id'))
            : null);
        $groups = $query($owner);

        return $this->respondWith($request, 'group', [
            SurfaceResolver::CLASSIC => fn () => view('group.list', [
                'owner' => $owner,
                'groups' => $groups,
            ]),
            SurfaceResolver::MODERN => function () use ($owner, $groups) {
                // The owner ref draws the chrome's scope avatar (Modern only, so Classic pays nothing).
                $owner->loadMissing('avatar.file');

                return Inertia::render('community/list', [
                    'owner' => MemberRefSerializer::ref($owner),
                    'isOwner' => $this->viewer()->is($owner),
                    'groups' => GroupSerializer::paginator($groups),
                ]);
            },
        ]);
    }

    public function members(Request $request, ListGroupMembers $query): View|InertiaResponse
    {
        $group = $this->groupFrom($request);
        $members = $query($group);

        return $this->respondWith($request, 'group', [
            SurfaceResolver::CLASSIC => function () use ($group, $members) {
                $this->markLocalNavGroup($group);

                return view('group.members', [
                    'group' => $group,
                    'members' => $members,
                ]);
            },
            SurfaceResolver::MODERN => fn () => Inertia::render('community/members', [
                'group' => GroupSerializer::summary($group),
                'members' => GroupSerializer::memberPaginator($members),
            ]),
        ]);
    }

    /**
     * One choice list per enum, shared verbatim by both surfaces so the OpenPNE 3 wording lives in
     * the enum alone. Slugs on the wire — the enums' own rule.
     *
     * @param  array<int, JoinPolicy|TopicReadAccess|TopicPostAuthority>  $cases
     * @return list<array{slug: string, label: string}>
     */
    private static function choices(array $cases): array
    {
        return array_map(fn ($case): array => [
            'slug' => $case->slug(),
            'label' => $case->label(),
        ], $cases);
    }

    public function edit(Request $request): View|InertiaResponse
    {
        $group = $this->optionalGroupFrom($request);
        if ($group !== null) {
            abort_unless(Gate::allows('update', $group), 404);
            $group->loadMissing('category', 'image');
        }
        $categories = $this->editableCategories($group);

        return $this->respondWith($request, 'group', [
            SurfaceResolver::CLASSIC => function () use ($group, $categories) {
                if ($group !== null) {
                    $this->markLocalNavGroup($group);
                }

                return view('group.edit', [
                    'group' => $group,
                    'categories' => $categories,
                    'policies' => self::choices(JoinPolicy::cases()),
                    'topicReadChoices' => self::choices(TopicReadAccess::cases()),
                    'topicPostChoices' => self::choices(TopicPostAuthority::cases()),
                    'canDelete' => $group !== null && Gate::allows('delete', $group),
                ]);
            },
            SurfaceResolver::MODERN => fn () => Inertia::render('community/edit', [
                'group' => $group === null ? null : [
                    'id' => $group->getKey(),
                    'name' => $group->name,
                    'description' => $group->description ?? '',
                    'registerPolicy' => $group->register_policy->slug(),
                    'categoryId' => $group->group_category_id,
                    'isJoinNotificationEnabled' => $group->is_join_notification_enabled,
                    'topicReadAccess' => $group->topic_read_access->slug(),
                    'topicPostAuthority' => $group->topic_post_authority->slug(),
                    'imageUrl' => $group->image?->thumbnailUrl(180, 180, square: true),
                ],
                'categories' => $categories->map(fn (GroupCategory $category): array => [
                    'id' => $category->getKey(),
                    'name' => $category->name,
                ])->values()->all(),
                'policies' => self::choices(JoinPolicy::cases()),
                'topicReadChoices' => self::choices(TopicReadAccess::cases()),
                'topicPostChoices' => self::choices(TopicPostAuthority::cases()),
                'canDelete' => $group !== null && Gate::allows('delete', $group),
            ]),
        ]);
    }

    public function save(GroupRequest $request, CreateGroup $create, UpdateGroup $update): RedirectResponse
    {
        $group = $this->optionalGroupFrom($request);

        try {
            if ($group === null) {
                $group = $create($this->viewer(), $request->toData(), $request->file('image'));
            } else {
                abort_unless(Gate::allows('update', $group), 404);
                $update($this->viewer(), $group, $request->toData(), $request->file('image'), $request->boolean('remove_image'));
            }
        } catch (GroupActionException $e) {
            return back()->withInput()->with('error', $this->messageFor($e->reason));
        }

        return $this->redirectToShow($group)
            ->with('status', __('%Community% settings saved.'));
    }

    public function showJoin(Request $request): View|RedirectResponse
    {
        $group = $this->groupFrom($request);
        $viewer = $this->viewer();
        // Already in the group or awaiting approval: nothing to confirm.
        if (GroupMembership::isMember($group, $viewer) || GroupMembership::isPending($group, $viewer)) {
            return redirect()->route('group.show', $group);
        }

        // Modern confirms joining inline — send a Modern viewer back to the group.
        if (SurfaceResolver::resolve($request, 'group') === SurfaceResolver::MODERN) {
            return redirect()->route('group.show', $group);
        }

        return $this->classic('group.join', ['group' => $group]);
    }

    public function join(Request $request, JoinGroup $action): RedirectResponse
    {
        $group = $this->groupFrom($request);

        try {
            $action($this->viewer(), $group);
        } catch (GroupActionException $e) {
            return $this->redirectToShow($group)->with('error', $this->messageFor($e->reason));
        }

        $status = $group->register_policy === JoinPolicy::Approval
            ? __('Your join request has been sent.')
            : __('You have joined this %community%.');

        return $this->redirectToShow($group)->with('status', $status);
    }

    public function showQuit(Request $request): View|RedirectResponse
    {
        $group = $this->groupFrom($request);
        $viewer = $this->viewer();
        // Only a non-admin member can quit (the sole admin must hand off first).
        if (! GroupMembership::isMember($group, $viewer) || GroupMembership::isAdmin($group, $viewer)) {
            return redirect()->route('group.show', $group);
        }

        // Modern confirms leaving inline — send a Modern viewer back to the group.
        if (SurfaceResolver::resolve($request, 'group') === SurfaceResolver::MODERN) {
            return redirect()->route('group.show', $group);
        }

        return $this->classic('group.quit', ['group' => $group]);
    }

    public function quit(Request $request, QuitGroup $action): RedirectResponse
    {
        $group = $this->groupFrom($request);

        try {
            $action($this->viewer(), $group);
        } catch (GroupActionException $e) {
            return $this->redirectToShow($group)->with('error', $this->messageFor($e->reason));
        }

        return $this->redirectToShow($group)->with('status', __('You have left this %community%.'));
    }

    public function showDelete(Request $request, Group $group): View|RedirectResponse
    {
        abort_unless(Gate::allows('delete', $group), 404);

        // Modern confirms deletion inline — send a Modern viewer back to the group.
        if (SurfaceResolver::resolve($request, 'group') === SurfaceResolver::MODERN) {
            return redirect()->route('group.show', $group);
        }

        return $this->classic('group.delete', ['group' => $group]);
    }

    public function delete(Request $request, Group $group, DeleteGroup $action): RedirectResponse
    {
        abort_unless(Gate::allows('delete', $group), 404);
        $action($this->viewer(), $group);

        return redirect()->route('group.search')
            ->with('status', __('%Community% deleted.'));
    }

    public function pendingMembers(Request $request, ListPendingMembers $query): View|InertiaResponse
    {
        $group = $this->groupFrom($request);
        abort_unless(Gate::allows('manageMembers', $group), 404);
        $applicants = $query($group);

        return $this->respondWith($request, 'group', [
            SurfaceResolver::CLASSIC => function () use ($group, $applicants) {
                $this->markLocalNavGroup($group);

                return view('group.pending', [
                    'group' => $group,
                    'applicants' => $applicants,
                ]);
            },
            SurfaceResolver::MODERN => fn () => Inertia::render('community/pending', [
                'group' => GroupSerializer::summary($group),
                'applicants' => GroupSerializer::applicantPaginator($applicants),
            ]),
        ]);
    }

    public function approve(Request $request, ApproveMember $action): RedirectResponse
    {
        return $this->moderate($request, fn (Group $c, Member $applicant) => $action($this->viewer(), $c, $applicant), __('Member approved.'));
    }

    public function decline(Request $request, DeclinePendingMember $action): RedirectResponse
    {
        return $this->moderate($request, fn (Group $c, Member $applicant) => $action($this->viewer(), $c, $applicant), __('Request declined.'));
    }

    /** Shared approve/decline body: gate on admin, resolve the applicant, run, redirect to pending. */
    private function moderate(Request $request, callable $run, string $status): RedirectResponse
    {
        $group = $this->groupFrom($request);
        abort_unless(Gate::allows('manageMembers', $group), 404);
        $applicant = Member::findOrFail($request->integer('member_id'));

        try {
            $run($group, $applicant);
        } catch (GroupActionException $e) {
            return $this->redirectToPending($group)->with('error', $this->messageFor($e->reason));
        }

        return $this->redirectToPending($group)->with('status', $status);
    }

    private function redirectToPending(Group $group): RedirectResponse
    {
        return redirect()->route('group.members.pending', ['group' => $group->getKey()]);
    }

    private function redirectToShow(Group $group): RedirectResponse
    {
        return redirect()->route('group.show', $group);
    }

    /**
     * Resolve the group a page is about: the canonical routes carry it in the path ({group}); the
     * OpenPNE 3 compatibility shapes carry it as ?id=. 404 when neither resolves.
     */
    private function groupFrom(Request $request): Group
    {
        $routeId = $request->route('group');
        $id = $routeId !== null ? (int) $routeId : $request->integer('id');

        return Group::findOrFail($id);
    }

    /**
     * Like groupFrom, but null when no group is identified — the create form/submit carries
     * neither a path {group} nor ?id=. Used by edit/save, which serve both new and existing.
     */
    private function optionalGroupFrom(Request $request): ?Group
    {
        $routeId = $request->route('group');
        $id = $routeId !== null ? (int) $routeId : ($request->filled('id') ? $request->integer('id') : null);

        return $id !== null ? Group::findOrFail($id) : null;
    }

    /** Categories an ordinary member may create in — the OpenPNE 3 create-form set. */
    private function selectableCategories()
    {
        return GroupCategory::query()
            ->where('is_allow_member_group', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /** Every category, for the search filter. */
    private function allCategories()
    {
        return GroupCategory::query()
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
            ->map(fn (GroupCategory $category): array => [
                'id' => $category->getKey(),
                'name' => $category->name,
            ])
            ->all();
    }

    /**
     * The edit form's category options: the member-creatable set plus the group's current
     * category if it is not in it, so an admin editing a group in an admin-only category can
     * keep it instead of having it silently dropped.
     */
    private function editableCategories(?Group $group)
    {
        $categories = $this->selectableCategories();
        $current = $group?->category;

        if ($current !== null && ! $categories->contains(fn (GroupCategory $c): bool => $c->is($current))) {
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
        // A page about one concrete group renders the group localNav; search and the
        // member-group list (plural `groups`) keep the default nav, as OpenPNE 3 does.
        if (($data['group'] ?? null) instanceof Group) {
            $this->markLocalNavGroup($data['group']);
        }

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
            GroupActionFailure::AlreadyMember => __('You are already a member of this %community%.'),
            GroupActionFailure::AlreadyRequested => __('Your join request is already pending.'),
            GroupActionFailure::NotMember => __('You are not a member of this %community%.'),
            GroupActionFailure::NotPending => __('No pending request found.'),
            GroupActionFailure::AdminCannotQuit => __('The admin must transfer the role before leaving.'),
            GroupActionFailure::NotManager, GroupActionFailure::NotAdmin => __('You are not allowed to manage this %community%.'),
            GroupActionFailure::CategoryNotAllowed => __('You cannot create a %community% in this category.'),
        };
    }
}
