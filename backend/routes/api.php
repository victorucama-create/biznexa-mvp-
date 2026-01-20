<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\StoreController;
use App\Http\Controllers\Api\MarketController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\ReportController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::group(['prefix' => 'auth'], function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('reset-password', [AuthController::class, 'resetPassword']);
    Route::post('refresh', [AuthController::class, 'refresh']);
    
    // Public store routes
    Route::get('public/store/{slug}', [StoreController::class, 'publicShow']);
    Route::get('public/store/{slug}/products', [StoreController::class, 'publicProducts']);
    Route::post('public/store/{slug}/order', [StoreController::class, 'placeOrder']);
    
    // Public market routes
    Route::get('public/market', [MarketController::class, 'publicIndex']);
    Route::get('public/market/search', [MarketController::class, 'publicSearch']);
    Route::get('public/market/business/{id}', [MarketController::class, 'publicShow']);
});

Route::middleware(['auth:api', 'multi-tenant'])->group(function () {
    // Auth routes
    Route::group(['prefix' => 'auth'], function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::put('profile', [AuthController::class, 'updateProfile']);
        Route::put('change-password', [AuthController::class, 'changePassword']);
    });

    // Dashboard
    Route::group(['prefix' => 'dashboard'], function () {
        Route::get('/', [DashboardController::class, 'index']);
        Route::get('quick-stats', [DashboardController::class, 'quickStats']);
    });

    // Products
    Route::group(['prefix' => 'products'], function () {
        Route::get('/', [ProductController::class, 'index']);
        Route::post('/', [ProductController::class, 'store']);
        Route::get('low-stock', [ProductController::class, 'lowStock']);
        Route::get('export', [ProductController::class, 'export']);
        Route::post('bulk-update', [ProductController::class, 'bulkUpdate']);
        Route::post('upload-image', [ProductController::class, 'uploadImage']);
        Route::get('{id}', [ProductController::class, 'show']);
        Route::put('{id}', [ProductController::class, 'update']);
        Route::delete('{id}', [ProductController::class, 'destroy']);
    });

    // Categories
    Route::group(['prefix' => 'categories'], function () {
        Route::get('/', [CategoryController::class, 'index']);
        Route::post('/', [CategoryController::class, 'store']);
        Route::get('{id}', [CategoryController::class, 'show']);
        Route::put('{id}', [CategoryController::class, 'update']);
        Route::delete('{id}', [CategoryController::class, 'destroy']);
    });

    // Sales (PDV)
    Route::group(['prefix' => 'sales'], function () {
        Route::get('/', [SaleController::class, 'index']);
        Route::post('/', [SaleController::class, 'store']);
        Route::post('quick-sale', [SaleController::class, 'quickSale']);
        Route::get('today', [SaleController::class, 'todaySales']);
        Route::get('{id}', [SaleController::class, 'show']);
        Route::put('{id}', [SaleController::class, 'update']);
        Route::delete('{id}', [SaleController::class, 'destroy']);
        Route::post('{id}/cancel', [SaleController::class, 'cancel']);
        Route::post('{id}/print', [SaleController::class, 'printReceipt']);
    });

    // Online Store
    Route::group(['prefix' => 'store'], function () {
        Route::get('/', [StoreController::class, 'show']);
        Route::put('/', [StoreController::class, 'update']);
        Route::get('stats', [StoreController::class, 'stats']);
        Route::get('orders', [StoreController::class, 'orders']);
        Route::put('orders/{id}/status', [StoreController::class, 'updateOrderStatus']);
        Route::post('publish', [StoreController::class, 'publish']);
    });

    // Market Directory
    Route::group(['prefix' => 'market'], function () {
        Route::get('/', [MarketController::class, 'index']);
        Route::get('search', [MarketController::class, 'search']);
        Route::get('business/{id}', [MarketController::class, 'show']);
        Route::post('highlight', [MarketController::class, 'addHighlight']);
    });

    // Billing & Subscriptions
    Route::group(['prefix' => 'billing'], function () {
        Route::get('/', [BillingController::class, 'index']);
        Route::get('plans', [BillingController::class, 'plans']);
        Route::post('subscribe', [BillingController::class, 'subscribe']);
        Route::post('upgrade', [BillingController::class, 'upgrade']);
        Route::post('downgrade', [BillingController::class, 'downgrade']);
        Route::post('cancel', [BillingController::class, 'cancel']);
        Route::get('invoices', [BillingController::class, 'invoices']);
        Route::get('invoices/{id}', [BillingController::class, 'downloadInvoice']);
        Route::post('webhook/{gateway}', [BillingController::class, 'webhook']);
    });

    // Settings
    Route::group(['prefix' => 'settings'], function () {
        Route::get('company', [SettingsController::class, 'getCompany']);
        Route::put('company', [SettingsController::class, 'updateCompany']);
        Route::get('users', [SettingsController::class, 'users']);
        Route::post('users', [SettingsController::class, 'createUser']);
        Route::put('users/{id}', [SettingsController::class, 'updateUser']);
        Route::delete('users/{id}', [SettingsController::class, 'deleteUser']);
        Route::get('integrations', [SettingsController::class, 'integrations']);
        Route::put('integrations', [SettingsController::class, 'updateIntegrations']);
        Route::get('notifications', [SettingsController::class, 'notifications']);
        Route::put('notifications', [SettingsController::class, 'updateNotifications']);
    });

    // Reports
    Route::group(['prefix' => 'reports'], function () {
        Route::get('sales', [ReportController::class, 'salesReport']);
        Route::get('products', [ReportController::class, 'productsReport']);
        Route::get('financial', [ReportController::class, 'financialReport']);
        Route::get('export/{type}', [ReportController::class, 'exportReport']);
    });
});

// Public API routes (no auth required)
Route::group(['prefix' => 'public'], function () {
    Route::get('market/featured', [MarketController::class, 'featuredBusinesses']);
    Route::get('market/categories', [MarketController::class, 'categories']);
    Route::get('store/{slug}/validate', [StoreController::class, 'validateSlug']);
});
