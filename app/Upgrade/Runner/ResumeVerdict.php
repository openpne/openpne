<?php

declare(strict_types=1);

namespace App\Upgrade\Runner;

/**
 * What the state table allows this build to do, read before any write: `fresh` (no rows),
 * `completed` (the marker exists; nothing is run), `blocked` (another epoch or manifest; reason names
 * it), `legacy` (an epoch row from before manifests, resumed as is) or `ok`.
 */
final class ResumeVerdict
{
    public const FRESH = 'fresh';

    public const COMPLETED = 'completed';

    public const BLOCKED = 'blocked';

    public const LEGACY = 'legacy';

    public const OK = 'ok';

    public function __construct(
        public readonly string $verdict,
        public readonly ?string $reason = null,
        public readonly ?string $blockingPhase = null,
    ) {}

    public function allowsRun(): bool
    {
        return in_array($this->verdict, [self::FRESH, self::LEGACY, self::OK], true);
    }
}
