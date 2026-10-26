<?php

declare(strict_types=1);

namespace CharityCashbook\Charity;

use CharityCashbook\Core\DatabaseInterface;
use CharityCashbook\Core\Logger;
use CharityCashbook\Core\Exceptions\ValidationException;

/**
 * Service for managing charity members and membership dues
 */
class MemberService
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
     * Get all members for a charity
     */
    public function getAll(int $charityId, array $filters = []): array
    {
        $sql = "SELECT m.*, 
                       d.name as donor_name,
                       (SELECT COUNT(*) FROM declarations decl WHERE decl.donor_id = m.donor_id AND decl.withdrawn_date IS NULL) > 0 as has_giftaid_declaration,
                       (SELECT COALESCE(SUM(md.amount_paid), 0) FROM membership_dues md WHERE md.member_id = m.id) as total_paid,
                       (SELECT MAX(md.period_end) FROM membership_dues md WHERE md.member_id = m.id AND md.status = 'paid') as paid_until
                FROM members m
                LEFT JOIN donors d ON m.donor_id = d.id
                WHERE m.charity_id = ?";
        
        $params = [$charityId];

        // Filter by active status
        if (isset($filters['active_only']) && $filters['active_only']) {
            $sql .= " AND m.is_active = TRUE";
        }

        // Filter by trustee status
        if (isset($filters['trustees_only']) && $filters['trustees_only']) {
            $sql .= " AND m.is_trustee = TRUE AND (m.trustee_end_date IS NULL OR m.trustee_end_date >= CURDATE())";
        }

        // Filter by membership type
        if (!empty($filters['membership_type'])) {
            $sql .= " AND m.membership_type = ?";
            $params[] = $filters['membership_type'];
        }

        // Search by name
        if (!empty($filters['search'])) {
            $sql .= " AND (m.name LIKE ? OR m.email LIKE ? OR m.member_number LIKE ?)";
            $search = '%' . $filters['search'] . '%';
            $params = array_merge($params, [$search, $search, $search]);
        }

        $sql .= " ORDER BY m.name";

        // Pagination
        if (isset($filters['limit'])) {
            $sql .= " LIMIT " . (int) $filters['limit'];
            if (isset($filters['offset'])) {
                $sql .= " OFFSET " . (int) $filters['offset'];
            }
        }

        return $this->db->query($sql, $params);
    }

    /**
     * Count members for pagination
     */
    public function countMembers(int $charityId, array $filters = []): int
    {
        $sql = "SELECT COUNT(*) as count FROM members m WHERE m.charity_id = ?";
        $params = [$charityId];

        if (isset($filters['active_only']) && $filters['active_only']) {
            $sql .= " AND m.is_active = TRUE";
        }

        if (isset($filters['trustees_only']) && $filters['trustees_only']) {
            $sql .= " AND m.is_trustee = TRUE AND (m.trustee_end_date IS NULL OR m.trustee_end_date >= CURDATE())";
        }

        if (!empty($filters['membership_type'])) {
            $sql .= " AND m.membership_type = ?";
            $params[] = $filters['membership_type'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (m.name LIKE ? OR m.email LIKE ? OR m.member_number LIKE ?)";
            $search = '%' . $filters['search'] . '%';
            $params = array_merge($params, [$search, $search, $search]);
        }

        $result = $this->db->queryOne($sql, $params);
        return (int) ($result['count'] ?? 0);
    }

    /**
     * Get a single member by ID
     */
    public function getById(int $memberId): ?array
    {
        return $this->db->queryOne(
            "SELECT m.*, 
                    d.name as donor_name,
                    d.id as donor_id,
                    (SELECT COUNT(*) FROM declarations decl WHERE decl.donor_id = m.donor_id AND decl.withdrawn_date IS NULL) > 0 as has_giftaid_declaration
             FROM members m
             LEFT JOIN donors d ON m.donor_id = d.id
             WHERE m.id = ?",
            [$memberId]
        );
    }

    /**
     * Get a member by ID with charity validation
     */
    public function get(int $memberId, int $charityId): ?array
    {
        return $this->db->queryOne(
            "SELECT m.*, 
                    d.name as donor_name,
                    d.id as donor_id,
                    (SELECT COUNT(*) FROM declarations decl WHERE decl.donor_id = m.donor_id AND decl.withdrawn_date IS NULL) > 0 as has_giftaid_declaration
             FROM members m
             LEFT JOIN donors d ON m.donor_id = d.id
             WHERE m.id = ? AND m.charity_id = ?",
            [$memberId, $charityId]
        );
    }

    /**
     * Create a new member
     */
    public function create(int $charityId, array $data, ?int $userId = null): int
    {
        $this->validateMember($data);

        // Check for duplicate member number
        if (!empty($data['member_number'])) {
            $existing = $this->db->queryOne(
                "SELECT id FROM members WHERE charity_id = ? AND member_number = ?",
                [$charityId, $data['member_number']]
            );
            if ($existing) {
                throw new ValidationException(['Member number already in use']);
            }
        }

        $memberId = $this->db->insert('members', [
            'charity_id' => $charityId,
            'donor_id' => !empty($data['donor_id']) ? (int) $data['donor_id'] : null,
            'name' => trim($data['name']),
            'email' => !empty($data['email']) ? $data['email'] : null,
            'phone' => !empty($data['phone']) ? $data['phone'] : null,
            'address_line1' => !empty($data['address_line1']) ? $data['address_line1'] : null,
            'address_line2' => !empty($data['address_line2']) ? $data['address_line2'] : null,
            'address_city' => !empty($data['address_city']) ? $data['address_city'] : null,
            'address_county' => !empty($data['address_county']) ? $data['address_county'] : null,
            'address_postcode' => !empty($data['address_postcode']) ? $data['address_postcode'] : null,
            'member_number' => !empty($data['member_number']) ? $data['member_number'] : null,
            'membership_type' => $data['membership_type'] ?? 'Standard',
            'joined_date' => !empty($data['joined_date']) ? $data['joined_date'] : date('Y-m-d'),
            'notes' => !empty($data['notes']) ? $data['notes'] : null,
            'created_by_user_id' => $userId,
        ]);

        $this->logger->info("Member created", ['charity_id' => $charityId, 'member_id' => $memberId]);

        return $memberId;
    }

    /**
     * Update a member
     */
    public function update(int $memberId, array $data, ?int $userId = null): void
    {
        $member = $this->getById($memberId);
        if (!$member) {
            throw new ValidationException(['Member not found']);
        }

        $this->validateMember($data);

        // Check for duplicate member number (excluding current member)
        if (!empty($data['member_number'])) {
            $existing = $this->db->queryOne(
                "SELECT id FROM members WHERE charity_id = ? AND member_number = ? AND id != ?",
                [$member['charity_id'], $data['member_number'], $memberId]
            );
            if ($existing) {
                throw new ValidationException(['Member number already in use']);
            }
        }

        $this->db->execute(
            "UPDATE members SET 
                donor_id = ?,
                name = ?,
                email = ?,
                phone = ?,
                address_line1 = ?,
                address_line2 = ?,
                address_city = ?,
                address_county = ?,
                address_postcode = ?,
                member_number = ?,
                membership_type = ?,
                joined_date = ?,
                left_date = ?,
                is_active = ?,
                notes = ?,
                updated_by_user_id = ?
             WHERE id = ?",
            [
                !empty($data['donor_id']) ? (int) $data['donor_id'] : null,
                trim($data['name']),
                !empty($data['email']) ? $data['email'] : null,
                !empty($data['phone']) ? $data['phone'] : null,
                !empty($data['address_line1']) ? $data['address_line1'] : null,
                !empty($data['address_line2']) ? $data['address_line2'] : null,
                !empty($data['address_city']) ? $data['address_city'] : null,
                !empty($data['address_county']) ? $data['address_county'] : null,
                !empty($data['address_postcode']) ? $data['address_postcode'] : null,
                !empty($data['member_number']) ? $data['member_number'] : null,
                $data['membership_type'] ?? 'Standard',
                !empty($data['joined_date']) ? $data['joined_date'] : null,
                !empty($data['left_date']) ? $data['left_date'] : null,
                !isset($data['is_active']) || $data['is_active'],
                !empty($data['notes']) ? $data['notes'] : null,
                $userId,
                $memberId,
            ]
        );

        $this->logger->info("Member updated", ['member_id' => $memberId]);
    }

    /**
     * Update just the address fields on a member (for syncing from donor record)
     */
    public function updateAddress(int $memberId, array $address): void
    {
        $this->db->execute(
            "UPDATE members SET
                address_line1 = ?,
                address_line2 = ?,
                address_city = ?,
                address_county = ?,
                address_postcode = ?
             WHERE id = ?",
            [
                !empty($address['address_line1']) ? $address['address_line1'] : null,
                !empty($address['address_line2']) ? $address['address_line2'] : null,
                !empty($address['address_city']) ? $address['address_city'] : null,
                !empty($address['address_county']) ? $address['address_county'] : null,
                !empty($address['address_postcode']) ? $address['address_postcode'] : null,
                $memberId,
            ]
        );
    }

    /**
     * Delete a member (and their dues)
     */
    public function delete(int $memberId, int $charityId): void
    {
        // Delete dues first
        $this->db->execute(
            "DELETE FROM membership_dues WHERE member_id = ?",
            [$memberId]
        );

        // Delete the member
        $this->db->execute(
            "DELETE FROM members WHERE id = ? AND charity_id = ?",
            [$memberId, $charityId]
        );
    }

    /**
     * Get current trustees (from history table)
     */
    public function getTrustees(int $charityId): array
    {
        return $this->db->query(
            "SELECT m.*, 
                    tr.role as current_trustee_role,
                    tr.start_date as current_trustee_start
             FROM members m
             JOIN member_trustee_roles tr ON tr.member_id = m.id 
                AND (tr.end_date IS NULL OR tr.end_date >= CURDATE())
             WHERE m.charity_id = ? 
               AND m.is_active = TRUE
             ORDER BY tr.role, m.name",
            [$charityId]
        );
    }

    /**
     * Get trustee role history for a member
     */
    public function getTrusteeHistory(int $memberId): array
    {
        return $this->db->query(
            "SELECT * FROM member_trustee_roles 
             WHERE member_id = ? 
             ORDER BY start_date DESC",
            [$memberId]
        );
    }

    /**
     * Add a trustee role to a member
     */
    public function addTrusteeRole(int $charityId, int $memberId, array $data, ?int $userId = null): int
    {
        // Mark member as trustee
        $this->db->execute(
            "UPDATE members SET is_trustee = TRUE, trustee_role = ?, trustee_start_date = ? WHERE id = ?",
            [$data['role'] ?? null, $data['start_date'], $memberId]
        );

        return $this->db->insert('member_trustee_roles', [
            'charity_id' => $charityId,
            'member_id' => $memberId,
            'role' => $data['role'] ?? null,
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by_user_id' => $userId,
        ]);
    }

    /**
     * End a trustee role
     */
    public function endTrusteeRole(int $roleId, string $endDate): void
    {
        $this->db->execute(
            "UPDATE member_trustee_roles SET end_date = ? WHERE id = ?",
            [$endDate, $roleId]
        );

        // Check if member has any current roles left
        $role = $this->db->queryOne("SELECT member_id FROM member_trustee_roles WHERE id = ?", [$roleId]);
        if ($role) {
            $currentRoles = $this->db->queryOne(
                "SELECT COUNT(*) as cnt FROM member_trustee_roles WHERE member_id = ? AND (end_date IS NULL OR end_date >= CURDATE())",
                [$role['member_id']]
            );
            if (($currentRoles['cnt'] ?? 0) == 0) {
                // No current roles - update member record
                $this->db->execute(
                    "UPDATE members SET is_trustee = FALSE, trustee_end_date = ? WHERE id = ?",
                    [$endDate, $role['member_id']]
                );
            }
        }
    }

    /**
     * Get a trustee role by ID
     */
    public function getTrusteeRole(int $roleId): ?array
    {
        return $this->db->queryOne(
            "SELECT tr.*, m.name as member_name, m.charity_id
             FROM member_trustee_roles tr
             JOIN members m ON tr.member_id = m.id
             WHERE tr.id = ?",
            [$roleId]
        );
    }

    /**
     * Update a trustee role
     */
    public function updateTrusteeRole(int $roleId, array $data): void
    {
        $this->db->execute(
            "UPDATE member_trustee_roles SET role = ?, start_date = ?, end_date = ?, notes = ? WHERE id = ?",
            [
                $data['role'] ?? null,
                $data['start_date'],
                !empty($data['end_date']) ? $data['end_date'] : null,
                $data['notes'] ?? null,
                $roleId
            ]
        );

        // Update the member's current trustee info if this is a current role
        $role = $this->getTrusteeRole($roleId);
        if ($role && (empty($data['end_date']) || $data['end_date'] >= date('Y-m-d'))) {
            // This is a current role - update member record
            $this->db->execute(
                "UPDATE members SET trustee_role = ?, trustee_start_date = ? WHERE id = ?",
                [$data['role'] ?? null, $data['start_date'], $role['member_id']]
            );
        }
    }

    /**
     * Get all trustee history for a charity (for reports)
     */
    public function getAllTrusteeHistory(int $charityId): array
    {
        return $this->db->query(
            "SELECT tr.*, 
                    m.name, m.member_number
             FROM member_trustee_roles tr
             JOIN members m ON tr.member_id = m.id
             WHERE tr.charity_id = ?
             ORDER BY tr.start_date DESC",
            [$charityId]
        );
    }

    /**
     * Get membership types for a charity
     */
    public function getMembershipTypes(int $charityId): array
    {
        return $this->db->query(
            "SELECT * FROM membership_types WHERE charity_id = ? AND is_active = TRUE ORDER BY sort_order, name",
            [$charityId]
        );
    }

    /**
     * Create a membership type
     */
    public function createMembershipType(int $charityId, array $data): int
    {
        $annualFee = $data['annual_fee'] ?? null;
        if ($annualFee === '' || $annualFee === null) {
            $annualFee = '0.00';
        }
        
        return $this->db->insert('membership_types', [
            'charity_id' => $charityId,
            'name' => trim($data['name']),
            'description' => $data['description'] ?? null,
            'annual_fee' => round((float) $annualFee, 2),
            'sort_order' => $data['sort_order'] ?? 0,
        ]);
    }

    /**
     * Update a membership type
     */
    public function updateMembershipType(int $typeId, array $data): void
    {
        $annualFee = $data['annual_fee'] ?? null;
        if ($annualFee === '' || $annualFee === null) {
            $annualFee = '0.00';
        }
        
        $this->db->execute(
            "UPDATE membership_types SET name = ?, description = ?, annual_fee = ?, sort_order = ? WHERE id = ?",
            [
                trim($data['name']),
                $data['description'] ?? null,
                round((float) $annualFee, 2),
                $data['sort_order'] ?? 0,
                $typeId,
            ]
        );
    }

    // ========== MEMBERSHIP DUES ==========

    /**
     * Get dues for a member
     */
    public function getMemberDues(int $memberId): array
    {
        return $this->db->query(
            "SELECT md.*, t.description as transaction_description, t.transaction_date as payment_date
             FROM membership_dues md
             LEFT JOIN transactions t ON md.transaction_id = t.id
             WHERE md.member_id = ?
             ORDER BY md.period_start DESC",
            [$memberId]
        );
    }

    /**
     * Get all dues for a charity with member info
     */
    public function getAllDues(int $charityId, array $filters = []): array
    {
        $sql = "SELECT md.*, 
                       m.name, m.member_number, m.email,
                       t.description as transaction_description
                FROM membership_dues md
                JOIN members m ON md.member_id = m.id
                LEFT JOIN transactions t ON md.transaction_id = t.id
                WHERE md.charity_id = ?";
        
        $params = [$charityId];

        // Filter by status
        if (!empty($filters['status'])) {
            $sql .= " AND md.status = ?";
            $params[] = $filters['status'];
        }

        // Filter by period label
        if (!empty($filters['period_label'])) {
            $sql .= " AND md.period_label = ?";
            $params[] = $filters['period_label'];
        }

        // Filter by period year (legacy/fallback)
        if (!empty($filters['year']) && empty($filters['period_label'])) {
            $sql .= " AND YEAR(md.period_start) = ?";
            $params[] = $filters['year'];
        }

        // Filter by member
        if (!empty($filters['member_id'])) {
            $sql .= " AND md.member_id = ?";
            $params[] = $filters['member_id'];
        }

        $sql .= " ORDER BY md.period_start DESC, m.name";

        return $this->db->query($sql, $params);
    }

    /**
     * Get distinct period labels for dues filter dropdown
     */
    public function getDuesPeriodLabels(int $charityId): array
    {
        return $this->db->query(
            "SELECT DISTINCT period_label 
             FROM membership_dues 
             WHERE charity_id = ? AND period_label IS NOT NULL
             ORDER BY period_start DESC",
            [$charityId]
        );
    }

    /**
     * Get dues statistics for a specific period
     */
    public function getDuesStatistics(int $charityId, ?string $periodLabel = null): array
    {
        $sql = "SELECT 
                    COUNT(*) as total_dues,
                    SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_count,
                    SUM(CASE WHEN status IN ('pending', 'partial') THEN 1 ELSE 0 END) as unpaid_count,
                    SUM(amount_due) as total_due,
                    SUM(amount_paid) as total_paid
                FROM membership_dues 
                WHERE charity_id = ?";
        
        $params = [$charityId];
        
        if ($periodLabel) {
            $sql .= " AND period_label = ?";
            $params[] = $periodLabel;
        }
        
        $stats = $this->db->queryOne($sql, $params);
        
        return [
            'dues_total' => (int) ($stats['total_dues'] ?? 0),
            'dues_paid' => (int) ($stats['paid_count'] ?? 0),
            'dues_unpaid' => (int) ($stats['unpaid_count'] ?? 0),
            'amount_due' => (float) ($stats['total_due'] ?? 0),
            'amount_paid' => (float) ($stats['total_paid'] ?? 0),
        ];
    }

    /**
     * Create a dues record for a member
     */
    public function createDues(int $charityId, int $memberId, array $data, ?int $userId = null): int
    {
        // Check for duplicate period
        $existing = $this->db->queryOne(
            "SELECT id FROM membership_dues WHERE member_id = ? AND period_start = ?",
            [$memberId, $data['period_start']]
        );
        if ($existing) {
            throw new ValidationException(['Dues already exist for this period']);
        }

        // Check if member has Gift Aid declaration (via linked donor)
        $member = $this->getById($memberId);
        $isGiftAidEligible = false;
        if ($member && $member['donor_id']) {
            $declaration = $this->db->queryOne(
                "SELECT id FROM declarations WHERE donor_id = ? AND withdrawn_date IS NULL",
                [$member['donor_id']]
            );
            $isGiftAidEligible = (bool) $declaration;
        }

        $status = 'pending';
        $amountPaid = (float) ($data['amount_paid'] ?? 0);
        $amountDue = (float) $data['amount_due'];
        
        if ($amountPaid >= $amountDue) {
            $status = 'paid';
        } elseif ($amountPaid > 0) {
            $status = 'partial';
        }

        return $this->db->insert('membership_dues', [
            'charity_id' => $charityId,
            'member_id' => $memberId,
            'period_start' => $data['period_start'],
            'period_end' => $data['period_end'],
            'period_label' => $data['period_label'] ?? null,
            'amount_due' => $amountDue,
            'amount_paid' => $amountPaid,
            'status' => $data['status'] ?? $status,
            'transaction_id' => $data['transaction_id'] ?? null,
            'is_giftaid_eligible' => $isGiftAidEligible,
            'notes' => $data['notes'] ?? null,
            'created_by_user_id' => $userId,
        ]);
    }

    /**
     * Get a dues record by ID with charity validation
     */
    public function getDuesRecord(int $duesId, int $charityId): ?array
    {
        return $this->db->queryOne(
            "SELECT * FROM membership_dues WHERE id = ? AND charity_id = ?",
            [$duesId, $charityId]
        );
    }

    /**
     * Record a payment against dues
     */
    public function recordDuesPayment(int $duesId, int $charityId, float $amount, ?int $transactionId = null): void
    {
        $dues = $this->getDuesRecord($duesId, $charityId);
        if (!$dues) {
            throw new ValidationException(['Dues record not found']);
        }

        $newAmountPaid = (float) $dues['amount_paid'] + $amount;
        $amountDue = (float) $dues['amount_due'];

        $status = 'partial';
        if ($newAmountPaid >= $amountDue) {
            $status = 'paid';
        } elseif ($newAmountPaid <= 0) {
            $status = 'pending';
        }

        $this->db->execute(
            "UPDATE membership_dues SET amount_paid = ?, status = ?, transaction_id = COALESCE(?, transaction_id) WHERE id = ?",
            [$newAmountPaid, $status, $transactionId, $duesId]
        );
    }

    /**
     * Waive dues
     */
    public function waiveDues(int $duesId, int $charityId, ?string $reason = null): void
    {
        $dues = $this->getDuesRecord($duesId, $charityId);
        if (!$dues) {
            throw new ValidationException(['Dues record not found']);
        }
        
        $notes = $reason ? "Waived: $reason" : "Waived";
        $this->db->execute(
            "UPDATE membership_dues SET status = 'waived', notes = CONCAT(COALESCE(notes, ''), '\n', ?) WHERE id = ?",
            [$notes, $duesId]
        );
    }

    /**
     * Undo a payment or waiver - revert dues to pending status
     */
    public function undoDuesStatus(int $duesId, int $charityId): void
    {
        $dues = $this->getDuesRecord($duesId, $charityId);
        if (!$dues) {
            throw new ValidationException(['Dues record not found']);
        }
        
        $newStatus = $dues['amount_paid'] > 0 && $dues['amount_paid'] < $dues['amount_due'] 
            ? 'partial' 
            : 'pending';
        
        $this->db->execute(
            "UPDATE membership_dues SET status = ?, amount_paid = 0, notes = CONCAT(COALESCE(notes, ''), '\nStatus reset to pending') WHERE id = ?",
            [$newStatus, $duesId]
        );
    }

    /**
     * Delete a dues record (e.g. one created with the wrong period).
     * Only allowed when no payment has been recorded against it.
     */
    public function deleteDues(int $duesId, int $charityId): void
    {
        $dues = $this->getDuesRecord($duesId, $charityId);
        if (!$dues) {
            throw new ValidationException(['Dues record not found']);
        }

        if ((float) $dues['amount_paid'] > 0 || $dues['transaction_id']) {
            throw new ValidationException(['Cannot delete dues with a payment recorded. Undo the payment first.']);
        }

        $this->db->execute(
            "DELETE FROM membership_dues WHERE id = ? AND charity_id = ?",
            [$duesId, $charityId]
        );
    }

    /**
     * Generate bulk dues for a period
     */
    public function generateBulkDues(int $charityId, array $data, ?int $userId = null): int
    {
        $members = $this->getAll($charityId, ['active_only' => true]);
        $count = 0;

        // Check if custom amount was provided
        $useCustomFee = isset($data['fee_source']) && $data['fee_source'] === 'custom';
        $customFee = $useCustomFee && isset($data['amount_due']) && $data['amount_due'] !== '' 
            ? (float) $data['amount_due'] 
            : null;

        foreach ($members as $member) {
            // Check if dues already exist for this member/period
            $existing = $this->db->queryOne(
                "SELECT id FROM membership_dues WHERE member_id = ? AND period_start = ?",
                [$member['id'], $data['period_start']]
            );
            if ($existing) {
                continue;
            }

            // Get fee - either custom or from membership type
            if ($customFee !== null) {
                $fee = $customFee;
            } else {
                $type = $this->db->queryOne(
                    "SELECT annual_fee FROM membership_types WHERE charity_id = ? AND name = ?",
                    [$charityId, $member['membership_type']]
                );
                $fee = $type ? (float) $type['annual_fee'] : 0;
            }

            // Create dues record (even if fee is 0 - some memberships are free)
            $this->createDues($charityId, $member['id'], [
                'period_start' => $data['period_start'],
                'period_end' => $data['period_end'],
                'period_label' => $data['period_label'] ?? null,
                'amount_due' => $fee,
            ], $userId);
            $count++;
        }

        return $count;
    }

    /**
     * Get membership statistics
     */
    public function getStatistics(int $charityId, ?array $charity = null): array
    {
        $totalMembers = $this->db->queryOne(
            "SELECT COUNT(*) as count FROM members WHERE charity_id = ? AND is_active = TRUE",
            [$charityId]
        )['count'] ?? 0;

        $totalTrustees = $this->db->queryOne(
            "SELECT COUNT(*) as count FROM members WHERE charity_id = ? AND is_active = TRUE AND is_trustee = TRUE AND (trustee_end_date IS NULL OR trustee_end_date >= CURDATE())",
            [$charityId]
        )['count'] ?? 0;

        // Use configurable membership period if charity data provided, otherwise fall back to period_label
        if ($charity) {
            $currentPeriod = $this->calculateMembershipPeriod($charity);
            $periodLabel = $currentPeriod['period_label'];
        } else {
            // Fallback: use the most recent period label from dues records
            $periodLabel = date('Y');
        }
        
        $duesStats = $this->db->queryOne(
            "SELECT 
                COUNT(*) as total_dues,
                SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_count,
                SUM(CASE WHEN status IN ('pending', 'partial') THEN 1 ELSE 0 END) as unpaid_count,
                SUM(amount_due) as total_due,
                SUM(amount_paid) as total_paid
             FROM membership_dues 
             WHERE charity_id = ? AND period_label = ?",
            [$charityId, $periodLabel]
        );

        return [
            'total_members' => (int) $totalMembers,
            'total_trustees' => (int) $totalTrustees,
            'dues_total' => (int) ($duesStats['total_dues'] ?? 0),
            'dues_paid' => (int) ($duesStats['paid_count'] ?? 0),
            'dues_unpaid' => (int) ($duesStats['unpaid_count'] ?? 0),
            'amount_due' => (float) ($duesStats['total_due'] ?? 0),
            'amount_paid' => (float) ($duesStats['total_paid'] ?? 0),
            'period_label' => $periodLabel,
        ];
    }

    /**
     * Search for members to link as donors
     */
    public function searchUnlinkedMembers(int $charityId, string $query): array
    {
        return $this->db->query(
            "SELECT id, name, email, member_number
             FROM members 
             WHERE charity_id = ? 
               AND donor_id IS NULL
               AND is_active = TRUE
               AND name LIKE ?
             ORDER BY name
             LIMIT 20",
            [$charityId, "%$query%"]
        );
    }

    /**
     * Link a member to a donor record
     */
    public function linkToDonor(int $memberId, int $donorId): void
    {
        $this->db->execute(
            "UPDATE members SET donor_id = ? WHERE id = ?",
            [$donorId, $memberId]
        );
    }

    /**
     * Unlink a member from a donor record
     */
    public function unlinkFromDonor(int $memberId): void
    {
        $this->db->execute(
            "UPDATE members SET donor_id = NULL WHERE id = ?",
            [$memberId]
        );
    }

    /**
     * Get member linked to a donor
     */
    public function getByDonorId(int $donorId): ?array
    {
        return $this->db->queryOne(
            "SELECT id, name, member_number, is_active FROM members WHERE donor_id = ?",
            [$donorId]
        );
    }

    /**
     * Calculate membership period dates based on charity's membership year type
     * Returns array with period_start, period_end, period_label for current/next period
     */
    public function calculateMembershipPeriod(array $charity, ?string $memberJoinedDate = null): array
    {
        $yearType = $charity['membership_year_type'] ?? 'financial';
        $today = new \DateTime();
        
        switch ($yearType) {
            case 'calendar':
                // Calendar year: Jan 1 - Dec 31
                $year = (int) $today->format('Y');
                return [
                    'period_start' => "$year-01-01",
                    'period_end' => "$year-12-31",
                    'period_label' => (string) $year,
                ];
                
            case 'anniversary':
                // Anniversary: based on member's join date
                if (!$memberJoinedDate) {
                    // Default to calendar year if no join date
                    $year = (int) $today->format('Y');
                    return [
                        'period_start' => "$year-01-01",
                        'period_end' => "$year-12-31",
                        'period_label' => (string) $year,
                    ];
                }
                $joinDate = new \DateTime($memberJoinedDate);
                $anniversaryThisYear = new \DateTime($today->format('Y') . '-' . $joinDate->format('m-d'));
                
                if ($today < $anniversaryThisYear) {
                    // Before this year's anniversary - current period started last year
                    $periodStart = (clone $anniversaryThisYear)->modify('-1 year');
                    $periodEnd = (clone $anniversaryThisYear)->modify('-1 day');
                } else {
                    // After this year's anniversary - current period started this year
                    $periodStart = $anniversaryThisYear;
                    $periodEnd = (clone $anniversaryThisYear)->modify('+1 year')->modify('-1 day');
                }
                
                return [
                    'period_start' => $periodStart->format('Y-m-d'),
                    'period_end' => $periodEnd->format('Y-m-d'),
                    'period_label' => $periodStart->format('Y') . '-' . $periodEnd->format('Y'),
                ];
                
            case 'financial':
            default:
                // Financial year: based on charity's year_end_month/year_end_day
                $yearEndMonth = (int) ($charity['year_end_month'] ?? 3);
                $yearEndDay = (int) ($charity['year_end_day'] ?? 31);
                
                // Calculate current financial year end
                $currentYear = (int) $today->format('Y');
                $yearEnd = new \DateTime("$currentYear-$yearEndMonth-$yearEndDay");
                
                if ($today > $yearEnd) {
                    // We're past this year's end, so FY ends next year
                    $yearEnd->modify('+1 year');
                }
                
                $yearStart = (clone $yearEnd)->modify('-1 year')->modify('+1 day');
                
                $startYear = (int) $yearStart->format('Y');
                $endYear = (int) $yearEnd->format('Y');
                
                return [
                    'period_start' => $yearStart->format('Y-m-d'),
                    'period_end' => $yearEnd->format('Y-m-d'),
                    'period_label' => $startYear === $endYear ? (string) $startYear : "$startYear-$endYear",
                ];
        }
    }

    /**
     * Validate member data
     */
    private function validateMember(array $data): void
    {
        $errors = [];

        if (empty($data['name'])) {
            $errors[] = 'Name is required';
        }
        if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email address';
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }
    }
}
