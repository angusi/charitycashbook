<?php

declare(strict_types=1);

namespace CharityCashbook\Tests\Unit\Charity;

use CharityCashbook\Charity\AuditService;
use CharityCashbook\Charity\CategoryService;
use CharityCashbook\Charity\TransactionService;
use CharityCashbook\Core\DatabaseInterface;
use CharityCashbook\Core\Exceptions\ValidationException;
use CharityCashbook\Core\Logger;
use PHPUnit\Framework\TestCase;

class TransferTest extends TestCase
{
    private TransactionService $service;
    private DatabaseInterface $db;
    private Logger $logger;
    private CategoryService $categoryService;
    private AuditService $auditService;

    protected function setUp(): void
    {
        $this->db = $this->createMock(DatabaseInterface::class);
        $this->logger = $this->createMock(Logger::class);
        $this->categoryService = $this->createMock(CategoryService::class);
        $this->auditService = $this->createMock(AuditService::class);

        $this->service = new TransactionService(
            $this->db,
            $this->logger,
            $this->categoryService,
            $this->auditService
        );
    }

    public function testCreateTransferWithBalancedFunds(): void
    {
        $this->db->method('insert')->willReturn(1);
        $this->db->method('beginTransaction');
        $this->db->method('commit');

        $id = $this->service->create(1, 1, [
            'transaction_type' => 'transfer',
            'transaction_date' => '2024-01-01',
            'amount' => 100.00,
            'description' => 'Transfer from General to Projects',
            'fund_entries' => [
                ['fund_id' => 1, 'amount' => -100.00], // Source (negative)
                ['fund_id' => 2, 'amount' => 100.00],  // Destination (positive)
            ],
        ]);

        $this->assertEquals(1, $id);
    }

    public function testCreateTransferRequiresAtLeastTwoEntriesOfSomeType(): void
    {
        try {
            $this->service->create(1, 1, [
                'transaction_type' => 'transfer',
                'transaction_date' => '2024-01-01',
                'amount' => 100.00,
                'description' => 'Transfer',
                'fund_entries' => [
                    ['fund_id' => 1, 'amount' => -100.00],
                ],
                'cashbook_entries' => [
                    ['cash_book_id' => 1, 'amount' => 100.00],
                ],
            ]);
            $this->fail('Expected ValidationException was not thrown');
        } catch (ValidationException $e) {
            $errors = $e->getErrors();
            $this->assertStringContainsString('at least 2 fund entries or 2 cashbook entries', $errors['entries']);
        }
    }

    public function testCreateTransferRequiresFundsToBalance(): void
    {
        try {
            $this->service->create(1, 1, [
                'transaction_type' => 'transfer',
                'transaction_date' => '2024-01-01',
                'amount' => 100.00,
                'description' => 'Transfer',
                'fund_entries' => [
                    ['fund_id' => 1, 'amount' => -100.00],
                    ['fund_id' => 2, 'amount' => 50.00], // Doesn't balance
                ],
            ]);
            $this->fail('Expected ValidationException was not thrown');
        } catch (ValidationException $e) {
            $errors = $e->getErrors();
            $this->assertStringContainsString('balance to zero', $errors['fund_entries']);
        }
    }

    public function testCreateTransferRejectsCategories(): void
    {
        try {
            $this->service->create(1, 1, [
                'transaction_type' => 'transfer',
                'transaction_date' => '2024-01-01',
                'amount' => 100.00,
                'description' => 'Transfer',
                'fund_entries' => [
                    ['fund_id' => 1, 'amount' => -100.00],
                    ['fund_id' => 2, 'amount' => 100.00],
                ],
                'category_entries' => [
                    ['operational_category_id' => 1, 'amount' => 100.00],
                ],
            ]);
            $this->fail('Expected ValidationException was not thrown');
        } catch (ValidationException $e) {
            $errors = $e->getErrors();
            $this->assertStringContainsString('should not have categories', $errors['category_entries']);
        }
    }

    public function testCreateTransferWithCashbookEntriesMustBalance(): void
    {
        try {
            $this->service->create(1, 1, [
                'transaction_type' => 'transfer',
                'transaction_date' => '2024-01-01',
                'amount' => 100.00,
                'description' => 'Transfer',
                'fund_entries' => [
                    ['fund_id' => 1, 'amount' => -100.00],
                    ['fund_id' => 2, 'amount' => 100.00],
                ],
                'cashbook_entries' => [
                    ['cash_book_id' => 1, 'amount' => 100.00], // Doesn't balance
                ],
            ]);
            $this->fail('Expected ValidationException was not thrown');
        } catch (ValidationException $e) {
            $errors = $e->getErrors();
            $this->assertStringContainsString('balance to zero', $errors['cashbook_entries']);
        }
    }

    public function testCreateCashbookTransfer(): void
    {
        $this->db->method('insert')->willReturn(1);
        $this->db->method('beginTransaction');
        $this->db->method('commit');

        // Cashbook-only transfer (e.g., petty cash to bank)
        $id = $this->service->create(1, 1, [
            'transaction_type' => 'transfer',
            'transaction_date' => '2024-01-01',
            'amount' => 100.00,
            'description' => 'Deposit petty cash to bank',
            'cashbook_entries' => [
                ['cash_book_id' => 1, 'amount' => -100.00], // From petty cash
                ['cash_book_id' => 2, 'amount' => 100.00],  // To bank
            ],
        ]);

        $this->assertEquals(1, $id);
    }

    public function testCreateCombinedFundAndCashbookTransfer(): void
    {
        $this->db->method('insert')->willReturn(1);
        $this->db->method('beginTransaction');
        $this->db->method('commit');

        // Combined transfer: move funds AND record in cashbooks
        $id = $this->service->create(1, 1, [
            'transaction_type' => 'transfer',
            'transaction_date' => '2024-01-01',
            'amount' => 100.00,
            'description' => 'Transfer between funds and accounts',
            'fund_entries' => [
                ['fund_id' => 1, 'amount' => -100.00],
                ['fund_id' => 2, 'amount' => 100.00],
            ],
            'cashbook_entries' => [
                ['cash_book_id' => 1, 'amount' => -100.00],
                ['cash_book_id' => 2, 'amount' => 100.00],
            ],
        ]);

        $this->assertEquals(1, $id);
    }

    public function testCreateTransferSupportsMultipleFunds(): void
    {
        $this->db->method('insert')->willReturn(1);
        $this->db->method('beginTransaction');
        $this->db->method('commit');

        // Transfer from multiple sources to one destination
        $id = $this->service->create(1, 1, [
            'transaction_type' => 'transfer',
            'transaction_date' => '2024-01-01',
            'amount' => 100.00,
            'description' => 'Consolidate funds',
            'fund_entries' => [
                ['fund_id' => 1, 'amount' => -60.00],  // Source 1
                ['fund_id' => 2, 'amount' => -40.00],  // Source 2
                ['fund_id' => 3, 'amount' => 100.00],  // Destination
            ],
        ]);

        $this->assertEquals(1, $id);
    }
}
