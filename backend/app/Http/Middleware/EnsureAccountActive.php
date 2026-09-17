<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureAccountActive
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user()?->is_active, 403, 'Akun Anda dinonaktifkan.');
        if ($request->hasSession() && $request->user()->currentAccessToken() === null) {
            abort_unless(hash_equals($request->user()->credentialEpoch(), (string) $request->session()->get('credential_epoch')), 401);
        }
        return $next($request);
    }
}
