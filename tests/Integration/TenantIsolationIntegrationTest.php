<?php

declare(strict_types=1);

namespace CharityCashbook\Tests\Integration;

use CharityCashbook\Charity\CashBookService;
use CharityCashbook\Charity\FundService;
use CharityCashbook\Charity\MemberService;
use CharityCashbook\Charity\TransactionService;
use CharityCashbook\Core\Exceptions\ValidationException;

/**
 * A user of one charity must not be able to read or change another charity's data by
 * posting its IDs.
 *
 * Requires MariaDB - set DB_HOST env var to run these tests.
 */
class TenantIsolationIntegrationTest extends BaseIntegrationTest
{
    use LedgerFixtures;

    private int $mine;
    private int $theirs;
    private int $userId;
    private array $myCategory;
    private int $myFund;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mine = $this->makeCharity();
        $this->theirs = $this->makeCharity();
        $this->userId = $this->makeUser($this->mine);
        $this->myCategory = $this->makeCategory($this->mine, 'receipt');
        $this->myFund = $this->makeFund($this->mine);
    }

    private function receipt(array $overrides = []): array
    {
        return array_merge([
            'transaction_date' => '2026-05-01',
            'amount' => '10.00',
            'description' => 'Test',
            'operational_category_id' => $this->myCategory['operational'],
            'fund_id' => $this->myFund,
        ], $overrides);
    }

    private function assertCreateRejected(array $data): void
    {
        $this->expectException(ValidationException::class);
        $this->getService(TransactionService::class)->create($this->mine, $this->userId, $data);
    }

    public function testCannotPostToAnotherCharitysFund(): void
    {
        $theirFund = $this->makeFund($this->theirs);
        $this->assertCreateRejected($this->receipt(['fund_id' => $theirFund]));
    }

    public function testAnotherCharitysFundBalanceIsUntouched(): void
    {
        $theirFund = $this->makeFund($this->theirs);
        try {
            $this->getService(TransactionService::class)->create($this->mine, $this->userId, $this->receipt(['fund_id' => $theirFund]));
        } catch (ValidationException) {
        }

        $this->assertEquals(0.0, $this->getService(FundService::class)->getBalance($theirFund, $this->theirs)['balance']);
    }

    public function testCannotPostToAnotherCharitysCashBook(): void
    {
        $theirBook = $this->makeCashBook($this->theirs);
        $this->assertCreateRejected($this->receipt(['cash_book_id' => $theirBook]));
    }

    public function testCannotUseAnotherCharitysDonor(): void
    {
        $theirDonor = $this->makeDonor($this->theirs);
        $this->assertCreateRejected($this->receipt(['donor_id' => $theirDonor]));
    }

    public function testCannotUseAnotherCharitysCategory(): void
    {
        $theirCategory = $this->makeCategory($this->theirs, 'receipt');
        $this->assertCreateRejected($this->receipt(['operational_category_id' => $theirCategory['operational']]));
    }

    public function testCannotAssignAnotherCharitysTransactionsToMyCashBook(): void
    {
        $theirUser = $this->makeUser($this->theirs);
        $theirCategory = $this->makeCategory($this->theirs, 'receipt');
        $theirTransaction = $this->getService(TransactionService::class)->create($this->theirs, $theirUser, [
            'transaction_date' => '2026-05-01',
            'amount' => '10.00',
            'description' => 'Theirs',
            'operational_category_id' => $theirCategory['operational'],
            'fund_id' => $this->makeFund($this->theirs),
        ]);
        $myBook = $this->makeCashBook($this->mine);

        $assigned = $this->getService(CashBookService::class)->assignTransactions($this->mine, [$theirTransaction], $myBook);

        $this->assertSame(0, $assigned);
        $this->assertSame(0, (int) $this->getDb()->queryValue(
            "SELECT COUNT(*) FROM transaction_cashbook_entries WHERE transaction_id = ?",
            [$theirTransaction]
        ));
    }

    public function testCannotEditAnotherCharitysMembershipType(): void
    {
        $theirType = $this->getDb()->insert('membership_types', [
            'charity_id' => $this->theirs,
            'name' => 'Standard',
            'annual_fee' => '10.00',
        ]);

        try {
            $this->getService(MemberService::class)->updateMembershipType($theirType, $this->mine, ['name' => 'Free', 'annual_fee' => '0']);
            $this->fail('Expected ValidationException');
        } catch (ValidationException) {
        }

        $this->assertSame('10.00', $this->getDb()->queryValue("SELECT annual_fee FROM membership_types WHERE id = ?", [$theirType]));
    }

    public function testCannotLinkMyMemberToAnotherCharitysDonor(): void
    {
        $member = $this->getDb()->insert('members', ['charity_id' => $this->mine, 'name' => 'Member', 'is_active' => 1]);
        $theirDonor = $this->makeDonor($this->theirs);

        $this->expectException(ValidationException::class);
        $this->getService(MemberService::class)->linkToDonor($member, $this->mine, $theirDonor);
    }
}
