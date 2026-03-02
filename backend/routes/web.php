<?php

use Illuminate\Support\Facades\Route;

// Fallback: Redirect any stray web access to the Next.js Frontend
Route::any('{any}', function () {
    return redirect(env('FRONTEND_URL', 'http://localhost:3000'));
})->where('any', '.*');
