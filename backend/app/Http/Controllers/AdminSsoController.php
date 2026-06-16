<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Admin SSO Bridge.
 *
 * Login frontend memakai token Sanctum (stateless), sedangkan Filament memakai
 * web session Laravel. Bridge ini menukar identitas admin yang sudah login via
 * API menjadi web session, sehingga admin tidak perlu login ulang di Filament.
 */
class AdminSsoController extends Controller
{
    /** Prefix key cache untuk token SSO one-time. */
    private const CACHE_PREFIX = 'filament_sso:';

    /** Masa berlaku token (detik) — sengaja pendek. */
    private const TTL_SECONDS = 60;

    /**
     * POST /api/admin/filament-sso
     *
     * Membuat one-time SSO token. Hanya untuk user role `admin` yang sudah
     * login via Sanctum. Mengembalikan URL backend untuk dikonsumsi browser.
     */
    public function generate(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user || $user->role !== 'admin') {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $token = Str::random(64);

        Cache::put(
            self::CACHE_PREFIX . $token,
            $user->id,
            now()->addSeconds(self::TTL_SECONDS),
        );

        return response()->json([
            'url' => url('/filament-sso/' . $token),
        ]);
    }

    /**
     * GET /filament-sso/{token}
     *
     * Mengonsumsi token (one-time), membuat web session Laravel, lalu
     * mengarahkan admin ke panel Filament tanpa login ulang.
     */
    public function consume(string $token): RedirectResponse
    {
        $cacheKey = self::CACHE_PREFIX . $token;
        $userId   = Cache::get($cacheKey);

        // One-time use: hapus token apa pun hasil validasinya.
        Cache::forget($cacheKey);

        if (! $userId) {
            return redirect('/admin/login')
                ->withErrors(['email' => 'Sesi SSO tidak valid atau telah kedaluwarsa. Silakan login.']);
        }

        $user = User::find($userId);

        if (! $user || $user->role !== 'admin') {
            return redirect('/admin/login')
                ->withErrors(['email' => 'Akun tidak memiliki akses admin.']);
        }

        Auth::login($user);
        request()->session()->regenerate();

        return redirect('/admin');
    }
}
