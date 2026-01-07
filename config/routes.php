<?php

declare(strict_types=1);

use CharityCashbook\Core\Middleware\AuthenticationMiddleware;
use CharityCashbook\Core\Middleware\CharityAccessMiddleware;
use CharityCashbook\Core\Middleware\CharitySessionMiddleware;
use CharityCashbook\Core\Middleware\PermissionMiddleware;
use CharityCashbook\Core\Middleware\SensitiveActionMiddleware;
use CharityCashbook\Core\Middleware\ServiceAdminSensitiveActionMiddleware;
use CharityCashbook\Core\Middleware\SuperAdminMiddleware;
use CharityCashbook\Core\Middleware\CsrfMiddleware;
use CharityCashbook\Core\Middleware\ModuleMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app) {
    // Health check
    $app->get('/health', \CharityCashbook\Core\Controllers\HealthController::class . ':check');

    // Public routes
    $app->get('/', \CharityCashbook\Auth\Controllers\LoginController::class . ':index');
    $app->get('/login', \CharityCashbook\Auth\Controllers\LoginController::class . ':index');
    $app->get('/privacy', \CharityCashbook\Core\Controllers\LegalController::class . ':privacy');
    $app->get('/terms', \CharityCashbook\Core\Controllers\LegalController::class . ':terms');
    $app->get('/login/{provider}', \CharityCashbook\Auth\Controllers\LoginController::class . ':redirectToProvider');
    $app->get('/oauth/callback/{provider}', \CharityCashbook\Auth\Controllers\OAuthCallbackController::class . ':handle');
    $app->post('/oauth/create-account', \CharityCashbook\Auth\Controllers\OAuthCallbackController::class . ':createAccount');
    $app->post('/oauth/link-to-existing', \CharityCashbook\Auth\Controllers\OAuthCallbackController::class . ':linkToExisting');
    $app->get('/logout', \CharityCashbook\Auth\Controllers\LoginController::class . ':logout');

    // Account linking routes (authenticated)
    $app->group('/account', function (RouteCollectorProxy $group) {
        $group->get('', \CharityCashbook\Auth\Controllers\AccountController::class . ':index');
        $group->get('/link', \CharityCashbook\Auth\Controllers\AccountController::class . ':linkStart');
        $group->get('/link/start/{provider}', \CharityCashbook\Auth\Controllers\AccountController::class . ':linkRedirect');
        $group->get('/link/confirm/{token}', \CharityCashbook\Auth\Controllers\AccountController::class . ':linkConfirm');
        $group->post('/link/cancel', \CharityCashbook\Auth\Controllers\AccountController::class . ':linkCancel');
        $group->get('/sessions', \CharityCashbook\Auth\Controllers\AccountController::class . ':sessions');
        $group->post('/sessions/logout-all', \CharityCashbook\Auth\Controllers\AccountController::class . ':logoutAll');
    })->add(AuthenticationMiddleware::class);

    // Service admin routes (super-admin only)
    $app->group('/service-admin', function (RouteCollectorProxy $group) {
        $group->get('', \CharityCashbook\ServiceAdmin\Controllers\DashboardController::class . ':index');
        $group->get('/statistics', \CharityCashbook\ServiceAdmin\Controllers\DashboardController::class . ':statistics');
        
        // Confirmation page for sensitive actions
        $group->get('/confirm-action', \CharityCashbook\ServiceAdmin\Controllers\ConfirmActionController::class . ':show');
        $group->post('/confirm-action', \CharityCashbook\ServiceAdmin\Controllers\ConfirmActionController::class . ':execute');
        
        // Charity management
        $group->get('/charities', \CharityCashbook\ServiceAdmin\Controllers\CharityController::class . ':index');
        $group->get('/charities/create', \CharityCashbook\ServiceAdmin\Controllers\CharityController::class . ':create');
        $group->post('/charities', \CharityCashbook\ServiceAdmin\Controllers\CharityController::class . ':store');
        $group->get('/charities/{id}', \CharityCashbook\ServiceAdmin\Controllers\CharityController::class . ':show');
        $group->get('/charities/{id}/edit', \CharityCashbook\ServiceAdmin\Controllers\CharityController::class . ':edit');
        $group->post('/charities/{id}', \CharityCashbook\ServiceAdmin\Controllers\CharityController::class . ':update');
        $group->post('/charities/{id}/suspend', \CharityCashbook\ServiceAdmin\Controllers\CharityController::class . ':suspend')
            ->add(ServiceAdminSensitiveActionMiddleware::class);
        $group->post('/charities/{id}/reactivate', \CharityCashbook\ServiceAdmin\Controllers\CharityController::class . ':reactivate')
            ->add(ServiceAdminSensitiveActionMiddleware::class);
        $group->post('/charities/{id}/invite', \CharityCashbook\ServiceAdmin\Controllers\CharityController::class . ':sendInvitation');
        $group->post('/charities/{id}/users/{user_id}/make-admin', \CharityCashbook\ServiceAdmin\Controllers\CharityController::class . ':makeUserAdmin')
            ->add(ServiceAdminSensitiveActionMiddleware::class);

        // OAuth provider management
        $group->get('/providers', \CharityCashbook\ServiceAdmin\Controllers\ProviderController::class . ':index');
        $group->get('/providers/create', \CharityCashbook\ServiceAdmin\Controllers\ProviderController::class . ':create');
        $group->post('/providers', \CharityCashbook\ServiceAdmin\Controllers\ProviderController::class . ':store');
        $group->get('/providers/{id}/edit', \CharityCashbook\ServiceAdmin\Controllers\ProviderController::class . ':edit');
        $group->post('/providers/{id}', \CharityCashbook\ServiceAdmin\Controllers\ProviderController::class . ':update');
        $group->post('/providers/{id}/toggle', \CharityCashbook\ServiceAdmin\Controllers\ProviderController::class . ':toggle')
            ->add(ServiceAdminSensitiveActionMiddleware::class);

        // Category template management
        $group->get('/templates', \CharityCashbook\ServiceAdmin\Controllers\TemplateController::class . ':index');
        $group->get('/templates/create', \CharityCashbook\ServiceAdmin\Controllers\TemplateController::class . ':create');
        $group->post('/templates', \CharityCashbook\ServiceAdmin\Controllers\TemplateController::class . ':store');
        $group->get('/templates/{id}/edit', \CharityCashbook\ServiceAdmin\Controllers\TemplateController::class . ':edit');
        $group->post('/templates/{id}', \CharityCashbook\ServiceAdmin\Controllers\TemplateController::class . ':update');
        $group->post('/templates/{id}/delete', \CharityCashbook\ServiceAdmin\Controllers\TemplateController::class . ':delete');
        $group->post('/templates/{id}/duplicate', \CharityCashbook\ServiceAdmin\Controllers\TemplateController::class . ':duplicate');
        $group->post('/templates/{id}/items', \CharityCashbook\ServiceAdmin\Controllers\TemplateController::class . ':addItem');
        $group->post('/templates/{id}/items/{item_id}', \CharityCashbook\ServiceAdmin\Controllers\TemplateController::class . ':updateItem');
        $group->post('/templates/{id}/items/{item_id}/delete', \CharityCashbook\ServiceAdmin\Controllers\TemplateController::class . ':deleteItem');

        // Diagnostics and logs
        $group->get('/diagnostics', \CharityCashbook\ServiceAdmin\Controllers\DiagnosticsController::class . ':index');
        $group->get('/logs', \CharityCashbook\ServiceAdmin\Controllers\LogsController::class . ':index');
        
        // Maintenance
        $group->get('/maintenance', \CharityCashbook\ServiceAdmin\Controllers\MaintenanceController::class . ':index');
        $group->post('/maintenance/clear-cache', \CharityCashbook\ServiceAdmin\Controllers\MaintenanceController::class . ':clearCache');
        $group->post('/maintenance/clean-sessions', \CharityCashbook\ServiceAdmin\Controllers\MaintenanceController::class . ':cleanSessions');
        $group->post('/maintenance/clean-tokens', \CharityCashbook\ServiceAdmin\Controllers\MaintenanceController::class . ':cleanTokens');
        $group->post('/maintenance/force-global-reauth', \CharityCashbook\ServiceAdmin\Controllers\MaintenanceController::class . ':forceGlobalReauth')
            ->add(ServiceAdminSensitiveActionMiddleware::class);
        $group->post('/charities/{id}/force-reauth', \CharityCashbook\ServiceAdmin\Controllers\MaintenanceController::class . ':forceCharityReauth')
            ->add(ServiceAdminSensitiveActionMiddleware::class);

        // Privacy / GDPR tools
        $group->get('/privacy', \CharityCashbook\ServiceAdmin\Controllers\PrivacyController::class . ':index');
        $group->get('/privacy/users/{id}', \CharityCashbook\ServiceAdmin\Controllers\PrivacyController::class . ':showUserData');
        $group->get('/privacy/users/{id}/export', \CharityCashbook\ServiceAdmin\Controllers\PrivacyController::class . ':exportUserData');
        $group->get('/privacy/users/{id}/delete', \CharityCashbook\ServiceAdmin\Controllers\PrivacyController::class . ':showUserDeletion');
        $group->post('/privacy/users/{id}/delete', \CharityCashbook\ServiceAdmin\Controllers\PrivacyController::class . ':processUserDeletion')
            ->add(ServiceAdminSensitiveActionMiddleware::class);
        $group->post('/privacy/users/{id}/terminate-sessions', \CharityCashbook\ServiceAdmin\Controllers\PrivacyController::class . ':terminateUserSessions')
            ->add(ServiceAdminSensitiveActionMiddleware::class);

        // Security investigation
        $group->get('/security', \CharityCashbook\ServiceAdmin\Controllers\SecurityController::class . ':index');
        $group->post('/security/users/{user_id}/terminate-sessions', \CharityCashbook\ServiceAdmin\Controllers\SecurityController::class . ':terminateSessions')
            ->add(ServiceAdminSensitiveActionMiddleware::class);
        $group->post('/security/users/{user_id}/disable', \CharityCashbook\ServiceAdmin\Controllers\SecurityController::class . ':disableUser')
            ->add(ServiceAdminSensitiveActionMiddleware::class);
        $group->post('/security/users/{user_id}/enable', \CharityCashbook\ServiceAdmin\Controllers\SecurityController::class . ':enableUser')
            ->add(ServiceAdminSensitiveActionMiddleware::class);
    })->add(SuperAdminMiddleware::class)->add(AuthenticationMiddleware::class);

    // Charity selection (authenticated users)
    $app->get('/charities', \CharityCashbook\Charity\Controllers\CharitySelectionController::class . ':index')
        ->add(AuthenticationMiddleware::class);
    $app->get('/charities/{id}/enter', \CharityCashbook\Charity\Controllers\CharitySelectionController::class . ':enter')
        ->add(AuthenticationMiddleware::class);

    // Pending user page (before CharityAccessMiddleware fully processes)
    $app->get('/charity/{charity_id}/pending', \CharityCashbook\Charity\Controllers\DashboardController::class . ':pending')
        ->add(AuthenticationMiddleware::class);

    // Charity workspace routes
    $app->group('/charity/{charity_id}', function (RouteCollectorProxy $group) {
        // Dashboard
        $group->get('', \CharityCashbook\Charity\Controllers\DashboardController::class . ':index');

        // Transactions
        $group->get('/transactions', \CharityCashbook\Charity\Controllers\TransactionController::class . ':index');
        $group->get('/transactions/create', \CharityCashbook\Charity\Controllers\TransactionController::class . ':create')
            ->add(new PermissionMiddleware('transactions.create'));
        $group->post('/transactions', \CharityCashbook\Charity\Controllers\TransactionController::class . ':store')
            ->add(new PermissionMiddleware('transactions.create'));
        $group->get('/transactions/{id}', \CharityCashbook\Charity\Controllers\TransactionController::class . ':show');
        $group->get('/transactions/{id}/edit', \CharityCashbook\Charity\Controllers\TransactionController::class . ':edit')
            ->add(new PermissionMiddleware('transactions.edit'));
        $group->post('/transactions/{id}', \CharityCashbook\Charity\Controllers\TransactionController::class . ':update')
            ->add(new PermissionMiddleware('transactions.edit'));
        $group->post('/transactions/{id}/delete', \CharityCashbook\Charity\Controllers\TransactionController::class . ':delete')
            ->add(new SensitiveActionMiddleware())
            ->add(new PermissionMiddleware('transactions.delete'));

        // Funds
        $group->get('/funds', \CharityCashbook\Charity\Controllers\FundController::class . ':index');
        $group->get('/funds/create', \CharityCashbook\Charity\Controllers\FundController::class . ':create')
            ->add(new PermissionMiddleware('funds.manage'));
        $group->post('/funds', \CharityCashbook\Charity\Controllers\FundController::class . ':store')
            ->add(new PermissionMiddleware('funds.manage'));
        $group->get('/funds/{id}/edit', \CharityCashbook\Charity\Controllers\FundController::class . ':edit')
            ->add(new PermissionMiddleware('funds.manage'));
        $group->post('/funds/{id}', \CharityCashbook\Charity\Controllers\FundController::class . ':update')
            ->add(new PermissionMiddleware('funds.manage'));

        // Categories
        $group->get('/categories', \CharityCashbook\Charity\Controllers\CategoryController::class . ':index');
        $group->get('/categories/reporting/create', \CharityCashbook\Charity\Controllers\CategoryController::class . ':createReporting')
            ->add(new PermissionMiddleware('categories.manage'));
        $group->post('/categories/reporting', \CharityCashbook\Charity\Controllers\CategoryController::class . ':storeReporting')
            ->add(new PermissionMiddleware('categories.manage'));
        $group->get('/categories/reporting/{id}/edit', \CharityCashbook\Charity\Controllers\CategoryController::class . ':editReporting')
            ->add(new PermissionMiddleware('categories.manage'));
        $group->post('/categories/reporting/{id}', \CharityCashbook\Charity\Controllers\CategoryController::class . ':updateReporting')
            ->add(new PermissionMiddleware('categories.manage'));
        $group->post('/categories/reporting/{id}/hide', \CharityCashbook\Charity\Controllers\CategoryController::class . ':hideReporting')
            ->add(new PermissionMiddleware('categories.manage'));
        $group->get('/categories/operational/create', \CharityCashbook\Charity\Controllers\CategoryController::class . ':createOperational')
            ->add(new PermissionMiddleware('categories.manage'));
        $group->post('/categories/operational', \CharityCashbook\Charity\Controllers\CategoryController::class . ':storeOperational')
            ->add(new PermissionMiddleware('categories.manage'));
        $group->get('/categories/operational/{id}/edit', \CharityCashbook\Charity\Controllers\CategoryController::class . ':editOperational')
            ->add(new PermissionMiddleware('categories.manage'));
        $group->post('/categories/operational/{id}', \CharityCashbook\Charity\Controllers\CategoryController::class . ':updateOperational')
            ->add(new PermissionMiddleware('categories.manage'));
        $group->post('/categories/operational/{id}/hide', \CharityCashbook\Charity\Controllers\CategoryController::class . ':hideOperational')
            ->add(new PermissionMiddleware('categories.manage'));

        // Donors (requires donors module)
        $group->group('/donors', function (RouteCollectorProxy $donors) {
            $donors->get('', \CharityCashbook\Charity\Controllers\DonorController::class . ':index');
            $donors->get('/create', \CharityCashbook\Charity\Controllers\DonorController::class . ':create')
                ->add(new PermissionMiddleware('donors.create'));
            $donors->post('', \CharityCashbook\Charity\Controllers\DonorController::class . ':store')
                ->add(new PermissionMiddleware('donors.create'));
            $donors->post('/quick-create', \CharityCashbook\Charity\Controllers\DonorController::class . ':quickCreate')
                ->add(new PermissionMiddleware('donors.create'));
            $donors->get('/{id}', \CharityCashbook\Charity\Controllers\DonorController::class . ':show');
            $donors->get('/{id}/edit', \CharityCashbook\Charity\Controllers\DonorController::class . ':edit')
                ->add(new PermissionMiddleware('donors.edit'));
            $donors->post('/{id}', \CharityCashbook\Charity\Controllers\DonorController::class . ':update')
                ->add(new PermissionMiddleware('donors.edit'));
            // Privacy / GDPR
            $donors->get('/{id}/privacy/export', \CharityCashbook\Charity\Controllers\PrivacyController::class . ':exportDonorData')
                ->add(new PermissionMiddleware('privacy.export'));
            $donors->get('/{id}/privacy/delete', \CharityCashbook\Charity\Controllers\PrivacyController::class . ':showDonorDeletion')
                ->add(new SensitiveActionMiddleware())
                ->add(new PermissionMiddleware('privacy.delete'));
            $donors->post('/{id}/privacy/delete', \CharityCashbook\Charity\Controllers\PrivacyController::class . ':processDonorDeletion')
                ->add(new SensitiveActionMiddleware())
                ->add(new PermissionMiddleware('privacy.delete'));
            // Create member from donor (requires both donors.edit and members.create)
            $donors->post('/{id}/create-member', \CharityCashbook\Charity\Controllers\DonorController::class . ':createMemberFromDonor')
                ->add(new PermissionMiddleware('members.create'))
                ->add(new PermissionMiddleware('donors.edit'));
        })->add(new ModuleMiddleware('donors'));

        // Gift Aid (requires giftaid module)
        $group->group('/giftaid', function (RouteCollectorProxy $giftaid) {
            $giftaid->get('', \CharityCashbook\Charity\Controllers\GiftAidController::class . ':index');
            $giftaid->get('/claims', \CharityCashbook\Charity\Controllers\GiftAidController::class . ':claims');
            $giftaid->get('/claims/create', \CharityCashbook\Charity\Controllers\GiftAidController::class . ':createClaim')
                ->add(new PermissionMiddleware('giftaid.manage_claims'));
            $giftaid->post('/claims', \CharityCashbook\Charity\Controllers\GiftAidController::class . ':storeClaim')
                ->add(new PermissionMiddleware('giftaid.manage_claims'));
            $giftaid->get('/claims/{id}', \CharityCashbook\Charity\Controllers\GiftAidController::class . ':showClaim');
            $giftaid->get('/claims/{id}/edit', \CharityCashbook\Charity\Controllers\GiftAidController::class . ':editClaim')
                ->add(new PermissionMiddleware('giftaid.manage_claims'));
            $giftaid->post('/claims/{id}', \CharityCashbook\Charity\Controllers\GiftAidController::class . ':updateClaim')
                ->add(new PermissionMiddleware('giftaid.manage_claims'));
            $giftaid->post('/claims/{id}/submit', \CharityCashbook\Charity\Controllers\GiftAidController::class . ':submitClaim')
                ->add(new PermissionMiddleware('giftaid.manage_claims'));
            $giftaid->post('/claims/{id}/unsubmit', \CharityCashbook\Charity\Controllers\GiftAidController::class . ':unsubmitClaim')
                ->add(new PermissionMiddleware('giftaid.manage_claims'));
        })->add(new ModuleMiddleware('giftaid'));

        // Reports (requires reports module)
        $group->group('/reports', function (RouteCollectorProxy $reports) {
            $reports->get('', \CharityCashbook\Charity\Controllers\ReportController::class . ':index');
            $reports->get('/receipts-payments', \CharityCashbook\Charity\Controllers\ReportController::class . ':receiptsPayments');
            $reports->get('/receipts-payments-detailed', \CharityCashbook\Charity\Controllers\ReportController::class . ':receiptsPaymentsDetailed');
            $reports->get('/fund-breakdown', \CharityCashbook\Charity\Controllers\ReportController::class . ':fundBreakdown');
            $reports->get('/transactions', \CharityCashbook\Charity\Controllers\ReportController::class . ':transactions');
            $reports->get('/fund-summary', \CharityCashbook\Charity\Controllers\ReportController::class . ':fundSummary');
            $reports->get('/category-analysis', \CharityCashbook\Charity\Controllers\ReportController::class . ':categoryAnalysis');
            $reports->get('/giftaid', \CharityCashbook\Charity\Controllers\ReportController::class . ':giftAid');
        })->add(new ModuleMiddleware('reports'));

        // Members (requires members module)
        $group->group('/members', function (RouteCollectorProxy $members) {
            $members->get('', \CharityCashbook\Charity\Controllers\MemberController::class . ':index');
            $members->get('/export', \CharityCashbook\Charity\Controllers\MemberController::class . ':exportMembers')
                ->add(new PermissionMiddleware('members.view_list'));
            $members->get('/trustees', \CharityCashbook\Charity\Controllers\MemberController::class . ':trustees');
            $members->get('/trustees/export', \CharityCashbook\Charity\Controllers\MemberController::class . ':exportTrustees')
                ->add(new PermissionMiddleware('members.view_list'));
            $members->get('/create', \CharityCashbook\Charity\Controllers\MemberController::class . ':create')
                ->add(new PermissionMiddleware('members.create'));
            $members->post('', \CharityCashbook\Charity\Controllers\MemberController::class . ':store')
                ->add(new PermissionMiddleware('members.create'));
            $members->get('/dues', \CharityCashbook\Charity\Controllers\MemberController::class . ':dues');
            $members->get('/dues/generate', \CharityCashbook\Charity\Controllers\MemberController::class . ':bulkDuesForm')
                ->add(new PermissionMiddleware('members.manage_dues'));
            $members->post('/dues/generate', \CharityCashbook\Charity\Controllers\MemberController::class . ':generateBulkDues')
                ->add(new PermissionMiddleware('members.manage_dues'));
            $members->get('/types', \CharityCashbook\Charity\Controllers\MemberController::class . ':types')
                ->add(new PermissionMiddleware('members.edit'));
            $members->post('/types', \CharityCashbook\Charity\Controllers\MemberController::class . ':storeType')
                ->add(new PermissionMiddleware('members.edit'));
            $members->post('/types/{id}', \CharityCashbook\Charity\Controllers\MemberController::class . ':updateType')
                ->add(new PermissionMiddleware('members.edit'));
            $members->get('/{id}', \CharityCashbook\Charity\Controllers\MemberController::class . ':show');
            $members->get('/{id}/edit', \CharityCashbook\Charity\Controllers\MemberController::class . ':edit')
                ->add(new PermissionMiddleware('members.edit'));
            $members->post('/{id}', \CharityCashbook\Charity\Controllers\MemberController::class . ':update')
                ->add(new PermissionMiddleware('members.edit'));
            $members->post('/{id}/trustee-role', \CharityCashbook\Charity\Controllers\MemberController::class . ':addTrusteeRole')
                ->add(new PermissionMiddleware('members.manage_trustees'));
            $members->get('/{id}/dues/create', \CharityCashbook\Charity\Controllers\MemberController::class . ':createDues')
                ->add(new PermissionMiddleware('members.manage_dues'));
            $members->post('/{id}/dues', \CharityCashbook\Charity\Controllers\MemberController::class . ':storeDues')
                ->add(new PermissionMiddleware('members.manage_dues'));
            $members->post('/{id}/link-donor', \CharityCashbook\Charity\Controllers\MemberController::class . ':linkDonor')
                ->add(new PermissionMiddleware('members.edit'));
            $members->post('/{id}/unlink-donor', \CharityCashbook\Charity\Controllers\MemberController::class . ':unlinkDonor')
                ->add(new PermissionMiddleware('members.edit'));
            // Create donor from member (requires both members.edit and donors.create)
            $members->post('/{id}/create-donor', \CharityCashbook\Charity\Controllers\MemberController::class . ':createDonorFromMember')
                ->add(new PermissionMiddleware('donors.create'))
                ->add(new PermissionMiddleware('members.edit'));
            $members->post('/dues/{dues_id}/payment', \CharityCashbook\Charity\Controllers\MemberController::class . ':recordPayment')
                ->add(new PermissionMiddleware('members.manage_dues'));
            $members->post('/dues/{dues_id}/waive', \CharityCashbook\Charity\Controllers\MemberController::class . ':waiveDues')
                ->add(new PermissionMiddleware('members.manage_dues'));
            $members->post('/dues/{dues_id}/undo', \CharityCashbook\Charity\Controllers\MemberController::class . ':undoDues')
                ->add(new PermissionMiddleware('members.manage_dues'));
            $members->post('/trustee-role/{role_id}/end', \CharityCashbook\Charity\Controllers\MemberController::class . ':endTrusteeRole')
                ->add(new PermissionMiddleware('members.manage_trustees'));
            $members->post('/trustee-role/{role_id}/update', \CharityCashbook\Charity\Controllers\MemberController::class . ':updateTrusteeRole')
                ->add(new PermissionMiddleware('members.manage_trustees'));
            // Privacy / GDPR
            $members->get('/{id}/privacy/export', \CharityCashbook\Charity\Controllers\PrivacyController::class . ':exportMemberData')
                ->add(new PermissionMiddleware('privacy.export'));
            $members->get('/{id}/privacy/delete', \CharityCashbook\Charity\Controllers\PrivacyController::class . ':showMemberDeletion')
                ->add(new SensitiveActionMiddleware())
                ->add(new PermissionMiddleware('privacy.delete'));
            $members->post('/{id}/privacy/delete', \CharityCashbook\Charity\Controllers\PrivacyController::class . ':processMemberDeletion')
                ->add(new SensitiveActionMiddleware())
                ->add(new PermissionMiddleware('privacy.delete'));
        })->add(new ModuleMiddleware('members'));

        // Cash Books (requires cashbooks module)
        $group->group('/cashbooks', function (RouteCollectorProxy $cashbooks) {
            $cashbooks->get('', \CharityCashbook\Charity\Controllers\CashBookController::class . ':index');
            $cashbooks->get('/setup', \CharityCashbook\Charity\Controllers\CashBookController::class . ':setup')
                ->add(new PermissionMiddleware('cashbooks.manage'));
            $cashbooks->post('/setup', \CharityCashbook\Charity\Controllers\CashBookController::class . ':completeSetup')
                ->add(new PermissionMiddleware('cashbooks.manage'));
            $cashbooks->get('/create', \CharityCashbook\Charity\Controllers\CashBookController::class . ':create')
                ->add(new PermissionMiddleware('cashbooks.manage'));
            $cashbooks->post('', \CharityCashbook\Charity\Controllers\CashBookController::class . ':store')
                ->add(new PermissionMiddleware('cashbooks.manage'));
            $cashbooks->get('/unlinked', \CharityCashbook\Charity\Controllers\CashBookController::class . ':unlinked');
            $cashbooks->post('/assign', \CharityCashbook\Charity\Controllers\CashBookController::class . ':assignTransactions')
                ->add(new PermissionMiddleware('cashbooks.manage'));
            $cashbooks->get('/{id}', \CharityCashbook\Charity\Controllers\CashBookController::class . ':show');
            $cashbooks->get('/{id}/edit', \CharityCashbook\Charity\Controllers\CashBookController::class . ':edit')
                ->add(new PermissionMiddleware('cashbooks.manage'));
            $cashbooks->post('/{id}', \CharityCashbook\Charity\Controllers\CashBookController::class . ':update')
                ->add(new PermissionMiddleware('cashbooks.manage'));
            $cashbooks->get('/{id}/reconcile', \CharityCashbook\Charity\Controllers\CashBookController::class . ':reconcile')
                ->add(new PermissionMiddleware('cashbooks.reconcile'));
            $cashbooks->post('/{id}/reconcile', \CharityCashbook\Charity\Controllers\CashBookController::class . ':storeReconciliation')
                ->add(new PermissionMiddleware('cashbooks.reconcile'));
        })->add(new ModuleMiddleware('cashbooks'));

        // Settings
        $group->group('/settings', function (RouteCollectorProxy $settings) {
            $settings->get('', \CharityCashbook\Charity\Controllers\SettingsController::class . ':index')
                ->add(new PermissionMiddleware('settings.view'));
            $settings->post('/general', \CharityCashbook\Charity\Controllers\SettingsController::class . ':updateGeneral')
                ->add(new PermissionMiddleware('settings.manage'));
            $settings->post('/auth', \CharityCashbook\Charity\Controllers\SettingsController::class . ':updateAuth')
                ->add(new PermissionMiddleware('settings.manage'));
            
            // Role permissions
            $settings->get('/roles', \CharityCashbook\Charity\Controllers\SettingsController::class . ':roles')
                ->add(new PermissionMiddleware('users.manage'));
            $settings->get('/roles/create', \CharityCashbook\Charity\Controllers\SettingsController::class . ':createRole')
                ->add(new PermissionMiddleware('users.manage'));
            $settings->post('/roles', \CharityCashbook\Charity\Controllers\SettingsController::class . ':storeRole')
                ->add(new PermissionMiddleware('users.manage'));
            $settings->get('/roles/{id}/edit', \CharityCashbook\Charity\Controllers\SettingsController::class . ':editRole')
                ->add(new PermissionMiddleware('users.manage'));
            $settings->post('/roles/{id}', \CharityCashbook\Charity\Controllers\SettingsController::class . ':updateRole')
                ->add(new PermissionMiddleware('users.manage'));
            $settings->post('/roles/{id}/delete', \CharityCashbook\Charity\Controllers\SettingsController::class . ':deleteRole')
                ->add(new PermissionMiddleware('users.manage'));
            
            // Modules
            $settings->get('/modules', \CharityCashbook\Charity\Controllers\SettingsController::class . ':modules')
                ->add(new PermissionMiddleware('settings.manage'));
            $settings->post('/modules/{module}/enable', \CharityCashbook\Charity\Controllers\SettingsController::class . ':enableModule')
                ->add(new PermissionMiddleware('settings.manage'));
            $settings->post('/modules/{module}/disable', \CharityCashbook\Charity\Controllers\SettingsController::class . ':disableModule')
                ->add(new PermissionMiddleware('settings.manage'));
            
            // User management
            $settings->get('/users', \CharityCashbook\Charity\Controllers\UserController::class . ':index')
                ->add(new PermissionMiddleware('users.view'));
            $settings->get('/users/invite', \CharityCashbook\Charity\Controllers\UserController::class . ':invite')
                ->add(new PermissionMiddleware('users.manage'));
            $settings->post('/users/invite', \CharityCashbook\Charity\Controllers\UserController::class . ':sendInvite')
                ->add(new PermissionMiddleware('users.manage'));
            $settings->get('/users/{id}/edit', \CharityCashbook\Charity\Controllers\UserController::class . ':edit')
                ->add(new PermissionMiddleware('users.manage'));
            $settings->post('/users/{id}', \CharityCashbook\Charity\Controllers\UserController::class . ':update')
                ->add(new PermissionMiddleware('users.manage'));
            $settings->post('/users/{id}/approve', \CharityCashbook\Charity\Controllers\UserController::class . ':approve')
                ->add(new PermissionMiddleware('users.manage'));
            $settings->post('/users/{id}/reject', \CharityCashbook\Charity\Controllers\UserController::class . ':reject')
                ->add(new PermissionMiddleware('users.manage'));
            $settings->post('/users/{id}/deactivate', \CharityCashbook\Charity\Controllers\UserController::class . ':deactivate')
                ->add(new PermissionMiddleware('users.manage'));
            $settings->post('/users/{id}/force-reauth', \CharityCashbook\Charity\Controllers\UserController::class . ':forceReauth')
                ->add(new PermissionMiddleware('users.manage'));
            $settings->post('/users/force-reauth-all', \CharityCashbook\Charity\Controllers\UserController::class . ':forceReauthAll')
                ->add(new PermissionMiddleware('users.manage'));
            
            // Allowed email management
            $settings->get('/allowed-emails', \CharityCashbook\Charity\Controllers\UserController::class . ':allowedEmails')
                ->add(new PermissionMiddleware('users.manage'));
            $settings->post('/allowed-emails', \CharityCashbook\Charity\Controllers\UserController::class . ':addAllowedEmail')
                ->add(new PermissionMiddleware('users.manage'));
            $settings->post('/allowed-emails/{id}/delete', \CharityCashbook\Charity\Controllers\UserController::class . ':deleteAllowedEmail')
                ->add(new PermissionMiddleware('users.manage'));
            
            // Audit log
            $settings->get('/audit', \CharityCashbook\Charity\Controllers\AuditController::class . ':index')
                ->add(new PermissionMiddleware('settings.view'));
        });

        // AJAX API endpoints
        $group->group('/api', function (RouteCollectorProxy $api) {
            $api->post('/donors/search', \CharityCashbook\Charity\Controllers\ApiController::class . ':searchDonors')
                ->add(new PermissionMiddleware('donors.view_list'));
            $api->post('/transactions/validate', \CharityCashbook\Charity\Controllers\ApiController::class . ':validateTransaction')
                ->add(new PermissionMiddleware('transactions.create'));
            $api->get('/categories/{type}', \CharityCashbook\Charity\Controllers\ApiController::class . ':getCategories')
                ->add(new PermissionMiddleware('categories.view'));
        });
    })
        ->add(CharitySessionMiddleware::class)
        ->add(CharityAccessMiddleware::class)
        ->add(AuthenticationMiddleware::class);
};
