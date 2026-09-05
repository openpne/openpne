<?php

declare(strict_types=1);

namespace App\Features\AiAccount;

use App\Features\AiAccount\Actions\CreateAiAccount;
use App\Features\AiAccount\Actions\DeleteAiAccount;
use App\Features\AiAccount\Actions\IssueMcpToken;
use App\Features\AiAccount\Actions\RevokeMcpToken;
use App\Features\AiAccount\Actions\UpdateAiAccountIdentity;
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
use App\Features\Member\Actions\RemoveAvatar;
use App\Features\Member\Actions\SetAvatar;
use App\Features\Member\MemberConfigCategory;
use App\Features\Member\Serializers\MemberRefSerializer;
use App\Files\ImageMetadataStripException;
use App\Http\Controllers\Concerns\RespondsWithSurface;
use App\Http\Controllers\Controller;
use App\Http\Requests\AiAccount\AiTokenRequest;
use App\Http\Requests\AiAccount\CreateAiAccountRequest;
use App\Http\Requests\AiAccount\DeleteAiAccountRequest;
use App\Http\Requests\AiAccount\UpdateAiAccountRequest;
use App\Http\Requests\Member\AvatarRequest;
use App\Mcp\McpAbilities;
use App\Models\Group;
use App\Models\Member;
use App\Support\Feature;
use App\Support\SecurityLog;
use App\Support\SurfaceResolver;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Only creation asks the site setting: everything else here answers to ownership alone, so switching
 * AI accounts off closes the door without stranding what is behind it.
 */
class AiAccountController extends Controller
{
    use RespondsWithSurface;

    public function __construct(private readonly AiAccountSettings $settings) {}

    /**
     * Modern-only: Classic reaches the same list through the member-config category page.
     */
    public function index(): InertiaResponse
    {
        $viewer = $this->viewer();

        return Inertia::render('member/config/ai/index', AiAccountSerializer::list($viewer, $this->settings));
    }

    /**
     * Dual-surface unlike the other config detail pages: this is the only way to give an account's
     * group seats up.
     */
    public function show(
        Request $request,
        Member $member,
        ListMemberGroups $joinedGroups,
        SearchGroups $searchGroups,
        SelfIntroductionField $selfIntroductionField,
    ): View|InertiaResponse {
        Gate::authorize('manageAiAccount', $member);

        // Not a cast: `?keyword[]=` hands us an array, and casting one is a fatal, not a search.
        $raw = $request->query('keyword');
        $keyword = is_string($raw) ? $raw : '';
        // The POSTs behind these panels have their own gate; hiding them is not the check.
        $groupsOn = Feature::Group->enabled();
        /** @var EloquentCollection<int, Group> $joined */
        $joined = $groupsOn ? $joinedGroups->all($member) : new EloquentCollection;
        /** @var EloquentCollection<int, Group> $pending */
        $pending = $groupsOn
            ? $member->groupJoinRequests()->with(['category', 'image'])->withCount('members')->orderByDesc('groups.id')->get()
            : new EloquentCollection;
        $browse = $groupsOn ? $searchGroups($keyword) : null;
        $tokens = AiAccountSerializer::tokens($member, $request->session());
        // OpenPNE 3's Doctrine I18n lang code.
        $lang = app()->getLocale() === 'ja' ? 'ja_JP' : 'en';
        $selfIntroduction = AiAccountSerializer::selfIntroduction($member, $selfIntroductionField(), $lang);

        return $this->respondWith($request, 'member', [
            SurfaceResolver::CLASSIC => fn () => view('member.ai-account', [
                'aiAccount' => $member,
                'selfIntroduction' => $selfIntroduction,
                'tokens' => $tokens,
                'groupsOn' => $groupsOn,
                'joined' => $joined,
                'pending' => $pending,
                'browse' => $browse,
                'keyword' => $keyword,
                'joinedIds' => $joined->modelKeys(),
                'pendingIds' => $pending->modelKeys(),
            ]),
            SurfaceResolver::MODERN => function () use ($member, $tokens, $selfIntroduction, $groupsOn, $joined, $pending, $browse, $keyword) {
                $props = [
                    'account' => MemberRefSerializer::ref($member),
                    'selfIntroduction' => $selfIntroduction,
                    'tokens' => $tokens,
                ];

                if ($groupsOn) {
                    $props['groups'] = [
                        'joined' => array_map([GroupSerializer::class, 'summary'], $joined->all()),
                        'pending' => array_map([GroupSerializer::class, 'summary'], $pending->all()),
                        // Every group, not only the joinable ones, so the pager keeps telling the
                        // truth about how big the catalogue is.
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

        return $this->accountRedirect($aiAccount)->with('status', __('AI account created.'));
    }

    /**
     * No password, unlike the token pair and the delete below: this is the same edit a person makes
     * to their own name and bio.
     */
    public function update(UpdateAiAccountRequest $request, Member $member, UpdateAiAccountIdentity $update): RedirectResponse
    {
        Gate::authorize('manageAiAccount', $member);

        $update($member, (string) $request->validated('name'), $request->validated('self_introduction'));

        return $this->accountRedirect($member)->with('status', __('AI account updated.'));
    }

    public function updateAvatar(AvatarRequest $request, Member $member, SetAvatar $action): RedirectResponse
    {
        Gate::authorize('manageAiAccount', $member);

        try {
            $action($member, $request->file('image'));
        } catch (ImageMetadataStripException) {
            // SetAvatar uses FileUploader directly, so the fail-closed strip arrives raw and is
            // turned into a field error here.
            throw ValidationException::withMessages(['image' => [ImageMetadataStripException::userMessage()]]);
        }

        return $this->accountRedirect($member)->with('status', __('Profile image updated.'));
    }

    public function destroyAvatar(Member $member, RemoveAvatar $action): RedirectResponse
    {
        Gate::authorize('manageAiAccount', $member);

        $action($member);

        return $this->accountRedirect($member)->with('status', __('Profile image removed.'));
    }

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

    public function storeToken(AiTokenRequest $request, Member $member, IssueMcpToken $issue): RedirectResponse
    {
        Gate::authorize('manageAiAccount', $member);

        $this->stampReauth($request);

        $back = $this->accountRedirect($member);

        try {
            $token = $issue($member, $request->boolean('read_only'));
        } catch (AiAccountActionException $e) {
            return $back->with('error', $this->messageFor($e->reason));
        }

        // After the Action's commit, so a rolled-back mint is never recorded as one.
        SecurityLog::event('token.issued', [
            'member_id' => (int) $member->getKey(),
            'owner_id' => $this->viewer()->getKey(),
            'name' => McpAbilities::TOKEN_NAME,
            'abilities' => implode(' ', $token->accessToken->abilities),
            'via' => 'owner',
        ]);

        return $back->with(AiAccountSerializer::NEW_TOKEN, [
            'member_id' => (int) $member->getKey(),
            'token' => $token->plainTextToken,
        ])->with('status', __('Access token issued.'));
    }

    public function destroyToken(AiTokenRequest $request, Member $member, int $token, RevokeMcpToken $revoke): RedirectResponse
    {
        Gate::authorize('manageAiAccount', $member);

        $this->stampReauth($request);

        abort_unless($revoke($member, $token), 404);

        SecurityLog::event('token.revoked', [
            'member_id' => (int) $member->getKey(),
            'owner_id' => $this->viewer()->getKey(),
            'name' => McpAbilities::TOKEN_NAME,
            'count' => 1,
            'via' => 'owner',
        ]);

        return $this->accountRedirect($member)->with('status', __('Access token revoked.'));
    }

    /**
     * The window runs from the proof rather than sliding forward with each token minted.
     */
    private function stampReauth(AiTokenRequest $request): void
    {
        if ($request->requiresPassword()) {
            AiTokenReauth::stamp($request->session());
        }
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

    private function groupAction(Request $request, Member $member, callable $action, string $status): RedirectResponse
    {
        Gate::authorize('manageAiAccount', $member);

        $back = $this->accountRedirect($member);

        try {
            $action();
        } catch (GroupActionException $e) {
            return $back->with('error', $this->groupMessageFor($e->reason));
        }

        return $back->with('status', $status);
    }

    private function accountRedirect(Member $member): RedirectResponse
    {
        return redirect()->route('member.config.ai.show', ['member' => $member->getKey()]);
    }

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
            AiAccountActionFailure::NotOwned, AiAccountActionFailure::MemberGone => __('That is not one of your AI accounts.'),
            AiAccountActionFailure::ActorFrozen => __('This AI account cannot be given a token right now.'),
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
