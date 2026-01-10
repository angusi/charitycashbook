<?php

declare(strict_types=1);

namespace Tests\Unit\Charity;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use CharityCashbook\Charity\FundService;
use CharityCashbook\Core\DatabaseInterface;

class FundServiceTest extends TestCase
{
    private FundService $fundService;
    private MockObject&DatabaseInterface $db;

    protected function setUp(): void
    {
        $this->db = $this->createMock(DatabaseInterface::class);
        $this->fundService = new FundService($this->db);
    }

    // ========================================
    // getAll tests
    // ========================================

    public function testGetAllReturnsEmptyArrayWhenNoFunds(): void
    {
        $this->db->method('query')
            ->willReturn([]);

        $result = $this->fundService->getAll(1);
        
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testGetAllReturnsFunds(): void
    {
        $funds = [
            ['id' => 1, 'name' => 'General Fund', 'fund_type' => 'unrestricted', 'is_active' => true],
            ['id' => 2, 'name' => 'Building Fund', 'fund_type' => 'restricted', 'is_active' => true],
        ];
        
        $this->db->method('query')
            ->willReturn($funds);

        $result = $this->fundService->getAll(1);
        
        $this->assertCount(2, $result);
        $this->assertEquals('General Fund', $result[0]['name']);
    }

    public function testGetAllActiveOnlyByDefault(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with($this->stringContains('is_active = TRUE'), [1])
            ->willReturn([]);

        $this->fundService->getAll(1);
    }

    public function testGetAllIncludesInactiveWhenRequested(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->logicalNot($this->stringContains('is_active = TRUE')),
                [1]
            )
            ->willReturn([]);

        $this->fundService->getAll(1, false);
    }

    // ========================================
    // get tests
    // ========================================

    public function testGetReturnsNullWhenNotFound(): void
    {
        $this->db->method('queryOne')
            ->willReturn(null);

        $result = $this->fundService->get(999, 1);
        
        $this->assertNull($result);
    }

    public function testGetReturnsFund(): void
    {
        $fund = ['id' => 1, 'name' => 'General Fund', 'fund_type' => 'unrestricted'];
        
        $this->db->method('queryOne')
            ->willReturn($fund);

        $result = $this->fundService->get(1, 1);
        
        $this->assertIsArray($result);
        $this->assertEquals('General Fund', $result['name']);
    }

    public function testGetQueryIncludesCharityId(): void
    {
        $this->db->expects($this->once())
            ->method('queryOne')
            ->with($this->stringContains('charity_id'), [5, 42])
            ->willReturn(null);

        $this->fundService->get(5, 42);
    }

    // ========================================
    // create tests
    // ========================================

    public function testCreateReturnsId(): void
    {
        $this->db->method('insert')
            ->willReturn(5);

        $result = $this->fundService->create(1, [
            'name' => 'New Fund',
            'fund_type' => 'restricted',
        ]);
        
        $this->assertEquals(5, $result);
    }

    public function testCreateUsesDefaultValues(): void
    {
        $this->db->expects($this->once())
            ->method('insert')
            ->with('funds', $this->callback(function ($data) {
                return $data['opening_balance'] === 0
                    && $data['opening_balance_date'] === null
                    && $data['display_order'] === 0
                    && $data['is_active'] === true;
            }))
            ->willReturn(1);

        $this->fundService->create(1, [
            'name' => 'Test Fund',
            'fund_type' => 'unrestricted',
        ]);
    }

    public function testCreateUsesProvidedValues(): void
    {
        $this->db->expects($this->once())
            ->method('insert')
            ->with('funds', $this->callback(function ($data) {
                return $data['charity_id'] === 42
                    && $data['name'] === 'Restricted Fund'
                    && $data['fund_type'] === 'restricted'
                    && $data['opening_balance'] === 1000.50
                    && $data['opening_balance_date'] === '2024-01-01'
                    && $data['display_order'] === 5;
            }))
            ->willReturn(1);

        $this->fundService->create(42, [
            'name' => 'Restricted Fund',
            'fund_type' => 'restricted',
            'opening_balance' => 1000.50,
            'opening_balance_date' => '2024-01-01',
            'display_order' => 5,
        ]);
    }

    // ========================================
    // update tests
    // ========================================

    public function testUpdateCallsDbUpdate(): void
    {
        $this->db->expects($this->once())
            ->method('update')
            ->with(
                'funds',
                ['name' => 'Updated Name'],
                'id = ? AND charity_id = ?',
                [5, 42]
            );

        $this->fundService->update(5, 42, ['name' => 'Updated Name']);
    }

    public function testUpdateMultipleFields(): void
    {
        $data = [
            'name' => 'New Name',
            'fund_type' => 'restricted',
            'display_order' => 10,
        ];

        $this->db->expects($this->once())
            ->method('update')
            ->with('funds', $data, 'id = ? AND charity_id = ?', [1, 1]);

        $this->fundService->update(1, 1, $data);
    }

    // ========================================
    // deactivate tests
    // ========================================

    public function testDeactivateSetsIsActiveFalse(): void
    {
        $this->db->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains('is_active = FALSE'),
                [5, 42]
            );

        $this->fundService->deactivate(5, 42);
    }

    // ========================================
    // getBalance tests
    // ========================================

    public function testGetBalanceReturnsZerosWhenFundNotFound(): void
    {
        $this->db->method('queryOne')
            ->willReturn(null);

        $result = $this->fundService->getBalance(999, 1);

        $this->assertEquals([
            'opening' => 0,
            'receipts' => 0,
            'payments' => 0,
            'transfers_in' => 0,
            'transfers_out' => 0,
            'balance' => 0,
        ], $result);
    }

    public function testGetBalanceCalculatesCorrectly(): void
    {
        $fund = ['id' => 1, 'opening_balance' => 1000.00];
        $totals = ['receipts' => 500.00, 'payments' => 200.00];

        $this->db->method('queryOne')
            ->willReturnOnConsecutiveCalls($fund, $totals);

        $result = $this->fundService->getBalance(1, 1);

        $this->assertEquals(1000.00, $result['opening']);
        $this->assertEquals(500.00, $result['receipts']);
        $this->assertEquals(200.00, $result['payments']);
        $this->assertEquals(1300.00, $result['balance']); // 1000 + 500 - 200
    }

    public function testGetBalanceWithAsOfDate(): void
    {
        $fund = ['id' => 1, 'opening_balance' => 500.00];
        $totals = ['receipts' => 100.00, 'payments' => 50.00];

        $this->db->expects($this->exactly(2))
            ->method('queryOne')
            ->willReturnCallback(function ($sql, $params) use ($fund, $totals) {
                if (str_contains($sql, 'funds')) {
                    return $fund;
                }
                // Verify date parameter is passed
                $this->assertContains('2024-06-30', $params);
                return $totals;
            });

        $result = $this->fundService->getBalance(1, 1, '2024-06-30');

        $this->assertEquals(550.00, $result['balance']); // 500 + 100 - 50
    }

    public function testGetBalanceWithNegativeBalance(): void
    {
        $fund = ['id' => 1, 'opening_balance' => 100.00];
        $totals = ['receipts' => 50.00, 'payments' => 300.00];

        $this->db->method('queryOne')
            ->willReturnOnConsecutiveCalls($fund, $totals);

        $result = $this->fundService->getBalance(1, 1);

        $this->assertEquals(-150.00, $result['balance']); // 100 + 50 - 300
    }

    public function testGetBalanceWithZeroOpeningBalance(): void
    {
        $fund = ['id' => 1, 'opening_balance' => 0];
        $totals = ['receipts' => 1000.00, 'payments' => 400.00];

        $this->db->method('queryOne')
            ->willReturnOnConsecutiveCalls($fund, $totals);

        $result = $this->fundService->getBalance(1, 1);

        $this->assertEquals(0, $result['opening']);
        $this->assertEquals(600.00, $result['balance']); // 0 + 1000 - 400
    }

    // ========================================
    // getAllBalances tests
    // ========================================

    public function testGetAllBalancesReturnsEmptyWhenNoFunds(): void
    {
        $this->db->method('query')->willReturn([]);

        $result = $this->fundService->getAllBalances(1);

        $this->assertEquals([], $result);
    }

    public function testGetAllBalancesIncludesBalanceForEachFund(): void
    {
        $funds = [
            ['id' => 1, 'name' => 'Fund A', 'opening_balance' => 100.00],
            ['id' => 2, 'name' => 'Fund B', 'opening_balance' => 200.00],
        ];

        $this->db->method('query')->willReturn($funds);

        $this->db->method('queryOne')
            ->willReturnCallback(function ($sql, $params) {
                if (str_contains($sql, 'funds')) {
                    $fundId = $params[0];
                    return ['id' => $fundId, 'opening_balance' => $fundId * 100];
                }
                return ['receipts' => 50.00, 'payments' => 25.00];
            });

        $result = $this->fundService->getAllBalances(1);

        $this->assertCount(2, $result);
        $this->assertArrayHasKey('balance', $result[0]);
        $this->assertArrayHasKey('receipts', $result[0]);
        $this->assertArrayHasKey('payments', $result[0]);
    }

    public function testGetAllBalancesPassesAsOfDate(): void
    {
        $funds = [['id' => 1, 'name' => 'Fund', 'opening_balance' => 100.00]];

        $this->db->method('query')->willReturn($funds);

        // Track whether asOfDate was passed through
        $asOfDatePassed = false;
        $this->db->method('queryOne')
            ->willReturnCallback(function ($sql, $params) use (&$asOfDatePassed) {
                if (str_contains($sql, 'funds')) {
                    return ['id' => 1, 'opening_balance' => 100.00];
                }
                if (in_array('2024-12-31', $params, true)) {
                    $asOfDatePassed = true;
                }
                return ['receipts' => 0, 'payments' => 0];
            });

        $this->fundService->getAllBalances(1, '2024-12-31');

        $this->assertTrue($asOfDatePassed);
    }
}
