<?php

declare(strict_types=1);

/**
 * Migration: give every category line a fund.
 *
 * Previously category entries and fund entries were independent, so a transaction split
 * across several funds AND several categories could not say which category amount went
 * to which fund; reports pro-rated it. Each category line now carries its fund_id, and for
 * receipts/payments the fund entries are derived from the lines.
 *
 * Backfill per transaction:
 * - one fund                         -> every line gets that fund
 * - one category, several funds      -> the line is split into one line per fund
 * - several categories and funds     -> allocated in proportion, exact to the penny for
 *                                       every category and every fund, and the transaction
 *                                       is flagged allocation_needs_review
 * - no fund entries (legacy data)    -> legacy transactions.fund_id if set, else flagged
 */

use CharityCashbook\Core\Database;
use CharityCashbook\Core\Money;

return [
    'up' => function (Database $db): void {
        $db->execute("
            ALTER TABLE transaction_category_entries
            ADD COLUMN fund_id INT UNSIGNED NULL AFTER operational_category_id,
            ADD CONSTRAINT fk_tce_fund FOREIGN KEY (fund_id) REFERENCES funds(id),
            ADD INDEX idx_fund (fund_id)
        ");
        $db->execute("
            ALTER TABLE transactions
            ADD COLUMN allocation_needs_review BOOLEAN NOT NULL DEFAULT FALSE
        ");

        $transactions = $db->query("
            SELECT t.id, t.fund_id AS legacy_fund_id
            FROM transactions t
            WHERE EXISTS (SELECT 1 FROM transaction_category_entries tce WHERE tce.transaction_id = t.id)
        ");

        $flagged = [];

        foreach ($transactions as $t) {
            $transactionId = (int) $t['id'];
            $categories = $db->query(
                "SELECT id, operational_category_id, amount FROM transaction_category_entries WHERE transaction_id = ? ORDER BY id",
                [$transactionId]
            );
            $funds = $db->query(
                "SELECT fund_id, SUM(amount) AS amount FROM transaction_fund_entries WHERE transaction_id = ? GROUP BY fund_id ORDER BY MIN(id)",
                [$transactionId]
            );

            // Legacy data with no fund entries
            if (!$funds) {
                if ($t['legacy_fund_id']) {
                    $db->execute(
                        "UPDATE transaction_category_entries SET fund_id = ? WHERE transaction_id = ?",
                        [$t['legacy_fund_id'], $transactionId]
                    );
                } else {
                    $flagged[] = $transactionId;
                }
                continue;
            }

            if (count($funds) === 1) {
                $db->execute(
                    "UPDATE transaction_category_entries SET fund_id = ? WHERE transaction_id = ?",
                    [$funds[0]['fund_id'], $transactionId]
                );
                continue;
            }

            // One row per category: several lines for the same category (e.g. left over from a
            // rollback of this migration) are merged, so a single-category split stays exact
            $signedByCategory = [];
            foreach ($categories as $c) {
                $categoryId = (int) $c['operational_category_id'];
                $signedByCategory[$categoryId] = ($signedByCategory[$categoryId] ?? 0) + Money::toPence($c['amount']);
            }
            $rowTotals = array_map('abs', $signedByCategory);
            $rowSign = array_map(fn($p) => $p < 0 ? -1 : 1, $signedByCategory);
            $colTotals = [];
            foreach ($funds as $f) {
                $colTotals[(int) $f['fund_id']] = abs(Money::toPence($f['amount']));
            }

            $consistent = array_sum($rowTotals) === array_sum($colTotals) && array_sum($colTotals) > 0;
            if (!$consistent) {
                // Categories and funds disagree: allocate by fund weights and flag for review
                $flagged[] = $transactionId;
            } elseif (count($signedByCategory) > 1) {
                $flagged[] = $transactionId;
            }

            $matrix = $consistent
                ? Money::allocateMatrix($rowTotals, $colTotals)
                : array_map(
                    fn($rowTotal) => Money::allocate($rowTotal, array_sum($colTotals) > 0 ? $colTotals : array_fill_keys(array_keys($colTotals), 1)),
                    $rowTotals
                );

            // Replace the category lines with one line per category per fund
            $db->execute("DELETE FROM transaction_category_entries WHERE transaction_id = ?", [$transactionId]);
            foreach ($matrix as $categoryId => $byFund) {
                foreach ($byFund as $fundId => $pence) {
                    if ($pence === 0) {
                        continue;
                    }
                    $db->insert('transaction_category_entries', [
                        'transaction_id' => $transactionId,
                        'operational_category_id' => $categoryId,
                        'fund_id' => $fundId,
                        'amount' => Money::fromPence($pence * $rowSign[$categoryId]),
                    ]);
                }
            }
        }

        if ($flagged) {
            $flagged = array_values(array_unique($flagged));
            $placeholders = implode(',', array_fill(0, count($flagged), '?'));
            $db->execute(
                "UPDATE transactions SET allocation_needs_review = TRUE WHERE id IN ($placeholders)",
                $flagged
            );
            echo '  ' . count($flagged) . " transaction(s) need their fund/category allocation reviewed (IDs: " . implode(', ', $flagged) . ")\n";
        }

        $unallocated = (int) $db->queryValue("SELECT COUNT(*) FROM transaction_category_entries WHERE fund_id IS NULL");
        if ($unallocated === 0) {
            $db->execute("ALTER TABLE transaction_category_entries MODIFY COLUMN fund_id INT UNSIGNED NOT NULL");
        } else {
            echo "  $unallocated category line(s) have no fund; fund_id left nullable until they are fixed\n";
        }
    },

    'down' => function (Database $db): void {
        // Lines that were split per fund stay split (one row per fund, same category);
        // category totals are unchanged, so the previous reports still add up.
        $db->execute("ALTER TABLE transactions DROP COLUMN allocation_needs_review");
        $db->execute("
            ALTER TABLE transaction_category_entries
            DROP FOREIGN KEY fk_tce_fund,
            DROP INDEX idx_fund,
            DROP COLUMN fund_id
        ");
    },
];
