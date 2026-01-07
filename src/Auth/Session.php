<?php

declare(strict_types=1);

namespace CharityCashbook\Auth;

/**
 * Represents an authenticated user session
 */
class Session
{
    private int $id;
    private int $userId;
    private string $displayName;
    private bool $isSuperAdmin;
    private array $charitySessions;
    private string $createdAt;
    private string $lastActiveAt;

    public function __construct(array $data)
    {
        $this->id = (int) $data['id'];
        $this->userId = (int) $data['user_id'];
        $this->displayName = $data['display_name'] ?? '';
        $this->isSuperAdmin = (bool) ($data['is_super_admin'] ?? false);
        $this->charitySessions = json_decode($data['charity_sessions'] ?? '{}', true);
        $this->createdAt = $data['created_at'] ?? '';
        $this->lastActiveAt = $data['last_active_at'] ?? '';
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function isSuperAdmin(): bool
    {
        return $this->isSuperAdmin;
    }

    public function getCharitySessions(): array
    {
        return $this->charitySessions;
    }

    public function getCharitySession(int $charityId): ?array
    {
        return $this->charitySessions[(string) $charityId] ?? null;
    }

    public function hasCharitySession(int $charityId): bool
    {
        return isset($this->charitySessions[(string) $charityId]);
    }

    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }

    public function getLastActiveAt(): string
    {
        return $this->lastActiveAt;
    }
}
