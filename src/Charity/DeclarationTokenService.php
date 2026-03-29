<?php

declare(strict_types=1);

namespace CharityCashbook\Charity;

use CharityCashbook\Core\DatabaseInterface;
use CharityCashbook\Core\Logger;

/**
 * Service for managing declaration tokens (public GiftAid declaration links)
 */
class DeclarationTokenService
{
    private const TOKEN_EXPIRY_DAYS = 30;

    private DatabaseInterface $db;
    private Logger $logger;

    public function __construct(DatabaseInterface $db, Logger $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * Generate a declaration token for a donor
     */
    public function generate(int $donorId, int $charityId, int $createdByUserId): string
    {
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+' . self::TOKEN_EXPIRY_DAYS . ' days'));

        $this->db->insert('declaration_tokens', [
            'token' => $token,
            'donor_id' => $donorId,
            'charity_id' => $charityId,
            'expires_at' => $expiresAt,
            'created_by_user_id' => $createdByUserId,
        ]);

        $this->logger->info('Declaration token generated', [
            'donor_id' => $donorId,
            'charity_id' => $charityId,
            'expires_at' => $expiresAt,
        ]);

        return $token;
    }

    /**
     * Validate a token and return its data (donor + charity info)
     *
     * Returns null if the token is invalid, expired, or already used.
     */
    public function validate(string $token): ?array
    {
        $row = $this->db->queryOne(
            "SELECT dt.*, 
                    d.name as donor_name, d.email as donor_email,
                    d.address_line1, d.address_line2, d.address_city, d.address_postcode,
                    c.name as charity_name
             FROM declaration_tokens dt
             JOIN donors d ON d.id = dt.donor_id
             JOIN charities c ON c.id = dt.charity_id
             WHERE dt.token = ?",
            [$token]
        );

        if (!$row) {
            return null;
        }

        if ($row['used_at'] !== null) {
            return array_merge($row, ['status' => 'used']);
        }

        if (strtotime($row['expires_at']) < time()) {
            return array_merge($row, ['status' => 'expired']);
        }

        return array_merge($row, ['status' => 'valid']);
    }

    /**
     * Consume a token after successful declaration submission
     */
    public function consume(string $token, int $declarationId): void
    {
        $this->db->execute(
            "UPDATE declaration_tokens SET used_at = NOW(), declaration_id = ? WHERE token = ?",
            [$declarationId, $token]
        );
    }

    /**
     * Get an active (unused, unexpired) token for a donor
     */
    public function getActiveToken(int $donorId, int $charityId): ?array
    {
        return $this->db->queryOne(
            "SELECT * FROM declaration_tokens 
             WHERE donor_id = ? AND charity_id = ? 
             AND used_at IS NULL AND expires_at > NOW()
             ORDER BY created_at DESC LIMIT 1",
            [$donorId, $charityId]
        );
    }

    /**
     * Bulk generate tokens for donors missing active declarations.
     *
     * Skips donors who already have an active (non-withdrawn) declaration
     * or an unexpired, unused token.
     *
     * @return array List of ['donor_id', 'donor_name', 'donor_email', 'token'] entries
     */
    public function bulkGenerate(int $charityId, int $createdByUserId): array
    {
        $donors = $this->db->query(
            "SELECT d.id, d.name, d.email
             FROM donors d
             WHERE d.charity_id = ? AND d.is_active = TRUE
             AND NOT EXISTS (
                 SELECT 1 FROM declarations decl 
                 WHERE decl.donor_id = d.id AND decl.withdrawn_date IS NULL
             )
             AND NOT EXISTS (
                 SELECT 1 FROM declaration_tokens dt 
                 WHERE dt.donor_id = d.id AND dt.charity_id = ?
                 AND dt.used_at IS NULL AND dt.expires_at > NOW()
             )
             ORDER BY d.name",
            [$charityId, $charityId]
        );

        $results = [];
        foreach ($donors as $donor) {
            $token = $this->generate($donor['id'], $charityId, $createdByUserId);
            $results[] = [
                'donor_id' => $donor['id'],
                'donor_name' => $donor['name'],
                'donor_email' => $donor['email'],
                'token' => $token,
            ];
        }

        $this->logger->info('Bulk declaration tokens generated', [
            'charity_id' => $charityId,
            'count' => count($results),
        ]);

        return $results;
    }
}
