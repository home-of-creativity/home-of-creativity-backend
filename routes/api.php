<?php

use App\Http\Controllers\Admin\ClientController as AdminClientController;
use App\Http\Controllers\Admin\ContactChannelController as AdminContactChannelController;
use App\Http\Controllers\Admin\EmployeeController as AdminEmployeeController;
use App\Http\Controllers\Admin\LandingReelController as AdminLandingReelController;
use App\Http\Controllers\Admin\OdooController as AdminOdooController;
use App\Http\Controllers\Admin\OverviewController;
use App\Http\Controllers\Admin\PortfolioCategoryController as AdminPortfolioCategoryController;
use App\Http\Controllers\Admin\PortfolioProjectController as AdminPortfolioProjectController;
use App\Http\Controllers\Admin\PricingCategoryController as AdminPricingCategoryController;
use App\Http\Controllers\Admin\PricingPackageController as AdminPricingPackageController;
use App\Http\Controllers\Admin\PricingSubcategoryController as AdminPricingSubcategoryController;
use App\Http\Controllers\Admin\ServiceRequestController as AdminServiceRequestController;
use App\Http\Controllers\Admin\ShowcaseClientController as AdminShowcaseClientController;
use App\Http\Controllers\Admin\SocialAccountController as AdminSocialAccountController;
use App\Http\Controllers\Admin\SocialInboxController as AdminSocialInboxController;
use App\Http\Controllers\Admin\SocialPostController as AdminSocialPostController;
use App\Http\Controllers\Admin\SocialStaffController as AdminSocialStaffController;
use App\Http\Controllers\AdminBotController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\LandingReelController;
use App\Http\Controllers\N8nWebhookController;
use App\Http\Controllers\PortfolioController;
use App\Http\Controllers\PricingController;
use App\Http\Controllers\ServiceRequestController;
use App\Http\Controllers\SocialFeedController;
use App\Http\Controllers\StaffBotController;
use App\Http\Controllers\TelegramBotController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    $authThrottle = (string) config('auth.api_throttle_per_minute');

    Route::post('register', [AuthController::class, 'register'])->middleware("throttle:{$authThrottle},1");
    Route::post('login', [AuthController::class, 'login'])->middleware("throttle:{$authThrottle},1");
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

Route::get('pricing', [PricingController::class, 'index']);
Route::get('contact', [ContactController::class, 'index']);
Route::get('portfolio/clients', [PortfolioController::class, 'clients']);
Route::get('portfolio/projects', [PortfolioController::class, 'projects']);
Route::get('portfolio/projects/{portfolio_project}', [PortfolioController::class, 'show']);
Route::get('social/instagram-feed', [SocialFeedController::class, 'instagram']);
Route::get('social/facebook-feed', [SocialFeedController::class, 'facebook']);
Route::get('reels', [LandingReelController::class, 'index']);

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('requests', ServiceRequestController::class)
        ->parameters(['requests' => 'service_request'])
        ->only(['index', 'store', 'show']);
});

Route::prefix('admin')->middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('overview', OverviewController::class);
    Route::get('contact', [AdminContactChannelController::class, 'index']);
    Route::post('contact', [AdminContactChannelController::class, 'store']);
    Route::put('contact/{contact_channel}', [AdminContactChannelController::class, 'update']);
    Route::post('contact/{contact_channel}/move', [AdminContactChannelController::class, 'move']);
    Route::delete('contact/{contact_channel}', [AdminContactChannelController::class, 'destroy']);
    Route::get('clients', [AdminClientController::class, 'index']);
    Route::post('clients', [AdminClientController::class, 'store']);
    Route::get('odoo/status', [AdminOdooController::class, 'status']);
    Route::post('odoo/sync-partners', [AdminOdooController::class, 'syncPartners']);
    Route::post('odoo/import-crm-clients', [AdminOdooController::class, 'importCrmClients']);
    Route::post('odoo/import-crm-clients/excel', [AdminOdooController::class, 'importCrmClientsExcel']);
    Route::post('odoo/sync-employees', [AdminOdooController::class, 'syncEmployees']);
    Route::get('odoo/quotations', [AdminOdooController::class, 'quotations']);
    Route::get('odoo/invoices', [AdminOdooController::class, 'invoices']);
    Route::get('clickup/members', [AdminEmployeeController::class, 'clickupMembers']);
    Route::apiResource('employees', AdminEmployeeController::class);
    Route::post('employees/{employee}/approve', [AdminEmployeeController::class, 'approve']);
    Route::post('employees/{employee}/reject', [AdminEmployeeController::class, 'reject']);
    Route::get('requests', [AdminServiceRequestController::class, 'index']);
    Route::get('requests/{service_request}', [AdminServiceRequestController::class, 'show']);
    Route::patch('requests/{service_request}', [AdminServiceRequestController::class, 'update']);
    Route::post('requests/{service_request}/quotation', [AdminServiceRequestController::class, 'sendQuotation']);
    Route::post('requests/{service_request}/confirm-payment', [AdminServiceRequestController::class, 'confirmPayment']);
    Route::post('requests/{service_request}/retry-gemini', [AdminServiceRequestController::class, 'retryGemini']);
    Route::post('requests/{service_request}/re-request-receipt', [AdminServiceRequestController::class, 'reRequestReceipt']);
    Route::post('requests/{service_request}/renew', [AdminServiceRequestController::class, 'renew']);
    Route::get('ops-settings', [AdminServiceRequestController::class, 'opsSettings']);
    Route::post('ops-settings/sham-cash-qr', [AdminServiceRequestController::class, 'uploadShamCashQr']);
    Route::get('requests/{service_request}/files/{file}/receipt', [AdminServiceRequestController::class, 'receipt']);
    Route::post('integration-events/{integrationEvent}/retry', [AdminServiceRequestController::class, 'retryIntegrationEvent']);
    Route::get('portfolio/categories', [AdminPortfolioCategoryController::class, 'index']);
    Route::post('portfolio/categories', [AdminPortfolioCategoryController::class, 'store']);
    Route::delete('portfolio/categories/bulk', [AdminPortfolioCategoryController::class, 'destroyAll']);
    Route::put('portfolio/categories/{portfolio_category}', [AdminPortfolioCategoryController::class, 'update']);
    Route::post('portfolio/categories/{portfolio_category}/move', [AdminPortfolioCategoryController::class, 'move']);
    Route::delete('portfolio/categories/{portfolio_category}', [AdminPortfolioCategoryController::class, 'destroy']);
    Route::get('portfolio/clients', [AdminShowcaseClientController::class, 'index']);
    Route::post('portfolio/clients', [AdminShowcaseClientController::class, 'store']);
    Route::delete('portfolio/clients/bulk', [AdminShowcaseClientController::class, 'destroyAll']);
    Route::put('portfolio/clients/{showcase_client}', [AdminShowcaseClientController::class, 'update']);
    Route::delete('portfolio/clients/{showcase_client}', [AdminShowcaseClientController::class, 'destroy']);
    Route::get('portfolio/projects', [AdminPortfolioProjectController::class, 'index']);
    Route::post('portfolio/projects', [AdminPortfolioProjectController::class, 'store']);
    Route::delete('portfolio/projects/bulk', [AdminPortfolioProjectController::class, 'destroyAll']);
    Route::put('portfolio/projects/{portfolio_project}', [AdminPortfolioProjectController::class, 'update']);
    Route::delete('portfolio/projects/{portfolio_project}', [AdminPortfolioProjectController::class, 'destroy']);
    Route::get('reels', [AdminLandingReelController::class, 'index']);
    Route::post('reels', [AdminLandingReelController::class, 'store']);
    Route::delete('reels/bulk', [AdminLandingReelController::class, 'destroyAll']);
    Route::match(['put', 'post'], 'reels/{landing_reel}', [AdminLandingReelController::class, 'update']);
    Route::delete('reels/{landing_reel}', [AdminLandingReelController::class, 'destroy']);
    Route::get('pricing/categories', [AdminPricingCategoryController::class, 'index']);
    Route::post('pricing/categories', [AdminPricingCategoryController::class, 'store']);
    Route::delete('pricing/categories/bulk', [AdminPricingCategoryController::class, 'destroyAll']);
    Route::put('pricing/categories/{pricing_category}', [AdminPricingCategoryController::class, 'update']);
    Route::post('pricing/categories/{pricing_category}/move', [AdminPricingCategoryController::class, 'move']);
    Route::delete('pricing/categories/{pricing_category}', [AdminPricingCategoryController::class, 'destroy']);
    Route::get('pricing/subcategories', [AdminPricingSubcategoryController::class, 'index']);
    Route::post('pricing/subcategories', [AdminPricingSubcategoryController::class, 'store']);
    Route::delete('pricing/subcategories/bulk', [AdminPricingSubcategoryController::class, 'destroyAll']);
    Route::put('pricing/subcategories/{pricing_subcategory}', [AdminPricingSubcategoryController::class, 'update']);
    Route::post('pricing/subcategories/{pricing_subcategory}/move', [AdminPricingSubcategoryController::class, 'move']);
    Route::delete('pricing/subcategories/{pricing_subcategory}', [AdminPricingSubcategoryController::class, 'destroy']);
    Route::get('pricing/packages', [AdminPricingPackageController::class, 'index']);
    Route::post('pricing/packages', [AdminPricingPackageController::class, 'store']);
    Route::delete('pricing/packages/bulk', [AdminPricingPackageController::class, 'destroyAll']);
    Route::put('pricing/packages/{pricing_package}', [AdminPricingPackageController::class, 'update']);
    Route::post('pricing/packages/{pricing_package}/move', [AdminPricingPackageController::class, 'move']);
    Route::delete('pricing/packages/{pricing_package}', [AdminPricingPackageController::class, 'destroy']);
    Route::get('social/accounts', [AdminSocialAccountController::class, 'index']);
    Route::post('social/accounts', [AdminSocialAccountController::class, 'store']);
    Route::put('social/accounts/{social_account}', [AdminSocialAccountController::class, 'update']);
    Route::post('social/accounts/{social_account}/toggle', [AdminSocialAccountController::class, 'toggle']);
    Route::delete('social/accounts/{social_account}', [AdminSocialAccountController::class, 'destroy']);
    Route::get('social/posts', [AdminSocialPostController::class, 'index']);
    Route::post('social/posts', [AdminSocialPostController::class, 'store']);
    Route::get('social/posts/{social_post}', [AdminSocialPostController::class, 'show']);
    Route::put('social/posts/{social_post}', [AdminSocialPostController::class, 'update']);
    Route::delete('social/posts/{social_post}', [AdminSocialPostController::class, 'destroy']);
    Route::post('social/posts/{social_post}/approve', [AdminSocialPostController::class, 'approve']);
    Route::post('social/posts/{social_post}/publish', [AdminSocialPostController::class, 'publish']);
    Route::get('social/inbox', [AdminSocialInboxController::class, 'index']);
    Route::post('social/inbox/sync', [AdminSocialInboxController::class, 'sync']);
    Route::post('social/inbox/{social_inbox_item}/reply', [AdminSocialInboxController::class, 'reply']);
    Route::get('social/staff', [AdminSocialStaffController::class, 'index']);
    Route::put('social/staff/{user}', [AdminSocialStaffController::class, 'update']);
});

Route::post('webhooks/n8n', N8nWebhookController::class)
    ->middleware(['shared.secret:services.n8n.webhook_secret', 'throttle:60,1']);

Route::prefix('integrations')->middleware(['shared.secret:services.n8n.webhook_secret', 'throttle:60,1'])->group(function () {
    Route::post('odoo/quotation', [IntegrationController::class, 'quotation']);
    Route::post('odoo/invoice', [IntegrationController::class, 'invoice']);
    Route::post('clickup/tasks', [IntegrationController::class, 'tasks']);
    Route::post('clickup/mapping', [IntegrationController::class, 'mapping']);
    Route::post('telegram/notify', [IntegrationController::class, 'notify']);
});

Route::prefix('bot/telegram')->middleware('shared.secret:services.telegram.bot_secret')->group(function () {
    Route::post('link', [TelegramBotController::class, 'link']);
    Route::get('me', [TelegramBotController::class, 'me']);
    Route::post('profile', [TelegramBotController::class, 'updateProfile']);
    Route::get('catalog', [TelegramBotController::class, 'catalog']);
    Route::post('catalog/requests', [TelegramBotController::class, 'catalogRequest']);
    Route::post('requests', [TelegramBotController::class, 'submit']);
    Route::get('requests', [TelegramBotController::class, 'index']);
    Route::patch('requests/{service_request}', [TelegramBotController::class, 'update']);
    Route::post('requests/{service_request}/approve', [TelegramBotController::class, 'approve']);
    Route::post('requests/{service_request}/reject', [TelegramBotController::class, 'reject']);
    Route::post('requests/{service_request}/acknowledge', [TelegramBotController::class, 'acknowledge']);
    Route::post('requests/{service_request}/cancel', [TelegramBotController::class, 'cancel']);
    Route::post('requests/{service_request}/complete', [TelegramBotController::class, 'complete']);
    Route::post('requests/{service_request}/receipt', [TelegramBotController::class, 'receipt']);
    Route::post('requests/{service_request}/revision', [TelegramBotController::class, 'revision']);
    Route::post('requests/{service_request}/renew', [TelegramBotController::class, 'renew']);
    Route::post('requests/{service_request}/decline-renewal', [TelegramBotController::class, 'declineRenewal']);
    Route::post('support', [TelegramBotController::class, 'support']);
});

Route::prefix('bot/staff')->middleware('shared.secret:services.telegram.staff_bot_secret')->group(function () {
    Route::get('me', [StaffBotController::class, 'me']);
    Route::post('join', [StaffBotController::class, 'join']);
    Route::post('reply', [StaffBotController::class, 'reply']);
    Route::get('replyable-requests', [StaffBotController::class, 'replyableRequests']);
    Route::get('quotable-requests', [StaffBotController::class, 'quotableRequests']);
    Route::post('quotation', [StaffBotController::class, 'sendQuotation']);
    Route::get('tasks', [StaffBotController::class, 'tasks']);
    Route::get('new-requests', [StaffBotController::class, 'newRequests']);
    Route::post('deliver', [StaffBotController::class, 'deliver']);
    Route::post('complete', [StaffBotController::class, 'complete']);
    Route::post('in-progress', [StaffBotController::class, 'markInProgress']);
    Route::get('progressable-requests', [StaffBotController::class, 'progressableRequests']);
    Route::get('completable-requests', [StaffBotController::class, 'completableRequests']);
});

Route::prefix('bot/admin')->middleware('shared.secret:services.telegram.admin_bot_secret')->group(function () {
    Route::get('me', [AdminBotController::class, 'me']);
    Route::get('departments', [AdminBotController::class, 'departments']);
    Route::get('members', [AdminBotController::class, 'members']);
    Route::get('tasks', [AdminBotController::class, 'tasks']);
    Route::post('guests', [AdminBotController::class, 'inviteGuest']);
    Route::post('assign', [AdminBotController::class, 'assign']);
});
