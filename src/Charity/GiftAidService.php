<?php

declare(strict_types=1);

namespace CharityCashbook\Charity;

use CharityCashbook\Core\DatabaseInterface;
use CharityCashbook\Core\Logger;
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
    private const GASDS_MATCHING_RATIO = 10; // GASDS claim cannot exceed 10x regular Gift Aid claims

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
     * Get GASDS used in a financial year
     */
    public function getGasdsUsed(int $charityId, string $financialYear): float
    {
        $fyStart = $financialYear . '-04-06';
        $fyEnd = ((int)$financialYear + 1) . '-04-05';
        
        $result = $this->db->queryOne(
            "SELECT COALESCE(SUM(giftaid_amount), 0) as total
             FROM transactions
             WHERE charity_id = ?
             AND is_deleted = FALSE
             AND giftaid_status = 'gasds'
             AND giftaid_amount IS NOT NULL
             AND transaction_date >= ?
             AND transaction_date <= ?",
            [$charityId, $fyStart, $fyEnd]
        );
        
        return (float) $result['total'];
    }

    /**
     * Get financial year for a date (UK tax year, April 6)
     */
    public function getFinancialYear(string $date): string
    {
        $d = new \DateTime($date);
        $year = (int) $d->format('Y');
        $monthDay = $d->format('m-d');
        
        if ($monthDay < '04-06') {
            return (string) ($year - 1);
        }
        return (string) $year;
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

        // GASDS limits (per tax year, but we use financial year for simplicity):
        // 1. Maximum £2,000 claim (= £8,000 in donations at 25%)
        // 2. Cannot exceed 10x your regular Gift Aid claims
        $yearlyGasdsClaimed = $this->getYearlyGasdsClaimTotal($charityId, $dateFrom);
        
        // Calculate remaining GASDS claim allowance
        $absoluteCap = self::GASDS_CLAIM_CAP; // £2,000
        $matchingCap = $standardGiftAid * self::GASDS_MATCHING_RATIO; // 10x regular Gift Aid
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
     * Get GASDS claim amount already submitted in the financial year
     */
    private function getYearlyGasdsClaimTotal(int $charityId, string $referenceDate): float
    {
        // Get charity year end
        $charity = $this->db->queryOne(
            "SELECT year_end_day, year_end_month FROM charities WHERE id = ?",
            [$charityId]
        );

        // Calculate financial year start
        $yearEndMonth = (int) $charity['year_end_month'];
        $yearEndDay = (int) $charity['year_end_day'];
        
        $refDate = new \DateTime($referenceDate);
        $refYear = (int) $refDate->format('Y');
        $refMonth = (int) $refDate->format('n');
        
        // If we're past the year end, financial year started this calendar year
        // Otherwise it started last calendar year
        if ($refMonth > $yearEndMonth || ($refMonth == $yearEndMonth && (int)$refDate->format('j') > $yearEndDay)) {
            $fyStartYear = $refYear;
        } else {
            $fyStartYear = $refYear - 1;
        }
        
        $fyStart = sprintf('%04d-%02d-%02d', $fyStartYear, $yearEndMonth, $yearEndDay + 1);

        // Return the GASDS claim amount (not donations) already submitted
        $result = $this->db->queryValue(
            "SELECT COALESCE(SUM(gasds_donations * 0.25), 0)
             FROM giftaid_claims
             WHERE charity_id = ?
             AND status = 'submitted'
             AND claim_period_start >= ?",
            [$charityId, $fyStart]
        );

        return (float) $result;
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

        if ($giftaidAmount < 0) {
            throw new ValidationException(['giftaid_amount' => 'Gift Aid amount cannot be negative']);
        }

        if ($giftaidAmount > (float)$transaction['amount']) {
            throw new ValidationException(['giftaid_amount' => 'Gift Aid amount cannot exceed transaction amount']);
        }

        $this->db->update(
            'transactions',
            ['giftaid_amount' => $giftaidAmount, 'updated_by_user_id' => $userId],
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

        if ($correctedAmount < 0) {
            throw new ValidationException(['corrected_amount' => 'Corrected amount cannot be negative']);
        }

        if ($correctedAmount > (float)$transaction['amount']) {
            throw new ValidationException(['corrected_amount' => 'Corrected amount cannot exceed transaction amount']);
        }

        $originalAmount = (float)$transaction['giftaid_amount'];
        $adjustment = $correctedAmount - $originalAmount;

        // Don't create a correction if there's no change
        if (abs($adjustment) < 0.01) {
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
                    d.address_line1, d.postcode as address_postcode
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
                    d.address_line1, d.postcode as address_postcode
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
     * Create a new claim (draft)
     */
    public function createClaim(
        int $charityId,
        int $userId,
        string $periodStart,
        string $periodEnd,
        array $transactionIds
    ): int {
        // Verify all transactions are eligible and have confirmed amounts
        $transactions = $this->db->query(
            "SELECT id, amount, giftaid_amount, giftaid_status 
             FROM transactions 
             WHERE id IN (" . implode(',', array_fill(0, count($transactionIds), '?')) . ")
             AND charity_id = ?
             AND is_deleted = FALSE
             AND giftaid_status IN ('eligible', 'gasds')
             AND giftaid_claim_id IS NULL
             AND giftaid_amount IS NOT NULL",
            array_merge($transactionIds, [$charityId])
        );

        if (count($transactions) !== count($transactionIds)) {
            throw new ValidationException(['transactions' => 'Some transactions are not eligible for claiming or need Gift Aid amount confirmation']);
        }

        // Calculate totals using giftaid_amount
        $standardTotal = 0;
        $gasdsTotal = 0;
        foreach ($transactions as $t) {
            if ($t['giftaid_status'] === 'gasds') {
                $gasdsTotal += (float) $t['giftaid_amount'];
            } else {
                $standardTotal += (float) $t['giftaid_amount'];
            }
        }

        $this->db->beginTransaction();

        try {
            // Create claim
            $claimId = $this->db->insert('giftaid_claims', [
                'charity_id' => $charityId,
                'claim_period_start' => $periodStart,
                'claim_period_end' => $periodEnd,
                'total_donations' => $standardTotal,
                'total_giftaid' => round($standardTotal * self::GIFTAID_RATE, 2),
                'gasds_donations' => $gasdsTotal,
                'gasds_giftaid' => round($gasdsTotal * self::GIFTAID_RATE, 2),
                'status' => 'draft',
                'created_by_user_id' => $userId,
            ]);

            // Link transactions to claim
            foreach ($transactionIds as $transactionId) {
                $this->db->insert('giftaid_claim_items', [
                    'claim_id' => $claimId,
                    'transaction_id' => $transactionId,
                ]);

                // Update transaction claim reference
                $this->db->execute(
                    "UPDATE transactions SET giftaid_claim_id = ? WHERE id = ?",
                    [$claimId, $transactionId]
                );
            }

            $this->db->commit();

            $this->auditService->log($charityId, $userId, 'giftaid_claim.created', 'giftaid_claim', $claimId, [
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'transaction_count' => count($transactionIds),
            ]);

            $this->logger->info('Gift Aid claim created', [
                'charity_id' => $charityId,
                'claim_id' => $claimId,
                'transactions' => count($transactionIds),
            ]);

            return $claimId;
        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }
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

        if (empty($transactionIds)) {
            throw new ValidationException(['transactions' => 'At least one transaction must be selected']);
        }

        // Get current transactions in this claim
        $currentTransactionIds = array_column(
            $this->db->query(
                "SELECT transaction_id FROM giftaid_claim_items WHERE claim_id = ?",
                [$claimId]
            ),
            'transaction_id'
        );

        // Verify new transactions are eligible (either unclaimed OR already in this claim)
        $placeholders = implode(',', array_fill(0, count($transactionIds), '?'));
        $transactions = $this->db->query(
            "SELECT id, amount, giftaid_status 
             FROM transactions 
             WHERE id IN ($placeholders)
             AND charity_id = ?
             AND is_deleted = FALSE
             AND giftaid_status IN ('eligible', 'gasds', 'claimed')
             AND (giftaid_claim_id IS NULL OR giftaid_claim_id = ?)",
            array_merge($transactionIds, [$charityId, $claimId])
        );

        if (count($transactions) !== count($transactionIds)) {
            throw new ValidationException(['transactions' => 'Some transactions are not eligible for this claim']);
        }

        // Calculate totals
        $standardTotal = 0;
        $gasdsTotal = 0;
        foreach ($transactions as $t) {
            if ($t['giftaid_status'] === 'gasds') {
                $gasdsTotal += (float) $t['amount'];
            } else {
                $standardTotal += (float) $t['amount'];
            }
        }

        $this->db->beginTransaction();

        try {
            // Remove old claim items and unlink transactions
            $this->db->execute(
                "UPDATE transactions SET giftaid_claim_id = NULL WHERE giftaid_claim_id = ?",
                [$claimId]
            );
            $this->db->execute(
                "DELETE FROM giftaid_claim_items WHERE claim_id = ?",
                [$claimId]
            );

            // Update claim details
            $this->db->execute(
                "UPDATE giftaid_claims SET 
                    claim_period_start = ?,
                    claim_period_end = ?,
                    total_donations = ?,
                    total_giftaid = ?,
                    gasds_donations = ?,
                    gasds_giftaid = ?
                 WHERE id = ?",
                [
                    $periodStart,
                    $periodEnd,
                    $standardTotal,
                    round($standardTotal * self::GIFTAID_RATE, 2),
                    $gasdsTotal,
                    round($gasdsTotal * self::GIFTAID_RATE, 2),
                    $claimId
                ]
            );

            // Add new claim items
            foreach ($transactionIds as $transactionId) {
                $this->db->insert('giftaid_claim_items', [
                    'claim_id' => $claimId,
                    'transaction_id' => $transactionId,
                ]);

                $this->db->execute(
                    "UPDATE transactions SET giftaid_claim_id = ? WHERE id = ?",
                    [$claimId, $transactionId]
                );
            }

            $this->db->commit();

            $this->auditService->log($charityId, $userId, 'giftaid_claim.updated', 'giftaid_claim', $claimId, [
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'transaction_count' => count($transactionIds),
            ]);

        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Submit a claim (mark as submitted to HMRC)
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

        $this->db->beginTransaction();

        try {
            // Update claim status
            $this->db->execute(
                "UPDATE giftaid_claims 
                 SET status = 'submitted', submitted_at = NOW(), 
                     submitted_by_user_id = ?, hmrc_reference = ?
                 WHERE id = ?",
                [$userId, $hmrcReference, $claimId]
            );

            // Mark transactions as claimed
            $this->db->execute(
                "UPDATE transactions 
                 SET giftaid_status = 'claimed' 
                 WHERE giftaid_claim_id = ?",
                [$claimId]
            );

            $this->db->commit();

            $this->auditService->log($charityId, $userId, 'giftaid_claim.submitted', 'giftaid_claim', $claimId);

            $this->logger->info('Gift Aid claim submitted', [
                'charity_id' => $charityId,
                'claim_id' => $claimId,
            ]);
        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Un-submit a claim (revert to draft)
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
            // Update claim status
            $this->db->execute(
                "UPDATE giftaid_claims 
                 SET status = 'draft', submitted_at = NULL, 
                     submitted_by_user_id = NULL, hmrc_reference = NULL
                 WHERE id = ?",
                [$claimId]
            );

            // Revert transaction status
            $this->db->execute(
                "UPDATE transactions 
                 SET giftaid_status = CASE 
                     WHEN has_oneoff_declaration = TRUE OR donor_id IS NOT NULL THEN 'eligible'
                     ELSE 'gasds'
                 END
                 WHERE giftaid_claim_id = ?",
                [$claimId]
            );

            $this->db->commit();

            $this->auditService->log($charityId, $userId, 'giftaid_claim.unsubmitted', 'giftaid_claim', $claimId);

            $this->logger->info('Gift Aid claim un-submitted', [
                'charity_id' => $charityId,
                'claim_id' => $claimId,
            ]);
        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Cancel a draft claim
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
            // Remove claim reference from transactions
            $this->db->execute(
                "UPDATE transactions SET giftaid_claim_id = NULL WHERE giftaid_claim_id = ?",
                [$claimId]
            );

            // Update claim status
            $this->db->execute(
                "UPDATE giftaid_claims SET status = 'cancelled' WHERE id = ?",
                [$claimId]
            );

            $this->db->commit();

            $this->auditService->log($charityId, $userId, 'giftaid_claim.cancelled', 'giftaid_claim', $claimId);
        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Generate claim report data for HMRC format
     */
    public function generateClaimReport(int $claimId, int $charityId): array
    {
        $claim = $this->getClaim($claimId, $charityId);
        if (!$claim) {
            return [];
        }

        $standardDonations = [];
        $gasdsDonations = [];

        foreach ($claim['items'] as $item) {
            $row = [
                'date' => $item['transaction_date'],
                'amount' => $item['amount'],
                'description' => $item['description'],
            ];

            if ($item['giftaid_status'] === 'gasds' || !$item['donor_id']) {
                $gasdsDonations[] = $row;
            } else {
                $row['donor_name'] = $item['donor_name'];
                $row['donor_address'] = trim($item['address_line1'] . ' ' . $item['address_postcode']);
                $standardDonations[] = $row;
            }
        }

        return [
            'claim' => $claim,
            'standard_donations' => $standardDonations,
            'gasds_donations' => $gasdsDonations,
            'summary' => [
                'standard_total' => $claim['total_donations'],
                'standard_giftaid' => $claim['total_giftaid'],
                'gasds_total' => $claim['gasds_donations'],
                'gasds_giftaid' => $claim['gasds_giftaid'],
                'total_giftaid' => $claim['total_giftaid'] + $claim['gasds_giftaid'],
            ],
        ];
    }
}
