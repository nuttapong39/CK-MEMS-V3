<?php

use App\Models\Equipment;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

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
Route::get('/{any}', function () {
    return view('app');
})->where('any', '^(?!api|up|qr).*$');
