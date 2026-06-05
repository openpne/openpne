<?php

namespace App\Http\Controllers;

use App\Models\Member;
use Illuminate\Support\Facades\Gate;

abstract class Controller
{
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

        return $subject;
    }
}
