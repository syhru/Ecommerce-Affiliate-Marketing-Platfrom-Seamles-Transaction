<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAffiliateActive
{

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $this->deny($request, 'Unauthenticated.', 401);
        }

        if ($user->role !== 'affiliate') {
            return $this->deny($request, 'Akses hanya untuk afiliasi.', 403);
        }

        $profile = $user->affiliateProfile;

        if (! $profile) {
            return $this->deny($request, 'Profil afiliasi tidak ditemukan.', 403);
        }

        if ($profile->status !== 'active') {
            $msg = match ($profile->status) {
                'pending'   => 'Akun afiliasi kamu sedang menunggu persetujuan admin.',
                'suspended' => 'Akun afiliasi kamu telah dinonaktifkan.',
                default     => 'Akun afiliasi kamu tidak aktif.',
            };
            return $this->deny($request, $msg, 403);
        }

        return $next($request);
    }

    private function deny(Request $request, string $message, int $status): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], $status);
        }
        abort($status, $message);
    }
}
