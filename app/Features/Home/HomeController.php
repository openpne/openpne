<?php

namespace App\Features\Home;

use App\Compat\RouteParityRegistry;
use App\Features\Community\Queries\ListMemberCommunities;
use App\Features\Diary\Queries\ListRecentDiaries;
use App\Features\Home\Serializers\HomeSerializer;
use App\Features\Timeline\Queries\HomeFeed;
use App\Http\Controllers\Controller;
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

    public function index(Request $request, GadgetService $gadgets): View|RedirectResponse
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
        ]);
    }

    /**
     * The Modern-only landing (root redirects here under a Modern surface): a digest of the
     * timeline, recent diaries, and the viewer's communities.
     */
    public function dashboard(Request $request): Response
    {
        /** @var Member $viewer */
        $viewer = $request->user();

        return Inertia::render('dashboard', HomeSerializer::dashboard(
            (new HomeFeed)->take($viewer, self::PREVIEW),
            (new ListRecentDiaries)->take($viewer, self::PREVIEW),
            (new ListMemberCommunities)->take($viewer, self::PREVIEW),
        ));
    }
}
