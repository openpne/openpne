<?php

namespace App\Features\Home;

use App\Compat\RouteParityRegistry;
use App\Http\Controllers\Controller;
use App\Services\GadgetService;
use App\Support\SurfaceResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The OpenPNE 3 member/home portal lives at the canonical root (/). It resolves by surface:
 * a Modern-default install lands on the Inertia dashboard, a Classic-default one on the Classic
 * home, which renders the admin-configured gadgets (the viewer is the home gadgets' subject).
 */
class HomeController extends Controller
{
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
            'pageId' => RouteParityRegistry::bodyId('home'),
        ]);
    }
}
