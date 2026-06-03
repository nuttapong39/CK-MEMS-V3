<?php

use App\Http\Controllers\PublicRepairController;
use App\Models\Equipment;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

// Public repair reporting via QR scan (no auth required).
// Declared before /qr/{idCode} so "repair" is not captured as an id code.
Route::get('/qr/repair/{idCode}', [PublicRepairController::class, 'show'])
    ->where('idCode', '[A-Za-z0-9\-_]+');
Route::post('/qr/repair/{idCode}', [PublicRepairController::class, 'store'])
    ->where('idCode', '[A-Za-z0-9\-_]+');

// Public QR scan landing page (no auth required)
Route::get('/qr/{idCode}', function (string $idCode) {
    $equipment = Equipment::with([
        'hospital:id,code,name_th',
        'department:id,code,name_th',
        'location:id,name',
        'latestCalibration',
    ])->where('id_code', $idCode)->firstOrFail();

    return view('qr.equipment', compact('equipment'));
})->where('idCode', '[A-Za-z0-9\-_]+');

// SPA catch-all (fallback)
// Exclude API, health check, and the public /qr/ scan pages — but NOT the
// SPA "qrcode" designer route (the trailing slash keeps "qrcode" matchable).
Route::get('/{any}', function () {
    return view('app');
})->where('any', '^(?!api/|up$|qr/).*$');
