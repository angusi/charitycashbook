<?php

declare(strict_types=1);

namespace CharityCashbook\Tests\Unit\Charity;

use CharityCashbook\Charity\AuditService;
use CharityCashbook\Charity\CategoryService;
use CharityCashbook\Charity\DonorService;
use CharityCashbook\Charity\TransactionService;
use CharityCashbook\Core\Exceptions\ValidationException;
use CharityCashbook\Core\Logger;
use CharityCashbook\Tests\Unit\Charity\Support\FakeLedgerDb;
use PHPUnit\Framework\TestCase;

class TransactionServiceTest extends TestCase
{
    private FakeLedgerDb $db;
    private CategoryService $categoryService;
    private DonorService $donorService;
    private TransactionService $service;

    /** Operational categories belonging to charity 1 */
    private array $categories = [
        1 => ['id' => 1, 'name' => 'Donations', 'category_type' => 'receipt', 'giftaid_eligible_default' => 1, 'reporting_category_name' => 'Donations'],
        2 => ['id' => 2, 'name' => 'Ticket sales', 'category_type' => 'receipt', 'giftaid_eligible_default' => 0, 'reporting_category_name' => 'Events'],
        3 => ['id' => 3, 'name' => 'Stationery', 'category_type' => 'payment', 'giftaid_eligible_default' => 0, 'reporting_category_name' => 'Costs'],
        4 => ['id' => 4, 'name' => 'Postage', 'category_type' => 'payment', 'giftaid_eligible_default' => 0, 'reporting_category_name' => 'Costs'],
    ];

    /** Donors belonging to charity 1, with whether they have a declaration */
    private array $donors = [7 => true, 8 => false];

    protected function setUp(): void
    {
        $this->db = new FakeLedgerDb();
        $this->categoryService = $this->createMock(CategoryService::class);
        $this->categoryService->method('getOperationalCategory')
            ->willReturnCallback(fn(int $id, int $charityId) => $charityId === 1 ? ($this->categories[$id] ?? null) : null);

        $this->donorService = $this->createMock(DonorService::class);
        $this->donorService->method('getNameOnly')
            ->willReturnCallback(fn(int $id, int $charityId) => ($charityId === 1 && isset($this->donors[$id])) ? ['id' => $id, 'name' => 'Donor'] : null);
        $this->donorService->method('hasValidDeclaration')
            ->willReturnCallback(fn(int $id, string $date) => $this->donors[$id] ?? false);

        $this->service = new TransactionService(
            $this->db,
            $this->createMock(Logger::class),
            $this->categoryService,
            $this->createMock(AuditService::class),
            $this->donorService
        );
    }

    private function receipt(array $overrides = []): array
    {
        return array_merge([
            'transaction_type' => 'receipt',
            'transaction_date' => '2026-05-01',
            'amount' => '100.00',
            'description' => 'Donation',
            'operational_category_id' => 1,
            'fund_id' => 10,
        ], $overrides);
    }

    private function assertRejected(callable $action, string $messageFragment): void
    {
        try {
            $action();
            $this->fail('Expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($messageFragment, implode(' ', $e->getErrors()));
        }
        $this->assertSame([], $this->db->inserted('transactions'), 'Nothing should be saved');
    }

    // =========================================================================
    // Creating receipts and payments
    // =========================================================================

    public function testSimpleReceiptCreatesOneLineAndOneFundEntry(): void
    {
        $this->service->create(1, 1, $this->receipt());

        $transaction = $this->db->inserted('transactions')[0];
        $this->assertSame('receipt', $transaction['transaction_type']);
        $this->assertSame('100.00', $transaction['amount']);
        $this->assertSame(1, $transaction['charity_id']);

        $this->assertSame(
            [['transaction_id' => 101, 'operational_category_id' => 1, 'fund_id' => 10, 'amount' => '100.00']],
            $this->db->inserted('transaction_category_entries')
        );
        $this->assertSame(
            [['transaction_id' => 101, 'fund_id' => 10, 'amount' => '100.00']],
            $this->db->inserted('transaction_fund_entries')
        );
        $this->assertTrue($this->db->committed);
    }

    public function testPaymentAmountsAreNegative(): void
    {
        $this->service->create(1, 1, $this->receipt([
            'transaction_type' => 'payment',
            'operational_category_id' => 3,
            'cash_book_id' => 20,
        ]));

        $this->assertSame('-100.00', $this->db->inserted('transaction_category_entries')[0]['amount']);
        $this->assertSame('-100.00', $this->db->inserted('transaction_fund_entries')[0]['amount']);
        $this->assertSame('-100.00', $this->db->inserted('transaction_cashbook_entries')[0]['amount']);
        $this->assertSame('not_applicable', $this->db->inserted('transactions')[0]['giftaid_status']);
    }

    public function testSplitLinesRecordEachCategoryAgainstItsFund(): void
    {
        // £100 restricted grant + £50 general donation in one receipt
        $this->service->create(1, 1, $this->receipt([
            'amount' => '150.00',
            'operational_category_id' => null,
            'fund_id' => null,
            'lines' => [
                ['operational_category_id' => 2, 'fund_id' => 11, 'amount' => '100.00'],
                ['operational_category_id' => 1, 'fund_id' => 10, 'amount' => '30.00'],
                ['operational_category_id' => 1, 'fund_id' => 11, 'amount' => '20.00'],
            ],
        ]));

        $lines = $this->db->inserted('transaction_category_entries');
        $this->assertSame([2, 11, '100.00'], [$lines[0]['operational_category_id'], $lines[0]['fund_id'], $lines[0]['amount']]);
        $this->assertSame([1, 10, '30.00'], [$lines[1]['operational_category_id'], $lines[1]['fund_id'], $lines[1]['amount']]);

        // Fund entries are derived from the lines, one per fund
        $funds = $this->db->inserted('transaction_fund_entries');
        $this->assertCount(2, $funds);
        $this->assertSame(['fund_id' => 11, 'amount' => '120.00'], array_intersect_key($funds[0], ['fund_id' => 1, 'amount' => 1]));
        $this->assertSame(['fund_id' => 10, 'amount' => '30.00'], array_intersect_key($funds[1], ['fund_id' => 1, 'amount' => 1]));
    }

    public function testCategorySplitWithSingleFundIsAccepted(): void
    {
        $this->service->create(1, 1, $this->receipt([
            'operational_category_id' => null,
            'category_entries' => [
                ['operational_category_id' => 1, 'amount' => '60.00'],
                ['operational_category_id' => 2, 'amount' => '40.00'],
            ],
        ]));

        $lines = $this->db->inserted('transaction_category_entries');
        $this->assertSame([10, 10], array_column($lines, 'fund_id'));
    }

    public function testFundSplitWithSingleCategoryIsAccepted(): void
    {
        $this->service->create(1, 1, $this->receipt([
            'fund_id' => null,
            'fund_entries' => [
                ['fund_id' => 10, 'amount' => '70.00'],
                ['fund_id' => 11, 'amount' => '30.00'],
            ],
        ]));

        $lines = $this->db->inserted('transaction_category_entries');
        $this->assertSame([10, 11], array_column($lines, 'fund_id'));
        $this->assertSame([1, 1], array_column($lines, 'operational_category_id'));
    }

    public function testSplitAcrossBothFundsAndCategoriesWithoutLinesIsRejected(): void
    {
        $this->assertRejected(fn() => $this->service->create(1, 1, $this->receipt([
            'operational_category_id' => null,
            'fund_id' => null,
            'category_entries' => [
                ['operational_category_id' => 1, 'amount' => '60.00'],
                ['operational_category_id' => 2, 'amount' => '40.00'],
            ],
            'fund_entries' => [
                ['fund_id' => 10, 'amount' => '50.00'],
                ['fund_id' => 11, 'amount' => '50.00'],
            ],
        ])), 'give each line its own category and fund');
    }

    public function testLinesMustAddUpToThePenny(): void
    {
        // Three thirds entered to 2dp come to £99.99, not £100.00
        $this->assertRejected(fn() => $this->service->create(1, 1, $this->receipt([
            'operational_category_id' => null,
            'fund_id' => null,
            'lines' => [
                ['operational_category_id' => 1, 'fund_id' => 10, 'amount' => '33.33'],
                ['operational_category_id' => 1, 'fund_id' => 11, 'amount' => '33.33'],
                ['operational_category_id' => 2, 'fund_id' => 12, 'amount' => '33.33'],
            ],
        ])), 'must add up to £100.00 (currently £99.99)');
    }

    public function testLineAmountsWithMoreThanTwoDecimalPlacesAreRejected(): void
    {
        $this->assertRejected(fn() => $this->service->create(1, 1, $this->receipt([
            'operational_category_id' => null,
            'fund_id' => null,
            'lines' => [
                ['operational_category_id' => 1, 'fund_id' => 10, 'amount' => '33.333'],
                ['operational_category_id' => 1, 'fund_id' => 11, 'amount' => '66.667'],
            ],
        ])), 'pounds and pence');
    }

    /**
     * @dataProvider invalidAmounts
     */
    public function testInvalidAmountsAreRejected(mixed $amount): void
    {
        $this->assertRejected(fn() => $this->service->create(1, 1, $this->receipt(['amount' => $amount])), 'Amount');
    }

    public static function invalidAmounts(): array
    {
        return [['abc'], ['0'], ['0.00'], ['-5.00'], ['10.005'], [''], [null]];
    }

    public function testInvalidDateIsRejected(): void
    {
        $this->assertRejected(fn() => $this->service->create(1, 1, $this->receipt(['transaction_date' => '2026-02-30'])), 'date');
    }

    public function testDescriptionIsRequired(): void
    {
        $this->assertRejected(fn() => $this->service->create(1, 1, $this->receipt(['description' => '  '])), 'Description');
    }

    public function testCategoryIsRequired(): void
    {
        $this->assertRejected(fn() => $this->service->create(1, 1, $this->receipt(['operational_category_id' => null])), 'category');
    }

    public function testFundIsRequired(): void
    {
        $this->assertRejected(fn() => $this->service->create(1, 1, $this->receipt(['fund_id' => null])), 'fund');
    }

    // =========================================================================
    // Tenant isolation
    // =========================================================================

    public function testAnotherCharitysFundIsRejected(): void
    {
        $this->db->funds[2] = [99];
        $this->assertRejected(fn() => $this->service->create(1, 1, $this->receipt(['fund_id' => 99])), 'Invalid fund');
    }

    public function testAnotherCharitysCashBookIsRejected(): void
    {
        $this->assertRejected(fn() => $this->service->create(1, 1, $this->receipt(['cash_book_id' => 99])), 'Invalid cash book');
    }

    public function testAnotherCharitysDonorIsRejected(): void
    {
        $this->assertRejected(fn() => $this->service->create(1, 1, $this->receipt(['donor_id' => 999])), 'Donor not found');
    }

    public function testAnotherCharitysCategoryIsRejected(): void
    {
        $this->assertRejected(fn() => $this->service->create(1, 1, $this->receipt(['operational_category_id' => 999])), 'Invalid category');
    }

    public function testAnotherCharitysFundInASplitLineIsRejected(): void
    {
        $this->assertRejected(fn() => $this->service->create(1, 1, $this->receipt([
            'operational_category_id' => null,
            'fund_id' => null,
            'lines' => [
                ['operational_category_id' => 1, 'fund_id' => 10, 'amount' => '50.00'],
                ['operational_category_id' => 1, 'fund_id' => 99, 'amount' => '50.00'],
            ],
        ])), 'Invalid fund');
    }

    // =========================================================================
    // Type consistency
    // =========================================================================

    public function testReceiptWithPaymentCategoryIsRejected(): void
    {
        $this->assertRejected(
            fn() => $this->service->create(1, 1, $this->receipt(['operational_category_id' => 3])),
            'categories chosen are for payments'
        );
    }

    public function testMixedCategoryTypesAreRejected(): void
    {
        $this->assertRejected(fn() => $this->service->create(1, 1, $this->receipt([
            'transaction_type' => null,
            'operational_category_id' => null,
            'category_entries' => [
                ['operational_category_id' => 1, 'amount' => '50.00'],
                ['operational_category_id' => 3, 'amount' => '50.00'],
            ],
        ])), 'All categories must be the same type');
    }

    public function testTypeIsTakenFromCategoriesWhenNotGiven(): void
    {
        $this->service->create(1, 1, $this->receipt(['transaction_type' => null, 'operational_category_id' => 3]));

        $this->assertSame('payment', $this->db->inserted('transactions')[0]['transaction_type']);
    }

    // =========================================================================
    // Cash books
    // =========================================================================

    public function testCashbookEntriesMustAddUpToTheAmount(): void
    {
        $this->assertRejected(fn() => $this->service->create(1, 1, $this->receipt([
            'cashbook_entries' => [
                ['cash_book_id' => 20, 'amount' => '60.00'],
                ['cash_book_id' => 21, 'amount' => '30.00'],
            ],
        ])), 'Cash book entries must add up to £100.00');
    }

    public function testCashbookSplitIsSaved(): void
    {
        $this->service->create(1, 1, $this->receipt([
            'cashbook_entries' => [
                ['cash_book_id' => 20, 'amount' => '60.00'],
                ['cash_book_id' => 21, 'amount' => '40.00'],
            ],
        ]));

        $this->assertSame(['60.00', '40.00'], array_column($this->db->inserted('transaction_cashbook_entries'), 'amount'));
    }

    // =========================================================================
    // Gift Aid
    // =========================================================================

    public function testDonorWithDeclarationIsEligibleForFullAmount(): void
    {
        $this->service->create(1, 1, $this->receipt(['donor_id' => 7]));

        $row = $this->db->inserted('transactions')[0];
        $this->assertSame('eligible', $row['giftaid_status']);
        $this->assertSame('100.00', $row['giftaid_amount']);
    }

    public function testDonorWithoutDeclarationIsNotEligible(): void
    {
        $this->service->create(1, 1, $this->receipt(['donor_id' => 8]));

        $this->assertSame('not_eligible', $this->db->inserted('transactions')[0]['giftaid_status']);
    }

    public function testAnonymousDonationInEligibleCategoryIsNotEligible(): void
    {
        $this->service->create(1, 1, $this->receipt());

        $this->assertSame('not_eligible', $this->db->inserted('transactions')[0]['giftaid_status']);
    }

    public function testAnonymousReceiptInOrdinaryCategoryIsNotApplicable(): void
    {
        $this->service->create(1, 1, $this->receipt(['operational_category_id' => 2]));

        $this->assertSame('not_applicable', $this->db->inserted('transactions')[0]['giftaid_status']);
    }

    public function testOneOffDeclarationIsEligible(): void
    {
        $this->service->create(1, 1, $this->receipt(['has_oneoff_declaration' => true]));

        $this->assertSame('eligible', $this->db->inserted('transactions')[0]['giftaid_status']);
    }

    public function testExcludedOverride(): void
    {
        $this->service->create(1, 1, $this->receipt(['donor_id' => 7, 'giftaid_eligibility_override' => 'not_eligible']));

        $row = $this->db->inserted('transactions')[0];
        $this->assertSame('excluded', $row['giftaid_status']);
        $this->assertNull($row['giftaid_amount']);
    }

    public function testBeforeCharityGiftAidDateIsNotApplicable(): void
    {
        $this->db->charity['giftaid_eligible_from_date'] = '2027-01-01';
        $this->service->create(1, 1, $this->receipt(['donor_id' => 7]));

        $this->assertSame('not_applicable', $this->db->inserted('transactions')[0]['giftaid_status']);
    }

    public function testGasdsDonationOfThirtyPoundsIsAccepted(): void
    {
        $this->service->create(1, 1, $this->receipt(['amount' => '30.00', 'is_gasds' => true]));

        $this->assertSame('gasds', $this->db->inserted('transactions')[0]['giftaid_status']);
    }

    public function testGasdsDonationOverThirtyPoundsIsRejected(): void
    {
        $this->assertRejected(
            fn() => $this->service->create(1, 1, $this->receipt(['amount' => '30.01', 'is_gasds' => true])),
            '£30.00 or less'
        );
    }

    public function testPartialGiftAidAmountCannotExceedDonation(): void
    {
        $this->assertRejected(
            fn() => $this->service->create(1, 1, $this->receipt(['donor_id' => 7, 'giftaid_amount' => '100.01'])),
            'cannot exceed'
        );
    }

    // =========================================================================
    // Locks
    // =========================================================================

    public function testCannotCreateInAClosedYear(): void
    {
        $this->db->charity['books_locked_until'] = '2026-03-31';
        $this->assertRejected(
            fn() => $this->service->create(1, 1, $this->receipt(['transaction_date' => '2026-03-31'])),
            'books are closed up to 2026-03-31'
        );
    }

    public function testCanCreateAfterTheClosedYear(): void
    {
        $this->db->charity['books_locked_until'] = '2026-03-31';
        $this->service->create(1, 1, $this->receipt(['transaction_date' => '2026-04-01']));

        $this->assertCount(1, $this->db->inserted('transactions'));
    }

    public function testFailureRollsBack(): void
    {
        $this->db->failOnInsert = new \RuntimeException('boom');

        try {
            $this->service->create(1, 1, $this->receipt());
            $this->fail('Expected exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }
        $this->assertTrue($this->db->rolledBack);
        $this->assertFalse($this->db->committed);
    }

    // =========================================================================
    // Transfers
    // =========================================================================

    private function transfer(array $overrides = []): array
    {
        return array_merge([
            'transaction_type' => 'transfer',
            'transaction_date' => '2026-05-01',
            'amount' => '250.00',
            'description' => 'Move to restricted',
            'fund_entries' => [
                ['fund_id' => 10, 'amount' => '-250.00'],
                ['fund_id' => 11, 'amount' => '250.00'],
            ],
        ], $overrides);
    }

    public function testBalancedFundTransfer(): void
    {
        $this->service->create(1, 1, $this->transfer());

        $row = $this->db->inserted('transactions')[0];
        $this->assertSame('transfer', $row['transaction_type']);
        $this->assertSame('not_applicable', $row['giftaid_status']);
        $this->assertSame(['-250.00', '250.00'], array_column($this->db->inserted('transaction_fund_entries'), 'amount'));
        $this->assertSame([], $this->db->inserted('transaction_category_entries'));
    }

    public function testUnbalancedTransferIsRejected(): void
    {
        $this->assertRejected(fn() => $this->service->create(1, 1, $this->transfer([
            'fund_entries' => [['fund_id' => 10, 'amount' => '-250.00'], ['fund_id' => 11, 'amount' => '200.00']],
        ])), 'must balance to zero');
    }

    public function testTransferMustMoveTheTransferAmount(): void
    {
        $this->assertRejected(fn() => $this->service->create(1, 1, $this->transfer([
            'fund_entries' => [['fund_id' => 10, 'amount' => '-100.00'], ['fund_id' => 11, 'amount' => '100.00']],
        ])), 'transfer amount is £250.00');
    }

    public function testTransferNeedsTwoDifferentFundsOrCashBooks(): void
    {
        $this->assertRejected(fn() => $this->service->create(1, 1, $this->transfer([
            'fund_entries' => [['fund_id' => 10, 'amount' => '-250.00'], ['fund_id' => 10, 'amount' => '250.00']],
        ])), 'at least 2 different funds or 2 different cashbooks');
    }

    public function testTransferWithCategoriesIsRejected(): void
    {
        $this->assertRejected(
            fn() => $this->service->create(1, 1, $this->transfer(['operational_category_id' => 1])),
            'should not have categories'
        );
    }

    public function testCashbookOnlyTransfer(): void
    {
        $this->service->create(1, 1, $this->transfer([
            'fund_entries' => null,
            'cashbook_entries' => [['cash_book_id' => 20, 'amount' => '-250.00'], ['cash_book_id' => 21, 'amount' => '250.00']],
        ]));

        $this->assertSame([], $this->db->inserted('transaction_fund_entries'));
        $this->assertCount(2, $this->db->inserted('transaction_cashbook_entries'));
    }

    public function testTransferToAnotherCharitysFundIsRejected(): void
    {
        $this->assertRejected(fn() => $this->service->create(1, 1, $this->transfer([
            'fund_entries' => [['fund_id' => 10, 'amount' => '-250.00'], ['fund_id' => 99, 'amount' => '250.00']],
        ])), 'Invalid fund');
    }

    // =========================================================================
    // Updating
    // =========================================================================

    public function testUpdateNonexistentTransactionFails(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->update(5, 1, 1, $this->receipt());
    }

    public function testUpdateAnotherCharitysTransactionFails(): void
    {
        $this->db->withTransaction(['charity_id' => 2]);

        $this->expectException(ValidationException::class);
        $this->service->update(5, 1, 1, $this->receipt());
    }

    public function testUpdateReplacesLinesAndClearsReviewFlag(): void
    {
        $this->db->withTransaction(['allocation_needs_review' => 1]);

        $this->service->update(5, 1, 1, $this->receipt(['amount' => '120.00', 'fund_id' => 11]));

        $update = $this->db->updates[0];
        $this->assertSame('120.00', $update['data']['amount']);
        $this->assertFalse($update['data']['allocation_needs_review']);
        $this->assertSame(11, $this->db->inserted('transaction_category_entries')[0]['fund_id']);
    }

    public function testClaimedTransactionAmountCannotChange(): void
    {
        $this->db->withTransaction(['giftaid_status' => 'claimed', 'giftaid_claim_id' => 3, 'donor_id' => 7, 'giftaid_amount' => '100.00']);

        $this->expectException(ValidationException::class);
        try {
            $this->service->update(5, 1, 1, $this->receipt(['amount' => '90.00', 'donor_id' => 7]));
        } catch (ValidationException $e) {
            $this->assertStringContainsString('amount', implode(' ', $e->getErrors()));
            $this->assertStringContainsString('Gift Aid correction', implode(' ', $e->getErrors()));
            $this->assertSame([], $this->db->updates);
            throw $e;
        }
    }

    public function testClaimedTransactionDescriptionCanChange(): void
    {
        $this->db->withTransaction(['giftaid_status' => 'claimed', 'giftaid_claim_id' => 3]);

        $this->service->update(5, 1, 1, ['description' => 'Corrected description', 'reference' => 'REF1']);

        $this->assertSame(['description' => 'Corrected description', 'reference' => 'REF1', 'updated_by_user_id' => 1], $this->db->updates[0]['data']);
    }

    public function testClaimedTransactionFullFormWithSameValuesKeepsGiftAid(): void
    {
        $this->db->withTransaction(['giftaid_status' => 'claimed', 'giftaid_claim_id' => 3, 'donor_id' => 7, 'giftaid_amount' => '100.00']);

        $this->service->update(5, 1, 1, $this->receipt(['donor_id' => 7, 'description' => 'New text']));

        $data = $this->db->updates[0]['data'];
        $this->assertSame('claimed', $data['giftaid_status']);
        $this->assertSame('100.00', $data['giftaid_amount']);
        $this->assertSame('New text', $data['description']);
    }

    public function testDraftClaimTransactionAmountCannotChange(): void
    {
        $this->db->withTransaction(['giftaid_status' => 'eligible', 'giftaid_claim_id' => 3, 'donor_id' => 7, 'giftaid_amount' => '100.00']);

        $this->expectException(ValidationException::class);
        try {
            $this->service->update(5, 1, 1, $this->receipt(['amount' => '90.00', 'donor_id' => 7]));
        } catch (ValidationException $e) {
            $this->assertStringContainsString('draft Gift Aid claim #3', implode(' ', $e->getErrors()));
            throw $e;
        }
    }

    public function testReconciledTransactionAmountCannotChange(): void
    {
        $this->db->withTransaction([], null, [
            ['cash_book_id' => 20, 'amount' => '100.00', 'is_reconciled' => 1, 'reconciled_at' => '2026-06-01 10:00:00'],
        ]);

        $this->expectException(ValidationException::class);
        try {
            $this->service->update(5, 1, 1, $this->receipt(['amount' => '90.00', 'cash_book_id' => 20]));
        } catch (ValidationException $e) {
            $this->assertStringContainsString('reconciled', implode(' ', $e->getErrors()));
            throw $e;
        }
    }

    public function testReconciledTransactionCanChangeCategoryAndKeepsReconciliation(): void
    {
        $this->db->withTransaction([], null, [
            ['cash_book_id' => 20, 'amount' => '100.00', 'is_reconciled' => 1, 'reconciled_at' => '2026-06-01 10:00:00'],
        ]);

        $this->service->update(5, 1, 1, $this->receipt(['operational_category_id' => 2, 'cash_book_id' => 20]));

        $entry = $this->db->inserted('transaction_cashbook_entries')[0];
        $this->assertTrue($entry['is_reconciled']);
        $this->assertSame('2026-06-01 10:00:00', $entry['reconciled_at']);
    }

    public function testCannotEditTransactionInClosedYear(): void
    {
        $this->db->charity['books_locked_until'] = '2026-12-31';
        $this->db->withTransaction();

        $this->expectException(ValidationException::class);
        $this->service->update(5, 1, 1, $this->receipt(['description' => 'Changed']));
    }

    public function testCannotMoveTransactionIntoClosedYear(): void
    {
        $this->db->charity['books_locked_until'] = '2026-03-31';
        $this->db->withTransaction();

        $this->expectException(ValidationException::class);
        $this->service->update(5, 1, 1, $this->receipt(['transaction_date' => '2026-03-01']));
    }

    // =========================================================================
    // Deleting
    // =========================================================================

    public function testDeleteSoftDeletes(): void
    {
        $this->db->withTransaction();

        $this->service->delete(5, 1, 1);

        $this->assertStringContainsString('SET is_deleted = TRUE', $this->db->executes[0]['sql']);
        $this->assertSame([1, 5, 1], $this->db->executes[0]['params']);
    }

    /**
     * @dataProvider undeletableTransactions
     */
    public function testLockedTransactionsCannotBeDeleted(array $overrides, array $cashbook, ?string $lockedUntil, string $message): void
    {
        $this->db->charity['books_locked_until'] = $lockedUntil;
        $this->db->withTransaction($overrides, null, $cashbook);

        try {
            $this->service->delete(5, 1, 1);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($message, implode(' ', $e->getErrors()));
        }
        $this->assertSame([], $this->db->executes);
    }

    public static function undeletableTransactions(): array
    {
        return [
            'claimed' => [['giftaid_status' => 'claimed', 'giftaid_claim_id' => 3], [], null, 'claimed for Gift Aid'],
            'in draft claim' => [['giftaid_status' => 'eligible', 'giftaid_claim_id' => 3], [], null, 'draft Gift Aid claim #3'],
            'reconciled' => [[], [['cash_book_id' => 20, 'amount' => '100.00', 'is_reconciled' => 1, 'reconciled_at' => '2026-06-01']], null, 'reconciled'],
            'closed year' => [[], [], '2026-12-31', 'books are closed'],
            'already deleted' => [['is_deleted' => 1], [], null, 'not found'],
        ];
    }

    // =========================================================================
    // Lists and totals
    // =========================================================================

    public function testListCountAndTotalsUseTheSameFilters(): void
    {
        $filters = [
            'date_from' => '2026-04-01',
            'date_to' => '2027-03-31',
            'cash_book_id' => 20,
            'giftaid_status' => 'eligible',
            'type' => 'receipt',
        ];

        $this->service->getTransactions(1, $filters);
        $this->service->countTransactions(1, $filters);
        $this->service->getTotals(1, $filters);

        [$list, $count, $totals] = array_slice($this->db->queries, -3);
        foreach ([$list, $count, $totals] as $query) {
            $this->assertStringContainsString('tcbe.cash_book_id = ?', $query['sql']);
            $this->assertStringContainsString('t.giftaid_status = ?', $query['sql']);
            // Transfers have no category; counting must not join categories
            $this->assertStringNotContainsString('JOIN operational_categories oc ON oc.id = t.operational_category_id', $query['sql']);
        }
        $this->assertSame([1, '2026-04-01', '2027-03-31', 20, 'receipt', 'eligible'], $count['params']);
    }

    public function testFundFilterTotalsOnlyCountThatFundsShare(): void
    {
        $this->service->getTotals(1, ['fund_id' => 11]);

        $query = end($this->db->queries);
        $this->assertStringContainsString('SUM((SELECT COALESCE(SUM(tfx.amount), 0) FROM transaction_fund_entries tfx WHERE tfx.transaction_id = t.id AND tfx.fund_id = ?))', $query['sql']);
        $this->assertSame([11, 1, 11], $query['params']);
    }

    public function testTotalsByCategoryFiltersDatesBeforeAggregating(): void
    {
        $this->service->getTotalsByCategory(1, '2026-04-01', '2026-11-25');

        $query = end($this->db->queries);
        // The date and deleted filters are in the aggregating subquery's WHERE, not a LEFT JOIN's ON
        $this->assertMatchesRegularExpression(
            '/WHERE t\.charity_id = \? AND t\.is_deleted = FALSE\s+AND t\.transaction_date >= \? AND t\.transaction_date <= \?/',
            $query['sql']
        );
        $this->assertSame([1, '2026-04-01', '2026-11-25', 1], $query['params']);
    }
}
