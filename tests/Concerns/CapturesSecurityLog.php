<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Level;

/**
 * Captures writes to the `security` log channel (and, when needed, the default channel) by swapping
 * the resolved channel's Monolog handlers for an in-memory TestHandler — no file I/O, and other
 * channels keep working. Each test refreshes the application, so the swap is per test.
 */
trait CapturesSecurityLog
{
    protected ?TestHandler $securityLogHandler = null;

    protected ?TestHandler $defaultLogHandler = null;

    protected function captureSecurityLog(): void
    {
        $this->securityLogHandler = new TestHandler;
        Log::channel('security')->getLogger()->setHandlers([$this->securityLogHandler]);
    }

    /** The default channel, so a test can assert an event did NOT also land there. */
    protected function captureDefaultLog(): void
    {
        $this->defaultLogHandler = new TestHandler;
        Log::channel((string) config('logging.default'))->getLogger()->setHandlers([$this->defaultLogHandler]);
    }

    /**
     * @return array<int, array{message: string, context: array<string, mixed>, level: Level}>
     */
    protected function securityRecords(?string $event = null): array
    {
        $records = array_map(
            fn ($record): array => [
                'message' => $record->message,
                'context' => $record->context,
                'level' => $record->level,
            ],
            $this->securityLogHandler?->getRecords() ?? [],
        );

        return $event === null
            ? $records
            : array_values(array_filter($records, fn (array $record): bool => $record['message'] === $event));
    }

    /** Exactly one security record for $event; returns its context. */
    protected function assertOneSecurityEvent(string $event): array
    {
        $matches = $this->securityRecords($event);
        $this->assertCount(1, $matches, "expected exactly one [{$event}] security record");
        $this->assertSame(Level::Info, $matches[0]['level']);

        return $matches[0]['context'];
    }
}
