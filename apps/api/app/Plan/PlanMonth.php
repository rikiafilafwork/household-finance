<?php

namespace App\Plan;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Bulan rencana: `YYYY-MM` di URL/API, tanggal 1 di database (`plan_items.month`, chk_plan_month).
 */
final readonly class PlanMonth
{
    public const ROUTE_PATTERN = '\d{4}-(0[1-9]|1[0-2])';

    private function __construct(public CarbonImmutable $start) {}

    public static function parse(string $yearMonth): self
    {
        if (preg_match('/^'.self::ROUTE_PATTERN.'$/', $yearMonth) !== 1) {
            throw new InvalidArgumentException("Bulan tidak valid: {$yearMonth}");
        }

        return new self(CarbonImmutable::createFromFormat('!Y-m-d', "{$yearMonth}-01"));
    }

    public static function fromDate(\DateTimeInterface $date): self
    {
        return new self(CarbonImmutable::instance($date)->startOfMonth()->startOfDay());
    }

    /** Tanggal 1, `YYYY-MM-DD` (kolom `month`). */
    public function firstDay(): string
    {
        return $this->start->toDateString();
    }

    /** Hari terakhir bulan, `YYYY-MM-DD`. */
    public function lastDay(): string
    {
        return $this->start->endOfMonth()->toDateString();
    }

    public function key(): string
    {
        return $this->start->format('Y-m');
    }

    public function previous(): self
    {
        return new self($this->start->subMonthNoOverflow());
    }

    /** Tanggal ke-$day di bulan ini, dibatasi hari terakhir (31 → 28/29 Feb). Lihat MonthlyDueDate. */
    public function day(int $day): string
    {
        return MonthlyDueDate::in($this, $day);
    }

    public function contains(string $date): bool
    {
        return $date >= $this->firstDay() && $date <= $this->lastDay();
    }
}
