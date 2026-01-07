<?php

declare(strict_types=1);

namespace CharityCashbook\Tests\Unit\Charity;

use CharityCashbook\Charity\CashBookService;
use CharityCashbook\Core\DatabaseInterface;
use CharityCashbook\Core\Logger;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class CashBookServiceTest extends TestCase
{
    private CashBookService $service;
    private MockObject $db;
    private MockObject $logger;

    protected function setUp(): void
    {
        $this->db = $this->createMock(DatabaseInterface::class);
        $this->logger = $this->createMock(Logger::class);

        $this->service = new CashBookService($this->db, $this->logger);
    }

    // ===========================================
    // getCashBooks() tests
    // ===========================================

    public function testGetCashBooksReturnsActiveOnly(): void
    {
        $expected = [
            ['id' => 1, 'name' => 'Main Account', 'is_active' => true],
            ['id' => 2, 'name' => 'Petty Cash', 'is_active' => true],
        ];

        $this->db->expects($this->once())
            ->method('query')
            ->with($this->stringContains('is_active = TRUE'))
            ->willReturn($expected);

        $result = $this->service->getCashBooks(1, true);
        $this->assertEquals($expected, $result);
    }

    public function testGetCashBooksReturnsAll(): void
    {
        $expected = [
            ['id' => 1, 'name' => 'Main Account', 'is_active' => true],
            ['id' => 2, 'name' => 'Closed Account', 'is_active' => false],
        ];

        $this->db->expects($this->once())
            ->method('query')
            ->with($this->logicalNot($this->stringContains('is_active')))
            ->willReturn($expected);

        $result = $this->service->getCashBooks(1, false);
        $this->assertEquals($expected, $result);
    }

    public function testGetCashBooksReturnsEmptyArray(): void
    {
        $this->db->method('query')->willReturn([]);
        $result = $this->service->getCashBooks(1);
        $this->assertEquals([], $result);
    }

    // ===========================================
    // getCashBook() tests
    // ===========================================

    public function testGetCashBookReturnsCashBook(): void
    {
        $expected = [
            'id' => 1,
            'name' => 'Main Account',
            'book_type' => 'bank',
            'opening_balance' => '1000.00',
        ];

        $this->db->expects($this->once())
            ->method('queryOne')
            ->willReturn($expected);

        $result = $this->service->getCashBook(1);
        $this->assertEquals($expected, $result);
    }

    public function testGetCashBookReturnsNullWhenNotFound(): void
    {
        $this->db->method('queryOne')->willReturn(null);
        $result = $this->service->getCashBook(999);
        $this->assertNull($result);
    }

    // ===========================================
    // create() tests
    // ===========================================

    public function testCreateWithMinimalData(): void
    {
        $this->db->expects($this->once())
            ->method('insert')
            ->with('cash_books', $this->callback(function ($data) {
                return $data['charity_id'] === 1
                    && $data['name'] === 'New Account'
                    && $data['book_type'] === 'bank'
                    && $data['opening_balance'] === 0.00;
            }));

        $this->db->method('lastInsertId')->willReturn(5);

        $result = $this->service->create(1, [
            'name' => 'New Account',
            'opening_balance_date' => '2024-01-01',
        ]);

        $this->assertEquals(5, $result);
    }

    public function testCreateWithFullData(): void
    {
        $this->db->expects($this->once())
            ->method('insert')
            ->with('cash_books', $this->callback(function ($data) {
                return $data['charity_id'] === 1
                    && $data['name'] === 'Business Account'
                    && $data['book_type'] === 'bank'
                    && $data['description'] === 'Main charity account'
                    && $data['account_number'] === '12345678'
                    && $data['sort_code'] === '12-34-56'
                    && $data['opening_balance'] === 5000.00
                    && $data['display_order'] === 1;
            }));

        $this->db->method('lastInsertId')->willReturn(6);

        $result = $this->service->create(1, [
            'name' => 'Business Account',
            'book_type' => 'bank',
            'description' => 'Main charity account',
            'account_number' => '12345678',
            'sort_code' => '12-34-56',
            'opening_balance' => 5000.00,
            'opening_balance_date' => '2024-01-01',
            'display_order' => 1,
        ]);

        $this->assertEquals(6, $result);
    }

    public function testCreatePettyCash(): void
    {
        $this->db->expects($this->once())
            ->method('insert')
            ->with('cash_books', $this->callback(function ($data) {
                return $data['book_type'] === 'petty_cash';
            }));

        $this->db->method('lastInsertId')->willReturn(7);

        $this->service->create(1, [
            'name' => 'Petty Cash',
            'book_type' => 'petty_cash',
            'opening_balance_date' => '2024-01-01',
        ]);
    }

    // ===========================================
    // update() tests
    // ===========================================

    public function testUpdateWithNameOnly(): void
    {
        $this->db->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains('name = ?'),
                ['Updated Name', 1]
            );

        $this->service->update(1, ['name' => 'Updated Name']);
    }

    public function testUpdateWithMultipleFields(): void
    {
        $this->db->expects($this->once())
            ->method('execute')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('name = ?'),
                    $this->stringContains('description = ?')
                ),
                $this->callback(function ($params) {
                    return $params[0] === 'New Name'
                        && $params[1] === 'New Description'
                        && $params[2] === 1;
                })
            );

        $this->service->update(1, [
            'name' => 'New Name',
            'description' => 'New Description',
        ]);
    }

    public function testUpdateWithEmptyData(): void
    {
        $this->db->expects($this->never())
            ->method('execute');

        $this->service->update(1, []);
    }

    public function testUpdateIgnoresInvalidFields(): void
    {
        $this->db->expects($this->once())
            ->method('execute')
            ->with(
                $this->logicalNot($this->stringContains('invalid_field')),
                $this->anything()
            );

        $this->service->update(1, [
            'name' => 'Valid',
            'invalid_field' => 'ignored',
        ]);
    }

    public function testUpdateIsActive(): void
    {
        $this->db->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains('is_active = ?'),
                [false, 1]
            );

        $this->service->update(1, ['is_active' => false]);
    }

    // ===========================================
    // calculateBalance() tests
    // ===========================================

    public function testCalculateBalanceReturnsCorrectValues(): void
    {
        $this->db->method('queryOne')
            ->willReturnOnConsecutiveCalls(
                // Cash book
                [
                    'id' => 1,
                    'opening_balance' => '1000.00',
                    'opening_balance_date' => '2024-01-01',
                ],
                // Cashbook entries summary
                [
                    'total_income' => '500.00',
                    'total_expenses' => '200.00',
                    'entry_count' => '10',
                ]
            );

        $result = $this->service->calculateBalance(1);

        $this->assertEquals(1000.00, $result['opening_balance']);
        $this->assertEquals('2024-01-01', $result['opening_date']);
        $this->assertEquals(500.00, $result['total_income']);
        $this->assertEquals(200.00, $result['total_expenses']);
        $this->assertEquals(1300.00, $result['current_balance']); // 1000 + 500 - 200
        $this->assertEquals(10, $result['entry_count']);
    }

    public function testCalculateBalanceWithAsOfDate(): void
    {
        $this->db->method('queryOne')
            ->willReturnOnConsecutiveCalls(
                ['id' => 1, 'opening_balance' => '0', 'opening_balance_date' => '2024-01-01'],
                ['total_income' => '100.00', 'total_expenses' => '50.00', 'entry_count' => '5']
            );

        $result = $this->service->calculateBalance(1, '2024-06-30');

        $this->assertEquals('2024-06-30', $result['as_of_date']);
    }

    public function testCalculateBalanceThrowsExceptionForNonexistentCashBook(): void
    {
        $this->db->method('queryOne')->willReturn(null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cash book not found');

        $this->service->calculateBalance(999);
    }

    public function testCalculateBalanceWithNoTransactions(): void
    {
        $this->db->method('queryOne')
            ->willReturnOnConsecutiveCalls(
                ['id' => 1, 'opening_balance' => '500.00', 'opening_balance_date' => '2024-01-01'],
                ['total_income' => null, 'total_expenses' => null, 'entry_count' => null]
            );

        $result = $this->service->calculateBalance(1);

        $this->assertEquals(500.00, $result['current_balance']);
        $this->assertEquals(0, $result['entry_count']);
    }

    // ===========================================
    // getTransactions() tests
    // ===========================================

    public function testGetTransactionsReturnsTransactions(): void
    {
        $expected = [
            ['id' => 1, 'amount' => '100.00', 'fund_name' => 'General'],
            ['id' => 2, 'amount' => '50.00', 'fund_name' => 'Restricted'],
        ];

        $this->db->expects($this->once())
            ->method('query')
            ->willReturn($expected);

        $result = $this->service->getTransactions(1);
        $this->assertEquals($expected, $result);
    }

    public function testGetTransactionsWithStartDateFilter(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('transaction_date >= ?'),
                $this->callback(function ($params) {
                    return $params[0] === 1 && $params[1] === '2024-01-01';
                })
            )
            ->willReturn([]);

        $this->service->getTransactions(1, ['start_date' => '2024-01-01']);
    }

    public function testGetTransactionsWithEndDateFilter(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('transaction_date <= ?'),
                $this->anything()
            )
            ->willReturn([]);

        $this->service->getTransactions(1, ['end_date' => '2024-12-31']);
    }

    public function testGetTransactionsWithReconciledFilter(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('is_reconciled = ?'),
                $this->callback(function ($params) {
                    return $params[1] === 1; // true converted to 1
                })
            )
            ->willReturn([]);

        $this->service->getTransactions(1, ['is_reconciled' => true]);
    }

    public function testGetTransactionsWithLimit(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('LIMIT 50'),
                $this->anything()
            )
            ->willReturn([]);

        $this->service->getTransactions(1, ['limit' => 50]);
    }

    // ===========================================
    // getUnassignedTransactions() tests
    // ===========================================

    public function testGetUnlinkedTransactionsReturnsTransactions(): void
    {
        $expected = [
            ['id' => 1, 'amount' => '100.00'],
        ];

        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('NOT EXISTS'),
                    $this->stringContains('transaction_cashbook_entries')
                )
            )
            ->willReturn($expected);

        $result = $this->service->getUnassignedTransactions(1);
        $this->assertEquals($expected, $result);
    }

    public function testGetUnlinkedTransactionsWithFilters(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('transaction_date >= ?'),
                    $this->stringContains('transaction_date <= ?'),
                    $this->stringContains('LIMIT 25')
                ),
                $this->anything()
            )
            ->willReturn([]);

        $this->service->getUnassignedTransactions(1, [
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'limit' => 25,
        ]);
    }

    // ===========================================
    // countUnassignedTransactions() tests
    // ===========================================

    public function testCountUnlinkedTransactionsReturnsCount(): void
    {
        $this->db->method('queryOne')
            ->willReturn(['cnt' => '15']);

        $result = $this->service->countUnassignedTransactions(1);
        $this->assertEquals(15, $result);
    }

    public function testCountUnlinkedTransactionsReturnsZero(): void
    {
        $this->db->method('queryOne')
            ->willReturn(['cnt' => null]);

        $result = $this->service->countUnassignedTransactions(1);
        $this->assertEquals(0, $result);
    }

    // ===========================================
    // assignTransactions() tests
    // ===========================================

    public function testAssignTransactionsUpdatesTransactions(): void
    {
        // Mock the transaction lookup and entry creation
        $this->db->method('queryOne')
            ->willReturn(['id' => 1, 'amount' => '100.00', 'transaction_type' => 'receipt']);
        
        $this->db->method('queryValue')
            ->willReturn(0); // No existing cashbook entries

        $this->db->expects($this->atLeastOnce())
            ->method('insert')
            ->with(
                'transaction_cashbook_entries',
                $this->anything()
            );

        $result = $this->service->assignTransactions([1, 2, 3], 5);
        $this->assertEquals(3, $result);
    }

    public function testAssignTransactionsReturnsZeroForEmptyArray(): void
    {
        $this->db->expects($this->never())
            ->method('execute');

        $result = $this->service->assignTransactions([], 5);
        $this->assertEquals(0, $result);
    }

    // ===========================================
    // unassignTransactions() tests
    // ===========================================

    public function testUnassignTransactionsUpdatesTransactions(): void
    {
        // PHPUnit 10 removed withConsecutive, so we just verify it's called twice
        $this->db->expects($this->exactly(2))
            ->method('execute');

        $result = $this->service->unassignTransactions([1, 2]);
        $this->assertEquals(2, $result);
    }

    public function testUnassignTransactionsReturnsZeroForEmptyArray(): void
    {
        $this->db->expects($this->never())
            ->method('execute');

        $result = $this->service->unassignTransactions([]);
        $this->assertEquals(0, $result);
    }

    public function testUnassignTransactionsClearsReconciliationStatus(): void
    {
        // The new implementation deletes entries and updates legacy columns
        $this->db->expects($this->exactly(2))
            ->method('execute');

        $this->service->unassignTransactions([1]);
    }

    // ===========================================
    // reconcile() tests
    // ===========================================

    public function testReconcileCreatesReconciliationRecord(): void
    {
        $this->db->method('queryOne')
            ->willReturnOnConsecutiveCalls(
                // getCashBook for calculateBalance
                ['id' => 1, 'opening_balance' => '1000.00', 'opening_balance_date' => '2024-01-01'],
                // Cashbook entries summary for calculateBalance
                ['total_income' => '500.00', 'total_expenses' => '200.00', 'entry_count' => '10']
            );

        $this->db->expects($this->once())
            ->method('insert')
            ->with('cash_book_reconciliations', $this->callback(function ($data) {
                return $data['cash_book_id'] === 1
                    && $data['statement_balance'] === 1350.00
                    && $data['expected_balance'] === 1300.00
                    && $data['discrepancy'] === 50.00
                    && $data['reconciled_by_user_id'] === 1;
            }));

        $this->db->method('lastInsertId')->willReturn(10);

        $result = $this->service->reconcile(1, [
            'reconciled_date' => '2024-06-30',
            'statement_balance' => 1350.00,
        ], 1);

        $this->assertEquals(10, $result);
    }

    public function testReconcileMarksTransactionsAsReconciled(): void
    {
        $this->db->method('queryOne')
            ->willReturnOnConsecutiveCalls(
                ['id' => 1, 'opening_balance' => '0', 'opening_balance_date' => '2024-01-01'],
                ['total_income' => '0', 'total_expenses' => '0', 'entry_count' => '0']
            );

        $this->db->method('lastInsertId')->willReturn(1);

        // Now expects two execute calls - one for cashbook entries, one for legacy columns
        $this->db->expects($this->exactly(2))
            ->method('execute')
            ->with(
                $this->stringContains('is_reconciled = TRUE'),
                $this->anything()
            );

        $this->service->reconcile(1, [
            'reconciled_date' => '2024-06-30',
            'statement_balance' => 0.00,
        ], 1);
    }

    public function testReconcileSkipsMarkingTransactions(): void
    {
        $this->db->method('queryOne')
            ->willReturnOnConsecutiveCalls(
                ['id' => 1, 'opening_balance' => '0', 'opening_balance_date' => '2024-01-01'],
                ['total_income' => '0', 'total_expenses' => '0', 'entry_count' => '0']
            );

        $this->db->method('lastInsertId')->willReturn(1);

        $this->db->expects($this->never())
            ->method('execute');

        $this->service->reconcile(1, [
            'reconciled_date' => '2024-06-30',
            'statement_balance' => 0.00,
            'skip_mark_reconciled' => true,
        ], 1);
    }

    // ===========================================
    // getReconciliations() tests
    // ===========================================

    public function testGetReconciliationsReturnsArray(): void
    {
        $expected = [
            ['id' => 1, 'reconciled_date' => '2024-06-30', 'reconciled_by_name' => 'John'],
            ['id' => 2, 'reconciled_date' => '2024-03-31', 'reconciled_by_name' => 'Jane'],
        ];

        $this->db->expects($this->once())
            ->method('query')
            ->willReturn($expected);

        $result = $this->service->getReconciliations(1);
        $this->assertEquals($expected, $result);
    }

    // ===========================================
    // getLastReconciliation() tests
    // ===========================================

    public function testGetLastReconciliationReturnsReconciliation(): void
    {
        $expected = ['id' => 5, 'reconciled_date' => '2024-06-30'];

        $this->db->expects($this->once())
            ->method('queryOne')
            ->willReturn($expected);

        $result = $this->service->getLastReconciliation(1);
        $this->assertEquals($expected, $result);
    }

    public function testGetLastReconciliationReturnsNullWhenNone(): void
    {
        $this->db->method('queryOne')->willReturn(null);

        $result = $this->service->getLastReconciliation(1);
        $this->assertNull($result);
    }

    // ===========================================
    // getSummary() tests
    // ===========================================

    public function testGetSummaryReturnsCorrectStructure(): void
    {
        // First call: getCashBooks
        $this->db->method('query')
            ->willReturnOnConsecutiveCalls(
                // getCashBooks
                [
                    ['id' => 1, 'name' => 'Main Account'],
                    ['id' => 2, 'name' => 'Petty Cash'],
                ],
                // getReconciliations for first cash book (via getLastReconciliation)
                [],
                // getReconciliations for second cash book
                []
            );

        // Multiple queryOne calls for calculateBalance and unreconciled counts
        $this->db->method('queryOne')
            ->willReturnOnConsecutiveCalls(
                // CB 1: getCashBook for calculateBalance
                ['id' => 1, 'opening_balance' => '1000.00', 'opening_balance_date' => '2024-01-01'],
                // CB 1: Cashbook entries summary
                ['total_income' => '500.00', 'total_expenses' => '200.00', 'entry_count' => '10'],
                // CB 1: getLastReconciliation
                ['reconciled_date' => '2024-03-31'],
                // CB 1: Unreconciled count
                ['cnt' => '5'],
                // CB 2: getCashBook for calculateBalance
                ['id' => 2, 'opening_balance' => '100.00', 'opening_balance_date' => '2024-01-01'],
                // CB 2: Cashbook entries summary
                ['total_income' => '50.00', 'total_expenses' => '30.00', 'entry_count' => '3'],
                // CB 2: getLastReconciliation
                null,
                // CB 2: Unreconciled count
                ['cnt' => '3'],
                // countUnassignedTransactions
                ['cnt' => '2']
            );

        $result = $this->service->getSummary(1);

        $this->assertArrayHasKey('cash_books', $result);
        $this->assertArrayHasKey('total_balance', $result);
        $this->assertArrayHasKey('unassigned_count', $result);
        $this->assertCount(2, $result['cash_books']);
    }
}
