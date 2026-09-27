<?php

declare(strict_types=1);

namespace App\Features\Home\Data;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/** A calendar month of issues, by `issue_date` (docs/internals/home-issues.md, "The month page"). */
final readonly class HomeIssueMonth
{
    /** @throws InvalidArgumentException for a month no calendar has, which a date constructor would roll into the next year */
    public function __construct(public int $year, public int $month)
    {
        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException("[{$month}] is not a month.");
        }
    }

    public static function of(DateTimeInterface $date): self
    {
        return new self((int) $date->format('Y'), (int) $date->format('n'));
    }

    public function first(): CarbonImmutable
    {
        return CarbonImmutable::create($this->year, $this->month, 1)->startOfDay();
    }

    public function last(): CarbonImmutable
    {
        return $this->first()->endOfMonth()->startOfDay();
    }

    public function href(): string
    {
        return sprintf('/home/%04d/%02d', $this->year, $this->month);
    }
}
