<?php

declare(strict_types=1);

namespace CharityCashbook\Tests\Unit\Charity;

use PHPUnit\Framework\TestCase;
use CharityCashbook\Charity\DonorService;
use CharityCashbook\Charity\AuditService;
use CharityCashbook\Core\DatabaseInterface;
use CharityCashbook\Core\Logger;
use CharityCashbook\Core\Exceptions\ValidationException;

class DonorServiceTest extends TestCase
{
    private DonorService $service;
    private DatabaseInterface $db;
    private Logger $logger;
    private AuditService $auditService;

    protected function setUp(): void
    {
        $this->db = $this->createMock(DatabaseInterface::class);
        $this->logger = $this->createMock(Logger::class);
        $this->auditService = $this->createMock(AuditService::class);
        
        $this->service = new DonorService(
            $this->db,
            $this->logger,
            $this->auditService
        );
    }

    // getAll tests
    public function testGetAllReturnsArray(): void
    {
        $this->db->method('query')->willReturn([
            ['id' => 1, 'name' => 'John Doe'],
        ]);

        $result = $this->service->getAll(1);
        
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
    }

    public function testGetAllIncludesInactiveWhenFlagFalse(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->logicalNot($this->stringContains('is_active = TRUE')),
                $this->anything()
            )
            ->willReturn([]);

        $this->service->getAll(1, false);
    }

    // search tests
    public function testSearchReturnsResults(): void
    {
        $this->db->method('query')->willReturn([
            ['id' => 1, 'name' => 'John Doe'],
        ]);

        $result = $this->service->search(1, 'John');
        
        $this->assertIsArray($result);
    }

    public function testSearchWithEmptyQuery(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->logicalNot($this->stringContains('name LIKE')),
                $this->anything()
            )
            ->willReturn([]);

        $this->service->search(1, '');
    }

    // quickSearch tests
    public function testQuickSearchReturnsMinimalData(): void
    {
        $this->db->method('query')->willReturn([
            ['id' => 1, 'name' => 'John'],
        ]);

        $result = $this->service->quickSearch(1, 'John');
        
        $this->assertIsArray($result);
    }

    // getNameOnly tests
    public function testGetNameOnlyReturnsDonor(): void
    {
        $this->db->method('queryOne')->willReturn(['id' => 1, 'name' => 'John']);

        $result = $this->service->getNameOnly(1, 1);
        
        $this->assertNotNull($result);
        $this->assertEquals('John', $result['name']);
    }

    public function testGetNameOnlyReturnsNullWhenNotFound(): void
    {
        $this->db->method('queryOne')->willReturn(null);

        $result = $this->service->getNameOnly(999, 1);
        
        $this->assertNull($result);
    }

    // get tests
    public function testGetReturnsDonor(): void
    {
        $donor = ['id' => 1, 'name' => 'John', 'email' => 'john@example.com'];
        $this->db->method('queryOne')->willReturn($donor);

        $result = $this->service->get(1, 1);
        
        $this->assertIsArray($result);
        $this->assertEquals(1, $result['id']);
    }

    // getDonations tests
    public function testGetDonationsReturnsArray(): void
    {
        $this->db->method('query')->willReturn([
            ['id' => 1, 'amount' => 100],
        ]);

        $result = $this->service->getDonations(1, 1);
        
        $this->assertIsArray($result);
    }

    // getFullDetails tests
    public function testGetFullDetailsLogsPiiAccess(): void
    {
        $donor = ['id' => 1, 'name' => 'John', 'address_line1' => '123 Street'];
        $this->db->method('queryOne')->willReturn($donor);
        
        $this->auditService->expects($this->once())
            ->method('logDonorAccess')
            ->with(1, 1, 1, 'view_address');

        $this->service->getFullDetails(1, 1, 1);
    }

    public function testGetFullDetailsDoesNotLogWhenNotFound(): void
    {
        $this->db->method('queryOne')->willReturn(null);
        
        $this->auditService->expects($this->never())->method('logDonorAccess');

        $result = $this->service->getFullDetails(999, 1, 1);
        
        $this->assertNull($result);
    }

    // create tests
    public function testCreateReturnsId(): void
    {
        $this->db->method('insert')->willReturn(5);
        $this->auditService->expects($this->once())->method('log');
        $this->logger->expects($this->once())->method('info');

        $result = $this->service->create(1, 1, ['name' => 'New Donor']);
        
        $this->assertEquals(5, $result);
    }

    public function testCreateThrowsValidationExceptionForMissingName(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->create(1, 1, []);
    }

    public function testCreateThrowsValidationExceptionForEmptyName(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->create(1, 1, ['name' => '']);
    }

    // update tests
    public function testUpdateSucceeds(): void
    {
        $this->db->expects($this->once())->method('update');
        $this->auditService->expects($this->once())->method('log');
        $this->auditService->expects($this->once())->method('logDonorAccess');

        $this->service->update(1, 1, 1, ['name' => 'Updated Name']);
    }

    public function testUpdateThrowsValidationExceptionForMissingName(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->update(1, 1, 1, []);
    }

    // deactivate tests
    public function testDeactivateExecutesUpdate(): void
    {
        $this->db->expects($this->once())->method('execute');
        $this->auditService->expects($this->once())->method('log');

        $this->service->deactivate(1, 1, 1);
    }

    // updateAddress tests
    public function testUpdateAddressExecutesUpdate(): void
    {
        $this->db->expects($this->once())->method('execute');

        $this->service->updateAddress(1, [
            'address_line1' => '123 Street',
            'address_postcode' => 'AB1 2CD',
        ]);
    }

    // getDeclarations tests
    public function testGetDeclarationsReturnsArray(): void
    {
        $this->db->method('query')->willReturn([
            ['id' => 1, 'start_date' => '2025-01-01'],
        ]);

        $result = $this->service->getDeclarations(1, 1);
        
        $this->assertIsArray($result);
    }

    // getCurrentDeclarationStatus tests
    public function testGetCurrentDeclarationStatusReturnsNone(): void
    {
        $this->db->method('queryOne')->willReturn(null);

        $result = $this->service->getCurrentDeclarationStatus(1);
        
        $this->assertEquals('none', $result);
    }

    public function testGetCurrentDeclarationStatusReturnsActive(): void
    {
        $this->db->method('queryOne')->willReturn([
            'id' => 1,
            'withdrawn_date' => null,
        ]);

        $result = $this->service->getCurrentDeclarationStatus(1);
        
        $this->assertEquals('active', $result);
    }

    public function testGetCurrentDeclarationStatusReturnsWithdrawn(): void
    {
        $this->db->method('queryOne')->willReturn([
            'id' => 1,
            'withdrawn_date' => '2025-06-01',
        ]);

        $result = $this->service->getCurrentDeclarationStatus(1);
        
        $this->assertEquals('withdrawn', $result);
    }

    // hasValidDeclaration tests
    public function testHasValidDeclarationReturnsTrue(): void
    {
        $this->db->method('queryValue')->willReturn(1);

        $result = $this->service->hasValidDeclaration(1, '2025-01-15');
        
        $this->assertTrue($result);
    }

    public function testHasValidDeclarationReturnsFalse(): void
    {
        $this->db->method('queryValue')->willReturn(0);

        $result = $this->service->hasValidDeclaration(1, '2025-01-15');
        
        $this->assertFalse($result);
    }

    // addDeclaration tests
    public function testAddDeclarationReturnsId(): void
    {
        $this->db->method('queryOne')->willReturn(['id' => 1, 'name' => 'John']);
        $this->db->method('insert')->willReturn(10);
        $this->auditService->expects($this->once())->method('log');

        $result = $this->service->addDeclaration(1, 1, 1, [
            'start_date' => '2025-01-01',
        ]);
        
        $this->assertEquals(10, $result);
    }

    public function testAddDeclarationThrowsExceptionForInvalidDonor(): void
    {
        $this->db->method('queryOne')->willReturn(null);

        $this->expectException(ValidationException::class);

        $this->service->addDeclaration(999, 1, 1, [
            'start_date' => '2025-01-01',
        ]);
    }

    // withdrawDeclaration tests
    public function testWithdrawDeclarationUpdatesDeclaration(): void
    {
        $this->db->method('queryOne')
            ->willReturnOnConsecutiveCalls(
                ['id' => 1, 'donor_id' => 5, 'charity_id' => 1], // Declaration found
            );

        $this->db->method('query')->willReturn([]); // No transactions to update

        $this->db->expects($this->once())->method('execute');
        $this->auditService->expects($this->once())->method('log');

        $this->service->withdrawDeclaration(1, 1, 1, '2025-06-01');
    }

    public function testWithdrawDeclarationThrowsExceptionWhenNotFound(): void
    {
        $this->db->method('queryOne')->willReturn(null);

        $this->expectException(ValidationException::class);

        $this->service->withdrawDeclaration(999, 1, 1, '2025-06-01');
    }

    // getDonorsWithEligibleDonations tests
    public function testGetDonorsWithEligibleDonationsReturnsArray(): void
    {
        $this->db->method('query')->willReturn([
            ['id' => 1, 'name' => 'John', 'eligible_count' => 5, 'eligible_total' => 500],
        ]);

        $result = $this->service->getDonorsWithEligibleDonations(1, '2025-01-01', '2025-12-31');

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertEquals(5, $result[0]['eligible_count']);
    }

    // Additional edge cases
    public function testCreateWithAllFields(): void
    {
        $this->db->method('insert')->willReturn(1);

        $result = $this->service->create(1, 1, [
            'name' => 'Full Donor',
            'address_line1' => '123 Street',
            'address_line2' => 'Apt 4',
            'address_city' => 'London',
            'address_postcode' => 'SW1A 1AA',
            'email' => 'donor@example.com',
            'notes' => 'Important donor',
        ]);

        $this->assertEquals(1, $result);
    }

    public function testUpdateWithAllFields(): void
    {
        $this->db->expects($this->once())->method('update');

        $this->service->update(1, 1, 1, [
            'name' => 'Updated Donor',
            'address_line1' => '456 Avenue',
            'address_line2' => 'Suite 5',
            'address_city' => 'Manchester',
            'address_postcode' => 'M1 1AA',
            'email' => 'updated@example.com',
            'notes' => 'Updated notes',
        ]);
    }

    public function testUpdateAddressWithEmptyFields(): void
    {
        $this->db->expects($this->once())->method('execute');

        $this->service->updateAddress(1, []);
    }

    public function testAddDeclarationWithCoversPast(): void
    {
        $this->db->method('queryOne')->willReturn(['id' => 1, 'name' => 'John']);
        $this->db->method('query')->willReturn([]); // No transactions to update
        $this->db->method('insert')->willReturn(10);

        $result = $this->service->addDeclaration(1, 1, 1, [
            'start_date' => '2025-01-01',
            'covers_past_donations' => true,
            'covers_past_from_date' => '2021-01-01',
            'notes' => 'Covers past 4 years',
        ]);

        $this->assertEquals(10, $result);
    }
}
