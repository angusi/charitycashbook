<?php

declare(strict_types=1);

namespace CharityCashbook\Charity;

use CharityCashbook\Core\DatabaseInterface;
use CharityCashbook\Core\Logger;
use CharityCashbook\Core\Money;
use CharityCashbook\Core\Exceptions\ValidationException;

/**
 * Service for managing Gift Aid claims
 */
class GiftAidService
{
    private DatabaseInterface $db;
    private Logger $logger;
    private AuditService $auditService;

    private const GIFTAID_RATE = 0.25; // 25% tax reclaim
    private const GASDS_CLAIM_CAP = 2000.00; // £2,000 cap on GASDS *claim* per tax year (donations up to £8,000)
    private const GASDS_DONATION_CAP_PENCE = 800000; // £8,000 of small donations per tax year
    private const GASDS_MATCHING_RATIO = 10; // GASDS donations cannot exceed 10x Gift Aid donations in the tax year

    public function __construct(DatabaseInterface $db, Logger $logger, AuditService $auditService)
    {
        $this->db = $db;
        $this->logger = $logger;
        $this->auditService = $auditService;
    }

    /**
     * Get all claims for a charity
     */
    public function getClaims(int $charityId): array
    {
        return $this->db->query(
            "SELECT gc.*, COALESCE(cu.display_name, u.display_name) as created_by_name
             FROM giftaid_claims gc
             LEFT JOIN users u ON u.id = gc.created_by_user_id
             LEFT JOIN charity_users cu ON cu.user_id = gc.created_by_user_id AND cu.charity_id = gc.charity_id
             WHERE gc.charity_id = ?
             ORDER BY gc.created_at DESC",
            [$charityId]
        );
    }

    /**
     * Get a single claim with details
     */
    public function getClaim(int $id, int $charityId): ?array
    {
        $claim = $this->db->queryOne(
            "SELECT gc.*, 
                    COALESCE(cu.display_name, u.display_name) as created_by_name, 
                    COALESCE(scu.display_name, su.display_name) as submitted_by_name
             FROM giftaid_claims gc
             LEFT JOIN users u ON u.id = gc.created_by_user_id
             LEFT JOIN charity_users cu ON cu.user_id = gc.created_by_user_id AND cu.charity_id = gc.charity_id
             LEFT JOIN users su ON su.id = gc.submitted_by_user_id
             LEFT JOIN charity_users scu ON scu.user_id = gc.submitted_by_user_id AND scu.charity_id = gc.charity_id
             WHERE gc.id = ? AND gc.charity_id = ?",
            [$id, $charityId]
        );

        return $claim;
    }

    /**
     * Get transactions in a claim
     */
    public function getClaimTransactions(int $claimId, int $charityId): array
    {
        return $this->db->query(
            "SELECT t.*, 
                    d.name as donor_name, 
                    d.address_line1, 
                    d.address_line2,
                    d.address_city,
                    d.address_postcode
             FROM giftaid_claim_items gci
             JOIN transactions t ON t.id = gci.transaction_id
             LEFT JOIN donors d ON d.id = t.donor_id
             WHERE gci.claim_id = ? AND t.charity_id = ?
             ORDER BY t.transaction_date, t.id",
            [$claimId, $charityId]
        );
    }

    /**
     * Get eligible unclaimed transactions (only those with confirmed giftaid_amount)
     */
    public function getEligibleTransactions(int $charityId, bool $unclaimedOnly = false): array
    {
        $sql = "SELECT t.*, d.name as donor_name
                FROM transactions t
                LEFT JOIN donors d ON d.id = t.donor_id
                WHERE t.charity_id = ?
                AND t.is_deleted = FALSE
                AND t.giftaid_status IN ('eligible', 'gasds')
                AND t.giftaid_amount IS NOT NULL";
        
        $params = [$charityId];
        
        if ($unclaimedOnly) {
            $sql .= " AND t.giftaid_claim_id IS NULL";
        }
        
        $sql .= " ORDER BY t.giftaid_status, t.transaction_date, t.id";

        return $this->db->query($sql, $params);
    }

    /**
     * Calculate claimable Gift Aid amount
     */
    public function calculateClaimableAmount(int $charityId): float
    {
        $result = $this->db->queryOne(
            "SELECT COALESCE(SUM(giftaid_amount), 0) as total
             FROM transactions
             WHERE charity_id = ?
             AND is_deleted = FALSE
             AND giftaid_status IN ('eligible', 'gasds')
             AND giftaid_claim_id IS NULL
             AND giftaid_amount IS NOT NULL",
            [$charityId]
        );
        
        return ((float) $result['total']) * self::GIFTAID_RATE;
    }

    /**
     * UK tax year (starting 6 April) for a date, as its starting calendar year
     */
    public function getFinancialYear(string $date): string
    {
        return (string) FinancialYear::taxYear($date);
    }

    /**
     * Get Gift Aid summary for a period
     */
    public function getSummary(int $charityId, string $dateFrom, string $dateTo): array
    {
        $result = $this->db->queryOne(
            "SELECT 
                COALESCE(SUM(CASE WHEN giftaid_status = 'eligible' AND giftaid_amount IS NOT NULL THEN giftaid_amount ELSE 0 END), 0) as eligible_total,
                COALESCE(SUM(CASE WHEN giftaid_status = 'claimed' THEN giftaid_amount ELSE 0 END), 0) as claimed_total,
                COALESCE(SUM(CASE WHEN giftaid_status = 'gasds' AND giftaid_amount IS NOT NULL THEN giftaid_amount ELSE 0 END), 0) as gasds_total,
                COUNT(CASE WHEN giftaid_status = 'eligible' AND giftaid_amount IS NOT NULL THEN 1 END) as eligible_count,
                COUNT(CASE WHEN giftaid_status = 'claimed' THEN 1 END) as claimed_count,
                COUNT(CASE WHEN giftaid_status IN ('eligible', 'gasds') AND giftaid_amount IS NULL THEN 1 END) as needs_review_count
             FROM transactions
             WHERE charity_id = ?
             AND is_deleted = FALSE
             AND transaction_type = 'receipt'
             AND transaction_date >= ?
             AND transaction_date <= ?",
            [$charityId, $dateFrom, $dateTo]
        );
        
        return [
            'eligible_total' => (float) $result['eligible_total'],
            'eligible_giftaid' => ((float) $result['eligible_total']) * self::GIFTAID_RATE,
            'claimed_total' => (float) $result['claimed_total'],
            'claimed_giftaid' => ((float) $result['claimed_total']) * self::GIFTAID_RATE,
            'gasds_total' => (float) $result['gasds_total'],
            'eligible_count' => (int) $result['eligible_count'],
            'claimed_count' => (int) $result['claimed_count'],
            'needs_review_count' => (int) $result['needs_review_count'],
        ];
    }

    /**
     * Calculate totals for eligible transactions
     */
    public function calculateEligibleTotals(int $charityId, string $dateFrom, string $dateTo): array
    {
        $result = $this->db->queryOne(
            "SELECT 
                COALESCE(SUM(CASE WHEN giftaid_status = 'eligible' AND giftaid_amount IS NOT NULL THEN giftaid_amount ELSE 0 END), 0) as standard_donations,
                COALESCE(SUM(CASE WHEN giftaid_status = 'gasds' AND giftaid_amount IS NOT NULL THEN giftaid_amount ELSE 0 END), 0) as gasds_donations,
                COUNT(CASE WHEN giftaid_status = 'eligible' AND giftaid_amount IS NOT NULL THEN 1 END) as standard_count,
                COUNT(CASE WHEN giftaid_status = 'gasds' AND giftaid_amount IS NOT NULL THEN 1 END) as gasds_count
             FROM transactions
             WHERE charity_id = ?
             AND is_deleted = FALSE
             AND giftaid_status IN ('eligible', 'gasds')
             AND giftaid_claim_id IS NULL
             AND giftaid_amount IS NOT NULL
             AND transaction_date >= ?
             AND transaction_date <= ?",
            [$charityId, $dateFrom, $dateTo]
        );

        $standardDonations = (float) $result['standard_donations'];
        $gasdsDonations = (float) $result['gasds_donations'];
        $standardGiftAid = $standardDonations * self::GIFTAID_RATE;

        // GASDS limits apply per UK tax year: up to £8,000 of small donations (a £2,000 top-up),
        // and no more than 10x the Gift Aid donations claimed in that tax year
        $position = $this->gasdsTaxYearPosition($charityId, $dateTo);
        $yearlyGasdsClaimed = $position['gasds_claimed'];
        $absoluteCap = self::GASDS_CLAIM_CAP;
        $matchingCap = ($position['standard_donations'] + $standardDonations) * self::GIFTAID_RATE * self::GASDS_MATCHING_RATIO;
        $effectiveCap = min($absoluteCap, $matchingCap);

        $gasdsClaimRemaining = max(0, $effectiveCap - $yearlyGasdsClaimed);

        // Convert GASDS donations to potential claim amount
        $potentialGasdsClaim = $gasdsDonations * self::GIFTAID_RATE;
        $claimableGasdsClaim = min($potentialGasdsClaim, $gasdsClaimRemaining);

        // Convert back to claimable donation amount for display
        $claimableGasdsDonations = $claimableGasdsClaim / self::GIFTAID_RATE;

        return [
            'standard_donations' => $standardDonations,
            'standard_giftaid' => round($standardGiftAid, 2),
            'standard_count' => (int) $result['standard_count'],
            'gasds_donations' => $gasdsDonations,
            'gasds_claimable' => round($claimableGasdsDonations, 2),
            'gasds_giftaid' => round($claimableGasdsClaim, 2),
            'gasds_count' => (int) $result['gasds_count'],
            'yearly_gasds_claimed' => $yearlyGasdsClaimed,
            'gasds_claim_remaining' => round($gasdsClaimRemaining, 2),
            'gasds_absolute_cap' => $absoluteCap,
            'gasds_matching_cap' => round($matchingCap, 2),
            'total_giftaid' => round($standardGiftAid + $claimableGasdsClaim, 2),
        ];
    }

    /**
     * Get transactions needing Gift Aid amount review (eligible but amount not confirmed)
     */
    public function getTransactionsNeedingAmountReview(int $charityId, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $sql = "SELECT t.*, d.name as donor_name
                FROM transactions t
                LEFT JOIN donors d ON d.id = t.donor_id
                WHERE t.charity_id = ?
                AND t.is_deleted = FALSE
                AND t.giftaid_status IN ('eligible', 'gasds')
                AND t.giftaid_amount IS NULL";
        
        $params = [$charityId];

        if ($dateFrom) {
            $sql .= " AND t.transaction_date >= ?";
            $params[] = $dateFrom;
        }
        if ($dateTo) {
            $sql .= " AND t.transaction_date <= ?";
            $params[] = $dateTo;
        }

        $sql .= " ORDER BY t.transaction_date DESC";

        $transactions = $this->db->query($sql, $params);

        // Calculate eligible_amount from category entries for each transaction
        foreach ($transactions as &$tx) {
            $tx['eligible_amount'] = $this->calculateEligibleAmountFromCategories($tx['id'], $charityId);
        }

        return $transactions;
    }

    /**
     * Get transactions with incomplete Gift Aid setup (intent=yes but missing donor/declaration)
     */
    public function getTransactionsWithIncompleteSetup(int $charityId, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $sql = "SELECT t.*, d.name as donor_name
                FROM transactions t
                LEFT JOIN donors d ON d.id = t.donor_id
                WHERE t.charity_id = ?
                AND t.is_deleted = FALSE
                AND t.giftaid_status IN ('pending_donor', 'pending_declaration')";
        
        $params = [$charityId];

        if ($dateFrom) {
            $sql .= " AND t.transaction_date >= ?";
            $params[] = $dateFrom;
        }
        if ($dateTo) {
            $sql .= " AND t.transaction_date <= ?";
            $params[] = $dateTo;
        }

        $sql .= " ORDER BY t.transaction_date DESC";

        return $this->db->query($sql, $params);
    }

    /**
     * Get all transactions needing review (both incomplete setup and amount confirmation)
     * @deprecated Use getTransactionsNeedingAmountReview() and getTransactionsWithIncompleteSetup() instead
     */
    public function getTransactionsNeedingReview(int $charityId, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        return $this->getTransactionsNeedingAmountReview($charityId, $dateFrom, $dateTo);
    }

    /**
     * Calculate the eligible amount based on category entries with giftaid_eligible_default
     */
    private function calculateEligibleAmountFromCategories(int $transactionId, int $charityId): float
    {
        $sql = "SELECT SUM(tce.amount) as eligible_total
                FROM transaction_category_entries tce
                JOIN operational_categories oc ON oc.id = tce.operational_category_id
                WHERE tce.transaction_id = ?
                AND oc.giftaid_eligible_default = 1";
        
        $result = $this->db->queryOne($sql, [$transactionId]);
        
        return (float) ($result['eligible_total'] ?? 0);
    }

    /**
     * Confirm Gift Aid amount for a transaction
     */
    public function confirmGiftAidAmount(int $transactionId, int $charityId, float $giftaidAmount, int $userId): void
    {
        $transaction = $this->db->queryOne(
            "SELECT * FROM transactions WHERE id = ? AND charity_id = ? AND is_deleted = FALSE",
            [$transactionId, $charityId]
        );

        if (!$transaction) {
            throw new ValidationException(['transaction' => 'Transaction not found']);
        }

        if (!in_array($transaction['giftaid_status'], ['eligible', 'gasds'])) {
            throw new ValidationException(['transaction' => 'Transaction is not eligible for Gift Aid']);
        }

        if ($transaction['giftaid_claim_id'] !== null) {
            throw new ValidationException(['transaction' => "Transaction is in Gift Aid claim #{$transaction['giftaid_claim_id']}; remove it from the claim first"]);
        }

        if ($giftaidAmount < 0) {
            throw new ValidationException(['giftaid_amount' => 'Gift Aid amount cannot be negative']);
        }

        if ($giftaidAmount > (float)$transaction['amount']) {
            throw new ValidationException(['giftaid_amount' => 'Gift Aid amount cannot exceed transaction amount']);
        }

        $this->db->update(
            'transactions',
            ['giftaid_amount' => Money::normalise($giftaidAmount), 'updated_by_user_id' => $userId],
            'id = ? AND charity_id = ?',
            [$transactionId, $charityId]
        );

        $this->auditService->log($charityId, $userId, 'giftaid_amount_confirmed', 'transaction', $transactionId, [
            'giftaid_amount' => $giftaidAmount,
            'transaction_amount' => $transaction['amount'],
        ]);
    }

    /**
     * Get pending corrections for a charity
     */
    public function getPendingCorrections(int $charityId): array
    {
        return $this->db->query(
            "SELECT gc.*, t.description as transaction_description, t.transaction_date,
                    d.name as donor_name
             FROM giftaid_corrections gc
             JOIN transactions t ON t.id = gc.original_transaction_id
             LEFT JOIN donors d ON d.id = t.donor_id
             WHERE gc.charity_id = ?
             AND gc.applied_to_claim_id IS NULL
             ORDER BY gc.created_at DESC",
            [$charityId]
        );
    }

    /**
     * Create a Gift Aid correction
     */
    public function createCorrection(
        int $charityId,
        int $transactionId,
        float $correctedAmount,
        string $reason,
        int $userId
    ): int {
        // Get the transaction and its claim
        $transaction = $this->db->queryOne(
            "SELECT t.*, gc.id as claim_id
             FROM transactions t
             JOIN giftaid_claims gc ON gc.id = t.giftaid_claim_id
             WHERE t.id = ? AND t.charity_id = ? AND t.giftaid_status = 'claimed'",
            [$transactionId, $charityId]
        );

        if (!$transaction) {
            throw new ValidationException(['transaction' => 'Transaction not found or not claimed']);
        }

        $pending = $this->db->queryValue(
            "SELECT COUNT(*) FROM giftaid_corrections
             WHERE charity_id = ? AND original_transaction_id = ? AND applied_to_claim_id IS NULL",
            [$charityId, $transactionId]
        );
        if ((int) $pending > 0) {
            throw new ValidationException(['transaction' => 'This transaction already has a correction waiting for the next claim']);
        }

        if ($correctedAmount < 0) {
            throw new ValidationException(['corrected_amount' => 'Corrected amount cannot be negative']);
        }

        if ($correctedAmount > (float)$transaction['amount']) {
            throw new ValidationException(['corrected_amount' => 'Corrected amount cannot exceed transaction amount']);
        }

        // What has been claimed so far: the amount in the original claim plus any
        // corrections already applied to later claims
        $applied = $this->db->queryValue(
            "SELECT COALESCE(SUM(adjustment), 0) FROM giftaid_corrections
             WHERE charity_id = ? AND original_transaction_id = ? AND applied_to_claim_id IS NOT NULL",
            [$charityId, $transactionId]
        );
        $originalPence = Money::toPence($transaction['giftaid_amount']) + Money::toPence($applied);
        $originalAmount = Money::fromPence($originalPence);
        $adjustment = Money::fromPence(Money::toPence($correctedAmount) - $originalPence);

        // Don't create a correction if there's no change
        if (Money::toPence($adjustment) === 0) {
            throw new ValidationException(['corrected_amount' => 'Corrected amount is the same as original']);
        }

        $correctionId = $this->db->insert('giftaid_corrections', [
            'charity_id' => $charityId,
            'original_transaction_id' => $transactionId,
            'original_claim_id' => $transaction['claim_id'],
            'original_amount' => $originalAmount,
            'corrected_amount' => $correctedAmount,
            'adjustment' => $adjustment,
            'reason' => $reason,
            'created_by_user_id' => $userId,
        ]);

        $this->auditService->log($charityId, $userId, 'giftaid_correction_created', 'giftaid_correction', $correctionId, [
            'transaction_id' => $transactionId,
            'original_amount' => $originalAmount,
            'corrected_amount' => $correctedAmount,
            'adjustment' => $adjustment,
            'reason' => $reason,
        ]);

        return $correctionId;
    }

    /**
     * Get overclaim total (negative adjustments) for pending corrections
     */
    public function getPendingOverclaimTotal(int $charityId): float
    {
        $result = $this->db->queryValue(
            "SELECT COALESCE(SUM(ABS(adjustment)), 0)
             FROM giftaid_corrections
             WHERE charity_id = ?
             AND applied_to_claim_id IS NULL
             AND adjustment < 0",
            [$charityId]
        );
        return (float) $result;
    }

    /**
     * Get underclaim corrections (positive adjustments) for pending corrections
     * Returns as array since these become donation rows
     */
    public function getPendingUnderclaims(int $charityId): array
    {
        return $this->db->query(
            "SELECT gc.*, t.transaction_date, d.name as donor_name,
                    d.address_line1, d.address_postcode
             FROM giftaid_corrections gc
             JOIN transactions t ON t.id = gc.original_transaction_id
             LEFT JOIN donors d ON d.id = t.donor_id
             WHERE gc.charity_id = ?
             AND gc.applied_to_claim_id IS NULL
             AND gc.adjustment > 0
             ORDER BY t.transaction_date",
            [$charityId]
        );
    }

    /**
     * Get corrections applied to a specific claim
     */
    public function getCorrectionsForClaim(int $claimId, int $charityId): array
    {
        return $this->db->query(
            "SELECT gc.*, t.transaction_date, d.name as donor_name,
                    d.address_line1, d.address_postcode
             FROM giftaid_corrections gc
             JOIN transactions t ON t.id = gc.original_transaction_id
             LEFT JOIN donors d ON d.id = t.donor_id
             WHERE gc.applied_to_claim_id = ?
             AND gc.charity_id = ?
             ORDER BY t.transaction_date",
            [$claimId, $charityId]
        );
    }

    /**
     * Validate the transactions for a claim and total them.
     *
     * Every transaction must belong to the charity, not be deleted, be eligible (or GASDS)
     * with a confirmed Gift Aid amount, and not be in another claim.
     *
     * @param int[] $transactionIds
     * @return array{rows: array, standard_pence: int, gasds_pence: int}
     */
    private function validateClaimItems(int $charityId, array $transactionIds, ?int $claimId): array
    {
        $transactionIds = array_values(array_unique(array_map('intval', $transactionIds)));
        if (!$transactionIds) {
            throw new ValidationException(['transactions' => 'At least one transaction must be selected']);
        }

        $placeholders = implode(',', array_fill(0, count($transactionIds), '?'));
        $rows = $this->db->query(
            "SELECT id, amount, giftaid_amount, giftaid_status, giftaid_claim_id, transaction_date, is_deleted, description
             FROM transactions
             WHERE id IN ($placeholders) AND charity_id = ?",
            array_merge($transactionIds, [$charityId])
        );

        $problems = [];
        $found = [];
        foreach ($rows as $row) {
            $found[(int) $row['id']] = true;
            $label = "#{$row['id']} ({$row['description']})";

            if ($row['is_deleted']) {
                $problems[] = "$label has been deleted";
            } elseif (!in_array($row['giftaid_status'], ['eligible', 'gasds'], true)) {
                $problems[] = "$label is no longer eligible for Gift Aid";
            } elseif ($row['giftaid_amount'] === null) {
                $problems[] = "$label needs its Gift Aid amount confirming";
            } elseif ($row['giftaid_claim_id'] !== null && (int) $row['giftaid_claim_id'] !== $claimId) {
                $problems[] = "$label is already in claim #{$row['giftaid_claim_id']}";
            }
        }
        foreach ($transactionIds as $id) {
            if (!isset($found[$id])) {
                $problems[] = "#$id was not found";
            }
        }

        if ($problems) {
            throw new ValidationException(['transactions' => 'These transactions cannot be claimed: ' . implode('; ', $problems)]);
        }

        $standard = 0;
        $gasds = 0;
        foreach ($rows as $row) {
            if ($row['giftaid_status'] === 'gasds') {
                $gasds += Money::toPence($row['giftaid_amount']);
            } else {
                $standard += Money::toPence($row['giftaid_amount']);
            }
        }

        return ['rows' => $rows, 'standard_pence' => $standard, 'gasds_pence' => $gasds];
    }

    /**
     * Enforce the GASDS limits for every tax year the claim's GASDS donations fall in:
     * - at most £8,000 of small donations per tax year (a £2,000 top-up)
     * - small donations may not exceed 10 times the Gift Aid donations claimed in that tax year
     *
     * Other non-cancelled claims (draft or submitted) count towards the limits.
     */
    private function assertGasdsLimits(int $charityId, array $rows, ?int $claimId): void
    {
        $byYear = [];
        foreach ($rows as $row) {
            $year = FinancialYear::taxYear($row['transaction_date']);
            $byYear[$year] ??= ['gasds' => 0, 'standard' => 0];
            $key = $row['giftaid_status'] === 'gasds' ? 'gasds' : 'standard';
            $byYear[$year][$key] += Money::toPence($row['giftaid_amount']);
        }

        foreach ($byYear as $year => $totals) {
            if ($totals['gasds'] === 0) {
                continue;
            }

            [$start, $end] = FinancialYear::taxYearBounds(sprintf('%04d-04-06', $year));
            $others = $this->claimedTotalsForTaxYear($charityId, $start, $end, $claimId);
            $gasds = $totals['gasds'] + $others['gasds'];
            $standard = $totals['standard'] + $others['standard'];
            $label = sprintf('%d/%02d', $year, ($year + 1) % 100);

            if ($gasds > self::GASDS_DONATION_CAP_PENCE) {
                throw new ValidationException([
                    'gasds' => sprintf(
                        'GASDS donations for tax year %s would be £%s, over the £%s limit',
                        $label,
                        Money::fromPence($gasds),
                        Money::fromPence(self::GASDS_DONATION_CAP_PENCE)
                    ),
                ]);
            }

            if ($gasds > $standard * self::GASDS_MATCHING_RATIO) {
                throw new ValidationException([
                    'gasds' => sprintf(
                        'GASDS donations for tax year %s (£%s) cannot be more than %d times the Gift Aid donations claimed in that year (£%s)',
                        $label,
                        Money::fromPence($gasds),
                        self::GASDS_MATCHING_RATIO,
                        Money::fromPence($standard)
                    ),
                ]);
            }
        }
    }

    /**
     * Donations already in non-cancelled claims for a tax year, in pence, by scheme
     *
     * @return array{standard: int, gasds: int}
     */
    private function claimedTotalsForTaxYear(int $charityId, string $start, string $end, ?int $excludeClaimId): array
    {
        $rows = $this->db->query(
            "SELECT COALESCE(gci.original_status, CASE WHEN t.giftaid_status = 'gasds' THEN 'gasds' ELSE 'eligible' END) as scheme,
                    SUM(t.giftaid_amount) as total
             FROM giftaid_claim_items gci
             JOIN giftaid_claims gc ON gc.id = gci.claim_id
             JOIN transactions t ON t.id = gci.transaction_id
             WHERE gc.charity_id = ? AND gc.status != 'cancelled'
               AND (? IS NULL OR gc.id != ?)
               AND t.is_deleted = FALSE
               AND t.transaction_date >= ? AND t.transaction_date <= ?
             GROUP BY scheme",
            [$charityId, $excludeClaimId, $excludeClaimId, $start, $end]
        );

        $totals = ['standard' => 0, 'gasds' => 0];
        foreach ($rows as $row) {
            $totals[$row['scheme'] === 'gasds' ? 'gasds' : 'standard'] += Money::toPence($row['total']);
        }

        return $totals;
    }

    /**
     * GASDS position for the tax year containing $date (for display)
     */
    public function gasdsTaxYearPosition(int $charityId, string $date): array
    {
        [$start, $end] = FinancialYear::taxYearBounds($date);
        $claimed = $this->claimedTotalsForTaxYear($charityId, $start, $end, null);

        $matchingCap = min(self::GASDS_DONATION_CAP_PENCE, $claimed['standard'] * self::GASDS_MATCHING_RATIO);

        return [
            'tax_year_start' => $start,
            'tax_year_end' => $end,
            'gasds_donations' => $claimed['gasds'] / 100,
            'gasds_claimed' => round($claimed['gasds'] * self::GIFTAID_RATE / 100, 2),
            'standard_donations' => $claimed['standard'] / 100,
            'donation_cap' => self::GASDS_DONATION_CAP_PENCE / 100,
            'claim_cap' => self::GASDS_CLAIM_CAP,
            'donations_remaining' => max(0, $matchingCap - $claimed['gasds']) / 100,
        ];
    }

    /**
     * Totals for a claim: its donations plus any corrections applied to it
     */
    private function claimTotals(int $claimId, int $standardPence, int $gasdsPence): array
    {
        $corrections = $this->db->queryOne(
            "SELECT
                COALESCE(SUM(CASE WHEN adjustment > 0 THEN adjustment ELSE 0 END), 0) as underclaimed,
                COALESCE(SUM(CASE WHEN adjustment < 0 THEN -adjustment ELSE 0 END), 0) as overclaimed
             FROM giftaid_corrections WHERE applied_to_claim_id = ?",
            [$claimId]
        );
        $under = Money::toPence($corrections['underclaimed'] ?? 0);
        $over = Money::toPence($corrections['overclaimed'] ?? 0);

        return [
            'total_donations' => Money::fromPence($standardPence),
            'total_giftaid' => Money::fromPence((int) round(($standardPence + $under - $over) * self::GIFTAID_RATE)),
            'gasds_donations' => Money::fromPence($gasdsPence),
            'gasds_giftaid' => Money::fromPence((int) round($gasdsPence * self::GIFTAID_RATE)),
            'underclaim_adjustment' => Money::fromPence($under),
            'overclaim_adjustment' => Money::fromPence($over),
        ];
    }

    /**
     * Link transactions to a claim, recording their pre-claim status
     */
    private function linkItems(int $claimId, array $rows): void
    {
        foreach ($rows as $row) {
            $this->db->insert('giftaid_claim_items', [
                'claim_id' => $claimId,
                'transaction_id' => $row['id'],
                'original_status' => $row['giftaid_status'],
            ]);
            $this->db->execute(
                "UPDATE transactions SET giftaid_claim_id = ? WHERE id = ?",
                [$claimId, $row['id']]
            );
        }
    }

    /**
     * Create a new claim (draft)
     *
     * Pending corrections to earlier claims are applied to this claim.
     */
    public function createClaim(
        int $charityId,
        int $userId,
        string $periodStart,
        string $periodEnd,
        array $transactionIds
    ): int {
        $this->assertPeriod($periodStart, $periodEnd);
        $items = $this->validateClaimItems($charityId, $transactionIds, null);
        $this->assertGasdsLimits($charityId, $items['rows'], null);

        $this->db->beginTransaction();

        try {
            $claimId = $this->db->insert('giftaid_claims', [
                'charity_id' => $charityId,
                'claim_period_start' => $periodStart,
                'claim_period_end' => $periodEnd,
                'total_donations' => '0.00',
                'total_giftaid' => '0.00',
                'status' => 'draft',
                'created_by_user_id' => $userId,
            ]);

            $this->linkItems($claimId, $items['rows']);

            // Apply all pending corrections to earlier claims to this claim
            $pendingCorrections = $this->db->execute(
                "UPDATE giftaid_corrections SET applied_to_claim_id = ?
                 WHERE charity_id = ? AND applied_to_claim_id IS NULL",
                [$claimId, $charityId]
            );

            $this->db->update(
                'giftaid_claims',
                $this->claimTotals($claimId, $items['standard_pence'], $items['gasds_pence']),
                'id = ?',
                [$claimId]
            );

            $this->auditService->log($charityId, $userId, 'giftaid_claim.created', 'giftaid_claim', $claimId, [
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'transaction_count' => count($items['rows']),
                'corrections_applied' => $pendingCorrections,
            ]);

            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }

        $this->logger->info('Gift Aid claim created', [
            'charity_id' => $charityId,
            'claim_id' => $claimId,
            'transactions' => count($items['rows']),
        ]);

        return $claimId;
    }

    /**
     * Update a draft claim (add/remove transactions)
     */
    public function updateClaim(
        int $claimId,
        int $charityId,
        int $userId,
        string $periodStart,
        string $periodEnd,
        array $transactionIds
    ): void {
        $claim = $this->getClaim($claimId, $charityId);
        if (!$claim) {
            throw new ValidationException(['claim_id' => 'Claim not found']);
        }

        if ($claim['status'] !== 'draft') {
            throw new ValidationException(['status' => 'Only draft claims can be edited']);
        }

        $this->assertPeriod($periodStart, $periodEnd);
        $items = $this->validateClaimItems($charityId, $transactionIds, $claimId);
        $this->assertGasdsLimits($charityId, $items['rows'], $claimId);

        $this->db->beginTransaction();

        try {
            $this->db->execute(
                "UPDATE transactions SET giftaid_claim_id = NULL WHERE giftaid_claim_id = ? AND charity_id = ?",
                [$claimId, $charityId]
            );
            $this->db->execute("DELETE FROM giftaid_claim_items WHERE claim_id = ?", [$claimId]);

            $this->linkItems($claimId, $items['rows']);

            $this->db->update(
                'giftaid_claims',
                array_merge(
                    ['claim_period_start' => $periodStart, 'claim_period_end' => $periodEnd],
                    $this->claimTotals($claimId, $items['standard_pence'], $items['gasds_pence'])
                ),
                'id = ? AND charity_id = ?',
                [$claimId, $charityId]
            );

            $this->auditService->log($charityId, $userId, 'giftaid_claim.updated', 'giftaid_claim', $claimId, [
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'transaction_count' => count($items['rows']),
            ]);

            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Submit a claim (mark as submitted to HMRC)
     *
     * Every item is checked again (it may have been edited, deleted or become ineligible
     * since the claim was drafted) and the totals are recalculated before submitting.
     */
    public function submitClaim(
        int $claimId,
        int $charityId,
        int $userId,
        ?string $hmrcReference = null
    ): void {
        $claim = $this->getClaim($claimId, $charityId);
        if (!$claim) {
            throw new ValidationException(['claim_id' => 'Claim not found']);
        }

        if ($claim['status'] !== 'draft') {
            throw new ValidationException(['status' => 'Claim is not in draft status']);
        }

        $transactionIds = array_column(
            $this->db->query("SELECT transaction_id FROM giftaid_claim_items WHERE claim_id = ?", [$claimId]),
            'transaction_id'
        );
        $items = $this->validateClaimItems($charityId, $transactionIds, $claimId);
        $this->assertGasdsLimits($charityId, $items['rows'], $claimId);

        $this->db->beginTransaction();

        try {
            $this->db->update(
                'giftaid_claims',
                $this->claimTotals($claimId, $items['standard_pence'], $items['gasds_pence']),
                'id = ? AND charity_id = ?',
                [$claimId, $charityId]
            );

            $this->db->execute(
                "UPDATE giftaid_claims 
                 SET status = 'submitted', submitted_at = NOW(), 
                     submitted_by_user_id = ?, hmrc_reference = ?
                 WHERE id = ? AND charity_id = ?",
                [$userId, $hmrcReference, $claimId, $charityId]
            );

            $this->db->execute(
                "UPDATE transactions 
                 SET giftaid_status = 'claimed' 
                 WHERE giftaid_claim_id = ? AND charity_id = ?",
                [$claimId, $charityId]
            );

            $this->auditService->log($charityId, $userId, 'giftaid_claim.submitted', 'giftaid_claim', $claimId);

            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }

        $this->logger->info('Gift Aid claim submitted', [
            'charity_id' => $charityId,
            'claim_id' => $claimId,
        ]);
    }

    /**
     * Un-submit a claim (revert to draft), restoring each transaction's pre-claim status
     */
    public function unsubmitClaim(int $claimId, int $charityId, int $userId): void
    {
        $claim = $this->getClaim($claimId, $charityId);
        if (!$claim) {
            throw new ValidationException(['claim_id' => 'Claim not found']);
        }

        if ($claim['status'] !== 'submitted') {
            throw new ValidationException(['status' => 'Claim is not submitted']);
        }

        $this->db->beginTransaction();

        try {
            $this->db->execute(
                "UPDATE giftaid_claims 
                 SET status = 'draft', submitted_at = NULL, 
                     submitted_by_user_id = NULL, hmrc_reference = NULL
                 WHERE id = ? AND charity_id = ?",
                [$claimId, $charityId]
            );

            // Restore the recorded status; claims made before it was recorded fall back to a best guess
            $this->db->execute(
                "UPDATE transactions t
                 JOIN giftaid_claim_items gci ON gci.transaction_id = t.id AND gci.claim_id = ?
                 SET t.giftaid_status = COALESCE(
                     gci.original_status,
                     CASE WHEN t.has_oneoff_declaration = TRUE OR t.donor_id IS NOT NULL THEN 'eligible' ELSE 'gasds' END
                 )
                 WHERE t.giftaid_claim_id = ? AND t.charity_id = ?",
                [$claimId, $claimId, $charityId]
            );

            $this->auditService->log($charityId, $userId, 'giftaid_claim.unsubmitted', 'giftaid_claim', $claimId);

            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }

        $this->logger->info('Gift Aid claim un-submitted', [
            'charity_id' => $charityId,
            'claim_id' => $claimId,
        ]);
    }

    /**
     * Cancel a draft claim, releasing its transactions and corrections
     */
    public function cancelClaim(int $claimId, int $charityId, int $userId): void
    {
        $claim = $this->getClaim($claimId, $charityId);
        if (!$claim) {
            throw new ValidationException(['claim_id' => 'Claim not found']);
        }

        if ($claim['status'] !== 'draft') {
            throw new ValidationException(['status' => 'Only draft claims can be cancelled']);
        }

        $this->db->beginTransaction();

        try {
            $this->db->execute(
                "UPDATE transactions SET giftaid_claim_id = NULL WHERE giftaid_claim_id = ? AND charity_id = ?",
                [$claimId, $charityId]
            );

            // Corrections applied to this claim go back to pending for the next claim
            $this->db->execute(
                "UPDATE giftaid_corrections SET applied_to_claim_id = NULL WHERE applied_to_claim_id = ? AND charity_id = ?",
                [$claimId, $charityId]
            );

            $this->db->execute(
                "UPDATE giftaid_claims SET status = 'cancelled' WHERE id = ? AND charity_id = ?",
                [$claimId, $charityId]
            );

            $this->auditService->log($charityId, $userId, 'giftaid_claim.cancelled', 'giftaid_claim', $claimId);

            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    private function assertPeriod(string $periodStart, string $periodEnd): void
    {
        if (!FinancialYear::isValidDate($periodStart) || !FinancialYear::isValidDate($periodEnd) || $periodStart > $periodEnd) {
            throw new ValidationException(['period' => 'The claim period must be two valid dates, start before end']);
        }
    }
}
