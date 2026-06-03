<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\SystemSettingsController;
use App\Http\Controllers\Api\V1\CalibrationController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\EquipmentController;
use App\Http\Controllers\Api\V1\MasterDataController;
use App\Http\Controllers\Api\V1\MophAlertController;
use App\Http\Controllers\Api\V1\ProviderIdController;
use App\Http\Controllers\Api\V1\QrCodeController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\RepairController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Public
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::get('system/public', [SystemSettingsController::class, 'public']);
    Route::post('auth/emergency-reset/verify', [AuthController::class, 'emergencyVerify']);
    Route::post('auth/emergency-reset', [AuthController::class, 'emergencyReset']);

    // Provider ID OAuth (stub — real handshake wired when MOPH PID docs arrive)
    Route::prefix('auth/provider-id')->group(function () {
        Route::get('start', [ProviderIdController::class, 'start']);
        Route::post('callback', [ProviderIdController::class, 'callback']);
        Route::post('demo-exchange', [ProviderIdController::class, 'demoExchange']);
    });

    // Protected (JWT)
    Route::middleware('auth:api')->group(function () {
        // Auth
        Route::post('auth/refresh', [AuthController::class, 'refresh']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);

        // Master data
        Route::prefix('master')->group(function () {
            Route::get('departments', [MasterDataController::class, 'departments']);
            Route::get('equipment-codes', [MasterDataController::class, 'equipmentCodes']);
            Route::get('risk-levels', [MasterDataController::class, 'riskLevels']);
        });

        // Dashboard
        Route::get('dashboard/stats', [DashboardController::class, 'stats']);

        // Equipment
        Route::prefix('equipments')->group(function () {
            Route::get('preview-id-code', [EquipmentController::class, 'previewIdCode']);
            Route::get('/', [EquipmentController::class, 'index']);
            Route::post('/', [EquipmentController::class, 'store'])->middleware('role:admin|staff');
            Route::get('{equipment}', [EquipmentController::class, 'show']);
            Route::put('{equipment}', [EquipmentController::class, 'update'])->middleware('role:admin|staff');
            Route::delete('{equipment}', [EquipmentController::class, 'destroy'])->middleware('role:admin');
            Route::post('{equipment}/image', [EquipmentController::class, 'uploadImage'])->middleware('role:admin|staff');
            Route::delete('{equipment}/image', [EquipmentController::class, 'removeImage'])->middleware('role:admin|staff');
        });

        // Repair tickets
        Route::prefix('repairs')->group(function () {
            Route::get('summary', [RepairController::class, 'summary']);
            Route::get('next-outsource-ref', [RepairController::class, 'nextOutsourceRef']);
            Route::get('/', [RepairController::class, 'index']);
            Route::post('/', [RepairController::class, 'store']);
            Route::get('{ticket}', [RepairController::class, 'show']);
            Route::post('{ticket}/transition', [RepairController::class, 'transition'])->middleware('role:admin|staff');
            Route::delete('{ticket}', [RepairController::class, 'destroy'])->middleware('role:admin|staff');
        });

        // Calibrations
        Route::prefix('calibrations')->group(function () {
            Route::get('summary', [CalibrationController::class, 'summary']);
            Route::get('/', [CalibrationController::class, 'index']);
            Route::post('/', [CalibrationController::class, 'store'])->middleware('role:admin|staff');
            Route::get('{calibration}', [CalibrationController::class, 'show']);
        });

        // Reports (admin + staff)
        Route::prefix('reports')->middleware('role:admin|staff')->group(function () {
            Route::get('equipments/excel', [ReportController::class, 'equipmentsExcel']);
            Route::get('equipments/pdf', [ReportController::class, 'equipmentsPdf']);
            Route::get('repairs/excel', [ReportController::class, 'repairsExcel']);
            Route::get('repairs/pdf', [ReportController::class, 'repairsPdf']);
            Route::get('calibrations/excel', [ReportController::class, 'calibrationsExcel']);
            Route::get('calibrations/{calibration}/certificate', [ReportController::class, 'calibrationCertificate']);
        });

        // QR Code (admin only)
        Route::prefix('qrcode')->middleware('role:admin')->group(function () {
            Route::get('templates', [QrCodeController::class, 'templates']);
            Route::post('templates', [QrCodeController::class, 'storeTemplate']);
            Route::delete('templates/{template}', [QrCodeController::class, 'destroyTemplate']);
            Route::post('batch-pdf', [QrCodeController::class, 'batchPdf']);
            Route::get('{equipment}/png', [QrCodeController::class, 'png']);
        });

        // System settings (admin only)
        Route::prefix('system')->middleware('role:admin')->group(function () {
            Route::get('settings', [SystemSettingsController::class, 'show']);
            Route::put('settings', [SystemSettingsController::class, 'update']);
            Route::post('settings/logo', [SystemSettingsController::class, 'uploadLogo']);
        });

        // User management (admin only)
        Route::prefix('users')->middleware('role:admin')->group(function () {
            Route::get('roles', [UserController::class, 'rolesList']);
            Route::get('/', [UserController::class, 'index']);
            Route::post('/', [UserController::class, 'store']);
            Route::get('{user}', [UserController::class, 'show']);
            Route::put('{user}', [UserController::class, 'update']);
            Route::delete('{user}', [UserController::class, 'destroy']);
            Route::post('{user}/reset-password', [UserController::class, 'resetPassword']);
        });

        // MOPH Alert (admin only)
        Route::prefix('moph')->middleware('role:admin')->group(function () {
            Route::get('settings', [MophAlertController::class, 'settings']);
            Route::put('settings', [MophAlertController::class, 'updateSettings']);
            Route::post('test', [MophAlertController::class, 'test']);
            Route::get('templates', [MophAlertController::class, 'templates']);
            Route::post('templates/preview', [MophAlertController::class, 'previewTemplate']);
            Route::get('templates/{template}', [MophAlertController::class, 'showTemplate']);
            Route::put('templates/{template}', [MophAlertController::class, 'updateTemplate']);
            Route::get('logs', [MophAlertController::class, 'logs']);
        });
    });
});
