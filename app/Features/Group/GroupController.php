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
use App\Features\Group\Serializers\UnifiedGroupSerializer;
use App\Features\GroupEvent\GroupEventAccess;
use App\Features\GroupEvent\Queries\RecentGroupEvents;
use App\Features\GroupEvent\Serializers\GroupEventSerializer;
use App\Features\GroupTalk\GroupTalkAccess;
use App\Features\GroupTalk\Queries\JoinedTalkRooms;
use App\Features\GroupTalk\Queries\LatestGroupMessage;
use App\Features\GroupTalk\Queries\UnreadTalkCounts;
use App\Features\GroupTalk\Serializers\TalkRoomSerializer;
use App\Features\GroupTopic\GroupTopicAccess;
use App\Features\GroupTopic\Queries\RecentGroupTopics;
use App\Features\GroupTopic\Serializers\GroupTopicSerializer;
use App\Features\GroupTopic\TopicPostAuthority;
use App\Features\GroupTopic\TopicReadAccess;
use App\Features\Member\Serializers\MemberRefSerializer;
use App\Features\Notifications\ConsumeNotificationRows;
use App\Features\Notifications\NotificationTarget;
use App\Http\Controllers\Concerns\RespondsWithSurface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Group\GroupRequest;
use App\Models\Group;
use App\Models\GroupCategory;
use App\Models\Member;
use App\Support\ChatPreview;
use App\Support\Feature;
use App\Support\LookResolver;
use App\Support\SurfaceResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class GroupController extends Controller
{
    use RespondsWithSurface;

    public function show(Request $request, int $group, ShowGroup $query, RecentGroupTopics $recentTopics, RecentGroupEvents $recentEvents, LatestGroupMessage $latestMessage, UnreadTalkCounts $talkUnread, ConsumeNotificationRows $feedRows): View|InertiaResponse
    {
        $found = $query($group);
        abort_if($found === null, 404);
        $found->loadMissing('category', 'image');
        $viewer = $this->viewer();
        $role = GroupMembership::roleOf($found, $viewer);
        $isPending = GroupMembership::isPending($found, $viewer);
        $isTransferNominee = $found->pending_admin_member_id !== null
            && (int) $found->pending_admin_member_id === (int) $viewer->getKey();
        // Only the Classic caption carries the friend count (OpenPNE 3 getNameAndCount), so only
        // Classic pays for it.
        $countFriends = Feature::Friend->enabled() && SurfaceResolver::resolve($request, 'group') === SurfaceResolver::CLASSIC;
        $sidebarMembers = $found->members()
            ->with(['member' => fn ($members) => $countFriends ? $members->with('avatar.file')->withCount('friendships') : $members->with('avatar.file')])
            ->orderByDesc('role')->orderBy('id')->limit(9)->get();
        // Events share the topic read gate, so one check covers both boards.
        $canViewBoard = GroupTopicAccess::canViewBoard($found, $viewer);
        // A switched-off unit hides its box through the same null both surfaces already hide on,
        // and its query never runs.
        $showTopics = $canViewBoard && Feature::GroupTopic->enabled();
        $showEvents = $canViewBoard && Feature::GroupEvent->enabled();
        // Read here rather than in a branch: all three layouts — Classic, the shipped Modern page and
        // the unified one — show these same rows, and none of them may ask for them a second time.
        $topics = $showTopics ? $recentTopics($found) : null;
        $canPostTopic = GroupTopicAccess::canPostTopic($found, $viewer);
        $events = $showEvents ? $recentEvents($found) : null;
        $canPostEvent = GroupEventAccess::canPostEvent($found, $viewer);
        // Talk asks its own two questions rather than borrowing the board's null seam: it reads the
        // same access column but is a separate unit, so a site running talk with the board switched
        // off must still get its entrance.
        $canViewTalk = Feature::GroupTalk->enabled() && GroupTalkAccess::canView($found, $viewer);
        // Never read the conversation before the gate says the viewer may: the preview carries a
        // member's words, so the access question comes first and the query does not run otherwise.
        $talkPreview = $canViewTalk ? $latestMessage($found) : null;
        // A non-member reading an Everyone group has no membership row and so no cursor: zero,
        // rather than everything.
        $talkUnreadCount = $canViewTalk ? ($talkUnread($viewer)[$found->getKey()]['count'] ?? 0) : 0;
        // The group's own rows only: the room's talk row is the conversation's, and reading it is
        // what the talk read cursor does.
        $feedRows->markTargetsRead((int) $viewer->getKey(), NotificationTarget::group((int) $found->getKey()));

        return $this->respondWith($request, 'group', [
            SurfaceResolver::CLASSIC => function () use ($found, $role, $isPending, $isTransferNominee, $sidebarMembers, $topics, $canPostTopic, $events, $canPostEvent, $canViewTalk) {
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
                    'recentTopics' => $topics,
                    'canPostTopic' => $canPostTopic,
                    'recentEvents' => $events,
                    'canPostEvent' => $canPostEvent,
                    // The talk screen is Modern for every member, so Classic gets a link box.
                    'canViewTalk' => $canViewTalk,
                ]);
            },
            SurfaceResolver::MODERN => function () use ($request, $found, $viewer, $role, $isPending, $isTransferNominee, $sidebarMembers, $topics, $canPostTopic, $events, $canPostEvent, $canViewTalk, $talkPreview, $talkUnreadCount) {
                $canManage = $role?->canManage() ?? false;
                $canJoin = $role === null && ! $isPending;
                $canLeave = $role !== null && $role !== GroupRole::Admin;
                $preview = $talkPreview === null ? null : [
                    // The room list's own line, so the card and the list read the same.
                    'body' => ChatPreview::lineOrImages([$talkPreview->body], (bool) $talkPreview->images_exists),
                    'authorName' => $talkPreview->author?->name,
                    'createdAt' => $talkPreview->created_at->toIso8601String(),
                ];

                // Both Inertia::render targets stay string literals, so a static sweep can classify
                // every routed component.
                if (LookResolver::resolve($request)->usesUnifiedPages()) {
                    return Inertia::render('unified/group', UnifiedGroupSerializer::page(
                        viewer: $viewer,
                        group: $found,
                        role: $role,
                        isPending: $isPending,
                        isTransferNominee: $isTransferNominee,
                        canManage: $canManage,
                        canJoin: $canJoin,
                        canLeave: $canLeave,
                        members: $sidebarMembers,
                        recentTopics: $topics,
                        canPostTopic: $canPostTopic,
                        recentEvents: $events,
                        canPostEvent: $canPostEvent,
                        canViewTalk: $canViewTalk,
                        talkPreview: $preview,
                        talkUnread: $talkUnreadCount,
                    ));
                }

                return Inertia::render('community/show', [
                    'group' => GroupSerializer::detail($found),
                    'viewerRole' => $role?->slug(),
                    'isPending' => $isPending,
                    'isTransferNominee' => $isTransferNominee,
                    'canManage' => $canManage,
                    'canJoin' => $canJoin,
                    'canLeave' => $canLeave,
                    'members' => GroupSerializer::members($sidebarMembers),
                    'recentTopics' => $topics === null ? null : GroupTopicSerializer::summaries($topics),
                    'canPostTopic' => $canPostTopic,
                    'recentEvents' => $events === null ? null : GroupEventSerializer::summaries($events),
                    'canPostEvent' => $canPostEvent,
                    'canViewTalk' => $canViewTalk,
                    'talkPreview' => $preview,
                    'talkUnread' => $talkUnreadCount,
                ]);
            },
        ]);
    }

    public function search(Request $request, SearchGroups $query): View|InertiaResponse
    {
        // OpenPNE 3 query shape community[name] / community[community_category_id] plus its
        // search_query alias, so a bookmarked OpenPNE 3 search URL still works.
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

    /**
     * The member's own list under Modern with talk on is a list of conversations; everything else
     * stays the group grid, so `view` is what the client switches on rather than a prop's presence.
     * The grid's query is deferred behind a closure because the two shapes read different tables.
     */
    public function listMine(Request $request, ListMemberGroups $query, JoinedTalkRooms $talkRooms): View|InertiaResponse
    {
        $owner = $this->memberSubject($request->filled('id')
            ? Member::findOrFail($request->integer('id'))
            : null);
        $groups = fn () => $query($owner);

        return $this->respondWith($request, 'group', [
            SurfaceResolver::CLASSIC => fn () => view('group.list', [
                'owner' => $owner,
                'groups' => $groups(),
            ]),
            SurfaceResolver::MODERN => function () use ($owner, $groups, $talkRooms) {
                // The owner ref draws the chrome's scope avatar (Modern only, so Classic pays nothing).
                $owner->loadMissing('avatar.file');
                $isOwner = $this->viewer()->is($owner);

                // A room list is the viewer's own conversations by construction: the order is what
                // was last said and the pills are their unread, neither of which another member's
                // membership list can answer.
                if ($isOwner && Feature::GroupTalk->enabled()) {
                    return Inertia::render('community/list', [
                        'owner' => MemberRefSerializer::ref($owner),
                        'isOwner' => true,
                        'view' => 'rooms',
                        'rooms' => TalkRoomSerializer::paginator($talkRooms($owner)),
                    ]);
                }

                return Inertia::render('community/list', [
                    'owner' => MemberRefSerializer::ref($owner),
                    'isOwner' => $isOwner,
                    'view' => 'grid',
                    'groups' => GroupSerializer::paginator($groups()),
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
     * the enum alone.
     *
     * @param  array<int, JoinPolicy|TopicReadAccess|TopicPostAuthority>  $cases
     * @return list<array{slug: string, label: string}>
     */
    private static function choices(array $cases): array
    {
        return array_map(fn ($case): array => [
            'slug' => $case->slug(),
            'label' => $case->label(),
            'op3Value' => $case->op3Value(),
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
        $modern = SurfaceResolver::resolve($request, 'group') === SurfaceResolver::MODERN;
        // Classic says so on OpenPNE 3's joinError screen; Modern confirms joining inline, so it
        // redirects back to the group.
        foreach ([
            [GroupMembership::isMember($group, $viewer), __('You are already joined to this %community%.')],
            [GroupMembership::isPending($group, $viewer), __('You have already sent the participation request to this %community%.')],
        ] as [$blocked, $body]) {
            if ($blocked) {
                return $modern ? redirect()->route('group.show', $group) : $this->classic('group.error', ['group' => $group, 'body' => $body]);
            }
        }

        if ($modern) {
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
        $modern = SurfaceResolver::resolve($request, 'group') === SurfaceResolver::MODERN;
        // Classic says so on OpenPNE 3's quitError screen; Modern confirms leaving inline, so it
        // redirects back to the group.
        foreach ([
            [GroupMembership::isAdmin($group, $viewer), __("The administrator doesn't leave the %community%.")],
            [! GroupMembership::isMember($group, $viewer), __("You haven't joined this %community% yet.")],
        ] as [$blocked, $body]) {
            if ($blocked) {
                return $modern ? redirect()->route('group.show', $group) : $this->classic('group.error', ['group' => $group, 'body' => $body]);
            }
        }

        if ($modern) {
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

    /** The canonical routes carry the group in the path; the OpenPNE 3 shapes carry it as `?id=`. */
    private function groupFrom(Request $request): Group
    {
        $routeId = $request->route('group');
        $id = $routeId !== null ? (int) $routeId : $request->integer('id');

        return Group::findOrFail($id);
    }

    private function optionalGroupFrom(Request $request): ?Group
    {
        $routeId = $request->route('group');
        $id = $routeId !== null ? (int) $routeId : ($request->filled('id') ? $request->integer('id') : null);

        return $id !== null ? Group::findOrFail($id) : null;
    }

    private function selectableCategories()
    {
        return GroupCategory::query()
            ->where('is_allow_member_group', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    private function allCategories()
    {
        return GroupCategory::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
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
