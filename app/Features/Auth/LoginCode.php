<?php

namespace App\Features\Auth;

use App\Models\Member;
use App\Notifications\Auth\LoginCodeNotification;
use App\Support\SecurityLog;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/** See docs/internals/security.md, "Member passkeys". */
class LoginCode
{
    /** Distinct from Fortify's `login.id`, so neither challenge's POST accepts the other's slot. */
    public const SLOT = 'login.code';

    public const TTL_MINUTES = 10;

    private const MAX_ATTEMPTS = 5;

    private const ISSUES_PER_HOUR = 5;

    /** False when the member's hourly budget is spent; nothing is stored or mailed then. */
    public function issue(Session $session, Member $member, bool $remember): bool
    {
        if (RateLimiter::hit('login-code-issue|'.$member->getKey(), 3600) > self::ISSUES_PER_HOUR) {
            return false;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $session->forget('login.id');
        $session->put(self::SLOT, [
            'member_id' => $member->getKey(),
            'remember' => $remember,
            'nonce' => Str::random(40),
            'hash' => $this->mac($code),
            'password' => $this->mac((string) $member->password),
            'email' => $this->mac((string) $member->email),
            'expires_at' => now()->addMinutes(self::TTL_MINUTES)->getTimestamp(),
        ]);

        SecurityLog::event('login.code_sent', ['guard' => 'member', 'member_id' => $member->getKey()]);

        // Pinned to the address read now: the notification queues, and a Member notifiable resolved
        // at send time would follow an address changed meanwhile.
        Notification::route('mail', $member->email)
            ->notify(new LoginCodeNotification($code, self::TTL_MINUTES, $member->locale ?? app()->getLocale()));

        return true;
    }

    /** @return array{member_id: int, remember: bool, nonce: string, hash: string, password: string, email: string, expires_at: int}|null */
    public function pending(Session $session): ?array
    {
        $slot = $session->get(self::SLOT);

        if (! is_array($slot) || ! isset($slot['member_id'], $slot['nonce'], $slot['hash'], $slot['password'], $slot['email'], $slot['expires_at'])) {
            return null;
        }

        if ($slot['expires_at'] < now()->getTimestamp()) {
            $session->forget(self::SLOT);

            return null;
        }

        return $slot;
    }

    /**
     * Null on any refusal; the slot survives only a plain wrong code with attempts left. The attempt
     * is counted in the cache before the comparison, because a counter in the session rewinds with a
     * replayed cookie.
     */
    public function verify(Session $session, string $code): ?Member
    {
        $slot = $this->pending($session);

        if ($slot === null) {
            return null;
        }

        $attempts = RateLimiter::hit('login-code-attempt|'.$slot['member_id'].'|'.$slot['nonce'], self::TTL_MINUTES * 60);

        if ($attempts > self::MAX_ATTEMPTS) {
            $session->forget(self::SLOT);

            return null;
        }

        $member = $this->challenged($slot);

        if ($member === null) {
            $session->forget(self::SLOT);

            return null;
        }

        if (! hash_equals($slot['hash'], $this->mac($code))) {
            if ($attempts >= self::MAX_ATTEMPTS) {
                $session->forget(self::SLOT);
            }

            return null;
        }

        $session->forget(self::SLOT);

        return $member;
    }

    /**
     * Replaces the pending code. Null when the challenge no longer stands, false when the member's
     * hourly budget is spent.
     */
    public function reissue(Session $session): ?bool
    {
        $slot = $this->pending($session);
        $member = $slot === null ? null : $this->challenged($slot);

        if ($member === null) {
            $session->forget(self::SLOT);

            return null;
        }

        return $this->issue($session, $member, (bool) $slot['remember']);
    }

    /**
     * The member the slot still stands for. Password and address are re-read because a guest session
     * outlives the revocation their change performs, and a factor confirmed since must take the TOTP
     * challenge instead.
     *
     * @param  array{member_id: int, password: string, email: string}  $slot
     */
    private function challenged(array $slot): ?Member
    {
        $member = Member::find($slot['member_id']);

        if (! $member || $member->is_login_rejected || $member->isAiAccount() || $member->hasEnabledTwoFactorAuthentication()) {
            return null;
        }

        return hash_equals($slot['password'], $this->mac((string) $member->password))
            && hash_equals($slot['email'], $this->mac((string) $member->email)) ? $member : null;
    }

    private function mac(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }
}
