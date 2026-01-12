<?php

declare(strict_types=1);

namespace CharityCashbook\Charity;

use CharityCashbook\Core\DatabaseInterface;
use CharityCashbook\Core\Logger;
use CharityCashbook\Core\Exceptions\ValidationException;

/**
 * Service for managing donors and Gift Aid declarations
 */
class DonorService
{
    private DatabaseInterface $db;
    private Logger $logger;
    private AuditService $auditService;

    public function __construct(DatabaseInterface $db, Logger $logger, AuditService $auditService)
    {
        $this->db = $db;
        $this->logger = $logger;
        $this->auditService = $auditService;
    }

    /**
     * Get all donors for a charity with declaration status
     */
    public function getAll(int $charityId, bool $activeOnly = true): array
    {
        $sql = "SELECT d.*, 
                       (SELECT COUNT(*) FROM transactions t WHERE t.donor_id = d.id AND t.is_deleted = FALSE) as donation_count,
                       (SELECT COALESCE(SUM(amount), 0) FROM transactions t WHERE t.donor_id = d.id AND t.is_deleted = FALSE) as total_donated,
                       (SELECT COUNT(*) FROM declarations decl WHERE decl.donor_id = d.id AND decl.withdrawn_date IS NULL) > 0 as has_active_declaration
                FROM donors d
                WHERE d.charity_id = ?";
        
        if ($activeOnly) {
            $sql .= " AND d.is_active = TRUE";
        }
        
        $sql .= " ORDER BY d.name";

        return $this->db->query($sql, [$charityId]);
    }

    /**
     * Search donors by name with optional filters
     */
    public function search(int $charityId, string $query, int $limit = 50, int $offset = 0, bool $giftaidOnly = false): array
    {
        $sql = "SELECT d.*,
                       (SELECT COUNT(*) FROM transactions t WHERE t.donor_id = d.id AND t.is_deleted = FALSE) as donation_count,
                       (SELECT COALESCE(SUM(amount), 0) FROM transactions t WHERE t.donor_id = d.id AND t.is_deleted = FALSE) as total_donated,
                       (SELECT COUNT(*) FROM declarations decl WHERE decl.donor_id = d.id AND decl.withdrawn_date IS NULL) > 0 as has_active_declaration
                FROM donors d
                WHERE d.charity_id = ? AND d.is_active = TRUE";

        $params = [$charityId];

        if (!empty($query)) {
            $sql .= " AND d.name LIKE ?";
            $params[] = '%' . $query . '%';
        }

        if ($giftaidOnly) {
            $sql .= " AND (SELECT COUNT(*) FROM declarations decl WHERE decl.donor_id = d.id AND decl.withdrawn_date IS NULL) > 0";
        }

        $sql .= " ORDER BY d.name LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        return $this->db->query($sql, $params);
    }

    /**
     * Count donors matching search criteria
     */
    public function countDonors(int $charityId, string $query = '', bool $giftaidOnly = false): int
    {
        $sql = "SELECT COUNT(*) as count FROM donors d WHERE d.charity_id = ? AND d.is_active = TRUE";
        $params = [$charityId];

        if (!empty($query)) {
            $sql .= " AND d.name LIKE ?";
            $params[] = '%' . $query . '%';
        }

        if ($giftaidOnly) {
            $sql .= " AND (SELECT COUNT(*) FROM declarations decl WHERE decl.donor_id = d.id AND decl.withdrawn_date IS NULL) > 0";
        }

        $result = $this->db->queryOne($sql, $params);
        return (int) ($result['count'] ?? 0);
    }

    /**
     * Quick search donors by name (for autocomplete in transaction forms)
     * Returns minimal data for privacy
     */
    public function quickSearch(int $charityId, string $query): array
    {
        return $this->db->query(
            "SELECT id, name FROM donors 
             WHERE charity_id = ? AND is_active = TRUE AND name LIKE ?
             ORDER BY name
             LIMIT 20",
            [$charityId, '%' . $query . '%']
        );
    }

    /**
     * Get donor name only (for standard users)
     */
    public function getNameOnly(int $id, int $charityId): ?array
    {
        return $this->db->queryOne(
            "SELECT id, name FROM donors WHERE id = ? AND charity_id = ?",
            [$id, $charityId]
        );
    }

    /**
     * Get donor by ID (alias for getFullDetails without PII logging)
     */
    public function get(int $id, int $charityId): ?array
    {
        return $this->db->queryOne(
            "SELECT * FROM donors WHERE id = ? AND charity_id = ?",
            [$id, $charityId]
        );
    }

    /**
     * Get donations for a donor
     */
    public function getDonations(int $donorId, int $charityId, int $limit = 50): array
    {
        return $this->db->query(
            "SELECT t.*, f.name as fund_name
             FROM transactions t
             LEFT JOIN funds f ON f.id = t.fund_id
             WHERE t.donor_id = ? AND t.charity_id = ? AND t.is_deleted = FALSE
             ORDER BY t.transaction_date DESC
             LIMIT ?",
            [$donorId, $charityId, $limit]
        );
    }

    /**
     * Get full donor details including address (for admin users)
     * Logs PII access
     */
    public function getFullDetails(int $id, int $charityId, int $userId): ?array
    {
        $donor = $this->db->queryOne(
            "SELECT * FROM donors WHERE id = ? AND charity_id = ?",
            [$id, $charityId]
        );

        if ($donor) {
            // Log PII access
            $this->auditService->logDonorAccess($charityId, $userId, $id, 'view_address');
        }

        return $donor;
    }

    /**
     * Create a donor
     */
    public function create(int $charityId, int $userId, array $data): int
    {
        $this->validate($data);

        $id = $this->db->insert('donors', [
            'charity_id' => $charityId,
            'name' => $data['name'],
            'address_line1' => $data['address_line1'] ?? null,
            'address_line2' => $data['address_line2'] ?? null,
            'address_city' => $data['address_city'] ?? null,
            'address_postcode' => $data['address_postcode'] ?? null,
            'email' => $data['email'] ?? null,
            'notes' => $data['notes'] ?? null,
            'is_active' => true,
            'created_by_user_id' => $userId,
        ]);

        $this->auditService->log($charityId, $userId, 'donor.created', 'donor', $id);

        $this->logger->info('Donor created', [
            'charity_id' => $charityId,
            'donor_id' => $id,
        ]);

        return $id;
    }

    /**
     * Update a donor
     */
    public function update(int $id, int $charityId, int $userId, array $data): void
    {
        $this->validate($data);

        $this->db->update('donors', [
            'name' => $data['name'],
            'address_line1' => $data['address_line1'] ?? null,
            'address_line2' => $data['address_line2'] ?? null,
            'address_city' => $data['address_city'] ?? null,
            'address_postcode' => $data['address_postcode'] ?? null,
            'email' => $data['email'] ?? null,
            'notes' => $data['notes'] ?? null,
            'updated_by_user_id' => $userId,
        ], 'id = ? AND charity_id = ?', [$id, $charityId]);

        $this->auditService->log($charityId, $userId, 'donor.updated', 'donor', $id);
        $this->auditService->logDonorAccess($charityId, $userId, $id, 'edit');
    }

    /**
     * Deactivate a donor
     */
    public function deactivate(int $id, int $charityId, int $userId): void
    {
        $this->db->execute(
            "UPDATE donors SET is_active = FALSE, updated_by_user_id = ? 
             WHERE id = ? AND charity_id = ?",
            [$userId, $id, $charityId]
        );

        $this->auditService->log($charityId, $userId, 'donor.deactivated', 'donor', $id);
    }

    /**
     * Update just the address fields on a donor (for syncing from member record)
     */
    public function updateAddress(int $donorId, array $address): void
    {
        $this->db->execute(
            "UPDATE donors SET
                address_line1 = ?,
                address_line2 = ?,
                address_city = ?,
                address_postcode = ?
             WHERE id = ?",
            [
                !empty($address['address_line1']) ? $address['address_line1'] : null,
                !empty($address['address_line2']) ? $address['address_line2'] : null,
                !empty($address['address_city']) ? $address['address_city'] : null,
                !empty($address['address_postcode']) ? $address['address_postcode'] : null,
                $donorId,
            ]
        );
    }

    /**
     * Delete a donor (and their declarations)
     */
    public function delete(int $id, int $charityId): void
    {
        // Delete declarations first
        $this->db->execute(
            "DELETE FROM declarations WHERE donor_id = ? AND charity_id = ?",
            [$id, $charityId]
        );

        // Delete the donor
        $this->db->execute(
            "DELETE FROM donors WHERE id = ? AND charity_id = ?",
            [$id, $charityId]
        );
    }

    /**
     * Validate donor data
     */
    private function validate(array $data): void
    {
        $errors = [];

        if (empty($data['name'])) {
            $errors['name'] = 'Name is required';
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }
    }

    // ========== Gift Aid Declarations ==========

    /**
     * Get declarations for a donor
     */
    public function getDeclarations(int $donorId, int $charityId): array
    {
        return $this->db->query(
            "SELECT d.*, COALESCE(cu.display_name, u.display_name) as created_by_name
             FROM declarations d
             LEFT JOIN users u ON u.id = d.created_by_user_id
             LEFT JOIN donors don ON don.id = d.donor_id
             LEFT JOIN charity_users cu ON cu.user_id = d.created_by_user_id AND cu.charity_id = don.charity_id
             WHERE d.donor_id = ?
             ORDER BY d.start_date DESC",
            [$donorId]
        );
    }

    /**
     * Get current declaration status for a donor
     */
    public function getCurrentDeclarationStatus(int $donorId): string
    {
        $declaration = $this->db->queryOne(
            "SELECT * FROM declarations 
             WHERE donor_id = ? 
             ORDER BY start_date DESC 
             LIMIT 1",
            [$donorId]
        );

        if (!$declaration) {
            return 'none';
        }

        if ($declaration['withdrawn_date']) {
            return 'withdrawn';
        }

        return 'active';
    }

    /**
     * Check if donor has valid declaration for a specific date
     */
    public function hasValidDeclaration(int $donorId, string $date): bool
    {
        $count = $this->db->queryValue(
            "SELECT COUNT(*) FROM declarations 
             WHERE donor_id = ? 
             AND start_date <= ?
             AND (withdrawn_date IS NULL OR withdrawn_date >= ?)",
            [$donorId, $date, $date]
        );

        return $count > 0;
    }

    /**
     * Add a declaration
     */
    public function addDeclaration(int $donorId, int $charityId, int $userId, array $data): int
    {
        // Verify donor belongs to charity
        $donor = $this->getNameOnly($donorId, $charityId);
        if (!$donor) {
            throw new ValidationException(['donor_id' => 'Donor not found']);
        }

        $id = $this->db->insert('declarations', [
            'donor_id' => $donorId,
            'start_date' => $data['start_date'],
            'covers_past_donations' => $data['covers_past_donations'] ?? false,
            'covers_past_from_date' => $data['covers_past_from_date'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by_user_id' => $userId,
        ]);

        $this->auditService->log($charityId, $userId, 'declaration.created', 'declaration', $id, [
            'donor_id' => $donorId,
        ]);

        // Update eligible transactions
        $this->updateTransactionEligibility($donorId, $charityId);

        return $id;
    }

    /**
     * Withdraw a declaration
     */
    public function withdrawDeclaration(int $declarationId, int $charityId, int $userId, string $withdrawnDate): void
    {
        $declaration = $this->db->queryOne(
            "SELECT d.*, do.charity_id 
             FROM declarations d
             JOIN donors do ON do.id = d.donor_id
             WHERE d.id = ? AND do.charity_id = ?",
            [$declarationId, $charityId]
        );

        if (!$declaration) {
            throw new ValidationException(['declaration_id' => 'Declaration not found']);
        }

        $this->db->execute(
            "UPDATE declarations SET withdrawn_date = ? WHERE id = ?",
            [$withdrawnDate, $declarationId]
        );

        $this->auditService->log($charityId, $userId, 'declaration.withdrawn', 'declaration', $declarationId);

        // Update eligible transactions
        $this->updateTransactionEligibility($declaration['donor_id'], $charityId);
    }

    /**
     * Update Gift Aid eligibility for a donor's transactions
     */
    private function updateTransactionEligibility(int $donorId, int $charityId): void
    {
        // Get all unclaimed transactions for this donor
        $transactions = $this->db->query(
            "SELECT id, transaction_date, has_oneoff_declaration, giftaid_status
             FROM transactions 
             WHERE donor_id = ? AND charity_id = ? AND is_deleted = FALSE 
             AND giftaid_status NOT IN ('claimed', 'not_applicable', 'gasds')",
            [$donorId, $charityId]
        );

        foreach ($transactions as $transaction) {
            // Skip if has one-off declaration
            if ($transaction['has_oneoff_declaration']) {
                continue;
            }

            $hasDeclaration = $this->hasValidDeclaration($donorId, $transaction['transaction_date']);
            $newStatus = $hasDeclaration ? 'eligible' : 'not_eligible';

            if ($transaction['giftaid_status'] !== $newStatus) {
                $this->db->execute(
                    "UPDATE transactions SET giftaid_status = ? WHERE id = ?",
                    [$newStatus, $transaction['id']]
                );
            }
        }
    }

    /**
     * Get donors with Gift Aid eligible donations
     */
    public function getDonorsWithEligibleDonations(int $charityId, string $dateFrom, string $dateTo): array
    {
        return $this->db->query(
            "SELECT d.*, 
                    COUNT(t.id) as eligible_count,
                    SUM(t.amount) as eligible_total
             FROM donors d
             JOIN transactions t ON t.donor_id = d.id
             WHERE t.charity_id = ?
             AND t.is_deleted = FALSE
             AND t.giftaid_status = 'eligible'
             AND t.transaction_date >= ?
             AND t.transaction_date <= ?
             GROUP BY d.id
             ORDER BY d.name",
            [$charityId, $dateFrom, $dateTo]
        );
    }
}
