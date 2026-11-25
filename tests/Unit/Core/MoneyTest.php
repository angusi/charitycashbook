<?php

declare(strict_types=1);

namespace CharityCashbook\Tests\Unit\Core;

use CharityCashbook\Core\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function testToPenceRounds(): void
    {
        $this->assertSame(10000, Money::toPence('100'));
        $this->assertSame(3333, Money::toPence('33.333'));
        $this->assertSame(1001, Money::toPence(10.005));
        $this->assertSame(-250, Money::toPence('-2.50'));
        $this->assertSame(0, Money::toPence(null));
        $this->assertSame(0, Money::toPence(''));
    }

    public function testFromPence(): void
    {
        $this->assertSame('100.00', Money::fromPence(10000));
        $this->assertSame('0.05', Money::fromPence(5));
        $this->assertSame('-2.50', Money::fromPence(-250));
    }

    public function testSumIsExact(): void
    {
        // Three thirds of £100 entered to 3dp round to £99.99, not £100.00
        $this->assertSame(9999, Money::sumPence(['33.333', '33.333', '33.334']));
        $this->assertSame(30, Money::sumPence([0.1, 0.2]));
    }

    public function testEquals(): void
    {
        $this->assertTrue(Money::equals('0.30', 0.1 + 0.2));
        $this->assertFalse(Money::equals('99.99', '100.00'));
    }

    public function testIsValid(): void
    {
        $this->assertTrue(Money::isValid('12.34'));
        $this->assertTrue(Money::isValid('12'));
        $this->assertTrue(Money::isValid(12.5));
        $this->assertFalse(Money::isValid('12.345'));
        $this->assertFalse(Money::isValid('abc'));
        $this->assertFalse(Money::isValid(null));
    }

    public function testAllocateSumsExactly(): void
    {
        $parts = Money::allocate(10000, ['a' => 1, 'b' => 1, 'c' => 1]);
        $this->assertSame(10000, array_sum($parts));
        $this->assertSame(['a', 'b', 'c'], array_keys($parts));

        $parts = Money::allocate(15000, [10 => 10000, 20 => 5000]);
        $this->assertSame([10 => 10000, 20 => 5000], $parts);
    }

    /**
     * @dataProvider matrices
     */
    public function testAllocateMatrixKeepsEveryRowAndColumnExact(array $rows, array $cols): void
    {
        $matrix = Money::allocateMatrix($rows, $cols);

        foreach ($rows as $r => $total) {
            $this->assertSame($total, array_sum($matrix[$r]), "row $r");
        }
        foreach ($cols as $c => $total) {
            $this->assertSame($total, array_sum(array_column($matrix, $c)), "column $c");
        }
    }

    public static function matrices(): array
    {
        return [
            'one category split across funds' => [[1 => 15000], [10 => 10000, 11 => 5000]],
            'several categories, one fund' => [[1 => 6000, 2 => 4000], [10 => 10000]],
            'awkward pennies' => [[1 => 10001, 2 => 5000, 3 => 3333], [7 => 9000, 8 => 9334]],
            'equal thirds' => [[1 => 100, 2 => 100, 3 => 100], [5 => 101, 6 => 199]],
        ];
    }

    public function testAllocateMatrixIsProportional(): void
    {
        $matrix = Money::allocateMatrix([1 => 15000], [10 => 10000, 11 => 5000]);

        $this->assertSame([1 => [10 => 10000, 11 => 5000]], $matrix);
    }

    public function testAllocateMatrixRejectsMismatchedTotals(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::allocateMatrix([1 => 100], [2 => 99]);
    }
}
