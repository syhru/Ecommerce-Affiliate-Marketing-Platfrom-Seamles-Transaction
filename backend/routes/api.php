<?php

use App\Http\Controllers\Api\AffiliateController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MidtransWebhookController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductController;
use Illuminate\Support\Facades\Route;

// auth
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/forgot-password', [\App\Http\Controllers\Auth\PasswordResetLinkController::class, 'store'])
  ->middleware('throttle:5,1');
Route::post('/reset-password', [\App\Http\Controllers\Auth\NewPasswordController::class, 'store'])
  ->middleware('throttle:5,1');

// email verif
Route::get('/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
  ->middleware(['signed', 'throttle:6,1'])
  ->name('verification.verify');

Route::post('/email/verification-notification', function (\Illuminate\Http\Request $request) {
    $request->user()->sendEmailVerificationNotification();
    return response()->json(['message' => 'Email verifikasi telah dikirim.']);
})->middleware(['auth:sanctum', 'throttle:6,1']);

// products
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{slug}', [ProductController::class, 'show']);

// Midtrans webhook (no CSRF, no auth)
Route::post('/webhooks/midtrans', [MidtransWebhookController::class, 'handle']);


// auth required routes
Route::middleware(['auth:sanctum', 'verified'])->group(function () {

    // auth & info
    Route::get('/user', [AuthController::class, 'user']);
    Route::put('/user/profile', [AuthController::class, 'updateProfile']);
    Route::put('/user/password', [AuthController::class, 'updatePassword']);
    Route::post('/user/link-telegram', [AuthController::class, 'linkTelegram']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // user orders
    Route::get('/user/orders', [OrderController::class, 'userOrders']);
    Route::get('/orders/{idOrOrderNumber}', [OrderController::class, 'show']);
    Route::get('/orders/{idOrOrderNumber}/tracking', [OrderController::class, 'tracking']);

    // order
    Route::post('/orders', [OrderController::class, 'store']);

    // affiliate registration (any authenticated user can apply)
    Route::post('/affiliate/register', [AffiliateController::class, 'register']);

    // affiliate routes (only active affiliates)
    Route::middleware('affiliate.active')->prefix('affiliate')->group(function () {
        Route::get('/dashboard', [AffiliateController::class, 'dashboard']);
        Route::get('/profile', [AffiliateController::class, 'profile']);
        Route::put('/profile', [AffiliateController::class, 'updateProfile']);
        Route::get('/commissions', [AffiliateController::class, 'commissions']);
        Route::get('/clicks', [AffiliateController::class, 'clicks']);
        Route::get('/withdrawals', [AffiliateController::class, 'withdrawals']);
        Route::post('/withdraw', [AffiliateController::class, 'withdraw']);
    });
});
