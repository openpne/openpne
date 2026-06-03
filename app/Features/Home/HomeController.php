<?php

namespace App\Features\Home;

use App\Compat\RouteParityRegistry;
use App\Http\Controllers\Controller;
use App\Support\SurfaceResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The OpenPNE 3 member/home portal lives at the canonical root (/). It resolves by surface:
 * a Modern-default install lands on the Inertia dashboard, a Classic-default one on the
 * Classic home. The gadget portal is not ported; this is a minimal Classic landing.
 */
class HomeController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        if ($request->user() === null) {
            return redirect('/login');
        }

        if (SurfaceResolver::resolve($request, 'home') === SurfaceResolver::MODERN) {
            return redirect('/dashboard');
        }

        return view('home.index', ['pageId' => RouteParityRegistry::bodyId('home')]);
    }
}
