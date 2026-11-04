<?php

declare(strict_types=1);

namespace App\Features\Auth;

use App\Models\Member;
use App\Models\RegistrationToken;
use App\Services\SnsSettingService;
use App\Support\CapacityLock;
use App\Support\SnsSettingKey;
use Closure;

/**
 * Seats are members who are not AI accounts plus the registration links still live, so an invitation
 * holds its seat until it is used or expires (docs/internals/capacity.md, "Member capacity").
 */
final class MemberCapacity
{
    public const LOW_WATER = 5;

    public function __construct(private readonly SnsSettingService $settings) {}

    public function limit(): ?int
    {
        $limit = $this->settings->get(SnsSettingKey::MemberCapacity);

        return $limit === null ? null : (int) $limit;
    }

    public function members(): int
    {
        return Member::query()->whereNull('owner_member_id')->count();
    }

    public function pending(): int
    {
        return RegistrationToken::query()
            ->where('created_at', '>', now()->subMinutes((int) config('openpne.registration.token_ttl_minutes')))
            ->count();
    }

    public function remaining(): ?int
    {
        $limit = $this->limit();

        return $limit === null ? null : max(0, $limit - $this->members() - $this->pending());
    }

    /** Whether a new registration link may be issued. */
    public function isFull(): bool
    {
        return $this->remaining() === 0;
    }

    /**
     * Whether a live link may complete: its own seat is already in pending(), so only members count.
     * OpenPNE 3 also counted the invitee's own pre-member row here and so refused the last seat.
     */
    public function isFullForCompletion(): bool
    {
        $limit = $this->limit();

        return $limit !== null && $this->members() >= $limit;
    }

    public function state(): CapacityState
    {
        $limit = $this->limit();
        if ($limit === null) {
            return CapacityState::Unlimited;
        }

        $taken = $this->members() + $this->pending();

        return match (true) {
            $taken > $limit => CapacityState::Over,
            $taken === $limit => CapacityState::Full,
            $limit - $taken <= self::LOW_WATER => CapacityState::Low,
            default => CapacityState::Ok,
        };
    }

    /**
     * Issues a link under the lock; $resend skips the seat check because the address already holds one.
     *
     * @template T
     *
     * @param  Closure(): T  $write
     * @return T
     *
     * @throws MemberCapacityReached
     */
    public function reserveToken(Closure $write, bool $resend = false): mixed
    {
        if ($resend || $this->limit() === null) {
            return $write();
        }

        return CapacityLock::hold(SnsSettingKey::MemberCapacity, function (?int $limit) use ($write): mixed {
            if ($limit !== null && $this->members() + $this->pending() >= $limit) {
                throw new MemberCapacityReached;
            }

            return $write();
        });
    }

    /**
     * Creates a member under the lock.
     *
     * @template T
     *
     * @param  Closure(): T  $write
     * @return T
     *
     * @throws MemberCapacityReached
     */
    public function reserveMember(Closure $write): mixed
    {
        if ($this->limit() === null) {
            return $write();
        }

        return CapacityLock::hold(SnsSettingKey::MemberCapacity, function (?int $limit) use ($write): mixed {
            if ($limit !== null && $this->members() >= $limit) {
                throw new MemberCapacityReached;
            }

            return $write();
        });
    }
}
