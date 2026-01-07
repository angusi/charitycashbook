<?php

declare(strict_types=1);

namespace CharityCashbook\Tests\Integration;

use CharityCashbook\Core\DatabaseInterface;

/**
 * Helper class for creating test data fixtures.
 * 
 * Provides methods to easily create test entities (charities, users, members, etc.)
 * with sensible defaults that can be overridden.
 */
class TestFixtures
{
    private DatabaseInterface $db;
    private int $counter = 0;
    
    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;
    }
    
    /**
     * Generate a unique suffix for test data
     */
    private function unique(): string
    {
        return (string) ++$this->counter;
    }
    
    /**
     * Create a test charity
     */
    public function createCharity(array $overrides = []): array
    {
        $id = $this->unique();
        $data = array_merge([
            'name' => "Test Charity $id",
            'registration_number' => "TC$id",
            'year_end_day' => 31,
            'year_end_month' => 3,
            'is_active' => 1,
        ], $overrides);
        
        $charityId = $this->db->insert('charities', $data);
        
        return array_merge(['id' => $charityId], $data);
    }
    
    /**
     * Create a test user
     */
    public function createUser(array $overrides = []): array
    {
        $id = $this->unique();
        $data = array_merge([
            'display_name' => "Test User $id",
            'primary_email' => "testuser$id@example.com",
            'is_super_admin' => 0,
            'is_active' => 1,
        ], $overrides);
        
        $userId = $this->db->insert('users', $data);
        
        return array_merge(['id' => $userId], $data);
    }
    
    /**
     * Add a user to a charity with a role
     */
    public function addUserToCharity(array $user, array $charity, string $role = 'admin'): array
    {
        $data = [
            'charity_id' => $charity['id'],
            'user_id' => $user['id'],
            'role' => $role,
            'is_active' => 1,
        ];
        
        $id = $this->db->insert('charity_users', $data);
        
        return array_merge(['id' => $id], $data);
    }
    
    /**
     * Create a test member
     */
    public function createMember(array $charity, array $overrides = []): array
    {
        $id = $this->unique();
        $firstName = "Test";
        $lastName = "Member$id";
        $data = array_merge([
            'charity_id' => $charity['id'],
            'first_name' => $firstName,
            'last_name' => $lastName,
            'full_name' => "$firstName $lastName",
            'email' => "member$id@example.com",
            'member_number' => "M$id",
            'membership_type' => 'standard',
            'join_date' => date('Y-m-d'),
            'is_active' => 1,
            'is_trustee' => 0,
        ], $overrides);
        
        $memberId = $this->db->insert('members', $data);
        
        return array_merge(['id' => $memberId], $data);
    }
    
    /**
     * Create a test trustee
     */
    public function createTrustee(array $charity, array $overrides = []): array
    {
        return $this->createMember($charity, array_merge([
            'is_trustee' => 1,
            'trustee_start_date' => date('Y-m-d'),
            'trustee_role' => 'Trustee',
        ], $overrides));
    }
    
    /**
     * Create a test donor
     */
    public function createDonor(array $charity, array $overrides = []): array
    {
        $id = $this->unique();
        $data = array_merge([
            'charity_id' => $charity['id'],
            'first_name' => 'Test',
            'last_name' => "Donor$id",
            'email' => "donor$id@example.com",
            'is_active' => 1,
            'is_giftaid_eligible' => 0,
        ], $overrides);
        
        $donorId = $this->db->insert('donors', $data);
        
        return array_merge(['id' => $donorId], $data);
    }
    
    /**
     * Create a test fund
     */
    public function createFund(array $charity, array $overrides = []): array
    {
        $id = $this->unique();
        $data = array_merge([
            'charity_id' => $charity['id'],
            'name' => "Test Fund $id",
            'fund_type' => 'unrestricted',
            'is_default' => 0,
            'is_active' => 1,
        ], $overrides);
        
        $fundId = $this->db->insert('funds', $data);
        
        return array_merge(['id' => $fundId], $data);
    }
    
    /**
     * Create a test cash book
     */
    public function createCashBook(array $charity, array $overrides = []): array
    {
        $id = $this->unique();
        $data = array_merge([
            'charity_id' => $charity['id'],
            'name' => "Test Cash Book $id",
            'book_type' => 'general',
            'is_default' => 0,
            'is_active' => 1,
        ], $overrides);
        
        $bookId = $this->db->insert('cash_books', $data);
        
        return array_merge(['id' => $bookId], $data);
    }
    
    /**
     * Create a test category
     */
    public function createCategory(array $charity, string $type = 'income', array $overrides = []): array
    {
        $id = $this->unique();
        $data = array_merge([
            'charity_id' => $charity['id'],
            'name' => "Test Category $id",
            'type' => $type,
            'is_active' => 1,
            'display_order' => (int)$id,
        ], $overrides);
        
        $catId = $this->db->insert('categories', $data);
        
        return array_merge(['id' => $catId], $data);
    }
    
    /**
     * Create a test transaction
     */
    public function createTransaction(
        array $charity,
        array $cashBook,
        array $fund,
        array $category,
        array $overrides = []
    ): array {
        $id = $this->unique();
        $data = array_merge([
            'charity_id' => $charity['id'],
            'cash_book_id' => $cashBook['id'],
            'fund_id' => $fund['id'],
            'category_id' => $category['id'],
            'date' => date('Y-m-d'),
            'amount' => 100.00,
            'description' => "Test Transaction $id",
            'type' => $category['type'],
            'is_deleted' => 0,
            'is_reconciled' => 0,
        ], $overrides);
        
        $txnId = $this->db->insert('transactions', $data);
        
        return array_merge(['id' => $txnId], $data);
    }
    
    /**
     * Create dues record for a member
     */
    public function createMemberDues(array $member, array $charity, array $overrides = []): array
    {
        $year = date('Y');
        $data = array_merge([
            'member_id' => $member['id'],
            'charity_id' => $charity['id'],
            'amount' => 25.00,
            'period_start' => "$year-01-01",
            'period_end' => "$year-12-31",
            'status' => 'pending',
        ], $overrides);
        
        $duesId = $this->db->insert('member_dues', $data);
        
        return array_merge(['id' => $duesId], $data);
    }
    
    /**
     * Create a trustee role history record
     */
    public function createTrusteeRole(array $member, array $charity, array $overrides = []): array
    {
        $data = array_merge([
            'member_id' => $member['id'],
            'charity_id' => $charity['id'],
            'role' => 'Trustee',
            'start_date' => date('Y-m-d'),
        ], $overrides);
        
        $roleId = $this->db->insert('member_trustee_roles', $data);
        
        return array_merge(['id' => $roleId], $data);
    }
    
    /**
     * Enable a module for a charity
     */
    public function enableModule(array $charity, string $module, ?int $userId = null): void
    {
        $this->db->insert('charity_modules', [
            'charity_id' => $charity['id'],
            'module' => $module,
            'is_enabled' => 1,
            'enabled_at' => date('Y-m-d H:i:s'),
            'enabled_by_user_id' => $userId,
        ]);
    }
    
    /**
     * Create a custom role with permissions
     */
    public function createRole(array $charity, string $name, array $permissions = []): array
    {
        $data = [
            'charity_id' => $charity['id'],
            'name' => $name,
            'is_active' => 1,
        ];
        
        $roleId = $this->db->insert('charity_roles', $data);
        
        foreach ($permissions as $permission) {
            $this->db->insert('charity_role_permissions', [
                'role_id' => $roleId,
                'permission' => $permission,
            ]);
        }
        
        return array_merge(['id' => $roleId], $data, ['permissions' => $permissions]);
    }
    
    /**
     * Create a complete charity setup (with fund, cash book, categories)
     */
    public function createCharityWithSetup(array $charityOverrides = []): array
    {
        $charity = $this->createCharity($charityOverrides);
        
        // Enable common modules
        $this->enableModule($charity, 'members');
        $this->enableModule($charity, 'donors');
        $this->enableModule($charity, 'cashbooks');
        
        // Create a default fund
        $fund = $this->createFund($charity, ['name' => 'General Fund', 'is_default' => 1]);
        
        // Create a default cash book
        $cashBook = $this->createCashBook($charity, ['name' => 'Main Account', 'is_default' => 1]);
        
        // Create income and expense categories
        $incomeCategory = $this->createCategory($charity, 'income', ['name' => 'Donations']);
        $expenseCategory = $this->createCategory($charity, 'expense', ['name' => 'Operating Costs']);
        
        return [
            'charity' => $charity,
            'fund' => $fund,
            'cash_book' => $cashBook,
            'income_category' => $incomeCategory,
            'expense_category' => $expenseCategory,
        ];
    }
    
    /**
     * Create a session for a user
     */
    public function createSession(array $user, array $charitySessions = []): string
    {
        $token = bin2hex(random_bytes(32));
        
        $this->db->insert('sessions', [
            'token_hash' => hash('sha256', $token),
            'user_id' => $user['id'],
            'charity_sessions' => json_encode($charitySessions),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit Test',
            'expires_at' => date('Y-m-d H:i:s', strtotime('+1 day')),
        ]);
        
        return $token;
    }
    
    /**
     * Get the database instance (for direct queries in tests)
     */
    public function getDb(): DatabaseInterface
    {
        return $this->db;
    }
}
