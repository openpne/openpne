<?php

declare(strict_types=1);

namespace App\Upgrade\Runner;

/**
 * What the state table allows this build to do, read before any write. `legacy_complete` is legacy
 * state with every phase checkpointed: adopted as a completion, never walked.
 */
final class ResumeVerdict
{
    public const FRESH = 'fresh';

    public const COMPLETED = 'completed';

    public const BLOCKED = 'blocked';

    public const LEGACY = 'legacy';

    public const LEGACY_COMPLETE = 'legacy_complete';

    public const OK = 'ok';

    public function __construct(
        public readonly string $verdict,
        public readonly ?string $reason = null,
        public readonly ?string $blockingPhase = null,
    ) {}
}
