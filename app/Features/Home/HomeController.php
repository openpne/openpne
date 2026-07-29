<?php

namespace App\Features\Home;

use App\Compat\RouteParityRegistry;
use App\Features\Community\Queries\PendingJoinRequestCounts;
use App\Features\Diary\Queries\ListRecentDiaries;
use App\Features\Diary\Queries\RecentMemberDiaries;
use App\Features\Home\Queries\JoinedCommunityActivity;
use App\Features\Home\Serializers\HomeSerializer;
use App\Features\Timeline\Queries\HomeFeed;
use App\Http\Controllers\Controller;
use App\Models\Community;
use App\Models\Member;
use App\Services\GadgetService;
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

    public function index(Request $request, GadgetService $gadgets, UnreadCounts $unread): View|RedirectResponse
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
            // Communities awaiting this member's admin-transfer decision: the OpenPNE 3
            // _cautionAboutChangeAdminRequest, restored as a direct link to each community's banner
            // (Modern surfaces this through the feed + bell instead). Cheap: pending_admin_member_id is indexed.
            'adminTransferCommunities' => Community::where('pending_admin_member_id', $viewer->getKey())->get(),
            // The remaining cautions are the header badge numbers, read from the same request-scoped
            // service the shell reads, so a caution and its badge can never disagree.
            'unread' => $unread->for($viewer),
        ]);
    }

    /**
     * The Modern-only landing (root redirects here under a Modern surface): a digest of the
     * all-members diary feed, the timeline, the viewer's joined-community activity, and their own
     * recent diaries.
     */
    public function dashboard(
        Request $request,
        JoinedCommunityActivity $communityActivity,
        UnreadCounts $unread,
        PendingJoinRequestCounts $pendingApprovals,
    ): Response {
        /** @var Member $viewer */
        $viewer = $request->user();

        return Inertia::render('dashboard', HomeSerializer::dashboard(
            (new ListRecentDiaries)->take($viewer, self::PREVIEW),
            (new HomeFeed)->take($viewer, self::PREVIEW),
            $communityActivity($viewer, self::PREVIEW),
            (new RecentMemberDiaries)($viewer, $viewer, self::PREVIEW),
            $unread->for($viewer),
            $pendingApprovals($viewer),
        ));
    }

    /** Rows shown on the community activity page — the dashboard digest's full "View all" target. */
    private const ACTIVITY_PAGE = 30;

    /**
     * The full cross-community activity view (the dashboard's community section, expanded): the
     * viewer's joined-community topics and events, merged newest-first. Modern-only, so it renders
     * Inertia directly like the dashboard.
     */
    public function communityActivity(Request $request, JoinedCommunityActivity $activity): Response
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
