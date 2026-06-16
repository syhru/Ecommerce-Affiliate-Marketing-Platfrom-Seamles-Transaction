<?php

use App\Http\Controllers\AdminSsoController;
use Illuminate\Support\Facades\Route;

// Admin SSO bridge — konsumsi one-time token, buat web session, lalu masuk Filament.
// WAJIB didaftarkan SEBELUM catch-all di bawah agar tidak tertelan redirect.
Route::get('/filament-sso/{token}', [AdminSsoController::class, 'consume']);

// Fallback: Redirect any stray web access to the Next.js Frontend
Route::any('{any}', function () {
    return redirect(env('FRONTEND_URL', 'http://localhost:3000'));
})->where('any', '.*');
