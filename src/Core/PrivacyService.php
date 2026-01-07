<?php

declare(strict_types=1);

namespace CharityCashbook\Core;

use ZipArchive;

/**
 * Service for GDPR compliance - data export, anonymisation, and deletion.
 * Handles both donor/member data (charity level) and user data (service level).
 */
class PrivacyService
{
    private DatabaseInterface $db;
    private Logger $logger;
    private string $tempPath;

    // Retention period in years for different data types
    private const RETENTION_YEARS = [
        'financial' => 7,      // HMRC requirement for Gift Aid records
        'audit' => 7,          // Audit trail retention
        'account' => 7,        // After account closure
    ];

    public function __construct(DatabaseInterface $db, Logger $logger, string $tempPath)
    {
        $this->db = $db;
        $this->logger = $logger;
        $this->tempPath = $tempPath;
    }

    // ========================================================================
    // DONOR DATA METHODS
    // ========================================================================

    /**
     * Find all personal data for a donor across all tables
     */
    public function findDonorData(int $donorId, int $charityId): array
    {
        $data = [
            'donor' => null,
            'declarations' => [],
            'donations' => [],
            'transactions_mentioned' => [],
            'access_log' => [],
            'audit_log' => [],
            'linked_member' => null,
        ];

        // Core donor record
        $data['donor'] = $this->db->queryOne(
            "SELECT * FROM donors WHERE id = ? AND charity_id = ?",
            [$donorId, $charityId]
        );

        if (!$data['donor']) {
            return $data;
        }

        // Gift Aid declarations
        $data['declarations'] = $this->db->query(
            "SELECT * FROM declarations WHERE donor_id = ? AND charity_id = ?",
            [$donorId, $charityId]
        );

        // Donations linked to transactions
        $data['donations'] = $this->db->query(
            "SELECT d.*, t.transaction_date, t.description, t.amount 
             FROM donations d
             JOIN transactions t ON t.id = d.transaction_id
             WHERE d.donor_id = ? AND t.charity_id = ?",
            [$donorId, $charityId]
        );

        // Transactions where donor name might appear in description
        $donorName = $data['donor']['name'] ?? '';
        if ($donorName) {
            $data['transactions_mentioned'] = $this->db->query(
                "SELECT id, transaction_date, description, amount, transaction_type 
                 FROM transactions 
                 WHERE charity_id = ? AND description LIKE ? AND is_deleted = FALSE",
                [$charityId, '%' . $donorName . '%']
            );
        }

        // Donor access log
        $data['access_log'] = $this->db->query(
            "SELECT dal.*, COALESCE(cu.display_name, u.display_name) as accessed_by_name
             FROM donor_access_log dal
             LEFT JOIN users u ON u.id = dal.user_id
             LEFT JOIN charity_users cu ON cu.user_id = dal.user_id AND cu.charity_id = dal.charity_id
             WHERE dal.donor_id = ? AND dal.charity_id = ?
             ORDER BY dal.created_at DESC",
            [$donorId, $charityId]
        );

        // Audit log entries mentioning this donor
        $data['audit_log'] = $this->db->query(
            "SELECT * FROM audit_log 
             WHERE charity_id = ? AND (
                 (entity_type = 'donor' AND entity_id = ?)
                 OR details LIKE ?
             )
             ORDER BY created_at DESC",
            [$charityId, $donorId, '%"donor_id":' . $donorId . '%']
        );

        // Check for linked member record
        $data['linked_member'] = $this->db->queryOne(
            "SELECT id, name FROM members WHERE donor_id = ? AND charity_id = ?",
            [$donorId, $charityId]
        );

        return $data;
    }

    /**
     * Find all personal data for a member across all tables
     */
    public function findMemberData(int $memberId, int $charityId): array
    {
        $data = [
            'member' => null,
            'trustee_history' => [],
            'membership_dues' => [],
            'audit_log' => [],
            'linked_donor' => null,
        ];

        // Core member record
        $data['member'] = $this->db->queryOne(
            "SELECT * FROM members WHERE id = ? AND charity_id = ?",
            [$memberId, $charityId]
        );

        if (!$data['member']) {
            return $data;
        }

        // Trustee role history
        $data['trustee_history'] = $this->db->query(
            "SELECT * FROM member_trustee_roles WHERE member_id = ? ORDER BY start_date DESC",
            [$memberId]
        );

        // Membership dues
        $data['membership_dues'] = $this->db->query(
            "SELECT * FROM membership_dues WHERE member_id = ? ORDER BY period_start DESC",
            [$memberId]
        );

        // Audit log entries mentioning this member
        $data['audit_log'] = $this->db->query(
            "SELECT * FROM audit_log 
             WHERE charity_id = ? AND (
                 (entity_type = 'member' AND entity_id = ?)
                 OR details LIKE ?
             )
             ORDER BY created_at DESC",
            [$charityId, $memberId, '%"member_id":' . $memberId . '%']
        );

        // Check for linked donor record
        if ($data['member']['donor_id']) {
            $data['linked_donor'] = $this->db->queryOne(
                "SELECT id, name FROM donors WHERE id = ?",
                [$data['member']['donor_id']]
            );
        }

        return $data;
    }

    /**
     * Export donor data as a ZIP file
     * Returns the path to the generated ZIP file
     */
    public function exportDonorData(int $donorId, int $charityId): string
    {
        $data = $this->findDonorData($donorId, $charityId);
        
        if (!$data['donor']) {
            throw new \RuntimeException('Donor not found');
        }

        // If there's a linked member, include that data too
        if ($data['linked_member']) {
            $data['member_data'] = $this->findMemberData($data['linked_member']['id'], $charityId);
        }

        return $this->createExportZip($data, 'donor', $donorId, $charityId);
    }

    /**
     * Export member data as a ZIP file
     */
    public function exportMemberData(int $memberId, int $charityId): string
    {
        $data = $this->findMemberData($memberId, $charityId);
        
        if (!$data['member']) {
            throw new \RuntimeException('Member not found');
        }

        // If there's a linked donor, include that data too
        if ($data['linked_donor']) {
            $data['donor_data'] = $this->findDonorData($data['linked_donor']['id'], $charityId);
        }

        return $this->createExportZip($data, 'member', $memberId, $charityId);
    }

    /**
     * Check if donor data can be deleted (retention period passed)
     */
    public function canDeleteDonorData(int $donorId, int $charityId): array
    {
        $result = [
            'can_delete' => true,
            'reasons' => [],
            'earliest_deletion_date' => null,
        ];

        // Check last Gift Aid claim
        $lastClaim = $this->db->queryOne(
            "SELECT MAX(gc.claim_date) as last_claim
             FROM giftaid_claim_items gci
             JOIN giftaid_claims gc ON gc.id = gci.claim_id
             JOIN donations d ON d.transaction_id = gci.transaction_id
             WHERE d.donor_id = ?",
            [$donorId]
        );

        if ($lastClaim && $lastClaim['last_claim']) {
            $retentionEnd = strtotime($lastClaim['last_claim'] . ' + ' . self::RETENTION_YEARS['financial'] . ' years');
            if ($retentionEnd > time()) {
                $result['can_delete'] = false;
                $result['reasons'][] = 'Gift Aid records must be retained until ' . date('Y-m-d', $retentionEnd);
                $result['earliest_deletion_date'] = date('Y-m-d', $retentionEnd);
            }
        }

        // Check last donation (even if not claimed)
        $lastDonation = $this->db->queryOne(
            "SELECT MAX(t.transaction_date) as last_donation
             FROM donations d
             JOIN transactions t ON t.id = d.transaction_id
             WHERE d.donor_id = ?",
            [$donorId]
        );

        if ($lastDonation && $lastDonation['last_donation']) {
            $retentionEnd = strtotime($lastDonation['last_donation'] . ' + ' . self::RETENTION_YEARS['financial'] . ' years');
            if ($retentionEnd > time()) {
                $result['can_delete'] = false;
                $deleteDate = date('Y-m-d', $retentionEnd);
                if (!in_array('Financial records must be retained until ' . $deleteDate, $result['reasons'])) {
                    $result['reasons'][] = 'Financial records must be retained until ' . $deleteDate;
                }
                if (!$result['earliest_deletion_date'] || $deleteDate > $result['earliest_deletion_date']) {
                    $result['earliest_deletion_date'] = $deleteDate;
                }
            }
        }

        return $result;
    }

    /**
     * Check if member data can be deleted
     */
    public function canDeleteMemberData(int $memberId, int $charityId): array
    {
        $result = [
            'can_delete' => true,
            'reasons' => [],
            'earliest_deletion_date' => null,
        ];

        // Check last membership dues payment
        $lastPayment = $this->db->queryOne(
            "SELECT MAX(COALESCE(paid_date, period_end)) as last_date
             FROM membership_dues
             WHERE member_id = ? AND status IN ('paid', 'waived')",
            [$memberId]
        );

        if ($lastPayment && $lastPayment['last_date']) {
            $retentionEnd = strtotime($lastPayment['last_date'] . ' + ' . self::RETENTION_YEARS['financial'] . ' years');
            if ($retentionEnd > time()) {
                $result['can_delete'] = false;
                $result['reasons'][] = 'Membership financial records must be retained until ' . date('Y-m-d', $retentionEnd);
                $result['earliest_deletion_date'] = date('Y-m-d', $retentionEnd);
            }
        }

        // Check if linked to donor with retention requirements
        $member = $this->db->queryOne("SELECT donor_id FROM members WHERE id = ?", [$memberId]);
        if ($member && $member['donor_id']) {
            $donorCheck = $this->canDeleteDonorData($member['donor_id'], $charityId);
            if (!$donorCheck['can_delete']) {
                $result['can_delete'] = false;
                $result['reasons'][] = 'Linked donor record has retention requirements';
                if ($donorCheck['earliest_deletion_date'] > $result['earliest_deletion_date']) {
                    $result['earliest_deletion_date'] = $donorCheck['earliest_deletion_date'];
                }
            }
        }

        return $result;
    }

    /**
     * Anonymise donor data (when deletion is requested but retention applies)
     */
    public function anonymiseDonorData(int $donorId, int $charityId, int $userId): array
    {
        $changes = [];
        $anonymousName = 'Anonymised Donor #' . $donorId;

        // Update donor record
        $this->db->execute(
            "UPDATE donors SET 
                name = ?,
                email = NULL,
                address_line1 = NULL,
                address_line2 = NULL,
                address_city = NULL,
                address_postcode = NULL,
                notes = NULL
             WHERE id = ? AND charity_id = ?",
            [$anonymousName, $donorId, $charityId]
        );
        $changes[] = 'Donor record anonymised';

        // Anonymise in transactions where name appears
        $donor = $this->db->queryOne("SELECT name FROM donors WHERE id = ?", [$donorId]);
        // Note: We don't modify transaction descriptions as they're financial records

        // Log the anonymisation
        $this->db->insert('audit_log', [
            'charity_id' => $charityId,
            'user_id' => $userId,
            'action' => 'donor_anonymised',
            'entity_type' => 'donor',
            'entity_id' => $donorId,
            'details' => json_encode(['reason' => 'GDPR erasure request']),
        ]);

        $this->logger->info('Donor data anonymised', [
            'donor_id' => $donorId,
            'charity_id' => $charityId,
            'user_id' => $userId,
        ]);

        return $changes;
    }

    /**
     * Anonymise member data
     */
    public function anonymiseMemberData(int $memberId, int $charityId, int $userId): array
    {
        $changes = [];
        $anonymousName = 'Anonymised Member #' . $memberId;

        // Update member record
        $this->db->execute(
            "UPDATE members SET 
                name = ?,
                email = NULL,
                phone = NULL,
                address_line1 = NULL,
                address_line2 = NULL,
                address_city = NULL,
                address_county = NULL,
                address_postcode = NULL,
                notes = NULL
             WHERE id = ? AND charity_id = ?",
            [$anonymousName, $memberId, $charityId]
        );
        $changes[] = 'Member record anonymised';

        // Anonymise trustee role notes
        $this->db->execute(
            "UPDATE member_trustee_roles SET notes = NULL WHERE member_id = ?",
            [$memberId]
        );
        $changes[] = 'Trustee history notes cleared';

        // Log the anonymisation
        $this->db->insert('audit_log', [
            'charity_id' => $charityId,
            'user_id' => $userId,
            'action' => 'member_anonymised',
            'entity_type' => 'member',
            'entity_id' => $memberId,
            'details' => json_encode(['reason' => 'GDPR erasure request']),
        ]);

        $this->logger->info('Member data anonymised', [
            'member_id' => $memberId,
            'charity_id' => $charityId,
            'user_id' => $userId,
        ]);

        return $changes;
    }

    // ========================================================================
    // USER ACCOUNT METHODS (Service Admin)
    // ========================================================================

    /**
     * Find all data for a user account
     */
    public function findUserData(int $userId): array
    {
        $data = [
            'user' => null,
            'identities' => [],
            'charity_access' => [],
            'sessions' => [],
            'login_history' => [],
            'audit_log_created' => [],
            'donor_access_log' => [],
        ];

        // Core user record
        $data['user'] = $this->db->queryOne(
            "SELECT id, display_name, primary_email, is_super_admin, is_active, created_at 
             FROM users WHERE id = ?",
            [$userId]
        );

        if (!$data['user']) {
            return $data;
        }

        // OAuth identities
        $data['identities'] = $this->db->query(
            "SELECT ui.*, op.name as provider_name, op.provider_type
             FROM user_identities ui
             JOIN oauth_providers op ON op.id = ui.oauth_provider_id
             WHERE ui.user_id = ?",
            [$userId]
        );

        // Charity access
        $data['charity_access'] = $this->db->query(
            "SELECT cu.*, c.name as charity_name
             FROM charity_users cu
             JOIN charities c ON c.id = cu.charity_id
             WHERE cu.user_id = ?",
            [$userId]
        );

        // Active sessions
        $data['sessions'] = $this->db->query(
            "SELECT id, created_at, expires_at, last_active_at, ip_address, user_agent
             FROM sessions WHERE user_id = ?",
            [$userId]
        );

        // Login history
        $data['login_history'] = $this->db->query(
            "SELECT * FROM login_history WHERE user_id = ? ORDER BY created_at DESC LIMIT 100",
            [$userId]
        );

        // Audit log entries created by this user
        $data['audit_log_created'] = $this->db->query(
            "SELECT id, charity_id, action, entity_type, entity_id, ip_address, created_at
             FROM audit_log WHERE user_id = ? ORDER BY created_at DESC LIMIT 100",
            [$userId]
        );

        // Donor access log entries
        $data['donor_access_log'] = $this->db->query(
            "SELECT dal.*, c.name as charity_name
             FROM donor_access_log dal
             JOIN charities c ON c.id = dal.charity_id
             WHERE dal.user_id = ?
             ORDER BY dal.created_at DESC LIMIT 100",
            [$userId]
        );

        return $data;
    }

    /**
     * Export user account data as ZIP
     */
    public function exportUserData(int $userId): string
    {
        $data = $this->findUserData($userId);
        
        if (!$data['user']) {
            throw new \RuntimeException('User not found');
        }

        return $this->createExportZip($data, 'user', $userId, null);
    }

    /**
     * Anonymise user account
     */
    public function anonymiseUserData(int $userId, int $adminUserId): array
    {
        $changes = [];
        $anonymousName = 'Deleted User #' . $userId;
        $anonymousEmail = 'deleted-' . $userId . '@anonymised.local';

        // Update user record
        $this->db->execute(
            "UPDATE users SET 
                display_name = ?,
                primary_email = ?,
                is_active = FALSE
             WHERE id = ?",
            [$anonymousName, $anonymousEmail, $userId]
        );
        $changes[] = 'User account anonymised and deactivated';

        // Delete OAuth identities
        $this->db->execute("DELETE FROM user_identities WHERE user_id = ?", [$userId]);
        $changes[] = 'OAuth identities removed';

        // Delete sessions
        $this->db->execute("DELETE FROM sessions WHERE user_id = ?", [$userId]);
        $changes[] = 'Sessions deleted';

        // Remove from charity_users
        $this->db->execute("DELETE FROM charity_users WHERE user_id = ?", [$userId]);
        $changes[] = 'Charity access removed';

        // Anonymise in audit logs (keep the logs but remove identifying info)
        $this->db->execute(
            "UPDATE audit_log SET user_id = NULL WHERE user_id = ?",
            [$userId]
        );
        $changes[] = 'Audit log entries anonymised';

        // Anonymise donor access log
        $this->db->execute(
            "UPDATE donor_access_log SET user_id = NULL WHERE user_id = ?",
            [$userId]
        );
        $changes[] = 'Donor access log entries anonymised';

        // Log the anonymisation
        $this->db->insert('audit_log', [
            'charity_id' => null,
            'user_id' => $adminUserId,
            'action' => 'user_anonymised',
            'entity_type' => 'user',
            'entity_id' => $userId,
            'details' => json_encode(['reason' => 'GDPR erasure request', 'changes' => $changes]),
        ]);

        $this->logger->info('User account anonymised', [
            'user_id' => $userId,
            'admin_user_id' => $adminUserId,
        ]);

        return $changes;
    }

    /**
     * Search for personal data across all charities (for service admin)
     */
    public function searchAllCharities(string $searchTerm): array
    {
        $results = [
            'donors' => [],
            'members' => [],
            'users' => [],
        ];

        $searchPattern = '%' . $searchTerm . '%';

        // Search donors
        $results['donors'] = $this->db->query(
            "SELECT d.*, c.name as charity_name
             FROM donors d
             JOIN charities c ON c.id = d.charity_id
             WHERE d.name LIKE ? OR d.email LIKE ?
             ORDER BY c.name, d.name
             LIMIT 100",
            [$searchPattern, $searchPattern]
        );

        // Search members
        $results['members'] = $this->db->query(
            "SELECT m.*, c.name as charity_name
             FROM members m
             JOIN charities c ON c.id = m.charity_id
             WHERE m.name LIKE ? OR m.email LIKE ?
             ORDER BY c.name, m.name
             LIMIT 100",
            [$searchPattern, $searchPattern]
        );

        // Search users
        $results['users'] = $this->db->query(
            "SELECT id, display_name, primary_email, is_active, created_at
             FROM users
             WHERE display_name LIKE ? OR primary_email LIKE ?
             ORDER BY display_name
             LIMIT 100",
            [$searchPattern, $searchPattern]
        );

        return $results;
    }

    // ========================================================================
    // HELPER METHODS
    // ========================================================================

    /**
     * Create a ZIP file with exported data
     */
    private function createExportZip(array $data, string $type, int $entityId, ?int $charityId): string
    {
        $filename = sprintf(
            '%s/%s_data_export_%d_%s.zip',
            $this->tempPath,
            $type,
            $entityId,
            date('Y-m-d_His')
        );

        $zip = new ZipArchive();
        if ($zip->open($filename, ZipArchive::CREATE) !== true) {
            throw new \RuntimeException('Cannot create ZIP file');
        }

        // Add JSON data files
        foreach ($data as $key => $value) {
            if ($value !== null && !empty($value)) {
                $zip->addFromString(
                    $key . '.json',
                    json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                );
            }
        }

        // Add a readable summary
        $summary = $this->generateExportSummary($data, $type);
        $zip->addFromString('SUMMARY.txt', $summary);

        // Add data retention notice
        $notice = $this->generateRetentionNotice($type);
        $zip->addFromString('DATA_RETENTION_NOTICE.txt', $notice);

        $zip->close();

        $this->logger->info('Personal data export created', [
            'type' => $type,
            'entity_id' => $entityId,
            'charity_id' => $charityId,
            'filename' => $filename,
        ]);

        return $filename;
    }

    /**
     * Generate a human-readable summary of exported data
     */
    private function generateExportSummary(array $data, string $type): string
    {
        $summary = "PERSONAL DATA EXPORT\n";
        $summary .= "====================\n\n";
        $summary .= "Export Date: " . date('Y-m-d H:i:s') . "\n";
        $summary .= "Export Type: " . ucfirst($type) . " Data\n\n";

        $summary .= "DATA INCLUDED IN THIS EXPORT\n";
        $summary .= "----------------------------\n\n";

        foreach ($data as $key => $value) {
            if ($value === null) {
                continue;
            }

            $count = is_array($value) ? (isset($value['id']) ? 1 : count($value)) : 1;
            $summary .= sprintf("- %s: %d record(s)\n", ucfirst(str_replace('_', ' ', $key)), $count);
        }

        $summary .= "\n\nFILE CONTENTS\n";
        $summary .= "-------------\n\n";
        $summary .= "Each .json file contains the raw data from our database.\n";
        $summary .= "This file (SUMMARY.txt) provides a human-readable overview.\n";
        $summary .= "DATA_RETENTION_NOTICE.txt explains our data retention policy.\n";

        return $summary;
    }

    /**
     * Generate data retention notice
     */
    private function generateRetentionNotice(string $type): string
    {
        $notice = "DATA RETENTION NOTICE\n";
        $notice .= "=====================\n\n";

        $notice .= "Under UK GDPR, you have the right to request deletion of your personal data.\n";
        $notice .= "However, certain data must be retained for legal compliance:\n\n";

        $notice .= "FINANCIAL RECORDS (7 years)\n";
        $notice .= "---------------------------\n";
        $notice .= "- Donation records linked to Gift Aid claims (HMRC requirement)\n";
        $notice .= "- Membership payment records\n";
        $notice .= "- Transaction records\n\n";

        $notice .= "AUDIT RECORDS (7 years)\n";
        $notice .= "-----------------------\n";
        $notice .= "- Access logs (who viewed your data)\n";
        $notice .= "- Change history (modifications to your records)\n\n";

        $notice .= "If you request deletion and retention periods have not passed,\n";
        $notice .= "we will anonymise your data instead (removing identifying information\n";
        $notice .= "while preserving the financial record structure).\n\n";

        $notice .= "Once retention periods expire, you may request full deletion.\n\n";

        $notice .= "For questions about your data, contact the charity's data controller\n";
        $notice .= "or email: privacy@charitycashbook.uk\n";

        return $notice;
    }
}
