<?php

declare(strict_types=1);

namespace CharityCashbook\Core;

/**
 * Money helpers.
 *
 * Amounts are stored as DECIMAL(15,2) and arrive from forms as strings or
 * floats. All comparisons and sums used for validation are done in integer
 * pence so that rounding can never let entries drift from their totals.
 */
final class Money
{
    /**
     * Convert an amount in pounds to integer pence (rounded half away from zero).
     */
    public static function toPence(string|int|float|null $amount): int
    {
        if ($amount === null || $amount === '') {
            return 0;
        }

        return (int) round(((float) $amount) * 100);
    }

    /**
     * Convert integer pence to a 2-decimal-place string suitable for DECIMAL columns.
     */
    public static function fromPence(int $pence): string
    {
        $sign = $pence < 0 ? '-' : '';
        $abs = abs($pence);

        return sprintf('%s%d.%02d', $sign, intdiv($abs, 100), $abs % 100);
    }

    /**
     * Normalise an amount to a 2-decimal-place string.
     */
    public static function normalise(string|int|float|null $amount): string
    {
        return self::fromPence(self::toPence($amount));
    }

    /**
     * Whether two amounts are equal to the penny.
     */
    public static function equals(string|int|float|null $a, string|int|float|null $b): bool
    {
        return self::toPence($a) === self::toPence($b);
    }

    /**
     * Sum amounts exactly, returning integer pence.
     *
     * @param iterable<string|int|float|null> $amounts
     */
    public static function sumPence(iterable $amounts): int
    {
        $total = 0;
        foreach ($amounts as $amount) {
            $total += self::toPence($amount);
        }

        return $total;
    }

    /**
     * Whether a value is a valid monetary amount (numeric, at most 2 decimal places).
     */
    public static function isValid(mixed $amount): bool
    {
        if (is_int($amount) || is_float($amount)) {
            return is_finite((float) $amount);
        }

        return is_string($amount) && preg_match('/^-?\d+(\.\d{1,2})?$/', trim($amount)) === 1;
    }

    /**
     * Split an amount in pence across weights so the parts sum exactly to the total
     * (largest-remainder method).
     *
     * @param int[] $weights Non-negative weights, keyed however the caller likes
     * @return int[] Parts in pence with the same keys as $weights
     */
    public static function allocate(int $totalPence, array $weights): array
    {
        $weightSum = array_sum($weights);
        if ($weightSum <= 0) {
            throw new \InvalidArgumentException('Cannot allocate across zero weights');
        }

        $parts = [];
        $remainders = [];
        $allocated = 0;
        foreach ($weights as $key => $weight) {
            $exact = $totalPence * $weight / $weightSum;
            $floor = (int) floor($exact);
            $parts[$key] = $floor;
            $remainders[$key] = $exact - $floor;
            $allocated += $floor;
        }

        $left = $totalPence - $allocated;
        arsort($remainders);
        foreach (array_keys($remainders) as $key) {
            if ($left <= 0) {
                break;
            }
            $parts[$key]++;
            $left--;
        }

        return $parts;
    }

    /**
     * Allocate row totals across column totals in proportion, so that every row and every
     * column sums exactly (pence). Rows and columns must have the same grand total.
     *
     * Used to give each category line of a split transaction a fund when only the category
     * totals and fund totals are known.
     *
     * @param int[] $rows Row totals in pence (non-negative), keyed by row
     * @param int[] $cols Column totals in pence (non-negative), keyed by column
     * @return array<int|string, array<int|string, int>> [row][col] => pence
     */
    public static function allocateMatrix(array $rows, array $cols): array
    {
        if (array_sum($rows) !== array_sum($cols)) {
            throw new \InvalidArgumentException('Row and column totals must be equal');
        }

        $matrix = [];
        foreach ($rows as $r => $rowTotal) {
            $matrix[$r] = $rowTotal > 0 && array_sum($cols) > 0
                ? self::allocate($rowTotal, $cols)
                : array_fill_keys(array_keys($cols), 0);
        }

        // Rows are exact; move single pennies within a row until every column is exact too
        for ($guard = 0; $guard < 100000; $guard++) {
            $over = null;
            $under = null;
            foreach ($cols as $c => $colTotal) {
                $diff = array_sum(array_column($matrix, $c)) - $colTotal;
                if ($diff > 0 && $over === null) {
                    $over = $c;
                } elseif ($diff < 0 && $under === null) {
                    $under = $c;
                }
            }
            if ($over === null || $under === null) {
                break;
            }
            foreach ($matrix as $r => $row) {
                if ($row[$over] > 0) {
                    $matrix[$r][$over]--;
                    $matrix[$r][$under]++;
                    break;
                }
            }
        }

        return $matrix;
    }
}
