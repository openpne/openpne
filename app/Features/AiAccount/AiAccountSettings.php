<?php

declare(strict_types=1);

namespace App\Features\AiAccount;

use App\Features\AiAccount\Exceptions\AiAccountActionException;
use App\Features\AiAccount\Exceptions\AiAccountActionFailure;
use App\Models\Member;
use App\Services\SnsSettingService;
use App\Support\CapacityLock;
use App\Support\SnsSettingKey;
use Closure;

/**
 * Both are creation-time questions only: nothing here gates managing, deleting or revoking tokens
 * for an account that already exists.
 */
final class AiAccountSettings
{
    public function __construct(private readonly SnsSettingService $settings) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get(SnsSettingKey::AiAccountsEnabled);
    }

    public function limit(): int
    {
        return (int) $this->settings->get(SnsSettingKey::AiAccountLimit);
    }

    /** The site-wide cap the host set, or null for none (docs/internals/capacity.md, "AI account capacity"). */
    public function capacity(): ?int
    {
        $capacity = $this->settings->get(SnsSettingKey::AiAccountCapacity);

        return $capacity === null ? null : (int) $capacity;
    }

    public function inUse(): int
    {
        return Member::query()->whereNotNull('owner_member_id')->count();
    }

    public function capacityReached(): bool
    {
        $capacity = $this->capacity();

        return $capacity !== null && $this->inUse() >= $capacity;
    }

    /** The one answer every create affordance shows; the creation re-reads it under the locks. */
    public function canCreate(int $owned): bool
    {
        return $this->enabled() && $owned < $this->limit() && ! $this->capacityReached();
    }

    /**
     * Runs $body under the site cap's lock; $body calls the check it is handed right before its
     * write, after its own refusals, so those are answered first.
     *
     * @template T
     *
     * @param  Closure(Closure(): void): T  $body
     * @return T
     */
    public function reserve(Closure $body): mixed
    {
        return CapacityLock::hold(SnsSettingKey::AiAccountCapacity, fn (?int $capacity): mixed => $body(function () use ($capacity): void {
            if ($capacity !== null && $this->inUse() >= $capacity) {
                throw new AiAccountActionException(AiAccountActionFailure::SiteCapacityReached);
            }
        }));
    }

    /** Whether the member config offers its AI category: the offer is on, or the member already owns an account it must keep reaching. */
    public function availableTo(Member $member): bool
    {
        return $this->enabled() || $member->aiAccounts()->exists();
    }
}
