<?php

declare(strict_types=1);

namespace CharityCashbook\Tests\Integration;

/**
 * Helpers for creating charities, funds, categories and transactions in integration tests.
 * Every fixture gets a unique name so tests never collide with each other's data.
 */
trait LedgerFixtures
{
    protected function makeCharity(array $overrides = []): int
    {
        return $this->getDb()->insert('charities', array_merge([
            'name' => 'Test Charity ' . uniqid(),
            'year_end_day' => 31,
            'year_end_month' => 3,
            'is_active' => 1,
        ], $overrides));
    }

    protected function makeUser(int $charityId, string $role = 'admin'): int
    {
        $userId = $this->getDb()->insert('users', [
            'display_name' => 'Test User',
            'primary_email' => 'test' . uniqid() . '@example.com',
            'is_active' => 1,
        ]);
        $this->getDb()->insert('charity_users', [
            'charity_id' => $charityId,
            'user_id' => $userId,
            'role' => $role,
            'is_active' => 1,
        ]);

        return $userId;
    }

    protected function makeFund(int $charityId, string $type = 'unrestricted', array $overrides = []): int
    {
        return $this->getDb()->insert('funds', array_merge([
            'charity_id' => $charityId,
            'name' => ucfirst($type) . ' fund ' . uniqid(),
            'fund_type' => $type,
            'opening_balance' => '0.00',
            'is_active' => 1,
        ], $overrides));
    }

    /**
     * @return array{reporting: int, operational: int}
     */
    protected function makeCategory(int $charityId, string $type, bool $giftAidEligible = false): array
    {
        $reporting = $this->getDb()->insert('reporting_categories', [
            'charity_id' => $charityId,
            'name' => ucfirst($type) . ' group ' . uniqid(),
            'category_type' => $type,
            'is_active' => 1,
        ]);
        $operational = $this->getDb()->insert('operational_categories', [
            'charity_id' => $charityId,
            'reporting_category_id' => $reporting,
            'name' => ucfirst($type) . ' ' . uniqid(),
            'giftaid_eligible_default' => $giftAidEligible ? 1 : 0,
            'is_active' => 1,
        ]);

        return ['reporting' => $reporting, 'operational' => $operational];
    }

    protected function makeCashBook(int $charityId, string $openingBalance = '0.00', string $openingDate = '2025-03-31'): int
    {
        return $this->getDb()->insert('cash_books', [
            'charity_id' => $charityId,
            'name' => 'Bank ' . uniqid(),
            'book_type' => 'bank',
            'opening_balance' => $openingBalance,
            'opening_balance_date' => $openingDate,
            'is_active' => 1,
        ]);
    }

    protected function makeDonor(int $charityId): int
    {
        return $this->getDb()->insert('donors', [
            'charity_id' => $charityId,
            'name' => 'Donor ' . uniqid(),
            'is_active' => 1,
        ]);
    }
}
