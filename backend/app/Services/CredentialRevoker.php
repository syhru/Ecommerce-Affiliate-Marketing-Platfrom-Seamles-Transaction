<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class CredentialRevoker
{
    public function revoke(User $user): void
    {
        $user->tokens()->delete();
        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))
                ->table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }
        if (app()->bound('request') && request()->hasSession()
            && request()->session()->get('credential_user_id') === $user->id) {
            request()->session()->invalidate();
            request()->session()->regenerateToken();
        }
    }
}
