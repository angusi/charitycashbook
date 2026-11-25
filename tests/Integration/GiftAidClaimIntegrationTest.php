<?php

declare(strict_types=1);

namespace CharityCashbook\Tests\Integration;

use CharityCashbook\Charity\DonorService;
use CharityCashbook\Charity\GiftAidService;
use CharityCashbook\Charity\TransactionService;
use CharityCashbook\Core\Exceptions\ValidationException;

/**
 * Gift Aid claims against a real database.
 *
 * Requires MariaDB - set DB_HOST env var to run these tests.
 */
class GiftAidClaimIntegrationTest extends BaseIntegrationTest
{
    use LedgerFixtures;

    private int $charityId;
    private int $userId;
    private int $fund;
    private array $donations;
    private TransactionService $transactions;
    private GiftAidService $giftAid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->charityId = $this->makeCharity();
        $this->userId = $this->makeUser($this->charityId);
        $this->fund = $this->makeFund($this->charityId);
        $this->donations = $this->makeCategory($this->charityId, 'receipt', true);
        $this->transactions = $this->getService(TransactionService::class);
        $this->giftAid = $this->getService(GiftAidService::class);
    }

    private function donorWithDeclaration(string $startDate, bool $coversPast = false): int
    {
        $donor = $this->makeDonor($this->charityId);
        $this->getService(DonorService::class)->addDeclaration($donor, $this->charityId, $this->userId, [
            'start_date' => $startDate,
            'covers_past_donations' => $coversPast,
        ]);

        return $donor;
    }

    private function donation(array $overrides = []): int
    {
        return $this->transactions->create($this->charityId, $this->userId, array_merge([
            'transaction_date' => '2026-05-01',
            'amount' => '100.00',
            'description' => 'Donation',
            'operational_category_id' => $this->donations['operational'],
            'fund_id' => $this->fund,
        ], $overrides));
    }

    private function giftAidStatus(int $transactionId): string
    {
        return (string) $this->getDb()->queryValue("SELECT giftaid_status FROM transactions WHERE id = ?", [$transactionId]);
    }

    public function testDraftClaimLocksItsDonations(): void
    {
        $donor = $this->donorWithDeclaration('2026-01-01');
        $id = $this->donation(['donor_id' => $donor]);
        $this->giftAid->createClaim($this->charityId, $this->userId, '2026-04-06', '2026-06-30', [$id]);

        try {
            $this->transactions->update($id, $this->charityId, $this->userId, [
                'transaction_date' => '2026-05-01', 'amount' => '500.00', 'description' => 'Donation',
                'operational_category_id' => $this->donations['operational'], 'fund_id' => $this->fund, 'donor_id' => $donor,
            ]);
            $this->fail('Amount change should be refused');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('draft Gift Aid claim', implode(' ', $e->getErrors()));
        }

        $this->expectException(ValidationException::class);
        $this->transactions->delete($id, $this->charityId, $this->userId);
    }

    public function testSubmitRefusesDonationsThatLostEligibility(): void
    {
        $donor = $this->donorWithDeclaration('2026-01-01');
        $id = $this->donation(['donor_id' => $donor]);
        $claimId = $this->giftAid->createClaim($this->charityId, $this->userId, '2026-04-06', '2026-06-30', [$id]);

        // Declaration withdrawn before the donation, after the claim was drafted
        $declarationId = (int) $this->getDb()->queryValue("SELECT id FROM declarations WHERE donor_id = ?", [$donor]);
        $this->getService(DonorService::class)->withdrawDeclaration($declarationId, $this->charityId, $this->userId, '2026-02-01');

        try {
            $this->giftAid->submitClaim($claimId, $this->charityId, $this->userId);
            $this->fail('Submit should be refused');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('no longer eligible', implode(' ', $e->getErrors()));
        }
        $this->assertSame('not_eligible', $this->giftAidStatus($id));
        $this->assertSame('draft', $this->getDb()->queryValue("SELECT status FROM giftaid_claims WHERE id = ?", [$claimId]));
    }

    public function testUnsubmitRestoresGasdsStatus(): void
    {
        $standardDonor = $this->donorWithDeclaration('2026-01-01');
        $standard = $this->donation(['donor_id' => $standardDonor]);
        // A small cash donation from a known donor, claimed under GASDS
        $gasds = $this->donation(['amount' => '20.00', 'is_gasds' => true, 'donor_id' => $this->makeDonor($this->charityId)]);
        $this->getDb()->execute("UPDATE transactions SET giftaid_amount = amount WHERE id = ?", [$gasds]);

        $claimId = $this->giftAid->createClaim($this->charityId, $this->userId, '2026-04-06', '2026-06-30', [$standard, $gasds]);
        $this->giftAid->submitClaim($claimId, $this->charityId, $this->userId);
        $this->assertSame('claimed', $this->giftAidStatus($gasds));

        $this->giftAid->unsubmitClaim($claimId, $this->charityId, $this->userId);

        $this->assertSame('gasds', $this->giftAidStatus($gasds));
        $this->assertSame('eligible', $this->giftAidStatus($standard));
    }

    public function testCorrectionsAreAppliedToTheNextClaimAndReleasedOnCancel(): void
    {
        $donor = $this->donorWithDeclaration('2026-01-01');
        $first = $this->donation(['donor_id' => $donor]);
        $claimId = $this->giftAid->createClaim($this->charityId, $this->userId, '2026-04-06', '2026-06-30', [$first]);
        $this->giftAid->submitClaim($claimId, $this->charityId, $this->userId);

        // Only £60 of the £100 should have been claimed
        $this->giftAid->createCorrection($this->charityId, $first, 60.00, 'Part was a ticket', $this->userId);

        $second = $this->donation(['donor_id' => $donor, 'transaction_date' => '2026-07-01']);
        $nextClaim = $this->giftAid->createClaim($this->charityId, $this->userId, '2026-07-01', '2026-07-31', [$second]);

        $claim = $this->giftAid->getClaim($nextClaim, $this->charityId);
        $this->assertSame('40.00', $claim['overclaim_adjustment']);
        // 25% of (100 - 40)
        $this->assertSame('15.00', $claim['total_giftaid']);
        $this->assertSame([], $this->giftAid->getPendingCorrections($this->charityId));

        $this->giftAid->cancelClaim($nextClaim, $this->charityId, $this->userId);
        $this->assertCount(1, $this->giftAid->getPendingCorrections($this->charityId));
    }

    public function testDeclarationCoveringPastDonations(): void
    {
        $donor = $this->donorWithDeclaration('2026-06-01', true);

        $id = $this->donation(['donor_id' => $donor, 'transaction_date' => '2025-01-15']);

        $this->assertSame('eligible', $this->giftAidStatus($id));
    }

    public function testExcludedDonationStaysExcludedWhenADeclarationIsAdded(): void
    {
        $donor = $this->makeDonor($this->charityId);
        $id = $this->donation(['donor_id' => $donor, 'giftaid_eligibility_override' => 'not_eligible']);
        $this->assertSame('excluded', $this->giftAidStatus($id));

        $this->getService(DonorService::class)->addDeclaration($donor, $this->charityId, $this->userId, [
            'start_date' => '2026-01-01',
        ]);

        $this->assertSame('excluded', $this->giftAidStatus($id));
    }
}
