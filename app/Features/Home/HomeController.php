<?php

namespace App\Features\Home;

use App\Compat\RouteParityRegistry;
use App\Features\Diary\Queries\ListRecentDiaries;
use App\Features\Diary\Queries\RecentMemberDiaries;
use App\Features\DirectMessage\Queries\CountUnreadDirectMessages;
use App\Features\Group\Queries\PendingJoinRequestCounts;
use App\Features\GroupTalk\Queries\JoinedTalkRooms;
use App\Features\Home\Queries\JoinedGroupActivity;
use App\Features\Home\Serializers\HomeSerializer;
use App\Features\Home\Serializers\UnifiedHomeSerializer;
use App\Features\Timeline\Queries\HomeFeed;
use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\Member;
use App\Services\GadgetService;
use App\Support\Feature;
use App\Support\LookResolver;
use App\Support\SurfaceResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The OpenPNE 3 member/home portal lives at the canonical root (/). It resolves by surface:
 * a Modern-default install lands on the Inertia dashboard, a Classic-default one on the Classic
 * home, which renders the admin-configured gadgets (the viewer is the home gadgets' subject).
 */
class HomeController extends Controller
{
    /** Items shown per digest section on the Modern dashboard. */
    private const PREVIEW = 5;

    public function index(Request $request, GadgetService $gadgets, UnreadCounts $unread, CountUnreadDirectMessages $unreadMessages): View|RedirectResponse
    {
        $viewer = $request->user();
        if ($viewer === null) {
            return redirect('/login');
        }

        if (SurfaceResolver::resolve($request, 'home') === SurfaceResolver::MODERN) {
            return redirect('/dashboard');
        }

        return view('home.index', [
            'zones' => $gadgets->zones('home', $viewer, $viewer),
            'layout' => $gadgets->layoutLetter('home'),
            'pageId' => RouteParityRegistry::bodyId('home'),
            // Groups awaiting this member's admin-transfer decision: the OpenPNE 3
            // _cautionAboutChangeAdminRequest, restored as a direct link to each community's banner
            // (Modern surfaces this through the feed + bell instead). Cheap: pending_admin_member_id is indexed.
            'adminTransferGroups' => Feature::Group->enabled()
                ? Group::where('pending_admin_member_id', $viewer->getKey())->get()
                : collect(),
            // The friend-request caution is the header badge number, read from the same
            // request-scoped service the shell reads, so a caution and its badge cannot disagree.
            'unread' => $unread->for($viewer),
            // Messages are counted separately: this caution links into the mailbox, where the number
            // it announces is the rows waiting there. The Modern badge counts conversations instead
            // (CountUnreadConversations), which is a different question about the same receipts.
            'unreadMessages' => Feature::DirectMessage->enabled() ? $unreadMessages($viewer) : 0,
        ]);
    }

    /**
     * The Modern-only landing (root redirects here under a Modern surface): a digest of the
     * viewer's conversations, the all-members diary feed, the timeline, their joined-community
     * activity, and their own recent diaries.
     */
    public function dashboard(
        Request $request,
        JoinedGroupActivity $groupActivity,
        JoinedTalkRooms $talkRooms,
        UnreadCounts $unread,
        PendingJoinRequestCounts $pendingApprovals,
    ): Response {
        /** @var Member $viewer */
        $viewer = $request->user();

        // The look swaps the page, not the route or the data sources. Both render calls stay string
        // literals: ChromeContextCoverageTest reads them to check every routed component is
        // classified.
        if (LookResolver::resolve($request)->usesUnifiedPages()) {
            return Inertia::render('unified/home', UnifiedHomeSerializer::page($viewer));
        }

        // Each digest belongs to a unit, so a switched-off one contributes an empty section and runs
        // no query — hiding it on the client would still ship the rows. JoinedGroupActivity
        // applies its own units (topics and events independently).
        $diaryOn = Feature::Diary->enabled();
        $groupOn = Feature::Group->enabled();

        return Inertia::render('dashboard', HomeSerializer::dashboard(
            $diaryOn ? (new ListRecentDiaries)->take($viewer, self::PREVIEW) : collect(),
            Feature::Timeline->enabled() ? (new HomeFeed)->take($viewer, self::PREVIEW) : collect(),
            $groupActivity($viewer, self::PREVIEW),
            $diaryOn ? (new RecentMemberDiaries)($viewer, $viewer, self::PREVIEW) : collect(),
            $unread->for($viewer),
            $groupOn ? $pendingApprovals($viewer) : collect(),
            Feature::GroupTalk->enabled() ? $talkRooms->take($viewer, self::PREVIEW) : collect(),
        ));
    }

    /** Rows shown on the community activity page — the dashboard digest's full "View all" target. */
    private const ACTIVITY_PAGE = 30;

    /**
     * The full cross-community activity view (the dashboard's community section, expanded): the
     * viewer's joined-community topics and events, merged newest-first. Modern-only, so it renders
     * Inertia directly like the dashboard.
     */
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
