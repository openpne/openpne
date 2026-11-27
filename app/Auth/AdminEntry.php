<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\Member;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Auth;

/**
 * The session's mark that the administrator proved who they are on the way into the admin panel
 * (docs/internals/administrators.md, "The panel's entry"). It stands only for the member, role
 * generation and password it was stamped with.
 */
final class AdminEntry
{
    public const SESSION_KEY = 'admin_entry';

    /** Call only once the member has just proven who they are. */
    public static function stamp(Session $session, Member $member): void
    {
        $session->put(self::SESSION_KEY, self::mark($member));
    }

    public static function holds(Session $session, Member $member): bool
    {
        $mark = $session->get(self::SESSION_KEY);
        if (! is_array($mark) || ! is_int($mark['at'] ?? null) || ! is_string($mark['credential'] ?? null)) {
            return false;
        }

        $current = self::mark($member);

        return ($mark['member_id'] ?? null) === $current['member_id']
            && ($mark['generation'] ?? null) === $current['generation']
            && hash_equals($current['credential'], $mark['credential'])
            && now()->getTimestamp() - $mark['at'] <= (int) config('session.lifetime') * 60;
    }

    /** holds(), and then the idle period starts again. */
    public static function admit(Session $session, Member $member): bool
    {
        if (! self::holds($session, $member)) {
            return false;
        }

        $session->put(self::SESSION_KEY.'.at', now()->getTimestamp());

        return true;
    }

    /** @return array{member_id: mixed, generation: int, credential: string, at: int} */
    public static function mark(Member $member): array
    {
        return [
            'member_id' => $member->getKey(),
            'generation' => (int) $member->admin_generation,
            'credential' => Auth::guard('member')->hashPasswordForCookie((string) $member->getAuthPassword()),
            'at' => now()->getTimestamp(),
        ];
    }
}
