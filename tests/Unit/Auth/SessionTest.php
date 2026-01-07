<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use CharityCashbook\Auth\Session;
use PHPUnit\Framework\TestCase;

class SessionTest extends TestCase
{
    public function testConstructorSetsPropertiesFromArray(): void
    {
        $data = [
            'id' => 42,
            'user_id' => 100,
            'display_name' => 'John Doe',
            'is_super_admin' => true,
            'charity_sessions' => '{"1":{"role":"admin","authenticated_at":1234567890}}',
            'created_at' => '2024-01-01 10:00:00',
            'last_active_at' => '2024-01-01 12:00:00',
        ];

        $session = new Session($data);

        $this->assertSame(42, $session->getId());
        $this->assertSame(100, $session->getUserId());
        $this->assertSame('John Doe', $session->getDisplayName());
        $this->assertTrue($session->isSuperAdmin());
        $this->assertSame('2024-01-01 10:00:00', $session->getCreatedAt());
        $this->assertSame('2024-01-01 12:00:00', $session->getLastActiveAt());
    }

    public function testConstructorHandlesDefaultValues(): void
    {
        $data = [
            'id' => 1,
            'user_id' => 1,
        ];

        $session = new Session($data);

        $this->assertSame('', $session->getDisplayName());
        $this->assertFalse($session->isSuperAdmin());
        $this->assertSame([], $session->getCharitySessions());
        $this->assertSame('', $session->getCreatedAt());
        $this->assertSame('', $session->getLastActiveAt());
    }

    public function testGetCharitySessionsDecodesJson(): void
    {
        $charitySessions = [
            '1' => ['role' => 'admin', 'authenticated_at' => 1234567890],
            '2' => ['role' => 'viewer', 'authenticated_at' => 1234567891],
        ];

        $data = [
            'id' => 1,
            'user_id' => 1,
            'charity_sessions' => json_encode($charitySessions),
        ];

        $session = new Session($data);

        $this->assertEquals($charitySessions, $session->getCharitySessions());
    }

    public function testGetCharitySessionReturnsSessionForCharity(): void
    {
        $charitySessions = [
            '42' => ['role' => 'treasurer', 'authenticated_at' => 1234567890],
        ];

        $data = [
            'id' => 1,
            'user_id' => 1,
            'charity_sessions' => json_encode($charitySessions),
        ];

        $session = new Session($data);

        $charitySession = $session->getCharitySession(42);

        $this->assertSame('treasurer', $charitySession['role']);
        $this->assertSame(1234567890, $charitySession['authenticated_at']);
    }

    public function testGetCharitySessionReturnsNullForUnknownCharity(): void
    {
        $data = [
            'id' => 1,
            'user_id' => 1,
            'charity_sessions' => '{}',
        ];

        $session = new Session($data);

        $this->assertNull($session->getCharitySession(999));
    }

    public function testHasCharitySessionReturnsTrueWhenExists(): void
    {
        $data = [
            'id' => 1,
            'user_id' => 1,
            'charity_sessions' => '{"42":{"role":"admin"}}',
        ];

        $session = new Session($data);

        $this->assertTrue($session->hasCharitySession(42));
    }

    public function testHasCharitySessionReturnsFalseWhenMissing(): void
    {
        $data = [
            'id' => 1,
            'user_id' => 1,
            'charity_sessions' => '{}',
        ];

        $session = new Session($data);

        $this->assertFalse($session->hasCharitySession(999));
    }

    public function testIsSuperAdminReturnsFalseByDefault(): void
    {
        $data = [
            'id' => 1,
            'user_id' => 1,
        ];

        $session = new Session($data);

        $this->assertFalse($session->isSuperAdmin());
    }

    public function testIsSuperAdminCastsToBoolean(): void
    {
        $data = [
            'id' => 1,
            'user_id' => 1,
            'is_super_admin' => 1, // Integer instead of bool
        ];

        $session = new Session($data);

        $this->assertTrue($session->isSuperAdmin());
    }

    public function testIdCastsToInteger(): void
    {
        $data = [
            'id' => '42', // String instead of int
            'user_id' => '100',
        ];

        $session = new Session($data);

        $this->assertSame(42, $session->getId());
        $this->assertSame(100, $session->getUserId());
    }
}
