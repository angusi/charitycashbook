<?php

declare(strict_types=1);

namespace CharityCashbook\Tests\Integration;

use CharityCashbook\Charity\CategoryService;
use CharityCashbook\Charity\CharityRepository;
use CharityCashbook\Charity\DonorService;
use CharityCashbook\Charity\FinancialYear;
use CharityCashbook\Charity\FundService;
use CharityCashbook\Charity\GiftAidService;
use CharityCashbook\Charity\PermissionService;
use CharityCashbook\Charity\ReportService;
use CharityCashbook\Charity\TransactionService;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Renders the pages changed by the review fixes with real data from the services, using
 * the same variables the controllers pass. Catches template/data-shape mistakes that
 * compiling the templates alone would not.
 *
 * Requires MariaDB - set DB_HOST env var to run these tests.
 */
class PageRenderIntegrationTest extends BaseIntegrationTest
{
    use LedgerFixtures;

    private Environment $twig;
    private array $charity;
    private array $common;
    private int $userId;
    private int $splitTransaction;
    private int $transfer;
    private int $claimId;
    private array $receiptCategory;
    private int $general;
    private int $roof;

    protected function setUp(): void
    {
        parent::setUp();

        $this->twig = new Environment(new FilesystemLoader(__DIR__ . '/../../src/Views'), ['strict_variables' => false]);
        $this->twig->addGlobal('csrf_token', 'test-token');
        $this->twig->addGlobal('app_name', 'Test');
        $this->twig->addGlobal('app_url', 'http://localhost');
        $this->twig->addGlobal('debug_mode', false);

        $charityId = $this->makeCharity(['books_locked_until' => '2025-03-31']);
        $this->userId = $this->makeUser($charityId);
        $this->general = $this->makeFund($charityId, 'unrestricted', ['opening_balance' => '1000.00', 'opening_balance_date' => '2025-03-31']);
        $this->roof = $this->makeFund($charityId, 'restricted');
        $endowment = $this->makeFund($charityId, 'endowment', ['opening_balance' => '5000.00', 'opening_balance_date' => '2025-03-31']);
        $this->receiptCategory = $this->makeCategory($charityId, 'receipt', true);
        $payment = $this->makeCategory($charityId, 'payment');
        $bank = $this->makeCashBook($charityId, '1000.00', '2025-03-31');
        $donor = $this->makeDonor($charityId);
        $this->getService(DonorService::class)->addDeclaration($donor, $charityId, $this->userId, ['start_date' => '2025-01-01']);

        $tx = $this->getService(TransactionService::class);
        $this->splitTransaction = $tx->create($charityId, $this->userId, [
            'transaction_date' => '2025-05-01', 'amount' => '150.00', 'description' => 'Split <b>receipt</b>',
            'donor_id' => $donor, 'cash_book_id' => $bank,
            'lines' => [
                ['operational_category_id' => $this->receiptCategory['operational'], 'fund_id' => $this->general, 'amount' => '50.00'],
                ['operational_category_id' => $this->receiptCategory['operational'], 'fund_id' => $this->roof, 'amount' => '100.00'],
            ],
        ]);
        $tx->create($charityId, $this->userId, [
            'transaction_date' => '2025-06-01', 'amount' => '40.00', 'description' => 'Hall hire',
            'operational_category_id' => $payment['operational'], 'fund_id' => $this->general, 'cash_book_id' => $bank,
        ]);
        $this->transfer = $tx->create($charityId, $this->userId, [
            'transaction_type' => 'transfer', 'transaction_date' => '2025-07-01', 'amount' => '25.00', 'description' => 'To roof',
            'fund_entries' => [['fund_id' => $this->general, 'amount' => '-25.00'], ['fund_id' => $this->roof, 'amount' => '25.00']],
        ]);
        $this->claimId = $this->getService(GiftAidService::class)
            ->createClaim($charityId, $this->userId, '2025-04-06', '2025-06-30', [$this->splitTransaction]);

        $this->charity = $this->getService(CharityRepository::class)->findById($charityId);
        $membership = ['role' => 'admin', 'is_active' => 1, 'permissions' => null, 'charity_id' => $charityId];
        $permissionService = $this->getService(PermissionService::class);
        $permissions = [];
        foreach (array_keys(PermissionService::PERMISSIONS) as $key) {
            $permissions[preg_replace('/\./', '_', $key, 1)] = $permissionService->hasPermission($membership, $key, $charityId);
        }
        $modules = array_fill_keys(['donors', 'members', 'reports', 'giftaid', 'cashbooks'], ['is_enabled' => true]);
        $this->common = [
            'charity' => $this->charity,
            'user' => ['display_name' => 'Tester', 'is_super_admin' => false],
            'user_role' => 'admin',
            'enabled_modules' => $modules,
            'permissions' => $permissions,
            'flash' => [],
        ];
    }

    private function render(string $template, array $data): string
    {
        return $this->twig->render($template, array_merge($this->common, $data));
    }

    private function period(): array
    {
        return ['date_from' => '2025-04-01', 'date_to' => '2026-03-31'];
    }

    public function testReportsRender(): void
    {
        $reports = $this->getService(ReportService::class);
        $charityId = (int) $this->charity['id'];
        ['date_from' => $from, 'date_to' => $to] = $this->period();

        $html = $this->render('charity/reports/receipts_payments.html.twig', $this->period() + [
            'report' => $reports->receiptsAndPayments($charityId, $from, $to),
        ]);
        $this->assertStringContainsString('Endowment', $html);
        $this->assertStringContainsString('Funds at end of period', $html);
        $this->assertStringContainsString('£6,110.00', $html); // 6000 + 150 - 40; the transfer nets to zero
        $this->assertStringContainsString('CASH AT 2026-03-31', $html);

        $html = $this->render('charity/reports/receipts_payments_detailed.html.twig', $this->period() + [
            'report' => $reports->detailedReceiptsAndPayments($charityId, $from, $to),
        ]);
        $this->assertStringContainsString('Funds at start of period', $html);

        foreach (['fund_breakdown' => 'fundByFundBreakdown', 'fund_operational_breakdown' => 'fundByFundOperationalBreakdown'] as $template => $method) {
            $html = $this->render("charity/reports/$template.html.twig", $this->period() + [
                'report' => $reports->$method($charityId, $from, $to),
            ]);
            $this->assertMatchesRegularExpression('/(£|&pound;)150\.00/', $html, $template);
        }

        $html = $this->render('charity/reports/fund_summary.html.twig', $this->period() + [
            'report' => $reports->fundSummary($charityId, $to, $from),
        ]);
        $this->assertStringContainsString('Opening (2025-04-01)', $html);
        $this->assertStringContainsString('£6,110.00', $html);

        $html = $this->render('charity/reports/category_analysis.html.twig', $this->period() + [
            'report' => $reports->categoryAnalysis($charityId, $from, $to, 'receipt'),
            'type' => 'receipt',
        ]);
        $this->assertStringContainsString('£150.00', $html);

        $tx = $this->getService(TransactionService::class);
        $filters = $this->period() + ['fund_id' => $this->roof, 'type' => null];
        $totals = $tx->getTotals($charityId, $filters);
        $html = $this->render('charity/reports/transactions.html.twig', $this->period() + [
            'transactions' => $tx->getTransactions($charityId, $filters),
            'funds' => $this->getService(FundService::class)->getAll($charityId, false),
            'fund_id' => $this->roof,
            'type' => null,
            'total_receipts' => $totals['receipts'],
            'total_payments' => $totals['payments'],
            'total_transfers' => $totals['transfers'],
        ]);
        $this->assertStringContainsString('£100.00 in this fund', $html);
        $this->assertStringContainsString('Download CSV', $html);
    }

    public function testTransactionPagesRender(): void
    {
        $charityId = (int) $this->charity['id'];
        $tx = $this->getService(TransactionService::class);
        $funds = $this->getService(FundService::class)->getAll($charityId);
        $transaction = $tx->get($this->splitTransaction, $charityId);

        $html = $this->render('charity/transactions/show.html.twig', ['transaction' => $transaction]);
        $this->assertStringContainsString('Split lines', $html);
        $this->assertStringContainsString('draft Gift Aid claim #' . $this->claimId, $html);
        $this->assertStringNotContainsString('<b>receipt</b>', $html, 'Descriptions are escaped');

        $categories = $this->getService(CategoryService::class)->getOperationalCategories($charityId, 'receipt', false);
        $html = $this->render('charity/transactions/edit.html.twig', [
            'transaction' => $transaction,
            'funds' => $funds,
            'categories' => $categories,
            'donors' => $this->getService(DonorService::class)->getAll($charityId),
            'cash_books' => [],
        ]);
        $this->assertStringContainsString('Split lines *', $html);
        $this->assertStringContainsString('lines[${idx}][fund_id]', $html);

        $html = $this->render('charity/transactions/create.html.twig', [
            'type' => 'receipt',
            'funds' => $funds,
            'categories' => $categories,
            'donors' => [],
            'cash_books' => [],
        ]);
        $this->assertStringContainsString('Split across categories or funds', $html);
        $this->assertStringNotContainsString('fund-entries', $html);

        $html = $this->render('charity/transactions/edit_transfer.html.twig', [
            'transaction' => $tx->get($this->transfer, $charityId),
            'funds' => $funds,
            'cash_books' => [],
        ]);
        $this->assertStringContainsString('transfer amount is', $html);

        $filters = ['limit' => 50, 'offset' => 0, 'fund_id' => $this->roof];
        $totals = $tx->getTotals($charityId, $filters);
        $html = $this->render('charity/transactions/index.html.twig', [
            'transactions' => $tx->getTransactions($charityId, $filters),
            'funds' => $funds,
            'categories' => $categories,
            'filters' => $filters + ['date_from' => null, 'date_to' => null, 'type' => null, 'category_id' => null, 'giftaid_status' => null, 'search' => null, 'needs_review' => null],
            'total_receipts' => $totals['receipts'],
            'total_payments' => $totals['payments'],
            'total_transfers' => $totals['transfers'],
            'pagination' => ['current_page' => 1, 'total_pages' => 1, 'total_items' => 2, 'per_page' => 50, 'base_url' => '/x'],
        ]);
        $this->assertStringContainsString('Net transfers in/(out)', $html);
        $this->assertStringContainsString('£25.00', $html);
    }

    public function testDashboardSettingsAndClaimRender(): void
    {
        $charityId = (int) $this->charity['id'];

        $html = $this->render('charity/dashboard.html.twig', [
            'funds' => $this->getService(FundService::class)->getAllBalances($charityId),
            'total_balance' => 6110.0,
            'recent_transactions' => $this->getService(TransactionService::class)->getTransactions($charityId, ['limit' => 10]),
            'ytd_receipts' => 0.0,
            'ytd_payments' => 0.0,
            'giftaid_summary' => $this->getService(GiftAidService::class)->calculateEligibleTotals($charityId, '2025-04-01', '2026-03-31'),
        ]);
        $this->assertStringContainsString('Fund Balances', $html);

        $html = $this->render('charity/settings/index.html.twig', [
            'books' => [
                'locked_until' => '2025-03-31',
                'close_candidate' => FinancialYear::lastCompletedEnd($this->charity),
                'close_candidate_start' => FinancialYear::startFor($this->charity, FinancialYear::lastCompletedEnd($this->charity)),
                'reopen_to' => '2024-03-31',
            ],
        ]);
        $this->assertStringContainsString('The books are closed up to', $html);
        $this->assertStringContainsString('Reopen year ending 2025-03-31', $html);

        $giftAid = $this->getService(GiftAidService::class);
        $html = $this->render('charity/giftaid/show_claim.html.twig', [
            'claim' => $giftAid->getClaim($this->claimId, $charityId),
            'transactions' => $giftAid->getClaimTransactions($this->claimId, $charityId),
            'underclaims' => [],
            'overclaim_total' => 0,
        ]);
        $this->assertStringContainsString('Cancel Draft Claim', $html);
        $this->assertStringContainsString('Net tax reclaim', $html);
        $this->assertStringContainsString('£37.50', $html); // 25% of £150
    }
}
