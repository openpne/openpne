<?php

declare(strict_types=1);

namespace App\Listeners\Auth;

use App\Auth\AdminEntry;
use App\Models\Member;
use App\Support\AdminRealm;
use App\Support\SecurityLog;
use Illuminate\Auth\Events\Login;

/**
 * Every member login fires `Login` only once its last proof has passed, so the panel does not ask
 * again right after one headed there; a login headed elsewhere leaves it asking
 * (docs/internals/administrators.md, "The panel's entry").
 */
class StampAdminEntry
{
    public function handle(Login $event): void
    {
        $intended = session('url.intended');

        if ($event->guard !== 'member' || ! $event->user instanceof Member || ! $event->user->isAdministrator()
            || ! is_string($intended) || ! AdminRealm::matchesUrl($intended)) {
            return;
        }

        AdminEntry::stamp(app('session.store'), $event->user);
        SecurityLog::event('admin.entered', ['member_id' => $event->user->getKey(), 'via' => 'login']);
    }
}
