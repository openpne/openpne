<?php

namespace App\Auth;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Database\Eloquent\Model;

/**
 * The `members` provider, narrowed to the rows that may hold a login: an AI account
 * (members.owner_member_id) reaches the site only through a personal access token its owner mints,
 * so it is invisible to every lookup an authentication guard makes.
 *
 * Said once in newModelQuery() — the one query the inherited retrieveById(), retrieveByToken() and
 * retrieveByCredentials() are all built on — because a session id, a remember-me cookie and a
 * credential lookup are three separate ways into the same row, and a refusal spelled out per method
 * is a refusal that can be forgotten on the next one the framework adds. In SQL rather than an
 * instanceof after hydration: the row is never fetched, so an AI account never exists as an
 * Authenticatable for a caller to mistake for a logged-in member.
 *
 * Sanctum resolves a bearer token's holder through the polymorphic `tokenable`, not through a user
 * provider, so an AI account's own tokens are untouched — which is the whole point of the account.
 */
class MemberUserProvider extends EloquentUserProvider
{
    /**
     * @param  Model|null  $model
     * @return \Illuminate\Database\Eloquent\Builder<*>
     */
    protected function newModelQuery($model = null)
    {
        return parent::newModelQuery($model)->whereNull('owner_member_id');
    }
}
