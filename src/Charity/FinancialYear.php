<?php

declare(strict_types=1);

namespace CharityCashbook\Charity;

/**
 * Financial-year and UK tax-year date helpers.
 *
 * All dates are 'Y-m-d' strings and comparisons are string comparisons,
 * so there is no time-of-day component to get wrong.
 */
final class FinancialYear
{
    /**
     * Year end for the financial year that contains $date.
     */
    public static function endFor(array $charity, string $date): string
    {
        [$month, $day] = self::yearEnd($charity);
        $year = (int) substr($date, 0, 4);

        $endThisYear = self::makeDate($year, $month, $day);
        if ($date <= $endThisYear) {
            return $endThisYear;
        }

        return self::makeDate($year + 1, $month, $day);
    }

    /**
     * Start of the financial year that contains $date.
     */
    public static function startFor(array $charity, string $date): string
    {
        $end = self::endFor($charity, $date);
        [$month, $day] = self::yearEnd($charity);
        $previousEnd = self::makeDate((int) substr($end, 0, 4) - 1, $month, $day);

        return self::addDays($previousEnd, 1);
    }

    /**
     * Start of the current financial year.
     */
    public static function currentStart(array $charity, ?string $today = null): string
    {
        return self::startFor($charity, $today ?? date('Y-m-d'));
    }

    /**
     * End of the current financial year.
     */
    public static function currentEnd(array $charity, ?string $today = null): string
    {
        return self::endFor($charity, $today ?? date('Y-m-d'));
    }

    /**
     * End of the most recent financial year that has fully ended before $today.
     */
    public static function lastCompletedEnd(array $charity, ?string $today = null): string
    {
        $start = self::currentStart($charity, $today);

        return self::addDays($start, -1);
    }

    /**
     * End of the financial year before the one ending on $yearEnd.
     */
    public static function previousEnd(array $charity, string $yearEnd): string
    {
        return self::addDays(self::startFor($charity, $yearEnd), -1);
    }

    /**
     * UK tax year (starting 6 April) that contains $date, identified by its starting calendar year.
     */
    public static function taxYear(string $date): int
    {
        $year = (int) substr($date, 0, 4);

        return substr($date, 5) < '04-06' ? $year - 1 : $year;
    }

    /**
     * Bounds of the UK tax year that contains $date.
     *
     * @return array{0: string, 1: string} [start, end]
     */
    public static function taxYearBounds(string $date): array
    {
        $year = self::taxYear($date);

        return [sprintf('%04d-04-06', $year), sprintf('%04d-04-05', $year + 1)];
    }

    /**
     * Whether a string is a valid 'Y-m-d' date.
     */
    public static function isValidDate(mixed $date): bool
    {
        if (!is_string($date)) {
            return false;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $d !== false && $d->format('Y-m-d') === $date;
    }

    public static function addDays(string $date, int $days): string
    {
        return (new \DateTimeImmutable($date))->modify(sprintf('%+d days', $days))->format('Y-m-d');
    }

    /**
     * @return array{0: int, 1: int} [month, day]
     */
    private static function yearEnd(array $charity): array
    {
        $month = (int) ($charity['year_end_month'] ?? 3);
        $day = (int) ($charity['year_end_day'] ?? 31);

        if ($month < 1 || $month > 12) {
            $month = 3;
        }

        return [$month, max(1, $day)];
    }

    /**
     * Build a date, clamping the day to the end of the month (e.g. 29 Feb in a non-leap year).
     */
    private static function makeDate(int $year, int $month, int $day): string
    {
        $lastDay = (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');

        return sprintf('%04d-%02d-%02d', $year, $month, min($day, $lastDay));
    }
}
