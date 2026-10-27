<?php

declare(strict_types=1);

namespace App\Upgrade\Runner;

use App\Support\ReleaseMarker;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use JsonSerializable;
use Throwable;

/**
 * The run's output and its record in one object: every typed call prints the human line through the
 * sink and records the event the JSON report serialises, so a line that bypasses it is invisible to
 * --json (docs/internals/upgrade.md, "Run report"). A null sink records without printing.
 */
final class RunReport implements JsonSerializable
{
    public const OUTCOME_OK = 'ok';

    public const OUTCOME_PRECHECK_ERROR = 'precheck_error';

    public const OUTCOME_BLOCKED = 'blocked';

    public const OUTCOME_FAILED = 'failed_at_step';

    private const RESUMED_REASON = 'already completed';

    /** @var list<string> */
    private array $errors = [];

    /** @var list<array{message: string, code: null, category: null, count: null}> */
    private array $warnings = [];

    /** @var array<string, string> */
    private array $envHints = [];

    /** @var list<string> */
    private array $notes = [];

    /** @var list<string> */
    private array $nextSteps = [];

    /** @var array<string, array{key: string, status: string, rows: ?int, elapsed_ms: ?int, reason: ?string, error: ?string}> */
    private array $steps = [];

    /** @var array<string, float> start hrtime per key, in nanoseconds */
    private array $startedAt = [];

    private ?string $outcome = null;

    private ?string $failedStep = null;

    private bool $resumed = false;

    private readonly float $startedNs;

    private readonly string $startedAtIso;

    private ?string $finishedAtIso = null;

    private ?int $elapsedMs = null;

    public function __construct(
        private readonly ?Closure $sink = null,
        private readonly bool $verbose = false,
        private readonly bool $dryRun = false,
    ) {
        $this->startedNs = hrtime(true);
        $this->startedAtIso = now()->toIso8601String();
    }

    /** The one-line message for a failure: a QueryException's PDO message, without the SQL and bindings the wrapper appends. */
    public static function failureMessage(Throwable $e): string
    {
        $cause = $e instanceof QueryException ? $e->getPrevious() : null;

        return self::oneLine(($cause ?? $e)->getMessage());
    }

    public function error(string $message): void
    {
        $this->errors[] = $message;
        $this->emit("ERROR {$message}");
    }

    public function warning(string $message): void
    {
        $this->warnings[] = ['message' => $message, 'code' => null, 'category' => null, 'count' => null];
        $this->emit("WARN {$message}");
    }

    public function envHint(string $key, string $value): void
    {
        $this->envHints[$key] = $value;
        $this->emit("ENV_HINT {$key}={$value}");
    }

    public function note(string $message): void
    {
        $this->notes[] = $message;
        $this->emit("NOTE {$message}");
    }

    public function nextStep(string $message): void
    {
        $this->nextSteps[] = $message;
        $this->emit($message);
    }

    public function planned(string $key): void
    {
        $this->step($key, 'planned');
        $this->emit("PLAN {$key}:");
    }

    public function started(string $key): void
    {
        $this->startedAt[$key] = hrtime(true);
        $this->step($key, 'running');
        $this->emit("RUN {$key}");
    }

    public function done(string $key, string $detail, ?int $rows = null): void
    {
        $elapsed = $this->elapsed($key);
        $this->step($key, 'done', rows: $rows, elapsedMs: $elapsed);
        $this->emit("DONE {$key}: {$detail}".self::suffix($elapsed));
    }

    public function skipped(string $key, string $reason): void
    {
        if ($reason === self::RESUMED_REASON) {
            $this->resumed = true;
        }
        $this->step($key, 'skipped', reason: $reason);
        $this->emit("SKIP {$key}: {$reason}");
    }

    public function progress(string $key, string $detail): void
    {
        $this->emit("WRAP {$key}: {$detail}");
    }

    /**
     * Records the failure, prints its one line, then — only under verbose and with a cause — throws
     * the cause: the caller's checkpoint and line are already written, which is what makes the
     * rethrow safe to put here and nowhere else.
     */
    public function failed(string $key, string $message, ?Throwable $cause = null): void
    {
        $message = self::oneLine($message);
        $this->step($key, 'failed', elapsedMs: $this->elapsed($key), error: $message);
        $this->outcome = self::OUTCOME_FAILED;
        $this->failedStep = $key;
        $this->emit("FAIL {$key}: {$message}");

        if ($this->verbose && $cause !== null) {
            throw $cause;
        }
    }

    public function abort(string $message, string $outcome = self::OUTCOME_PRECHECK_ERROR): void
    {
        $this->outcome = $outcome;
        $this->emit("Aborted: {$message}");
    }

    public function line(string $raw): void
    {
        $this->emit($raw);
    }

    public function finish(bool $ok): void
    {
        $this->elapsedMs = (int) round((hrtime(true) - $this->startedNs) / 1e6);
        $this->finishedAtIso = now()->toIso8601String();
        $this->outcome ??= $ok ? self::OUTCOME_OK : self::OUTCOME_FAILED;

        if ($ok) {
            $this->emit('Finished in '.self::seconds($this->elapsedMs).'s');
        }
    }

    public function isFailed(): bool
    {
        return $this->failedStep !== null;
    }

    public function outcome(): ?string
    {
        return $this->outcome;
    }

    public function toJson(): string
    {
        return json_encode($this, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'site' => rescue(static fn (): string => DB::connection()->getDatabaseName(), report: false),
            'release' => rescue(static fn (): ?string => ReleaseMarker::sha(), report: false),
            'dry_run' => $this->dryRun,
            'resumed' => $this->resumed,
            'started_at' => $this->startedAtIso,
            'finished_at' => $this->finishedAtIso,
            'elapsed_ms' => $this->elapsedMs,
            'outcome' => $this->outcome,
            'failed_step' => $this->failedStep,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'env_hints' => (object) $this->envHints,
            'notes' => $this->notes,
            'next_steps' => $this->nextSteps,
            'steps' => array_values($this->steps),
        ];
    }

    private function step(string $key, string $status, ?int $rows = null, ?int $elapsedMs = null, ?string $reason = null, ?string $error = null): void
    {
        $this->steps[$key] = ['key' => $key, 'status' => $status, 'rows' => $rows, 'elapsed_ms' => $elapsedMs, 'reason' => $reason, 'error' => $error];
    }

    private function elapsed(string $key): ?int
    {
        if (! isset($this->startedAt[$key])) {
            return null;
        }

        $ms = (int) round((hrtime(true) - $this->startedAt[$key]) / 1e6);
        unset($this->startedAt[$key]);

        return $ms;
    }

    private static function suffix(?int $elapsedMs): string
    {
        return $elapsedMs === null ? '' : ' ('.self::seconds($elapsedMs).'s)';
    }

    private static function seconds(int $ms): string
    {
        return number_format($ms / 1000, 1, '.', '');
    }

    private static function oneLine(string $message): string
    {
        return trim((string) preg_replace('/[\s\x00-\x1F\x7F]+/', ' ', $message));
    }

    private function emit(string $line): void
    {
        if ($this->sink !== null) {
            ($this->sink)($line);
        }
    }
}
