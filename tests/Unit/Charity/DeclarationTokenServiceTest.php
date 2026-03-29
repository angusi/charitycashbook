<?php

declare(strict_types=1);

namespace CharityCashbook\Tests\Unit\Charity;

use PHPUnit\Framework\TestCase;
use CharityCashbook\Charity\DeclarationTokenService;
use CharityCashbook\Core\DatabaseInterface;
use CharityCashbook\Core\Logger;

class DeclarationTokenServiceTest extends TestCase
{
    private DeclarationTokenService $service;
    private DatabaseInterface $db;
    private Logger $logger;

    protected function setUp(): void
    {
        $this->db = $this->createMock(DatabaseInterface::class);
        $this->logger = $this->createMock(Logger::class);

        $this->service = new DeclarationTokenService($this->db, $this->logger);
    }

    public function testGenerateCreatesTokenAndReturnsIt(): void
    {
        $this->db->expects($this->once())
            ->method('insert')
            ->with(
                'declaration_tokens',
                $this->callback(function (array $data) {
                    return strlen($data['token']) === 64
                        && $data['donor_id'] === 5
                        && $data['charity_id'] === 1
                        && $data['created_by_user_id'] === 10
                        && !empty($data['expires_at']);
                })
            )
            ->willReturn(1);

        $token = $this->service->generate(5, 1, 10);

        $this->assertIsString($token);
        $this->assertEquals(64, strlen($token));
        $this->assertTrue(ctype_xdigit($token));
    }

    public function testGenerateProducesUniqueTokens(): void
    {
        $this->db->method('insert')->willReturn(1);

        $token1 = $this->service->generate(1, 1, 1);
        $token2 = $this->service->generate(1, 1, 1);

        $this->assertNotEquals($token1, $token2);
    }

    public function testValidateReturnsNullForUnknownToken(): void
    {
        $this->db->method('queryOne')->willReturn(null);

        $result = $this->service->validate('nonexistent');

        $this->assertNull($result);
    }

    public function testValidateReturnsUsedStatusForConsumedToken(): void
    {
        $this->db->method('queryOne')->willReturn([
            'token' => 'abc123',
            'donor_id' => 1,
            'charity_id' => 1,
            'used_at' => '2026-03-01 12:00:00',
            'expires_at' => date('Y-m-d H:i:s', strtotime('+7 days')),
            'donor_name' => 'Test',
            'donor_email' => null,
            'address_line1' => null,
            'address_line2' => null,
            'address_city' => null,
            'address_postcode' => null,
            'charity_name' => 'Test Charity',
        ]);

        $result = $this->service->validate('abc123');

        $this->assertEquals('used', $result['status']);
    }

    public function testValidateReturnsExpiredStatusForOldToken(): void
    {
        $this->db->method('queryOne')->willReturn([
            'token' => 'abc123',
            'donor_id' => 1,
            'charity_id' => 1,
            'used_at' => null,
            'expires_at' => date('Y-m-d H:i:s', strtotime('-1 day')),
            'donor_name' => 'Test',
            'donor_email' => null,
            'address_line1' => null,
            'address_line2' => null,
            'address_city' => null,
            'address_postcode' => null,
            'charity_name' => 'Test Charity',
        ]);

        $result = $this->service->validate('abc123');

        $this->assertEquals('expired', $result['status']);
    }

    public function testValidateReturnsValidStatusForActiveToken(): void
    {
        $this->db->method('queryOne')->willReturn([
            'token' => 'abc123',
            'donor_id' => 5,
            'charity_id' => 1,
            'used_at' => null,
            'expires_at' => date('Y-m-d H:i:s', strtotime('+7 days')),
            'donor_name' => 'Jane Doe',
            'donor_email' => 'jane@example.com',
            'address_line1' => '123 High Street',
            'address_line2' => null,
            'address_city' => 'Edinburgh',
            'address_postcode' => 'EH1 1AB',
            'charity_name' => 'Test Charity',
        ]);

        $result = $this->service->validate('abc123');

        $this->assertEquals('valid', $result['status']);
        $this->assertEquals('Jane Doe', $result['donor_name']);
        $this->assertEquals('Test Charity', $result['charity_name']);
        $this->assertEquals('EH1 1AB', $result['address_postcode']);
    }

    public function testConsumeUpdatesTokenRecord(): void
    {
        $this->db->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains('UPDATE declaration_tokens'),
                $this->equalTo([42, 'mytoken123'])
            );

        $this->service->consume('mytoken123', 42);
    }

    public function testGetActiveTokenReturnsNullWhenNoneExist(): void
    {
        $this->db->method('queryOne')->willReturn(null);

        $result = $this->service->getActiveToken(1, 1);

        $this->assertNull($result);
    }

    public function testGetActiveTokenReturnsTokenData(): void
    {
        $tokenData = ['id' => 1, 'token' => 'abc', 'donor_id' => 5, 'charity_id' => 1];
        $this->db->method('queryOne')->willReturn($tokenData);

        $result = $this->service->getActiveToken(5, 1);

        $this->assertEquals($tokenData, $result);
    }

    public function testBulkGenerateSkipsDonorsWithActiveDeclarations(): void
    {
        // Query returns only donors without active declarations (per SQL)
        $this->db->method('query')->willReturn([
            ['id' => 3, 'name' => 'No Declaration Donor', 'email' => 'nd@test.com'],
        ]);
        $this->db->method('insert')->willReturn(1);

        $results = $this->service->bulkGenerate(1, 10);

        $this->assertCount(1, $results);
        $this->assertEquals('No Declaration Donor', $results[0]['donor_name']);
        $this->assertEquals('nd@test.com', $results[0]['donor_email']);
        $this->assertEquals(64, strlen($results[0]['token']));
    }

    public function testBulkGenerateReturnsEmptyWhenAllDonorsHaveDeclarations(): void
    {
        $this->db->method('query')->willReturn([]);

        $results = $this->service->bulkGenerate(1, 10);

        $this->assertEmpty($results);
    }
}
