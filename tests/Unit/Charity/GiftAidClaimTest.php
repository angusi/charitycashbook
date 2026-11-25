<?php

declare(strict_types=1);

namespace CharityCashbook\Tests\Unit\Charity;

use CharityCashbook\Charity\AuditService;
use CharityCashbook\Charity\GiftAidService;
use CharityCashbook\Core\DatabaseInterface;
use CharityCashbook\Core\Exceptions\ValidationException;
use CharityCashbook\Core\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Claim rules: totals use the confirmed Gift Aid amount, items are re-checked on submit,
 * GASDS limits apply per tax year, and corrections flow into the next claim.
 */
class GiftAidClaimTest extends TestCase
{
    private GiftAidService $service;

    /** Transactions by id */
    private array $transactions = [];
    /** Donations in other claims for the tax year: ['standard' => pounds, 'gasds' => pounds] */
    private array $otherClaims = ['standard' => 0, 'gasds' => 0];
    private ?array $claim = null;
    private array $claimItemIds = [];
    private array $appliedCorrections = ['underclaimed' => '0.00', 'overclaimed' => '0.00'];
    private int $pendingCorrections = 0;
    private string $appliedAdjustments = '0.00';

    private array $inserts = [];
    private array $updates = [];
    private array $executes = [];
    private bool $committed = false;

    protected function setUp(): void
    {
        $db = $this->createMock(DatabaseInterface::class);

        $db->method('query')->willReturnCallback(function (string $sql, array $params) {
            if (str_contains($sql, 'FROM transactions') && str_contains($sql, 'WHERE id IN')) {
                $ids = array_slice($params, 0, -1);
                return array_values(array_filter($this->transactions, fn($t) => in_array($t['id'], $ids, true)));
            }
            if (str_contains($sql, 'FROM giftaid_claim_items gci') && str_contains($sql, 'GROUP BY scheme')) {
                return [
                    ['scheme' => 'eligible', 'total' => (string) $this->otherClaims['standard']],
                    ['scheme' => 'gasds', 'total' => (string) $this->otherClaims['gasds']],
                ];
            }
            if (str_contains($sql, 'SELECT transaction_id FROM giftaid_claim_items')) {
                return array_map(fn($id) => ['transaction_id' => $id], $this->claimItemIds);
            }
            return [];
        });

        $db->method('queryOne')->willReturnCallback(function (string $sql, array $params) {
            if (str_contains($sql, 'FROM giftaid_claims gc')) {
                return $this->claim;
            }
            if (str_contains($sql, 'FROM giftaid_corrections WHERE applied_to_claim_id')) {
                return $this->appliedCorrections;
            }
            if (str_contains($sql, 'JOIN giftaid_claims gc ON gc.id = t.giftaid_claim_id')) {
                return ['id' => 5, 'amount' => '100.00', 'giftaid_amount' => '100.00', 'claim_id' => 3];
            }
            return null;
        });

        $db->method('queryValue')->willReturnCallback(function (string $sql) {
            if (str_contains($sql, 'applied_to_claim_id IS NULL')) {
                return $this->pendingCorrections;
            }
            if (str_contains($sql, 'SUM(adjustment)')) {
                return $this->appliedAdjustments;
            }
            return 0;
        });

        $db->method('insert')->willReturnCallback(function (string $table, array $data) {
            $this->inserts[] = [$table, $data];
            return 50;
        });
        $db->method('update')->willReturnCallback(function (string $table, array $data) {
            $this->updates[] = [$table, $data];
            return 1;
        });
        $db->method('execute')->willReturnCallback(function (string $sql, array $params = []) {
            $this->executes[] = [$sql, $params];
            return 1;
        });
        $db->method('commit')->willReturnCallback(function () {
            $this->committed = true;
        });

        $this->service = new GiftAidService($db, $this->createMock(Logger::class), $this->createMock(AuditService::class));
    }

    private function tx(int $id, string $amount, ?string $giftaidAmount, string $status = 'eligible', array $extra = []): array
    {
        return $this->transactions[$id] = array_merge([
            'id' => $id,
            'amount' => $amount,
            'giftaid_amount' => $giftaidAmount,
            'giftaid_status' => $status,
            'giftaid_claim_id' => null,
            'transaction_date' => '2026-06-01',
            'is_deleted' => 0,
            'description' => "Donation $id",
        ], $extra);
    }

    private function claimTotalsWritten(): array
    {
        $writes = array_values(array_filter($this->updates, fn($u) => $u[0] === 'giftaid_claims'));
        return end($writes)[1];
    }

    private function assertRejected(callable $action, string $fragment): void
    {
        try {
            $action();
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($fragment, implode(' ', $e->getErrors()));
        }
        $this->assertFalse($this->committed);
    }

    public function testClaimTotalsUseTheConfirmedGiftAidAmount(): void
    {
        $this->tx(1, '100.00', '60.00');      // only £60 of £100 qualifies
        $this->tx(2, '20.00', '20.00', 'gasds');

        $this->service->createClaim(1, 1, '2026-04-06', '2026-06-30', [1, 2]);

        $totals = $this->claimTotalsWritten();
        $this->assertSame('60.00', $totals['total_donations']);
        $this->assertSame('15.00', $totals['total_giftaid']);
        $this->assertSame('20.00', $totals['gasds_donations']);
        $this->assertSame('5.00', $totals['gasds_giftaid']);
        $this->assertTrue($this->committed);
    }

    public function testClaimItemsRecordTheirOriginalStatus(): void
    {
        $this->tx(1, '100.00', '100.00');
        $this->tx(2, '20.00', '20.00', 'gasds');

        $this->service->createClaim(1, 1, '2026-04-06', '2026-06-30', [1, 2]);

        $items = array_values(array_filter($this->inserts, fn($i) => $i[0] === 'giftaid_claim_items'));
        $this->assertSame(['eligible', 'gasds'], array_map(fn($i) => $i[1]['original_status'], $items));
    }

    public function testUpdatingADraftClaimAlsoUsesTheConfirmedAmount(): void
    {
        $this->claim = ['id' => 3, 'status' => 'draft'];
        $this->tx(1, '100.00', '60.00', 'eligible', ['giftaid_claim_id' => 3]);

        $this->service->updateClaim(3, 1, 1, '2026-04-06', '2026-06-30', [1]);

        $this->assertSame('60.00', $this->claimTotalsWritten()['total_donations']);
    }

    /**
     * @dataProvider unclaimableTransactions
     */
    public function testUnclaimableTransactionsAreRejected(array $extra, ?string $giftaidAmount, string $status, string $message): void
    {
        $this->tx(1, '100.00', $giftaidAmount, $status, $extra);

        $this->assertRejected(fn() => $this->service->createClaim(1, 1, '2026-04-06', '2026-06-30', [1]), $message);
    }

    public static function unclaimableTransactions(): array
    {
        return [
            'deleted' => [['is_deleted' => 1], '100.00', 'eligible', 'has been deleted'],
            'not eligible' => [[], '100.00', 'not_eligible', 'no longer eligible'],
            'amount not confirmed' => [[], null, 'eligible', 'needs its Gift Aid amount confirming'],
            'in another claim' => [['giftaid_claim_id' => 9], '100.00', 'eligible', 'already in claim #9'],
        ];
    }

    public function testUnknownTransactionIsRejected(): void
    {
        $this->assertRejected(fn() => $this->service->createClaim(1, 1, '2026-04-06', '2026-06-30', [404]), '#404 was not found');
    }

    public function testSubmitRechecksItemsChangedSinceDrafting(): void
    {
        $this->claim = ['id' => 3, 'status' => 'draft'];
        $this->claimItemIds = [1];
        // The donor's declaration was withdrawn after the claim was drafted
        $this->tx(1, '100.00', '100.00', 'not_eligible', ['giftaid_claim_id' => 3]);

        $this->assertRejected(fn() => $this->service->submitClaim(3, 1, 1), 'no longer eligible');
        $this->assertSame([], array_filter($this->executes, fn($e) => str_contains($e[0], "giftaid_status = 'claimed'")));
    }

    public function testSubmitRecalculatesTotals(): void
    {
        $this->claim = ['id' => 3, 'status' => 'draft'];
        $this->claimItemIds = [1, 2];
        $this->tx(1, '100.00', '80.00', 'eligible', ['giftaid_claim_id' => 3]);
        $this->tx(2, '50.00', '50.00', 'eligible', ['giftaid_claim_id' => 3]);

        $this->service->submitClaim(3, 1, 1, 'HMRC-REF');

        $this->assertSame('130.00', $this->claimTotalsWritten()['total_donations']);
        $this->assertNotEmpty(array_filter($this->executes, fn($e) => str_contains($e[0], "giftaid_status = 'claimed'")));
    }

    public function testGasdsCannotGoOverEightThousandPoundsInATaxYear(): void
    {
        $this->otherClaims = ['standard' => 5000, 'gasds' => 7990];
        $this->tx(1, '25.00', '25.00', 'gasds');

        $this->assertRejected(
            fn() => $this->service->createClaim(1, 1, '2026-04-06', '2026-06-30', [1]),
            'over the £8000.00 limit'
        );
    }

    public function testGasdsCannotExceedTenTimesGiftAidDonations(): void
    {
        $this->otherClaims = ['standard' => 10, 'gasds' => 90];
        $this->tx(1, '20.00', '20.00', 'gasds');

        $this->assertRejected(
            fn() => $this->service->createClaim(1, 1, '2026-04-06', '2026-06-30', [1]),
            'cannot be more than 10 times'
        );
    }

    public function testGasdsWithinLimitsIsAccepted(): void
    {
        $this->otherClaims = ['standard' => 100, 'gasds' => 500];
        $this->tx(1, '20.00', '20.00', 'gasds');

        $this->service->createClaim(1, 1, '2026-04-06', '2026-06-30', [1]);

        $this->assertTrue($this->committed);
    }

    public function testCorrectionsAreIncludedInTheClaimTotal(): void
    {
        $this->tx(1, '100.00', '60.00');
        $this->appliedCorrections = ['underclaimed' => '40.00', 'overclaimed' => '20.00'];

        $this->service->createClaim(1, 1, '2026-04-06', '2026-06-30', [1]);

        // Pending corrections are attached to the new claim
        $this->assertNotEmpty(array_filter($this->executes, fn($e) => str_contains($e[0], 'UPDATE giftaid_corrections SET applied_to_claim_id = ?')));
        $totals = $this->claimTotalsWritten();
        $this->assertSame('40.00', $totals['underclaim_adjustment']);
        $this->assertSame('20.00', $totals['overclaim_adjustment']);
        // 25% of (60 + 40 - 20)
        $this->assertSame('20.00', $totals['total_giftaid']);
    }

    public function testCorrectionIsMeasuredAgainstWhatHasAlreadyBeenClaimed(): void
    {
        // £100 claimed originally, already corrected down by £30 in a later claim
        $this->appliedAdjustments = '-30.00';

        $this->service->createCorrection(1, 5, 50.00, 'Donor confirmed only £50 was a gift', 1);

        $correction = array_values(array_filter($this->inserts, fn($i) => $i[0] === 'giftaid_corrections'))[0][1];
        $this->assertSame('70.00', $correction['original_amount']);
        $this->assertSame('-20.00', $correction['adjustment']);
    }

    public function testOnlyOnePendingCorrectionPerTransaction(): void
    {
        $this->pendingCorrections = 1;

        $this->assertRejected(
            fn() => $this->service->createCorrection(1, 5, 50.00, 'Second go', 1),
            'already has a correction waiting'
        );
    }

    public function testUnsubmitRestoresTheRecordedStatus(): void
    {
        $this->claim = ['id' => 3, 'status' => 'submitted'];

        $this->service->unsubmitClaim(3, 1, 1);

        $restore = array_values(array_filter($this->executes, fn($e) => str_contains($e[0], 'gci.original_status')));
        $this->assertCount(1, $restore);
    }

    public function testCancellingADraftReleasesItsCorrections(): void
    {
        $this->claim = ['id' => 3, 'status' => 'draft'];

        $this->service->cancelClaim(3, 1, 1);

        $this->assertNotEmpty(array_filter(
            $this->executes,
            fn($e) => str_contains($e[0], 'UPDATE giftaid_corrections SET applied_to_claim_id = NULL')
        ));
    }

    public function testInvalidClaimPeriodIsRejected(): void
    {
        $this->tx(1, '100.00', '100.00');

        $this->assertRejected(fn() => $this->service->createClaim(1, 1, '2026-06-30', '2026-04-06', [1]), 'claim period');
    }
}
