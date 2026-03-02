<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Requests\Api\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    /** POST /api/register */
    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (empty($data['role'])) {
            $data['role'] = str_ends_with($data['email'], '@tdr-hpz.com') ? 'admin' : 'customer';
        }

        $user = User::create([
      'name'             => $data['name'],
      'email'            => $data['email'],
      'password'         => Hash::make($data['password']),
      'role'             => $data['role'],
      'telegram_chat_id' => $data['telegram_chat_id'] ?? null,
        ]);

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'message' => 'Registrasi berhasil.',
            'token'   => $token,
            'user'    => new UserResource($user),
        ], 201);
    }

    /** POST /api/login */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Email atau password salah.'], 401);
        }

        if (! $user->is_active) {
            return response()->json(['message' => 'Akun Anda dinonaktifkan.'], 403);
        }

        // Email @tdr-hpz.com selalu jadi admin (handle akun lama)
        if (str_ends_with($user->email, '@tdr-hpz.com') && $user->role !== 'admin') {
            $user->update(['role' => 'admin']);
            $user->refresh();
        }

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'message' => 'Login berhasil.',
            'token'   => $token,
            'user'    => new UserResource($user),
        ]);
    }

    /** GET /api/user */
    public function user(Request $request): UserResource
    {
        return new UserResource($request->user()->load('affiliateProfile'));
    }

    /** PUT /api/user/profile */
    public function updateProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'             => 'sometimes|string|max:255',
            'email'            => 'sometimes|email|unique:users,email,' . $request->user()->id,
            'telegram_chat_id' => 'nullable|string|max:100',
        ]);

        $request->user()->update($data);

        return response()->json([
            'message' => 'Profil berhasil diperbarui.',
            'user'    => new UserResource($request->user()->fresh()),
        ]);
    }

    /** POST /api/user/link-telegram */
    public function linkTelegram(Request $request): JsonResponse
    {
        $request->validate([
            'telegram_chat_id' => 'required|string|max:100',
        ]);

        $request->user()->update(['telegram_chat_id' => $request->telegram_chat_id]);

        return response()->json(['message' => 'Telegram berhasil dihubungkan.']);
    }

  /** PUT /api/user/password */
  public function updatePassword(Request $request): JsonResponse {
    $request->validate([
      'current_password' => ['required', 'string'],
      'password'         => ['required', 'confirmed', Password::min(8)],
    ]);

    if (! Hash::check($request->current_password, $request->user()->password)) {
      return response()->json([
        'message' => 'Password lama tidak sesuai.',
        'errors'  => ['current_password' => ['Password lama tidak sesuai.']],
      ], 422);
    }

    $request->user()->update([
      'password' => Hash::make($request->password),
    ]);

    return response()->json(['message' => 'Password berhasil diubah.']);
  }

  /** POST /api/logout */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logout berhasil.']);
    }

    /** GET /api/email/verify/{id}/{hash} */
    public function verifyEmail(Request $request, string $id, string $hash): JsonResponse
    {
        $user = User::findOrFail($id);

        if (! hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
            return response()->json(['message' => 'Link verifikasi tidak valid.'], 403);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email sudah terverifikasi.']);
        }

        $user->markEmailAsVerified();
        event(new Verified($user));

        return response()->json(['message' => 'Email berhasil diverifikasi.']);
    }
}
