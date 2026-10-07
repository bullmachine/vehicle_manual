<?php

use App\Http\Controllers\AdminAuditController;
use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\AdminEnquiryController;
use App\Http\Controllers\AdminManualController;
use App\Http\Controllers\AdminMasterController;
use App\Http\Controllers\AdminQrController;
use App\Http\Controllers\AdminReportController;
use App\Http\Controllers\AdminSettingController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\CustomerPortalController;
use App\Http\Controllers\PublicPortalController;
use App\Http\Controllers\PublicSupportController;
use Illuminate\Support\Facades\Route;

Route::prefix('public')->group(function () {
    Route::get('qr/{token}', [PublicPortalController::class, 'qr'])->middleware('throttle:60,1');
    Route::get('vehicle-variants', [PublicPortalController::class, 'vehicleVariants'])->middleware('throttle:60,1');
    Route::get('engines', [PublicPortalController::class, 'engines'])->middleware('throttle:60,1');
    Route::get('languages', [PublicPortalController::class, 'languages'])->middleware('throttle:60,1');
  Route::get('phone-countries', [PublicPortalController::class, 'phoneCountries'])->middleware('throttle:60,1');
    Route::post('customer/request-otp', [PublicPortalController::class, 'requestOtp'])->middleware('throttle:10,1');
    Route::post('customer/verify-otp', [PublicPortalController::class, 'verifyOtp'])->middleware('throttle:10,1');
    Route::post('customer/session', [PublicPortalController::class, 'createSession'])->middleware('throttle:10,1');
    Route::post('support/enquiries', [PublicSupportController::class, 'store'])->middleware('throttle:5,1');
});

Route::prefix('customer')->middleware('customer.session')->group(function () {
    Route::get('session', [CustomerPortalController::class, 'session']);
    Route::get('manuals', [CustomerPortalController::class, 'manuals']);
Route::get('manuals/{manualId}', [CustomerPortalController::class, 'manual']);
Route::get('manuals/{manualId}/file', [CustomerPortalController::class, 'file']);
Route::get('manuals/{manualId}/pages/{page}', [CustomerPortalController::class, 'page']);
    Route::get('manuals/{manualId}/search', [CustomerPortalController::class, 'search']);
    Route::post('manuals/{manualId}/feedback', [CustomerPortalController::class, 'feedback']);
});

Route::prefix('admin')->group(function () {
    Route::post('login', [AdminAuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('forgot-password', [AdminAuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
    Route::post('reset-password', [AdminAuthController::class, 'resetPassword'])->middleware('throttle:5,1');

    Route::middleware(['auth:sanctum', 'admin'])->group(function () {
        Route::get('me', [AdminAuthController::class, 'me']);
        Route::post('logout', [AdminAuthController::class, 'logout']);
        Route::post('two-factor/enable', [AdminAuthController::class, 'enableTwoFactor']);
        Route::post('two-factor/confirm', [AdminAuthController::class, 'confirmTwoFactor']);
        Route::get('dashboard', [AdminDashboardController::class, 'index'])->middleware('permission:dashboard.view');

        $masterRoutes = function (string $resource): void {
            Route::get($resource, [AdminMasterController::class, 'index'])->defaults('resource', $resource)->middleware('permission:masters.manage');
            Route::post($resource, [AdminMasterController::class, 'store'])->defaults('resource', $resource)->middleware('permission:masters.manage');
            Route::put($resource.'/{id}', [AdminMasterController::class, 'update'])->defaults('resource', $resource)->middleware('permission:masters.manage');
            Route::delete($resource.'/{id}', [AdminMasterController::class, 'destroy'])->defaults('resource', $resource)->middleware('permission:masters.manage');
        };
        $masterRoutes('vehicles');
        $masterRoutes('engines');
        $masterRoutes('mappings');
        $masterRoutes('vehicle-qr-types');
        $masterRoutes('languages');
        $masterRoutes('manual-types');
        $masterRoutes('qr-types');

        Route::get('manuals', [AdminManualController::class, 'index'])->middleware('permission:manuals.manage');
        Route::post('manuals', [AdminManualController::class, 'store'])->middleware('permission:manuals.manage');
        Route::get('manuals/{manualId}', [AdminManualController::class, 'show'])->middleware('permission:manuals.manage');
        Route::put('manuals/{manualId}', [AdminManualController::class, 'update'])->middleware('permission:manuals.manage');
        Route::post('manuals/{manualId}/versions', [AdminManualController::class, 'storeVersion'])->middleware('permission:manuals.manage');
    Route::post('manuals/{manualId}/versions/{versionId}/archive', [AdminManualController::class, 'archiveVersion'])->middleware('permission:manuals.manage');
        Route::post('manuals/{manualId}/publish', [AdminManualController::class, 'publish'])->middleware('permission:manuals.manage');
        Route::post('manuals/{manualId}/unpublish', [AdminManualController::class, 'unpublish'])->middleware('permission:manuals.manage');
        Route::post('manuals/{manualId}/archive', [AdminManualController::class, 'archive'])->middleware('permission:manuals.manage');

        Route::get('qr-codes', [AdminQrController::class, 'index'])->middleware('permission:qr.manage');
        Route::post('qr-codes', [AdminQrController::class, 'store'])->middleware('permission:qr.manage');
        Route::get('qr-codes/{id}', [AdminQrController::class, 'show'])->middleware('permission:qr.manage');
        Route::put('qr-codes/{id}', [AdminQrController::class, 'update'])->middleware('permission:qr.manage');
        Route::post('qr-codes/{id}/activate', [AdminQrController::class, 'activate'])->middleware('permission:qr.manage');
        Route::post('qr-codes/{id}/deactivate', [AdminQrController::class, 'deactivate'])->middleware('permission:qr.manage');
        Route::post('qr-codes/{id}/regenerate', [AdminQrController::class, 'regenerate'])->middleware('permission:qr.manage');

        Route::get('reports/qr-scans', [AdminReportController::class, 'qrScans'])->middleware('permission:reports.view');
        Route::get('reports/qr-scans/export', [AdminReportController::class, 'exportQrScans'])->middleware('permission:reports.view');
        Route::get('reports/manual-usage', [AdminReportController::class, 'manualUsage'])->middleware('permission:reports.view');
        Route::get('reports/customers', [AdminReportController::class, 'customers'])->middleware('permission:reports.view');
        Route::get('settings', [AdminSettingController::class, 'show'])->middleware('permission:settings.manage');
        Route::put('settings', [AdminSettingController::class, 'update'])->middleware('permission:settings.manage');
        Route::get('audit-logs', [AdminAuditController::class, 'index'])->middleware('permission:audit.view');
        Route::get('support-enquiries', [AdminEnquiryController::class, 'index'])->middleware('permission:enquiries.view');
        Route::put('support-enquiries/{enquiry}', [AdminEnquiryController::class, 'update'])->middleware('permission:enquiries.manage');
        Route::delete('support-enquiries/{enquiry}', [AdminEnquiryController::class, 'destroy'])->middleware('permission:enquiries.manage');
        Route::get('admin-users', [AdminUserController::class, 'index'])->middleware('permission:users.manage');
        Route::post('admin-users', [AdminUserController::class, 'store'])->middleware('permission:users.manage');
        Route::put('admin-users/{id}', [AdminUserController::class, 'update'])->middleware('permission:users.manage');
        Route::get('roles', [AdminUserController::class, 'roles'])->middleware('permission:users.manage');
    });
});
