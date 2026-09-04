<?php

namespace App\Auth;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Database\Eloquent\Model;

/**
 * An AI account (members.owner_member_id) reaches the site only through a personal access token, so
 * it is excluded in the one query every guard lookup builds on, in SQL so the row is never hydrated
 * into an Authenticatable. Sanctum resolves a bearer token's holder through the polymorphic
 * tokenable, not through this provider, so an AI account's own tokens are unaffected.
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
