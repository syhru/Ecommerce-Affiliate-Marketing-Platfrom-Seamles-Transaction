<?php

namespace App\Http\Middleware;

use App\Jobs\ProcessAffiliateClick;
use App\Models\AffiliateProfile;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackAffiliateClick
{

    public function handle(Request $request, Closure $next): Response
    {
        $refCode = $request->query('ref') ?? $request->cookie('ref_code');

        if ($refCode) {
            $profile = AffiliateProfile::where('referral_code', $refCode)
                ->where('status', 'active')
                ->first();

            if ($profile) {
                ProcessAffiliateClick::dispatch(
                    $profile->user_id,
                    $request->ip(),
                    $request->userAgent() ?? '',
                    $request->headers->get('referer') ?? ''
                );

                $response = $next($request);
                return $response->withCookie(
                    cookie('ref_code', $refCode, 60 * 24 * 30)
                );
            }
        }

        return $next($request);
    }
}
