<?php

namespace App\Http\Controllers;

use App\Models\Community;
use App\Models\Member;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

abstract class Controller
{
    /** The authenticated member behind the request; the member auth guard on these routes guarantees it. */
    protected function viewer(): Member
    {
        $viewer = auth()->user();
        assert($viewer instanceof Member);

        return $viewer;
    }

    /** The uniform redirect-and-flash for a form submit. */
    protected function redirectAfterSubmit(string $canonicalName, ?string $status = null, ?string $error = null): RedirectResponse
    {
        $redirect = redirect()->route($canonicalName);
        if ($status !== null) {
            $redirect = $redirect->with('status', $status);
        }
        if ($error !== null) {
            $redirect = $redirect->with('error', $error);
        }

        return $redirect;
    }

    /**
     * Resolve the member a member-scoped page is about (its OpenPNE 3 localNav `friend`
     * subject), and deny the whole page (404) when that member has blocked the viewer. Pass
     * null for a self-scoped page; the viewer becomes the subject. Centralises the block gate
     * so every owner page responds uniformly instead of some 404-ing and others rendering an
     * empty body.
     */
    protected function memberSubject(?Member $subject): Member
    {
        $subject ??= auth()->user();
        abort_if($subject === null, 404);
        Gate::authorize('access', $subject);
        $this->markLocalNavSubject($subject);

        return $subject;
    }

    /**
     * Record the member a page is about so the Classic localNav renders OpenPNE 3's `friend`
     * context (the subject's id-scoped Home/Diary/Friends) instead of the viewer's `default`
     * nav. Only a member other than the viewer is recorded — a self page keeps the default nav,
     * matching OpenPNE 3 (sf_nav_type stays `default` when the subject id equals the viewer's).
     */
    protected function markLocalNavSubject(Member $subject): void
    {
        $viewer = auth()->user();
        if ($viewer !== null && ! $viewer->is($subject)) {
            request()->attributes->set('localNavSubject', $subject);
        }
    }

    /**
     * Record the community a page is about so the Classic localNav renders OpenPNE 3's `community`
     * context (the community's id-scoped Top / Topics / Events / Join / Leave links) instead of the
     * viewer's `default` nav. OpenPNE 3 community module default_nav=community; the search and
     * member-community-list actions, which are not about one community, keep the default nav.
     */
    protected function markLocalNavCommunity(Community $community): void
    {
        request()->attributes->set('localNavCommunity', $community);
    }
}
