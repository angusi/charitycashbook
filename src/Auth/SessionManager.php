<?php

declare(strict_types=1);

namespace CharityCashbook\Auth;

use CharityCashbook\Core\DatabaseInterface;
use CharityCashbook\Core\Logger;

/**
 * Manages user sessions with database storage
 */
class SessionManager
{
    private const TOKEN_LENGTH = 64;
    private const COOKIE_NAME = 'charity_cashbook_session';

    private DatabaseInterface $db;
    private Logger $logger;
    private int $lifetimeDays;

    public function __construct(DatabaseInterface $db, Logger $logger, int $lifetimeDays = 30)
    {
        $this->db = $db;
        $this->logger = $logger;
        $this->lifetimeDays = $lifetimeDays;
    }

    /**
     * Create a new session for a user
     *
     * @return array{token: string, id: int} The session token and database ID
     */
    public function create(int $userId): array
    {
        // Generate cryptographically secure token
        $token = base64_encode(random_bytes(self::TOKEN_LENGTH));
        $tokenHash = hash('sha512', $token);

        // Get user info for session
        $user = $this->db->queryOne(
            "SELECT display_name, is_super_admin FROM users WHERE id = ?",
            [$userId]
        );

        // Store session in database
        $sessionId = $this->db->insert('sessions', [
            'token_hash' => $tokenHash,
            'user_id' => $userId,
            'charity_sessions' => '{}',
            'expires_at' => date('Y-m-d H:i:s', time() + ($this->lifetimeDays * 86400)),
            'ip_address' => $this->getClientIp(),
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
        ]);

        // Set cookie
        $this->setCookie($token);

        $this->logger->auth('Session created', ['user_id' => $userId]);

        return ['token' => $token, 'id' => $sessionId];
    }

    /**
     * Validate the current session
     */
    public function validate(): ?Session
    {
        $token = $_COOKIE[self::COOKIE_NAME] ?? null;
        if (!$token) {
            return null;
        }

        $tokenHash = hash('sha512', $token);

        $row = $this->db->queryOne(
            "SELECT s.*, u.display_name, u.is_super_admin 
             FROM sessions s
             JOIN users u ON u.id = s.user_id
             WHERE s.token_hash = ? AND s.expires_at > NOW() AND u.is_active = TRUE",
            [$tokenHash]
        );

        if (!$row) {
            $this->clearCookie();
            return null;
        }

        // Update last active timestamp
        $this->db->execute(
            "UPDATE sessions SET last_active_at = NOW() WHERE id = ?",
            [$row['id']]
        );

        return new Session($row);
    }

    /**
     * Check if a charity session is still valid (not expired and has tracked identity)
     */
    public function checkCharitySession(Session $session, int $charityId, int $timeoutMinutes): bool
    {
        $charitySession = $session->getCharitySession($charityId);

        if (!$charitySession) {
            return false;
        }

        // Require identity to be tracked - forces re-auth for legacy sessions
        if (empty($charitySession['authenticated_via_identity_id'])) {
            return false;
        }

        $authenticatedAt = $charitySession['authenticated_at'];
        $expiresAt = $authenticatedAt + ($timeoutMinutes * 60);

        return time() < $expiresAt;
    }

    /**
     * Refresh the charity session after re-authentication
     */
    public function refreshCharitySession(
        int $sessionId,
        int $charityId,
        string $role,
        int $identityId,
        int $timeoutMinutes
    ): void {
        // Get current charity sessions
        $row = $this->db->queryOne(
            "SELECT charity_sessions FROM sessions WHERE id = ?",
            [$sessionId]
        );

        if (!$row) {
            return;
        }

        $charitySessions = json_decode($row['charity_sessions'], true);
        $charitySessions[(string) $charityId] = [
            'role' => $role,
            'authenticated_at' => time(),
            'authenticated_via_identity_id' => $identityId,
            'timeout_minutes' => $timeoutMinutes,
        ];

        $this->db->execute(
            "UPDATE sessions SET charity_sessions = ? WHERE id = ?",
            [json_encode($charitySessions), $sessionId]
        );
    }

    /**
     * Invalidate a specific charity session for a user
     */
    public function invalidateCharitySession(int $userId, int $charityId): void
    {
        $sessions = $this->db->query(
            "SELECT id, charity_sessions FROM sessions WHERE user_id = ?",
            [$userId]
        );

        foreach ($sessions as $session) {
            $charitySessions = json_decode($session['charity_sessions'], true);
            
            if (isset($charitySessions[(string) $charityId])) {
                unset($charitySessions[(string) $charityId]);
                
                $this->db->execute(
                    "UPDATE sessions SET charity_sessions = ? WHERE id = ?",
                    [json_encode($charitySessions), $session['id']]
                );
            }
        }

        $this->logger->auth('Charity session invalidated', [
            'user_id' => $userId,
            'charity_id' => $charityId,
        ]);
    }

    /**
     * Invalidate all charity sessions for a charity
     */
    public function invalidateAllCharitySessions(int $charityId): void
    {
        // Get all sessions that have this charity
        $sessions = $this->db->query(
            "SELECT id, charity_sessions FROM sessions 
             WHERE JSON_CONTAINS_PATH(charity_sessions, 'one', ?)",
            ['$."' . $charityId . '"']
        );

        foreach ($sessions as $session) {
            $charitySessions = json_decode($session['charity_sessions'], true);
            unset($charitySessions[(string) $charityId]);
            
            $this->db->execute(
                "UPDATE sessions SET charity_sessions = ? WHERE id = ?",
                [json_encode($charitySessions), $session['id']]
            );
        }

        $this->logger->auth('All charity sessions invalidated', ['charity_id' => $charityId]);
    }

    /**
     * Destroy the current session
     */
    public function destroy(): void
    {
        $token = $_COOKIE[self::COOKIE_NAME] ?? null;
        if ($token) {
            $tokenHash = hash('sha512', $token);
            $this->db->execute("DELETE FROM sessions WHERE token_hash = ?", [$tokenHash]);
            $this->logger->auth('Session destroyed');
        }

        $this->clearCookie();
    }

    /**
     * Destroy all sessions for a user
     */
    public function destroyAllForUser(int $userId): void
    {
        $this->db->execute("DELETE FROM sessions WHERE user_id = ?", [$userId]);
        $this->logger->auth('All sessions destroyed for user', ['user_id' => $userId]);
    }

    /**
     * Get all active sessions for a user
     */
    public function getSessionsForUser(int $userId): array
    {
        return $this->db->query(
            "SELECT id, created_at, last_active_at, ip_address, user_agent 
             FROM sessions 
             WHERE user_id = ? AND expires_at > NOW()
             ORDER BY last_active_at DESC",
            [$userId]
        );
    }

    /**
     * Clean up expired sessions
     */
    public function cleanExpiredSessions(): int
    {
        return $this->db->execute("DELETE FROM sessions WHERE expires_at < NOW()");
    }

    private function setCookie(string $token): void
    {
        setcookie(self::COOKIE_NAME, $token, [
            'expires' => time() + ($this->lifetimeDays * 86400),
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function clearCookie(): void
    {
        setcookie(self::COOKIE_NAME, '', [
            'expires' => 1,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function getClientIp(): ?string
    {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
        if ($ip && str_contains($ip, ',')) {
            $ip = trim(explode(',', $ip)[0]);
        }
        return $ip ? substr($ip, 0, 45) : null;
    }
}
