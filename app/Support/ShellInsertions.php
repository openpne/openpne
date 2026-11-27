<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AdminRoleInvite;
use Illuminate\Http\Request;

/**
 * Withholds everything the shells draw that operators or packages supply, on every page drawn at a
 * registration link of an address invited as administrator, error pages included (docs/internals/administrators.md, "The registration screen").
 */
final class ShellInsertions
{
    private const ATTRIBUTE = 'openpne.shell_insertions_withheld';

    public static function withheld(): bool
    {
        if (! app()->bound('request')) {
            return false;
        }

        $request = request();
        if (! $request->attributes->has(self::ATTRIBUTE)) {
            $request->attributes->set(self::ATTRIBUTE, self::atAdministratorInviteLink($request));
        }

        return $request->attributes->get(self::ATTRIBUTE) === true;
    }

    private static function atAdministratorInviteLink(Request $request): bool
    {
        $token = $request->routeIs('register.form', 'register.complete') ? $request->route('token') : null;

        return is_string($token) && AdminRoleInvite::liveForLink($token);
    }
}
