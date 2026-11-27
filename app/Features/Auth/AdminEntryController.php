<?php

declare(strict_types=1);

namespace App\Features\Auth;

use App\Auth\AdminEntry;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AdminEntryRequest;
use App\Models\Member;
use App\Support\AdminRealm;
use App\Support\SecurityLog;
use App\Support\SurfaceResolver;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** See docs/internals/administrators.md, "The panel's entry". */
class AdminEntryController extends Controller
{
    public function show(Request $request): Response|View|RedirectResponse
    {
        $member = $request->user();
        abort_unless($member instanceof Member && $member->canAccessPanel(Filament::getPanel('admin')), 403);

        if (AdminEntry::holds($request->session(), $member)) {
            return $this->onward($request);
        }

        if (SurfaceResolver::resolve($request, 'auth') === SurfaceResolver::CLASSIC) {
            return view('auth.admin-entry');
        }

        // A plain form, not an Inertia visit: the answer is a panel page, which loads in full.
        return Inertia::render('auth/admin-entry', ['csrfToken' => csrf_token()]);
    }

    public function store(AdminEntryRequest $request): RedirectResponse
    {
        $member = $request->user();
        assert($member instanceof Member);

        AdminEntry::stamp($request->session(), $member);
        $request->session()->regenerate(true);

        SecurityLog::event('admin.entered', [
            'member_id' => $member->getKey(),
            'via' => $request->reauthProof()->viaPasskey ? 'passkey' : 'password',
        ]);

        return $this->onward($request);
    }

    private function onward(Request $request): RedirectResponse
    {
        $intended = $request->session()->pull('url.intended');

        return redirect()->to(is_string($intended) && AdminRealm::matchesUrl($intended) ? $intended : Filament::getPanel('admin')->getUrl());
    }
}
