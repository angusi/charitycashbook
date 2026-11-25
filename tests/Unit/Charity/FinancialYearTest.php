<?php

declare(strict_types=1);

namespace CharityCashbook\Tests\Unit\Charity;

use CharityCashbook\Charity\FinancialYear;
use PHPUnit\Framework\TestCase;

class FinancialYearTest extends TestCase
{
    private const MARCH_YEAR_END = ['year_end_month' => 3, 'year_end_day' => 31];
    private const DEC_YEAR_END = ['year_end_month' => 12, 'year_end_day' => 31];

    public function testCurrentStartBeforeYearEnd(): void
    {
        $this->assertSame('2025-04-01', FinancialYear::currentStart(self::MARCH_YEAR_END, '2026-02-10'));
        $this->assertSame('2026-03-31', FinancialYear::currentEnd(self::MARCH_YEAR_END, '2026-02-10'));
    }

    public function testYearEndDayItselfIsInTheEndingYear(): void
    {
        // Regression: on the year-end day the old code returned tomorrow as the start
        $this->assertSame('2025-04-01', FinancialYear::currentStart(self::MARCH_YEAR_END, '2026-03-31'));
        $this->assertSame('2026-03-31', FinancialYear::currentEnd(self::MARCH_YEAR_END, '2026-03-31'));
    }

    public function testDayAfterYearEndStartsNewYear(): void
    {
        $this->assertSame('2026-04-01', FinancialYear::currentStart(self::MARCH_YEAR_END, '2026-04-01'));
        $this->assertSame('2027-03-31', FinancialYear::currentEnd(self::MARCH_YEAR_END, '2026-04-01'));
    }

    public function testCalendarYearEnd(): void
    {
        $this->assertSame('2026-01-01', FinancialYear::currentStart(self::DEC_YEAR_END, '2026-11-25'));
        $this->assertSame('2026-12-31', FinancialYear::currentEnd(self::DEC_YEAR_END, '2026-11-25'));
    }

    public function testLeapDayYearEndClampsInNonLeapYears(): void
    {
        $charity = ['year_end_month' => 2, 'year_end_day' => 29];
        $this->assertSame('2027-02-28', FinancialYear::currentEnd($charity, '2026-11-25'));
        $this->assertSame('2026-03-01', FinancialYear::currentStart($charity, '2026-11-25'));
        $this->assertSame('2028-02-29', FinancialYear::currentEnd($charity, '2027-12-01'));
    }

    public function testLastCompletedAndPreviousEnd(): void
    {
        $this->assertSame('2026-03-31', FinancialYear::lastCompletedEnd(self::MARCH_YEAR_END, '2026-11-25'));
        $this->assertSame('2025-03-31', FinancialYear::previousEnd(self::MARCH_YEAR_END, '2026-03-31'));
    }

    public function testTaxYear(): void
    {
        $this->assertSame(2026, FinancialYear::taxYear('2026-11-25'));
        $this->assertSame(2026, FinancialYear::taxYear('2026-04-06'));
        $this->assertSame(2025, FinancialYear::taxYear('2026-04-05'));
        $this->assertSame(['2026-04-06', '2027-04-05'], FinancialYear::taxYearBounds('2026-11-25'));
    }

    public function testIsValidDate(): void
    {
        $this->assertTrue(FinancialYear::isValidDate('2026-02-28'));
        $this->assertFalse(FinancialYear::isValidDate('2026-02-30'));
        $this->assertFalse(FinancialYear::isValidDate('2026-03-32'));
        $this->assertFalse(FinancialYear::isValidDate(''));
        $this->assertFalse(FinancialYear::isValidDate(null));
    }
}
