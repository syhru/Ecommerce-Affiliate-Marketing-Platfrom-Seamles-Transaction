<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\WithdrawalRequest;
use App\Http\Resources\AffiliateProfileResource;
use App\Models\AffiliateClick;
use App\Models\AffiliateCommission;
use App\Models\AffiliateProfile;
use App\Models\AffiliateWithdrawal;
use App\Services\AffiliateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class AffiliateController extends Controller
{
    public function __construct(protected AffiliateService $affiliateService)
    {
    }

    private function getAffiliateProfile(Request $request): AffiliateProfile
    {
        return $request->user()->affiliateProfile;
    }

    /** POST /api/affiliate/register */
    public function register(Request $request): JsonResponse
    {
        $user = $request->user();
        $existingProfile = $user->affiliateProfile;

        // Jika sudah ada profil dan masih pending/active, tolak
        if ($existingProfile && in_array($existingProfile->status, ['pending', 'active'])) {
            return response()->json([
              'message' => 'Anda sudah terdaftar sebagai affiliate.',
              'profile' => new AffiliateProfileResource($existingProfile),
            ], 422);
        }

        $data = $request->validate([
          'bank_name'           => ['required', 'string', 'max:100'],
          'bank_account_number' => ['required', 'string', 'max:50'],
          'bank_account_holder' => ['required', 'string', 'max:255'],
        ]);

        if ($existingProfile && in_array($existingProfile->status, ['inactive', 'rejected'])) {
            // Re-register: update profil yang sudah ada kembali ke pending
            $existingProfile->update([
              'status'              => 'pending',
              'bank_name'           => $data['bank_name'],
              'bank_account_number' => $data['bank_account_number'],
              'bank_account_holder' => $data['bank_account_holder'],
              'approved_at'         => null,
              'approved_by'         => null,
            ]);

            $user->update(['role' => 'affiliate']);

            return response()->json([
              'message' => 'Pendaftaran ulang affiliate berhasil! Tunggu persetujuan admin.',
              'profile' => new AffiliateProfileResource($existingProfile->fresh()),
            ], 201);
        }

        // Baru pertama kali daftar
        do {
            $code = strtoupper(Str::random(8));
        } while (AffiliateProfile::where('referral_code', $code)->exists());

        $profile = AffiliateProfile::create([
          'user_id'             => $user->id,
          'referral_code'       => $code,
          'commission_rate'     => 10,
          'balance'             => 0,
          'total_earned'        => 0,
          'status'              => 'pending',
          'bank_name'           => $data['bank_name'],
          'bank_account_number' => $data['bank_account_number'],
          'bank_account_holder' => $data['bank_account_holder'],
        ]);

        // Update user role to affiliate
        $user->update(['role' => 'affiliate']);

        return response()->json([
          'message' => 'Pendaftaran affiliate berhasil! Tunggu persetujuan admin.',
          'profile' => new AffiliateProfileResource($profile),
        ], 201);
    }

    /** GET /api/affiliate/dashboard */
    public function dashboard(Request $request): JsonResponse
    {
        $affiliateProfile = $this->getAffiliateProfile($request);
        $stats            = $this->affiliateService->getStats($affiliateProfile);
        $chartData        = $this->affiliateService->getChartData($affiliateProfile);

        return response()->json(compact('stats', 'chartData'));
    }

    /** GET /api/affiliate/profile */
    public function profile(Request $request): AffiliateProfileResource
    {
        return new AffiliateProfileResource($this->getAffiliateProfile($request));
    }

    /** PUT /api/affiliate/profile */
    public function updateProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'bank_name'           => 'required|string|max:100',
            'bank_account_number' => 'required|string|max:50',
            'bank_account_holder' => 'required|string|max:255',
        ]);

        $this->getAffiliateProfile($request)->update($data);

        return response()->json([
            'message' => 'Profil bank berhasil diperbarui.',
            'profile' => new AffiliateProfileResource($this->getAffiliateProfile($request)->fresh()),
        ]);
    }

    /** GET /api/affiliate/commissions */
    public function commissions(Request $request): JsonResponse
    {
        $commissions = AffiliateCommission::where('affiliate_id', $request->user()->id)
            ->with('order')
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return response()->json($commissions);
    }

    /** GET /api/affiliate/clicks */
    public function clicks(Request $request): JsonResponse
    {
        $clicks = AffiliateClick::where('affiliate_id', $request->user()->id)
            ->orderByDesc('clicked_at')
            ->paginate($request->integer('per_page', 20));

        return response()->json($clicks);
    }

    /** GET /api/affiliate/withdrawals */
    public function withdrawals(Request $request): JsonResponse
    {
        $withdrawals = AffiliateWithdrawal::where('affiliate_id', $request->user()->id)
            ->latest()
            ->paginate($request->integer('per_page', 10));

        return response()->json($withdrawals);
    }

    /** POST /api/affiliate/withdraw */
    public function withdraw(WithdrawalRequest $request): JsonResponse
    {
        $data             = $request->validated();
        $affiliateProfile = $this->getAffiliateProfile($request);

        try {
            $withdrawal = $this->affiliateService->processWithdrawal(
                $affiliateProfile,
                (float) $data['amount'],
                [
                    'bank_name'           => $data['bank_name'],
                    'bank_account_number' => $data['bank_account_number'],
                    'bank_account_holder' => $data['bank_account_holder'],
                ]
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message'    => 'Permintaan pencairan berhasil diajukan.',
            'withdrawal' => $withdrawal,
        ], 201);
    }
}
