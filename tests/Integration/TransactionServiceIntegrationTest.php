<?php

declare(strict_types=1);

namespace CharityCashbook\Tests\Integration;

use CharityCashbook\Charity\TransactionService;
use CharityCashbook\Charity\CategoryService;
use CharityCashbook\Charity\AuditService;
use CharityCashbook\Core\DatabaseInterface;
use CharityCashbook\Core\Logger;

/**
 * Integration tests for TransactionService.
 * 
 * Tests complex transaction scenarios with real database:
 * - Multi-fund/category/cashbook transactions
 * - Gift Aid status determination
 * - Transfer validation
 * 
 * Requires MariaDB - set DB_HOST env var to run these tests.
 */
class TransactionServiceIntegrationTest extends BaseIntegrationTest
{
    private TransactionService $service;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->getService(TransactionService::class);
    }
    
    public function testCreateSimpleReceipt(): void
    {
        // Create test data
        $charity = $this->createTestCharity();
        $user = $this->createTestUser($charity['id']);
        $fund = $this->createTestFund($charity['id']);
        $reportingCategory = $this->createTestReportingCategory($charity['id'], 'receipt');
        $opCategory = $this->createTestOperationalCategory($charity['id'], $reportingCategory['id']);
        
        // Create transaction
        $id = $this->service->create($charity['id'], $user['id'], [
            'transaction_date' => '2026-01-15',
            'description' => 'Test donation',
            'amount' => 100.00,
            'fund_id' => $fund['id'],
            'operational_category_id' => $opCategory['id'],
        ]);
        
        $this->assertGreaterThan(0, $id);
        
        // Verify transaction was created
        $transaction = $this->service->get($id, $charity['id']);
        $this->assertEquals('Test donation', $transaction['description']);
        $this->assertEquals(100.00, (float) $transaction['amount']);
        $this->assertEquals('receipt', $transaction['transaction_type']);
    }
    
    public function testCreateReceiptWithGiftAidEligibleCategory(): void
    {
        $charity = $this->createTestCharity();
        $user = $this->createTestUser($charity['id']);
        $fund = $this->createTestFund($charity['id']);
        $reportingCategory = $this->createTestReportingCategory($charity['id'], 'receipt');
        $opCategory = $this->createTestOperationalCategory($charity['id'], $reportingCategory['id'], [
            'giftaid_eligible_default' => true,
        ]);
        $donor = $this->createTestDonor($charity['id']);
        $this->createTestDeclaration($donor['id'], '2025-01-01');
        
        // Create transaction with donor who has declaration
        $id = $this->service->create($charity['id'], $user['id'], [
            'transaction_date' => '2026-01-15',
            'description' => 'Gift Aid eligible donation',
            'amount' => 50.00,
            'fund_id' => $fund['id'],
            'operational_category_id' => $opCategory['id'],
            'donor_id' => $donor['id'],
            'giftaid_eligibility_override' => 'eligible',
        ]);
        
        $transaction = $this->service->get($id, $charity['id']);
        $this->assertEquals('eligible', $transaction['giftaid_status']);
    }
    
    public function testCreateReceiptWithPendingDeclaration(): void
    {
        $charity = $this->createTestCharity();
        $user = $this->createTestUser($charity['id']);
        $fund = $this->createTestFund($charity['id']);
        $reportingCategory = $this->createTestReportingCategory($charity['id'], 'receipt');
        $opCategory = $this->createTestOperationalCategory($charity['id'], $reportingCategory['id'], [
            'giftaid_eligible_default' => true,
        ]);
        // Donor without declaration
        $donor = $this->createTestDonor($charity['id']);
        
        // Create transaction - should be pending_declaration
        $id = $this->service->create($charity['id'], $user['id'], [
            'transaction_date' => '2026-01-15',
            'description' => 'Donation needing declaration',
            'amount' => 50.00,
            'fund_id' => $fund['id'],
            'operational_category_id' => $opCategory['id'],
            'donor_id' => $donor['id'],
            'giftaid_eligibility_override' => 'eligible',
        ]);
        
        $transaction = $this->service->get($id, $charity['id']);
        $this->assertEquals('pending_declaration', $transaction['giftaid_status']);
    }
    
    public function testCreateReceiptWithPendingDonor(): void
    {
        $charity = $this->createTestCharity();
        $user = $this->createTestUser($charity['id']);
        $fund = $this->createTestFund($charity['id']);
        $reportingCategory = $this->createTestReportingCategory($charity['id'], 'receipt');
        $opCategory = $this->createTestOperationalCategory($charity['id'], $reportingCategory['id'], [
            'giftaid_eligible_default' => true,
        ]);
        
        // Create transaction without donor - should be pending_donor
        $id = $this->service->create($charity['id'], $user['id'], [
            'transaction_date' => '2026-01-15',
            'description' => 'Donation needing donor',
            'amount' => 50.00,
            'fund_id' => $fund['id'],
            'operational_category_id' => $opCategory['id'],
            'giftaid_eligibility_override' => 'eligible',
        ]);
        
        $transaction = $this->service->get($id, $charity['id']);
        $this->assertEquals('pending_donor', $transaction['giftaid_status']);
    }
    
    public function testCreateReceiptExcludedFromGiftAid(): void
    {
        $charity = $this->createTestCharity();
        $user = $this->createTestUser($charity['id']);
        $fund = $this->createTestFund($charity['id']);
        $reportingCategory = $this->createTestReportingCategory($charity['id'], 'receipt');
        $opCategory = $this->createTestOperationalCategory($charity['id'], $reportingCategory['id'], [
            'giftaid_eligible_default' => true,
        ]);
        
        // Explicitly exclude from Gift Aid
        $id = $this->service->create($charity['id'], $user['id'], [
            'transaction_date' => '2026-01-15',
            'description' => 'Non-Gift Aid donation',
            'amount' => 50.00,
            'fund_id' => $fund['id'],
            'operational_category_id' => $opCategory['id'],
            'giftaid_eligibility_override' => 'not_eligible',
        ]);
        
        $transaction = $this->service->get($id, $charity['id']);
        $this->assertEquals('excluded', $transaction['giftaid_status']);
    }
    
    public function testCreateReceiptBeforeCharityGiftAidDate(): void
    {
        // Create charity with Gift Aid eligibility starting in 2026
        $charity = $this->createTestCharity(['giftaid_eligible_from_date' => '2026-01-01']);
        $user = $this->createTestUser($charity['id']);
        $fund = $this->createTestFund($charity['id']);
        $reportingCategory = $this->createTestReportingCategory($charity['id'], 'receipt');
        $opCategory = $this->createTestOperationalCategory($charity['id'], $reportingCategory['id'], [
            'giftaid_eligible_default' => true,
        ]);
        $donor = $this->createTestDonor($charity['id']);
        $this->createTestDeclaration($donor['id'], '2020-01-01');
        
        // Create transaction before eligibility date - should be not_applicable
        $id = $this->service->create($charity['id'], $user['id'], [
            'transaction_date' => '2025-12-15', // Before 2026-01-01
            'description' => 'Pre-eligibility donation',
            'amount' => 50.00,
            'fund_id' => $fund['id'],
            'operational_category_id' => $opCategory['id'],
            'donor_id' => $donor['id'],
            'giftaid_eligibility_override' => 'eligible',
        ]);
        
        $transaction = $this->service->get($id, $charity['id']);
        $this->assertEquals('not_applicable', $transaction['giftaid_status']);
    }
    
    public function testCreatePartialGiftAidAmount(): void
    {
        $charity = $this->createTestCharity();
        $user = $this->createTestUser($charity['id']);
        $fund = $this->createTestFund($charity['id']);
        $reportingCategory = $this->createTestReportingCategory($charity['id'], 'receipt');
        $opCategory = $this->createTestOperationalCategory($charity['id'], $reportingCategory['id'], [
            'giftaid_eligible_default' => true,
        ]);
        $donor = $this->createTestDonor($charity['id']);
        $this->createTestDeclaration($donor['id'], '2025-01-01');
        
        // Create transaction with partial Gift Aid amount
        $id = $this->service->create($charity['id'], $user['id'], [
            'transaction_date' => '2026-01-15',
            'description' => 'Partial Gift Aid donation',
            'amount' => 100.00,
            'fund_id' => $fund['id'],
            'operational_category_id' => $opCategory['id'],
            'donor_id' => $donor['id'],
            'giftaid_eligibility_override' => 'eligible',
            'giftaid_amount' => 75.00, // Only £75 of £100 is eligible
        ]);
        
        $transaction = $this->service->get($id, $charity['id']);
        $this->assertEquals('eligible', $transaction['giftaid_status']);
        $this->assertEquals(75.00, (float) $transaction['giftaid_amount']);
    }
    
    public function testPaymentIsNotGiftAidApplicable(): void
    {
        $charity = $this->createTestCharity();
        $user = $this->createTestUser($charity['id']);
        $fund = $this->createTestFund($charity['id']);
        $reportingCategory = $this->createTestReportingCategory($charity['id'], 'payment');
        $opCategory = $this->createTestOperationalCategory($charity['id'], $reportingCategory['id']);
        
        $id = $this->service->create($charity['id'], $user['id'], [
            'transaction_date' => '2026-01-15',
            'description' => 'Office supplies',
            'amount' => 50.00,
            'fund_id' => $fund['id'],
            'operational_category_id' => $opCategory['id'],
        ]);
        
        $transaction = $this->service->get($id, $charity['id']);
        $this->assertEquals('payment', $transaction['transaction_type']);
        $this->assertEquals('not_applicable', $transaction['giftaid_status']);
    }
    
    // Helper methods to create test data
    
    private function createTestCharity(array $overrides = []): array
    {
        $data = array_merge([
            'name' => 'Test Charity ' . uniqid(),
            'year_end_day' => 31,
            'year_end_month' => 3,
            'is_active' => 1,
        ], $overrides);
        
        $id = $this->getDb()->insert('charities', $data);
        return array_merge(['id' => $id], $data);
    }
    
    private function createTestUser(int $charityId): array
    {
        $userData = [
            'display_name' => 'Test User',
            'primary_email' => 'test' . uniqid() . '@example.com',
            'is_active' => 1,
        ];
        $userId = $this->getDb()->insert('users', $userData);
        
        $this->getDb()->insert('charity_users', [
            'charity_id' => $charityId,
            'user_id' => $userId,
            'role' => 'admin',
            'is_active' => 1,
        ]);
        
        return array_merge(['id' => $userId], $userData);
    }
    
    private function createTestFund(int $charityId, array $overrides = []): array
    {
        $data = array_merge([
            'charity_id' => $charityId,
            'name' => 'General Fund',
            'fund_type' => 'unrestricted',
            'is_active' => 1,
        ], $overrides);
        
        $id = $this->getDb()->insert('funds', $data);
        return array_merge(['id' => $id], $data);
    }
    
    private function createTestReportingCategory(int $charityId, string $type): array
    {
        $data = [
            'charity_id' => $charityId,
            'name' => ucfirst($type) . ' Category',
            'category_type' => $type,
            'is_active' => 1,
        ];
        
        $id = $this->getDb()->insert('reporting_categories', $data);
        return array_merge(['id' => $id], $data);
    }
    
    private function createTestOperationalCategory(int $charityId, int $reportingCategoryId, array $overrides = []): array
    {
        $data = array_merge([
            'charity_id' => $charityId,
            'reporting_category_id' => $reportingCategoryId,
            'name' => 'Test Operational Category',
            'giftaid_eligible_default' => false,
            'is_active' => 1,
        ], $overrides);
        
        $id = $this->getDb()->insert('operational_categories', $data);
        return array_merge(['id' => $id], $data);
    }
    
    private function createTestDonor(int $charityId): array
    {
        $data = [
            'charity_id' => $charityId,
            'name' => 'Test Donor ' . uniqid(),
            'is_active' => 1,
        ];
        
        $id = $this->getDb()->insert('donors', $data);
        return array_merge(['id' => $id], $data);
    }
    
    private function createTestDeclaration(int $donorId, string $startDate): array
    {
        $data = [
            'donor_id' => $donorId,
            'start_date' => $startDate,
            'covers_past_donations' => 1,
        ];
        
        $id = $this->getDb()->insert('declarations', $data);
        return array_merge(['id' => $id], $data);
    }
}
