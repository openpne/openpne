<?php

namespace App\Features\Home;

use App\Compat\RouteParityRegistry;
use App\Features\Diary\Queries\DiaryCommentHistory;
use App\Features\Diary\Queries\ListFriendDiaries;
use App\Features\Diary\Queries\ListRecentDiaries;
use App\Features\Diary\Queries\RecentMemberDiaries;
use App\Features\DirectMessage\Queries\CountUnreadDirectMessages;
use App\Features\Group\Queries\PendingJoinRequestCounts;
use App\Features\Home\Actions\PublishDueHomeIssue;
use App\Features\Home\Queries\AdjacentHomeIssues;
use App\Features\Home\Queries\JoinedGroupActivity;
use App\Features\Home\Queries\ShowHomeIssue;
use App\Features\Home\Serializers\HomeIssueSerializer;
use App\Features\Home\Serializers\HomeSerializer;
use App\Features\Reactions\Queries\ReactionAggregates;
use App\Features\Timeline\Queries\HomeFeed;
use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Services\GadgetService;
use App\Services\SnsSettingService;
use App\Support\Feature;
use App\Support\ModernHome;
use App\Support\SnsSettingKey;
use App\Support\SurfaceResolver;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The OpenPNE 3 member/home portal at the canonical root: Modern renders the page the site's
 * `modern_home` names (docs/internals/home-issues.md, "Routes") and Classic the Classic home, whose
 * home gadgets take the viewer as their subject.
 */
class HomeController extends Controller
{
    /** Items shown per digest section on the Modern dashboard. */
    private const PREVIEW = 5;

    public function index(
        Request $request,
        GadgetService $gadgets,
        UnreadCounts $unread,
        CountUnreadDirectMessages $unreadMessages,
        PendingJoinRequestCounts $pendingApprovals,
        PublishDueHomeIssue $latest,
        ShowHomeIssue $show,
        AdjacentHomeIssues $adjacent,
        SnsSettingService $settings,
    ): View|RedirectResponse|Response {
        /** @var Member|null $viewer */
        $viewer = $request->user();
        if ($viewer === null) {
            return redirect('/login');
        }

        if (SurfaceResolver::resolve($request, 'home') === SurfaceResolver::MODERN) {
            return $settings->get(SnsSettingKey::ModernHome) === ModernHome::WhatsNew
                ? app()->call([$this, 'dashboard'])
                : $this->latest($request, $latest, $show, $adjacent);
        }

        return view('home.index', [
            'zones' => $gadgets->zones('home', $viewer, $viewer),
            'layout' => $gadgets->layoutLetter('home'),
            'pageId' => RouteParityRegistry::bodyId('home'),
            // Groups awaiting this member's admin-transfer decision (OpenPNE 3
            // _cautionAboutChangeAdminRequest), restored as a direct link to each group's banner.
            'adminTransferGroups' => Feature::Group->enabled()
                ? Group::where('pending_admin_member_id', $viewer->getKey())->get()
                : collect(),
            // OpenPNE 3 _cautionAboutCommunityMemberPre: one line per group the viewer administers.
            'pendingJoinGroups' => Feature::Group->enabled() ? $pendingApprovals($viewer) : collect(),
            // The friend-request caution is the header badge number, read from the same
            // request-scoped service the shell reads, so a caution and its badge cannot disagree.
            'unread' => $unread->for($viewer),
            // Counted apart from the Modern badge's conversations: this caution links into the
            // mailbox, where the number it announces is the rows waiting there.
            'unreadMessages' => Feature::DirectMessage->enabled() ? $unreadMessages($viewer) : 0,
        ]);
    }

    /** The latest issue, publishing the due one on the way (docs/internals/home-issues.md, "Publish on read"). */
    public function latest(Request $request, PublishDueHomeIssue $latest, ShowHomeIssue $show, AdjacentHomeIssues $adjacent): Response
    {
        /** @var Member $viewer */
        $viewer = $request->user();
        $now = CarbonImmutable::now();
        $issue = $latest($now);

        // Nothing published yet is not an absence to report: the site has a front page from its
        // first day, and that one simply has nothing on it.
        $page = $issue === null
            ? HomeIssueSerializer::page(null, null, null, null, $now)
            : HomeIssueSerializer::page(
                $issue,
                $show($viewer, $issue),
                $adjacent($issue)['previous'],
                // The latest issue is what this page IS, so nothing stands forward of it
                // however the run happens to be dated.
                null,
                $now,
            );

        return Inertia::render('home/issue', $page);
    }

    public function dashboard(
        Request $request,
        JoinedGroupActivity $groupActivity,
        DiaryCommentHistory $commented,
        UnreadCounts $unread,
        PendingJoinRequestCounts $pendingApprovals,
        ReactionAggregates $reactions,
    ): Response {
        /** @var Member $viewer */
        $viewer = $request->user();

        // Each digest belongs to a unit, so a switched-off one contributes an empty section and runs
        // no query — hiding it on the client would still ship the rows.
        $diaryOn = Feature::Diary->enabled();
        $groupOn = Feature::Group->enabled();
        $friendOn = Feature::Friend->enabled();

        $timeline = Feature::Timeline->enabled() ? (new HomeFeed)->take($viewer, self::PREVIEW) : collect();

        return Inertia::render('dashboard', HomeSerializer::dashboard(
            $viewer,
            $diaryOn && $friendOn ? (new ListFriendDiaries)->take($viewer, self::PREVIEW) : collect(),
            $diaryOn ? (new ListRecentDiaries)->take($viewer, self::PREVIEW) : collect(),
            $diaryOn ? $commented->summaries($viewer, self::PREVIEW) : collect(),
            $timeline,
            $groupActivity($viewer, self::PREVIEW),
            $diaryOn ? (new RecentMemberDiaries)($viewer, $viewer, self::PREVIEW) : collect(),
            $unread->for($viewer),
            $groupOn ? $pendingApprovals($viewer) : collect(),
            $groupOn ? Group::where('pending_admin_member_id', $viewer->getKey())->orderBy('id')->get() : collect(),
            // A switched-off unit counts as nothing joined, and with both off there is nothing to go find.
            ($friendOn || $groupOn)
                && ! ($friendOn && $viewer->friendships()->exists())
                && ! ($groupOn && $viewer->groupMemberships()->exists()),
            $reactions($viewer, TimelinePost::class, $timeline->map(fn (TimelinePost $post): int => (int) $post->getKey())->all()),
        ));
    }

    /** Rows shown on the community activity page — the dashboard digest's full "View all" target. */
    private const ACTIVITY_PAGE = 30;

    /** Modern-only, so it renders Inertia directly rather than resolving a surface. */
    public function groupActivity(Request $request, JoinedGroupActivity $activity): Response
    {
        /** @var Member $viewer */
        $viewer = $request->user();

        return Inertia::render('community/recent', [
            'activity' => $activity($viewer, self::ACTIVITY_PAGE)
                ->map([HomeSerializer::class, 'activityEntry'])
                ->all(),
        ]);
    }
}
